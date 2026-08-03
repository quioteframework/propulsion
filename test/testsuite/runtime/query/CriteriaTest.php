<?php

/**
 * This file is part of the Propulsion package.
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 *
 * @license    MIT License
 */

/**
 * Test class for Criteria.
 *
 * @author     <a href="mailto:celkins@scardini.com">Christopher Elkins</a>
 * @author     <a href="mailto:sam@neurogrid.com">Sam Joseph</a>
 */
class CriteriaTest extends \PHPUnit\Framework\TestCase
{

	/**
	 * The criteria to use in the test.
	 * @var        Criteria
	 */
	private $c;

	/**
	 * DB adapter saved for later.
	 *
	 * @var        DBAdapter
	 */
	private $savedAdapter;

	protected function setUp(): void
	{
		parent::setUp();
		// Despite historically extending BookstoreTestBase, nothing in this file
		// opens a real database connection or touches $this->con: every test here
		// builds Criteria/SQL-string output in memory against a swapped-in DBSQLite()/
		// DBMySQL() adapter purely to select dialect quoting rules (see testOrderByIgnoreCase
		// et al, which reference BookPeer::TITLE/addSelectColumns() only for their
		// generated column-name constants/metadata, never executing anything). So this
		// needs the Bookstore fixture *classes* to exist (see IntegrationDatabase::
		// ensureClassesGenerated(), triggered eagerly and unconditionally in
		// bootstrap.php) but not a live DB/Docker -- extending TestCase directly here,
		// instead of BookstoreTestBase, means this whole class also runs (not just
		// skips cleanly) with PROPULSION_SKIP_INTEGRATION=1 or no Docker at all.
		$this->c = new Criteria();
		$this->savedAdapter = Propulsion::getDB(null);
		Propulsion::setDB(null, new DBSQLite());
	}

	protected function tearDown(): void
	{
		Propulsion::setDB(null, $this->savedAdapter);
		parent::tearDown();
	}

	/**
	 * Test basic adding of strings.
	 */
	public function testAddString()
	{
		$table = "myTable";
		$column = "myColumn";
		$value = "myValue";

		// Add the string
		$this->c->add($table . '.' . $column, $value);

		// Verify that the key exists
		$this->assertTrue($this->c->containsKey($table . '.' . $column));

		// Verify that what we get out is what we put in
		$this->assertTrue($this->c->getValue($table . '.' . $column) === $value);
	}

	/**
	 * Test basic adding of strings for table with explicit schema.
	 */
	public function testAddStringWithSchemas()
	{
		$table = "mySchema.myTable";
		$column = "myColumn";
		$value = "myValue";

		// Add the string
		$this->c->add($table . '.' . $column, $value);

		// Verify that the key exists
		$this->assertTrue($this->c->containsKey($table . '.' . $column));

		// Verify that what we get out is what we put in
		$this->assertTrue($this->c->getValue($table . '.' . $column) === $value);
	}

	public function testAddAndSameColumns()
	{
		$table1 = "myTable1";
		$column1 = "myColumn1";
		$value1 = "myValue1";
		$key1 = "$table1.$column1";

		$table2 = "myTable1";
		$column2 = "myColumn1";
		$value2 = "myValue2";
		$key2 = "$table2.$column2";

		$this->c->add($key1, $value1, Criteria::EQUAL);
		$this->c->addAnd($key2, $value2, Criteria::EQUAL);

		$expect = "SELECT  FROM myTable1 WHERE (myTable1.myColumn1=:p1 AND myTable1.myColumn1=:p2)";

		$params = array();
		$result = BasePeer::createSelectSql($this->c, $params);

		$expect_params = array(
			array('table' => 'myTable1', 'column' => 'myColumn1', 'value' => 'myValue1'),
			array('table' => 'myTable1', 'column' => 'myColumn1', 'value' => 'myValue2'),
		);

		$this->assertEquals($expect, $result, 'addAnd() called on an existing column creates a combined criterion');
		$this->assertEquals($expect_params, $params, 'addAnd() called on an existing column creates a combined criterion');
	}

	public function testAddAndSameColumnsPropulsion14Compatibility()
	{
		$table1 = "myTable1";
		$column1 = "myColumn1";
		$value1 = "myValue1";
		$key1 = "$table1.$column1";

		$table2 = "myTable1";
		$column2 = "myColumn1";
		$value2 = "myValue2";
		$key2 = "$table2.$column2";

		$table3 = "myTable3";
		$column3 = "myColumn3";
		$value3 = "myValue3";
		$key3 = "$table3.$column3";

		$this->c->add($key1, $value1, Criteria::EQUAL);
		$this->c->add($key3, $value3, Criteria::EQUAL);
		$this->c->addAnd($key2, $value2, Criteria::EQUAL);

		$expect = "SELECT  FROM myTable1, myTable3 WHERE (myTable1.myColumn1=:p1 AND myTable1.myColumn1=:p2) AND myTable3.myColumn3=:p3";

		$params = array();
		$result = BasePeer::createSelectSql($this->c, $params);

		$expect_params = array(
			array('table' => 'myTable1', 'column' => 'myColumn1', 'value' => 'myValue1'),
			array('table' => 'myTable1', 'column' => 'myColumn1', 'value' => 'myValue2'),
			array('table' => 'myTable3', 'column' => 'myColumn3', 'value' => 'myValue3'),
		);

		$this->assertEquals($expect, $result, 'addAnd() called on an existing column creates a combined criterion');
		$this->assertEquals($expect_params, $params, 'addAnd() called on an existing column creates a combined criterion');
	}

	public function testAddAndDistinctColumns()
	{
		$table1 = "myTable1";
		$column1 = "myColumn1";
		$value1 = "myValue1";
		$key1 = "$table1.$column1";

		$table2 = "myTable2";
		$column2 = "myColumn2";
		$value2 = "myValue2";
		$key2 = "$table2.$column2";

		$this->c->add($key1, $value1, Criteria::EQUAL);
		$this->c->addAnd($key2, $value2, Criteria::EQUAL);

		$expect = "SELECT  FROM myTable1, myTable2 WHERE myTable1.myColumn1=:p1 AND myTable2.myColumn2=:p2";

		$params = array();
		$result = BasePeer::createSelectSql($this->c, $params);

		$expect_params = array(
			array('table' => 'myTable1', 'column' => 'myColumn1', 'value' => 'myValue1'),
			array('table' => 'myTable2', 'column' => 'myColumn2', 'value' => 'myValue2'),
		);

		$this->assertEquals($expect, $result, 'addAnd() called on a distinct column adds a criterion to the criteria');
		$this->assertEquals($expect_params, $params, 'addAnd() called on a distinct column adds a criterion to the criteria');
	}

