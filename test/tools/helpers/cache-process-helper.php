<?php

/**
 * This file is part of the Propulsion package.
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 *
 * @license    MIT License
 */

/**
 * A separate-OS-process driver for {@see FileCacheCrossProcessTest}.
 *
 * The filesystem driver's whole reason for existing is that it is shared
 * between processes -- including between a CLI job and the web tier, which is
 * the one thing APCu cannot do. Proving that requires a genuinely different
 * process, not another object in the same one, so the test spawns this script
 * with proc_open() and asserts on what it prints.
 *
 * Usage: php cache-process-helper.php <directory> <action> <key> [value]
 * Actions: get | set | has | delete | add
 * Prints a single JSON object on stdout.
 */

require __DIR__ . '/../../../vendor/autoload.php';

use Propulsion\Cache\Driver\FileCache;

$directory = $argv[1] ?? '';
$action = $argv[2] ?? '';
$key = $argv[3] ?? '';
$value = $argv[4] ?? null;

try {
    $cache = new FileCache($directory);

    $result = match ($action) {
        'get' => ['value' => $cache->get($key, null), 'hit' => $cache->has($key)],
        'set' => ['ok' => $cache->set($key, $value)],
        'add' => ['won' => $cache->add($key, $value, 60)],
        'has' => ['hit' => $cache->has($key)],
        'delete' => ['ok' => $cache->delete($key)],
        default => throw new InvalidArgumentException('Unknown action "' . $action . '"'),
    };

    echo json_encode(['status' => 'ok'] + $result, JSON_THROW_ON_ERROR), "\n";
    exit(0);
} catch (Throwable $e) {
    echo json_encode(['status' => 'error', 'message' => $e->getMessage()], JSON_THROW_ON_ERROR), "\n";
    exit(1);
}
