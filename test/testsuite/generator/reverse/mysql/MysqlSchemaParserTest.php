<?php


use PHPUnit\Framework\TestCase;
/**
 * This file is part of the Propulsion package.
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 *
 * @license    MIT License
 */

set_include_path(get_include_path().PATH_SEPARATOR.dirname(__FILE__).'/../../../../../generator/Lib');

/**
 * Tests for Mysql database schema parser.
 *
 * @author      William Durand
 * @version     $Revision$
 * @package     propel.generator.reverse.mysql
 */
class MysqlSchemaParserTest extends TestCase
{
	protected function setUp(): void
	{
		parent::setUp();

		$xmlDom = new DOMDocument();
		$xmlDom->load(dirname(__FILE__) . '/../../../../fixtures/reverse/mysql/runtime-conf.xml');
		$xml = simplexml_load_string($xmlDom->saveXML());
		$phpconf = OpenedPropulsionConvertConfTask::simpleXmlToArray($xml);

		Propulsion::setConfiguration($phpconf);
        Propulsion::initialize();
	}

	protected function tearDown(): void
	{
		parent::tearDown();
		// testParse() markTestSkipped()s itself (below) when no MySQL server is
		// reachable, e.g. PROPULSION_SKIP_INTEGRATION=1 or no Docker at all --
		// but tearDown() still always runs, even for a skipped test. Without a
		// Docker-backed run, the bookstore fixtures' live-DB conf was never
		// written (ensureClassesGenerated() only generates the *classes*, not
		// this conf file -- see IntegrationDatabase), so guard against
		// Propulsion::init() throwing on a config file that doesn't exist and
		// turning a clean skip into an error.
		$conf = dirname(__FILE__) . '/../../../../fixtures/bookstore/build/conf/bookstore-conf.php';
		if (file_exists($conf)) {
			Propulsion::init($conf);
		}
	}

    public function testParse()
    {
        // Unlike the rest of this suite (Postgres, via a testcontainer -- see
        // IntegrationDatabase), no MySQL server is provisioned anywhere in this
        // environment, so a real connection attempt here always fails ("No such file or
        // directory" -- the DSN has no host, so PDO tries a local Unix socket that
        // doesn't exist). Skip gracefully, the same way BookstoreTestBase's setUp() skips
        // (rather than errors) when its own PROPULSION_SKIP_INTEGRATION/Docker-backed
        // Postgres isn't available.
        try {
            $con = Propulsion::getConnection('reverse-bookstore');
        } catch (PropulsionException $e) {
            $this->markTestSkipped('No MySQL server available to reverse-engineer against: ' . $e->getMessage());
        }
        $parser = new MysqlSchemaParser($con);
        $parser->setGeneratorConfig(new QuickGeneratorConfig());

        $database = new Database();
        $database->setPlatform(new DefaultPlatform());

        $this->assertEquals(1, $parser->parse($database), 'One table and one view defined should return one as we exclude views');

		$tables = $database->getTables();
		$this->assertEquals(1, count($tables));

		$table = $tables[0];
		$this->assertEquals('Book', $table->getPhpName());
		$this->assertEquals(4, count($table->getColumns()));
    }
}

class OpenedPropulsionConvertConfTask extends PropulsionConvertConfTask
{
	public static function simpleXmlToArray($xml)
	{
		return parent::simpleXmlToArray($xml);
	}
}