	public function testAddOrSameColumns()
	{
		$table1 = "myTable1";
		$column1 = "myColumn1";
		$value1 = "myValue1";
		$key1 = "$table1.$column1";

		$table2 = "myTable1";
		$column2 = "myColumn1";
		$value2 = "myValue2";
		$key2 = "$table2.$column2";

		$this->c->add($key1, $value1, Criteria::EQUAL);
		$this->c->addOr($key2, $value2, Criteria::EQUAL);

		$expect = "SELECT  FROM myTable1 WHERE (myTable1.myColumn1=:p1 OR myTable1.myColumn1=:p2)";

		$params = array();
		$result = BasePeer::createSelectSql($this->c, $params);

		$expect_params = array(
			array('table' => 'myTable1', 'column' => 'myColumn1', 'value' => 'myValue1'),
			array('table' => 'myTable1', 'column' => 'myColumn1', 'value' => 'myValue2'),
		);

		$this->assertEquals($expect, $result, 'addOr() called on an existing column creates a combined criterion');
		$this->assertEquals($expect_params, $params, 'addOr() called on an existing column creates a combined criterion');
	}

	public function testAddAndOrColumnsPropulsion14Compatibility()
	{
		$table1 = "myTable1";
		$column1 = "myColumn1";
		$value1 = "myValue1";
		$key1 = "$table1.$column1";

		$table2 = "myTable1";
		$column2 = "myColumn1";
		$value2 = "myValue2";
		$key2 = "$table2.$column2";

		$table3 = "myTable3";
		$column3 = "myColumn3";
		$value3 = "myValue3";
		$key3 = "$table3.$column3";

		$this->c->add($key1, $value1, Criteria::EQUAL);
		$this->c->add($key3, $value3, Criteria::EQUAL);
		$this->c->addOr($key2, $value2, Criteria::EQUAL);

		$expect = "SELECT  FROM myTable1, myTable3 WHERE (myTable1.myColumn1=:p1 OR myTable1.myColumn1=:p2) AND myTable3.myColumn3=:p3";

		$params = array();
		$result = BasePeer::createSelectSql($this->c, $params);

		$expect_params = array(
			array('table' => 'myTable1', 'column' => 'myColumn1', 'value' => 'myValue1'),
			array('table' => 'myTable1', 'column' => 'myColumn1', 'value' => 'myValue2'),
			array('table' => 'myTable3', 'column' => 'myColumn3', 'value' => 'myValue3'),
		);

		$this->assertEquals($expect, $result, 'addOr() called on an existing column creates a combined criterion');
		$this->assertEquals($expect_params, $params, 'addOr() called on an existing column creates a combined criterion');
	}

	public function testAddOrDistinctColumns()
	{
		$table1 = "myTable1";
		$column1 = "myColumn1";
		$value1 = "myValue1";
		$key1 = "$table1.$column1";

		$table2 = "myTable2";
		$column2 = "myColumn2";
		$value2 = "myValue2";
		$key2 = "$table2.$column2";

		$this->c->add($key1, $value1, Criteria::EQUAL);
		$this->c->addOr($key2, $value2, Criteria::EQUAL);

		$expect = "SELECT  FROM myTable1, myTable2 WHERE (myTable1.myColumn1=:p1 OR myTable2.myColumn2=:p2)";

		$params = array();
		$result = BasePeer::createSelectSql($this->c, $params);

		$expect_params = array(
			array('table' => 'myTable1', 'column' => 'myColumn1', 'value' => 'myValue1'),
			array('table' => 'myTable2', 'column' => 'myColumn2', 'value' => 'myValue2'),
		);

		$this->assertEquals($expect, $result, 'addOr() called on a distinct column adds a criterion to the latest criterion');
		$this->assertEquals($expect_params, $params, 'addOr() called on a distinct column adds a criterion to the latest criterion');
	}

	public function testAddOrEmptyCriteria()
	{
		$table1 = "myTable1";
		$column1 = "myColumn1";
		$value1 = "myValue1";
		$key1 = "$table1.$column1";

		$this->c->addOr($key1, $value1, Criteria::EQUAL);

		$expect = "SELECT  FROM myTable1 WHERE myTable1.myColumn1=:p1";

		$params = array();
		$result = BasePeer::createSelectSql($this->c, $params);

		$expect_params = array(
			array('table' => 'myTable1', 'column' => 'myColumn1', 'value' => 'myValue1'),
		);

		$this->assertEquals($expect, $result, 'addOr() called on an empty Criteria adds a criterion to the criteria');
		$this->assertEquals($expect_params, $params, 'addOr() called on an empty Criteria adds a criterion to the criteria');
	}

	/**
	 * Test Criterion.setIgnoreCase().
	 * As the output is db specific the test just prints the result to
	 * System.out
	 */
	public function testCriterionIgnoreCase()
	{
		$originalDB = Propulsion::getDB();
		$adapters = array(new DBMySQL(), new DBPostgres());
		$expectedIgnore = array("UPPER(TABLE.COLUMN) LIKE UPPER(:p1)", "TABLE.COLUMN ILIKE :p1");

		$i =0;
		foreach ($adapters as $adapter) {

			Propulsion::setDB(null, $adapter);
			$myCriteria = new Criteria();

			$myCriterion = $myCriteria->getNewCriterion(
				"TABLE.COLUMN", "FoObAr", Criteria::LIKE);
			$sb = "";
			$params=array();
			$myCriterion->appendPsTo($sb, $params);
			$expected = "TABLE.COLUMN LIKE :p1";

			$this->assertEquals($expected, $sb);

			$ignoreCriterion = $myCriterion->setIgnoreCase(true);

			$sb = "";
			$params=array();
			$ignoreCriterion->appendPsTo($sb, $params);
			// $expected = "UPPER(TABLE.COLUMN) LIKE UPPER(?)";
			$this->assertEquals($expectedIgnore[$i], $sb);
			$i++;
		}
		Propulsion::setDB(null, $originalDB);
	}

	/**
	 * Rendering a criterion must not rewrite it.
	 *
	 * The ignore-case Postgres branch used to assign Criteria::ILIKE back onto
	 * $this->comparison, so a criterion rendered once for Postgres stayed
	 * rewritten. Invisible on Postgres alone (the rewrite is idempotent -- a
	 * second render sees ILIKE and matches neither branch) but not across
	 * adapters, which is what testCriterionIgnoreCase() above cannot catch: it
	 * builds a fresh criterion per adapter, so nothing carries over.
	 */
	public function testCriterionIgnoreCaseDoesNotMutateTheCriterion()
	{
		$originalDB = Propulsion::getDB();
		try {
			Propulsion::setDB(null, new DBPostgres());
			$criterion = (new Criteria())
				->getNewCriterion('TABLE.COLUMN', 'FoObAr', Criteria::LIKE)
				->setIgnoreCase(true);

			$sb = '';
			$params = array();
			$criterion->appendPsTo($sb, $params);
			$this->assertEquals('TABLE.COLUMN ILIKE :p1', $sb, 'Postgres still renders ILIKE');

			$this->assertEquals(
				Criteria::LIKE,
				$criterion->getComparison(),
				'the criterion still reports the comparison the caller asked for; getComparison() is '
				. 'public API and feeds Criteria::equals()/addJoinObject() dedupe'
			);
		} finally {
			Propulsion::setDB(null, $originalDB);
		}
	}

