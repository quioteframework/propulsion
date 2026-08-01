<?php

/**
 * This file is part of the Propulsion package.
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 *
 * @license    MIT License
 */
namespace Propulsion\Cache\Driver;

use Propulsion\Cache\AtomicCache;
use Propulsion\Exception\PropulsionException;

/**
 * PSR-16 over plain files, relying on the operating system's page cache to keep
 * hot entries in memory.
 *
 * **On the "isn't this about as fast as APCu?" question: no, and it is worth
 * being precise about why.** Even with every page warm, a read is still
 * open/read/close -- three or four syscalls, each paying a mode switch and a
 * VFS path walk, underneath PHP's stream-wrapper layer -- against APCu's hash
 * probe in a segment already mapped into the process. Expect roughly 8-20us
 * versus 1-3us, so about 3-10x slower. What makes the comparison largely
 * academic is the denominator: a local database round trip for a small SELECT
 * is 100us-1ms, so both drivers amount to "the query went away". The gap only
 * bites when the cached query was cheap to begin with and is hit in a tight
 * loop.
 *
 * Its real justifications are not about speed at all:
 *
 *  1. **No infrastructure.** Works on shared hosting and in a bare container.
 *  2. **Survives restarts.** APCu's segment dies with the php-fpm master.
 *  3. **Shared across SAPIs** -- the one thing APCu genuinely cannot do, since
 *     `apc.enable_cli` gives each CLI process a private segment. A cron job
 *     writing to the database can invalidate the web tier's cached queries
 *     here; under APCu it silently cannot. For applications with CLI writers
 *     this driver is *more correct*, not just slower.
 *
 * Format on disk is a fixed-width expiry header followed by the serialized
 * payload, so an expired entry is detected after reading 11 bytes rather than
 * the whole (potentially large) row set. Expiry is deliberately *not* encoded
 * in the file's mtime: that would destroy mtime for operational debugging, and
 * "never expires" has no natural timestamp.
 */
final class FileCache extends AbstractCacheDriver implements ConfigurableCacheDriver, AtomicCache
{
    /** Written into the cache root at construction; {@see clear()} refuses to run without it. */
    private const MARKER_FILE = '.propulsion-cache';

    /** Zero-padded unix timestamp plus "\n". 0 means "never expires". */
    private const HEADER_BYTES = 11;

    public const DEFAULT_LEVELS = 2;
    public const DEFAULT_DIR_MODE = 0o770;
    public const DEFAULT_FILE_MODE = 0o660;

    private readonly string $root;

    public function __construct(
        string $directory,
        private readonly int $levels = self::DEFAULT_LEVELS,
        private readonly ?int $defaultTtl = null,
        private readonly ?int $maxBytes = null,
        private readonly int $dirMode = self::DEFAULT_DIR_MODE,
        private readonly int $fileMode = self::DEFAULT_FILE_MODE,
    ) {
        if ($directory === '') {
            throw new PropulsionException('The "file" cache driver requires a non-empty directory: Check your configuration file');
        }
        if ($this->levels < 0 || $this->levels > 3) {
            throw new PropulsionException('The "file" cache driver option "levels" must be between 0 and 3, got ' . $this->levels);
        }
        if ($this->maxBytes !== null && $this->maxBytes < 1) {
            throw new PropulsionException('The "file" cache driver option "max_bytes" must be positive, got ' . $this->maxBytes);
        }

        if (!is_dir($directory) && !@mkdir($directory, $this->dirMode, true) && !is_dir($directory)) {
            throw new PropulsionException('Unable to create Propulsion cache directory "' . $directory . '"');
        }
        $real = realpath($directory);
        if ($real === false) {
            throw new PropulsionException('Unable to resolve Propulsion cache directory "' . $directory . '"');
        }
        if (!is_writable($real)) {
            throw new PropulsionException('Propulsion cache directory "' . $real . '" is not writable');
        }
        $this->root = $real;

        $marker = $this->root . DIRECTORY_SEPARATOR . self::MARKER_FILE;
        if (!is_file($marker)) {
            @file_put_contents($marker, "Propulsion query cache directory. Safe to delete when Propulsion is not running.\n");
        }
    }

