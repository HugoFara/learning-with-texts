<?php

/**
 * \file
 * \brief Centralized input validation utilities.
 *
 * Provides type-safe methods for validating and extracting request parameters.
 *
 * PHP version 8.2
 *
 * @category Lwt
 * @package  Lwt
 * @author   HugoFara <git@hugofara.net>
 * @license  Unlicense <http://unlicense.org/>
 * @link     https://hugofara.github.io/lwt/developer/api
 * @since    3.0.0
 */

declare(strict_types=1);

namespace Lwt\Shared\Infrastructure\Http;

/**
 * Centralized input validation for request parameters.
 *
 * Provides methods for safely extracting and validating input from
 * $_REQUEST, $_GET, $_POST, and $_FILES superglobals.
 *
 * @category Lwt
 * @package  Lwt
 * @author   HugoFara <git@hugofara.net>
 * @license  Unlicense <http://unlicense.org/>
 * @link     https://hugofara.github.io/lwt/developer/api
 * @since    3.0.0
 */
class InputValidator
{
    /**
     * Get a string parameter from the request.
     *
     * @param string $key     Parameter name
     * @param string $default Default value if not set or invalid
     * @param bool   $trim    Whether to trim whitespace (default: true)
     *
     * @return string The validated string value
     */
    public static function getString(
        string $key,
        string $default = '',
        bool $trim = true
    ): string {
        if (!isset($_REQUEST[$key])) {
            return $default;
        }

        $value = $_REQUEST[$key];

        if (!is_string($value)) {
            return $default;
        }

        return $trim ? trim($value) : $value;
    }

    /**
     * Get a string parameter from GET request.
     *
     * @param string $key     Parameter name
     * @param string $default Default value if not set or invalid
     * @param bool   $trim    Whether to trim whitespace (default: true)
     *
     * @return string The validated string value
     */
    public static function getStringFromGet(
        string $key,
        string $default = '',
        bool $trim = true
    ): string {
        if (!isset($_GET[$key])) {
            return $default;
        }

        $value = $_GET[$key];

        if (!is_string($value)) {
            return $default;
        }

        return $trim ? trim($value) : $value;
    }

    /**
     * Get a string parameter from POST request.
     *
     * @param string $key     Parameter name
     * @param string $default Default value if not set or invalid
     * @param bool   $trim    Whether to trim whitespace (default: true)
     *
     * @return string The validated string value
     */
    public static function getStringFromPost(
        string $key,
        string $default = '',
        bool $trim = true
    ): string {
        if (!isset($_POST[$key])) {
            return $default;
        }

        $value = $_POST[$key];

        if (!is_string($value)) {
            return $default;
        }

        return $trim ? trim($value) : $value;
    }

    /**
     * Get an integer parameter from the request.
     *
     * @param string   $key     Parameter name
     * @param int|null $default Default value if not set or invalid
     * @param int|null $min     Minimum allowed value (null for no minimum)
     * @param int|null $max     Maximum allowed value (null for no maximum)
     *
     * @return int|null The validated integer value, or null if invalid
     */
    public static function getInt(
        string $key,
        ?int $default = null,
        ?int $min = null,
        ?int $max = null
    ): ?int {
        if (!isset($_REQUEST[$key])) {
            return $default;
        }

        $value = $_REQUEST[$key];

        if (!is_numeric($value)) {
            return $default;
        }

        $intValue = (int) $value;

        if ($min !== null && $intValue < $min) {
            return $default;
        }

        if ($max !== null && $intValue > $max) {
            return $default;
        }

        return $intValue;
    }

    /**
     * Get a required integer parameter from the request.
     *
     * @param string   $key Parameter name
     * @param int|null $min Minimum allowed value (null for no minimum)
     * @param int|null $max Maximum allowed value (null for no maximum)
     *
     * @return int The validated integer value
     *
     * @throws \InvalidArgumentException If the parameter is missing or invalid
     */
    public static function requireInt(
        string $key,
        ?int $min = null,
        ?int $max = null
    ): int {
        if (!isset($_REQUEST[$key])) {
            throw new \InvalidArgumentException(
                "Required parameter '$key' is missing"
            );
        }

        $value = $_REQUEST[$key];

        if (!is_numeric($value)) {
            throw new \InvalidArgumentException(
                "Parameter '$key' must be a valid integer"
            );
        }

        $intValue = (int) $value;

        if ($min !== null && $intValue < $min) {
            throw new \InvalidArgumentException(
                "Parameter '$key' must be at least $min"
            );
        }

        if ($max !== null && $intValue > $max) {
            throw new \InvalidArgumentException(
                "Parameter '$key' must be at most $max"
            );
        }

        return $intValue;
    }

