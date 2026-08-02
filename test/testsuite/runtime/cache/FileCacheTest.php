<?php

/**
 * This file is part of the Propulsion package.
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 *
 * @license    MIT License
 */

use Propulsion\Cache\Driver\FileCache;
use Propulsion\Exception\PropulsionException;
use Psr\SimpleCache\CacheInterface;

/**
 * @see Psr16DriverTestCase for the shared PSR-16 conformance assertions.
 * @see FileCacheCrossProcessTest for the genuine two-OS-process test.
 */
class FileCacheTest extends Psr16DriverTestCase
{
    private string $dir = '';

    protected function setUp(): void
    {
        parent::setUp();
        $this->dir = sys_get_temp_dir() . '/propulsion-filecache-' . bin2hex(random_bytes(6));
    }

    protected function tearDown(): void
    {
        self::removeTree($this->dir);
        parent::tearDown();
    }

    protected function createCache(): CacheInterface
    {
        return new FileCache($this->dir);
    }

    public static function removeTree(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        $entries = scandir($dir);
        if ($entries === false) {
            return;
        }
        foreach ($entries as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $path = $dir . DIRECTORY_SEPARATOR . $entry;
            is_dir($path) ? self::removeTree($path) : @unlink($path);
        }
        @rmdir($dir);
    }

    /**
     * @return list<string>
     */
    private function entryFiles(): array
    {
        if (!is_dir($this->dir)) {
            return [];
        }
        $found = [];
        $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($this->dir, FilesystemIterator::SKIP_DOTS));
        foreach ($it as $file) {
            if ($file instanceof SplFileInfo && $file->isFile() && $file->getExtension() === 'pcache') {
                $found[] = $file->getPathname();
            }
        }

