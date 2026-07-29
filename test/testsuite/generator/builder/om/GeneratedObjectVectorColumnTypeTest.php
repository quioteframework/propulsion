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
 * Tests the generated objects for a VECTOR column, which hydrates to/from a
 * plain array<float> (emulated as unbounded text on this test's platform,
 * SQLite, storing the JSON-encoded array).
 */
class GeneratedObjectVectorColumnTypeTest extends TestCase
{
	public function setUp(): void
	{
		if (!class_exists('VectorColumnTypeEntity')) {
			$schema = <<<EOF
<database name="generated_object_vector_type_test">
	<table name="vector_column_type_entity">
		<column name="id" primaryKey="true" type="INTEGER" autoIncrement="true" />
		<column name="embedding" type="VECTOR" size="3" />
	</table>
</database>
EOF;
			PropulsionQuickBuilder::buildSchema($schema);
		}
	}

	public function testGetterReturnsNullByDefault()
	{
		$e = new VectorColumnTypeEntity();
		$this->assertNull($e->getEmbedding());
	}

	public function testSetterAndGetterRoundTrip()
	{
		$e = new VectorColumnTypeEntity();
		$e->setEmbedding([0.1, 0.2, 0.3]);
		$this->assertSame([0.1, 0.2, 0.3], $e->getEmbedding());
	}

	public function testValueIsPersistedAndRehydratedAsArray()
	{
		$e = new VectorColumnTypeEntity();
		// Whole-number floats (e.g. 3.0) are used nowhere here -- JSON has no
		// separate float/int lexical form, so json_decode() would hand one
		// back as an int, not a float; not a vector-specific concern (any
		// JSON-encoded numeric column has the same round-trip behavior).
		$e->setEmbedding([1.5, -2.5, 3.25]);
		$e->save();
		$id = $e->getId();
		VectorColumnTypeEntityPeer::clearInstancePool();

		$found = VectorColumnTypeEntityQuery::create()->findPk($id);
		$this->assertSame([1.5, -2.5, 3.25], $found->getEmbedding());
	}

	public function testValueIsCopied()
	{
		$e1 = new VectorColumnTypeEntity();
		$e1->setEmbedding([9.0, 8.0, 7.0]);
		$e2 = new VectorColumnTypeEntity();
		$e1->copyInto($e2);
		$this->assertSame([9.0, 8.0, 7.0], $e2->getEmbedding());
	}
}
