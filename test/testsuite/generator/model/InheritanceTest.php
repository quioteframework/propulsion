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
 * Test class for Inheritance.
 */
class InheritanceTest extends TestCase
{
    public function testGettersAndSetters()
    {
        $inheritance = new Inheritance();
        $inheritance->setKey('foo');
        $inheritance->setClassName('Foo');
        $inheritance->setPackage('foo.bar');
        $inheritance->setAncestor('Bar');

        $this->assertSame('foo', $inheritance->getKey());
        $this->assertSame('Foo', $inheritance->getClassName());
        $this->assertSame('foo.bar', $inheritance->getPackage());
        $this->assertSame('Bar', $inheritance->getAncestor());
    }

    public function testGetSetColumn()
    {
        $inheritance = new Inheritance();
        $column = new Column('foo');
        $inheritance->setColumn($column);
        $this->assertSame($column, $inheritance->getColumn());
    }

    public function testLoadFromXmlSetsAttributes()
    {
        $inheritance = new Inheritance();
        $inheritance->loadFromXML(array(
            'key' => 'foo',
            'class' => 'Foo',
            'package' => 'foo.bar',
            'extends' => 'Bar',
        ));

        $this->assertSame('foo', $inheritance->getKey());
        $this->assertSame('Foo', $inheritance->getClassName());
        $this->assertSame('foo.bar', $inheritance->getPackage());
        $this->assertSame('Bar', $inheritance->getAncestor());
    }

    public function testAppendXmlWritesKeyAndClass()
    {
        $inheritance = new Inheritance();
        $inheritance->setKey('foo');
        $inheritance->setClassName('Foo');

        $doc = new DOMDocument();
        $column = $doc->appendChild($doc->createElement('column'));
        $inheritance->appendXml($column);

        $node = $column->getElementsByTagName('inheritance')->item(0);
        $this->assertSame('foo', $node->getAttribute('key'));
        $this->assertSame('Foo', $node->getAttribute('class'));
        $this->assertFalse($node->hasAttribute('extends'));
    }

    public function testAppendXmlWritesExtendsWhenSet()
    {
        $inheritance = new Inheritance();
        $inheritance->setKey('foo');
        $inheritance->setClassName('Foo');
        $inheritance->setAncestor('Bar');

        $doc = new DOMDocument();
        $column = $doc->appendChild($doc->createElement('column'));
        $inheritance->appendXml($column);

        $node = $column->getElementsByTagName('inheritance')->item(0);
        $this->assertSame('Bar', $node->getAttribute('extends'));
    }
}
