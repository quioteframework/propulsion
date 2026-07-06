<?php

/**
 * This file is part of the Propulsion package.
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 *
 * @license    MIT License
 */
namespace Propulsion\Generator\Manager;

use Propulsion\Generator\Exception\EngineException;
use Propulsion\Generator\Platform\DefaultPlatform;

/**
 * Plain-PHP replacement for the Phing-based PropulsionSQLTask: loads XML schema files
 * and writes the platform's DDL for each database to a `.sql` file.
 *
 * One `.sql` file is written per distinct database *name* (not per schema file):
 * several schema files commonly share a <database name="..."> (e.g. a project's
 * behavior-*-schema.xml files all targeting the same "bookstore-behavior"
 * database), and their DDL is concatenated into a single file rather than the
 * last one processed overwriting the others'.
 */
class SqlManager extends AbstractSchemaManager
{
    public function __construct(
        \Propulsion\Generator\Config\GeneratorConfig $generatorConfig,
        private readonly string $outputDir,
        ?string $defaultPackage = null,
        string $dbEncoding = 'utf-8',
    ) {
        parent::__construct($generatorConfig, $defaultPackage, $dbEncoding);
    }

    /**
     * @param string[] $schemaFiles
     * @return int Number of .sql files written (created or updated).
     */
    public function generate(array $schemaFiles): int
    {
        if (!is_dir($this->outputDir) && !mkdir($this->outputDir, 0777, true) && !is_dir($this->outputDir)) {
            throw new EngineException("Error creating directory: {$this->outputDir}");
        }

        $dataModels = $this->loadDataModels($schemaFiles);
        $ddlByDatabase = [];
        $platformByDatabase = [];

        foreach ($dataModels as $dataModel) {
            foreach ($dataModel->getDatabases() as $database) {
                $platform = $database->getPlatform();
                if (!$platform instanceof DefaultPlatform) {
                    throw new EngineException(sprintf(
                        "Unable to build SQL for database '%s': its configured platform must extend DefaultPlatform.",
                        $database->getName()
                    ));
                }

                if ($this->generatorConfig->getBuildProperty('disableIdentifierQuoting')) {
                    $platform->setIdentifierQuoting(false);
                }

                $name = $database->getName();
                $ddlByDatabase[$name] = ($ddlByDatabase[$name] ?? '') . $platform->getAddTablesDDL($database);
                $platformByDatabase[$name] = $platform;
            }
        }

        $written = 0;
        foreach ($ddlByDatabase as $name => $ddl) {
            $outFile = $this->outputDir . DIRECTORY_SEPARATOR . $name . '.sql';

            if (is_file($outFile) && $ddl === file_get_contents($outFile)) {
                $this->logger->debug('(unchanged) {file}', ['file' => $outFile]);
                continue;
            }

            $this->logger->info('Writing SQL file: {file} (platform: {platform})', [
                'file' => $outFile,
                'platform' => $platformByDatabase[$name]::class,
            ]);
            file_put_contents($outFile, $ddl);
            $written++;
        }

        return $written;
    }
}
