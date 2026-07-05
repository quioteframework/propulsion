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
 * @author	William Durand <william.durand1@gmail.com>
 */
class GeneratorConfigTest extends TestCase
{
	protected $pathToFixtureFiles;

	public function setUp(): void
	{
		$this->pathToFixtureFiles = dirname(__FILE__) . '/../../../fixtures/generator/config';
	}

	public function testGetClassnameWithClass()
	{
		$file = $this->pathToFixtureFiles . '/Foobar.php';

		if (!file_exists($file)) {
			$this->markTestSkipped();
		}

		// Load the file to simulate the autoloading process
		require $file;

		$generator = new GeneratorConfig();
		$generator->setBuildProperty('propulsion.foo.bar', 'Foobar');

		$this->assertSame('Foobar', $generator->getClassname('propulsion.foo.bar'));
	}

	public function testGetClassnameWithClassAndNamespace()
	{
		$file = $this->pathToFixtureFiles . '/FoobarWithNS.php';

		if (!file_exists($file)) {
			$this->markTestSkipped();
		}

		// Load the file to simulate the autoloading process
		require $file;

		$generator = new GeneratorConfig();
		$generator->setBuildProperty('propulsion.foo.bar', '\Foo\Test\FoobarWithNS');

		$this->assertSame('\Foo\Test\FoobarWithNS', $generator->getClassname('propulsion.foo.bar'));
	}

	/**
 	 * @expectedException EngineException
 	 */
	public function testGetClassnameOnInexistantProperty()
	{
		$this->expectException(EngineException::class);
		$generator = new GeneratorConfig();
		$generator->getClassname('propulsion.foo.bar');
	}

	/**
	 * The recommended build-time connection config format: a plain PHP array
	 * passed directly via the `propulsion.buildtimeConfigArray` build property
	 * (e.g. an ad-hoc `--config` override), in the same shape
	 * getBuildConnections() returns. See KNOWN_ISSUES.md for why this
	 * supersedes the legacy buildtime-conf.xml format.
	 */
	public function testGetBuildConnectionsFromDirectPhpArray()
	{
		$generator = new GeneratorConfig();
		$generator->setBuildProperty('buildtimeConfigArray', [
			'default' => 'bookstore',
			'datasources' => [
				'bookstore' => ['adapter' => 'pgsql', 'dsn' => 'pgsql:host=localhost;dbname=mydb', 'user' => 'me', 'password' => 'secret'],
			],
		]);

		$connections = $generator->getBuildConnections();

		$this->assertSame([
			'bookstore' => ['adapter' => 'pgsql', 'dsn' => 'pgsql:host=localhost;dbname=mydb', 'user' => 'me', 'password' => 'secret'],
		], $connections);
		$this->assertSame([
			'adapter' => 'pgsql', 'dsn' => 'pgsql:host=localhost;dbname=mydb', 'user' => 'me', 'password' => 'secret',
		], $generator->getBuildConnection());
	}

	/**
	 * Same format as testGetBuildConnectionsFromDirectPhpArray(), but loaded
	 * from a `.php` file via `propulsion.buildtimeConfFile` -- the file-based
	 * equivalent of --buildtime-conf pointing at a plain PHP config file
	 * instead of the legacy XML format.
	 */
	public function testGetBuildConnectionsFromPhpConfigFile()
	{
		$dir = sys_get_temp_dir() . '/propulsion-generator-config-test-' . uniqid();
		mkdir($dir, 0777, true);
		$file = $dir . '/buildtime-conf.php';
		file_put_contents($file, '<?php return ' . var_export([
			'default' => 'bookstore',
			'datasources' => [
				'bookstore' => ['adapter' => 'mysql', 'dsn' => 'mysql:host=localhost;dbname=mydb', 'user' => 'me', 'password' => 'secret'],
			],
		], true) . ';');

		try {
			$generator = new GeneratorConfig();
			$generator->setBuildProperty('buildtimeConfFile', $file);

			$connections = $generator->getBuildConnections();

			$this->assertSame([
				'bookstore' => ['adapter' => 'mysql', 'dsn' => 'mysql:host=localhost;dbname=mydb', 'user' => 'me', 'password' => 'secret'],
			], $connections);
		} finally {
			unlink($file);
			rmdir($dir);
		}
	}

	/**
	 * The legacy buildtime-conf.xml format (deprecated but still supported --
	 * see KNOWN_ISSUES.md) must keep working, dispatched to based on the
	 * `.xml` file extension.
	 */
	public function testGetBuildConnectionsFromLegacyXmlConfigFile()
	{
		$dir = sys_get_temp_dir() . '/propulsion-generator-config-test-' . uniqid();
		mkdir($dir, 0777, true);
		$file = $dir . '/buildtime-conf.xml';
		file_put_contents($file, '<config><propel><datasources default="bookstore">'
			. '<datasource id="bookstore"><adapter>pgsql</adapter>'
			. '<connection><dsn>pgsql:host=localhost;dbname=mydb</dsn><user>me</user><password>secret</password></connection>'
			. '</datasource></datasources></propel></config>');

		try {
			$generator = new GeneratorConfig();
			$generator->setBuildProperty('buildtimeConfFile', $file);

			$connections = $generator->getBuildConnections();

			$this->assertSame([
				'bookstore' => ['adapter' => 'pgsql', 'dsn' => 'pgsql:host=localhost;dbname=mydb', 'user' => 'me', 'password' => 'secret'],
			], $connections);
		} finally {
			unlink($file);
			rmdir($dir);
		}
	}
}