    /**
     * @param array<string, mixed> $options
     */
    public static function fromConfig(array $options, ?int $defaultTtl): static
    {
        $directory = $options['directory'] ?? null;
        if (!is_string($directory) || $directory === '') {
            throw new PropulsionException(
                'Propulsion configuration option "cache.query.file.directory" is required for the "file" cache '
                . 'driver and must be a non-empty string: Check your configuration file'
            );
        }

        $levels = $options['levels'] ?? self::DEFAULT_LEVELS;
        if (!is_int($levels)) {
            throw new PropulsionException('Propulsion configuration option "cache.query.file.levels" must be an integer, got ' . get_debug_type($levels));
        }

        $maxBytes = $options['max_bytes'] ?? null;
        if ($maxBytes !== null && !is_int($maxBytes)) {
            throw new PropulsionException('Propulsion configuration option "cache.query.file.max_bytes" must be an integer or null, got ' . get_debug_type($maxBytes));
        }

        $dirMode = $options['dir_mode'] ?? self::DEFAULT_DIR_MODE;
        if (!is_int($dirMode)) {
            throw new PropulsionException('Propulsion configuration option "cache.query.file.dir_mode" must be an integer, got ' . get_debug_type($dirMode));
        }

        $fileMode = $options['file_mode'] ?? self::DEFAULT_FILE_MODE;
        if (!is_int($fileMode)) {
            throw new PropulsionException('Propulsion configuration option "cache.query.file.file_mode" must be an integer, got ' . get_debug_type($fileMode));
        }

        return new static($directory, $levels, $defaultTtl, $maxBytes, $dirMode, $fileMode);
    }

    public function get(string $key, mixed $default = null): mixed
    {
        $this->validateKey($key);
        $path = $this->pathFor($key);

        $handle = @fopen($path, 'rb');
        if ($handle === false) {
            return $default;
        }

        try {
            $header = fread($handle, self::HEADER_BYTES);
            if (!is_string($header) || strlen($header) < self::HEADER_BYTES) {
                return $default;
            }
            $expiry = (int) substr($header, 0, self::HEADER_BYTES - 1);
            if ($expiry !== 0 && $expiry <= time()) {
                // Lazy eviction: an expired entry is removed when something
                // asks for it. Entries that are never asked for again are the
                // job of prune(); see the class docblock.
                @unlink($path);

                return $default;
            }

            $body = stream_get_contents($handle);
            if (!is_string($body) || $body === '') {
                return $default;
            }
        } finally {
            fclose($handle);
        }

        return $this->unserializeBody($body, $path, $default);
    }

    public function set(string $key, mixed $value, null|int|\DateInterval $ttl = null): bool
    {
        $this->validateKey($key);
        if ($this->isElapsed($ttl, $this->defaultTtl)) {
            return $this->delete($key);
        }

        $path = $this->pathFor($key);
        if (!$this->ensureDirectory(dirname($path))) {
            return false;
        }

        $payload = $this->encode($value, $this->expiryFor($ttl, $this->defaultTtl));

        // Write to a temp file in the *same* directory, then rename. rename()
        // is atomic within a filesystem, so a concurrent reader sees either the
        // previous complete entry or the new complete one, never a torn write
        // -- which is also why this driver needs no locking on either path.
        // (A temp file in the system temp dir would risk a cross-device rename,
        // which is not atomic and can fail outright.)
        $tmp = dirname($path) . DIRECTORY_SEPARATOR . '.' . bin2hex(random_bytes(8)) . '.tmp';
        if (@file_put_contents($tmp, $payload) === false) {
            return false;
        }
        @chmod($tmp, $this->fileMode);

        if (!@rename($tmp, $path)) {
            @unlink($tmp);

            return false;
        }

        return true;
    }

