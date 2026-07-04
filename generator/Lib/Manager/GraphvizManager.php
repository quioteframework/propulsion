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
use Propulsion\Generator\Model\Database;

/**
 * Plain-PHP replacement for the Phing-based PropulsionGraphvizTask: loads XML schema
 * files and writes a Graphviz `.dot` file per database, describing its tables (as
 * record nodes listing columns, with [PK]/[FK] markers) and foreign-key edges between them.
 */
class GraphvizManager extends AbstractSchemaManager
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
     * @return int Number of .dot files written.
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
                $dot = $this->buildDot($database);
                $outFile = $this->outputDir . DIRECTORY_SEPARATOR . $database->getName() . '.schema.dot';

                if (is_file($outFile) && $dot === file_get_contents($outFile)) {
                    $this->logger->debug('(unchanged) {file}', ['file' => $outFile]);
                    continue;
                }

                $this->logger->info('Writing dot file: {file}', ['file' => $outFile]);
                file_put_contents($outFile, $dot);
                $written++;
            }
        }

        return $written;
    }

    private function buildDot(Database $database): string
    {
        $this->logger->debug('db: {database}', ['database' => $database->getName()]);

        $dot = "digraph G {\n";

        foreach ($database->getTables() as $tbl) {
            $this->logger->debug("\t+ {table}", ['table' => $tbl->getName()]);

            $dot .= 'node' . $tbl->getName() . ' [label="{<table>' . $tbl->getName() . '|<cols>';
            foreach ($tbl->getColumns() as $col) {
                $dot .= $col->getName() . ' (' . $col->getType() . ')';
                if (count($col->getForeignKeys()) > 0) {
                    $dot .= ' [FK]';
                } elseif ($col->isPrimaryKey()) {
                    $dot .= ' [PK]';
                }
                $dot .= '\l';
            }
            $dot .= '}", shape=record];';
            $dot .= "\n";
        }

        $dot .= "\n";
        foreach ($database->getTables() as $tbl) {
            foreach ($tbl->getColumns() as $col) {
                $fk = $col->getForeignKeys();
                if (count($fk) === 0) {
                    continue;
                }
                if (count($fk) > 1) {
                    throw new EngineException('not sure what to do here...');
                }
                $fk = $fk[0];
                $dot .= 'node' . $tbl->getName() . ':cols -> node' . $fk->getForeignTableName() . ':table [label="'
                    . $col->getName() . '=' . implode(',', $fk->getForeignColumns()) . ' "];';
                $dot .= "\n";
            }
        }

        $dot .= "}\n";

        return $dot;
    }
}
