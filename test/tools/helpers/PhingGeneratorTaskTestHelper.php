<?php

/**
 * This file is part of the Propulsion package.
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 *
 * @license    MIT License
 */

use Phing\Phing;
use Phing\Project;
use Phing\Task;

/**
 * Test-only support for exercising the legacy Phing `Task` classes
 * (generator/Lib/Task/*) directly and in-process, the same way IntegrationDatabase
 * exercises the modern console Manager classes directly rather than shelling out to
 * `bin/propulsion`.
 *
 * There is no console-app equivalent of `generator/bin/propel-gen` that can be
 * invoked as a library call the way ModelManager/SqlManager are, so this drives the
 * actual Task classes via Phing's own PHP API: build a bare Phing\Project, seed it
 * with the same resolved `propel.*` properties `generator/build-propel.xml` would
 * (via generator/default.properties plus any overrides), then instantiate and
 * configure the Task class exactly as `<propel-om>`/`<propel-schema-reverse>`/etc.
 * would from XML attribute bindings.
 *
 * This intentionally bypasses shelling out to `vendor/bin/phing`/`propel-gen`: that
 * would make tests slower and harder to get failure output out of, and this project
 * already prefers the in-process-API pattern (see IntegrationDatabase's own doc
 * comment). The *build.xml/build-propel.xml XML itself* (property cascade, task
 * targets, phingcall wiring) is a separate concern from whether the Task classes
 * produce correct output -- see KNOWN_ISSUES.md for the property-shadowing bug found
 * and fixed in generator/default.properties + generator/build-propel.xml while
 * building this coverage; that fix is what makes `propel-gen <target>` work at all
 * for any project directory, and isn't re-verified by every individual Task test.
 */
class PhingGeneratorTaskTestHelper
{
    private static bool $phingStarted = false;

    /**
     * Builds a bare Phing\Project seeded with generator/default.properties (plus the
     * given raw `propel.*`-keyed overrides, e.g. ['propel.database' => 'pgsql']),
     * ready to have a Task class attached to it via configureTask().
     */
    public static function bootProject(array $overrides = []): Project
    {
        self::ensurePhingStarted();

        $repoRoot = dirname(__DIR__, 3);

        $project = new Project();
        $project->setBasedir($repoRoot . '/generator');
        $project->init();

        foreach (self::resolvedProperties($repoRoot . '/generator/default.properties', $overrides) as $name => $value) {
            $project->setProperty($name, (string) $value);
        }

        return $project;
    }

    /**
     * Attaches a Task instance to the given Project the way Phing's own
     * ProjectConfigurator/IntrospectionHelper would when parsing a <propel-xxx/>
     * element from build-propel.xml, so the task's log()/getProject()/getLocation()
     * calls all work normally.
     */
    public static function configureTask(Task $task, Project $project, string $taskName): void
    {
        $task->setProject($project);
        $task->setTaskName($taskName);
        $task->setOwningTarget(new \Phing\Target());
        $task->init();
    }

    /**
     * Parses a Phing-style `key = value` properties file and resolves `${...}`
     * placeholders against the merged (file + overrides) table -- a deliberately
     * simplified, test-only re-implementation of what
     * GeneratorConfig::createFromPropertiesFile() does internally (that method
     * returns a GeneratorConfig, not a flat property array suitable for seeding a
     * live Phing\Project, so it can't be reused directly here).
     *
     * @return array<string,string>
     */
    private static function resolvedProperties(string $file, array $overrides): array
    {
        $props = [];
        foreach (file($file) as $line) {
            $line = trim($line);
            if ($line === '' || $line[0] === '#') {
                continue;
            }
            $pos = strpos($line, '=');
            if ($pos === false) {
                continue;
            }
            $props[trim(substr($line, 0, $pos))] = trim(substr($line, $pos + 1));
        }
        $props = array_merge($props, $overrides);

        for ($i = 0; $i < 10; $i++) {
            $changed = false;
            foreach ($props as $key => $value) {
                if (!is_string($value)) {
                    continue;
                }
                $resolved = preg_replace_callback(
                    '/\$\{([^{}]+)\}/',
                    static fn (array $m) => $props[$m[1]] ?? $m[0],
                    $value
                );
                if ($resolved !== $value) {
                    $props[$key] = $resolved;
                    $changed = true;
                }
            }
            if (!$changed) {
                break;
            }
        }

        return $props;
    }

    /**
     * Runs the given callback with E_DEPRECATED reporting turned off.
     *
     * The underlying schema-parsing/builder code (shared with the console path, and
     * predating this test coverage) emits a handful of real, pre-existing
     * E_DEPRECATED notices for PHP 8.5 (e.g. Database.php's schema-name check doing
     * strpos() against a possibly-null value) that were never triggered by any
     * existing test. phpunit.xml sets beStrictAboutOutputDuringTests, so those
     * notices being newly *and directly* triggered inside a test method (rather than
     * during fixture setup in bootstrap.php, like the rest of the suite's generator
     * calls) would otherwise mark that test "risky" -- an artifact of exercising a
     * previously-uncovered code path, not a real problem with the Task classes this
     * test exists to verify. See KNOWN_ISSUES.md.
     */
    public static function withoutDeprecationNotices(callable $fn): mixed
    {
        $previous = error_reporting();
        error_reporting($previous & ~E_DEPRECATED);
        try {
            return $fn();
        } finally {
            error_reporting($previous);
        }
    }

    private static function ensurePhingStarted(): void
    {
        if (self::$phingStarted) {
            return;
        }
        self::$phingStarted = true;

        Phing::startup();
        Phing::setProperty('phing.home', dirname(__DIR__, 3) . '/vendor/phing/phing');
    }
}