    /**
     * Get a positive integer (> 0) parameter from the request.
     *
     * @param string   $key     Parameter name
     * @param int|null $default Default value if not set or invalid
     *
     * @return int|null The validated positive integer value
     */
    public static function getPositiveInt(string $key, ?int $default = null): ?int
    {
        return self::getInt($key, $default, 1);
    }

    /**
     * Get an integer parameter with a guaranteed non-null return.
     *
     * Similar to getInt but always returns an integer, never null.
     *
     * @param string $key     Parameter name
     * @param int    $default Default value if not set or invalid
     * @param int    $min     Minimum allowed value
     *
     * @return int The validated integer value
     */
    public static function getIntParam(string $key, int $default, int $min = PHP_INT_MIN): int
    {
        $value = self::getInt($key, $default, $min);
        return $value ?? $default;
    }

    /**
     * Get a non-negative integer (>= 0) parameter from the request.
     *
     * @param string   $key     Parameter name
     * @param int|null $default Default value if not set or invalid
     *
     * @return int|null The validated non-negative integer value
     */
    public static function getNonNegativeInt(string $key, ?int $default = null): ?int
    {
        return self::getInt($key, $default, 0);
    }

    /**
     * Get a float parameter from the request.
     *
     * @param string     $key     Parameter name
     * @param float|null $default Default value if not set or invalid
     * @param float|null $min     Minimum allowed value (null for no minimum)
     * @param float|null $max     Maximum allowed value (null for no maximum)
     *
     * @return float|null The validated float value, or null if invalid
     */
    public static function getFloat(
        string $key,
        ?float $default = null,
        ?float $min = null,
        ?float $max = null
    ): ?float {
        if (!isset($_REQUEST[$key])) {
            return $default;
        }

        $value = $_REQUEST[$key];

        if (!is_numeric($value)) {
            return $default;
        }

        $floatValue = (float) $value;

        if ($min !== null && $floatValue < $min) {
            return $default;
        }

        if ($max !== null && $floatValue > $max) {
            return $default;
        }

        return $floatValue;
    }

    /**
     * Get a boolean parameter from the request.
     *
     * Accepts: "1", "true", "yes", "on" for true
     *          "0", "false", "no", "off", "" for false
     *
     * @param string    $key     Parameter name
     * @param bool|null $default Default value if not set
     *
     * @return bool|null The validated boolean value
     */
    public static function getBool(string $key, ?bool $default = null): ?bool
    {
        if (!isset($_REQUEST[$key])) {
            return $default;
        }

        /** @var mixed $value */
        $value = $_REQUEST[$key];

        // Handle non-string types that might come from other input sources
        if (is_bool($value)) {
            return $value;
        }

        if (is_int($value)) {
            return $value !== 0;
        }

        if (!is_string($value)) {
            return $default;
        }

        $value = strtolower(trim($value));

        if (in_array($value, ['1', 'true', 'yes', 'on'], true)) {
            return true;
        }

        if (in_array($value, ['0', 'false', 'no', 'off', ''], true)) {
            return false;
        }

        return $default;
    }

    /**
     * Get an array parameter from the request.
     *
     * @param string $key     Parameter name
     * @param array  $default Default value if not set or invalid
     *
     * @return array The validated array value
     *
     * @psalm-return array<mixed>
     */
    public static function getArray(string $key, array $default = []): array
    {
        if (!isset($_REQUEST[$key])) {
            return $default;
        }

        $value = $_REQUEST[$key];

        if (!is_array($value)) {
            return $default;
        }

        return $value;
    }

    /**
     * Get an array of integers from the request.
     *
     * Filters out non-numeric values and returns only valid integers.
     *
     * @param string     $key     Parameter name
     * @param list<int>  $default Default value if not set
     *
     * @return int[] Array of validated integers
     *
     * @psalm-param list<int> $default
     * @psalm-return list<int>
     */
    public static function getIntArray(string $key, array $default = []): array
    {
        $array = self::getArray($key, $default);

        if (empty($array)) {
            return $default;
        }

        $result = [];
        /** @var mixed $value */
        foreach ($array as $value) {
            if (is_numeric($value)) {
                $result[] = (int) $value;
            }
        }

        return $result;
    }

