<?php

/**
 * This file is part of the Propulsion package.
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 *
 * @license    MIT License
 */

use PHPUnit\Framework\TestCase;
use Propulsion\Adapter\DBMySQL;
use Propulsion\Adapter\DBPostgres;
use Propulsion\Adapter\DBSQLite;
use Propulsion\Exception\PropulsionException;
use Propulsion\Propulsion;
use Propulsion\Query\VectorExpression;

/**
 * {@see VectorExpression}: the literal formatting it does itself (which is
 * what makes writing the query vector straight into the SQL safe), and the
 * per-platform spelling it delegates to the adapter.
 */
class VectorExpressionTest extends TestCase
{
	private const PG = 'vector_expression_test_pg';
	private const MY = 'vector_expression_test_mysql';
	private const LITE = 'vector_expression_test_sqlite';

	protected function setUp(): void
	{
		parent::setUp();
		Propulsion::setDB(self::PG, new DBPostgres());
		Propulsion::setDB(self::MY, new DBMySQL());
		Propulsion::setDB(self::LITE, new DBSQLite());
	}

	public function testLiteralFormatsAListOfNumbers()
	{
		$this->assertSame('[1,2,3]', VectorExpression::literal(array(1, 2, 3)));
		$this->assertSame('[0.5,-1.25]', VectorExpression::literal(array(0.5, -1.25)));
	}

	public function testLiteralPassesThroughAnAlreadyFormattedLiteral()
	{
		$this->assertSame('[0.1, 0.2]', VectorExpression::literal('[0.1, 0.2]'));
		$this->assertSame('[]', VectorExpression::literal('[]'));
	}

	/**
	 * @dataProvider rejectedLiterals
	 */
	#[\PHPUnit\Framework\Attributes\DataProvider('rejectedLiterals')]
	public function testLiteralRejectsAnythingThatIsNotNumbers(array|string $vector)
	{
		$this->expectException(PropulsionException::class);
		VectorExpression::literal($vector);
	}

	/**
	 * @return array<string, array<int, array<array-key, mixed>|string>>
	 */
	public static function rejectedLiterals(): array
	{
		return array(
			'sql injected through a string literal' => array("[1]'::vector); DROP TABLE doc; --"),
			'a bare column reference' => array('embedding'),
			'a non-numeric element' => array(array(1, 'two')),
			'a null element' => array(array(1, null)),
			'a nested array' => array(array(array(1))),
			'NAN' => array(array(NAN)),
			'INF' => array(array(INF)),
		);
	}

	public function testPostgresRendersPgvectorOperators()
	{
		$vec = array(1, 2);
		$this->assertSame(
			"doc.EMBEDDING <-> '[1,2]'::vector",
			VectorExpression::l2Distance('doc.EMBEDDING', $vec)->toSql(self::PG)
		);
		$this->assertSame(
			"doc.EMBEDDING <=> '[1,2]'::vector",
			VectorExpression::cosineDistance('doc.EMBEDDING', $vec)->toSql(self::PG)
		);
		$this->assertSame(
			"doc.EMBEDDING <#> '[1,2]'::vector",
			VectorExpression::innerProduct('doc.EMBEDDING', $vec)->toSql(self::PG)
		);
		$this->assertSame(
			"doc.EMBEDDING <+> '[1,2]'::vector",
			VectorExpression::l1Distance('doc.EMBEDDING', $vec)->toSql(self::PG)
		);
	}

	public function testMariaDbRendersFunctionCalls()
	{
		$this->assertSame(
			"VEC_DISTANCE_EUCLIDEAN(doc.EMBEDDING, VEC_FromText('[1,2]'))",
			VectorExpression::l2Distance('doc.EMBEDDING', array(1, 2))->toSql(self::MY)
		);
		$this->assertSame(
			"VEC_DISTANCE_COSINE(doc.EMBEDDING, VEC_FromText('[1,2]'))",
			VectorExpression::cosineDistance('doc.EMBEDDING', array(1, 2))->toSql(self::MY)
		);
	}

	public function testMariaDbHasNoInnerProductOrL1Metric()
	{
		$this->expectException(PropulsionException::class);
		$this->expectExceptionMessage('no vector distance function');
		VectorExpression::innerProduct('doc.EMBEDDING', array(1, 2))->toSql(self::MY);
	}

	public function testMysqlHasNoDistanceFunctionAtAll()
	{
		// Community MySQL 9 ships the VECTOR type and its two conversion
		// functions but no distance function -- DISTANCE() is HeatWave-only.
		// Saying so beats emitting SQL that cannot run on the MySQL you can
		// actually install.
		$mysql = new DBMySQL();
		$mysql->setServerFlavor(false);
		Propulsion::setDB('vector_expression_test_mysql9', $mysql);

		$this->expectException(PropulsionException::class);
		$this->expectExceptionMessage('HeatWave-only');
		VectorExpression::l2Distance('doc.EMBEDDING', array(1, 2))->toSql('vector_expression_test_mysql9');
	}

	public function testAPlatformWithoutVectorSupportSaysSo()
	{
		$this->expectException(PropulsionException::class);
		$this->expectExceptionMessage('does not support vector distance expressions');
		VectorExpression::l2Distance('doc.EMBEDDING', array(1, 2))->toSql(self::LITE);
	}

	public function testAccessors()
	{
		$expr = VectorExpression::cosineDistance('doc.EMBEDDING', array(1));
		$this->assertSame('doc.EMBEDDING', $expr->getColumn());
		$this->assertSame(VectorExpression::COSINE, $expr->getMetric());
		$this->assertSame('[1]', $expr->getVectorLiteral());
	}
}
