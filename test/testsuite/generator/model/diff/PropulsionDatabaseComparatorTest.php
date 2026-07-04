<?php

/**
 * This file is part of the Propulsion package.
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 *
 * @license    MIT License
 */

use PHPUnit\Framework\TestCase;
use Propulsion\Generator\Config\GeneratorConfig;
use Propulsion\Generator\Builder\Util\XmlToAppData;
use Propulsion\Generator\Model\Diff\PropulsionDatabaseComparator;

/**
 * Coverage for the schema-diffing engine
 * (Propulsion\Generator\Model\Diff\PropulsionDatabaseComparator) applied
 * directly to two schema.xml versions with a real structural diff (added
 * column, changed column size, added FK) -- confirms the emitted DDL
 * actually reflects the diff.
 *
 * This is the Phing-free half of what used to be covered by
 * PropulsionSQLDiffTaskTest (removed along with the rest of the Phing task
 * classes -- see KNOWN_ISSUES.md): it never touched Phing to begin with
 * (Propulsion\Generator\Config\GeneratorConfig and
 * Propulsion\Generator\Builder\Util\XmlToAppData are both already
 * Phing-free), so it's preserved here as a standalone test of the shared
 * comparator engine that both the deleted PropulsionSQLDiffTask and the
 * current sql:diff console command (Propulsion\Generator\Manager\SqlDiffManager)
 * are built on. See test/testsuite/generator/command/SqlDiffCommandTest.php
 * for end-to-end console-command coverage of the same engine against a live,
 * deliberately out-of-date database.
 */
class PropulsionDatabaseComparatorTest extends TestCase
{
    private string $fixtureDir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->fixtureDir = dirname(__DIR__, 4) . '/fixtures/generator-parity-diff';
    }

    public function testTwoSchemaVersionsProduceExpectedStructuralDiff(): void
    {
        $repoRoot = dirname(__DIR__, 5);
        $config = GeneratorConfig::createFromPropertiesFile(
            $repoRoot . '/generator/default.php',
            null,
            ['propel.database' => 'pgsql']
        );
        $platform = $config->getConfiguredPlatform();

        $fromDb = $this->loadDatabase($this->fixtureDir . '/schema-v1.xml', $platform, $config);
        $toDb = $this->loadDatabase($this->fixtureDir . '/schema-v2.xml', $platform, $config);

        $diff = PropulsionDatabaseComparator::computeDiff($fromDb, $toDb);
        $this->assertNotFalse($diff, 'A structural diff should be detected between schema-v1 and schema-v2');

        $upSql = $platform->getModifyDatabaseDDL($diff);
        $downSql = $platform->getModifyDatabaseDDL($diff->getReverseDiff());

        // Added columns (author_id FK column, price)
        $this->assertMatchesRegularExpression('/ADD\s+(COLUMN\s+)?"?author_id"?/i', $upSql);
        $this->assertMatchesRegularExpression('/ADD\s+(COLUMN\s+)?"?price"?/i', $upSql);

        // Changed column size (title VARCHAR(100) -> VARCHAR(255))
        $this->assertMatchesRegularExpression('/"?title"?[^\n]*VARCHAR\(255\)/i', $upSql);

        // Added foreign key to diff_author
        $this->assertMatchesRegularExpression('/FOREIGN KEY/i', $upSql);
        $this->assertStringContainsString('diff_author', $upSql);

        // Down migration should reverse it: drop the added columns/FK
        $this->assertMatchesRegularExpression('/DROP\s+(COLUMN\s+)?"?author_id"?/i', $downSql);
        $this->assertMatchesRegularExpression('/DROP\s+(COLUMN\s+)?"?price"?/i', $downSql);
    }

    private function loadDatabase(string $schemaFile, $platform, GeneratorConfig $config)
    {
        $xmlParser = new XmlToAppData($platform, null, 'utf-8');
        $xmlParser->setGeneratorConfig($config);
        $appData = $xmlParser->parseFile($schemaFile);
        $appData->doFinalInitialization();
        $database = $appData->getDatabase(null, false);
        $database->setPlatform($platform);

        return $database;
    }
}
