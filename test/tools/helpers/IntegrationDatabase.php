<?php

/**
 * This file is part of the Propulsion package.
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 *
 * @license    MIT License
 */

use Testcontainers\Modules\PostgresContainer;
use Testcontainers\Modules\MySQLContainer;
use Testcontainers\Container\GenericContainer;
use Testcontainers\Container\StartedGenericContainer;
use Testcontainers\Wait\WaitForLog;
use Propulsion\Generator\Config\GeneratorConfig;
use Propulsion\Generator\Manager\ModelManager;
use Propulsion\Generator\Manager\SqlManager;
use Propulsion\Generator\Util\PropulsionSQLParser;

/**
 * Shared, process-wide Postgres testcontainer backing the "live" integration test
 * tier (Bookstore/CMS fixture tests, i.e. anything extending BookstoreTestBase or
 * CmsTestBase). Started lazily on first use: generates the Bookstore Object Model
 * classes and DDL via the real generator classes (the same ones bin/propulsion
 * uses -- no shelling out), loads the schema into the container, and writes a
 * runtime config file pointing Propulsion::init() at it.
 *
 * One container serves the whole PHPUnit run (starting one per test class would be
 * far too slow); it's torn down via register_shutdown_function().
 *
 * Set PROPULSION_SKIP_INTEGRATION=1 to skip all tests that depend on this (e.g. in
 * environments without Docker) rather than fail on a Docker error.
 *
 * Set PROPULSION_TEST_DB=mysql|mariadb|mssql|oracle to run the *main bookstore
 * fixture only* against that testcontainer instead of the default Postgres
 * one -- useful for confirming whether a given test failure is a real library bug
 * or a platform-semantics difference (documented ones: MySQL's loose numeric
 * coercion in WHERE clauses and relaxed non-standard GROUP BY; identifier quoting
 * defaults differ per platform too). The "schemas" and "namespaced" fixture
 * projects are Postgres-schema-feature-specific by design (see
 * ensureSchemasReady()/ensureNamespacedReady()) and are not retargeted -- their
 * tests degrade to markTestSkipped() automatically in every non-Postgres mode,
 * since they try to open a `pgsql:` DSN against a differently-flavored container
 * and fail the same way they would with no Docker at all.
 *
 * MSSQL and Oracle each have their own full-suite CI job, the same as Postgres and
 * MySQL (see .github/workflows/tests.yml). MariaDB is the one platform this class
 * supports that CI does not cover -- see KNOWN_ISSUES.md. Requirements to run
 * MSSQL/Oracle locally, since neither ships a PDO driver most PHP installs have
 * by default:
 *   - MSSQL: `pdo_dblib` (Debian/Ubuntu: the `php-sybase` package). No further
 *     environment setup needed; DBMSSQL/MssqlPropulsionPDO already assume dblib.
 *   - Oracle: `pdo_oci`, built from PECL against a real Oracle Instant Client
 *     (Basic + SDK packages from https://www.oracle.com/database/technologies/instant-client/
 *     -- pick the linux-arm64 or linux-x86-64 download page for your host arch).
 *     `pdo_oci` isn't distributed as a prebuilt package for arbitrary PHP versions,
 *     so this means: `pecl download pdo_oci`, `phpize`, then
 *     `./configure --with-pdo-oci=instantclient,/path/to/instantclient,<version>`,
 *     `make`. The built `modules/pdo_oci.so` can be loaded without installing it
 *     system-wide via `php -d extension=/path/to/pdo_oci.so ...`, but the Instant
 *     Client directory (plus, on Ubuntu 24.04+, a `libaio.so.1 -> libaio.so.1t64`
 *     compat symlink for the `libaio1t64` "64-bit time_t" package rename) has to
 *     be on the dynamic loader's search path at runtime, not just at build time.
 *     Either put it in `/etc/ld.so.conf.d/` and run `ldconfig` (what CI does, and
 *     what makes it work for every process without further setup), or export
 *     `LD_LIBRARY_PATH` for each invocation. The rpath the build bakes in is not
 *     enough on its own: it is emitted as `DT_RUNPATH`, which the loader consults
 *     only for `pdo_oci.so`'s own direct dependencies, so `libclntsh.so` resolves
 *     but the `libnnz.so` it in turn needs does not.
 */
class IntegrationDatabase
{
    /**
     * Applied to every testcontainer this class starts, so a leaked container
     * (register_shutdown_function() doesn't run on kill -9 or a timeout-killed
     * process -- see KNOWN_ISSUES.md) can always be found and cleaned up later
     * regardless of its random generated name: `docker ps -aq --filter
     * label=propulsion.test-container=true`. Cleanup uses `docker stop`/`docker
     * rm`, not a signal sent directly to the container's own process, so this
     * works the same way regardless of the host OS running the test suite.
     */
    public const CONTAINER_LABELS = ['propulsion.test-container' => 'true'];

    private static ?StartedGenericContainer $container = null;
    private static bool $attempted = false;
    private static ?string $skipReason = null;

    /**
     * self::$container is only ever read after a caller has already gone through
     * ensureContainerStarted() (which either populates it or throws) -- but that
     * guarantee holds across two separate static-property accesses that PHPStan
     * can't correlate, so every read site needs this instead of a bare
     * self::$container->foo() null-unsafe call.
     */
    private static function requireContainer(): StartedGenericContainer
    {
        if (self::$container === null) {
            throw new \RuntimeException('No testcontainer is running -- ensureContainerStarted() must run first.');
        }
        return self::$container;
    }

    /**
     * PDO::query() is typed as returning PDOStatement|false, but every caller
     * here already has PDO::ATTR_ERRMODE_EXCEPTION set, so a syntactically
     * valid query never actually returns false in practice -- this just makes
     * that real guarantee explicit instead of asserting it away.
     */
    private static function queryScalar(\PDO $pdo, string $sql): mixed
    {
        $stmt = $pdo->query($sql);
        if ($stmt === false) {
            throw new \RuntimeException("Query failed: $sql");
        }
        return $stmt->fetchColumn();
    }

