<?php

/**
 * User Repository Interface
 *
 * Domain port for user persistence operations.
 *
 * PHP version 8.2
 *
 * @category Lwt
 * @package  Lwt\Modules\User\Domain
 * @author   HugoFara <git@hugofara.net>
 * @license  Unlicense <http://unlicense.org/>
 * @link     https://hugofara.github.io/lwt/developer/api
 * @since    3.0.0
 */

declare(strict_types=1);

namespace Lwt\Modules\User\Domain;

use DateTimeImmutable;
use Lwt\Modules\User\Domain\User;

/**
 * Repository interface for User entity.
 *
 * Defines the contract for user persistence operations.
 * Implementations may use different storage backends (MySQL, memory, etc.)
 *
 * @since 3.0.0
 */
interface UserRepositoryInterface
{
    /**
     * Find a user by ID.
     *
     * @param int $id User ID
     *
     * @return User|null
     */
    public function find(int $id): ?User;

    /**
     * Save a user entity (create or update).
     *
     * @param User $entity The user to save
     *
     * @return int The user ID
     */
    public function save(User $entity): int;

    /**
     * Delete a user by ID.
     *
     * @param int $id User ID
     *
     * @return bool True if deleted
     */
    public function delete(int $id): bool;

    /**
     * Check if a user exists.
     *
     * @param int $id User ID
     *
     * @return bool
     */
    public function exists(int $id): bool;

    /**
     * Find a user by username.
     *
     * @param string $username The username
     *
     * @return User|null
     */
    public function findByUsername(string $username): ?User;

    /**
     * Find a user by email.
     *
     * @param string $email The email address
     *
     * @return User|null
     */
    public function findByEmail(string $email): ?User;

    /**
     * Find a user by API token.
     *
     * @param string $token The API token
     *
     * @return User|null
     */
    public function findByApiToken(string $token): ?User;

    /**
     * Find a user by remember-me token.
     *
     * @param string $token The remember token
     *
     * @return User|null
     */
    public function findByRememberToken(string $token): ?User;

    /**
     * Find a user by WordPress ID.
     *
     * @param int $wordPressId The WordPress user ID
     *
     * @return User|null
     */
    public function findByWordPressId(int $wordPressId): ?User;

    /**
     * Find a user by Google ID.
     *
     * @param string $googleId The Google user ID
     *
     * @return User|null
     */
    public function findByGoogleId(string $googleId): ?User;

    /**
     * Find a user by Microsoft ID.
     *
     * @param string $microsoftId The Microsoft user ID
     *
     * @return User|null
     */
    public function findByMicrosoftId(string $microsoftId): ?User;

    /**
     * Check if a username exists.
     *
     * @param string   $username  Username to check
     * @param int|null $excludeId User ID to exclude (for updates)
     *
     * @return bool
     */
    public function usernameExists(string $username, ?int $excludeId = null): bool;

    /**
     * Check if an email exists.
     *
     * @param string   $email     Email to check
     * @param int|null $excludeId User ID to exclude (for updates)
     *
     * @return bool
     */
    public function emailExists(string $email, ?int $excludeId = null): bool;

    /**
     * Find all active users.
     *
     * @return User[]
     */
    public function findActive(): array;

    /**
     * Update the last login timestamp.
     *
     * @param int $userId User ID
     *
     * @return bool True if updated
     */
    public function updateLastLogin(int $userId): bool;

    /**
     * Update the password hash.
     *
     * @param int    $userId       User ID
     * @param string $passwordHash New password hash
     *
     * @return bool True if updated
     */
    public function updatePassword(int $userId, string $passwordHash): bool;

    /**
     * Update the API token.
     *
     * @param int                    $userId  User ID
     * @param string|null            $token   API token (null to clear)
     * @param DateTimeImmutable|null $expires Token expiration
     *
     * @return bool True if updated
     */
    public function updateApiToken(int $userId, ?string $token, ?DateTimeImmutable $expires): bool;

    /**
     * Update the remember-me token.
     *
     * @param int                    $userId  User ID
     * @param string|null            $token   Remember token (null to clear)
     * @param DateTimeImmutable|null $expires Token expiration
     *
     * @return bool True if updated
     */
    public function updateRememberToken(int $userId, ?string $token, ?DateTimeImmutable $expires): bool;

    /**
     * Find a user by password reset token.
     *
     * @param string $token The password reset token (hashed)
     *
     * @return User|null
     */
    public function findByPasswordResetToken(string $token): ?User;

    /**
     * Update the password reset token.
     *
     * @param int                    $userId  User ID
     * @param string|null            $token   Password reset token (null to clear)
     * @param DateTimeImmutable|null $expires Token expiration
     *
     * @return bool True if updated
     */
    public function updatePasswordResetToken(int $userId, ?string $token, ?DateTimeImmutable $expires): bool;

    /**
     * Activate a user account.
     *
     * @param int $userId User ID
     *
     * @return bool True if updated
     */
    public function activate(int $userId): bool;

    /**
     * Deactivate a user account.
     *
     * @param int $userId User ID
     *
     * @return bool True if updated
     */
    public function deactivate(int $userId): bool;

    /**
     * Get users formatted for select dropdown options.
     *
     * @param int $maxNameLength Maximum username length before truncation
     *
     * @return array<int, array{id: int, username: string, email: string}>
     */
    public function getForSelect(int $maxNameLength = 40): array;

    /**
     * Find a user by email verification token.
     *
     * @param string $token The verification token (hashed)
     *
     * @return User|null
     */
    public function findByEmailVerificationToken(string $token): ?User;

    /**
     * Count the number of admin users with a valid (non-null) password.
     *
     * Used for first-admin bootstrap: the migration seed admin has
     * a NULL password and should not count.
     *
     * @return int
     */
    public function countAdmins(): int;

    /**
     * Get basic user info (minimal data for lists).
     *
     * @param int $userId User ID
     *
     * @return array{id: int, username: string, email: string, is_active: bool, is_admin: bool}|null
     */
    public function getBasicInfo(int $userId): ?array;
}
