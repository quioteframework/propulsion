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

    private static bool $namespacedAttempted = false;
    private static ?string $namespacedSkipReason = null;

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

        try {
            self::ensureContainerStarted();
        } catch (\Throwable $e) {
            self::$skipReason = $e->getMessage();
            throw new \RuntimeException(self::$skipReason);
        }

        try {
            self::buildFixtures(self::$container->getHost(), self::$container->getFirstMappedPort());
        } catch (\Throwable $e) {
            self::$skipReason = 'Could not build bookstore fixtures: ' . $e->getMessage();
            throw new \RuntimeException(self::$skipReason);
        }

        // Force Propulsion\Propel to load now, which eagerly registers its own
        // legacy-class-map aliases (BaseObject, TableMap, PropelException, ...).
        // Generated fixture classes (BaseTable4 extends BaseObject, etc.) need
        // those bare aliases to already exist the moment the classmap autoloader
        // below pulls them in -- which can happen as early as PHPUnit's test suite
        // discovery, well before anything else would have triggered Propel::init().
        class_exists(\Propulsion\Propel::class);

        self::registerClassmapAutoloader();
    }

    public static function namespacedConfFile(): string
    {
        return dirname(__DIR__, 2) . '/fixtures/namespaced/build/conf/bookstore_namespaced-conf.php';
    }

    public static function namespacedClassesDir(): string
    {
        return dirname(__DIR__, 2) . '/fixtures/namespaced/build/classes';
    }

    /**
     * Same idea as ensureReady(), for the separate "namespaced" fixture project
     * (test/fixtures/namespaced/schema.xml) used by NamespaceTest: its tables
     * declare a `namespace="..."` attribute, which only the PHP84 builders honor,
     * so this targets that platform explicitly (ensureReady()'s bookstore fixtures
     * stay on the default PHP5 target). Reuses the same running container as
     * ensureReady() (starting one if neither has run yet) but a separate database,
     * since both fixture projects define tables named book/author/publisher.
     */
    public static function ensureNamespacedReady(): void
    {
        if (self::$namespacedAttempted) {
            if (self::$namespacedSkipReason !== null) {
                throw new \RuntimeException(self::$namespacedSkipReason);
            }
            return;
        }
        self::$namespacedAttempted = true;

        try {
            self::ensureContainerStarted();
        } catch (\Throwable $e) {
            self::$namespacedSkipReason = $e->getMessage();
            throw new \RuntimeException(self::$namespacedSkipReason);
        }

        try {
            self::buildNamespacedFixtures(self::$container->getHost(), self::$container->getFirstMappedPort());
        } catch (\Throwable $e) {
            self::$namespacedSkipReason = 'Could not build namespaced fixtures: ' . $e->getMessage();
            throw new \RuntimeException(self::$namespacedSkipReason);
        }

        class_exists(\Propulsion\Propel::class);
        self::registerClassmapAutoloader(self::namespacedClassesDir());
    }

    private static function ensureContainerStarted(): void
    {
        if (self::$container !== null) {
            return;
        }

        if (getenv('PROPULSION_SKIP_INTEGRATION')) {
            throw new \RuntimeException('PROPULSION_SKIP_INTEGRATION is set.');
        }

        self::workaroundBrokenDockerCredentialHelper();

        try {
            self::$container = (new PostgresContainer())
                ->withPostgresUser('propulsion')
                ->withPostgresPassword('propulsion')
                ->withPostgresDatabase('propulsion_test')
                ->start();
        } catch (\Throwable $e) {
            throw new \RuntimeException('Could not start the Postgres testcontainer (is Docker running?): ' . $e->getMessage());
        }

        register_shutdown_function(static function () {
            self::$container?->stop();
        });
    }

    private static function buildNamespacedFixtures(string $host, int $port): void
    {
        $fixtureDir = dirname(__DIR__, 2) . '/fixtures/namespaced';
        $repoRoot = dirname(__DIR__, 3);
        $classesDir = self::namespacedClassesDir();

        if (!is_dir($classesDir) && !mkdir($classesDir, 0777, true) && !is_dir($classesDir)) {
            throw new \RuntimeException("Unable to create $classesDir");
        }

        $config = GeneratorConfig::createFromPropertiesFile(
            $repoRoot . '/generator/default.properties',
            [$fixtureDir . '/build.properties'],
            ['propel.database' => 'pgsql', 'propel.targetPlatform' => 'php84']
        );

        $schemas = glob($fixtureDir . '/*schema.xml');
        sort($schemas);

        $sqlDir = sys_get_temp_dir() . '/propulsion-test-sql-namespaced';
        if (!is_dir($sqlDir)) {
            mkdir($sqlDir, 0777, true);
        }

        $previousCwd = getcwd();
        chdir($repoRoot);
        try {
            (new ModelManager($config, $classesDir))->generate($schemas);
            (new SqlManager($config, $sqlDir))->generate($schemas);
        } finally {
            chdir($previousCwd);
        }

        $adminDsn = "pgsql:host=$host;port=$port;dbname=propulsion_test";
        $admin = new \PDO($adminDsn, 'propulsion', 'propulsion');
        $admin->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        if (!$admin->query("SELECT 1 FROM pg_database WHERE datname = 'propulsion_test_namespaced'")->fetchColumn()) {
            $admin->exec('CREATE DATABASE propulsion_test_namespaced');
        }

        $dsn = "pgsql:host=$host;port=$port;dbname=propulsion_test_namespaced";
        $pdo = new \PDO($dsn, 'propulsion', 'propulsion');
        $pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        foreach (glob($sqlDir . '/*.sql') as $sqlFile) {
            $pdo->exec((string) file_get_contents($sqlFile));
        }

        self::writeNamespacedRuntimeConf($dsn);
    }

    private static function writeNamespacedRuntimeConf(string $dsn): void
    {
        $config = [
            'datasources' => [
                'default' => 'bookstore_namespaced',
                'bookstore_namespaced' => [
                    'adapter' => 'pgsql',
                    'connection' => [
                        'dsn' => $dsn,
                        'user' => 'propulsion',
                        'password' => 'propulsion',
                        'classname' => 'DebugPDO',
                        'settings' => [
                            'queries' => [
                                'SET lock_timeout = 5000',
                                'SET statement_timeout = 15000',
                            ],
                        ],
                    ],
                ],
            ],
        ];

        $confDir = dirname(self::namespacedConfFile());
        if (!is_dir($confDir) && !mkdir($confDir, 0777, true) && !is_dir($confDir)) {
            throw new \RuntimeException("Unable to create $confDir");
        }

        file_put_contents(self::namespacedConfFile(), "<?php\nreturn " . var_export($config, true) . ";\n");
    }

    /**
     * The bookstore fixtures are generated with the PHP5 builder (flat, unnamespaced
     * classes -- BookPeer, Author, etc.), which composer's PSR-4 autoloading can't
     * find; the namespaced fixtures use real `namespace Foo\Bar;` declarations, but
     * not in a PSR-4-compatible directory layout composer could map either.
     * Historically this relied on a plain "search the include path" autoloader that
     * PHP dropped along with __autoload(); build an equivalent classmap here instead,
     * scanning each generated file's actual namespace + class declaration once
     * (not just its filename, so this works for both flat and namespaced output).
     */
    private static function registerClassmapAutoloader(?string $classesDir = null): void
    {
        $classmap = [];
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($classesDir ?? self::classesDir(), \FilesystemIterator::SKIP_DOTS)
        );
        foreach ($iterator as $file) {
            if ($file->getExtension() !== 'php') {
                continue;
            }
            $source = file_get_contents($file->getPathname());
            $namespace = '';
            if (preg_match('/^\s*namespace\s+([\w\\\\]+)\s*;/m', $source, $m)) {
                $namespace = $m[1] . '\\';
            }
            if (preg_match_all('/^\s*(?:abstract\s+)?(?:final\s+)?class\s+(\w+)/m', $source, $cm)) {
                foreach ($cm[1] as $cls) {
                    $classmap[$namespace . $cls] = $file->getPathname();
                }
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
                // Fail fast instead of hanging the whole suite: a test that opens a
                // second connection/transaction against a row the first one is still
                // holding (uncommitted) should error out in a few seconds, not block
                // forever. Surfaced by a real deadlock during AggregateColumnBehaviorTest.
                'settings' => [
                    'queries' => [
                        'SET lock_timeout = 5000',
                        'SET statement_timeout = 15000',
                    ],
                ],
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
