<?php

/**
 * This file is part of the Propulsion package.
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 *
 * @license    MIT License
 */

/**
 * Test class for DatabaseMap.
 *
 * @author     François Zaninotto
 */
class DatabaseMapTest extends BookstoreTestBase
{
  protected $databaseMap;
  protected $databaseName;

  protected function setUp(): void
  {
    parent::setUp();
    $this->databaseName = 'foodb';
    $this->databaseMap = TestDatabaseBuilder::getDmap();
  }

  protected function tearDown(): void
  {
    // nothing to do for now
    parent::tearDown();
  }

  public function testConstructor()
  {
    $this->assertEquals($this->databaseName, $this->databaseMap->getName(), 'constructor sets the table name');
  }

  public function testAddTable()
  {
    $this->assertFalse($this->databaseMap->hasTable('foo'), 'tables are empty by default');
    try
    {
      $this->databaseMap->getTable('foo');
      $this->fail('getTable() throws an exception when called on an inexistent table');
    } catch(PropulsionException $e) {
      $this->assertTrue(true, 'getTable() throws an exception when called on an inexistent table');
    }
    $tmap = $this->databaseMap->addTable('foo');
    $this->assertTrue($this->databaseMap->hasTable('foo'), 'hasTable() returns true when the table was added by way of addTable()');
    $this->assertEquals($tmap, $this->databaseMap->getTable('foo'), 'getTable() returns a table by name when the table was added by way of addTable()');
  }

  public function testAddTableObject()
  {
    $this->assertFalse($this->databaseMap->hasTable('foo2'), 'tables are empty by default');
    try
    {
      $this->databaseMap->getTable('foo2');
      $this->fail('getTable() throws an exception when called on a table with no builder');
    } catch(PropulsionException $e) {
      $this->assertTrue(true, 'getTable() throws an exception when called on a table with no builder');
    }
    $tmap = new TableMap('foo2');
    $this->databaseMap->addTableObject($tmap);
    $this->assertTrue($this->databaseMap->hasTable('foo2'), 'hasTable() returns true when the table was added by way of addTableObject()');
    $this->assertEquals($tmap, $this->databaseMap->getTable('foo2'), 'getTable() returns a table by name when the table was added by way of addTableObject()');
  }

  public function testAddTableFromMapClass()
  {
    $table1 = $this->databaseMap->addTableFromMapClass('BazTableMap');
    try
    {
      $table2 = $this->databaseMap->getTable('baz');
      $this->assertEquals($table1, $table2, 'addTableFromMapClass() adds a table from a map class');
    } catch(PropulsionException $e) {
      $this->fail('addTableFromMapClass() adds a table from a map class');
    }
  }

  public function testGetColumn()
  {
    try
    {
      $this->databaseMap->getColumn('foo.BAR');
      $this->fail('getColumn() throws an exception when called on column of an inexistent table');
    } catch(PropulsionException $e) {
      $this->assertTrue(true, 'getColumn() throws an exception when called on column of an inexistent table');
    }
    $tmap = $this->databaseMap->addTable('foo');
    try
    {
      $this->databaseMap->getColumn('foo.BAR');
      $this->fail('getColumn() throws an exception when called on an inexistent column of an existent table');
    } catch(PropulsionException $e) {
      $this->assertTrue(true, 'getColumn() throws an exception when called on an inexistent column of an existent table');
    }
    $column = $tmap->addColumn('BAR', 'Bar', 'INTEGER');
    $this->assertEquals($column, $this->databaseMap->getColumn('foo.BAR'), 'getColumn() returns a ColumnMap object based on a fully qualified name');
  }

  public function testHasTableFindsSchemaQualifiedTables()
  {
    // A schema-qualified table registers under its dotted name -- that is what
    // TableMap::getName() returns and what getTable() looks up (the schemas
    // fixture generates TABLE_NAME = 'contest.bookstore_contest'). hasTable()
    // used to truncate at the first dot unconditionally, so it answered "no"
    // for every such table: it checked $tables['contest'].
    $this->databaseMap->addTable('myschema.mytable');

    $this->assertTrue(
      $this->databaseMap->hasTable('myschema.mytable'),
      'hasTable() finds a schema-qualified table under the name it was registered with'
    );
    $this->assertSame(
      'myschema.mytable',
      $this->databaseMap->getTable('myschema.mytable')->getName(),
      'hasTable() and getTable() agree on schema-qualified names'
    );
    $this->assertFalse(
      $this->databaseMap->hasTable('myschema.absent'),
      'an unregistered table in a known schema is still absent'
    );
  }