    /**
     * Get an array of strings from the request.
     *
     * Filters out non-string values.
     *
     * @param string        $key     Parameter name
     * @param list<string>  $default Default value if not set
     * @param bool          $trim    Whether to trim whitespace (default: true)
     *
     * @return string[] Array of validated strings
     *
     * @psalm-param list<string> $default
     * @psalm-return list<string>
     */
    public static function getStringArray(
        string $key,
        array $default = [],
        bool $trim = true
    ): array {
        $array = self::getArray($key, $default);

        if (empty($array)) {
            return $default;
        }

        $result = [];
        /** @var mixed $value */
        foreach ($array as $value) {
            if (is_string($value)) {
                $result[] = $trim ? trim($value) : $value;
            }
        }

        return $result;
    }

    /**
     * Get a value from a predefined set of allowed values.
     *
     * @param string   $key     Parameter name
     * @param string[] $allowed Array of allowed values
     * @param string   $default Default value if not set or not in allowed list
     *
     * @return string The validated value from the allowed set
     */
    public static function getEnum(
        string $key,
        array $allowed,
        string $default = ''
    ): string {
        $value = self::getString($key, $default);

        if (!in_array($value, $allowed, true)) {
            return $default;
        }

        return $value;
    }

    /**
     * Get an integer value from a predefined set of allowed values.
     *
     * @param string $key     Parameter name
     * @param int[]  $allowed Array of allowed values
     * @param int    $default Default value if not set or not in allowed list
     *
     * @return int The validated value from the allowed set
     */
    public static function getIntEnum(
        string $key,
        array $allowed,
        int $default
    ): int {
        $value = self::getInt($key, $default);

        if ($value === null || !in_array($value, $allowed, true)) {
            return $default;
        }

        return $value;
    }

    /**
     * Get a valid URL from the request.
     *
     * @param string $key     Parameter name
     * @param string $default Default value if not set or invalid
     *
     * @return string The validated URL or default
     */
    public static function getUrl(string $key, string $default = ''): string
    {
        $value = self::getString($key, $default);

        if ($value === '') {
            return $default;
        }

        $filtered = filter_var($value, FILTER_VALIDATE_URL);

        return $filtered === false ? $default : $filtered;
    }

    /**
     * Get a valid email address from the request.
     *
     * @param string $key     Parameter name
     * @param string $default Default value if not set or invalid
     *
     * @return string The validated email or default
     */
    public static function getEmail(string $key, string $default = ''): string
    {
        $value = self::getString($key, $default);

        if ($value === '') {
            return $default;
        }

        $filtered = filter_var($value, FILTER_VALIDATE_EMAIL);

        return $filtered === false ? $default : $filtered;
    }

    /**
     * Get an uploaded file's information.
     *
     * @param string $key Parameter name
     *
     * @return array|null File info array or null if not uploaded or error
     *
     * @psalm-return array{name: string, type: string, tmp_name: string, error: int, size: int}|null
     */
    public static function getUploadedFile(string $key): ?array
    {
        if (!isset($_FILES[$key])) {
            return null;
        }

        /** @var mixed $file */
        $file = $_FILES[$key];

        // Validate file structure - must be an array with required keys
        if (!is_array($file)) {
            return null;
        }

        if (
            !isset($file['error']) || !isset($file['tmp_name']) ||
            !isset($file['name']) || !isset($file['type']) || !isset($file['size'])
        ) {
            return null;
        }

        // Check for upload errors
        if ($file['error'] !== UPLOAD_ERR_OK) {
            return null;
        }

        // Verify the file was actually uploaded
        /** @var string $tmpName */
        $tmpName = $file['tmp_name'];
        if (!is_uploaded_file($tmpName)) {
            return null;
        }

        /** @psalm-suppress RedundantCast - Cast needed for robustness with varied input */
        return [
            // Sanitize at ingestion so every downstream consumer (dict
            // import, EPUB parser, error messages, logs) sees the same
            // clean name without having to remember to call basename().
            'name' => self::sanitizeUploadName((string) $file['name']),
            'type' => (string) $file['type'],
            'tmp_name' => (string) $file['tmp_name'],
            'error' => (int) $file['error'],
            'size' => (int) $file['size']
        ];
    }

