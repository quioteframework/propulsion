<?php

/**
 * This file is part of the Propulsion package.
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 *
 * @license    MIT License
 */

/**
 * Tests the DbMySQL adapter
 *
 * @see        BookstoreDataPopulator
 * @author     William Durand
 */
class DBMySQLTest extends BookstoreTestBase
{
	public static function getConParams()
	{
		return array(
			array(
				array(
					'dsn' => 'dsn=my_dsn',
					'settings' => array(
						'charset' => array(
							'value' => 'foobar'
						)
					)
				)
			)
		);
	}

	/**
	 * @dataProvider getConParams
	 * @expectedException PropulsionException
	 */
	#[\PHPUnit\Framework\Attributes\DataProvider('getConParams')]
	public function testPrepareParamsThrowsException($conparams)
	{
		$this->expectException(PropulsionException::class);
		if (version_compare(PHP_VERSION, '5.3.6', '>=')) {
			$this->markTestSkipped('PHP_VERSION >= 5.3.6, no need to throw an exception.');
		}

		$db = new DBMySQL();
		$db->prepareParams($conparams);
	}

	/**
	 * @dataProvider getConParams
	 */
	#[\PHPUnit\Framework\Attributes\DataProvider('getConParams')]
	public function testPrepareParams($conparams)
	{
		if (version_compare(PHP_VERSION, '5.3.6', '<')) {
			$this->markTestSkipped('PHP_VERSION < 5.3.6 will throw an exception.');
		}

		$db = new DBMySQL();
		$params = $db->prepareParams($conparams);

		$this->assertTrue(is_array($params));
		$this->assertEquals('dsn=my_dsn;charset=foobar', $params['dsn'], 'The given charset is in the DSN string');
		$this->assertArrayNotHasKey('charset', $params['settings'], 'The charset should be removed');
	}

	/**
	 * @dataProvider getConParams
	 */
	#[\PHPUnit\Framework\Attributes\DataProvider('getConParams')]
	public function testNoSetNameQueryExecuted($conparams)
	{
		if (version_compare(PHP_VERSION, '5.3.6', '<')) {
			$this->markTestSkipped('PHP_VERSION < 5.3.6 will throw an exception.');
		}

		$db = new DBMySQL();
		$params = $db->prepareParams($conparams);

		$settings = array();
		if (isset($params['settings'])) {
			$settings = $params['settings'];
		}

		$db->initConnection($this->getPdoMock(), $settings);
	}

	protected function getPdoMock()
	{
		$con = $this
			->getMockBuilder('mockPDO')
			->getMock();

		$con
			->expects($this->never())
			->method('exec');

		return $con;
	}

	public function testToUpperCase()
	{
		$db = new DBMySQL();
		$this->assertEquals('UPPER(foo)', $db->toUpperCase('foo'));
	}

	public function testIgnoreCase()
	{
		$db = new DBMySQL();
		$this->assertEquals('UPPER(foo)', $db->ignoreCase('foo'));
	}

	public function testConcatString()
	{
		$db = new DBMySQL();
		$this->assertEquals('CONCAT(foo, bar)', $db->concatString('foo', 'bar'));
	}

	public function testSubString()
	{
		$db = new DBMySQL();
		$this->assertEquals('SUBSTRING(foo, 1, 3)', $db->subString('foo', 1, 3));
	}

	public function testStrLength()
	{
		$db = new DBMySQL();
		$this->assertEquals('CHAR_LENGTH(foo)', $db->strLength('foo'));
	}

	public function testQuoteIdentifier()
	{
		$db = new DBMySQL();
		$this->assertEquals('`foo`', $db->quoteIdentifier('foo'));
	}

	public function testQuoteIdentifierTable()
	{
		$db = new DBMySQL();
		$this->assertEquals('`mydb`.`foo` `bar`', $db->quoteIdentifierTable('mydb.foo bar'));
	}

	public function testUseQuoteIdentifier()
	{
		$db = new DBMySQL();
		$this->assertTrue($db->useQuoteIdentifier());
	}

	public function testRandom()
	{
		$db = new DBMySQL();
		$this->assertEquals('rand(0)', $db->random());
		$this->assertEquals('rand(42)', $db->random('42'));
	}

	public function testApplyLimitWithLimitOnly()
	{
		$db = new DBMySQL();
		$sql = 'SELECT * FROM foo';
		$db->applyLimit($sql, 0, 10);
		$this->assertEquals('SELECT * FROM foo LIMIT 10', $sql);
	}

	public function testApplyLimitWithLimitAndOffset()
	{
		$db = new DBMySQL();
		$sql = 'SELECT * FROM foo';
		$db->applyLimit($sql, 5, 10);
		$this->assertEquals('SELECT * FROM foo LIMIT 5, 10', $sql);
	}

	public function testApplyLimitWithOffsetOnly()
	{
		$db = new DBMySQL();
		$sql = 'SELECT * FROM foo';
		$db->applyLimit($sql, 5, 0);
		$this->assertEquals('SELECT * FROM foo LIMIT 5, 18446744073709551615', $sql);
	}

	public function testLockAndUnlockTable()
	{
		$db = new DBMySQL();
		$con = $this->getMockBuilder('mockPDO')->getMock();
		$con->expects($this->once())->method('exec')->with('LOCK TABLE foo WRITE');
		$db->lockTable($con, 'foo');

		$con2 = $this->getMockBuilder('mockPDO')->getMock();
		$con2->expects($this->once())->method('exec')->with('UNLOCK TABLES');
		$db->unlockTable($con2, 'foo');
	}
}

// See: http://stackoverflow.com/questions/3138946/mocking-the-pdo-object-using-phpunit
class mockPDO extends PDO
{
	public function __construct()
	{
	}
}
