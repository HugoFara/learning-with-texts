<?php

/**
 * Token Hasher Service
 *
 * Provides secure hashing for API and remember-me tokens.
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
 * Service for hashing API and remember-me tokens.
 *
 * Uses SHA-256 for fast, secure token hashing. Unlike passwords which
 * need slow hashing (bcrypt), tokens are random and don't need protection
 * against dictionary attacks. SHA-256 provides secure one-way hashing
 * that's efficient for token validation.
 *
 * @since 3.0.0
 */
class TokenHasher
{
    /**
     * Hash a token for storage.
     *
     * @param string $token The plaintext token
     *
     * @return string The hashed token (64 hex characters)
     */
    public function hash(string $token): string
    {
        return hash('sha256', $token);
    }

    /**
     * Verify a token against a stored hash.
     *
     * @param string $token The plaintext token to verify
     * @param string $hash  The stored hash to verify against
     *
     * @return bool True if the token matches the hash
     */
    public function verify(string $token, string $hash): bool
    {
        $tokenHash = $this->hash($token);
        return hash_equals($hash, $tokenHash);
    }

    /**
     * Generate a secure random token.
     *
     * @param int<1, max> $length Token length in bytes (default 32 = 64 hex chars)
     *
     * @return string The generated token (hex encoded)
     */
    public function generate(int $length = 32): string
    {
        return bin2hex(random_bytes($length));
    }
}