  public function testHasTableStillAcceptsAQualifiedColumnName()
  {
    // The historic first-segment fallback: callers passing "table.COLUMN".
    // Retained, and checked second, so this can only ever answer "yes" where it
    // used to answer "yes".
    $this->databaseMap->addTable('qualifiedfallback');

    $this->assertTrue(
      $this->databaseMap->hasTable('qualifiedfallback.SOME_COLUMN'),
      'hasTable() still resolves the leading table segment of a qualified column name'
    );
  }

  public function testGetColumnSplitsOnTheLastDot()
  {
    // "schema.table.COLUMN" has three segments; splitting into two took
    // ('schema', 'table') from it -- a table that does not exist and a column
    // name that is really the table.
    $tmap = $this->databaseMap->addTable('lastdot.mytable');
    $column = $tmap->addColumn('MYCOL', 'Mycol', 'INTEGER');

    $this->assertEquals(
      $column,
      $this->databaseMap->getColumn('lastdot.mytable.MYCOL'),
      'getColumn() resolves a schema-qualified column reference'
    );
  }

  public function testGetColumnRejectsAnUnqualifiedName()
  {
    $this->expectException(PropulsionException::class);
    $this->databaseMap->getColumn('NOTQUALIFIED');
  }

  public function testGetTableByPhpName()
  {
    try
    {
      $this->databaseMap->getTableByPhpName('Foo1');
      $this->fail('getTableByPhpName() throws an exception when called on an inexistent table');
    } catch(PropulsionException $e) {
      $this->assertTrue(true, 'getTableByPhpName() throws an exception when called on an inexistent table');
    }
    $tmap = $this->databaseMap->addTable('foo1');
    try
    {
      $this->databaseMap->getTableByPhpName('Foo1');
      $this->fail('getTableByPhpName() throws an exception when called on a table with no phpName');
    } catch(PropulsionException $e) {
      $this->assertTrue(true, 'getTableByPhpName() throws an exception when called on a table with no phpName');
    }
    $tmap2 = new TableMap('foo2');
    $tmap2->setClassname('Foo2');
    $this->databaseMap->addTableObject($tmap2);
    $this->assertEquals($tmap2, $this->databaseMap->getTableByPhpName('Foo2'), 'getTableByPhpName() returns tableMap when phpName was set by way of TableMap::setPhpName()');
  }

  public function testGetTableByPhpNameNotLoaded()
  {
		$this->assertEquals('book', Propulsion::getDatabaseMap('bookstore')->getTableByPhpName('Book')->getName(), 'getTableByPhpName() can autoload a TableMap when the Peer class is generated and autoloaded');
  }

  public function testGetTableByPhpNameCachesTheNameThatWasAskedFor()
  {
    // addTableObject() indexes a map under its own getClassname(), so when the
    // requested phpName differs from that the fast-path lookup kept missing and
    // the slow path -- a regex plus up to two class_exists() probes -- re-ran on
    // every call. The resolution is now also indexed under the requested name.
    $map = Propulsion::getDatabaseMap('bookstore');
    $resolved = $map->getTableByPhpName('Book');

    $reflected = new ReflectionProperty(DatabaseMap::class, 'tablesByPhpName');
    /** @var array<string, TableMap> $index */
    $index = $reflected->getValue($map);

    $this->assertArrayHasKey('Book', $index, 'the resolved map is indexed under the requested phpName');
    $this->assertSame(
      $resolved,
      $map->getTableByPhpName('Book'),
      'a repeated lookup returns the very same TableMap instance'
    );
  }

}

class TestDatabaseBuilder
{
  protected static $dmap = null;
  protected static $tmap = null;
  public static function getDmap()
  {
    // Always fresh, not memoized: several DatabaseMapTest methods add tables
    // ('foo', 'foo2', ...) to whatever map this returns. A single cached
    // instance shared across every test method leaks tables from one test
    // into the next -- order-dependent (PHPUnit's "depends,defects"
    // executionOrder reorders methods run-to-run), surfacing as spurious
    // "tables are empty by default" failures for whichever test happens to
    // run after another that already added the same table name.
    self::$dmap = new DatabaseMap('foodb');
    return self::$dmap;
  }
  public static function setTmap($tmap)
  {
    self::$tmap = $tmap;
  }
  public static function getTmap()
  {
    return self::$tmap;
  }
}

class BazTableMap extends TableMap
{
  public function initialize(): void
  {
    $this->setName('baz');
    $this->setPhpName('Baz');
  }
}