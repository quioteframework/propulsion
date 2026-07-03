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

/**
 * Plain-PHP replacement for the Phing-based PropelSQLTask: loads XML schema files
 * and writes the platform's DDL for each database to a `.sql` file.
 *
 * One `.sql` file is written per schema file (named after the schema's database),
 * unlike the Phing task's Mapper-driven naming -- multi-schema packaging is not
 * yet supported here.
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
        $written = 0;

        foreach ($dataModels as $dataModel) {
            foreach ($dataModel->getDatabases() as $database) {
                $platform = $database->getPlatform();

                if ($this->generatorConfig->getBuildProperty('disableIdentifierQuoting')) {
                    $platform->setIdentifierQuoting(false);
                }

                $outFile = $this->outputDir . DIRECTORY_SEPARATOR . $database->getName() . '.sql';
                $ddl = $platform->getAddTablesDDL($database);

                if (is_file($outFile) && $ddl === file_get_contents($outFile)) {
                    $this->logger->debug('(unchanged) {file}', ['file' => $outFile]);
                    continue;
                }

                $this->logger->info('Writing SQL file: {file} (platform: {platform})', [
                    'file' => $outFile,
                    'platform' => $platform::class,
                ]);
                file_put_contents($outFile, $ddl);
                $written++;
            }
        }

        return $written;
    }
}