    /**
     * Atomic create-if-absent, via `x` mode (O_CREAT|O_EXCL). The kernel
     * guarantees exactly one concurrent caller creates the file, which is what
     * makes single-flight correct here.
     */
    public function add(string $key, mixed $value, null|int|\DateInterval $ttl = null): bool
    {
        $this->validateKey($key);
        if ($this->isElapsed($ttl, $this->defaultTtl)) {
            return false;
        }

        $path = $this->pathFor($key);
        if (!$this->ensureDirectory(dirname($path))) {
            return false;
        }

        $handle = @fopen($path, 'xb');
        if ($handle === false) {
            // Either another caller won, or a stale expired entry is sitting
            // there. Only the latter may be taken over.
            if ($this->get($key, $this) === $this) {
                @unlink($path);
                $handle = @fopen($path, 'xb');
            }
            if ($handle === false) {
                return false;
            }
        }

        try {
            $written = fwrite($handle, $this->encode($value, $this->expiryFor($ttl, $this->defaultTtl)));
        } finally {
            fclose($handle);
        }

        if ($written === false) {
            @unlink($path);

            return false;
        }
        @chmod($path, $this->fileMode);

        return true;
    }

    public function delete(string $key): bool
    {
        $this->validateKey($key);
        $path = $this->pathFor($key);
        if (is_file($path)) {
            @unlink($path);
        }

        return true;
    }

    /**
     * Recursively removes the shard tree.
     *
     * Guarded by a marker file written at construction: without it this method
     * refuses to run. A misconfigured `directory` pointing at, say, a document
     * root would otherwise turn a routine cache flush into data loss, and the
     * check costs one stat.
     */
    public function clear(): bool
    {
        if (!is_file($this->root . DIRECTORY_SEPARATOR . self::MARKER_FILE)) {
            throw new PropulsionException(
                'Refusing to clear "' . $this->root . '": it has no ' . self::MARKER_FILE . ' marker, so it does '
                . 'not look like a directory Propulsion created. Check your cache.query.file.directory setting'
            );
        }

        foreach ($this->shardDirectories() as $dir) {
            $this->removeTree($dir);
        }

        foreach ($this->entryFiles($this->root) as $file) {
            @unlink($file);
        }

        return true;
    }

    public function has(string $key): bool
    {
        $this->validateKey($key);

        return $this->get($key, $this) !== $this;
    }

    /**
     * Remove expired entries, then -- if `max_bytes` is configured and still
     * exceeded -- the oldest entries until the cache fits.
     *
     * This is deliberately *not* wired into the request path. Probabilistic
     * in-request garbage collection is a well-known trap: it turns one unlucky
     * user's request into a full-tree stat walk. Call it from cron or a systemd
     * timer instead; see docs/CACHING.md.
     *
     * @return int number of entries removed
     */
    public function prune(): int
    {
        $now = time();
        $removed = 0;
        /** @var list<array{path: string, size: int, mtime: int}> $live */
        $live = [];
        $liveBytes = 0;

        foreach ($this->allEntryFiles() as $file) {
            $expiry = $this->readExpiry($file);
            if ($expiry === null) {
                // Unreadable or malformed -- it can never be served, so it is
                // pure waste.
                @unlink($file);
                $removed++;
                continue;
            }
            if ($expiry !== 0 && $expiry <= $now) {
                @unlink($file);
                $removed++;
                continue;
            }

            $size = @filesize($file);
            $mtime = @filemtime($file);
            if ($size === false || $mtime === false) {
                continue;
            }
            $live[] = ['path' => $file, 'size' => $size, 'mtime' => $mtime];
            $liveBytes += $size;
        }

        if ($this->maxBytes === null || $liveBytes <= $this->maxBytes) {
            return $removed;
        }

        usort($live, static fn (array $a, array $b): int => $a['mtime'] <=> $b['mtime']);
        foreach ($live as $entry) {
            if ($liveBytes <= $this->maxBytes) {
                break;
            }
            if (@unlink($entry['path'])) {
                $liveBytes -= $entry['size'];
                $removed++;
            }
        }

        return $removed;
    }

    /**
     * Total bytes currently occupied by cache entries. Used by the tests and by
     * operators sizing `max_bytes`; it walks the tree, so it is not for the
     * request path.
     */
    public function sizeInBytes(): int
    {
        $total = 0;
        foreach ($this->allEntryFiles() as $file) {
            $size = @filesize($file);
            if ($size !== false) {
                $total += $size;
            }
        }

        return $total;
    }

