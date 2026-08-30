<?php

/**
 * Password Service - Password hashing and verification
 *
 * PHP version 8.2
 *
 * @category Lwt
 * @package  Lwt\Modules\User\Application\Services
 * @author   HugoFara <git@hugofara.net>
 * @license  Unlicense <http://unlicense.org/>
 * @link     https://hugofara.github.io/lwt/developer/api
 * @since    3.0.0
 */

declare(strict_types=1);

namespace Lwt\Modules\User\Application\Services;

/**
 * Service class for password hashing and verification.
 *
 * Uses PHP's password_hash() with Argon2ID algorithm (preferred)
 * or bcrypt as fallback for older PHP versions.
 *
 * @category Lwt
 * @package  Lwt\Modules\User\Application\Services
 * @author   HugoFara <git@hugofara.net>
 * @license  Unlicense <http://unlicense.org/>
 * @link     https://hugofara.github.io/lwt/developer/api
 * @since    3.0.0
 */
class PasswordService
{
    /**
     * Default algorithm for password hashing.
     *
     * Argon2ID is the recommended algorithm (PHP 7.3+).
     * Falls back to bcrypt if Argon2ID is not available.
     */
    private const PREFERRED_ALGORITHM = PASSWORD_ARGON2ID;
    private const FALLBACK_ALGORITHM = PASSWORD_BCRYPT;

    /**
     * Argon2ID options.
     *
     * These are conservative defaults that balance security with performance.
     *
     * @var array<string, int>
     */
    private const ARGON2_OPTIONS = [
        'memory_cost' => 65536,  // 64 MB
        'time_cost' => 4,        // 4 iterations
        'threads' => 3,          // 3 parallel threads
    ];

    /**
     * Bcrypt options (fallback).
     *
     * @var array<string, int>
     */
    private const BCRYPT_OPTIONS = [
        'cost' => 12,
    ];

    /**
     * The algorithm to use for hashing.
     *
     * @var string|int
     */
    private string|int $algorithm;

    /**
     * Options for the hashing algorithm.
     *
     * @var array<string, int>
     */
    private array $options;

    /**
     * Create a new PasswordService.
     *
     * Automatically selects the best available algorithm.
     */
    public function __construct()
    {
        if (defined('PASSWORD_ARGON2ID')) {
            $this->algorithm = self::PREFERRED_ALGORITHM;
            $this->options = self::ARGON2_OPTIONS;
        } else {
            $this->algorithm = self::FALLBACK_ALGORITHM;
            $this->options = self::BCRYPT_OPTIONS;
        }
    }

    /**
     * Hash a password using the configured algorithm.
     *
     * @param string $password The plain-text password to hash
     *
     * @return string The hashed password
     *
     * @throws \RuntimeException If password hashing fails
     */
    public function hash(string $password): string
    {
        $hash = password_hash($password, $this->algorithm, $this->options);

        // Note: password_hash returns string|false in PHP < 8.0, but always string in PHP 8.0+
        // We keep this check for documentation purposes
        /** @var string $hash */
        return $hash;
    }

    /**
     * Verify a password against a hash.
     *
     * @param string $password The plain-text password to verify
     * @param string $hash     The hash to verify against
     *
     * @return bool True if the password matches the hash
     */
    public function verify(string $password, string $hash): bool
    {
        return password_verify($password, $hash);
    }

    /**
     * Check if a hash needs to be rehashed.
     *
     * This should be called after successful verification to determine
     * if the hash should be updated (e.g., if algorithm options changed).
     *
     * @param string $hash The hash to check
     *
     * @return bool True if the hash should be rehashed
     */
    public function needsRehash(string $hash): bool
    {
        return password_needs_rehash($hash, $this->algorithm, $this->options);
    }

    /**
     * Get information about a password hash.
     *
     * Useful for debugging and migration purposes.
     *
     * @param string $hash The hash to get info about
     *
     * @return array{algo: int|string|null, algoName: string, options: array<string, int>}
     */
    public function getHashInfo(string $hash): array
    {
        /** @var array{algo: int|string|null, algoName: string, options: array<string, int>} */
        return password_get_info($hash);
    }

    /**
     * Validate password strength.
     *
     * @param string $password The password to validate
     *
     * @return array{valid: bool, errors: string[]} Validation result
     */
    public function validateStrength(string $password): array
    {
        $errors = [];

        if (strlen($password) < 8) {
            $errors[] = 'Password must be at least 8 characters long';
        }

        if (strlen($password) > 128) {
            $errors[] = 'Password cannot exceed 128 characters';
        }

        // Check for at least one letter
        if (!preg_match('/[a-zA-Z]/', $password)) {
            $errors[] = 'Password must contain at least one letter';
        }

        // Check for at least one number
        if (!preg_match('/[0-9]/', $password)) {
            $errors[] = 'Password must contain at least one number';
        }

        return [
            'valid' => empty($errors),
            'errors' => $errors,
        ];
    }

    /**
     * Generate a secure random token.
     *
     * Useful for password reset tokens, API tokens, etc.
     *
     * @param int<1, max> $length The length of the token in bytes (will be hex-encoded to 2x length)
     *
     * @return string The generated token
     *
     * @throws \Exception If random bytes generation fails
     */
    public function generateToken(int $length = 32): string
    {
        return bin2hex(random_bytes($length));
    }
}
