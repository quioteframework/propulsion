<?php


use PHPUnit\Framework\TestCase;
use Propulsion\Generator\Util\PropelSQLParser;

/**
 * This file is part of the Propulsion package.
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 *
 * @license    MIT License
 */

/**
 *
 * @package    generator.util
 */
class PropelSQLParserTest extends TestCase
{
	public static function stripSqlCommentsDataProvider()
	{
		return array(
			array('', ''),
			array('foo with no comments', 'foo with no comments'),
			array('foo with // inline comments', 'foo with // inline comments'),
			array("foo with\n// comments", "foo with\n"),
			array(" // comments preceded by blank\nfoo", "foo"),
			array("// slash-style comments\nfoo", "foo"),
			array("-- dash-style comments\nfoo", "foo"),
			array("# hash-style comments\nfoo", "foo"),
			array("/* c-style comments*/\nfoo", "\nfoo"),
			array("foo with\n// comments\nwith foo", "foo with\nwith foo"),
			array("// comments with\nfoo with\n// comments\nwith foo", "foo with\nwith foo"),
		);
	}

	/**
	 * @dataProvider stripSqlCommentsDataProvider
	 */
	#[\PHPUnit\Framework\Attributes\DataProvider('stripSqlCommentsDataProvider')]
	public function testStripSQLComments($input, $output)
	{
		$parser = new PropelSQLParser();
		$parser->setSQL($input);
		$parser->stripSQLCommentLines();
		$this->assertEquals($output, $parser->getSQL());
	}

	public static function convertLineFeedsToUnixStyleDataProvider()
	{
		return array(
			array('', ''),
			array("foo bar", "foo bar"),
			array("foo\nbar", "foo\nbar"),
			array("foo\rbar", "foo\nbar"),
			array("foo\r\nbar", "foo\nbar"),
			array("foo\r\nbar\rbaz\nbiz\r\n", "foo\nbar\nbaz\nbiz\n"),
		);
	}

	/**
	 * @dataProvider convertLineFeedsToUnixStyleDataProvider
	 */
	#[\PHPUnit\Framework\Attributes\DataProvider('convertLineFeedsToUnixStyleDataProvider')]
	public function testConvertLineFeedsToUnixStyle($input, $output)
	{
		$parser = new PropelSQLParser();
		$parser->setSQL($input);
		$parser->convertLineFeedsToUnixStyle();
		$this->assertEquals($output, $parser->getSQL());
	}

	public static function explodeIntoStatementsDataProvider()
	{
		return array(
			array('', array()),
			array('foo', array('foo')),
			array('foo;', array('foo')),
			array('foo; ', array('foo')),
			array('foo;bar', array('foo', 'bar')),
			array('foo;bar;', array('foo', 'bar')),
			array("f\no\no;\nb\nar\n;", array("f\no\no", "b\nar")),
			array('foo";"bar;baz', array('foo";"bar', 'baz')),
			array('foo\';\'bar;baz', array('foo\';\'bar', 'baz')),
			array('foo"\";"bar;', array('foo"\";"bar')),
		);
	}
	/**
	 * @dataProvider explodeIntoStatementsDataProvider
	 */
	#[\PHPUnit\Framework\Attributes\DataProvider('explodeIntoStatementsDataProvider')]
	public function testExplodeIntoStatements($input, $output)
	{
		$parser = new PropelSQLParser();
		$parser->setSQL($input);
		$this->assertEquals($output, $parser->explodeIntoStatements());
	}
}
