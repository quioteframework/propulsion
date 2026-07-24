<?php

/**
 * This file is part of the Propulsion package.
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 *
 * @license    MIT License
 */

use PHPUnit\Framework\TestCase;

/**
 * Test class for PropulsionTypes.
 */
class PropulsionTypesTest extends TestCase
{
    public function testGetPhpNative()
    {
        $this->assertSame('int', PropulsionTypes::getPhpNative(PropulsionTypes::INTEGER));
        $this->assertSame('string', PropulsionTypes::getPhpNative(PropulsionTypes::VARCHAR));
        $this->assertSame('boolean', PropulsionTypes::getPhpNative(PropulsionTypes::BOOLEAN));
        $this->assertSame('double', PropulsionTypes::getPhpNative(PropulsionTypes::FLOAT));
        $this->assertSame('array', PropulsionTypes::getPhpNative(PropulsionTypes::PHP_ARRAY));
    }

    public function testGetCreoleType()
    {
        $this->assertSame(PropulsionTypes::VARCHAR, PropulsionTypes::getCreoleType(PropulsionTypes::VARCHAR));
        // pre-epoch date types map to VARCHAR
        $this->assertSame(PropulsionTypes::VARCHAR, PropulsionTypes::getCreoleType(PropulsionTypes::BU_DATE));
        $this->assertSame(PropulsionTypes::VARCHAR, PropulsionTypes::getCreoleType(PropulsionTypes::BU_TIMESTAMP));
    }

    public function testGetPDOType()
    {
        $this->assertSame(PDO::PARAM_INT, PropulsionTypes::getPDOType(PropulsionTypes::INTEGER));
        $this->assertSame(PDO::PARAM_STR, PropulsionTypes::getPDOType(PropulsionTypes::VARCHAR));
        $this->assertSame(PDO::PARAM_BOOL, PropulsionTypes::getPDOType(PropulsionTypes::BOOLEAN));
        $this->assertSame(PDO::PARAM_LOB, PropulsionTypes::getPDOType(PropulsionTypes::BLOB));
    }

    public function testGetPropulsionTypeReturnsNullForUnknownCode()
    {
        $this->assertNull(PropulsionTypes::getPropulsionType(99999));
    }

    public function testGetPropulsionTypesReturnsAllTypeNames()
    {
        $types = PropulsionTypes::getPropulsionTypes();
        $this->assertIsArray($types);
        $this->assertContains(PropulsionTypes::VARCHAR, $types);
        $this->assertContains(PropulsionTypes::INTEGER, $types);
    }

    public function testIsTemporalType()
    {
        $this->assertTrue(PropulsionTypes::isTemporalType(PropulsionTypes::DATE));
        $this->assertTrue(PropulsionTypes::isTemporalType(PropulsionTypes::TIMESTAMP));
        $this->assertFalse(PropulsionTypes::isTemporalType(PropulsionTypes::INTEGER));
    }

    public function testIsTextType()
    {
        $this->assertTrue(PropulsionTypes::isTextType(PropulsionTypes::VARCHAR));
        $this->assertTrue(PropulsionTypes::isTextType(PropulsionTypes::CLOB));
        $this->assertFalse(PropulsionTypes::isTextType(PropulsionTypes::INTEGER));
    }

    public function testIsNumericType()
    {
        $this->assertTrue(PropulsionTypes::isNumericType(PropulsionTypes::INTEGER));
        $this->assertTrue(PropulsionTypes::isNumericType(PropulsionTypes::DECIMAL));
        $this->assertFalse(PropulsionTypes::isNumericType(PropulsionTypes::VARCHAR));
    }

    public function testIsBooleanType()
    {
        $this->assertTrue(PropulsionTypes::isBooleanType(PropulsionTypes::BOOLEAN));
        $this->assertTrue(PropulsionTypes::isBooleanType(PropulsionTypes::BOOLEAN_EMU));
        $this->assertFalse(PropulsionTypes::isBooleanType(PropulsionTypes::INTEGER));
    }

    public function testIsLobType()
    {
        $this->assertTrue(PropulsionTypes::isLobType(PropulsionTypes::BLOB));
        $this->assertTrue(PropulsionTypes::isLobType(PropulsionTypes::VARBINARY));
        $this->assertFalse(PropulsionTypes::isLobType(PropulsionTypes::VARCHAR));
    }

    public function testIsPhpPrimitiveType()
    {
        $this->assertTrue(PropulsionTypes::isPhpPrimitiveType('string'));
        $this->assertTrue(PropulsionTypes::isPhpPrimitiveType('int'));
        $this->assertTrue(PropulsionTypes::isPhpPrimitiveType('boolean'));
        $this->assertTrue(PropulsionTypes::isPhpPrimitiveType('double'));
        $this->assertTrue(PropulsionTypes::isPhpPrimitiveType('float'));
        $this->assertFalse(PropulsionTypes::isPhpPrimitiveType('array'));
        $this->assertFalse(PropulsionTypes::isPhpPrimitiveType('DateTime'));
    }

    public function testIsPhpPrimitiveNumericType()
    {
        $this->assertTrue(PropulsionTypes::isPhpPrimitiveNumericType('int'));
        $this->assertTrue(PropulsionTypes::isPhpPrimitiveNumericType('double'));
        $this->assertFalse(PropulsionTypes::isPhpPrimitiveNumericType('string'));
    }

    public function testIsPhpObjectType()
    {
        $this->assertTrue(PropulsionTypes::isPhpObjectType('DateTime'));
        $this->assertFalse(PropulsionTypes::isPhpObjectType('string'));
        $this->assertFalse(PropulsionTypes::isPhpObjectType('array'));
        $this->assertFalse(PropulsionTypes::isPhpObjectType('resource'));
    }

    public function testIsJsonType()
    {
        $this->assertTrue(PropulsionTypes::isJsonType(PropulsionTypes::JSON));
        $this->assertTrue(PropulsionTypes::isJsonType(PropulsionTypes::JSONB));
        $this->assertFalse(PropulsionTypes::isJsonType(PropulsionTypes::VARCHAR));
        $this->assertFalse(PropulsionTypes::isJsonType(PropulsionTypes::PHP_ARRAY));
        $this->assertFalse(PropulsionTypes::isJsonType(PropulsionTypes::OBJECT));
    }

    public function testJsonTypesGetPdoTypeString()
    {
        $this->assertSame(PDO::PARAM_STR, PropulsionTypes::getPDOType(PropulsionTypes::JSON));
        $this->assertSame(PDO::PARAM_STR, PropulsionTypes::getPDOType(PropulsionTypes::JSONB));
    }

    public function testJsonTypesGetPhpNative()
    {
        // Like OBJECT, JSON/JSONB decode to any shape (array, scalar, or null),
        // so there is no single native PHP type -- see PropulsionTypes::JSON_NATIVE_TYPE.
        $this->assertSame('', PropulsionTypes::getPhpNative(PropulsionTypes::JSON));
        $this->assertSame('', PropulsionTypes::getPhpNative(PropulsionTypes::JSONB));
    }
}
