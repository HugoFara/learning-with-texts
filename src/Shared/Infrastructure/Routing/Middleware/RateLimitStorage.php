<?php

/**
 * Rate Limit Storage Backend
 *
 * PHP version 8.2
 *
 * @category Lwt
 * @package  Lwt\Shared\Infrastructure\Routing\Middleware
 * @author   HugoFara <git@hugofara.net>
 * @license  Unlicense <http://unlicense.org/>
 * @link     https://hugofara.github.io/lwt/developer/api
 * @since    3.0.0
 */

declare(strict_types=1);

namespace Lwt\Shared\Infrastructure\Routing\Middleware;

/**
 * Storage backend for rate limit data.
 *
 * Uses APCu if available, otherwise falls back to file-based storage.
 * Automatically cleans up expired entries.
 *
 * @category Lwt
 * @package  Lwt\Shared\Infrastructure\Routing\Middleware
 * @author   HugoFara <git@hugofara.net>
 * @license  Unlicense <http://unlicense.org/>
 * @link     https://hugofara.github.io/lwt/developer/api
 * @since    3.0.0
 */
class RateLimitStorage
{
    /**
     * Storage directory for file-based backend.
     */
    private string $storageDir;

    /**
     * Whether APCu is available.
     */
    private bool $useApcu;

    /**
     * Cleanup probability (1 in N requests triggers cleanup).
     */
    private const CLEANUP_PROBABILITY = 100;

    /**
     * Create a new RateLimitStorage.
     *
     * @param string|null $storageDir Optional custom storage directory
     */
    public function __construct(?string $storageDir = null)
    {
        $this->useApcu = function_exists('apcu_fetch') && apcu_enabled();
        $this->storageDir = $storageDir ?? $this->getDefaultStorageDir();

        // Probabilistic cleanup for file-based storage
        if (!$this->useApcu && random_int(1, self::CLEANUP_PROBABILITY) === 1) {
            $this->cleanup();
        }
    }

    /**
     * Get the default storage directory.
     *
     * @return string Path to storage directory
     */
    private function getDefaultStorageDir(): string
    {
        // Use system temp directory with app-specific subdirectory
        $baseDir = sys_get_temp_dir();
        $storageDir = $baseDir . '/lwt_rate_limit';

        if (!is_dir($storageDir)) {
            @mkdir($storageDir, 0700, true);
        }

        return $storageDir;
    }

    /**
     * Get rate limit data for a key.
     *
     * @param string $key Storage key
     *
     * @return array{count: int, window_start: int}|null Data or null if not found
     */
    public function get(string $key): ?array
    {
        if ($this->useApcu) {
            /** @var mixed $data */
            $data = apcu_fetch($key, $success);
            if ($success && is_array($data)) {
                /** @var array{count: int, window_start: int} */
                return $data;
            }
            return null;
        }

        return $this->getFromFile($key);
    }

    /**
     * Set rate limit data for a key.
     *
     * @param string $key  Storage key
     * @param array  $data Rate limit data
     * @param int    $ttl  Time-to-live in seconds
     *
     * @return void
     */
    public function set(string $key, array $data, int $ttl): void
    {
        if ($this->useApcu) {
            apcu_store($key, $data, $ttl);
            return;
        }

        $this->saveToFile($key, $data, $ttl);
    }

    /**
     * Get data from file storage.
     *
     * @param string $key Storage key
     *
     * @return array{count: int, window_start: int}|null Data or null if not found/expired
     */
    private function getFromFile(string $key): ?array
    {
        $file = $this->getFilePath($key);

        if (!file_exists($file)) {
            return null;
        }

        $content = @file_get_contents($file);
        if ($content === false) {
            return null;
        }

        $stored = @json_decode($content, true);
        if (!is_array($stored)) {
            @unlink($file);
            return null;
        }

        // Check expiration
        if (isset($stored['expires']) && $stored['expires'] < time()) {
            @unlink($file);
            return null;
        }

        // Validate the data structure
        $data = $stored['data'] ?? null;
        if (!is_array($data) || !isset($data['count'], $data['window_start'])) {
            @unlink($file);
            return null;
        }

        return [
            'count' => (int) $data['count'],
            'window_start' => (int) $data['window_start']
        ];
    }

    /**
     * Save data to file storage.
     *
     * @param string $key  Storage key
     * @param array  $data Rate limit data
     * @param int    $ttl  Time-to-live in seconds
     *
     * @return void
     */
    private function saveToFile(string $key, array $data, int $ttl): void
    {
        $file = $this->getFilePath($key);

        $stored = [
            'data' => $data,
            'expires' => time() + $ttl,
        ];

        // Atomic write using rename
        $pid = getmypid();
        $tempFile = $file . '.tmp.' . ($pid !== false ? $pid : random_int(1000, 9999));
        $json = json_encode($stored);
        $success = $json !== false ? @file_put_contents($tempFile, $json, LOCK_EX) : false;

        if ($success !== false) {
            @rename($tempFile, $file);
        } else {
            @unlink($tempFile);
        }
    }

    /**
     * Get the file path for a storage key.
     *
     * @param string $key Storage key
     *
     * @return string File path
     */
    private function getFilePath(string $key): string
    {
        // Use hash to create valid filename
        $hash = hash('sha256', $key);
        return $this->storageDir . '/' . $hash . '.json';
    }

    /**
     * Clean up expired entries from file storage.
     *
     * @return void
     */
    private function cleanup(): void
    {
        if (!is_dir($this->storageDir)) {
            return;
        }

        $files = @scandir($this->storageDir);
        if ($files === false) {
            return;
        }

        $now = time();
        $maxCleanup = 50; // Limit cleanup to avoid blocking
        $cleaned = 0;

        foreach ($files as $file) {
            if ($cleaned >= $maxCleanup) {
                break;
            }

            if ($file === '.' || $file === '..' || !str_ends_with($file, '.json')) {
                continue;
            }

            $path = $this->storageDir . '/' . $file;
            $content = @file_get_contents($path);

            if ($content === false) {
                continue;
            }

            /** @var mixed $decoded */
            $decoded = @json_decode($content, true);
            if (!is_array($decoded) || (isset($decoded['expires']) && $decoded['expires'] < $now)) {
                @unlink($path);
                $cleaned++;
            }
        }
    }

    /**
     * Clear all rate limit data (for testing).
     *
     * @return void
     *
     * @psalm-suppress PossiblyUnusedMethod - Public API for testing
     */
    public function clear(): void
    {
        if ($this->useApcu) {
            apcu_clear_cache();
            return;
        }

        if (!is_dir($this->storageDir)) {
            return;
        }

        $files = @scandir($this->storageDir);
        if ($files === false) {
            return;
        }

        foreach ($files as $file) {
            if ($file === '.' || $file === '..' || !str_ends_with($file, '.json')) {
                continue;
            }
            @unlink($this->storageDir . '/' . $file);
        }
    }
}