	/**
	 * The consequence of the above: one Criteria rendered for two datasources
	 * with different adapters. MySQL has no ILIKE operator, so the leaked
	 * rewrite produced a syntax error on the second query.
	 */
	public function testIgnoreCaseLikeDoesNotLeakIlikeOntoANonPostgresAdapter()
	{
		$originalDB = Propulsion::getDB();
		try {
			$criterion = (new Criteria())
				->getNewCriterion('TABLE.COLUMN', 'FoObAr', Criteria::LIKE)
				->setIgnoreCase(true);

			$criterion->setDB(new DBPostgres());
			$sb = '';
			$params = array();
			$criterion->appendPsTo($sb, $params);
			$this->assertStringContainsString('ILIKE', $sb);

			// Same criterion, different adapter.
			$criterion->setDB(new DBMySQL());
			$sb = '';
			$params = array();
			$criterion->appendPsTo($sb, $params);

			$this->assertStringNotContainsString(
				'ILIKE',
				$sb,
				'MySQL has no ILIKE operator; a rewrite from an earlier render must not reach it'
			);
			$this->assertEquals('UPPER(TABLE.COLUMN) LIKE UPPER(:p1)', $sb);
		} finally {
			Propulsion::setDB(null, $originalDB);
		}
	}

	/**
	 * Criteria::__clone() deep-clones its own nested-Criteria collections
	 * (selectQueries, setOperations, CTE queries) so a clone cannot mutate the
	 * original's subqueries -- isKeepQuery() defaults to true, so every
	 * find()/count()/update() clones for exactly that reason, and rendering does
	 * write to the Criteria it renders. The EXISTS/IN subquery, which lives in
	 * Criterion::$value rather than in one of those collections, was the one kind
	 * still shared by reference.
	 */
	public function testCloneDeepClonesAnExistsSubquery()
	{
		$subQuery = new Criteria('bookstore');
		$subQuery->addSelectColumn('review.ID');

		$c = new Criteria('bookstore');
		$c->addSelectColumn('book.ID');
		$c->addExistsQuery($subQuery);

		$clone = clone $c;

		$originalSub = $this->onlyCriterionValue($c);
		$clonedSub = $this->onlyCriterionValue($clone);

		$this->assertInstanceOf(Criteria::class, $originalSub);
		$this->assertInstanceOf(Criteria::class, $clonedSub);
		$this->assertNotSame($originalSub, $clonedSub, 'the subquery must not be shared with the clone');

		// And the two are genuinely independent, which is the property that matters.
		$clonedSub->addSelectColumn('review.STARS');
		$this->assertEquals(array('review.ID'), $originalSub->getSelectColumns());
		$this->assertEquals(array('review.ID', 'review.STARS'), $clonedSub->getSelectColumns());
	}

	public function testCloneDeepClonesAnInSubquery()
	{
		// addInQuery() stores its subquery the same way, so it needs its own
		// assertion rather than riding on addExistsQuery()'s.
		$subQuery = new Criteria('bookstore');
		$subQuery->addSelectColumn('review.BOOK_ID');

		$c = new Criteria('bookstore');
		$c->addSelectColumn('book.ID');
		$c->addInQuery('book.ID', $subQuery);

		$clone = clone $c;

		$originalSub = $this->onlyCriterionValue($c);
		$clonedSub = $this->onlyCriterionValue($clone);

		$this->assertInstanceOf(Criteria::class, $originalSub);
		$this->assertInstanceOf(Criteria::class, $clonedSub);
		$this->assertNotSame($originalSub, $clonedSub);
	}

	/**
	 * A criterion whose value is *not* a Criteria must be copied as-is -- cloning
	 * scalars is meaningless and cloning an arbitrary caller-supplied object
	 * would change behaviour well beyond the subquery case.
	 */
	public function testCloneLeavesANonCriteriaValueAlone()
	{
		$value = new stdClass();
		$value->marker = 'original';

		$c = new Criteria('bookstore');
		$c->add('book.TITLE', $value);

		$clone = clone $c;

		$this->assertSame(
			$value,
			$this->onlyCriterionValue($clone),
			'only a Criteria value is deep-cloned; anything else is left exactly as the caller passed it'
		);
	}

	/**
	 * The value of the single criterion in $c.
	 */
	private function onlyCriterionValue(Criteria $c): mixed
	{
		$keys = array_keys($c->getMap());
		$this->assertCount(1, $keys, 'this helper assumes exactly one criterion');

		return $c->getCriterion($keys[0])->getValue();
	}

	public function testOrderByIgnoreCase()
	{
		$originalDB = Propulsion::getDB();
		Propulsion::setDB(null, new DBMySQL());

		// This test never calls Propulsion::init() (no live DB/Docker needed -- see
		// setUp() above), so the only registered datasource is the unnamed "default"
		// one this file's setUp() points at DBMySQL()/DBSQLite(); BaseBookPeer::
		// buildTableMap() (run automatically once BookPeer's class file is
		// autoloaded, from the BookPeer::TITLE reference below) instead registers
		// the 'book' table under BookPeer::DATABASE_NAME ('bookstore'), since that's
		// what's baked into the generated class. Rather than pointing this Criteria
		// at 'bookstore' (which has no registered adapter here, only 'default'
		// does), copy the 'book' TableMap into the 'default' DatabaseMap too, so
		// BasePeer::createSelectSql() can resolve both the table and the adapter
		// under the same (default) name -- exactly as if this table had been
		// declared directly against the datasource this test is using.
		$defaultDbMap = Propulsion::getDatabaseMap();
		if (!$defaultDbMap->hasTable(BookPeer::TABLE_NAME)) {
			$defaultDbMap->addTableObject(new BookTableMap());
		}

		$criteria = new Criteria();
		$criteria->setIgnoreCase(true);
		$criteria->addAscendingOrderByColumn(BookPeer::TITLE);
		BookPeer::addSelectColumns($criteria);
		$params=array();
		$sql = BasePeer::createSelectSql($criteria, $params);
		$expectedSQL = 'SELECT book.ID, book.TITLE, book.ISBN, book.PRICE, book.PUBLISHER_ID, book.AUTHOR_ID, UPPER(book.TITLE) FROM `book` ORDER BY UPPER(book.TITLE) ASC';
		$this->assertEquals($expectedSQL, $sql);

		Propulsion::setDB(null, $originalDB);
	}

	/**
	 * Test that true is evaluated correctly.
	 */
	public function testBoolean()
	{
		$this->c = new Criteria();
		$this->c->add("TABLE.COLUMN", true);

		$expect = "SELECT  FROM TABLE WHERE TABLE.COLUMN=:p1";
		$expect_params = array( array('table' => 'TABLE', 'column' => 'COLUMN', 'value' => true),
		);
		try {
			$params = array();
			$result = BasePeer::createSelectSql($this->c, $params);
		} catch (PropulsionException $e) {
			$this->fail("PropulsionException thrown in BasePeer.createSelectSql(): ". $e->getMessage());
		}

		$this->assertEquals($expect, $result, "Boolean test failed.");
		$this->assertEquals($expect_params, $params);

	}

	public function testCurrentDate()
	{
		$this->c = new Criteria();
		$this->c->add("TABLE.TIME_COLUMN", Criteria::CURRENT_TIME);
		$this->c->add("TABLE.DATE_COLUMN", Criteria::CURRENT_DATE);

		$expect = "SELECT  FROM TABLE WHERE TABLE.TIME_COLUMN=CURRENT_TIME AND TABLE.DATE_COLUMN=CURRENT_DATE";

		$result = null;
		try {
			$params = array();
			$result = BasePeer::createSelectSql($this->c, $params);
		} catch (PropulsionException $e) {
			print $e->getTraceAsString();
			$this->fail("PropulsionException thrown in BasePeer.createSelectSql(): ". $e->getMessage());
		}

		$this->assertEquals($expect, $result, "Current date test failed!");

	}

