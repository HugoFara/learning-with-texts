<?php

/**
 * \file
 * \brief Environment file (.env) loader for LWT configuration.
 *
 * This class provides a simple way to load configuration from .env files,
 * which is the modern standard for application configuration.
 *
 * PHP version 8.2
 *
 * @category Lwt
 * @package  Lwt
 * @author   HugoFara <git@hugofara.net>
 * @license Unlicense <http://unlicense.org/>
 * @link    https://hugofara.github.io/lwt/developer/api
 * @since   3.0.0
 */

declare(strict_types=1);

namespace Lwt\Shared\Infrastructure\Bootstrap;

/**
 * Simple .env file parser and loader.
 *
 * This class parses .env files and makes the values available
 * through environment variables and a static getter.
 *
 * Usage:
 * ```php
 * use Lwt\Shared\Infrastructure\Bootstrap\EnvLoader;
 *
 * // Load .env file
 * EnvLoader::load('/path/to/.env');
 *
 * // Get values
 * $dbHost = EnvLoader::get('DB_HOST', 'localhost');
 * ```
 *
 * @since 3.0.0
 */
class EnvLoader
{
    /**
     * @var array<string, string> Loaded environment variables
     */
    private static array $env = [];

    /**
     * @var bool Whether the .env file has been loaded
     */
    private static bool $loaded = false;

    /**
     * Load and parse a .env file.
     *
     * The file format supports:
     * - KEY=value
     * - KEY="value with spaces"
     * - KEY='value with spaces'
     * - # comments
     * - Empty lines (ignored)
     *
     * @param string $path Path to the .env file
     *
     * @return bool True if file was loaded successfully, false otherwise
     */
    public static function load(string $path): bool
    {
        if (!file_exists($path) || !is_readable($path)) {
            return false;
        }

        $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        if ($lines === false) {
            return false;
        }

        foreach ($lines as $line) {
            // Skip comments and empty lines
            $line = trim($line);
            if ($line === '' || str_starts_with($line, '#')) {
                continue;
            }

            // Parse KEY=value format
            $pos = strpos($line, '=');
            if ($pos === false) {
                continue;
            }

            $key = trim(substr($line, 0, $pos));
            $value = trim(substr($line, $pos + 1));

            // Remove surrounding quotes if present
            if (
                (str_starts_with($value, '"') && str_ends_with($value, '"'))
                || (str_starts_with($value, "'") && str_ends_with($value, "'"))
            ) {
                $value = substr($value, 1, -1);
            }

            // Handle escape sequences in double-quoted strings
            if (str_starts_with($value, '"')) {
                $value = stripcslashes($value);
            }

            // Store in our array
            self::$env[$key] = $value;

            // Also set in $_ENV and putenv for compatibility
            $_ENV[$key] = $value;
            putenv("$key=$value");
        }

        self::$loaded = true;
        return true;
    }

    /**
     * Get an environment variable value.
     *
     * Checks in order:
     * 1. Values loaded from .env file
     * 2. $_ENV superglobal
     * 3. getenv() function
     * 4. Default value
     *
     * @param string      $key     The environment variable name
     * @param string|null $default Default value if not found
     *
     * @return string|null The value, or default if not found
     */
    public static function get(string $key, ?string $default = null): ?string
    {
        // Check our loaded values first
        if (isset(self::$env[$key])) {
            return self::$env[$key];
        }

        // Check $_ENV
        if (isset($_ENV[$key])) {
            return is_string($_ENV[$key]) ? $_ENV[$key] : (string)$_ENV[$key];
        }

        // Check getenv()
        $value = getenv($key);
        if ($value !== false) {
            return $value;
        }

        return $default;
    }

    /**
     * Get an environment variable as a boolean.
     *
     * Recognizes: true, 1, yes, on as true
     *             false, 0, no, off, empty as false
     *
     * @param string $key     The environment variable name
     * @param bool   $default Default value if not found
     *
     * @return bool The boolean value
     */
    public static function getBool(string $key, bool $default = false): bool
    {
        $value = self::get($key);

        if ($value === null) {
            return $default;
        }

        $value = strtolower(trim($value));

        if (in_array($value, ['true', '1', 'yes', 'on'], true)) {
            return true;
        }

        if (in_array($value, ['false', '0', 'no', 'off', ''], true)) {
            return false;
        }

        return $default;
    }

    /**
     * Get an environment variable as an integer.
     *
     * @param string $key     The environment variable name
     * @param int    $default Default value if not found or not numeric
     *
     * @return int The integer value
     */
    public static function getInt(string $key, int $default = 0): int
    {
        $value = self::get($key);

        if ($value === null || !is_numeric($value)) {
            return $default;
        }

        return (int) $value;
    }

    /**
     * Check if a .env file has been loaded.
     *
     * @return bool True if loaded
     */
    public static function isLoaded(): bool
    {
        return self::$loaded;
    }

    /**
     * Check if an environment variable exists.
     *
     * @param string $key The environment variable name
     *
     * @return bool True if the variable exists (even if empty)
     */
    public static function has(string $key): bool
    {
        return isset(self::$env[$key])
            || isset($_ENV[$key])
            || getenv($key) !== false;
    }

    /**
     * Get all loaded environment variables.
     *
     * @return array<string, string> All loaded variables
     */
    public static function all(): array
    {
        return self::$env;
    }

    /**
     * Set (or remove) an environment variable across every source {@link get}
     * consults — the loaded store, `$_ENV`, and `putenv()` — so the value is
     * returned deterministically regardless of any previously-loaded `.env`
     * entry. Pass `null` to remove the key entirely.
     *
     * Mirrors how {@link load} writes a value and how {@link reset} removes one,
     * giving callers (runtime overrides, tests) a single authoritative setter
     * rather than poking `$_ENV` directly, which the loaded store would shadow.
     *
     * @param string      $key   The environment variable name
     * @param string|null $value The value, or null to remove the variable
     *
     * @return void
     */
    public static function set(string $key, ?string $value): void
    {
        if ($key === '') {
            return;
        }
        if ($value === null) {
            unset(self::$env[$key], $_ENV[$key]);
            putenv($key);
            return;
        }
        self::$env[$key] = $value;
        $_ENV[$key] = $value;
        putenv("$key=$value");
    }

    /**
     * Reset the loader state.
     *
     * Primarily used for testing.
     *
     * @return void
     */
    public static function reset(): void
    {
        // Clear environment variables that were set
        foreach (array_keys(self::$env) as $key) {
            if ($key !== '') {
                unset($_ENV[$key]);
                putenv($key);
            }
        }

        self::$env = [];
        self::$loaded = false;
    }

    /**
     * Get database configuration array.
     *
     * Convenience method that returns all database-related configuration
     * in a single array, suitable for passing to connection functions.
     *
     * @return array{
     *     server: string,
     *     userid: string,
     *     passwd: string,
     *     dbname: string,
     *     socket: string
     * }
     */
    public static function getDatabaseConfig(): array
    {
        return [
            'server' => self::get('DB_HOST', 'localhost') ?? 'localhost',
            'userid' => self::get('DB_USER', 'root') ?? 'root',
            'passwd' => self::get('DB_PASSWORD', '') ?? '',
            'dbname' => self::get('DB_NAME', 'learning-with-texts') ?? 'learning-with-texts',
            'socket' => self::get('DB_SOCKET', '') ?? '',
        ];
    }
}
