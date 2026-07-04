<?php

/**
 * This file is part of the Propulsion package.
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 *
 * @license    MIT License
 */
namespace Propulsion\Generator\Manager;

use Propulsion\Generator\Config\GeneratorConfig;
use Propulsion\Generator\Exception\EngineException;
use Propulsion\Generator\Model\Column;
use Propulsion\Generator\Model\Database;
use Propulsion\Generator\Model\IDMethod;
use Propulsion\Generator\Model\PropulsionTypes;
use Propulsion\Generator\Model\Rule;
use Propulsion\Generator\Model\Validator;
use Psr\Log\LoggerAwareInterface;
use Psr\Log\LoggerAwareTrait;
use Psr\Log\NullLogger;

/**
 * Plain-PHP replacement for the Phing-based PropulsionSchemaReverseTask: connects to a
 * live database via PDO, reverse-engineers its tables/columns/types/foreign
 * keys/indices into the Database/Table/Column model classes, and produces a
 * schema.xml DOMDocument (or writes it straight to a file).
 *
 * Unlike ModelManager/SqlManager, this does not extend AbstractSchemaManager: that
 * class' job is loading *existing* schema.xml files into a data model, whereas this
 * class *produces* a schema.xml from a live database -- there is no schema file to load.
 *
 * Does not extend Phing\Task\System\Pdo\PDOTask (unlike the original Task, which used
 * it purely for the `getConnection()` DSN/userid/password -> \PDO helper) -- this opens
 * its own plain \PDO connection directly, matching PDOTask::getConnection()'s exact
 * behavior (ERRMODE_EXCEPTION, ATTR_AUTOCOMMIT set from the autocommit flag, default false).
 */
class SchemaReverseManager implements LoggerAwareInterface
{
    use LoggerAwareTrait;

    /** Zero bit for no validators */
    public const VALIDATORS_NONE = 0;

    /** Bit for maxLength validator */
    public const VALIDATORS_MAXLENGTH = 1;

    /** Bit for maxValue validator */
    public const VALIDATORS_MAXVALUE = 2;

    /** Bit for type validator */
    public const VALIDATORS_TYPE = 4;

    /** Bit for required validator */
    public const VALIDATORS_REQUIRED = 8;

    /** Bit for unique validator */
    public const VALIDATORS_UNIQUE = 16;

    /** Bit for all validators */
    public const VALIDATORS_ALL = 255;

    /**
     * Maps validator type tokens to bits.
     *
     * @var array<string,int>
     */
    private static array $validatorBitMap = [
        'none' => self::VALIDATORS_NONE,
        'maxlength' => self::VALIDATORS_MAXLENGTH,
        'maxvalue' => self::VALIDATORS_MAXVALUE,
        'type' => self::VALIDATORS_TYPE,
        'required' => self::VALIDATORS_REQUIRED,
        'unique' => self::VALIDATORS_UNIQUE,
        'all' => self::VALIDATORS_ALL,
    ];

    /**
     * Defines messages that are added to validators.
     *
     * @var array<string,array{msg: string, var: string[]}>
     */
    private static array $validatorMessages = [
        'maxlength' => [
            'msg' => 'The field %s must be not longer than %s characters.',
            'var' => ['colName', 'value'],
        ],
        'maxvalue' => [
            'msg' => 'The field %s must be not greater than %s.',
            'var' => ['colName', 'value'],
        ],
        'type' => [
            'msg' => 'The column %s must be an %s value.',
            'var' => ['colName', 'value'],
        ],
        'required' => [
            'msg' => 'The field %s is required.',
            'var' => ['colName'],
        ],
        'unique' => [
            'msg' => 'This %s already exists in table %s.',
            'var' => ['colName', 'tableName'],
        ],
    ];

    public function __construct(
        private readonly GeneratorConfig $generatorConfig,
        private readonly string $dsn,
        private readonly ?string $user = null,
        private readonly ?string $password = null,
        private readonly bool $autocommit = false,
    ) {
        $this->logger = new NullLogger();
    }

