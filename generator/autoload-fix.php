<?php

/**
 * Autoloader fix for Phing 3.x compatibility with namespaced Propel classes
 */

// Try to load Composer autoloader
$autoloadPaths = [
    __DIR__ . '/../../../vendor/autoload.php',
    __DIR__ . '/../../vendor/autoload.php', 
    __DIR__ . '/vendor/autoload.php',
];

foreach ($autoloadPaths as $autoloadPath) {
    if (file_exists($autoloadPath)) {
        require_once $autoloadPath;
        break;
    }
}

// Register custom autoloader for Propel Generator classes
spl_autoload_register(function($class) {
    // Normalize leading backslash (autoload may pass class names with or without it)
    $normalized = ltrim($class, '\\');

    // If it's a namespaced Propel Generator class, try to load from the bundled Lib/ tree
    if (strpos($normalized, 'Propel\\Generator\\') === 0) {
        $file = str_replace('Propel\\Generator\\', '', $normalized);
        $file = str_replace('\\', '/', $file) . '.php';
        $path = __DIR__ . '/Lib/' . $file;
        if (file_exists($path)) {
            require_once $path;
            return true;
        }

        // If requested class looks like a generator-local reference to an external class
        // (for example "Propel\Generator\Task\Project"), try to map common
        // external short names to their real classes and create an alias so legacy
        // code that uses unqualified names still works.
    $short = substr($normalized, strrpos($normalized, '\\') + 1);
        $externalMap = [
            'Project' => 'Phing\\Project',
            'PhingFile' => 'Phing\\File',
            'PDOException' => '\\PDOException',
            'PropelSQLParser' => 'Propel\\Generator\\Util\\PropelSQLParser',
        ];

        if (isset($externalMap[$short])) {
            $target = $externalMap[$short];
            // Try to ensure the target exists (let other autoloaders try)
            if (!class_exists($target, false) && !interface_exists($target, false)) {
                // attempt to autoload the target via other autoloaders
                class_exists($target);
                interface_exists($target);
            }

            if (class_exists($target, false) || interface_exists($target, false)) {
                // Create an alias so code referencing the class under the generator
                // namespace will resolve to the real external class.
                if (!class_exists($class, false) && !interface_exists($class, false)) {
                    class_alias($target, $class);
                    return true;
                }
            }
        }
    }

    // Support legacy dot-path classnames (e.g. "propel.generator.task.PropelMigrationTask")
    // Convert to a PSR-4 candidate and try to require the file from Lib/.
    if (strpos($class, '.') !== false) {
        $psr = implode('\\', array_map(function($p){
            // Preserve already-cased segments; ucfirst common lowercase segments
            return ctype_lower($p) ? ucfirst($p) : $p;
        }, explode('.', ltrim($class, '.'))));

        // Also try with initial segment capitalized (propel -> Propel)
        $parts = explode('\\', $psr);
        if (count($parts) > 0) {
            $parts[0] = ucfirst($parts[0]);
        }
        $psrCandidate = implode('\\', $parts);
        $file = str_replace('\\', '/', $psrCandidate) . '.php';
        $path = __DIR__ . '/Lib/' . $file;
        if (file_exists($path)) {
            require_once $path;
            return true;
        }
    }

    return false;
});

// Force load the classes Phing will need for taskdef
$taskClasses = [
    '\\Propel\\Generator\\Task\\PropelOMTask',
    '\\Propel\\Generator\\Task\\PropelDataDumpTask', 
    '\\Propel\\Generator\\Task\\PropelDataSQLTask',
    '\\Propel\\Generator\\Task\\PropelSQLTask',
    '\\Propel\\Generator\\Task\\PropelSQLDiffTask',
    '\\Propel\\Generator\\Task\\PropelSchemaReverseTask',
    '\\Propel\\Generator\\Task\\PropelConvertConfTask',
    '\\Propel\\Generator\\Task\\PropelDBD2PropelTask',
    '\\Propel\\Generator\\Task\\PropelGraphvizTask',
    '\\Propel\\Generator\\Task\\PropelMigrationTask',
    '\\Propel\\Generator\\Task\\PropelMigrationStatusTask',
    '\\Propel\\Generator\\Task\\PropelMigrationUpTask',
    '\\Propel\\Generator\\Task\\PropelMigrationDownTask'
];

// Platform classes
$platformClasses = [
    '\\Propel\\Generator\\Platform\\MysqlPlatform',
    '\\Propel\\Generator\\Platform\\PgsqlPlatform',
    '\\Propel\\Generator\\Platform\\SqlitePlatform',
    '\\Propel\\Generator\\Platform\\OraclePlatform',
    '\\Propel\\Generator\\Platform\\MssqlPlatform',
    '\\Propel\\Generator\\Platform\\SqlsrvPlatform',
    '\\Propel\\Generator\\Platform\\DefaultPlatform'
];

foreach (array_merge($taskClasses, $platformClasses) as $class) {
    if (!class_exists($class, false)) {
        $file = str_replace(['\\Propel\\Generator\\', '\\'], ['', '/'], $class) . '.php';
        $path = __DIR__ . '/Lib/' . $file;
        if (file_exists($path)) {
            require_once $path;
        }
    }
}