	public function testCountAster()
	{
		$this->c = new Criteria();
		$this->c->addSelectColumn("COUNT(*)");
		$this->c->add("TABLE.TIME_COLUMN", Criteria::CURRENT_TIME);
		$this->c->add("TABLE.DATE_COLUMN", Criteria::CURRENT_DATE);

		$expect = "SELECT COUNT(*) FROM TABLE WHERE TABLE.TIME_COLUMN=CURRENT_TIME AND TABLE.DATE_COLUMN=CURRENT_DATE";

		$result = null;
		try {
			$params = array();
			$result = BasePeer::createSelectSql($this->c, $params);
		} catch (PropulsionException $e) {
			print $e->getTraceAsString();
			$this->fail("PropulsionException thrown in BasePeer.createSelectSql(): ". $e->getMessage());
		}

		$this->assertEquals($expect, $result);

	}

	public function testIn()
	{
		$c = new Criteria();
		$c->addSelectColumn("*");
		$c->add("TABLE.SOME_COLUMN", array(), Criteria::IN);
		$c->add("TABLE.OTHER_COLUMN", array(1, 2, 3), Criteria::IN);

		$expect = "SELECT * FROM TABLE WHERE 1<>1 AND TABLE.OTHER_COLUMN IN (:p1,:p2,:p3)";
		try {
			$params = array();
			$result = BasePeer::createSelectSql($c, $params);
		} catch (PropulsionException $e) {
			print $e->getTraceAsString();
			$this->fail("PropulsionException thrown in BasePeer.createSelectSql(): ". $e->getMessage());
		}
		$this->assertEquals($expect, $result);
	}

	public function testInEmptyAfterFull()
	{
		$c = new Criteria();
		$c->addSelectColumn("*");
		$c->add("TABLE.OTHER_COLUMN", array(1, 2, 3), Criteria::IN);
		$c->add("TABLE.SOME_COLUMN", array(), Criteria::IN);

		$expect = "SELECT * FROM TABLE WHERE TABLE.OTHER_COLUMN IN (:p1,:p2,:p3) AND 1<>1";
		try {
			$params = array();
			$result = BasePeer::createSelectSql($c, $params);
		} catch (PropulsionException $e) {
			print $e->getTraceAsString();
			$this->fail("PropulsionException thrown in BasePeer.createSelectSql(): ". $e->getMessage());
		}
		$this->assertEquals($expect, $result);
	}

	public function testInNested()
	{
		// now do a nested logic test, just for sanity (not that this should be any surprise)

		$c = new Criteria();
		$c->addSelectColumn("*");
		$myCriterion = $c->getNewCriterion("TABLE.COLUMN", array(), Criteria::IN);
		$myCriterion->addOr($c->getNewCriterion("TABLE.COLUMN2", array(1,2), Criteria::IN));
		$c->add($myCriterion);

		$expect = "SELECT * FROM TABLE WHERE (1<>1 OR TABLE.COLUMN2 IN (:p1,:p2))";
		try {
			$params = array();
			$result = BasePeer::createSelectSql($c, $params);
		} catch (PropulsionException $e) {
			print $e->getTraceAsString();
			$this->fail("PropulsionException thrown in BasePeer.createSelectSql(): ". $e->getMessage());
		}
		$this->assertEquals($expect, $result);

	}

	public function testJoinObject ()
	{
		$j = new Join('TABLE_A.COL_1', 'TABLE_B.COL_2');
		$this->assertEquals('INNER JOIN', $j->getJoinType());
		$this->assertEquals('TABLE_A.COL_1', $j->getLeftColumn());
		$this->assertEquals('TABLE_A', $j->getLeftTableName());
		$this->assertEquals('COL_1', $j->getLeftColumnName());
		$this->assertEquals('TABLE_B.COL_2', $j->getRightColumn());
		$this->assertEquals('TABLE_B', $j->getRightTableName());
		$this->assertEquals('COL_2', $j->getRightColumnName());

		$j = new Join('TABLE_A.COL_1', 'TABLE_B.COL_1', Criteria::LEFT_JOIN);
		$this->assertEquals('LEFT JOIN', $j->getJoinType());
		$this->assertEquals('TABLE_A.COL_1', $j->getLeftColumn());
		$this->assertEquals('TABLE_B.COL_1', $j->getRightColumn());

		$j = new Join('TABLE_A.COL_1', 'TABLE_B.COL_1', Criteria::RIGHT_JOIN);
		$this->assertEquals('RIGHT JOIN', $j->getJoinType());
		$this->assertEquals('TABLE_A.COL_1', $j->getLeftColumn());
		$this->assertEquals('TABLE_B.COL_1', $j->getRightColumn());

		$j = new Join('TABLE_A.COL_1', 'TABLE_B.COL_1', Criteria::INNER_JOIN);
		$this->assertEquals('INNER JOIN', $j->getJoinType());
		$this->assertEquals('TABLE_A.COL_1', $j->getLeftColumn());
		$this->assertEquals('TABLE_B.COL_1', $j->getRightColumn());

		$j = new Join(array('TABLE_A.COL_1', 'TABLE_A.COL_2'), array('TABLE_B.COL_1', 'TABLE_B.COL_2'), Criteria::INNER_JOIN);
		$this->assertEquals('TABLE_A.COL_1', $j->getLeftColumn(0));
		$this->assertEquals('TABLE_A.COL_2', $j->getLeftColumn(1));
		$this->assertEquals('TABLE_B.COL_1', $j->getRightColumn(0));
		$this->assertEquals('TABLE_B.COL_2', $j->getRightColumn(1));
	}

	public function testAddStraightJoin ()
	{
		$c = new Criteria();
		$c->addSelectColumn("*");
		$c->addJoin('TABLE_A.COL_1', 'TABLE_B.COL_1'); // straight join

		$expect = "SELECT * FROM TABLE_A INNER JOIN TABLE_B ON (TABLE_A.COL_1=TABLE_B.COL_1)";
		try {
			$params = array();
			$result = BasePeer::createSelectSql($c, $params);
		} catch (PropulsionException $e) {
			print $e->getTraceAsString();
			$this->fail("PropulsionException thrown in BasePeer.createSelectSql(): ". $e->getMessage());
		}
		$this->assertEquals($expect, $result);
	}

	public function testAddSeveralJoins ()
	{
		$c = new Criteria();
		$c->addSelectColumn("*");
		$c->addJoin('TABLE_A.COL_1', 'TABLE_B.COL_1');
		$c->addJoin('TABLE_B.COL_X', 'TABLE_D.COL_X');

		$expect = 'SELECT * FROM TABLE_A INNER JOIN TABLE_B ON (TABLE_A.COL_1=TABLE_B.COL_1)'
			. ' INNER JOIN TABLE_D ON (TABLE_B.COL_X=TABLE_D.COL_X)';
		try {
			$params = array();
			$result = BasePeer::createSelectSql($c, $params);
		} catch (PropulsionException $e) {
			print $e->getTraceAsString();
			$this->fail("PropulsionException thrown in BasePeer.createSelectSql(): ". $e->getMessage());
		}
		$this->assertEquals($expect, $result);
	}

