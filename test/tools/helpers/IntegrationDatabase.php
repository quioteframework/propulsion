<?php

/**
 * This file is part of the Propel package.
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 *
 * @license    MIT License
 */

use Testcontainers\Modules\PostgresContainer;
use Testcontainers\Container\StartedGenericContainer;
use Propulsion\Generator\Config\GeneratorConfig;
use Propulsion\Generator\Manager\ModelManager;
use Propulsion\Generator\Manager\SqlManager;

/**
 * Shared, process-wide Postgres testcontainer backing the "live" integration test
 * tier (Bookstore/CMS fixture tests, i.e. anything extending BookstoreTestBase or
 * CmsTestBase). Started lazily on first use: generates the Bookstore Object Model
 * classes and DDL via the real generator classes (the same ones bin/propulsion
 * uses -- no shelling out), loads the schema into the container, and writes a
 * runtime config file pointing Propel::init() at it.
 *
 * One container serves the whole PHPUnit run (starting one per test class would be
 * far too slow); it's torn down via register_shutdown_function().
 *
 * Set PROPULSION_SKIP_INTEGRATION=1 to skip all tests that depend on this (e.g. in
 * environments without Docker) rather than fail on a Docker error.
 */
class IntegrationDatabase
{
    private static ?StartedGenericContainer $container = null;
    private static bool $attempted = false;
    private static ?string $skipReason = null;

    public static function confFile(): string
    {
        return dirname(__DIR__, 2) . '/fixtures/bookstore/build/conf/bookstore-conf.php';
    }

    public static function classesDir(): string
    {
        return dirname(__DIR__, 2) . '/fixtures/bookstore/build/classes';
    }

    /**
     * Ensures the shared Postgres container is running and the bookstore fixtures
     * are built and loaded into it. Safe to call repeatedly (idempotent after the
     * first call, whether it succeeded or failed).
     *
     * @throws \RuntimeException With a message suitable for markTestSkipped() when
     *         Docker/Postgres aren't usable in this environment.
     */
    public static function ensureReady(): void
    {
        if (self::$attempted) {
            if (self::$skipReason !== null) {
                throw new \RuntimeException(self::$skipReason);
            }
            return;
        }
        self::$attempted = true;

        if (getenv('PROPULSION_SKIP_INTEGRATION')) {
            self::$skipReason = 'PROPULSION_SKIP_INTEGRATION is set.';
            throw new \RuntimeException(self::$skipReason);
        }

        self::workaroundBrokenDockerCredentialHelper();

        try {
            self::$container = (new PostgresContainer())
                ->withPostgresUser('propulsion')
                ->withPostgresPassword('propulsion')
                ->withPostgresDatabase('propulsion_test')
                ->start();
        } catch (\Throwable $e) {
            self::$skipReason = 'Could not start the Postgres testcontainer (is Docker running?): ' . $e->getMessage();
            throw new \RuntimeException(self::$skipReason);
        }

        register_shutdown_function(static function () {
            self::$container?->stop();
        });

        try {
            self::buildFixtures(self::$container->getHost(), self::$container->getFirstMappedPort());
        } catch (\Throwable $e) {
            self::$skipReason = 'Could not build bookstore fixtures: ' . $e->getMessage();
            throw new \RuntimeException(self::$skipReason);
        }

        self::registerClassmapAutoloader();
    }

    /**
     * The bookstore fixtures are generated with the PHP5 builder (flat, unnamespaced
     * classes -- BookPeer, Author, etc.), which composer's PSR-4 autoloading can't
     * find. Historically this relied on a plain "search the include path" autoloader
     * that PHP dropped along with __autoload(); build an equivalent classmap here by
     * scanning the generated classes directory once.
     */
    private static function registerClassmapAutoloader(): void
    {
        $classmap = [];
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator(self::classesDir(), \FilesystemIterator::SKIP_DOTS)
        );
        foreach ($iterator as $file) {
            if ($file->getExtension() === 'php') {
                $classmap[$file->getBasename('.php')] = $file->getPathname();
            }
        }

