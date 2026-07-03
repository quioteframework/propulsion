<?php

/**
 * This file is part of the Propulsion package.
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 *
 * @license    MIT License
 */
namespace Propulsion\Generator\Manager;

use Propulsion\Generator\Builder\Util\XmlToAppData;
use Propulsion\Generator\Config\GeneratorConfig;
use Propulsion\Generator\Exception\EngineException;
use Psr\Log\LoggerAwareInterface;
use Psr\Log\LoggerAwareTrait;
use Psr\Log\NullLogger;

/**
 * Shared schema-loading logic for the plain-PHP (non-Phing) generator commands:
 * turns a list of *schema.xml file paths into fully-initialized AppData models.
 */
abstract class AbstractSchemaManager implements LoggerAwareInterface
{
    use LoggerAwareTrait;

    public function __construct(
        protected readonly GeneratorConfig $generatorConfig,
        protected readonly ?string $defaultPackage = null,
        protected readonly string $dbEncoding = 'utf-8',
    ) {
        $this->logger = new NullLogger();
    }

    /**
     * @param string[] $schemaFiles
     * @return \Propulsion\Generator\Model\AppData[]
     */
    protected function loadDataModels(array $schemaFiles): array
    {
        if (empty($schemaFiles)) {
            throw new EngineException('No schema files were provided.');
        }

        $defaultPlatform = $this->generatorConfig->getConfiguredPlatform();
        $dataModels = [];

        foreach ($schemaFiles as $schemaFile) {
            if (!is_file($schemaFile)) {
                throw new EngineException("Schema file not found: $schemaFile");
            }

            $this->logger->info('Processing schema {file}', ['file' => $schemaFile]);

            $xmlParser = new XmlToAppData($defaultPlatform, $this->defaultPackage, $this->dbEncoding);
            $xmlParser->setGeneratorConfig($this->generatorConfig);
            $appData = $xmlParser->parseFile($schemaFile);
            $appData->setName(basename($schemaFile));

            $nbTables = $appData->getDatabase(null, false)->countTables();
            $this->logger->info('{count} tables processed in {file}', ['count' => $nbTables, 'file' => $schemaFile]);

            $dataModels[] = $appData;
        }

        foreach ($dataModels as $appData) {
            $appData->doFinalInitialization();
        }

        return $dataModels;
    }
}