    /**
     * Strip path components, control bytes, and bidi-override
     * codepoints from a client-supplied filename.
     *
     * The result is still UTF-8 and human-readable for legitimate
     * inputs; hostile inputs collapse to harmless ASCII. Apply this
     * to every $_FILES['name'] before storing, displaying, or
     * embedding in an error message — basename() alone leaves the
     * "trojan source" RTL-override class of payload intact.
     */
    public static function sanitizeUploadName(string $name): string
    {
        $name = basename($name);
        // Strip C0/C1 control bytes and bidi-control codepoints
        // (U+202A..U+202E, U+2066..U+2069). /u makes PCRE compare
        // codepoints — \x{...} escapes are required, raw byte
        // sequences would be re-decoded and never match.
        $name = (string) preg_replace(
            '/[\x00-\x1F\x7F]|[\x{202A}-\x{202E}]|[\x{2066}-\x{2069}]/u',
            '',
            $name
        );
        // Clamp absurdly long names — most filesystems cap at 255
        // bytes, and a name that long usually signals an attack.
        if (strlen($name) > 255) {
            $name = substr($name, 0, 255);
        }
        if ($name === '') {
            return 'unknown';
        }
        return $name;
    }

    /**
     * Get the contents of an uploaded text file.
     *
     * @param string $key         Parameter name
     * @param int    $maxSize     Maximum file size in bytes (default: 1MB)
     * @param bool   $convertCrlf Whether to convert CRLF to LF (default: true)
     *
     * @return string|null File contents or null if not uploaded or error
     */
    public static function getUploadedTextContent(
        string $key,
        int $maxSize = 1048576,
        bool $convertCrlf = true
    ): ?string {
        $file = self::getUploadedFile($key);

        if ($file === null) {
            return null;
        }

        if ($file['size'] > $maxSize) {
            return null;
        }

        $content = file_get_contents($file['tmp_name']);

        if ($content === false) {
            return null;
        }

        if ($convertCrlf) {
            $content = str_replace("\r\n", "\n", $content);
            $content = str_replace("\r", "\n", $content);
        }

        return $content;
    }

    /**
     * Validate that a string matches a regular expression pattern.
     *
     * @param string $value   Value to validate
     * @param string $pattern Regular expression pattern (empty pattern returns false)
     *
     * @return bool True if the value matches the pattern
     */
    public static function matchesPattern(string $value, string $pattern): bool
    {
        if ($pattern === '') {
            return false;
        }
        /** @var non-empty-string $pattern */
        return preg_match($pattern, $value) === 1;
    }

    /**
     * Get a string that matches a regular expression pattern.
     *
     * @param string $key     Parameter name
     * @param string $pattern Regular expression pattern
     * @param string $default Default value if not set or doesn't match
     *
     * @return string The validated string or default
     */
    public static function getStringMatching(
        string $key,
        string $pattern,
        string $default = ''
    ): string {
        $value = self::getString($key, $default);

        if ($value === '' || !self::matchesPattern($value, $pattern)) {
            return $default;
        }

        return $value;
    }

    /**
     * Sanitize a string for HTML output.
     *
     * @param string $value String to sanitize
     *
     * @return string Sanitized string
     */
    public static function sanitizeHtml(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    }

    /**
     * Get a sanitized string parameter for HTML output.
     *
     * @param string $key     Parameter name
     * @param string $default Default value if not set
     *
     * @return string The sanitized string value
     */
    public static function getHtmlSafe(string $key, string $default = ''): string
    {
        return self::sanitizeHtml(self::getString($key, $default));
    }

    /**
     * Check if a parameter exists in the request.
     *
     * @param string $key Parameter name
     *
     * @return bool True if the parameter exists
     */
    public static function has(string $key): bool
    {
        return isset($_REQUEST[$key]);
    }

    /**
     * Check if a parameter exists in GET.
     *
     * @param string $key Parameter name
     *
     * @return bool True if the parameter exists in GET
     */
    public static function hasFromGet(string $key): bool
    {
        return isset($_GET[$key]);
    }