        spl_autoload_register(static function (string $class) use ($classmap): void {
            if (isset($classmap[$class])) {
                require_once $classmap[$class];
            }
        });
    }

    /**
     * Docker Desktop's default ~/.docker/config.json can reference a credsStore
     * helper binary (e.g. desktop.exe) that isn't invokable from this shell, which
     * makes the Docker API client used by testcontainers/testcontainers fail before
     * it ever tries to pull/run anything. Point it at an empty, repo-local config
     * instead -- but only if the environment hasn't already set one deliberately.
     */
    private static function workaroundBrokenDockerCredentialHelper(): void
    {
        if (getenv('DOCKER_CONFIG') !== false) {
            return;
        }
        $dir = sys_get_temp_dir() . '/propulsion-test-docker-config';
        if (!is_dir($dir)) {
            mkdir($dir, 0777, true);
        }
        $configFile = $dir . '/config.json';
        if (!is_file($configFile)) {
            file_put_contents($configFile, '{}');
        }
        putenv("DOCKER_CONFIG=$dir");
    }

    private static function buildFixtures(string $host, int $port): void
    {
        $fixtureDir = dirname(__DIR__, 2) . '/fixtures/bookstore';
        $repoRoot = dirname(__DIR__, 3);
        $classesDir = self::classesDir();

        if (!is_dir($classesDir) && !mkdir($classesDir, 0777, true) && !is_dir($classesDir)) {
            throw new \RuntimeException("Unable to create $classesDir");
        }

        $config = GeneratorConfig::createFromPropertiesFile(
            $repoRoot . '/generator/default.properties',
            [
                $fixtureDir . '/build.properties',
                $fixtureDir . '/build.propulsion.properties',
            ],
            ['propel.database' => 'pgsql']
        );

        $schemas = glob($fixtureDir . '/*schema.xml');
        sort($schemas);

        $sqlDir = sys_get_temp_dir() . '/propulsion-test-sql';
        if (!is_dir($sqlDir)) {
            mkdir($sqlDir, 0777, true);
        }

        // GeneratorConfig's legacy dot-notation behavior class resolution (e.g.
        // 'test.tools.helpers.bookstore.behavior.AddClassBehavior') is resolved
        // relative to the working directory -- anchor it to the repo root
        // regardless of where the PHPUnit process itself was launched from.
        $previousCwd = getcwd();
        chdir($repoRoot);
        try {
            (new ModelManager($config, $classesDir))->generate($schemas);
            (new SqlManager($config, $sqlDir))->generate($schemas);
        } finally {
            chdir($previousCwd);
        }

        $dsn = "pgsql:host=$host;port=$port;dbname=propulsion_test";
        $pdo = new \PDO($dsn, 'propulsion', 'propulsion');
        $pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        foreach (glob($sqlDir . '/*.sql') as $sqlFile) {
            $pdo->exec((string) file_get_contents($sqlFile));
        }

        self::writeRuntimeConf($dsn);
    }

    private static function writeRuntimeConf(string $dsn): void
    {
        $datasource = [
            'adapter' => 'pgsql',
            'connection' => [
                'dsn' => $dsn,
                'user' => 'propulsion',
                'password' => 'propulsion',
                'classname' => 'DebugPDO',
            ],
        ];

        $config = [
            'datasources' => [
                'default' => 'bookstore',
                'bookstore' => $datasource,
                'bookstore-cms' => $datasource,
                'bookstore-behavior' => $datasource,
            ],
        ];

        $confDir = dirname(self::confFile());
        if (!is_dir($confDir) && !mkdir($confDir, 0777, true) && !is_dir($confDir)) {
            throw new \RuntimeException("Unable to create $confDir");
        }

        file_put_contents(self::confFile(), "<?php\nreturn " . var_export($config, true) . ";\n");
    }
}
