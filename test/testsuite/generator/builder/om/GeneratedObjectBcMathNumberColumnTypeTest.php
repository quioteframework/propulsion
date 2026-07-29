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
 * Tests the generated objects for a DECIMAL column declaring
 * `phpType="\BcMath\Number"`, which hydrates to/from PHP 8.4's
 * arbitrary-precision `BcMath\Number` instead of the default plain string.
 */
class GeneratedObjectBcMathNumberColumnTypeTest extends TestCase
{
	public function setUp(): void
	{
		if (!class_exists('BcMathColumnTypeEntity')) {
			$schema = <<<EOF
<database name="generated_object_bcmath_number_type_test">
	<table name="bc_math_column_type_entity">
		<column name="id" primaryKey="true" type="INTEGER" autoIncrement="true" />
		<column name="price" type="DECIMAL" size="10" scale="2" phpType="\BcMath\Number" />
		<column name="price_with_default" type="DECIMAL" size="10" scale="2" phpType="\BcMath\Number" defaultValue="9.99" />
	</table>
</database>
EOF;
			PropulsionQuickBuilder::buildSchema($schema);
		}
	}

	public function testGetterReturnsNullByDefault()
	{
		$e = new BcMathColumnTypeEntity();
		$this->assertNull($e->getPrice());
	}

	public function testSetterAcceptsStringAndNormalizesToNumber()
	{
		$e = new BcMathColumnTypeEntity();
		$e->setPrice('19.99');
		$this->assertInstanceOf(\BcMath\Number::class, $e->getPrice());
		$this->assertSame('19.99', (string) $e->getPrice());
	}

	public function testSetterAcceptsNumberInstanceDirectly()
	{
		$e = new BcMathColumnTypeEntity();
		$number = new \BcMath\Number('42.50');
		$e->setPrice($number);
		$this->assertSame($number, $e->getPrice());
	}

	public function testSetterAcceptsIntAndFloat()
	{
		$e = new BcMathColumnTypeEntity();
		$e->setPrice(5);
		$this->assertSame('5', (string) $e->getPrice());
	}

	public function testDefaultValueIsANumberInstance()
	{
		$e = new BcMathColumnTypeEntity();
		$this->assertInstanceOf(\BcMath\Number::class, $e->getPriceWithDefault());
		$this->assertSame('9.99', (string) $e->getPriceWithDefault());
	}

	public function testValueIsPersistedAndRehydratedAsNumberInstance()
	{
		$e = new BcMathColumnTypeEntity();
		$e->setPrice('123.45');
		$e->save();
		$id = $e->getId();
		BcMathColumnTypeEntityPeer::clearInstancePool();

		$found = BcMathColumnTypeEntityQuery::create()->findPk($id);
		$this->assertInstanceOf(\BcMath\Number::class, $found->getPrice());
		$this->assertSame('123.45', (string) $found->getPrice());
	}

	public function testValueIsCopied()
	{
		$e1 = new BcMathColumnTypeEntity();
		$e1->setPrice('7.00');
		$e2 = new BcMathColumnTypeEntity();
		$e1->copyInto($e2);
		$this->assertSame('7.00', (string) $e2->getPrice());
	}
}