    /**
     * Check if a parameter exists in POST.
     *
     * @param string $key Parameter name
     *
     * @return bool True if the parameter exists in POST
     */
    public static function hasFromPost(string $key): bool
    {
        return isset($_POST[$key]);
    }

    /**
     * Check if a parameter exists and is not empty.
     *
     * @param string $key Parameter name
     *
     * @return bool True if the parameter exists and is not empty
     */
    public static function hasValue(string $key): bool
    {
        if (!isset($_REQUEST[$key])) {
            return false;
        }

        /** @var mixed $value */
        $value = $_REQUEST[$key];

        if (is_string($value)) {
            return trim($value) !== '';
        }

        if (is_array($value)) {
            return count($value) > 0;
        }

        return $value !== null;
    }

    /**
     * Get the request method (GET, POST, etc.).
     *
     * @return string The request method in uppercase
     */
    public static function getMethod(): string
    {
        return strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
    }

    /**
     * Check if the request is a POST request.
     *
     * @return bool True if this is a POST request
     */
    public static function isPost(): bool
    {
        return self::getMethod() === 'POST';
    }

    /**
     * Check if the request is a GET request.
     *
     * @return bool True if this is a GET request
     */
    public static function isGet(): bool
    {
        return self::getMethod() === 'GET';
    }

    /**
     * Get a JSON-decoded value from the request.
     *
     * @param string $key     Parameter name
     * @param mixed  $default Default value if not set or invalid JSON
     *
     * @return mixed The decoded JSON value or default
     */
    public static function getJson(string $key, mixed $default = null): mixed
    {
        $value = self::getString($key, '');

        if ($value === '') {
            return $default;
        }

        /** @var mixed $decoded */
        $decoded = json_decode($value, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            return $default;
        }

        return $decoded;
    }

    /**
     * Get multiple parameters at once.
     *
     * @param array<string, mixed> $schema Array of key => default value pairs
     *
     * @return array<string, mixed> Array of validated values
     */
    public static function getMany(array $schema): array
    {
        $result = [];

        /** @var mixed $default */
        foreach ($schema as $key => $default) {
            if (is_int($default)) {
                $result[$key] = self::getInt($key, $default);
            } elseif (is_float($default)) {
                $result[$key] = self::getFloat($key, $default);
            } elseif (is_bool($default)) {
                $result[$key] = self::getBool($key, $default);
            } elseif (is_array($default)) {
                $result[$key] = self::getArray($key, $default);
            } else {
                $result[$key] = self::getString($key, (string) $default);
            }
        }

        return $result;
    }

    // ===== Database settings persistence methods =====

    /**
     * Get a string from request, persisting to database settings.
     *
     * If the request parameter exists, saves it to database settings.
     * Otherwise returns the database value or default.
     *
     * @param string $reqKey  Request parameter key
     * @param string $dbKey   Database settings key for persistence
     * @param string $default Default value if neither exists
     *
     * @return string The current value
     */
    public static function getStringWithDb(
        string $reqKey,
        string $dbKey,
        string $default = ''
    ): string {
        // Import Settings class - it's already loaded via db_bootstrap.php
        $dbValue = \Lwt\Shared\Infrastructure\Database\Settings::get($dbKey);

        if (self::has($reqKey)) {
            $value = self::getString($reqKey);
            \Lwt\Shared\Infrastructure\Database\Settings::save($dbKey, $value);
            return $value;
        }

        if ($dbValue !== '') {
            return $dbValue;
        }

        return $default;
    }

    /**
     * Get an integer from request, persisting to database settings.
     *
     * If the request parameter exists, saves it to database settings.
     * Otherwise returns the database value or default.
     *
     * @param string $reqKey  Request parameter key
     * @param string $dbKey   Database settings key for persistence
     * @param int    $default Default value if neither exists
     *
     * @return int The current value
     */
    public static function getIntWithDb(
        string $reqKey,
        string $dbKey,
        int $default = 0
    ): int {
        $dbValue = \Lwt\Shared\Infrastructure\Database\Settings::get($dbKey);

        if (self::has($reqKey)) {
            $value = self::getInt($reqKey, $default);
            \Lwt\Shared\Infrastructure\Database\Settings::save($dbKey, (string) ($value ?? $default));
            return $value ?? $default;
        }

        if ($dbValue !== '' && is_numeric($dbValue)) {
            return (int) $dbValue;
        }

        return $default;
    }
}