	public function testAddLeftJoin ()
	{
		$c = new Criteria();
		$c->addSelectColumn("TABLE_A.*");
		$c->addSelectColumn("TABLE_B.*");
		$c->addJoin('TABLE_A.COL_1', 'TABLE_B.COL_2', Criteria::LEFT_JOIN);

		$expect = "SELECT TABLE_A.*, TABLE_B.* FROM TABLE_A LEFT JOIN TABLE_B ON (TABLE_A.COL_1=TABLE_B.COL_2)";
		try {
			$params = array();
			$result = BasePeer::createSelectSql($c, $params);
		} catch (PropulsionException $e) {
			print $e->getTraceAsString();
			$this->fail("PropulsionException thrown in BasePeer.createSelectSql(): ". $e->getMessage());
		}
		$this->assertEquals($expect, $result);
	}

	public function testAddSeveralLeftJoins ()
	{
		// Fails.. Suspect answer in the chunk starting at BasePeer:605
		$c = new Criteria();
		$c->addSelectColumn('*');
		$c->addJoin('TABLE_A.COL_1', 'TABLE_B.COL_1', Criteria::LEFT_JOIN);
		$c->addJoin('TABLE_A.COL_2', 'TABLE_C.COL_2', Criteria::LEFT_JOIN);

		$expect = 'SELECT * FROM TABLE_A '
			.'LEFT JOIN TABLE_B ON (TABLE_A.COL_1=TABLE_B.COL_1) '
			.'LEFT JOIN TABLE_C ON (TABLE_A.COL_2=TABLE_C.COL_2)';
		try {
			$params = array();
			$result = BasePeer::createSelectSql($c, $params);
		} catch (PropulsionException $e) {
			print $e->getTraceAsString();
			$this->fail("PropulsionException thrown in BasePeer.createSelectSql(): ". $e->getMessage());
		}
		$this->assertEquals($expect, $result);
	}

	public function testAddRightJoin ()
	{
		$c = new Criteria();
		$c->addSelectColumn("*");
		$c->addJoin('TABLE_A.COL_1', 'TABLE_B.COL_2', Criteria::RIGHT_JOIN);

		$expect = "SELECT * FROM TABLE_A RIGHT JOIN TABLE_B ON (TABLE_A.COL_1=TABLE_B.COL_2)";
		try {
			$params = array();
			$result = BasePeer::createSelectSql($c, $params);
		} catch (PropulsionException $e) {
			print $e->getTraceAsString();
			$this->fail("PropulsionException thrown in BasePeer.createSelectSql(): ". $e->getMessage());
		}
		$this->assertEquals($expect, $result);
	}

	public function testAddSeveralRightJoins ()
	{
		// Fails.. Suspect answer in the chunk starting at BasePeer:605
		$c = new Criteria();
		$c->addSelectColumn('*');
		$c->addJoin('TABLE_A.COL_1', 'TABLE_B.COL_1', Criteria::RIGHT_JOIN);
		$c->addJoin('TABLE_A.COL_2', 'TABLE_C.COL_2', Criteria::RIGHT_JOIN);

		$expect = 'SELECT * FROM TABLE_A '
			.'RIGHT JOIN TABLE_B ON (TABLE_A.COL_1=TABLE_B.COL_1) '
			.'RIGHT JOIN TABLE_C ON (TABLE_A.COL_2=TABLE_C.COL_2)';
		try {
			$params = array();
			$result = BasePeer::createSelectSql($c, $params);
		} catch (PropulsionException $e) {
			print $e->getTraceAsString();
			$this->fail("PropulsionException thrown in BasePeer.createSelectSql(): ". $e->getMessage());
		}
		$this->assertEquals($expect, $result);
	}

	public function testAddInnerJoin ()
	{
		$c = new Criteria();
		$c->addSelectColumn("*");
		$c->addJoin('TABLE_A.COL_1', 'TABLE_B.COL_1', Criteria::INNER_JOIN);

		$expect = "SELECT * FROM TABLE_A INNER JOIN TABLE_B ON (TABLE_A.COL_1=TABLE_B.COL_1)";
		try {
			$params = array();
			$result = BasePeer::createSelectSql($c, $params);
		} catch (PropulsionException $e) {
			print $e->getTraceAsString();
			$this->fail("PropulsionException thrown in BasePeer.createSelectSql(): ". $e->getMessage());
		}
		$this->assertEquals($expect, $result);
	}

	public function testAddSeveralInnerJoin ()
	{
		$c = new Criteria();
		$c->addSelectColumn("*");
		$c->addJoin('TABLE_A.COL_1', 'TABLE_B.COL_1', Criteria::INNER_JOIN);
		$c->addJoin('TABLE_B.COL_1', 'TABLE_C.COL_1', Criteria::INNER_JOIN);

		$expect = 'SELECT * FROM TABLE_A '
			.'INNER JOIN TABLE_B ON (TABLE_A.COL_1=TABLE_B.COL_1) '
			.'INNER JOIN TABLE_C ON (TABLE_B.COL_1=TABLE_C.COL_1)';
		try {
			$params = array();
			$result = BasePeer::createSelectSql($c, $params);
		} catch (PropulsionException $e) {
			print $e->getTraceAsString();
			$this->fail("PropulsionException thrown in BasePeer.createSelectSql(): ". $e->getMessage());
		}
		$this->assertEquals($expect, $result);
	}

	/**
	 * @link       http://www.propelorm.org/ticket/451
	 * @link       http://www.propelorm.org/ticket/283#comment:8
	 */
	public function testSeveralMixedJoinOrders()
	{
		$c = new Criteria();
		$c->clearSelectColumns()->
			addJoin("TABLE_A.FOO_ID", "TABLE_B.ID", Criteria::LEFT_JOIN)->
			addJoin("TABLE_A.BAR_ID", "TABLE_C.ID")->
			addSelectColumn("TABLE_A.ID");

		$expect = 'SELECT TABLE_A.ID FROM TABLE_A LEFT JOIN TABLE_B ON (TABLE_A.FOO_ID=TABLE_B.ID) INNER JOIN TABLE_C ON (TABLE_A.BAR_ID=TABLE_C.ID)';
		$params = array();
		$result = BasePeer::createSelectSql($c, $params);
		$this->assertEquals($expect, $result);
	}

	public function testAddJoinArray()
	{
		$c = new Criteria();
		$c->clearSelectColumns()->
			addJoin(array('TABLE_A.FOO_ID'), array('TABLE_B.ID'), Criteria::LEFT_JOIN)->
			addSelectColumn("TABLE_A.ID");

		$expect = 'SELECT TABLE_A.ID FROM TABLE_A LEFT JOIN TABLE_B ON TABLE_A.FOO_ID=TABLE_B.ID';
		$params = array();
		$result = BasePeer::createSelectSql($c, $params);
		$this->assertEquals($expect, $result);
	}

