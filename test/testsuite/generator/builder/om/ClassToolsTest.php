<?php

/**
 * This file is part of the Propulsion package.
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 *
 * @license    MIT License
 */

use PHPUnit\Framework\TestCase;
use Propulsion\Generator\Builder\OM\ClassTools;

/**
 * Test class for ClassTools.
 */
class ClassToolsTest extends TestCase
{
    public function testClassnameWithDottedPath()
    {
        $this->assertSame('Foo', ClassTools::classname('foo.bar.Foo'));
    }

    public function testClassnameWithoutDottedPath()
    {
        $this->assertSame('Foo', ClassTools::classname('Foo'));
    }

    public function testGetFilePathWithSingleDotPath()
    {
        $this->assertSame('foo/bar/Foo.php', ClassTools::getFilePath('foo.bar.Foo'));
    }

    public function testGetFilePathWithPrefixAndClassname()
    {
        $this->assertSame('foo/bar/Foo.php', ClassTools::getFilePath('foo.bar', 'Foo'));
    }

    public function testGetFilePathWithCustomExtension()
    {
        $this->assertSame('foo/bar/Foo.txt', ClassTools::getFilePath('foo.bar', 'Foo', '.txt'));
    }

    public function testCreateFilePathWithClassname()
    {
        $this->assertSame('foo/bar/Foo.php', ClassTools::createFilePath('foo/bar', 'Foo'));
    }

    public function testCreateFilePathWithEmptyPathAndClassname()
    {
        $this->assertSame('Foo.php', ClassTools::createFilePath('', 'Foo'));
    }

    public function testCreateFilePathWithoutClassname()
    {
        $this->assertSame('foo/bar.php', ClassTools::createFilePath('foo/bar'));
    }

    private function makeTable($name = 'foo')
    {
        $database = new Database('foodb');
        return $database->addTable(new Table($name));
    }

    public function testGetBasePeerReturnsDefaultWhenNotSpecified()
    {
        $table = $this->makeTable();
        $this->assertSame('BasePeer', ClassTools::getBasePeer($table));
    }

    public function testGetBasePeerReturnsCustomWhenSpecified()
    {
        $table = $this->makeTable();
        $table->setBasePeer('MyBasePeer');
        $this->assertSame('MyBasePeer', ClassTools::getBasePeer($table));
    }

    public function testGetBaseClassReturnsDefaultWhenNotSpecified()
    {
        $table = $this->makeTable();
        $this->assertSame('BaseObject', ClassTools::getBaseClass($table));
    }

    public function testGetBaseClassReturnsCustomWhenSpecified()
    {
        $table = $this->makeTable();
        $table->setBaseClass('MyBaseObject');
        $this->assertSame('MyBaseObject', ClassTools::getBaseClass($table));
    }

    public function testGetInterfaceReturnsPersistentByDefaultForWritableTable()
    {
        $table = $this->makeTable();
        $this->assertSame('Persistent', ClassTools::getInterface($table));
    }

    public function testGetInterfaceReturnsNullForReadOnlyTable()
    {
        $database = new Database('foodb');
        $table = $database->addTable(array('name' => 'foo', 'readOnly' => 'true'));
        $this->assertNull(ClassTools::getInterface($table));
    }

    public function testGetInterfaceReturnsCustomWhenSpecified()
    {
        $table = $this->makeTable();
        $table->setInterface('MyInterface');
        $this->assertSame('MyInterface', ClassTools::getInterface($table));
    }

    public function testGetPhpReservedWordsContainsCommonKeywords()
    {
        $words = ClassTools::getPhpReservedWords();
        $this->assertIsArray($words);
        $this->assertContains('class', $words);
        $this->assertContains('function', $words);
        $this->assertContains('namespace', $words);
    }
}
