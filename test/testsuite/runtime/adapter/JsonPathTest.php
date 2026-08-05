<?php

/**
 * This file is part of the Propulsion package.
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 *
 * @license    MIT License
 */

use PHPUnit\Framework\TestCase;
use Propulsion\Adapter\DBAdapter;
use Propulsion\Adapter\DBMSSQL;
use Propulsion\Adapter\DBMySQL;
use Propulsion\Adapter\DBNone;
use Propulsion\Adapter\DBOracle;
use Propulsion\Adapter\DBPostgres;
use Propulsion\Adapter\DBSQLite;
use Propulsion\Exception\PropulsionException;
use Propulsion\Propulsion;
use Propulsion\Query\JsonExpression;

/**
 * The per-platform SQL for JSON path extraction, and the shared path parser
 * underneath it. String shapes only -- that a Postgres `#>>` and a MySQL
 * `JSON_UNQUOTE(JSON_EXTRACT(...))` actually return the same value for the
 * same document is what JsonPathQueryTest checks against a live server.
 */
class JsonPathTest extends TestCase
{
	private const DATASOURCE = 'json_path_test';

	protected function setUp(): void
	{
		parent::setUp();
		Propulsion::setDB(self::DATASOURCE, new DBPostgres());
	}

	public function testEveryRealPlatformSupportsJsonPaths()
	{
		foreach (array(new DBPostgres(), new DBMySQL(), new DBSQLite(), new DBMSSQL(), new DBOracle()) as $db) {
			$this->assertTrue($db->supportsJsonPath(), get_class($db));
		}
		$this->assertFalse((new DBNone())->supportsJsonPath());
	}

	public function testPostgresUsesThePathTakingOperators()
	{
		$db = new DBPostgres();
		$this->assertSame('(book.META #>> \'{"author","name"}\')', $db->getJsonExtractSql('book.META', '$.author.name'));
		$this->assertSame('(book.META #> \'{"author"}\')', $db->getJsonExtractSql('book.META', '$.author', false));
		$this->assertSame('(book.META #>> \'{"tags","0"}\')', $db->getJsonExtractSql('book.META', '$.tags[0]'));
	}

	public function testPostgresWholeDocumentPathHasNoOperatorToUse()
	{
		// '{}' is not a valid path, so "$" degenerates to the column itself.
		$db = new DBPostgres();
		$this->assertSame('(book.META)::text', $db->getJsonExtractSql('book.META', '$'));
		$this->assertSame('book.META', $db->getJsonExtractSql('book.META', '$', false));
	}

	public function testMysqlUnquotesForTheTextForm()
	{
		$db = new DBMySQL();
		$this->assertSame(
			'JSON_UNQUOTE(JSON_EXTRACT(book.META, \'$."author"."name"\'))',
			$db->getJsonExtractSql('book.META', '$.author.name')
		);
		$this->assertSame(
			'JSON_EXTRACT(book.META, \'$."author"\')',
			$db->getJsonExtractSql('book.META', '$.author', false)
		);
	}

	public function testSqliteUsesTheArrowOperators()
	{
		$db = new DBSQLite();
		$this->assertSame('(book.META ->> \'$."author"."name"\')', $db->getJsonExtractSql('book.META', '$.author.name'));
		$this->assertSame('(book.META -> \'$."author"\')', $db->getJsonExtractSql('book.META', '$.author', false));
	}

	public function testMssqlAndOracleSplitScalarFromDocument()
	{
		foreach (array(new DBMSSQL(), new DBOracle()) as $db) {
			$this->assertSame(
				'JSON_VALUE(book.META, \'$."author"."name"\')',
				$db->getJsonExtractSql('book.META', '$.author.name'),
				get_class($db)
			);
			$this->assertSame(
				'JSON_QUERY(book.META, \'$."author"\')',
				$db->getJsonExtractSql('book.META', '$.author', false),
				get_class($db)
			);
		}
	}

	public function testAPlatformWithoutJsonPathSupportSaysSo()
	{
		$this->expectException(PropulsionException::class);
		$this->expectExceptionMessage('does not support JSON path expressions');
		(new DBNone())->getJsonExtractSql('book.META', '$.a');
	}

	public function testIndexesAndKeysCanBeMixed()
	{
		$db = new DBMySQL();
		$this->assertSame(
			'JSON_UNQUOTE(JSON_EXTRACT(book.META, \'$."a"[2]."b"[0]\'))',
			$db->getJsonExtractSql('book.META', '$.a[2].b[0]')
		);
	}

	public function testKeysWithSpacesAreQuotedRatherThanRejected()
	{
		$db = new DBMySQL();
		$this->assertSame(
			'JSON_UNQUOTE(JSON_EXTRACT(book.META, \'$."first name"\'))',
			$db->getJsonExtractSql('book.META', '$.first name')
		);
	}

	/**
	 * @dataProvider malformedPaths
	 */
	#[\PHPUnit\Framework\Attributes\DataProvider('malformedPaths')]
	public function testMalformedPathsAreRejectedHereRatherThanByTheServer(string $path)
	{
		$this->expectException(PropulsionException::class);
		(new DBMySQL())->getJsonExtractSql('book.META', $path);
	}

	/**
	 * @return array<string, array<int, string>>
	 */
	public static function malformedPaths(): array
	{
		return array(
			'no leading $'          => array('author.name'),
			'empty'                 => array(''),
			'empty key'             => array('$..name'),
			'quote in key'          => array('$.a"b'),
			'backslash in key'      => array('$.a\\b'),
			'unclosed index'        => array('$.a[0'),
			'wildcard index'        => array('$.a[*]'),
			'slice index'           => array('$.a[0:2]'),
			'negative index'        => array('$.a[-1]'),
			'recursive descent'     => array('$..a'),
			'stray text'            => array('$author'),
		);
	}

	public function testJsonExpressionRendersForTheGivenDatasource()
	{
		$this->assertSame(
			'(book.META #>> \'{"a"}\')',
			JsonExpression::text('book.META', '$.a')->toSql(self::DATASOURCE)
		);
		$this->assertSame(
			'(book.META #> \'{"a"}\')',
			JsonExpression::json('book.META', '$.a')->toSql(self::DATASOURCE)
		);
	}

	public function testJsonExpressionAccessors()
	{
		$expr = JsonExpression::text('book.META', '$.a');
		$this->assertSame('book.META', $expr->getColumn());
		$this->assertSame('$.a', $expr->getPath());
		$this->assertTrue($expr->isText());
		$this->assertFalse(JsonExpression::json('book.META')->isText());
		$this->assertSame('$', JsonExpression::json('book.META')->getPath());
	}
}