        return $found;
    }

    public function testCreatesTheDirectoryAndAMarkerFile()
    {
        $this->createCache();

        $this->assertDirectoryExists($this->dir);
        $this->assertFileExists($this->dir . '/.propulsion-cache');
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('shardLevels')]
    public function testShardingProducesTheExpectedDepth(int $levels)
    {
        $cache = new FileCache($this->dir, $levels);
        $cache->set('some_key', 'v');

        $files = $this->entryFiles();
        $this->assertCount(1, $files);

        $relative = trim(str_replace(realpath($this->dir), '', $files[0]), DIRECTORY_SEPARATOR);
        $depth = count(explode(DIRECTORY_SEPARATOR, $relative)) - 1;

        $this->assertSame($levels, $depth);
        $this->assertSame('v', $cache->get('some_key'));
    }

    /**
     * @return array<string, array{0: int}>
     */
    public static function shardLevels(): array
    {
        return ['flat' => [0], 'one level' => [1], 'two levels' => [2], 'three levels' => [3]];
    }

    public function testRejectsAnOutOfRangeLevels()
    {
        $this->expectException(PropulsionException::class);
        new FileCache($this->dir, 4);
    }

    public function testLeavesNoTemporaryFilesBehind()
    {
        $cache = $this->createCache();
        for ($i = 0; $i < 20; $i++) {
            $cache->set('tmp_probe_' . $i, str_repeat('x', 500));
        }

        $leftovers = [];
        $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($this->dir, FilesystemIterator::SKIP_DOTS));
        foreach ($it as $file) {
            if ($file instanceof SplFileInfo && $file->isFile() && str_ends_with($file->getFilename(), '.tmp')) {
                $leftovers[] = $file->getPathname();
            }
        }

        $this->assertSame([], $leftovers, 'the temp-file-then-rename write path must not leak temp files');
    }

    /**
     * A truncated or garbage payload must read as a miss. The suite runs with
     * failOnWarning="true", so an unsuppressed unserialize() would fail here.
     */
    public function testCorruptEntryReadsAsAMissAndIsRemoved()
    {
        $cache = $this->createCache();
        $cache->set('corrupt_me', ['real' => 'payload']);

        $files = $this->entryFiles();
        $this->assertCount(1, $files);
        file_put_contents($files[0], "0000000000\nnot-valid-serialized-data");

        $this->assertSame('MISS', $cache->get('corrupt_me', 'MISS'));
        $this->assertFalse($cache->has('corrupt_me'));
        $this->assertSame([], $this->entryFiles(), 'a corrupt entry should be unlinked');
    }

    public function testTruncatedHeaderReadsAsAMiss()
    {
        $cache = $this->createCache();
        $cache->set('short_file', 'v');

        $files = $this->entryFiles();
        file_put_contents($files[0], '123');

        $this->assertSame('MISS', $cache->get('short_file', 'MISS'));
    }

    public function testStoredFalseSurvivesTheCorruptionGuard()
    {
        // serialize(false) is the one payload that unserialize() legitimately
        // returns false for, so the corruption check must special-case it.
        $cache = $this->createCache();
        $cache->set('legit_false', false);

        $this->assertTrue($cache->has('legit_false'));
        $this->assertFalse($cache->get('legit_false', 'SENTINEL'));
    }

    public function testClearRemovesEntriesButKeepsTheMarker()
    {
        $cache = $this->createCache();
        $cache->set('a', 1);
        $cache->set('b', 2);

        $this->assertTrue($cache->clear());
        $this->assertSame([], $this->entryFiles());
        $this->assertFileExists($this->dir . '/.propulsion-cache');
    }

    /**
     * Without this guard, a fat-fingered `directory` pointing at a document
     * root would turn a routine cache flush into data loss.
     */
    public function testClearRefusesWithoutTheMarkerFile()
    {
        $cache = $this->createCache();
        $cache->set('a', 1);
        unlink($this->dir . '/.propulsion-cache');

        $this->expectException(PropulsionException::class);
        $cache->clear();
    }

    public function testPruneRemovesExpiredEntriesOnly()
    {
        $cache = new FileCache($this->dir, 2, 3600);
        $cache->set('lives', 'v');
        $cache->set('dies', 'v');

        // Rewrite one entry's header so it is already expired, without waiting.
        foreach ($this->entryFiles() as $file) {
            $contents = (string) file_get_contents($file);
            if (str_contains($contents, serialize('v')) && str_contains($file, sha1('dies'))) {
                file_put_contents($file, str_pad((string) (time() - 10), 10, '0', STR_PAD_LEFT) . "\n" . serialize('v'));
            }
        }

        $this->assertSame(1, $cache->prune());
        $this->assertTrue($cache->has('lives'));
        $this->assertFalse($cache->has('dies'));
    }

    public function testPruneEnforcesMaxBytes()
    {
        $cache = new FileCache($this->dir, 2, null, 4000);
        for ($i = 0; $i < 20; $i++) {
            $cache->set('bulk_' . $i, str_repeat('x', 1000));
            // Distinct mtimes so "oldest first" eviction is deterministic.
            touch($this->entryFiles()[0], time() - (100 - $i));
        }

        $this->assertGreaterThan(4000, $cache->sizeInBytes());
        $cache->prune();
        $this->assertLessThanOrEqual(4000, $cache->sizeInBytes(), 'prune() must bring the cache within max_bytes');
    }

    public function testAddTakesOverAnExpiredEntry()
    {
        $cache = $this->createCache();
        $this->assertTrue($cache->add('lock', 'first', 3600));

        // Expire it in place, the way a lapsed single-flight lock would.
        foreach ($this->entryFiles() as $file) {
            file_put_contents($file, str_pad((string) (time() - 10), 10, '0', STR_PAD_LEFT) . "\n" . serialize('first'));
        }

        $this->assertTrue($cache->add('lock', 'second', 3600), 'an expired lock must be reclaimable');
        $this->assertSame('second', $cache->get('lock'));
    }

    public function testHandCraftedObjectPayloadIsNotInstantiated()
    {
        // This driver is shared across processes and SAPIs by design, so its
        // directory is writable by everything that uses it (and possibly by
        // another application sharing it) -- which makes what comes back out of
        // it untrusted input. Deserializing arbitrary classes from there is a
        // gadget-chain foothold in every reader, so unserialize() runs with
        // allowed_classes: false and a class payload comes back inert.
        $cache = new FileCache($this->dir);
        $cache->set('seed', 'anything');

        // Write a serialized object straight into the entry file, the way a
        // hostile writer with directory access would.
        $path = $this->entryPathFor($cache, 'seed');
        $this->assertIsString($path);
        $header = str_pad((string) (time() + 3600), 10, '0', STR_PAD_LEFT) . "\n";
        file_put_contents($path, $header . serialize(new ArrayObject(['pwned'])));

        $value = $cache->get('seed');

        $this->assertNotInstanceOf(ArrayObject::class, $value);
        $this->assertInstanceOf('__PHP_Incomplete_Class', $value);
    }

    public function testOrdinaryValueShapesStillRoundTrip()
    {
        // The flip side of the check above: narrowing unserialize() must not
        // disturb anything Propulsion actually stores (scalar row arrays,
        // version tokens, admission counters).
        $cache = new FileCache($this->dir);
        $rows = [[1, 'a', null], [2, 'b', 3.5]];

        $cache->set('rows', $rows);
        $cache->set('token', 'a1b2c3d4');
        $cache->set('sightings', 2);

        $this->assertSame($rows, $cache->get('rows'));
        $this->assertSame('a1b2c3d4', $cache->get('token'));
        $this->assertSame(2, $cache->get('sightings'));
    }

    /**
     * The on-disk path FileCache uses for $key -- it shards by sha1, which is
     * private, so this locates the one entry file that exists instead of
     * recomputing the layout.
     */
    private function entryPathFor(FileCache $cache, string $key): ?string
    {
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($cache->getRoot(), FilesystemIterator::SKIP_DOTS)
        );
        foreach ($iterator as $file) {
            if ($file instanceof SplFileInfo && $file->isFile() && $file->getExtension() === 'pcache') {
                return $file->getPathname();
            }
        }

        return null;
    }

    public function testFromConfigRequiresADirectory()
    {
        $this->expectException(PropulsionException::class);
        FileCache::fromConfig([], null);
    }

    public function testFromConfigBuildsFromOptions()
    {
        $cache = FileCache::fromConfig(['directory' => $this->dir, 'levels' => 1], 300);
        $cache->set('k', 'v');

        $this->assertSame('v', $cache->get('k'));
        $this->assertSame(realpath($this->dir), $cache->getRoot());
    }

    public function testFromConfigRejectsNonIntegerLevels()
    {
        $this->expectException(PropulsionException::class);
        FileCache::fromConfig(['directory' => $this->dir, 'levels' => 'two'], null);
    }
}
