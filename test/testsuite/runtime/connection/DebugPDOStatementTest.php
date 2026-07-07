<?php

/**
 * This file is part of the Propulsion package.
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 *
 * @license    MIT License
 */

use PHPUnit\Framework\TestCase;
use Propulsion\Connection\PropulsionPDO;
use Propulsion\Connection\DebugPDOStatement;

/**
 * Test class for DebugPDOStatement. Uses a real sqlite in-memory connection since
 * PDOStatement instances can only be obtained via PDO::prepare(), not constructed
 * directly.
 */
class DebugPDOStatementTest extends TestCase
{
    private PropulsionPDO $pdo;

    protected function setUp(): void
    {
        $this->pdo = new PropulsionPDO('sqlite::memory:');
        $this->pdo->useDebug = true;
        $this->pdo->setConfiguration(new \Propulsion\Config\PropulsionConfiguration(array()));
        $this->pdo->setAttribute(PDO::ATTR_STATEMENT_CLASS, array(DebugPDOStatement::class, array($this->pdo)));
        $this->pdo->exec('CREATE TABLE foo (id INTEGER PRIMARY KEY, name TEXT)');
    }

    public function testExecuteReturnsDebugPDOStatementInstance()
    {
        $stmt = $this->pdo->prepare('SELECT * FROM foo');
        $this->assertInstanceOf(DebugPDOStatement::class, $stmt);
    }

    public function testGetExecutedQueryStringSubstitutesBoundValues()
    {
        $stmt = $this->pdo->prepare('INSERT INTO foo (id, name) VALUES (:p1, :p2)');
        $stmt->bindValue(':p1', 1, PDO::PARAM_INT);
        $stmt->bindValue(':p2', 'Bar', PDO::PARAM_STR);
        $stmt->execute();

        $sql = $stmt->getExecutedQueryString();
        $this->assertStringContainsString('1', $sql);
        $this->assertStringContainsString("'Bar'", $sql);
        $this->assertStringNotContainsString(':p1', $sql);
        $this->assertStringNotContainsString(':p2', $sql);
    }

    public function testGetExecutedQueryStringWithoutPlaceholdersReturnsQueryUnchanged()
    {
        $stmt = $this->pdo->prepare('SELECT * FROM foo');
        $stmt->execute();
        $this->assertSame('SELECT * FROM foo', $stmt->getExecutedQueryString());
    }

    public function testExecuteUpdatesConnectionQueryCountAndLastExecutedQuery()
    {
        $before = $this->pdo->getQueryCount();

        $stmt = $this->pdo->prepare('INSERT INTO foo (id, name) VALUES (:p1, :p2)');
        $stmt->bindValue(':p1', 2, PDO::PARAM_INT);
        $stmt->bindValue(':p2', 'Baz', PDO::PARAM_STR);
        $stmt->execute();

        $this->assertSame($before + 1, $this->pdo->getQueryCount());
        $this->assertStringContainsString('Baz', $this->pdo->getLastExecutedQuery());
    }

    public function testExecuteWithInputParametersArray()
    {
        $stmt = $this->pdo->prepare('INSERT INTO foo (id, name) VALUES (?, ?)');
        $result = $stmt->execute(array(3, 'Direct'));
        $this->assertTrue($result);

        $check = $this->pdo->query('SELECT name FROM foo WHERE id = 3')->fetchColumn();
        $this->assertSame('Direct', $check);
    }

    public function testBindParamBindsByReference()
    {
        $stmt = $this->pdo->prepare('INSERT INTO foo (id, name) VALUES (:p1, :p2)');
        $id = 4;
        $name = 'Referenced';
        $stmt->bindParam(':p1', $id, PDO::PARAM_INT);
        $stmt->bindParam(':p2', $name, PDO::PARAM_STR);
        $stmt->execute();

        $check = $this->pdo->query('SELECT name FROM foo WHERE id = 4')->fetchColumn();
        $this->assertSame('Referenced', $check);
    }
}