    /**
     * Parses a comma-separated list of "validator bit" names (e.g. "required,unique")
     * into the bitfield expected by reverse()/generate(). Mirrors the old Task's
     * setAddValidators().
     */
    public static function parseValidatorBits(?string $expr): int
    {
        if ($expr === null || trim($expr) === '') {
            return self::VALIDATORS_NONE;
        }

        $bits = self::VALIDATORS_NONE;
        foreach (explode(',', strtolower($expr)) as $token) {
            $token = trim($token);
            if ($token === '') {
                continue;
            }
            if (!isset(self::$validatorBitMap[$token])) {
                throw new EngineException("Unable to interpret validator in expression ('$expr'): $token");
            }
            $bits |= self::$validatorBitMap[$token];
        }

        return $bits;
    }

    /**
     * Reverse-engineers the live database into a schema.xml DOMDocument.
     *
     * @param string $databaseName Used as the <database name=""> value in the generated schema.xml.
     * @param int $validatorBits Bitfield of self::VALIDATORS_* (default: none).
     */
    public function reverse(string $databaseName, int $validatorBits = self::VALIDATORS_NONE): \DOMDocument
    {
        if ($databaseName === '') {
            throw new EngineException('The databaseName is required for schema reverse engineering');
        }

        $doc = new \DOMDocument('1.0', 'utf-8');
        $doc->formatOutput = true; // pretty printing
        $doc->appendChild($doc->createComment('Autogenerated by ' . self::class . ' class.'));

        $con = $this->connect();
        $database = $this->buildModel($con, $databaseName);

        if ($validatorBits !== self::VALIDATORS_NONE) {
            $this->addValidators($database, $validatorBits);
        }

        $database->appendXml($doc);

        return $doc;
    }

    /**
     * Reverse-engineers the live database and writes the resulting schema.xml to disk.
     *
     * @return string The generated XML (also written to $outputFile).
     */
    public function generate(string $databaseName, string $outputFile, int $validatorBits = self::VALIDATORS_NONE): string
    {
        $doc = $this->reverse($databaseName, $validatorBits);
        $xmlstr = $doc->saveXML();

        $dir = dirname($outputFile);
        if ($dir !== '' && !is_dir($dir) && !mkdir($dir, 0777, true) && !is_dir($dir)) {
            throw new EngineException("Error creating directory: $dir");
        }

        $this->logger->info('Writing XML to file: {file}', ['file' => $outputFile]);
        file_put_contents($outputFile, $xmlstr);

        $this->logger->info('Schema reverse engineering finished');

        return $xmlstr;
    }

    /**
     * Opens the PDO connection, matching Phing\Task\System\Pdo\PDOTask::getConnection()'s
     * behavior exactly (ERRMODE_EXCEPTION always; ATTR_AUTOCOMMIT best-effort).
     */
    private function connect(): \PDO
    {
        $this->logger->debug('Connecting to {dsn}', ['dsn' => $this->dsn]);

        try {
            $con = new \PDO($this->dsn, $this->user, $this->password, [
                \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
            ]);
        } catch (\PDOException $e) {
            throw new EngineException('Unable to connect to database: ' . $e->getMessage(), 0, $e);
        }

        try {
            $con->setAttribute(\PDO::ATTR_AUTOCOMMIT, $this->autocommit);
        } catch (\PDOException $e) {
            $this->logger->debug('Unable to enable auto-commit for this database: {error}', ['error' => $e->getMessage()]);
        }

        return $con;
    }

    /**
     * Builds the model classes from the database schema.
     */
    private function buildModel(\PDO $con, string $databaseName): Database
    {
        $this->logger->info('Reading database structure...');

        $database = new Database($databaseName);
        $database->setPlatform($this->generatorConfig->getConfiguredPlatform($con));
        $database->setDefaultIdMethod(IDMethod::NATIVE);

        $parser = $this->generatorConfig->getConfiguredSchemaParser($con);
        // The Task parameter is only ever used by parsers for optional Phing-specific
        // MSG_VERBOSE logging (see e.g. PgsqlSchemaParser::parse()); passing null
        // here is exactly what happens when the old Task ran without verbose output.
        $nbTables = $parser->parse($database, null);

        $this->logger->info('Successfully reverse engineered {count} tables', ['count' => $nbTables]);

        return $database;
    }

