<?php

/**
 * This file is part of the Propulsion package.
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 *
 * @license    MIT License
 */
namespace Propulsion\Generator\Manager;

use Propulsion\Generator\Builder\Util\ColumnValue;
use Propulsion\Generator\Builder\Util\DataRow;
use Propulsion\Generator\Exception\EngineException;

/**
 * Plain-PHP replacement for the Phing-based PropulsionDataSQLTask (and the
 * XmlToDataSQL class it used, which had real Phing\Parser\ExpatParser/
 * Phing\Parser\AbstractHandler SAX-parser coupling): reads an XML `<dataset>`
 * file produced by DataDumpManager and converts it into INSERT SQL via the
 * per-platform DataSQLBuilder classes (unchanged, never had Phing coupling
 * of their own).
 *
 * Uses DOMDocument rather than a SAX/expat-handler pattern -- these dataset
 * files are simple, flat XML (one child element per row, no nesting), so a
 * straightforward DOM walk is simpler than reimplementing Phing's streaming
 * SAX callback API for no real benefit at this file size.
 */
class DataSqlManager extends AbstractSchemaManager
{
    /**
     * @param string[] $schemaFiles
     * @return int Number of rows converted to INSERT statements.
     */
    public function transform(array $schemaFiles, string $dataXmlFile, string $outputSqlFile, ?string $databaseName = null): int
    {
        if (!is_file($dataXmlFile)) {
            throw new EngineException("Data XML file not found: $dataXmlFile");
        }

        $dataModels = $this->loadDataModels($schemaFiles);

        $database = null;
        foreach ($dataModels as $dataModel) {
            foreach ($dataModel->getDatabases() as $db) {
                if ($databaseName === null || $db->getName() === $databaseName) {
                    $database = $db;
                    break 2;
                }
            }
        }
        if ($database === null) {
            throw new EngineException(sprintf(
                'No database%s found in the given schema file(s).',
                $databaseName !== null ? sprintf(' named "%s"', $databaseName) : ''
            ));
        }

        $platform = $this->generatorConfig->getConfiguredPlatform();
        $database->setPlatform($platform);

        $doc = new \DOMDocument();
        $doc->preserveWhiteSpace = false;
        if (!@$doc->load($dataXmlFile) || $doc->documentElement === null) {
            throw new EngineException("Unable to parse data XML file: $dataXmlFile");
        }

        $builderClass = $this->generatorConfig->getBuilderClassname('datasql');
        $builderClass::reset();

        $sql = $builderClass::getDatabaseStartSql();
        $currentTableName = null;
        $currentBuilder = null;
        $rowCount = 0;

        foreach ($doc->documentElement->childNodes as $rowNode) {
            if (!$rowNode instanceof \DOMElement) {
                continue;
            }

            $table = $database->getTableByPhpName($rowNode->nodeName);
            if ($table === null) {
                throw new EngineException(sprintf(
                    'Data XML file references table "%s", which is not defined in the given schema file(s).',
                    $rowNode->nodeName
                ));
            }

            $columnValues = [];
            foreach ($rowNode->attributes as $attr) {
                $col = $table->getColumnByPhpName($attr->nodeName);
                if ($col === null) {
                    throw new EngineException(sprintf(
                        'Data XML file references column "%s" on table "%s", which is not defined in the schema.',
                        $attr->nodeName,
                        $table->getName()
                    ));
                }
                $columnValues[] = new ColumnValue($col, iconv('utf-8', $this->dbEncoding, $attr->nodeValue));
            }

            $data = new DataRow($table, $columnValues);

            if ($currentTableName !== $table->getName()) {
                if ($currentBuilder !== null) {
                    $sql .= $currentBuilder->getTableEndSql();
                }
                $currentTableName = $table->getName();
                $currentBuilder = $this->generatorConfig->getConfiguredBuilder($table, 'datasql');
                $sql .= $currentBuilder->getTableStartSql();
            }

            $sql .= $currentBuilder->buildRowSql($data);
            $rowCount++;
        }

        if ($currentBuilder !== null) {
            $sql .= $currentBuilder->getTableEndSql();
        }
        $sql .= $builderClass::getDatabaseEndSql();

        file_put_contents($outputSqlFile, $sql);

        return $rowCount;
    }
}
