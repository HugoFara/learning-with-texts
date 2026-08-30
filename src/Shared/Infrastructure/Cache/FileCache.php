<?php

/**
 * \file
 * \brief Small file-backed cache for expensive remote fetches.
 *
 * PHP version 8.2
 *
 * @category Lwt
 * @package  Lwt\Shared\Infrastructure\Cache
 * @author   HugoFara <git@hugofara.net>
 * @license  Unlicense <http://unlicense.org/>
 * @link     https://hugofara.github.io/lwt/developer/api
 * @since    3.6.1
 */

declare(strict_types=1);

namespace Lwt\Shared\Infrastructure\Cache;

/**
 * A namespaced, TTL'd cache of strings on the filesystem.
 *
 * Exists for one job: stop the app re-downloading the same third-party
 * document on every request. Only put things here that are the same for every
 * user — a cached remote document is shared across the whole installation, so
 * anything derived from a user's own data must be computed after the read, not
 * stored in it.
 *
 * Entries live under the system temp directory, so losing them costs a refetch
 * and nothing else. Every operation degrades to a miss rather than throwing:
 * an unwritable temp directory should make the app slow, never broken.
 *
 * @category Lwt
 * @package  Lwt\Shared\Infrastructure\Cache
 * @author   HugoFara <git@hugofara.net>
 * @license  Unlicense <http://unlicense.org/>
 * @link     https://hugofara.github.io/lwt/developer/api
 * @since    3.6.1
 */
class FileCache
{
    /**
     * Cache namespace, used as the directory name.
     */
    private string $namespace;

    /**
     * Entry lifetime in seconds.
     */
    private int $ttl;

    /**
     * @param string $namespace Directory name under the temp dir
     * @param int    $ttl       Entry lifetime in seconds
     */
    public function __construct(string $namespace, int $ttl)
    {
        $this->namespace = preg_replace('/[^a-z0-9_]/i', '_', $namespace) ?? 'lwt_cache';
        $this->ttl = $ttl;
    }

    /**
     * Read an entry, or null when absent or expired.
     *
     * @param string $key Cache key, of any shape — it is hashed
     *
     * @return string|null The cached value, or null on a miss
     */
    public function get(string $key): ?string
    {
        $path = $this->path($key);
        if ($path === null || !is_file($path)) {
            return null;
        }

        $mtime = @filemtime($path);
        if ($mtime === false || (time() - $mtime) > $this->ttl) {
            @unlink($path);
            return null;
        }

        $content = @file_get_contents($path);
        return ($content === false || $content === '') ? null : $content;
    }

    /**
     * Write an entry.
     *
     * Silently does nothing when the value is empty or the cache directory is
     * unavailable — the caller already holds the value it was going to store.
     *
     * @param string $key   Cache key, of any shape — it is hashed
     * @param string $value Value to store
     *
     * @return void
     */
    public function set(string $key, string $value): void
    {
        if ($value === '') {
            return;
        }

        $path = $this->path($key);
        if ($path === null) {
            return;
        }

        // Write then rename, so a reader never sees a half-written entry and
        // two concurrent writers cannot interleave into one corrupt file.
        $tmp = $path . '.' . getmypid() . '.tmp';
        if (@file_put_contents($tmp, $value) === false) {
            @unlink($tmp);
            return;
        }
        if (!@rename($tmp, $path)) {
            @unlink($tmp);
        }
    }

    /**
     * Resolve the file backing a key, creating the directory on demand.
     *
     * @param string $key Cache key
     *
     * @return string|null Absolute path, or null when the directory is unusable
     */
    private function path(string $key): ?string
    {
        $dir = sys_get_temp_dir() . '/' . $this->namespace;
        if (!is_dir($dir) && !@mkdir($dir, 0700, true) && !is_dir($dir)) {
            return null;
        }
        return $dir . '/' . hash('sha256', $key);
    }
}
