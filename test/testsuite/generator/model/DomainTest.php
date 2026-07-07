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
 * Test class for Domain.
 */
class DomainTest extends TestCase
{
    public function testConstructorDefaultsSqlTypeToType()
    {
        $domain = new Domain('INTEGER');
        $this->assertSame('INTEGER', $domain->getType());
        $this->assertSame('INTEGER', $domain->getSqlType());
    }

    public function testConstructorWithExplicitSqlType()
    {
        $domain = new Domain('INTEGER', 'INT');
        $this->assertSame('INTEGER', $domain->getType());
        $this->assertSame('INT', $domain->getSqlType());
    }

    public function testCopyCopiesAllFields()
    {
        $source = new Domain('INTEGER', 'INT', 10, 2);
        $source->setName('foo');
        $source->setDescription('a description');
        $source->setDefaultValue(new ColumnDefaultValue('5', ColumnDefaultValue::TYPE_VALUE));

        $target = new Domain();
        $target->copy($source);

        $this->assertSame('foo', $target->getName());
        $this->assertSame('a description', $target->getDescription());
        $this->assertSame(10, $target->getSize());
        $this->assertSame(2, $target->getScale());
        $this->assertSame('INT', $target->getSqlType());
        $this->assertSame('INTEGER', $target->getType());
        $this->assertSame('5', $target->getDefaultValue()->getValue());
    }

    public function testReplaceScaleOnlyReplacesWhenNotNull()
    {
        $domain = new Domain('INTEGER', 'INT', 10, 2);
        $domain->replaceScale(null);
        $this->assertSame(2, $domain->getScale());
        $domain->replaceScale(4);
        $this->assertSame(4, $domain->getScale());
    }

    public function testReplaceSizeOnlyReplacesWhenNotNull()
    {
        $domain = new Domain('INTEGER', 'INT', 10, 2);
        $domain->replaceSize(null);
        $this->assertSame(10, $domain->getSize());
        $domain->replaceSize(20);
        $this->assertSame(20, $domain->getSize());
    }

    public function testReplaceTypeOnlyReplacesWhenNotNull()
    {
        $domain = new Domain('INTEGER');
        $domain->replaceType(null);
        $this->assertSame('INTEGER', $domain->getType());
        $domain->replaceType('VARCHAR');
        $this->assertSame('VARCHAR', $domain->getType());
    }

    public function testReplaceSqlTypeOnlyReplacesWhenNotNull()
    {
        $domain = new Domain('INTEGER', 'INT');
        $domain->replaceSqlType(null);
        $this->assertSame('INT', $domain->getSqlType());
        $domain->replaceSqlType('INTEGER');
        $this->assertSame('INTEGER', $domain->getSqlType());
    }

    public function testReplaceDefaultValueOnlyReplacesWhenNotNull()
    {
        $domain = new Domain('INTEGER');
        $original = new ColumnDefaultValue('1', ColumnDefaultValue::TYPE_VALUE);
        $domain->setDefaultValue($original);
        $domain->replaceDefaultValue(null);
        $this->assertSame($original, $domain->getDefaultValue());

        $replacement = new ColumnDefaultValue('2', ColumnDefaultValue::TYPE_VALUE);
        $domain->replaceDefaultValue($replacement);
        $this->assertSame($replacement, $domain->getDefaultValue());
    }

    public function testPrintSizeWithSizeAndScale()
    {
        $domain = new Domain('DECIMAL', 'DECIMAL', 10, 2);
        $this->assertSame('(10,2)', $domain->printSize());
    }

    public function testPrintSizeWithSizeOnly()
    {
        $domain = new Domain('VARCHAR', 'VARCHAR', 255);
        $this->assertSame('(255)', $domain->printSize());
    }

    public function testPrintSizeWithNeither()
    {
        $domain = new Domain('INTEGER');
        $this->assertSame('', $domain->printSize());
    }

