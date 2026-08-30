<?php

/**
 * Password Hasher Service
 *
 * Wrapper around PasswordService for the User module.
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
 * Service for password hashing and verification.
 *
 * This is a thin wrapper around PasswordService to provide
 * a convenient interface for common password operations.
 *
 * @since 3.0.0
 */
class PasswordHasher
{
    /**
     * Underlying password service.
     *
     * @var PasswordService
     */
    private PasswordService $service;

    /**
     * Create a new PasswordHasher.
     *
     * @param PasswordService|null $service Optional password service
     */
    public function __construct(?PasswordService $service = null)
    {
        $this->service = $service ?? new PasswordService();
    }

    /**
     * Hash a password.
     *
     * @param string $password The plain-text password
     *
     * @return string The hashed password
     */
    public function hash(string $password): string
    {
        return $this->service->hash($password);
    }

    /**
     * Verify a password against a hash.
     *
     * @param string $password The plain-text password
     * @param string $hash     The hash to verify against
     *
     * @return bool True if the password matches
     */
    public function verify(string $password, string $hash): bool
    {
        return $this->service->verify($password, $hash);
    }

    /**
     * Check if a hash needs to be rehashed.
     *
     * @param string $hash The hash to check
     *
     * @return bool True if rehash is needed
     */
    public function needsRehash(string $hash): bool
    {
        return $this->service->needsRehash($hash);
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
        return $this->service->validateStrength($password);
    }

    /**
     * Generate a secure random token.
     *
     * @param int<1, max> $length Token length in bytes
     *
     * @return string The generated token
     */
    public function generateToken(int $length = 32): string
    {
        return $this->service->generateToken($length);
    }
}
