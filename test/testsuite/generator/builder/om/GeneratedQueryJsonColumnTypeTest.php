<?php

use PHPUnit\Framework\TestCase;
/**
 * This file is part of the Propulsion package.
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 *
 * @license    MIT License
 */

/**
 * Tests the generated queries for JSON/JSONB column types filters.
 */
class GeneratedQueryJsonColumnTypeTest extends TestCase
{
	protected $v1, $v2;

	public function setUp(): void
	{
		$this->v1 = array('foo' => 'bar');
		$this->v2 = array('foo' => 'baz');

		if (!class_exists('ComplexColumnTypeEntity21')) {
			$schema = <<<EOF
<database name="generated_query_complex_type_test_21">
	<table name="complex_column_type_entity_21">
		<column name="id" primaryKey="true" type="INTEGER" autoIncrement="true" />
		<column name="data" type="JSON" />
	</table>
</database>
EOF;
			PropulsionQuickBuilder::buildSchema($schema);
			$e0 = new ComplexColumnTypeEntity21();
			$e0->save();
			$e1 = new ComplexColumnTypeEntity21();
			$e1->setData($this->v1);
			$e1->save();
			$e2 = new ComplexColumnTypeEntity21();
			$e2->setData($this->v2);
			$e2->save();
			ComplexColumnTypeEntity21Peer::clearInstancePool();
		}
	}

	public function testActiveQueryMethods()
	{
		$this->assertTrue(method_exists('ComplexColumnTypeEntity21Query', 'filterByData'));
	}

	public function testColumnHydration()
	{
		$e = ComplexColumnTypeEntity21Query::create()
			->orderById()
			->offset(1)
			->findOne();
		$this->assertEquals($this->v1, $e->getData(), 'JSON columns are correctly hydrated');
	}

	public function testWhere()
	{
		$nb = ComplexColumnTypeEntity21Query::create()
			->where('ComplexColumnTypeEntity21.Data LIKE ?', '%bar%')
			->count();
		$this->assertEquals(1, $nb, 'JSON columns are searchable by serialized JSON text using where()');
	}

	public function testFilterByColumn()
	{
		$e = ComplexColumnTypeEntity21Query::create()
			->filterByData($this->v1)
			->findOne();
		$this->assertEquals($this->v1, $e->getData(), 'JSON columns are searchable by array value');

		$e = ComplexColumnTypeEntity21Query::create()
			->filterByData($this->v2)
			->findOne();
		$this->assertEquals($this->v2, $e->getData(), 'JSON columns are searchable by array value');

		$e = ComplexColumnTypeEntity21Query::create()
			->filterByData($this->v1, Criteria::NOT_EQUAL)
			->orderById()
			->find();
		// Standard SQL <> semantics: the row with a NULL Data value does not match
		// either side of a NOT_EQUAL comparison, so only the $v2 row qualifies.
		$this->assertEquals(1, $e->count(), 'JSON columns support NOT_EQUAL filtering');
		$this->assertEquals($this->v2, $e[0]->getData());
	}
}