    public function testGetPhpDefaultValueReturnsNullWhenNoDefault()
    {
        $domain = new Domain('INTEGER');
        $this->assertNull($domain->getPhpDefaultValue());
    }

    public function testGetPhpDefaultValueThrowsForExpression()
    {
        $domain = new Domain('INTEGER');
        $domain->setDefaultValue(new ColumnDefaultValue('NOW()', ColumnDefaultValue::TYPE_EXPR));
        $this->expectException(\Propulsion\Generator\Exception\EngineException::class);
        $domain->getPhpDefaultValue();
    }

    public function testGetPhpDefaultValueCastsBooleanType()
    {
        $domain = new Domain(PropulsionTypes::BOOLEAN);
        $domain->setDefaultValue(new ColumnDefaultValue('true', ColumnDefaultValue::TYPE_VALUE));
        $this->assertTrue($domain->getPhpDefaultValue());
    }

    public function testGetPhpDefaultValueReturnsRawValueForNonBoolean()
    {
        $domain = new Domain('INTEGER');
        $domain->setDefaultValue(new ColumnDefaultValue('5', ColumnDefaultValue::TYPE_VALUE));
        $this->assertSame('5', $domain->getPhpDefaultValue());
    }

    public function testAppendXmlWritesBasicAttributes()
    {
        $domain = new Domain('INTEGER');
        $domain->setName('foo');

        $doc = new DOMDocument();
        $table = $doc->appendChild($doc->createElement('table'));
        $domain->appendXml($table);

        $domainNode = $table->getElementsByTagName('domain')->item(0);
        $this->assertSame('INTEGER', $domainNode->getAttribute('type'));
        $this->assertSame('foo', $domainNode->getAttribute('name'));
        $this->assertFalse($domainNode->hasAttribute('sqlType'));
    }

    public function testAppendXmlWritesSqlTypeWhenDifferentFromType()
    {
        $domain = new Domain('INTEGER', 'INT');
        $domain->setName('foo');

        $doc = new DOMDocument();
        $table = $doc->appendChild($doc->createElement('table'));
        $domain->appendXml($table);

        $domainNode = $table->getElementsByTagName('domain')->item(0);
        $this->assertSame('INT', $domainNode->getAttribute('sqlType'));
    }

    public function testAppendXmlWritesSizeScaleDescriptionAndDefaultValue()
    {
        $domain = new Domain('DECIMAL', 'DECIMAL', 10, 2);
        $domain->setName('foo');
        $domain->setDescription('a description');
        $domain->setDefaultValue(new ColumnDefaultValue('5', ColumnDefaultValue::TYPE_VALUE));

        $doc = new DOMDocument();
        $table = $doc->appendChild($doc->createElement('table'));
        $domain->appendXml($table);

        $domainNode = $table->getElementsByTagName('domain')->item(0);
        $this->assertSame('10', $domainNode->getAttribute('size'));
        $this->assertSame('2', $domainNode->getAttribute('scale'));
        $this->assertSame('a description', $domainNode->getAttribute('description'));
        $this->assertSame('5', $domainNode->getAttribute('defaultValue'));
    }

    public function testAppendXmlWritesDefaultExprForExpressionDefault()
    {
        $domain = new Domain('DATETIME');
        $domain->setName('foo');
        $domain->setDefaultValue(new ColumnDefaultValue('NOW()', ColumnDefaultValue::TYPE_EXPR));

        $doc = new DOMDocument();
        $table = $doc->appendChild($doc->createElement('table'));
        $domain->appendXml($table);

        $domainNode = $table->getElementsByTagName('domain')->item(0);
        $this->assertSame('NOW()', $domainNode->getAttribute('defaultExpr'));
        $this->assertFalse($domainNode->hasAttribute('defaultValue'));
    }

    public function testSetAndGetDatabase()
    {
        $domain = new Domain('INTEGER');
        $database = new Database('foo');
        $domain->setDatabase($database);
        $this->assertSame($database, $domain->getDatabase());
    }
}
