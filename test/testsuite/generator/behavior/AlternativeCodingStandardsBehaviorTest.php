<?php

use PHPUnit\Framework\TestCase;
/*
 *	$Id: TimestampableBehaviorTest.php 2035 2010-11-14 17:54:27Z francois $
 * This file is part of the Propulsion package.
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 *
 * @license    MIT License
 */

/**
 * Tests for TimestampableBehavior class
 *
 * @author     François Zaninotto
 * @version    $Revision$
 */
class AlternativeCodingStandardsBehaviorTest extends TestCase
{
	public static function convertBracketsNewlineDataProvider()
	{
		return array(
			array("class Foo {
}", "class Foo
{
}"),
			array("if (true) {
}", "if (true)
{
}"),
			array("} else {
}", "}
else
{
}"),
			array("foreach (\$i as \$j) {
}", "foreach (\$i as \$j)
{
}"),
		);
	}

	/**
	 * @dataProvider convertBracketsNewlineDataProvider
	 */
	#[\PHPUnit\Framework\Attributes\DataProvider('convertBracketsNewlineDataProvider')]
	public function testConvertBracketsNewline($input, $output)
	{
		$b = new TestableAlternativeCodingStandardsBehavior();
		$b->filter($input);
		$this->assertEquals($output, $input);
	}

	public function testRemoveClosingComments()
	{
		$b = new TestableAlternativeCodingStandardsBehavior();
		$script = "\tsave()\n\t{\n\t} // save()\n";
		$b->filter($script);
		$this->assertStringNotContainsString('// save()', $script);
	}

	public function testUseWhitespaceReplacesTabsWithConfiguredSize()
	{
		$b = new TestableAlternativeCodingStandardsBehavior();
		$b->setParameters(array(
			'tab_size' => 4,
			'brackets_newline' => 'false',
			'remove_closing_comments' => 'false',
			'use_whitespace' => 'true',
			'strip_comments' => 'false',
		));
		$script = "\tfoo();\n";
		$b->filter($script);
		$this->assertEquals("    foo();\n", $script);
	}

	public function testDisablingAllFiltersLeavesScriptUnchanged()
	{
		$b = new TestableAlternativeCodingStandardsBehavior();
		$b->setParameters(array(
			'brackets_newline' => 'false',
			'remove_closing_comments' => 'false',
			'use_whitespace' => 'false',
			'strip_comments' => 'false',
		));
		$script = "if (true) {\n} // done\n";
		$b->filter($script);
		$this->assertEquals("if (true) {\n} // done\n", $script);
	}

	public function testStripCommentsRemovesInlineAndBlockComments()
	{
		$code = "<?php\n// a comment\n\$foo = 1; /* block */\n";
		$result = AlternativeCodingStandardsBehavior::stripComments($code);
		$this->assertStringNotContainsString('a comment', $result);
		$this->assertStringNotContainsString('block', $result);
		$this->assertStringContainsString('$foo = 1;', $result);
	}

	public function testFilterStripsCommentsWhenConfigured()
	{
		$b = new TestableAlternativeCodingStandardsBehavior();
		$b->setParameters(array(
			'brackets_newline' => 'false',
			'remove_closing_comments' => 'false',
			'use_whitespace' => 'false',
			'strip_comments' => 'true',
		));
		$script = "<?php\n// a comment\n\$foo = 1;\n";
		$b->filter($script);
		$this->assertStringNotContainsString('a comment', $script);
	}

	public function testFilterWrapperMethodsAllDelegateToFilter()
	{
		$b = new AlternativeCodingStandardsBehavior();
		foreach (array('objectFilter', 'extensionObjectFilter', 'queryFilter', 'extensionQueryFilter', 'peerFilter', 'extensionPeerFilter', 'tableMapFilter') as $method) {
			$script = "if (true) {\n}";
			$b->$method($script);
			$this->assertStringContainsString("if (true)\n{\n}", $script, "$method() applies the same filtering as filter()");
		}
	}
}

class TestableAlternativeCodingStandardsBehavior extends AlternativeCodingStandardsBehavior {
	public function filter(string &$script): void
	{
		parent::filter($script);
	}
}
