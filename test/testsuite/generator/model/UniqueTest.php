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
 * Test class for Unique.
 */
class UniqueTest extends TestCase
{
    public function testIsUniqueReturnsTrue()
    {
        $unique = new Unique('foo_unique');
        $this->assertTrue($unique->isUnique());
    }

    public function testAppendXmlWritesUniqueElementWithColumns()
    {
        $table = new Table('foo');
        $unique = new Unique('foo_unique');
        $unique->addColumn(['name' => 'foo']);
        $unique->addColumn(['name' => 'bar']);
        $table->addUnique($unique);

        $doc = new DOMDocument();
        $database = $doc->appendChild($doc->createElement('database'));
        $unique->appendXml($database);

        $uniqueNode = $database->getElementsByTagName('unique')->item(0);
        $this->assertNotNull($uniqueNode);
        $this->assertSame('foo_unique', $uniqueNode->getAttribute('name'));

        $columnNodes = $uniqueNode->getElementsByTagName('unique-column');
        $this->assertSame(2, $columnNodes->length);
        $this->assertSame('foo', $columnNodes->item(0)->getAttribute('name'));
        $this->assertSame('bar', $columnNodes->item(1)->getAttribute('name'));
    }

    public function testAppendXmlIncludesVendorInfo()
    {
        $table = new Table('foo');
        $unique = new Unique('foo_unique');
        $unique->addColumn(['name' => 'foo']);
        $vendorInfo = new VendorInfo('mysql');
        $unique->addVendorInfo($vendorInfo);
        $table->addUnique($unique);

        $doc = new DOMDocument();
        $database = $doc->appendChild($doc->createElement('database'));
        $unique->appendXml($database);

        $uniqueNode = $database->getElementsByTagName('unique')->item(0);
        $vendorNode = $uniqueNode->getElementsByTagName('vendor')->item(0);
        $this->assertNotNull($vendorNode);
        $this->assertSame('mysql', $vendorNode->getAttribute('type'));
    }
}