    /**
     * Tracks the Docker-free half of ensureReady(): generating the Bookstore Object
     * Model classes + SQL via the real generator classes. This needs no database
     * connection at all (Postgres, MySQL, or otherwise) -- it's pure schema-XML-to-PHP
     * codegen -- so it's split out and run unconditionally, independent of whether a
     * live Postgres testcontainer can be started. Tests that only inspect generated
     * code/object-model shape (not an actual DB round-trip) can depend on this alone
     * via ensureClassesGenerated() and run identically with or without Docker.
     */
    private static bool $classesGenerated = false;
    private static ?string $classesGenerationError = null;
    private static bool $classmapRegistered = false;

    /**
     * SA/SYSTEM password for the MSSQL/Oracle testcontainers. Container-local only
     * (nothing outside this ephemeral container ever authenticates with it), same
     * throwaway-credential convention as the 'propulsion'/'propulsion' user/password
     * used for the Postgres/MySQL containers -- just non-trivial enough to satisfy
     * both images' own minimum password-complexity requirements.
     */
    private const MSSQL_SA_PASSWORD = 'Pr0pulsion!Test';
    private const ORACLE_SYSTEM_PASSWORD = 'Pr0pulsion!Test';

    /** Dedicated non-privileged user created in the Oracle container post-start (see
     * ensureContainerStarted()) -- mirrors the 'propulsion'/'propulsion' user the
     * Postgres/MySQL containers are given directly via withPostgresUser()/
     * withMySQLUser(); Oracle has no equivalent GenericContainer-level convenience,
     * so this project creates it explicitly with the image's own createAppUser
     * script instead.
     */
    private const ORACLE_APP_USER = 'propulsion';
    private const ORACLE_APP_PASSWORD = 'propulsion';

    private static function platform(): string
    {
        $platform = getenv('PROPULSION_TEST_DB');
        return in_array($platform, ['mysql', 'mariadb', 'mssql', 'oracle'], true) ? $platform : 'pgsql';
    }

    /**
     * The generator/runtime adapter name for currentPlatform() -- identical to
     * platform() except for MariaDB, which maps to plain 'mysql': there is no
     * separate MariadbPlatform (generator side) or DBMariadb (runtime side)
     * anywhere in this codebase -- DBMySQL itself detects MariaDB vs. real MySQL
     * at connection time (DBMySQL::isMariaDb()) and only the generator's
     * naming-convention platform class lookup
     * ("platform.${propulsion.database}Platform") and the runtime's datasource
     * 'adapter' key need steering back to the class that actually exists.
     *
     * Public because tests that build their own datasource config need it too:
     * an `'adapter' => currentPlatform()` there resolves to a `MariadbPlatform`
     * that does not exist. Use `currentPlatform()` to decide *whether* a test
     * applies, and this to name the adapter/platform it should actually load.
     * Note this is not the same mapping as `pdoDriverPrefix()`, which answers a
     * different question (mssql -> dblib, oracle -> oci); the platform classes
     * for those two do exist under their own names.
     */
    public static function generatorPlatform(): string
    {
        return self::platform() === 'mariadb' ? 'mysql' : self::platform();
    }

    /**
     * Public accessor for tests that need to skip themselves on a platform
     * they don't apply to (e.g. a test exercising MySQL's loose numeric
     * coercion in WHERE clauses, which Postgres correctly rejects instead).
     */
    public static function currentPlatform(): string
    {
        return self::platform();
    }

    /**
     * The PDO driver prefix for currentPlatform() -- identical to the platform
     * name for every platform except MSSQL, whose PDO driver is "dblib"
     * (FreeTDS), not a literal "mssql" driver (which doesn't exist); Oracle,
     * whose PDO driver is "oci" (pdo_oci), not a literal "oracle" driver (which
     * likewise doesn't exist); and MariaDB, served by the same pdo_mysql driver
     * as real MySQL (there is no separate "pdo_mariadb"). Several generator
     * command/manager integration tests build their own raw PDO DSN from
     * currentPlatform() directly against the shared testcontainer; they need
     * this instead so "new PDO(...)" doesn't fail with "could not find driver"
     * under PROPULSION_TEST_DB=mssql/oracle/mariadb.
     */
    public static function pdoDriverPrefix(): string
    {
        return match (self::platform()) {
            'mssql' => 'dblib',
            'oracle' => 'oci',
            'mariadb' => 'mysql',
            default => self::platform(),
        };
    }

    /**
     * Builds a raw PDO DSN for the given host/port/database against
     * currentPlatform(), for tests that construct their own PDO connection to
     * the shared testcontainer instead of going through Propulsion's own
     * connection management. dblib (MSSQL) is the one platform here that
     * doesn't take host and port as separate DSN attributes -- it wants
     * "host=host:port" combined, unlike pgsql/mysql's "host=host;port=port".
     * oci (Oracle) doesn't take them as separate attributes either -- it wants
     * an Easy Connect string embedded in "dbname=", same format
     * loadFixtureData() already uses to connect to the shared container itself.
     * $dbname itself is ignored for Oracle: unlike Postgres/MySQL/MSSQL (each of
     * which can host any number of independently-named databases), the
     * oracle-free image's PDO Easy Connect target is the single "FREEPDB1"
     * pluggable database it ships -- callers wanting a Postgres/MySQL/MSSQL-style
     * separate scratch database have no Oracle equivalent to ask for one.
     */
    public static function pdoDsn(string $host, int $port, string $dbname): string
    {
        return match (self::platform()) {
            'mssql' => "dblib:host=$host:$port;dbname=$dbname;charset=UTF8",
            'oracle' => "oci:dbname=//$host:$port/FREEPDB1;charset=UTF8",
            default => self::pdoDriverPrefix() . ":host=$host;port=$port;dbname=$dbname",
        };
    }

    /**
     * The user/password to connect to the shared testcontainer with, for
     * tests that build their own raw PDO connection instead of going through
     * Propulsion's connection management. Every platform except MSSQL gets a
     * dedicated 'propulsion'/'propulsion' app user; MSSQL only ever gets the
     * container's own 'sa' superuser (see loadFixtureData()).
     *
     * @return array{0: string, 1: string} [$user, $password]
     */
    public static function pdoCredentials(): array
    {
        return self::platform() === 'mssql' ? ['sa', self::MSSQL_SA_PASSWORD] : ['propulsion', 'propulsion'];
    }