	public function testAddJoinArrayMultiple()
	{
		$c = new Criteria();
		$c->clearSelectColumns()->
			addJoin(
				array('TABLE_A.FOO_ID', 'TABLE_A.BAR'),
				array('TABLE_B.ID', 'TABLE_B.BAZ'),
				Criteria::LEFT_JOIN)->
				addSelectColumn("TABLE_A.ID");

		$expect = 'SELECT TABLE_A.ID FROM TABLE_A LEFT JOIN TABLE_B ON (TABLE_A.FOO_ID=TABLE_B.ID AND TABLE_A.BAR=TABLE_B.BAZ)';
		$params = array();
		$result = BasePeer::createSelectSql($c, $params);
		$this->assertEquals($expect, $result);
	}

	/**
	 * Test the Criteria::addJoinMultiple() method with an implicit join
	 *
	 */
	public function testAddJoinMultiple()
	{
		$c = new Criteria();
		$c->
			clearSelectColumns()->
			addMultipleJoin(array(
				array('TABLE_A.FOO_ID', 'TABLE_B.ID'),
				array('TABLE_A.BAR', 'TABLE_B.BAZ')))->
				addSelectColumn("TABLE_A.ID");

		$expect = 'SELECT TABLE_A.ID FROM TABLE_A INNER JOIN TABLE_B '
			. 'ON (TABLE_A.FOO_ID=TABLE_B.ID AND TABLE_A.BAR=TABLE_B.BAZ)';
		$params = array();
		$result = BasePeer::createSelectSql($c, $params);
		$this->assertEquals($expect, $result);
	}

	/**
	 * Test the Criteria::addJoinMultiple() method with a value as second argument
	 *
	 */
	public function testAddJoinMultipleValue()
	{
		$c = new Criteria();
		$c->
			clearSelectColumns()->
			addMultipleJoin(array(
				array('TABLE_A.FOO_ID', 'TABLE_B.ID'),
				array('TABLE_A.BAR', 3)))->
				addSelectColumn("TABLE_A.ID");

		$expect = 'SELECT TABLE_A.ID FROM TABLE_A INNER JOIN TABLE_B '
			. 'ON (TABLE_A.FOO_ID=TABLE_B.ID AND TABLE_A.BAR=3)';
		$params = array();
		$result = BasePeer::createSelectSql($c, $params);
		$this->assertEquals($expect, $result);
	}

	/**
	 * Test the Criteria::addJoinMultiple() method with a joinType
	 *
	 */
	public function testAddJoinMultipleWithJoinType()
	{
		$c = new Criteria();
		$c->
			clearSelectColumns()->
			addMultipleJoin(array(
				array('TABLE_A.FOO_ID', 'TABLE_B.ID'),
				array('TABLE_A.BAR', 'TABLE_B.BAZ')),
			Criteria::LEFT_JOIN)->
			addSelectColumn("TABLE_A.ID");

		$expect = 'SELECT TABLE_A.ID FROM TABLE_A '
			. 'LEFT JOIN TABLE_B ON (TABLE_A.FOO_ID=TABLE_B.ID AND TABLE_A.BAR=TABLE_B.BAZ)';
		$params = array();
		$result = BasePeer::createSelectSql($c, $params);
		$this->assertEquals($expect, $result);
	}

	/**
	 * Test the Criteria::addJoinMultiple() method with operator
	 *
	 */
	public function testAddJoinMultipleWithOperator()
	{
		$c = new Criteria();
		$c->
			clearSelectColumns()->
			addMultipleJoin(array(
				array('TABLE_A.FOO_ID', 'TABLE_B.ID', Criteria::GREATER_EQUAL),
				array('TABLE_A.BAR', 'TABLE_B.BAZ', Criteria::LESS_THAN)))->
				addSelectColumn("TABLE_A.ID");

		$expect = 'SELECT TABLE_A.ID FROM TABLE_A INNER JOIN TABLE_B '
			. 'ON (TABLE_A.FOO_ID>=TABLE_B.ID AND TABLE_A.BAR<TABLE_B.BAZ)';
		$params = array();
		$result = BasePeer::createSelectSql($c, $params);
		$this->assertEquals($expect, $result);
	}

	/**
	 * Test the Criteria::addJoinMultiple() method with join type and operator
	 *
	 */
	public function testAddJoinMultipleWithJoinTypeAndOperator()
	{
		$c = new Criteria();
		$c->
			clearSelectColumns()->
			addMultipleJoin(array(
				array('TABLE_A.FOO_ID', 'TABLE_B.ID', Criteria::GREATER_EQUAL),
				array('TABLE_A.BAR', 'TABLE_B.BAZ', Criteria::LESS_THAN)),
			Criteria::LEFT_JOIN)->
			addSelectColumn("TABLE_A.ID");

		$expect = 'SELECT TABLE_A.ID FROM TABLE_A '
			. 'LEFT JOIN TABLE_B ON (TABLE_A.FOO_ID>=TABLE_B.ID AND TABLE_A.BAR<TABLE_B.BAZ)';
		$params = array();
		$result = BasePeer::createSelectSql($c, $params);
		$this->assertEquals($expect, $result);
	}

	/**
	 * Test the Criteria::CUSTOM behavior.
	 */
	public function testCustomOperator()
	{
		$c = new Criteria();
		$c->addSelectColumn('A.COL');
		$c->add('A.COL', 'date_part(\'YYYY\', A.COL) = \'2007\'', Criteria::CUSTOM);

		$expected = "SELECT A.COL FROM A WHERE date_part('YYYY', A.COL) = '2007'";
		$params = array();
		$result = BasePeer::createSelectSql($c, $params);
		$this->assertEquals($expected, $result);
	}

	/**
	 * Tests adding duplicate joins.
	 */
	public function testAddJoin_Duplicate()
	{
		$c = new Criteria();

		$c->addJoin("tbl.COL1", "tbl.COL2", Criteria::LEFT_JOIN);
		$c->addJoin("tbl.COL1", "tbl.COL2", Criteria::LEFT_JOIN);
		$this->assertEquals(1, count($c->getJoins()), "Expected not to have duplciate LJOIN added.");

		$c->addJoin("tbl.COL1", "tbl.COL2", Criteria::RIGHT_JOIN);
		$c->addJoin("tbl.COL1", "tbl.COL2", Criteria::RIGHT_JOIN);
		$this->assertEquals(2, count($c->getJoins()), "Expected 1 new right join to be added.");

		$c->addJoin("tbl.COL1", "tbl.COL2");
		$c->addJoin("tbl.COL1", "tbl.COL2");
		$this->assertEquals(3, count($c->getJoins()), "Expected 1 new implicit join to be added.");

		$c->addJoin("tbl.COL3", "tbl.COL4");
		$this->assertEquals(4, count($c->getJoins()), "Expected new col join to be added.");

	}

	public function testHasSelectClause()
	{
		$c = new Criteria();
		$c->addSelectColumn("foo");

		$this->assertTrue($c->hasSelectClause());

		$c = new Criteria();
		$c->addAsColumn("foo", "bar");

		$this->assertTrue($c->hasSelectClause());
	}

	/**
	 * Tests including aliases in criterion objects.
	 */
	public function testAliasInCriterion()
	{
		$c = new Criteria();
		$c->addAsColumn("column_alias", "tbl.COL1");
		$crit = $c->getNewCriterion("column_alias", "FOO");
		$this->assertNull($crit->getTable());
		$this->assertEquals("column_alias", $crit->getColumn());
		$c->addHaving($crit); // produces invalid SQL referring to '.olumn_alias'
	}

