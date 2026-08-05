<?php

/**
 * This file is part of the Propulsion package.
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 *
 * @license    MIT License
 */

use PHPUnit\Framework\TestCase;
use Propulsion\Adapter\DBSQLite;
use Propulsion\Map\ColumnMap;
use Propulsion\Propulsion;
use Propulsion\Query\ColumnSqlRewriter;
use Propulsion\Query\Criteria;
use Propulsion\Util\BasePeer;

/**
 * An adapter that rewrites exactly one column's SQL, standing in for the real
 * platform cases (MariaDB's native VECTOR, a real geometry column) without
 * needing either of those servers. SQLite is the base only because it is a
 * concrete adapter with no connection requirements.
 */
class RewritingTestAdapter extends DBSQLite
{
	public function usesColumnSqlRewriting(): bool
	{
		return true;
	}

	public function getColumnBindExpression(ColumnMap $cMap, string $placeholder): string
	{
		return $cMap->isNativeVector() ? 'FROM_TEXT(' . $placeholder . ')' : $placeholder;
	}

	public function getColumnSelectExpression(ColumnMap $cMap, string $columnExpression): string
	{
		return $cMap->isNativeVector() ? 'TO_TEXT(' . $columnExpression . ')' : $columnExpression;
	}
}

/**
 * Covers the column-SQL-rewriting hooks
 * ({@see \Propulsion\Adapter\DBAdapter::getColumnBindExpression()}/
 * {@see \Propulsion\Adapter\DBAdapter::getColumnSelectExpression()}) and their
 * four wiring points: the SELECT list, WHERE comparisons, the INSERT value
 * list, and the UPDATE SET clause. Every assertion is on generated SQL, so no
 * live database of any flavour is involved.
 */
class ColumnSqlRewritingTest extends TestCase
{
	private const DATASOURCE = 'column_sql_rewriting_test';
	private const PLAIN_DATASOURCE = 'column_sql_rewriting_test_plain';

	protected function setUp(): void
	{
		parent::setUp();

		foreach (array(self::DATASOURCE => new RewritingTestAdapter(), self::PLAIN_DATASOURCE => new DBSQLite()) as $name => $adapter) {
			Propulsion::setDB($name, $adapter);
			$dbMap = Propulsion::getDatabaseMap($name);
			if ($dbMap->hasTable('doc')) {
				continue;
			}
			$table = $dbMap->addTable('doc');
			$table->setClassname('Doc');
			$table->addColumn('ID', 'Id', 'INTEGER', true, null, null, true);
			$table->addColumn('TITLE', 'Title', 'VARCHAR', false, 255);
			$table->addColumn('EMBEDDING', 'Embedding', 'VECTOR', false, 3)->setNativeVector(true);
		}
	}

	private function newCriteria(string $dbName = self::DATASOURCE): Criteria
	{
		$c = new Criteria($dbName);
		$c->setPrimaryTableName('doc');

		return $c;
	}

	public function testSelectListWrapsOnlyTheRewrittenColumn()
	{
		$c = $this->newCriteria();
		$c->addSelectColumn('doc.ID');
		$c->addSelectColumn('doc.TITLE');
		$c->addSelectColumn('doc.EMBEDDING');

		$params = array();
		$sql = BasePeer::createSelectSql($c, $params);

		$this->assertStringContainsString('SELECT doc.ID, doc.TITLE, TO_TEXT(doc.EMBEDDING) FROM', $sql);
	}

	public function testSelectListKeepsTheTableAliasInsideTheWrapper()
	{
		$c = $this->newCriteria();
		$c->addAlias('d', 'doc');
		$c->addSelectColumn('d.EMBEDDING');

		$params = array();
		$sql = BasePeer::createSelectSql($c, $params);

		// The wrapper must reference the alias, which is what is in scope --
		// not the real table name the ColumnMap was looked up under.
		$this->assertStringContainsString('TO_TEXT(d.EMBEDDING)', $sql);
		$this->assertStringNotContainsString('TO_TEXT(doc.EMBEDDING)', $sql);
	}

	public function testSelectListLeavesComputedExpressionsAlone()
	{
		$c = $this->newCriteria();
		$c->addSelectColumn('COUNT(doc.EMBEDDING)');
		$c->addAsColumn('n', 'MAX(doc.ID)');

		$params = array();
		$sql = BasePeer::createSelectSql($c, $params);

		$this->assertStringContainsString('COUNT(doc.EMBEDDING)', $sql);
		$this->assertStringNotContainsString('TO_TEXT', $sql);
	}