    private static bool $namespacedAttempted = false;
    private static ?string $namespacedSkipReason = null;

    private static bool $schemasAttempted = false;
    private static ?string $schemasSkipReason = null;

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
    /**
     * Exposes the shared testcontainer's host/port (starting it via ensureReady()'s
     * Postgres-only path if nothing has started one yet) for tests that need to spin
     * up their own scratch database in it -- e.g. generator Task-class integration
     * tests that reverse-engineer or migrate a small hand-built schema rather than
     * one of the pre-built fixture projects. Only meaningful when currentPlatform()
     * is 'pgsql' (the default); throws the same skip-reason RuntimeException as
     * ensureReady() if Docker/Postgres aren't usable here.
     *
     * @return array{host: string, port: int}
     */
    public static function containerConnection(): array
    {
        self::ensureContainerStarted();

        return [
            'host' => self::requireContainer()->getHost(),
            'port' => self::hostPort(),
        ];
    }

    /**
     * The exception to raise when the bookstore tier cannot be brought up.
     *
     * Normally a `\RuntimeException`, which every test base catches and turns into
     * `markTestSkipped()` -- the right behaviour on a developer machine with no
     * Docker. In CI that is actively harmful: a job whose entire purpose is to run
     * the suite against MSSQL reports a green "OK, but some tests were skipped!"
     * having run none of it, and the breakage is invisible until someone reads the
     * skip count. Both the MSSQL and Oracle jobs sat broken that way.
     *
     * With `PROPULSION_REQUIRE_INTEGRATION=1` this returns an `\Error` instead,
     * which the `catch (\RuntimeException)` in the test bases does not catch, so the
     * run goes red at the first affected test with the real reason attached.
     *
     * Deliberately scoped to this tier only. The namespaced and schemas fixture
     * projects are Postgres-specific by design and are *supposed* to skip under
     * every other `PROPULSION_TEST_DB` value; failing on those would make the
     * MySQL/MSSQL/Oracle jobs permanently red for no reason.
     */
    private static function unavailable(string $message): \Throwable
    {
        if (getenv('PROPULSION_REQUIRE_INTEGRATION') && !getenv('PROPULSION_SKIP_INTEGRATION')) {
            return new \Error(
                'PROPULSION_REQUIRE_INTEGRATION is set, so an unavailable integration '
                . 'database is a failure rather than a skip: ' . $message
            );
        }

        return new \RuntimeException($message);
    }

    public static function ensureReady(): void
    {
        if (self::$attempted) {
            if (self::$skipReason !== null) {
                throw self::unavailable(self::$skipReason);
            }
            return;
        }
        self::$attempted = true;

        // Generate the Object Model classes first, unconditionally -- this is pure
        // codegen (schema.xml -> PHP), it needs no database, Docker or otherwise. If
        // this alone fails, no point in even trying to start a container.
        try {
            self::ensureClassesGenerated();
        } catch (\Throwable $e) {
            self::$skipReason = $e->getMessage();
            throw self::unavailable(self::$skipReason);
        }

        try {
            self::ensureContainerStarted();
        } catch (\Throwable $e) {
            self::$skipReason = $e->getMessage();
            throw self::unavailable(self::$skipReason);
        }

        try {
            self::loadFixtureData(self::requireContainer()->getHost(), self::hostPort());
        } catch (\Throwable $e) {
            self::$skipReason = 'Could not build bookstore fixtures: ' . $e->getMessage();
            throw self::unavailable(self::$skipReason);
        }
    }

    /**
     * Generates the Bookstore Object Model classes (and their DDL, though nothing
     * loads it anywhere here) via the real generator classes -- the same ones
     * bin/propulsion uses, no shelling out -- without starting any database, container
     * or otherwise. Idempotent; safe to call from bootstrap.php unconditionally and
     * again (as a no-op) from ensureReady().
     *
     * Tests that only exercise generated code shape/object-model behavior (e.g.
     * TableBehaviorTest, OMBuilderTest, FieldnameRelatedTest) or build SQL strings
     * in memory against a non-live adapter (e.g. CriteriaTest, which swaps in
     * DBSQLite()/DBMySQL() purely to select dialect quoting, never opening a
     * connection) can depend on this alone and run identically with or without
     * Docker -- they were only ever forced through the Docker-gated ensureReady()
     * because that was, historically, the only thing that generated these classes
     * at all.
     *
     * @throws \RuntimeException With a message suitable for markTestSkipped() if
     *         codegen itself fails (e.g. a broken schema.xml) -- this should be rare
     *         and unrelated to Docker availability.
     */
    public static function ensureClassesGenerated(): void
    {
        if (self::$classesGenerated) {
            if (self::$classesGenerationError !== null) {
                throw new \RuntimeException(self::$classesGenerationError);
            }
            return;
        }
        self::$classesGenerated = true;

        try {
            self::generateFixtureClasses();
        } catch (\Throwable $e) {
            self::$classesGenerationError = 'Could not generate bookstore fixture classes: ' . $e->getMessage();
            throw new \RuntimeException(self::$classesGenerationError);
        }

        // Force Propulsion\Propulsion to load now, which eagerly registers its own
        // legacy-class-map aliases (BaseObject, TableMap, PropulsionException, ...).
        // Generated fixture classes (BaseTable4 extends BaseObject, etc.) need
        // those bare aliases to already exist the moment the classmap autoloader
        // below pulls them in -- which can happen as early as PHPUnit's test suite
        // discovery, well before anything else would have triggered Propulsion::init().
        class_exists(\Propulsion\Propulsion::class);

        self::registerClassmapAutoloader();
    }