	/**
	 * Test whether GROUP BY is being respected in equals() check.
	 */
	public function testEqualsGroupBy()
	{
		$c1 = new Criteria();
		$c1->addGroupByColumn('GBY1');

		$c2 = new Criteria();
		$c2->addGroupByColumn('GBY2');

		$this->assertFalse($c2->equals($c1), "Expected Criteria NOT to be the same with different GROUP BY columns");

		$c3 = new Criteria();
		$c3->addGroupByColumn('GBY1');
		$c4 = new Criteria();
		$c4->addGroupByColumn('GBY1');
		$this->assertTrue($c4->equals($c3), "Expected Criteria objects to match.");
	}

	/**
	 * Test whether calling setDistinct twice puts in two distinct keywords or not.
	 */
	public function testDoubleSelectModifiers()
	{
		$c = new Criteria();
		$c->setDistinct();
		$this->assertEquals(array(Criteria::DISTINCT), $c->getSelectModifiers(), 'Initial setDistinct works');
		$c->setDistinct();
		$this->assertEquals(array(Criteria::DISTINCT), $c->getSelectModifiers(), 'Calling setDistinct again leaves a single distinct');
		$c->setAll();
		$this->assertEquals(array(Criteria::ALL), $c->getSelectModifiers(), 'All keyword is swaps distinct out');
		$c->setAll();
		$this->assertEquals(array(Criteria::ALL), $c->getSelectModifiers(), 'Calling setAll leaves a single all');
		$c->setDistinct();
		$this->assertEquals(array(Criteria::DISTINCT), $c->getSelectModifiers(), 'All back to distinct works');

		$c2 = new Criteria();
		$c2->setAll();
		$this->assertEquals(array(Criteria::ALL), $c2->getSelectModifiers(), 'Initial setAll works');
	}

	public function testAddSelectModifier()
	{
		$c = new Criteria();
		$c->setDistinct();
		$c->addSelectModifier('SQL_CALC_FOUND_ROWS');
		$this->assertEquals(array(Criteria::DISTINCT, 'SQL_CALC_FOUND_ROWS'), $c->getSelectModifiers(), 'addSelectModifier() adds a select modifier to the Criteria');
		$c->addSelectModifier('SQL_CALC_FOUND_ROWS');
		$this->assertEquals(array(Criteria::DISTINCT, 'SQL_CALC_FOUND_ROWS'), $c->getSelectModifiers(), 'addSelectModifier() adds a select modifier only once');
		$params = array();
		$result = BasePeer::createSelectSql($c, $params);
		$this->assertEquals('SELECT DISTINCT SQL_CALC_FOUND_ROWS  FROM ', $result, 'addSelectModifier() adds a modifier to the final query');
	}

	public function testClone()
	{
		$c1 = new Criteria();
		$c1->add('tbl.COL1', 'foo', Criteria::EQUAL);
		$c2 = clone $c1;
		$c2->addAnd('tbl.COL1', 'bar', Criteria::EQUAL);
		$nbCrit = 0;
		foreach ($c1->keys() as $key) {
			foreach ($c1->getCriterion($key)->getAttachedCriterion() as $criterion) {
				$nbCrit++;
			}
		}
		$this->assertEquals(1, $nbCrit, 'cloning a Criteria clones its Criterions');
	}

	public function testCompiledQueryCacheKeyIsScopedToTheDatasource()
	{
		// The compiled-query cache stores SQL *text*, and the adapter decides
		// what that text looks like -- MySQL writes "LIMIT offset, limit" where
		// SQLite writes "LIMIT limit OFFSET offset". The caller-supplied key
		// identifies the query *shape* and, per the documented recommendation,
		// is usually just __METHOD__, which is identical for both datasources.
		// Without the datasource in the internal key, the second datasource is
		// served the first one's dialect, and the paramCount guard cannot see
		// it: the shapes match, only the dialect differs.
		Propulsion::setDB('cqc_sqlite_ds', new DBSQLite());
		Propulsion::setDB('cqc_mysql_ds', new DBMySQL());
		Propulsion::getServiceContainer()->getCompiledQueryCache()->clear();

		$sharedKey = 'same-shape-key';

		$sqliteCriteria = new Criteria('cqc_sqlite_ds');
		$sqliteCriteria->addSelectColumn('book.TITLE');
		$sqliteCriteria->setLimit(5);
		$sqliteCriteria->setOffset(3);
		$sqliteCriteria->setCompiledQueryCache($sharedKey);
		$params = array();
		$sqliteSql = BasePeer::createSelectSql($sqliteCriteria, $params);

		$mysqlCriteria = new Criteria('cqc_mysql_ds');
		$mysqlCriteria->addSelectColumn('book.TITLE');
		$mysqlCriteria->setLimit(5);
		$mysqlCriteria->setOffset(3);
		$mysqlCriteria->setCompiledQueryCache($sharedKey);
		$params = array();
		$mysqlSql = BasePeer::createSelectSql($mysqlCriteria, $params);

		$this->assertStringContainsString('LIMIT 5 OFFSET 3', $sqliteSql, 'sanity: SQLite dialect');
		$this->assertStringContainsString('LIMIT 3, 5', $mysqlSql, 'the MySQL datasource must not be served the SQLite dialect from cache');
		$this->assertNotSame($sqliteSql, $mysqlSql);

		Propulsion::getServiceContainer()->getCompiledQueryCache()->clear();
	}

	public function testCloneDeepCopiesNestedSubqueriesSetOperationsAndCtes()
	{
		// isKeepQuery() defaults to true, so every find()/count()/update()
		// clones the query specifically so the caller's object is not mutated.
		// These three collections were shallow-copied, leaving a clone sharing
		// its nested Criteria with the original.
		$sub = new Criteria();
		$sub->add('sub.COL', 'a');
		$branch = new Criteria();
		$branch->add('branch.COL', 'b');
		$cte = new Criteria();
		$cte->add('cte.COL', 'c');

		$c1 = new Criteria();
		$c1->addSelectQuery($sub, 'sq');
		$c1->union($branch);
		$c1->withCte('recent', $cte);

		$c2 = clone $c1;

		$this->assertNotSame($sub, $c2->getSelectQuery('sq'), 'a FROM-clause subquery is cloned');

		$c2Operations = $c2->getSetOperations();
		$this->assertNotSame($branch, $c2Operations[0][1], 'a set-operation branch is cloned');
		$this->assertSame(Criteria::UNION, $c2Operations[0][0], 'the operator is preserved');

		$c2Ctes = $c2->getCommonTableExpressions();
		$this->assertNotSame($cte, $c2Ctes[0]['query'], 'a CTE query is cloned');
		$this->assertSame('recent', $c2Ctes[0]['name'], 'the CTE name is preserved');

		// And the copies really are independent, not just distinct objects.
		$c2->getSelectQuery('sq')->add('sub.COL', 'changed');
		$this->assertSame('a', $sub->getValue('sub.COL'), 'mutating the clone must not reach the original');
	}

