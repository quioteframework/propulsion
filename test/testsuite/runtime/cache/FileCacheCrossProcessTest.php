<?php

/**
 * This file is part of the Propulsion package.
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 *
 * @license    MIT License
 */

use PHPUnit\Framework\TestCase;
use Propulsion\Cache\Driver\FileCache;

/**
 * Proves the filesystem driver really is shared across operating-system
 * processes, by spawning a second PHP process and exchanging entries with it.
 *
 * This is the only driver test in the suite that demonstrates genuine
 * cross-process behaviour. APCu cannot be tested this way at all (each CLI
 * process gets its own segment), and a Redis-backed pool would be testing a
 * third-party client rather than Propulsion. It needs no Docker and runs in the
 * fast unit tier.
 *
 * @see test/tools/helpers/cache-process-helper.php the child process
 */
class FileCacheCrossProcessTest extends TestCase
{
    private string $dir = '';

    protected function setUp(): void
    {
        parent::setUp();
        $this->dir = sys_get_temp_dir() . '/propulsion-xproc-' . bin2hex(random_bytes(6));
    }

    protected function tearDown(): void
    {
        FileCacheTest::removeTree($this->dir);
        parent::tearDown();
    }

    /**
     * @return array<string, mixed>
     */
    private function runChild(string $action, string $key, ?string $value = null): array
    {
        $helper = __DIR__ . '/../../../tools/helpers/cache-process-helper.php';
        $this->assertFileExists($helper);

        $command = escapeshellarg(PHP_BINARY)
            . ' ' . escapeshellarg($helper)
            . ' ' . escapeshellarg($this->dir)
            . ' ' . escapeshellarg($action)
            . ' ' . escapeshellarg($key);
        if ($value !== null) {
            $command .= ' ' . escapeshellarg($value);
        }

        $descriptors = [1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
        $process = proc_open($command, $descriptors, $pipes);
        $this->assertIsResource($process, 'failed to spawn the helper process');

        $stdout = (string) stream_get_contents($pipes[1]);
        $stderr = (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        proc_close($process);

        $decoded = json_decode(trim($stdout), true);
        $this->assertIsArray($decoded, "helper did not return JSON.\nstdout: $stdout\nstderr: $stderr");
        $this->assertSame('ok', $decoded['status'] ?? null, 'helper reported: ' . ($decoded['message'] ?? '?'));

        return $decoded;
    }

    public function testEntryWrittenByTheChildIsVisibleInTheParent()
    {
        $this->runChild('set', 'from_child', 'child_value');

        $cache = new FileCache($this->dir);
        $this->assertTrue($cache->has('from_child'));
        $this->assertSame('child_value', $cache->get('from_child'));
    }

    public function testEntryWrittenByTheParentIsVisibleInTheChild()
    {
        $cache = new FileCache($this->dir);
        $cache->set('from_parent', 'parent_value');

        $result = $this->runChild('get', 'from_parent');

        $this->assertTrue($result['hit']);
        $this->assertSame('parent_value', $result['value']);
    }

    public function testDeleteInTheChildIsVisibleInTheParent()
    {
        $cache = new FileCache($this->dir);
        $cache->set('doomed', 'v');

        $this->runChild('delete', 'doomed');

        $this->assertFalse($cache->has('doomed'), 'a delete in another process must be observed here');
    }

    /**
     * The property single-flight depends on: O_EXCL creation is atomic across
     * processes, so exactly one of two contenders can win a lock.
     */
    public function testOnlyOneProcessWinsAnAdd()
    {
        $cache = new FileCache($this->dir);
        $this->assertTrue($cache->add('contended_lock', 'parent', 60), 'parent should win the empty lock');

        $result = $this->runChild('add', 'contended_lock', 'child');

        $this->assertFalse($result['won'], 'the second process must lose the race');
        $this->assertSame('parent', $cache->get('contended_lock'), 'the loser must not overwrite the winner');
    }

    public function testExpiredEntryWrittenByTheParentIsAMissInTheChild()
    {
        $cache = new FileCache($this->dir);
        $cache->set('short_lived', 'v', -1);

        $result = $this->runChild('has', 'short_lived');

        $this->assertFalse($result['hit']);
    }
}
