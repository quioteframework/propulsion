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
 * Test class for IdMethodParameter.
 */
class IdMethodParameterTest extends TestCase
{
    public function testGettersAndSetters()
    {
        $param = new IdMethodParameter();
        $param->setName('foo');
        $param->setValue('bar');
        $this->assertSame('foo', $param->getName());
        $this->assertSame('bar', $param->getValue());
    }

    public function testGetSetTable()
    {
        $param = new IdMethodParameter();
        $table = new Table('foo');
        $param->setTable($table);
        $this->assertSame($table, $param->getTable());
        $this->assertSame('foo', $param->getTableName());
    }

    public function testLoadFromXmlSetsNameAndValue()
    {
        $param = new IdMethodParameter();
        $param->loadFromXML(['name' => 'sequenceName', 'value' => 'foo_seq']);
        $this->assertSame('sequenceName', $param->getName());
        $this->assertSame('foo_seq', $param->getValue());
    }

    public function testAppendXmlWritesNameAndValue()
    {
        $param = new IdMethodParameter();
        $param->setName('foo');
        $param->setValue('bar');

        $doc = new DOMDocument();
        $table = $doc->appendChild($doc->createElement('table'));
        $param->appendXml($table);

        $paramNode = $table->getElementsByTagName('id-method-parameter')->item(0);
        $this->assertNotNull($paramNode);
        $this->assertSame('foo', $paramNode->getAttribute('name'));
        $this->assertSame('bar', $paramNode->getAttribute('value'));
    }

    public function testAppendXmlOmitsNameAttributeWhenNameIsEmpty()
    {
        $param = new IdMethodParameter();
        $param->setValue('bar');

        $doc = new DOMDocument();
        $table = $doc->appendChild($doc->createElement('table'));
        $param->appendXml($table);

        $paramNode = $table->getElementsByTagName('id-method-parameter')->item(0);
        $this->assertFalse($paramNode->hasAttribute('name'));
        $this->assertSame('bar', $paramNode->getAttribute('value'));
    }
}