	public function testClearResetsPrimaryTableNameCommentCacheOptionsAndCombineOperator()
	{
		$c = new Criteria();
		$c->setPrimaryTableName('book');
		$c->setComment('a comment');
		$c->setQueryCache(true, 120, false);
		$c->_or();

		$c->clear();

		$this->assertNull($c->getPrimaryTableName(), 'primaryTableName is reset');
		$this->assertNull($c->getComment(), 'the query comment is reset');
		$this->assertFalse($c->isQueryCacheEnabled(), 'query caching is reset');
		$this->assertNull($c->getQueryCacheTtl(), 'the per-query TTL override is reset');
		$this->assertTrue($c->isQueryCacheShared(), 'the shared-tier opt-out is reset');

		// _or() flips the pending combine operator and it is only consumed by
		// the next condition, so a Criteria cleared mid-expression would
		// otherwise OR its first new condition onto nothing.
		$c->add('tbl.A', 1);
		$c->add('tbl.B', 2);
		$params = array();
		$sql = BasePeer::createSelectSql($c, $params);
		$this->assertStringContainsString('AND', $sql, 'the pending OR from before clear() must not survive');
	}

	public function testComment()
	{
		$c = new Criteria();
		$this->assertNull($c->getComment(), 'Comment is null by default');
		$c2 = $c->setComment('foo');
		$this->assertEquals('foo', $c->getComment(), 'Comment is set by setComment()');
		$this->assertEquals($c, $c2, 'setComment() returns the current Criteria');
		$c->setComment();
		$this->assertNull($c->getComment(), 'Comment is reset by setComment(null)');
	}

	public function testClear()
	{
		$c = new CriteriaForClearTest();
		$c->clear();

		$this->assertTrue(is_array($c->getNamedCriterions()), 'namedCriterions is an array');
		$this->assertEquals(0, count($c->getNamedCriterions()), 'namedCriterions is empty by default');

		$this->assertFalse($c->getIgnoreCase(), 'ignoreCase is false by default');

		$this->assertFalse($c->getSingleRecord(), 'singleRecord is false by default');

		$this->assertTrue(is_array($c->getSelectModifiers()), 'selectModifiers is an array');
		$this->assertEquals(0, count($c->getSelectModifiers()), 'selectModifiers is empty by default');

		$this->assertTrue(is_array($c->getSelectColumns()), 'selectColumns is an array');
		$this->assertEquals(0, count($c->getSelectColumns()), 'selectColumns is empty by default');

		$this->assertTrue(is_array($c->getOrderByColumns()), 'orderByColumns is an array');
		$this->assertEquals(0, count($c->getOrderByColumns()), 'orderByColumns is empty by default');

		$this->assertTrue(is_array($c->getGroupByColumns()), 'groupByColumns is an array');
		$this->assertEquals(0, count($c->getGroupByColumns()), 'groupByColumns is empty by default');

		$this->assertNull($c->getHaving(), 'having is null by default');

		$this->assertTrue(is_array($c->getAsColumns()), 'asColumns is an array');
		$this->assertEquals(0, count($c->getAsColumns()), 'asColumns is empty by default');

		$this->assertTrue(is_array($c->getJoins()), 'joins is an array');
		$this->assertEquals(0, count($c->getJoins()), 'joins is empty by default');

		$this->assertTrue(is_array($c->getSelectQueries()), 'selectQueries is an array');
		$this->assertEquals(0, count($c->getSelectQueries()), 'selectQueries is empty by default');

		$this->assertEquals(0, $c->getOffset(), 'offset is 0 by default');

		$this->assertEquals(0, $c->getLimit(), 'limit is 0 by default');

		$this->assertNull($c->getBlobFlag(), 'blobFlag is null by default');

		$this->assertTrue(is_array($c->getAliases()), 'aliases is an array');
		$this->assertEquals(0, count($c->getAliases()), 'aliases is empty by default');

		$this->assertFalse($c->getUseTransaction(), 'useTransaction is false by default');

		$this->assertFalse($c->getIsInIf(), 'isInIf is false by default');

		$this->assertFalse($c->getWasTrue(), 'wasTrue is false by default');
	}

	public function testLimit()
	{
		$c = new Criteria();
		$this->assertEquals(0, $c->getLimit(), 'Limit is 0 by default');

		$c2 = $c->setLimit(1);
		$this->assertEquals(1, $c->getLimit(), 'Limit is set by setLimit');
		$this->assertSame($c, $c2, 'setLimit() returns the current Criteria');
	}

	public function testLockDefaults()
	{
		$c = new Criteria();
		$this->assertNull($c->getLockMode(), 'lockMode is null by default');
		$this->assertFalse($c->isLockNoWait(), 'lockNoWait is false by default');
		$this->assertFalse($c->isLockSkipLocked(), 'lockSkipLocked is false by default');
	}

	public function testSetLockForUpdate()
	{
		$c = new Criteria();
		$c2 = $c->setLockForUpdate();
		$this->assertEquals(Criteria::LOCK_FOR_UPDATE, $c->getLockMode(), 'setLockForUpdate() sets the lock mode');
		$this->assertFalse($c->isLockNoWait());
		$this->assertFalse($c->isLockSkipLocked());
		$this->assertSame($c, $c2, 'setLockForUpdate() returns the current Criteria');
	}

	public function testSetLockForShare()
	{
		$c = new Criteria();
		$c->setLockForShare();
		$this->assertEquals(Criteria::LOCK_FOR_SHARE, $c->getLockMode(), 'setLockForShare() sets the lock mode');
	}

	public function testSetLockForUpdateSkipLocked()
	{
		$c = new Criteria();
		$c->setLockForUpdate(true);
		$this->assertTrue($c->isLockSkipLocked());
		$this->assertFalse($c->isLockNoWait());
	}

	public function testSetLockForUpdateNoWait()
	{
		$c = new Criteria();
		$c->setLockForUpdate(false, true);
		$this->assertFalse($c->isLockSkipLocked());
		$this->assertTrue($c->isLockNoWait());
	}

	public function testSetLockForUpdateRejectsSkipLockedAndNoWaitTogether()
	{
		$c = new Criteria();
		$this->expectException(PropulsionException::class);
		$c->setLockForUpdate(true, true);
	}

	public function testClearLock()
	{
		$c = new Criteria();
		$c->setLockForUpdate(true);
		$c2 = $c->clearLock();
		$this->assertNull($c->getLockMode());
		$this->assertFalse($c->isLockSkipLocked());
		$this->assertSame($c, $c2, 'clearLock() returns the current Criteria');
	}

	public function testCriteriaClearResetsLock()
	{
		$c = new Criteria();
		$c->setLockForUpdate(true);
		$c->clear();
		$this->assertNull($c->getLockMode(), 'clear() resets the lock mode');
		$this->assertFalse($c->isLockSkipLocked(), 'clear() resets lockSkipLocked');
	}
}

class CriteriaForClearTest extends Criteria
{
	public function getNamedCriterions()
	{
		return $this->namedCriterions;
	}

	public function getIgnoreCase()
	{
		return $this->ignoreCase;
	}

	public function getSingleRecord()
	{
		return $this->singleRecord;
	}

    public function getUseTransaction()
    {
        return $this->useTransaction;
    }

	public function getWasTrue()
	{
		return $this->wasTrue;
	}

	public function getBlobFlag()
	{
		return $this->blobFlag;
	}

	public function getIsInIf()
	{
		return $this->isInIf;
	}
}
