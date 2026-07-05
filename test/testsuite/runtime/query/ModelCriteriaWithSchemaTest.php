<?php

/**
 * This file is part of the Propulsion package.
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 *
 * @license    MIT License
 */

/**
 * Test class for ModelCriteria withs schemas.
 *
 * @author     Francois Zaninotto
 * @version    $Id: ModelCriteriaTest.php 2090 2010-12-13 22:37:03Z francois $
 */
class ModelCriteriaWithSchemaTest extends SchemasTestBase
{

	protected function assertCriteriaTranslation($criteria, $expectedSql, $expectedParams, $message = '')
	{
		$params = array();
		$result = BasePeer::createSelectSql($criteria, $params);

		$this->assertEquals($expectedSql, $result, $message);
		$this->assertEquals($expectedParams, $params, $message);
	}

	public static function conditionsForTestReplaceNamesWithSchemas()
	{
		return array(
			array('ContestBookstoreContest.PrizeBookId = ?', 'PrizeBookId', 'contest.bookstore_contest.PRIZE_BOOK_ID = ?'), // basic case
			array('ContestBookstoreContest.PrizeBookId=?', 'PrizeBookId', 'contest.bookstore_contest.PRIZE_BOOK_ID=?'), // without spaces
			array('ContestBookstoreContest.Id<= ?', 'Id', 'contest.bookstore_contest.ID<= ?'), // with non-equal comparator
			array('ContestBookstoreContest.BookstoreId LIKE ?', 'BookstoreId', 'contest.bookstore_contest.BOOKSTORE_ID LIKE ?'), // with SQL keyword separator
			array('(ContestBookstoreContest.BookstoreId) LIKE ?', 'BookstoreId', '(contest.bookstore_contest.BOOKSTORE_ID) LIKE ?'), // with parenthesis
			array('(ContestBookstoreContest.Id*1.5)=1', 'Id', '(contest.bookstore_contest.ID*1.5)=1') // ignore numbers
		);
	}

	/**
	 * @dataProvider conditionsForTestReplaceNamesWithSchemas
	 */
	#[\PHPUnit\Framework\Attributes\DataProvider('conditionsForTestReplaceNamesWithSchemas')]
	public function testReplaceNamesWithSchemas($origClause, $columnPhpName, $modifiedClause)
	{
		$c = new TestableModelCriteriaWithSchema('bookstore-schemas', 'ContestBookstoreContest');
		$this->doTestReplaceNames($c, ContestBookstoreContestPeer::getTableMap(),  $origClause, $columnPhpName, $modifiedClause);
	}

	public function doTestReplaceNames($c, $tableMap, $origClause, $columnPhpName, $modifiedClause)
	{
		$c->replaceNames($origClause);
		$columns = $c->replacedColumns;
		if ($columnPhpName) {
			// Compare by identity (getPhpName()) rather than deep object equality --
			// fetching a FK column's ColumnMap can lazily trigger the TableMap's
			// relation-building (RelationMap objects, 'relationsBuilt' flag), which
			// has nothing to do with whether replaceNames() matched the right column.
			$this->assertEquals(
				array($tableMap->getColumnByPhpName($columnPhpName)->getPhpName()),
				array_map(fn ($column) => $column->getPhpName(), $columns)
			);
		}
		$this->assertEquals($modifiedClause, $origClause);
	}

}

class TestableModelCriteriaWithSchema extends ModelCriteria
{
	public $joins = array();
	public $replacedColumns;

	public function replaceNames(string &$clause): bool
	{
		return parent::replaceNames($clause);
	}

}
