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
 * Test for PropulsionJSONParser class
 *
 * @author     Francois Zaninotto
 * @package    runtime.parser
 */
class PropulsionParserTest extends TestCase
{
	public function testGetParser()
	{
		$parser = PropulsionParser::getParser('XML');
		$this->assertTrue($parser instanceof PropulsionXMLParser);
	}

	/**
	 * @expectedException PropulsionException
	 */
	public function testGetParserThrowsExceptionOnWrongParser()
	{
		$this->expectException(PropulsionException::class);
		$parser = PropulsionParser::getParser('Foo');
	}

	public function testLoad()
	{
		$fixtureFile = dirname(__FILE__) . '/fixtures/test_data.xml';
		$parser = PropulsionParser::getParser('XML');
		$content = $parser->load($fixtureFile);
		$expectedContent = <<<EOF
<?xml version="1.0" encoding="UTF-8"?>
<foo>
  <bar prop="0"/>
  <bar prop="1"/>
</foo>
EOF;
		$this->assertEquals($expectedContent, $content, 'PropulsionParser::load() executes PHP code in files');
	}

	public function testDump()
	{
		$testContent = "Foo Content";
		$testFile = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'propel_test_' . microtime();
		$parser = PropulsionParser::getParser('XML');
		$parser->dump($testContent, $testFile);
		$content = file_get_contents($testFile);
		$this->assertEquals($testContent, $content);
		unlink($testFile);
	}

}