    /**
     * Adds any requested validators to the data model.
     *
     * We will add the following type specific validators:
     *
     *      for notNull columns: required validator
     *      for unique indexes: unique validator
     *      for varchar types: maxLength validators (CHAR, VARCHAR, LONGVARCHAR)
     *      for numeric types: maxValue validators (BIGINT, SMALLINT, TINYINT, INTEGER, FLOAT, DOUBLE, NUMERIC, DECIMAL, REAL)
     *
     * @todo find out how to evaluate the appropriate size and adjust maxValue rule values appropriate
     */
    private function addValidators(Database $database, int $validatorBits): void
    {
        foreach ($database->getTables() as $table) {
            $set = new SchemaReverseValidatorSet();

            foreach ($table->getColumns() as $col) {
                if ($col->isNotNull() && $this->isValidatorRequired($validatorBits, self::VALIDATORS_REQUIRED)) {
                    $validator = $set->getValidator($col);
                    $validator->addRule($this->getValidatorRule($col, 'required'));
                }

                if (in_array($col->getType(), [PropulsionTypes::CHAR, PropulsionTypes::VARCHAR, PropulsionTypes::LONGVARCHAR], true)
                        && $col->getSize() && $this->isValidatorRequired($validatorBits, self::VALIDATORS_MAXLENGTH)) {
                    $validator = $set->getValidator($col);
                    $validator->addRule($this->getValidatorRule($col, 'maxLength', $col->getSize()));
                }

                if ($col->isNumericType() && $this->isValidatorRequired($validatorBits, self::VALIDATORS_MAXVALUE)) {
                    $this->logger->warning('maxValue validator added for column {column}. You will have to adjust the size value manually.', ['column' => $col->getName()]);
                    $validator = $set->getValidator($col);
                    $validator->addRule($this->getValidatorRule($col, 'maxValue', 'REPLACEME'));
                }

                if ($col->isPhpPrimitiveType() && $this->isValidatorRequired($validatorBits, self::VALIDATORS_TYPE)) {
                    $validator = $set->getValidator($col);
                    $validator->addRule($this->getValidatorRule($col, 'type', $col->getPhpType()));
                }
            }

            foreach ($table->getUnices() as $unique) {
                $colnames = $unique->getColumns();
                if (count($colnames) === 1) { // currently 'unique' validator only works w/ single columns.
                    $col = $table->getColumn($colnames[0]);
                    $validator = $set->getValidator($col);
                    $validator->addRule($this->getValidatorRule($col, 'unique'));
                }
            }

            foreach ($set->getValidators() as $validator) {
                $table->addValidator($validator);
            }
        }
    }

    private function isValidatorRequired(int $validatorBits, int $type): bool
    {
        return ($validatorBits & $type) === $type;
    }

    private function getValidatorRule(Column $column, string $type, mixed $value = null): Rule
    {
        $rule = new Rule();
        $rule->setName($type);
        if ($value !== null) {
            $rule->setValue($value);
        }
        $rule->setMessage($this->getRuleMessage($column, $type, $value));

        return $rule;
    }

    private function getRuleMessage(Column $column, string $type, mixed $value): string
    {
        $colName = $column->getName();
        $tableName = $column->getTable()->getName();
        $msg = self::$validatorMessages[strtolower($type)];
        // array_values() strips the string keys compact() produces: passing those
        // through to sprintf() via call_user_func_array() would otherwise be
        // interpreted as (unsupported) named arguments on PHP 8.1+.
        $args = array_values(compact($msg['var']));
        array_unshift($args, $msg['msg']);

        return call_user_func_array('sprintf', $args);
    }
}

/**
 * A helper class to store validator sets indexed by column.
 */
class SchemaReverseValidatorSet
{
    /** @var array<string,Validator> */
    private array $validators = [];

    public function getValidator(Column $column): Validator
    {
        $key = $column->getName();
        if (!isset($this->validators[$key])) {
            $this->validators[$key] = new Validator();
            $this->validators[$key]->setColumn($column);
        }

        return $this->validators[$key];
    }

    /**
     * @return array<string,Validator>
     */
    public function getValidators(): array
    {
        return $this->validators;
    }
}