    private static function generateFixtureClasses(): void
    {
        $fixtureDir = dirname(__DIR__, 2) . '/fixtures/bookstore';
        $repoRoot = dirname(__DIR__, 3);
        $classesDir = self::classesDir();
        $platform = self::platform();

        if (!is_dir($classesDir) && !mkdir($classesDir, 0777, true) && !is_dir($classesDir)) {
            throw new \RuntimeException("Unable to create $classesDir");
        }

        $config = GeneratorConfig::createFromPropertiesFile(
            $repoRoot . '/generator/default.php',
            [
                $fixtureDir . '/build.php',
                $fixtureDir . '/build.propulsion.php',
            ],
            ['propulsion.database' => self::generatorPlatform()]
        );

        $schemas = glob($fixtureDir . '/*schema.xml') ?: [];
        sort($schemas);

        $sqlDir = self::sqlDirFor($platform);
        if (!is_dir($sqlDir)) {
            mkdir($sqlDir, 0777, true);
        }

        // GeneratorConfig's legacy dot-notation behavior class resolution (e.g.
        // 'test.tools.helpers.bookstore.behavior.AddClassBehavior') is resolved
        // relative to the working directory -- anchor it to the repo root
        // regardless of where the PHPUnit process itself was launched from.
        $previousCwd = getcwd();
        if ($previousCwd === false) {
            throw new \RuntimeException('Unable to determine the current working directory.');
        }
        chdir($repoRoot);
        try {
            (new ModelManager($config, $classesDir))->generate($schemas);
            (new SqlManager($config, $sqlDir))->generate($schemas);
        } finally {
            chdir($previousCwd);
        }
    }

    private static function sqlDirFor(string $platform): string
    {
        return sys_get_temp_dir() . '/propulsion-test-sql-' . $platform;
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
     * declare a `namespace="..."` attribute, which the modern builders honor
     * regardless of targetPlatform -- this still passes `targetPlatform=php84`
     * explicitly only for parity with the fixture's own historical build.php,
     * not because it changes which builder class runs (there is only one
     * builder set left; see generator/default.php's "BUILDER SETTINGS"
     * section). Reuses the same running container as ensureReady() (starting
     * one if neither has run yet) but a separate database, since both fixture
     * projects define tables named book/author/publisher.
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
            self::buildNamespacedFixtures(self::requireContainer()->getHost(), self::hostPort());
        } catch (\Throwable $e) {
            self::$namespacedSkipReason = 'Could not build namespaced fixtures: ' . $e->getMessage();
            throw new \RuntimeException(self::$namespacedSkipReason);
        }

