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

/**
 * Plain-PHP replacement for the Phing-based PropulsionDataDumpTask: connects
 * to a live database and dumps the rows of every table described by a set of
 * schema.xml files into an XML `<dataset>` file (one child element per row,
 * named after the table's phpName, with column values as phpName-keyed
 * attributes). The result can be turned into INSERT SQL by DataSqlManager.
 *
 * Unlike the original Task, this takes an explicit DSN/user/password rather
 * than routing multiple databases through a Phing-Properties "datadbmap"
 * coordination file -- that file only made sense inside a multi-database
 * Phing build; a single console invocation dumps one database at a time.
 */
class DataDumpManager extends AbstractSchemaManager
{
    public function __construct(
        GeneratorConfig $generatorConfig,
        private readonly string $dsn,
        private readonly ?string $user = null,
        private readonly ?string $password = null,
        ?string $defaultPackage = null,
        string $dbEncoding = 'utf-8',
    ) {
        parent::__construct($generatorConfig, $defaultPackage, $dbEncoding);
    }

    /**
     * @param string[] $schemaFiles
     * @return int Number of rows dumped.
     */
    public function dump(array $schemaFiles, string $outputFile, ?string $databaseName = null): int
    {
        $dataModels = $this->loadDataModels($schemaFiles);

        $pdo = new \PDO($this->dsn, $this->user, $this->password);
        $pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);

        $doc = new \DOMDocument('1.0', 'utf-8');
        $doc->formatOutput = true;
        $doc->appendChild($doc->createComment('Created by DataDumpManager.'));

        $dsNode = $doc->createElement('dataset');
        $dsNode->setAttribute('name', 'all');
        $doc->appendChild($dsNode);

        $platform = $this->generatorConfig->getConfiguredPlatform($pdo);
        if ($platform === null) {
            throw new EngineException('Cannot dump data: no platform is configured for this connection.');
        }
        $rowCount = 0;
        $dumpedAnyTable = false;

        foreach ($dataModels as $dataModel) {
            foreach ($dataModel->getDatabases() as $database) {
                if ($databaseName !== null && $database->getName() !== $databaseName) {
                    continue;
                }
                $dumpedAnyTable = true;

                foreach ($database->getTables() as $table) {
                    $tableName = $table->getName();
                    $tablePhpName = $table->getPhpName();
                    if ($tableName === null || $tablePhpName === null) {
                        throw new EngineException('Cannot dump a table with no name.');
                    }

                    $this->logger->info('Dumping table {table}', ['table' => $tableName]);
                    $stmt = $pdo->query('SELECT * FROM ' . $platform->quoteIdentifier($tableName));
                    if ($stmt === false) {
                        throw new EngineException("Failed to query table \"$tableName\".");
                    }
                    while (is_array($row = $stmt->fetch(\PDO::FETCH_ASSOC))) {
                        $rowNode = $doc->createElement($tablePhpName);
                        foreach ($table->getColumns() as $col) {
                            $colName = $col->getName();
                            $colPhpName = $col->getPhpName();
                            if ($colName === null) {
                                continue;
                            }
                            $cval = $row[$colName] ?? null;
                            if ($cval !== null) {
                                if (!is_scalar($cval)) {
                                    throw new EngineException("Column \"$colName\" in table \"$tableName\" returned a non-scalar value that cannot be dumped.");
                                }
                                $encoded = iconv($this->dbEncoding, 'utf-8', (string) $cval);
                                if ($encoded === false) {
                                    throw new EngineException("Failed to convert value of column \"$colName\" in table \"$tableName\" from {$this->dbEncoding} to utf-8.");
                                }
                                $rowNode->setAttribute($colPhpName, $encoded);
                            }
                        }
                        $dsNode->appendChild($rowNode);
                        $rowCount++;
                    }
                }
            }
        }

        if ($databaseName !== null && !$dumpedAnyTable) {
            throw new EngineException(sprintf('No database named "%s" found in the given schema file(s).', $databaseName));
        }

        $doc->save($outputFile);

        return $rowCount;
    }
}
