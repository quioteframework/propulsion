<?php

/**
 * This file is part of the Propulsion package.
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 *
 * @license    MIT License
 */
namespace Propulsion\Generator\Manager;

use Propulsion\Generator\Builder\OM\QueryInheritanceBuilder;
use Propulsion\Generator\Config\GeneratorConfig;
use Propulsion\Generator\Exception\EngineException;

/**
 * Plain-PHP replacement for the former Phing-based PropulsionOMTask/AbstractPropulsionDataModelTask
 * pair (Phing itself has since been removed from this project entirely -- see
 * KNOWN_ISSUES.md): loads XML schema files into a data model and writes the
 * generated Object Model classes (Peer/Object/TableMap/Query and friends) to disk.
 *
 * Does not (yet) support the Fileset-driven multi-schema packaging, XSLT
 * normalization, or XSD validation steps the old Phing task offered -- those were
 * confirmed at output parity with this class for the common single/behavior-schema
 * case (see PropulsionOMTaskTest's history in KNOWN_ISSUES.md) before the Phing
 * task was retired; nothing currently depends on the packaging/XSLT/XSD features.
 */
class ModelManager extends AbstractSchemaManager
{
    public function __construct(
        GeneratorConfig $generatorConfig,
        private readonly string $outputDir,
        ?string $defaultPackage = null,
        string $dbEncoding = 'utf-8',
    ) {
        parent::__construct($generatorConfig, $defaultPackage, $dbEncoding);
    }

    /**
     * Loads the given schema files and generates the Object Model classes for
     * every table they define.
     *
     * @param string[] $schemaFiles Absolute or relative paths to *schema.xml files.
     * @return int Number of files written (created or updated).
     */
    public function generate(array $schemaFiles): int
    {
        $dataModels = $this->loadDataModels($schemaFiles);

        $totalWritten = 0;
        foreach ($dataModels as $dataModel) {
            foreach ($dataModel->getDatabases() as $database) {
                if ($this->generatorConfig->getBuildProperty('disableIdentifierQuoting')) {
                    $database->getPlatform()->setIdentifierQuoting(false);
                }

                foreach ($database->getTables() as $table) {
                    if (!$table->isForReferenceOnly()) {
                        $totalWritten += $this->buildTable($table);
                    }
                }
            }
        }

        return $totalWritten;
    }

    private function buildTable($table): int
    {
        $generatorConfig = $this->generatorConfig;
        $written = 0;

        $this->logger->debug('Building table {table}', ['table' => $table->getName()]);

        // These files are always created / overwritten.
        foreach (['peer', 'object', 'tablemap', 'query'] as $target) {
            $builder = $generatorConfig->getConfiguredBuilder($table, $target);
            $written += $this->writeBuilderOutput($builder);
        }

        // These stub classes are only generated if they don't already exist.
        foreach (['peerstub', 'objectstub', 'querystub'] as $target) {
            $builder = $generatorConfig->getConfiguredBuilder($table, $target);
            $written += $this->writeBuilderOutput($builder, overwrite: false);
        }

        // Single table inheritance: stub child Object/Query classes.
        if ($col = $table->getChildrenColumn()) {
            if ($col->isEnumeratedClasses()) {
                foreach ($col->getChildren() as $child) {
                    if ($child->getAncestor()) {
                        /** @var QueryInheritanceBuilder $builder */
                        $builder = $generatorConfig->getConfiguredBuilder($table, 'queryinheritance');
                        $builder->setChild($child);
                        $written += $this->writeBuilderOutput($builder, overwrite: true);
                    }

                    foreach (['objectmultiextend', 'queryinheritancestub'] as $target) {
                        $builder = $generatorConfig->getConfiguredBuilder($table, $target);
                        $builder->setChild($child);
                        $written += $this->writeBuilderOutput($builder, overwrite: false);
                    }
                }
            }
        }

        // Optional [empty] interface.
        if ($table->getInterface()) {
            $builder = $generatorConfig->getConfiguredBuilder($table, 'interface');
            $written += $this->writeBuilderOutput($builder, overwrite: false);
        }

        // Tree behaviors.
        if ($treeMode = $table->treeMode()) {
            switch ($treeMode) {
                case 'NestedSet':
                    foreach (['nestedsetpeer', 'nestedset'] as $target) {
                        $builder = $generatorConfig->getConfiguredBuilder($table, $target);
                        $written += $this->writeBuilderOutput($builder);
                    }
                    break;

                case 'MaterializedPath':
                    foreach (['nodepeer', 'node'] as $target) {
                        $builder = $generatorConfig->getConfiguredBuilder($table, $target);
                        $written += $this->writeBuilderOutput($builder);
                    }
                    foreach (['nodepeerstub', 'nodestub'] as $target) {
                        $builder = $generatorConfig->getConfiguredBuilder($table, $target);
                        $written += $this->writeBuilderOutput($builder, overwrite: false);
                    }
                    break;
            }
        }

        // Classes contributed by behaviors.
        if ($table->hasAdditionalBuilders()) {
            foreach ($table->getAdditionalBuilders() as $builderClass) {
                $builder = new $builderClass($table);
                $builder->setGeneratorConfig($generatorConfig);
                $written += $this->writeBuilderOutput($builder, overwrite: $builder->overwrite ?? true);
            }
        }

        if ($written === 0) {
            $this->logger->debug('Table {table}: no change', ['table' => $table->getName()]);
        }

        return $written;
    }

    private function writeBuilderOutput($builder, bool $overwrite = true): int
    {
        $path = $this->outputDir . DIRECTORY_SEPARATOR . $builder->getClassFilePath();
        $this->ensureDirExists(dirname($path));

        if (is_file($path) && !$overwrite) {
            $this->logger->debug('(exists) {path}', ['path' => $path]);
            return 0;
        }

        $script = $builder->build();
        foreach ($builder->getWarnings() as $warning) {
            $this->logger->warning($warning);
        }

        if (is_file($path) && $this->stripTimestamp($script) === $this->stripTimestamp((string) file_get_contents($path))) {
            $this->logger->debug('(unchanged) {path}', ['path' => $path]);
            return 0;
        }

        $action = is_file($path) ? 'Updating' : 'Creating';
        $this->logger->info('{action} {path} (table: {table}, builder: {builder})', [
            'action' => $action,
            'path' => $path,
            'table' => $builder->getTable()->getName(),
            'builder' => $builder::class,
        ]);

        file_put_contents($path, $script);

        return 1;
    }

    private function ensureDirExists(string $path): void
    {
        if (!is_dir($path) && !mkdir($path, 0777, true) && !is_dir($path)) {
            throw new EngineException("Error creating directory: $path");
        }
    }

    /**
     * Strips autogenerated timestamp lines before content comparison so a
     * timestamp-only diff does not trigger a file rewrite.
     */
    private function stripTimestamp(string $content): string
    {
        return preg_replace('/^\s*\*\s*\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}\s*$/m', ' *', $content);
    }
}