    public function getRoot(): string
    {
        return $this->root;
    }

    private function encode(mixed $value, ?int $expiry): string
    {
        return str_pad((string) ($expiry ?? 0), self::HEADER_BYTES - 1, '0', STR_PAD_LEFT)
            . "\n"
            . serialize($value);
    }

    /**
     * `unserialize()` on a truncated or corrupt payload emits a warning, and
     * the test suite runs with `failOnWarning="true"`, so it is suppressed and
     * validated rather than trusted. A corrupt entry is treated as a miss and
     * removed.
     */
    private function unserializeBody(string $body, string $path, mixed $default): mixed
    {
        $value = @unserialize($body, ['allowed_classes' => true]);
        if ($value === false && $body !== serialize(false)) {
            @unlink($path);

            return $default;
        }

        return $value;
    }

    /**
     * @return int|null unix expiry (0 = never), or null if unreadable/malformed
     */
    private function readExpiry(string $path): ?int
    {
        $handle = @fopen($path, 'rb');
        if ($handle === false) {
            return null;
        }
        try {
            $header = fread($handle, self::HEADER_BYTES);
        } finally {
            fclose($handle);
        }
        if (!is_string($header) || strlen($header) < self::HEADER_BYTES) {
            return null;
        }

        return (int) substr($header, 0, self::HEADER_BYTES - 1);
    }

    /**
     * Shard so no single directory accumulates a pathological number of files:
     * lookups survive it on a modern filesystem, but clear(), prune(), backups
     * and plain `ls` all degrade badly.
     */
    private function pathFor(string $key): string
    {
        $hash = sha1($key);
        $path = $this->root;
        for ($i = 0; $i < $this->levels; $i++) {
            $path .= DIRECTORY_SEPARATOR . substr($hash, $i * 2, 2);
        }

        return $path . DIRECTORY_SEPARATOR . $hash . '.pcache';
    }

    private function ensureDirectory(string $dir): bool
    {
        if (is_dir($dir)) {
            return true;
        }

        // Check-then-create races with other processes; mkdir() failing
        // because someone else just made it is success, not an error.
        if (@mkdir($dir, $this->dirMode, true)) {
            return true;
        }

        return is_dir($dir);
    }

    /**
     * @return list<string>
     */
    private function shardDirectories(): array
    {
        $dirs = [];
        $entries = @scandir($this->root);
        if ($entries === false) {
            return [];
        }
        foreach ($entries as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $path = $this->root . DIRECTORY_SEPARATOR . $entry;
            if (is_dir($path)) {
                $dirs[] = $path;
            }
        }

        return $dirs;
    }

    /**
     * @return list<string>
     */
    private function entryFiles(string $dir): array
    {
        $files = [];
        $entries = @scandir($dir);
        if ($entries === false) {
            return [];
        }
        foreach ($entries as $entry) {
            if (!str_ends_with($entry, '.pcache') && !str_ends_with($entry, '.tmp')) {
                continue;
            }
            $path = $dir . DIRECTORY_SEPARATOR . $entry;
            if (is_file($path)) {
                $files[] = $path;
            }
        }

        return $files;
    }

    /**
     * @return list<string>
     */
    private function allEntryFiles(): array
    {
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($this->root, \FilesystemIterator::SKIP_DOTS)
        );

        $files = [];
        foreach ($iterator as $file) {
            if ($file instanceof \SplFileInfo && $file->isFile() && $file->getExtension() === 'pcache') {
                $files[] = $file->getPathname();
            }
        }

        return $files;
    }

    private function removeTree(string $dir): void
    {
        $entries = @scandir($dir);
        if ($entries === false) {
            return;
        }
        foreach ($entries as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $path = $dir . DIRECTORY_SEPARATOR . $entry;
            if (is_dir($path)) {
                $this->removeTree($path);
            } else {
                @unlink($path);
            }
        }
        @rmdir($dir);
    }
}