        class_exists(\Propulsion\Propulsion::class);
        self::registerClassmapAutoloader(self::namespacedClassesDir());
    }

    public static function schemasConfFile(): string
    {
        return dirname(__DIR__, 2) . '/fixtures/schemas/build/conf/bookstore-conf.php';
    }

    public static function schemasClassesDir(): string
    {
        return dirname(__DIR__, 2) . '/fixtures/schemas/build/classes';
    }

    /**
     * Same idea as ensureReady()/ensureNamespacedReady(), for the separate "schemas"
     * fixture project (test/fixtures/schemas/schema.xml) used by the *WithSchema(s)
     * tests: its tables use a `schema="..."` attribute (Propulsion's "multiple schemas in
     * one database" support) combined with `propulsion.schema.autoPrefix`, which bakes the
     * schema name into the generated PHP class/table names (e.g.
     * `BookstoreSchemasBookstore`, `ContestBookstoreContest`) rather than needing real
     * Postgres `CREATE SCHEMA`/`search_path` support -- so, like the bookstore fixtures,
     * this targets the default, flat/unnamespaced builder. Reuses the same
     * running container as ensureReady() (starting one if none has run yet) but a
     * separate database, since the schemas project's tables overlap in name with the
     * bookstore fixtures (book, bookstore, customer, ...).
     */
    public static function ensureSchemasReady(): void
    {
        if (self::$schemasAttempted) {
            if (self::$schemasSkipReason !== null) {
                throw new \RuntimeException(self::$schemasSkipReason);
            }
            return;
        }
        self::$schemasAttempted = true;

        try {
            self::ensureContainerStarted();
        } catch (\Throwable $e) {
            self::$schemasSkipReason = $e->getMessage();
            throw new \RuntimeException(self::$schemasSkipReason);
        }

        try {
            self::buildSchemasFixtures(self::requireContainer()->getHost(), self::hostPort());
        } catch (\Throwable $e) {
            self::$schemasSkipReason = 'Could not build schemas fixtures: ' . $e->getMessage();
            throw new \RuntimeException(self::$schemasSkipReason);
        }

        class_exists(\Propulsion\Propulsion::class);
        self::registerClassmapAutoloader(self::schemasClassesDir());
    }

    private static function buildSchemasFixtures(string $host, int $port): void
    {
        $fixtureDir = dirname(__DIR__, 2) . '/fixtures/schemas';
        $repoRoot = dirname(__DIR__, 3);
        $classesDir = self::schemasClassesDir();

        if (!is_dir($classesDir) && !mkdir($classesDir, 0777, true) && !is_dir($classesDir)) {
            throw new \RuntimeException("Unable to create $classesDir");
        }

        $config = GeneratorConfig::createFromPropertiesFile(
            $repoRoot . '/generator/default.php',
            [$fixtureDir . '/build.php'],
            ['propulsion.database' => 'pgsql']
        );

        $schemas = glob($fixtureDir . '/*schema.xml') ?: [];
        sort($schemas);

        $sqlDir = sys_get_temp_dir() . '/propulsion-test-sql-schemas';
        if (!is_dir($sqlDir)) {
            mkdir($sqlDir, 0777, true);
        }

        $previousCwd = getcwd();
        if ($previousCwd === false) {
            throw new \RuntimeException('Unable to determine the current working directory.');
        }
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
        if (!self::queryScalar($admin, "SELECT 1 FROM pg_database WHERE datname = 'propulsion_test_schemas'")) {
            $admin->exec('CREATE DATABASE propulsion_test_schemas');
        }

        $dsn = "pgsql:host=$host;port=$port;dbname=propulsion_test_schemas";
        $pdo = new \PDO($dsn, 'propulsion', 'propulsion');
        $pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);

        // The schemas fixture's tables use a `schema="..."` attribute (Propulsion's
        // "multiple schemas in one database" support). PgsqlPlatform::getAddSchemasDDL()
        // now emits `CREATE SCHEMA` for these directly (see the fixed gap in
        // KNOWN_ISSUES.md's Postgres-parity entry -- it used to only do this for the
        // legacy `<vendor type="pgsql">` schema convention, not this fixture's own
        // `schema="..."` attribute), so the generated SQL below creates the schemas
        // itself; no separate pre-creation step is needed here anymore.
        foreach (glob($sqlDir . '/*.sql') ?: [] as $sqlFile) {
            $pdo->exec((string) file_get_contents($sqlFile));
        }

        self::writeSchemasRuntimeConf($dsn);
    }

    private static function writeSchemasRuntimeConf(string $dsn): void
    {
        // The schema.xml's <database name="bookstore-schemas"> becomes the generated
        // classes' DATABASE_NAME constant, so the datasource key needs to match exactly.
        $config = [
            'datasources' => [
                'default' => 'bookstore-schemas',
                'bookstore-schemas' => [
                    'adapter' => 'pgsql',
                    'connection' => [
                        'dsn' => $dsn,
                        'user' => 'propulsion',
                        'password' => 'propulsion',
                        'classname' => 'Propulsion\\Adapter\\Pgsql\\PgsqlDebugPDO', // these two fixtures are always Postgres, see this method's own docblock
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

        $confDir = dirname(self::schemasConfFile());
        if (!is_dir($confDir) && !mkdir($confDir, 0777, true) && !is_dir($confDir)) {
            throw new \RuntimeException("Unable to create $confDir");
        }

        file_put_contents(self::schemasConfFile(), "<?php\nreturn " . var_export($config, true) . ";\n");
    }

    /**
     * The host port the shared testcontainer is reachable on, resolved once.
     *
     * Deliberately not `StartedGenericContainer::getFirstMappedPort()`. That reads
     * `NetworkSettings.Ports` out of an inspect response it caches forever (see its
     * own "TODO: ... shouldn't be cached" comment), so if the first call lands
     * before Docker has populated that field, every later call fails identically
     * rather than transiently.
     *
     * Waiting for the field to populate does not work either: on GitHub Actions the
     * MSSQL and Oracle containers sit at `status=running`, having already logged that
     * they are accepting connections, with `NetworkSettings.Ports` still carrying no
     * host binding after 60s. Whatever the cause, that field is not a reliable signal
     * there.
     *
     * `HostConfig.PortBindings` is, because it is not something Docker reports back --
     * it is what testcontainers *asked for*. `GenericContainer::createPortBindings()`
     * picks a concrete host port itself (`RandomPortGenerator`) and binds
     * `0.0.0.0:<port>`, so the number is settled before the container is even created.
     * `NetworkSettings.Ports` is still preferred when present, being the authoritative
     * record of what Docker actually published; the request is the fallback.
     *
     * Being wrong here is self-correcting: the caller immediately opens a real
     * connection on the port this returns, so a mapping that does not exist surfaces
     * as a connection failure naming the port, rather than as an opaque wait timeout.
     */
    private static ?int $hostPort = null;

    private static function hostPort(): int
    {
        return self::$hostPort ??= self::resolveHostPort(self::requireContainer());
    }

    private static function resolveHostPort(StartedGenericContainer $container): int
    {
        $budget = (int) (getenv('PROPULSION_PORT_PUBLISH_TIMEOUT') ?: 30);
        $deadline = microtime(true) + $budget;
        $requested = null;
        $lastState = 'unknown';

        do {
            $response = $container->getClient()->containerInspect($container->getId());
            if ($response instanceof \Docker\API\Model\ContainersIdJsonGetResponse200) {
                // A container that has exited will never publish anything. Say so,
                // with the exit code and OOM flag, instead of waiting out the budget
                // and blaming Docker -- Oracle Free in particular is heavy enough to
                // be killed on a constrained runner.
                $state = $response->getState();
                if ($state !== null) {
                    $lastState = $state->getStatus() ?? 'unknown';
                    if ($state->getRunning() !== true && $state->getRestarting() !== true) {
                        throw new \RuntimeException(sprintf(
                            'Container %s is not running (status=%s, exitCode=%s, oomKilled=%s%s).',
                            $container->getId(),
                            $lastState,
                            $state->getExitCode() ?? '?',
                            $state->getOOMKilled() ? 'yes' : 'no',
                            ($state->getError() ?? '') !== '' ? ', error=' . $state->getError() : ''
                        ));
                    }
                }

                $published = self::firstHostPort($response->getNetworkSettings()?->getPorts());
                if ($published !== null) {
                    return $published;
                }

                $requested ??= self::firstHostPort($response->getHostConfig()?->getPortBindings());
            }
            usleep(250_000);
        } while (microtime(true) < $deadline);

        if ($requested !== null) {
            fwrite(STDERR, sprintf(
                "\nNote: Docker reported no published port for container %s within %ds (status=%s); "
                . "using the host port testcontainers requested (%d) instead.\n",
                $container->getId(),
                $budget,
                $lastState,
                $requested
            ));

            return $requested;
        }

        throw new \RuntimeException(sprintf(
            'Container %s has neither a published port nor a requested host port binding '
            . 'after %ds (status=%s).',
            $container->getId(),
            $budget,
            $lastState
        ));
    }

    /**
     * First `HostPort` in a Docker port map, whether that map came from
     * `NetworkSettings.Ports` (what Docker published) or `HostConfig.PortBindings`
     * (what was requested). Both use the same
     * `"<port>/<proto>" => [PortBinding, ...]` shape; an entry whose value is null or
     * whose binding carries no host port means "not bound", which is exactly the case
     * a bare `count($ports) > 0` check used to mistake for success.
     *
     * @param iterable<string, mixed>|null $ports
     */
    private static function firstHostPort(?iterable $ports): ?int
    {
        foreach ((array) ($ports ?? []) as $bindings) {
            $binding = ((array) $bindings)[0] ?? null;
            if ($binding instanceof \Docker\API\Model\PortBinding) {
                $hostPort = $binding->getHostPort();
                if ($hostPort !== null && $hostPort !== '' && (int) $hostPort > 0) {
                    return (int) $hostPort;
                }
            }
        }

        return null;
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

        $platform = self::platform();

        if ($platform === 'mysql') {
            try {
                self::$container = (new MySQLContainer())
                    ->withMySQLUser('propulsion', 'propulsion')
                    ->withMySQLDatabase('propulsion_test')
                    ->withLabels(self::CONTAINER_LABELS)
                    ->start();
                self::enableMysqlLocalInfile(self::$container->getHost(), self::hostPort());
            } catch (\Throwable $e) {
                throw new \RuntimeException('Could not start the MySQL testcontainer (is Docker running?): ' . $e->getMessage());
            }
        } elseif ($platform === 'mariadb') {
            try {
                // No MariadbContainer module ships in testcontainers/testcontainers --
                // built directly on GenericContainer instead, mirroring
                // MySQLContainer's own construction: the official mariadb image is a
                // drop-in-compatible server that accepts the exact same
                // MYSQL_ROOT_PASSWORD/MYSQL_USER/MYSQL_PASSWORD/MYSQL_DATABASE
                // environment variables MySQLContainer uses -- but its healthcheck
                // binary is "mariadb-admin", not "mysqladmin" (confirmed absent from
                // $PATH in the mariadb:11 image; MySQLContainer's own WaitForExec
                // relies on the latter, which is why this can't just reuse it).
                // Version pinned to 11.x (well past the 10.5 RETURNING support floor
                // DBMySQL::isMariaDb() gates on).
                self::$container = (new GenericContainer('mariadb:11'))
                    ->withExposedPorts(3306)
                    ->withEnvironment([
                        'MYSQL_ROOT_PASSWORD' => 'root',
                        'MYSQL_USER' => 'propulsion',
                        'MYSQL_PASSWORD' => 'propulsion',
                        'MYSQL_DATABASE' => 'propulsion_test',
                    ])
                    ->withWait(new \Testcontainers\Wait\WaitForExec(['mariadb-admin', 'ping', '-h', '127.0.0.1'], null, 15000))
                    ->withLabels(self::CONTAINER_LABELS)
                    ->start();
                self::enableMysqlLocalInfile(self::$container->getHost(), self::hostPort());
            } catch (\Throwable $e) {
                throw new \RuntimeException('Could not start the MariaDB testcontainer (is Docker running?): ' . $e->getMessage());
            }
        } elseif ($platform === 'mssql') {
            try {
                self::$container = (new GenericContainer('mcr.microsoft.com/azure-sql-edge:latest'))
                    ->withEnvironment([
                        'ACCEPT_EULA' => '1',
                        'MSSQL_SA_PASSWORD' => self::MSSQL_SA_PASSWORD,
                    ])
                    ->withExposedPorts(1433)
                    ->withWait(new WaitForLog('SQL Server is now ready for client connections', timeout: 60000))
                    ->withLabels(self::CONTAINER_LABELS)
                    ->start();
            } catch (\Throwable $e) {
                throw new \RuntimeException('Could not start the MSSQL (azure-sql-edge) testcontainer (is Docker running?): ' . $e->getMessage());
            }

            try {
                self::createMssqlDatabase(self::requireContainer()->getHost(), self::hostPort());
            } catch (\Throwable $e) {
                throw new \RuntimeException('Could not create the MSSQL testcontainer\'s propulsion_test database: ' . $e->getMessage());
            }
        } elseif ($platform === 'oracle') {
            try {
                self::$container = (new GenericContainer('gvenzl/oracle-free:23-slim'))
                    ->withEnvironment(['ORACLE_PASSWORD' => self::ORACLE_SYSTEM_PASSWORD])
                    ->withExposedPorts(1521)
                    // Oracle Free's first-ever startup uncompresses seed datafiles before
                    // it opens the listener -- much slower than Postgres/MySQL/MSSQL's
                    // first start, easily 30s+ even on a fast host.
                    ->withWait(new WaitForLog('DATABASE IS READY TO USE', timeout: 180000))
                    ->withLabels(self::CONTAINER_LABELS)
                    ->start();
            } catch (\Throwable $e) {
                throw new \RuntimeException('Could not start the Oracle (oracle-free) testcontainer (is Docker running?): ' . $e->getMessage());
            }

            try {
                self::createOracleAppUser(self::requireContainer());
            } catch (\Throwable $e) {
                throw new \RuntimeException('Could not create the Oracle testcontainer\'s propulsion app user: ' . $e->getMessage());
            }
        } else {
            try {
                self::$container = (new PostgresContainer())
                    ->withPostgresUser('propulsion')
                    ->withPostgresPassword('propulsion')
                    ->withPostgresDatabase('propulsion_test')
                    ->withLabels(self::CONTAINER_LABELS)
                    ->start();
            } catch (\Throwable $e) {
                throw new \RuntimeException('Could not start the Postgres testcontainer (is Docker running?): ' . $e->getMessage());
            }
        }

        register_shutdown_function(static function () {
            self::$container?->stop();
        });
    }

    /**
     * MySQL 8+ ships with the server-side `local_infile` global variable OFF by
     * default -- LOAD DATA LOCAL INFILE (DBMySQL::bulkLoad()'s mechanism) refuses to
     * run at all otherwise, regardless of the client-side PDO::MYSQL_ATTR_LOCAL_INFILE
     * setting writeRuntimeConf() also needs to set. MySQLContainer's constructor
     * always sets MYSQL_ROOT_PASSWORD to 'root' (root/root), so connect as that to
     * flip it -- the 'propulsion' user created by withMySQLUser() doesn't have the
     * SYSTEM_VARIABLES_ADMIN/SUPER privilege SET GLOBAL requires.
     */
    private static function enableMysqlLocalInfile(string $host, int $port): void
    {
        $pdo = new \PDO("mysql:host=$host;port=$port", 'root', 'root');
        $pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        $pdo->exec('SET GLOBAL local_infile = 1');
    }

    /**
     * Azure SQL Edge only ships a `master` database out of the box -- unlike
     * MySQLContainer::withMySQLDatabase()/PostgresContainer::withPostgresDatabase(),
     * there's no GenericContainer-level convenience for this, so create it the same
     * way a human would: connect as sa and run CREATE DATABASE.
     */
    private static function createMssqlDatabase(string $host, int $port): void
    {
        $pdo = self::connectMssqlWithRetry("dblib:host=$host:$port;charset=UTF8", 'sa', self::MSSQL_SA_PASSWORD);
        $exists = self::queryScalar($pdo, "SELECT 1 FROM sys.databases WHERE name = 'propulsion_test'");
        if (!$exists) {
            $pdo->exec('CREATE DATABASE propulsion_test');
        }
    }

    /**
     * WaitForLog resolves the instant Azure SQL Edge's log emits "SQL Server is
     * now ready for client connections" -- observed, in practice, to occasionally
     * still be a few hundred ms ahead of the SA login actually being accepted
     * (a real, reproducible race, not theoretical), so the very first connection
     * attempt right after start() can fail with "Adaptive Server connection
     * failed". A handful of short retries absorbs that gap without needing a
     * more elaborate readiness probe.
     */
    private static function connectMssqlWithRetry(string $dsn, string $user, string $password): \PDO
    {
        $lastError = null;
        for ($attempt = 0; $attempt < 5; $attempt++) {
            try {
                $pdo = new \PDO($dsn, $user, $password);
                $pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
                return $pdo;
            } catch (\PDOException $e) {
                $lastError = $e;
                usleep(500_000);
            }
        }
        throw $lastError;
    }

    /**
     * gvenzl/oracle-free only exposes sys/system (via ORACLE_PASSWORD); the image's
     * own createAppUser script is the documented way to provision an additional,
     * non-privileged user in the default FREEPDB1 pluggable database -- mirroring
     * the dedicated 'propulsion'/'propulsion' user the Postgres/MySQL containers get
     * directly through their own withPostgresUser()/withMySQLUser() calls. Runs via
     * the Docker exec API (StartedGenericContainer::exec()), not a shelled-out
     * `docker exec`, consistent with this class only ever driving containers through
     * testcontainers-php.
     */
    private static function createOracleAppUser(StartedGenericContainer $container): void
    {
        $output = $container->exec(['/opt/oracle/createAppUser', self::ORACLE_APP_USER, self::ORACLE_APP_PASSWORD]);
        if (stripos($output, 'ORA-') !== false) {
            throw new \RuntimeException("createAppUser failed: $output");
        }
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
            $repoRoot . '/generator/default.php',
            [$fixtureDir . '/build.php'],
            ['propulsion.database' => 'pgsql', 'propulsion.targetPlatform' => 'php84']
        );

        $schemas = glob($fixtureDir . '/*schema.xml') ?: [];
        sort($schemas);

        $sqlDir = sys_get_temp_dir() . '/propulsion-test-sql-namespaced';
        if (!is_dir($sqlDir)) {
            mkdir($sqlDir, 0777, true);
        }

        $previousCwd = getcwd();
        if ($previousCwd === false) {
            throw new \RuntimeException('Unable to determine the current working directory.');
        }
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
        if (!self::queryScalar($admin, "SELECT 1 FROM pg_database WHERE datname = 'propulsion_test_namespaced'")) {
            $admin->exec('CREATE DATABASE propulsion_test_namespaced');
        }

        $dsn = "pgsql:host=$host;port=$port;dbname=propulsion_test_namespaced";
        $pdo = new \PDO($dsn, 'propulsion', 'propulsion');
        $pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        foreach (glob($sqlDir . '/*.sql') ?: [] as $sqlFile) {
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
                        'classname' => 'Propulsion\\Adapter\\Pgsql\\PgsqlDebugPDO', // this fixture is always Postgres, see this method's own docblock
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
     * The bookstore fixtures are generated with the default, flat/unnamespaced
     * builder (BookPeer, Author, etc.), which composer's PSR-4 autoloading can't
     * find; the namespaced fixtures use real `namespace Foo\Bar;` declarations, but
     * not in a PSR-4-compatible directory layout composer could map either.
     * Historically this relied on a plain "search the include path" autoloader that
     * PHP dropped along with __autoload(); build an equivalent classmap here instead,
     * scanning each generated file's actual namespace + class declaration once
     * (not just its filename, so this works for both flat and namespaced output).
     */
    private static function registerClassmapAutoloader(?string $classesDir = null): void
    {
        // The default (bookstore) classmap can now be registered from two call paths
        // that may both run in the same process -- ensureClassesGenerated() (always)
        // and ensureReady() (previously the only caller) -- guard against scanning and
        // registering it twice. The namespaced/schemas variants each pass their own
        // explicit $classesDir and are already only ever called once per fixture
        // project (see ensureNamespacedReady()/ensureSchemasReady()'s own $attempted
        // guards), so they don't need this.
        if ($classesDir === null) {
            if (self::$classmapRegistered) {
                return;
            }
            self::$classmapRegistered = true;
        }

        $classmap = [];
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($classesDir ?? self::classesDir(), \FilesystemIterator::SKIP_DOTS)
        );
        foreach ($iterator as $file) {
            if (!$file instanceof \SplFileInfo || $file->getExtension() !== 'php') {
                continue;
            }
            $source = file_get_contents($file->getPathname());
            if ($source === false) {
                continue;
            }
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
        if (getenv('DOCKER_CONFIG') === false) {
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

        // testcontainers/testcontainers's own registry-auth lookup (used whenever an
        // image isn't already cached locally and needs a real pull, e.g. the first time
        // this environment pulls mysql:latest rather than the already-cached
        // postgres:latest) reads DOCKER_AUTH_CONFIG or ~/.docker/config.json directly --
        // it does not consult the Docker CLI's own DOCKER_CONFIG variable the workaround
        // above targets, so a broken credsStore there still breaks a fresh pull even with
        // that override in place. Short-circuit it the same way, with an explicit empty
        // auth config so it never invokes the (unusable, from this shell) credential
        // helper binary at all.
        if (getenv('DOCKER_AUTH_CONFIG') === false) {
            putenv('DOCKER_AUTH_CONFIG={}');
        }
    }

    /**
     * The database-dependent half of what ensureReady() used to do as buildFixtures():
     * loads the already-generated DDL (see generateFixtureClasses()/
     * ensureClassesGenerated(), always run first) into the live container and points
     * the runtime config at it. Requires a running container -- callers must have
     * already gone through ensureContainerStarted().
     */
    private static function loadFixtureData(string $host, int $port): void
    {
        $platform = self::platform();
        $sqlDir = self::sqlDirFor($platform);

        [$dsn, $user, $password] = match ($platform) {
            'mysql', 'mariadb' => ["mysql:host=$host;port=$port;dbname=propulsion_test", 'propulsion', 'propulsion'],
            'mssql' => ["dblib:host=$host:$port;dbname=propulsion_test;charset=UTF8", 'sa', self::MSSQL_SA_PASSWORD],
            'oracle' => ["oci:dbname=//$host:$port/FREEPDB1;charset=UTF8", self::ORACLE_APP_USER, self::ORACLE_APP_PASSWORD],
            default => ["pgsql:host=$host;port=$port;dbname=propulsion_test", 'propulsion', 'propulsion'],
        };
        $pdo = $platform === 'mssql'
            ? self::connectMssqlWithRetry($dsn, $user, $password)
            : new \PDO($dsn, $user, $password);
        $pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        foreach (glob($sqlDir . '/*.sql') ?: [] as $sqlFile) {
            self::execSqlFile($pdo, $sqlFile, $platform);
        }

        self::writeRuntimeConf($dsn, self::generatorPlatform(), $user, $password);
    }

    /**
     * Pgsql/MySQL's PDO drivers tolerate a whole file of semicolon-separated DDL
     * statements in a single exec() call, and Pgsql's own DROP TABLE DDL is
     * self-guarded (IF EXISTS) so it's a no-op against an always-fresh
     * testcontainer database -- which is why this could just be one
     * `$pdo->exec(file_get_contents(...))` call before Oracle/MSSQL support
     * existed.
     *
     * MSSQL and Oracle both need statement-by-statement execution, for two
     * different reasons:
     *   - Oracle: OCI executes exactly one statement per call (no multi-statement
     *     batches at all), and OraclePlatform's DROP TABLE/DROP SEQUENCE DDL isn't
     *     self-guarded, so it fails outright (ORA-00942/ORA-02289) against a
     *     database that never had the table/sequence to begin with -- skip DROPs
     *     entirely, there's nothing to drop on a fresh container.
     *   - MSSQL: pdo_dblib (FreeTDS) *does* accept a whole multi-statement batch
     *     in one exec() call, but reproducibly leaves the connection in a
     *     "results pending" state afterward (SQLSTATE HY000/20019, "Attempt to
     *     initiate a new Adaptive Server operation with results pending") once
     *     that batch is large/varied enough -- confirmed by executing the real
     *     multi-table bookstore fixture's generated SQL against a live
     *     azure-sql-edge container: a single whole-file exec() of bookstore.sql
     *     succeeds, but the *next* file's exec() on the same connection then
     *     fails with exactly this error. Splitting into individual statements
     *     avoids ever putting more than one command in flight on the connection.
     *     MssqlPlatform's DROP TABLE DDL is self-guarded like Pgsql's, so DROPs
     *     don't need skipping here.
     *
     * Both reuse the same PropulsionSQLParser::parseString() call
     * PropulsionQuickBuilder::buildSQL() already uses for the identical reason.
     */
    private static function execSqlFile(\PDO $pdo, string $sqlFile, string $platform): void
    {
        $sql = (string) file_get_contents($sqlFile);
        if ($platform !== 'oracle' && $platform !== 'mssql') {
            $pdo->exec($sql);
            return;
        }

        foreach (PropulsionSQLParser::parseString($sql) as $statement) {
            $statement = trim($statement);
            if ($statement === '') {
                continue;
            }
            if ($platform === 'oracle' && stripos($statement, 'DROP') === 0) {
                continue;
            }
            $pdo->exec($statement);
        }
    }

    private static function writeRuntimeConf(string $dsn, string $platform = 'pgsql', string $user = 'propulsion', string $password = 'propulsion'): void
    {
        $datasource = [
            'adapter' => $platform,
            'connection' => [
                'dsn' => $dsn,
                'user' => $user,
                'password' => $password,
                // Driver-specific *DebugPDO (each extends the matching driver-specific
                // PropulsionPDO -- see that class's own docblock for why: keeps
                // driver-specific PDO methods like \Pdo\Pgsql::copyFromArray() reachable,
                // instead of only the ones PDO::pgsql*()-style bolted-on methods expose).
                // dblib (FreeTDS/MSSQL) additionally doesn't support native PDO
                // transactions at all -- see MssqlPropulsionPDO's own docblock for the
                // commit/rollback/lastInsertId workaround that class provides on top.
                'classname' => match ($platform) {
                    'mssql' => 'Propulsion\\Adapter\\MSSQL\\MssqlDebugPDO',
                    'oracle' => 'Propulsion\\Adapter\\Oracle\\OracleDebugPDO',
                    'mysql', 'mariadb' => 'Propulsion\\Adapter\\Mysql\\MysqlDebugPDO',
                    'sqlite' => 'Propulsion\\Adapter\\Sqlite\\SqliteDebugPDO',
                    default => 'Propulsion\\Adapter\\Pgsql\\PgsqlDebugPDO',
                },
                // Fail fast instead of hanging the whole suite: a test that opens a
                // second connection/transaction against a row the first one is still
                // holding (uncommitted) should error out in a few seconds, not block
                // forever. Surfaced by a real deadlock during AggregateColumnBehaviorTest.
                // MySQL's equivalent is a session variable, not a SET-per-statement
                // pragma, and its deadlock detector is on by default regardless.
                // No MSSQL/Oracle-specific timeout tuning yet -- both already have
                // deadlock detection on by default; revisit if a test needs it.
                'settings' => match ($platform) {
                    'mysql' => ['queries' => ['SET SESSION innodb_lock_wait_timeout = 5']],
                    'pgsql' => ['queries' => ['SET lock_timeout = 5000', 'SET statement_timeout = 15000']],
                    default => ['queries' => []],
                },
                // Client-side half of DBMySQL::bulkLoad()'s LOAD DATA LOCAL INFILE
                // requirement -- can't be toggled after connecting, so this has to be a
                // constructor-time PDO option, not a post-connect SET. The server-side
                // half (the `local_infile` global variable) is set once per-container in
                // enableMysqlLocalInfile() above, since it's a server, not a connection,
                // setting.
                'options' => match ($platform) {
                    'mysql' => ['Pdo\Mysql::ATTR_LOCAL_INFILE' => ['value' => true]],
                    default => [],
                },
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