	public function testAsColumnsAreRewrittenToo()
	{
		$c = $this->newCriteria();
		$c->addSelectColumn('doc.ID');
		$c->addAsColumn('vec', 'doc.EMBEDDING');

		$params = array();
		$sql = BasePeer::createSelectSql($c, $params);

		$this->assertStringContainsString('TO_TEXT(doc.EMBEDDING) AS vec', $sql);
	}

	public function testWhereComparisonWrapsTheBoundValueNotTheColumn()
	{
		$c = $this->newCriteria();
		$c->addSelectColumn('doc.ID');
		$c->add('doc.EMBEDDING', '[1,2,3]');

		$params = array();
		$sql = BasePeer::createSelectSql($c, $params);

		$this->assertStringContainsString('WHERE doc.EMBEDDING=FROM_TEXT(:p1)', $sql);
		$this->assertCount(1, $params);
		$this->assertSame('[1,2,3]', $params[0]['value']);
	}

	public function testInComparisonWrapsEveryBoundValue()
	{
		$c = $this->newCriteria();
		$c->addSelectColumn('doc.ID');
		$c->add('doc.EMBEDDING', array('[1,2,3]', '[4,5,6]'), Criteria::IN);

		$params = array();
		$sql = BasePeer::createSelectSql($c, $params);

		$this->assertStringContainsString('doc.EMBEDDING IN (FROM_TEXT(:p1),FROM_TEXT(:p2))', $sql);
		$this->assertCount(2, $params);
	}

	public function testWhereComparisonOnAPlainColumnIsUntouched()
	{
		$c = $this->newCriteria();
		$c->addSelectColumn('doc.ID');
		$c->add('doc.TITLE', 'x');

		$params = array();
		$sql = BasePeer::createSelectSql($c, $params);

		$this->assertStringContainsString('WHERE doc.TITLE=:p1', $sql);
	}

	public function testAnAdapterThatDoesNotRewriteEmitsTheOriginalSql()
	{
		$c = $this->newCriteria(self::PLAIN_DATASOURCE);
		$c->addSelectColumn('doc.EMBEDDING');
		$c->add('doc.EMBEDDING', '[1,2,3]');

		$params = array();
		$sql = BasePeer::createSelectSql($c, $params);

		$this->assertStringContainsString('SELECT doc.EMBEDDING FROM', $sql);
		$this->assertStringContainsString('WHERE doc.EMBEDDING=:p1', $sql);
	}

	public function testRewriterLeavesAnUnmappedTableAlone()
	{
		$db = new RewritingTestAdapter();
		$c = $this->newCriteria();

		$this->assertSame(
			'nosuchtable.EMBEDDING',
			ColumnSqlRewriter::select($db, $c, 'nosuchtable.EMBEDDING')
		);
		$this->assertSame(
			':p1',
			ColumnSqlRewriter::bind($db, self::DATASOURCE, 'nosuchtable', 'EMBEDDING', ':p1')
		);
	}

	public function testRewriterLeavesAnUnmappedColumnAlone()
	{
		$db = new RewritingTestAdapter();

		$this->assertSame(
			':p1',
			ColumnSqlRewriter::bind($db, self::DATASOURCE, 'doc', 'NO_SUCH_COLUMN', ':p1')
		);
		$this->assertSame(
			':p1',
			ColumnSqlRewriter::bind($db, self::DATASOURCE, null, null, ':p1')
		);
	}

	/**
	 * @dataProvider notAPlainColumnReference
	 */
	#[\PHPUnit\Framework\Attributes\DataProvider('notAPlainColumnReference')]
	public function testRewriterOnlyMatchesAPlainQualifiedColumnReference(string $expression)
	{
		$db = new RewritingTestAdapter();
		$c = $this->newCriteria();

		$this->assertSame($expression, ColumnSqlRewriter::select($db, $c, $expression));
	}

	/**
	 * @return array<int, array<int, string>>
	 */
	public static function notAPlainColumnReference(): array
	{
		return array(
			array('EMBEDDING'),                       // unqualified
			array('MAX(doc.EMBEDDING)'),              // function wrapper
			array('doc.EMBEDDING + 1'),               // arithmetic
			array('"doc"."EMBEDDING"'),               // already-quoted identifiers
			array('doc.EMBEDDING AS e'),              // carries its own alias
		);
	}
}
