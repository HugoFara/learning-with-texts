<?php

/**
 * MySQL User Repository
 *
 * Infrastructure adapter for user persistence using MySQL.
 *
 * PHP version 8.2
 *
 * @category Lwt
 * @package  Lwt\Modules\User\Infrastructure
 * @author   HugoFara <git@hugofara.net>
 * @license  Unlicense <http://unlicense.org/>
 * @link     https://hugofara.github.io/lwt/developer/api
 * @since    3.0.0
 */

declare(strict_types=1);

namespace Lwt\Modules\User\Infrastructure;

use DateTimeImmutable;
use Lwt\Modules\User\Domain\User;
use Lwt\Shared\Domain\ValueObjects\UserId;
use Lwt\Shared\Infrastructure\Database\Connection;
use Lwt\Shared\Infrastructure\Database\QueryBuilder;
use Lwt\Modules\User\Domain\UserRepositoryInterface;

/**
 * MySQL implementation of UserRepositoryInterface.
 *
 * Provides database access for user management operations.
 * Handles authentication lookups and user CRUD.
 *
 * @since 3.0.0
 */
class MySqlUserRepository implements UserRepositoryInterface
{
    /**
     * @var string Table name without prefix
     */
    private string $tableName = 'users';

    /**
     * @var string Primary key column
     */
    private string $primaryKey = 'UsID';

    /**
     * Get a query builder for this repository's table.
     *
     * @return QueryBuilder
     */
    private function query(): QueryBuilder
    {
        return QueryBuilder::table($this->tableName);
    }

    /**
     * Map a database row to a User entity.
     *
     * @param array<string, mixed> $row Database row
     *
     * @return User
     */
    private function mapToEntity(array $row): User
    {
        $user = User::reconstitute(
            (int) $row['UsID'],
            (string) $row['UsUsername'],
            ($row['UsEmail'] ?? null) !== null ? (string) $row['UsEmail'] : null,
            $row['UsPasswordHash'] !== null ? (string) $row['UsPasswordHash'] : null,
            $row['UsApiToken'] !== null ? (string) $row['UsApiToken'] : null,
            $this->parseNullableDateTime($this->getNullableString($row['UsApiTokenExpires'] ?? null)),
            ($row['UsRememberToken'] ?? null) !== null ? (string) $row['UsRememberToken'] : null,
            $this->parseNullableDateTime($this->getNullableString($row['UsRememberTokenExpires'] ?? null)),
            ($row['UsPasswordResetToken'] ?? null) !== null ? (string) $row['UsPasswordResetToken'] : null,
            $this->parseNullableDateTime($this->getNullableString($row['UsPasswordResetTokenExpires'] ?? null)),
            $this->parseNullableDateTime($this->getNullableString($row['UsEmailVerifiedAt'] ?? null)),
            ($row['UsEmailVerificationToken'] ?? null) !== null ? (string) $row['UsEmailVerificationToken'] : null,
            $this->parseNullableDateTime($this->getNullableString($row['UsEmailVerificationTokenExpires'] ?? null)),
            $row['UsWordPressId'] !== null ? (int) $row['UsWordPressId'] : null,
            ($row['UsGoogleId'] ?? null) !== null ? (string) $row['UsGoogleId'] : null,
            ($row['UsMicrosoftId'] ?? null) !== null ? (string) $row['UsMicrosoftId'] : null,
            $this->parseDateTime($this->getNullableString($row['UsCreated'] ?? null)),
            $this->parseNullableDateTime($this->getNullableString($row['UsLastLogin'] ?? null)),
            (bool) ($row['UsIsActive'] ?? true),
            (string) ($row['UsRole'] ?? User::ROLE_USER)
        );

        // Recovery code is hydrated separately so it stays out of the (already
        // long) reconstitute() signature.
        $user->setRecoveryCodeHash(
            ($row['UsRecoveryCodeHash'] ?? null) !== null ? (string) $row['UsRecoveryCodeHash'] : null
        );

        return $user;
    }

    /**
     * Map a User entity to database row.
     *
     * @param User $entity The user entity
     *
     * @return array<string, scalar|null>
     */
    private function mapToRow(User $entity): array
    {
        return [
            'UsID' => $entity->id()->toInt(),
            'UsUsername' => $entity->username(),
            'UsEmail' => $entity->email(),
            'UsPasswordHash' => $entity->passwordHash(),
            'UsApiToken' => $entity->apiToken(),
            'UsApiTokenExpires' => $entity->apiTokenExpires()?->format('Y-m-d H:i:s'),
            'UsRememberToken' => $entity->rememberToken(),
            'UsRememberTokenExpires' => $entity->rememberTokenExpires()?->format('Y-m-d H:i:s'),
            'UsPasswordResetToken' => $entity->passwordResetToken(),
            'UsPasswordResetTokenExpires' => $entity->passwordResetTokenExpires()?->format('Y-m-d H:i:s'),
            'UsEmailVerifiedAt' => $entity->emailVerifiedAt()?->format('Y-m-d H:i:s'),
            'UsEmailVerificationToken' => $entity->emailVerificationToken(),
            'UsEmailVerificationTokenExpires' => $entity->emailVerificationTokenExpires()?->format('Y-m-d H:i:s'),
            'UsWordPressId' => $entity->wordPressId(),
            'UsGoogleId' => $entity->googleId(),
            'UsMicrosoftId' => $entity->microsoftId(),
            'UsCreated' => $entity->created()->format('Y-m-d H:i:s'),
            'UsLastLogin' => $entity->lastLogin()?->format('Y-m-d H:i:s'),
            'UsIsActive' => $entity->isActive() ? 1 : 0,
            'UsRole' => $entity->role(),
            'UsRecoveryCodeHash' => $entity->recoveryCodeHash(),
        ];
    }

    /**
     * Parse a datetime string into DateTimeImmutable.
     *
     * @param string|null $datetime The datetime string
     *
     * @return DateTimeImmutable
     */
    private function parseDateTime(?string $datetime): DateTimeImmutable
    {
        if ($datetime === null || $datetime === '' || $datetime === '0000-00-00 00:00:00') {
            return new DateTimeImmutable();
        }
        return new DateTimeImmutable($datetime);
    }

    /**
     * Get a nullable string from a mixed value.
     *
     * @param mixed $value The value to convert
     *
     * @return string|null
     */
    private function getNullableString(mixed $value): ?string
    {
        return $value !== null ? (string) $value : null;
    }

    /**
     * Parse a nullable datetime string.
     *
     * @param string|null $datetime The datetime string
     *
     * @return DateTimeImmutable|null
     */
    private function parseNullableDateTime(?string $datetime): ?DateTimeImmutable
    {
        if ($datetime === null || $datetime === '' || $datetime === '0000-00-00 00:00:00') {
            return null;
        }
        return new DateTimeImmutable($datetime);
    }

    /**
     * {@inheritdoc}
     */
    public function find(int $id): ?User
    {
        $row = $this->query()
            ->where($this->primaryKey, '=', $id)
            ->firstPrepared();

        if ($row === null) {
            return null;
        }

        return $this->mapToEntity($row);
    }

    /**
     * {@inheritdoc}
     */
    public function save(User $entity): int
    {
        $data = $this->mapToRow($entity);
        $id = $entity->id()->toInt();

        if ($id > 0 && !$entity->id()->isNew()) {
            // Update existing
            $this->query()
                ->where($this->primaryKey, '=', $id)
                ->updatePrepared($data);
            return $id;
        }

        // Insert new
        $insertData = $data;
        unset($insertData[$this->primaryKey]);

        $newId = (int) $this->query()->insertPrepared($insertData);
        $entity->setId(UserId::fromInt($newId));

        return $newId;
    }

    /**
     * {@inheritdoc}
     */
    public function delete(int $id): bool
    {
        if ($id <= 0) {
            return false;
        }

        $affected = $this->query()
            ->where($this->primaryKey, '=', $id)
            ->deletePrepared();

        return $affected > 0;
    }

    /**
     * {@inheritdoc}
     */
    public function exists(int $id): bool
    {
        return $this->query()
            ->where($this->primaryKey, '=', $id)
            ->existsPrepared();
    }

    /**
     * {@inheritdoc}
     */
    public function findByUsername(string $username): ?User
    {
        $row = $this->query()
            ->where('UsUsername', '=', $username)
            ->firstPrepared();

        return $row !== null ? $this->mapToEntity($row) : null;
    }

    /**
     * {@inheritdoc}
     */
    public function findByEmail(string $email): ?User
    {
        $row = $this->query()
            ->where('UsEmail', '=', strtolower($email))
            ->firstPrepared();

        return $row !== null ? $this->mapToEntity($row) : null;
    }

    /**
     * {@inheritdoc}
     */
    public function findByApiToken(string $token): ?User
    {
        $row = $this->query()
            ->where('UsApiToken', '=', $token)
            ->firstPrepared();

        return $row !== null ? $this->mapToEntity($row) : null;
    }

    /**
     * {@inheritdoc}
     */
    public function findByRememberToken(string $token): ?User
    {
        $row = $this->query()
            ->where('UsRememberToken', '=', $token)
            ->firstPrepared();

        return $row !== null ? $this->mapToEntity($row) : null;
    }

    /**
     * {@inheritdoc}
     */
    public function findByWordPressId(int $wordPressId): ?User
    {
        $row = $this->query()
            ->where('UsWordPressId', '=', $wordPressId)
            ->firstPrepared();

        return $row !== null ? $this->mapToEntity($row) : null;
    }

    /**
     * {@inheritdoc}
     */
    public function findByGoogleId(string $googleId): ?User
    {
        $row = $this->query()
            ->where('UsGoogleId', '=', $googleId)
            ->firstPrepared();

        return $row !== null ? $this->mapToEntity($row) : null;
    }

    /**
     * {@inheritdoc}
     */
    public function findByMicrosoftId(string $microsoftId): ?User
    {
        $row = $this->query()
            ->where('UsMicrosoftId', '=', $microsoftId)
            ->firstPrepared();

        return $row !== null ? $this->mapToEntity($row) : null;
    }

    /**
     * {@inheritdoc}
     */
    public function usernameExists(string $username, ?int $excludeId = null): bool
    {
        $query = $this->query()
            ->where('UsUsername', '=', $username);

        if ($excludeId !== null) {
            $query->where('UsID', '!=', $excludeId);
        }

        return $query->existsPrepared();
    }

    /**
     * {@inheritdoc}
     */
    public function emailExists(string $email, ?int $excludeId = null): bool
    {
        $query = $this->query()
            ->where('UsEmail', '=', strtolower($email));

        if ($excludeId !== null) {
            $query->where('UsID', '!=', $excludeId);
        }

        return $query->existsPrepared();
    }

    /**
     * {@inheritdoc}
     */
    public function findActive(): array
    {
        $rows = $this->query()
            ->where('UsIsActive', '=', 1)
            ->orderBy('UsUsername')
            ->getPrepared();

        return array_map(
            fn(array $row) => $this->mapToEntity($row),
            $rows
        );
    }

    /**
     * Find all inactive users.
     *
     * @return User[]
     */
    public function findInactive(): array
    {
        $rows = $this->query()
            ->where('UsIsActive', '=', 0)
            ->orderBy('UsUsername')
            ->getPrepared();

        return array_map(
            fn(array $row) => $this->mapToEntity($row),
            $rows
        );
    }

    /**
     * Find all admin users.
     *
     * @return User[]
     */
    public function findAdmins(): array
    {
        $rows = $this->query()
            ->where('UsRole', '=', User::ROLE_ADMIN)
            ->orderBy('UsUsername')
            ->getPrepared();

        return array_map(
            fn(array $row) => $this->mapToEntity($row),
            $rows
        );
    }

    /**
     * Find users linked to WordPress.
     *
     * @return User[]
     */
    public function findWordPressUsers(): array
    {
        $rows = $this->query()
            ->whereNotNull('UsWordPressId')
            ->orderBy('UsUsername')
            ->getPrepared();

        return array_map(
            fn(array $row) => $this->mapToEntity($row),
            $rows
        );
    }

    /**
     * Find users linked to Google.
     *
     * @return User[]
     */
    public function findGoogleUsers(): array
    {
        $rows = $this->query()
            ->whereNotNull('UsGoogleId')
            ->orderBy('UsUsername')
            ->getPrepared();

        return array_map(
            fn(array $row) => $this->mapToEntity($row),
            $rows
        );
    }

    /**
     * Find users linked to Microsoft.
     *
     * @return User[]
     */
    public function findMicrosoftUsers(): array
    {
        $rows = $this->query()
            ->whereNotNull('UsMicrosoftId')
            ->orderBy('UsUsername')
            ->getPrepared();

        return array_map(
            fn(array $row) => $this->mapToEntity($row),
            $rows
        );
    }

    /**
     * {@inheritdoc}
     */
    public function updateLastLogin(int $userId): bool
    {
        $affected = $this->query()
            ->where('UsID', '=', $userId)
            ->updatePrepared(['UsLastLogin' => date('Y-m-d H:i:s')]);

        return $affected > 0;
    }

    /**
     * {@inheritdoc}
     */
    public function updatePassword(int $userId, string $passwordHash): bool
    {
        $affected = $this->query()
            ->where('UsID', '=', $userId)
            ->updatePrepared(['UsPasswordHash' => $passwordHash]);

        return $affected > 0;
    }

    /**
     * {@inheritdoc}
     */
    public function updateApiToken(int $userId, ?string $token, ?DateTimeImmutable $expires): bool
    {
        $affected = $this->query()
            ->where('UsID', '=', $userId)
            ->updatePrepared([
                'UsApiToken' => $token,
                'UsApiTokenExpires' => $expires?->format('Y-m-d H:i:s'),
            ]);

        return $affected > 0;
    }

    /**
     * {@inheritdoc}
     */
    public function updateRememberToken(int $userId, ?string $token, ?DateTimeImmutable $expires): bool
    {
        $affected = $this->query()
            ->where('UsID', '=', $userId)
            ->updatePrepared([
                'UsRememberToken' => $token,
                'UsRememberTokenExpires' => $expires?->format('Y-m-d H:i:s'),
            ]);

        return $affected > 0;
    }

    /**
     * {@inheritdoc}
     */
    public function findByPasswordResetToken(string $token): ?User
    {
        $row = $this->query()
            ->where('UsPasswordResetToken', '=', $token)
            ->firstPrepared();

        return $row !== null ? $this->mapToEntity($row) : null;
    }

    /**
     * {@inheritdoc}
     */
    public function updatePasswordResetToken(int $userId, ?string $token, ?DateTimeImmutable $expires): bool
    {
        $affected = $this->query()
            ->where('UsID', '=', $userId)
            ->updatePrepared([
                'UsPasswordResetToken' => $token,
                'UsPasswordResetTokenExpires' => $expires?->format('Y-m-d H:i:s'),
            ]);

        return $affected > 0;
    }

    /**
     * {@inheritdoc}
     */
    public function findByEmailVerificationToken(string $token): ?User
    {
        $row = $this->query()
            ->where('UsEmailVerificationToken', '=', $token)
            ->firstPrepared();

        return $row !== null ? $this->mapToEntity($row) : null;
    }

    /**
     * {@inheritdoc}
     */
    public function activate(int $userId): bool
    {
        $affected = $this->query()
            ->where('UsID', '=', $userId)
            ->updatePrepared(['UsIsActive' => 1]);

        return $affected > 0;
    }

    /**
     * {@inheritdoc}
     */
    public function deactivate(int $userId): bool
    {
        $affected = $this->query()
            ->where('UsID', '=', $userId)
            ->updatePrepared(['UsIsActive' => 0]);

        return $affected > 0;
    }

    /**
     * Update user role.
     *
     * @param int    $userId User ID
     * @param string $role   New role (user or admin)
     *
     * @return bool True if updated
     */
    public function updateRole(int $userId, string $role): bool
    {
        $affected = $this->query()
            ->where('UsID', '=', $userId)
            ->updatePrepared(['UsRole' => $role]);

        return $affected > 0;
    }

    /**
     * Link user to WordPress account.
     *
     * @param int $userId      User ID
     * @param int $wordPressId WordPress user ID
     *
     * @return bool True if updated
     */
    public function linkWordPress(int $userId, int $wordPressId): bool
    {
        $affected = $this->query()
            ->where('UsID', '=', $userId)
            ->updatePrepared(['UsWordPressId' => $wordPressId]);

        return $affected > 0;
    }

    /**
     * Unlink user from WordPress account.
     *
     * @param int $userId User ID
     *
     * @return bool True if updated
     */
    public function unlinkWordPress(int $userId): bool
    {
        $affected = $this->query()
            ->where('UsID', '=', $userId)
            ->updatePrepared(['UsWordPressId' => null]);

        return $affected > 0;
    }

    /**
     * Link user to Google account.
     *
     * @param int    $userId   User ID
     * @param string $googleId Google user ID
     *
     * @return bool True if updated
     */
    public function linkGoogle(int $userId, string $googleId): bool
    {
        $affected = $this->query()
            ->where('UsID', '=', $userId)
            ->updatePrepared(['UsGoogleId' => $googleId]);

        return $affected > 0;
    }

    /**
     * Unlink user from Google account.
     *
     * @param int $userId User ID
     *
     * @return bool True if updated
     */
    public function unlinkGoogle(int $userId): bool
    {
        $affected = $this->query()
            ->where('UsID', '=', $userId)
            ->updatePrepared(['UsGoogleId' => null]);

        return $affected > 0;
    }

    /**
     * Link user to Microsoft account.
     *
     * @param int    $userId      User ID
     * @param string $microsoftId Microsoft user ID
     *
     * @return bool True if updated
     */
    public function linkMicrosoft(int $userId, string $microsoftId): bool
    {
        $affected = $this->query()
            ->where('UsID', '=', $userId)
            ->updatePrepared(['UsMicrosoftId' => $microsoftId]);

        return $affected > 0;
    }

    /**
     * Unlink user from Microsoft account.
     *
     * @param int $userId User ID
     *
     * @return bool True if updated
     */
    public function unlinkMicrosoft(int $userId): bool
    {
        $affected = $this->query()
            ->where('UsID', '=', $userId)
            ->updatePrepared(['UsMicrosoftId' => null]);

        return $affected > 0;
    }

    /**
     * {@inheritdoc}
     */
    public function getForSelect(int $maxNameLength = 40): array
    {
        $rows = $this->query()
            ->select(['UsID', 'UsUsername', 'UsEmail'])
            ->where('UsIsActive', '=', 1)
            ->orderBy('UsUsername')
            ->getPrepared();

        $result = [];

        foreach ($rows as $row) {
            $username = (string) $row['UsUsername'];
            if (mb_strlen($username, 'UTF-8') > $maxNameLength) {
                $username = mb_substr($username, 0, $maxNameLength, 'UTF-8') . '...';
            }
            $result[] = [
                'id' => (int) $row['UsID'],
                'username' => $username,
                'email' => (string) $row['UsEmail'],
            ];
        }

        return $result;
    }

    /**
     * {@inheritdoc}
     */
    public function getBasicInfo(int $userId): ?array
    {
        $row = $this->query()
            ->select([
                'UsID',
                'UsUsername',
                'UsEmail',
                'UsIsActive',
                'UsRole',
            ])
            ->where('UsID', '=', $userId)
            ->firstPrepared();

        if ($row === null) {
            return null;
        }

        return [
            'id' => (int) $row['UsID'],
            'username' => (string) $row['UsUsername'],
            'email' => (string) $row['UsEmail'],
            'is_active' => (bool) $row['UsIsActive'],
            'is_admin' => $row['UsRole'] === User::ROLE_ADMIN,
        ];
    }

    /**
     * Get users with pagination.
     *
     * @param int    $page      Page number (1-based)
     * @param int    $perPage   Items per page
     * @param string $orderBy   Column to order by
     * @param string $direction Sort direction
     *
     * @return array{items: User[], total: int, page: int, per_page: int, total_pages: int}
     */
    public function findPaginated(
        int $page = 1,
        int $perPage = 20,
        string $orderBy = 'UsUsername',
        string $direction = 'ASC'
    ): array {
        $query = $this->query();

        $total = (clone $query)->countPrepared();
        $totalPages = $total > 0 ? (int) ceil($total / $perPage) : 0;

        $page = max(1, min($page, max(1, $totalPages)));
        $offset = ($page - 1) * $perPage;

        $rows = $query
            ->orderBy($orderBy, $direction)
            ->limit($perPage)
            ->offset($offset)
            ->getPrepared();

        $items = array_map(
            fn(array $row) => $this->mapToEntity($row),
            $rows
        );

        return [
            'items' => $items,
            'total' => $total,
            'page' => $page,
            'per_page' => $perPage,
            'total_pages' => $totalPages,
        ];
    }

    /**
     * Search users by username or email.
     *
     * @param string $query Search query
     * @param int    $limit Maximum results
     *
     * @return User[]
     */
    public function search(string $query, int $limit = 50): array
    {
        $searchPattern = '%' . str_replace(['%', '_'], ['\\%', '\\_'], $query) . '%';

        $sql = "SELECT * FROM users WHERE (UsUsername LIKE ? OR UsEmail LIKE ?) ORDER BY UsUsername LIMIT ?";
        $rows = Connection::preparedFetchAll($sql, [$searchPattern, $searchPattern, $limit]);

        return array_map(
            fn(array $row) => $this->mapToEntity($row),
            $rows
        );
    }

    /**
     * Find users who logged in recently.
     *
     * @param int $days  Number of days to look back
     * @param int $limit Maximum results
     *
     * @return User[]
     */
    public function findRecentlyActive(int $days = 30, int $limit = 50): array
    {
        $timestamp = strtotime("-{$days} days");
        $sinceDate = date('Y-m-d H:i:s', $timestamp !== false ? $timestamp : time());

        $rows = $this->query()
            ->where('UsLastLogin', '>=', $sinceDate)
            ->orderBy('UsLastLogin', 'DESC')
            ->limit($limit)
            ->getPrepared();

        return array_map(
            fn(array $row) => $this->mapToEntity($row),
            $rows
        );
    }

    /**
     * Find users created recently.
     *
     * @param int $days  Number of days to look back
     * @param int $limit Maximum results
     *
     * @return User[]
     */
    public function findRecentlyCreated(int $days = 30, int $limit = 50): array
    {
        $timestamp = strtotime("-{$days} days");
        $sinceDate = date('Y-m-d H:i:s', $timestamp !== false ? $timestamp : time());

        $rows = $this->query()
            ->where('UsCreated', '>=', $sinceDate)
            ->orderBy('UsCreated', 'DESC')
            ->limit($limit)
            ->getPrepared();

        return array_map(
            fn(array $row) => $this->mapToEntity($row),
            $rows
        );
    }

    /**
     * Get user statistics.
     *
     * @return array{total: int, active: int, inactive: int, admins: int,
     *               wordpress_linked: int, google_linked: int, microsoft_linked: int}
     */
    public function getStatistics(): array
    {
        $baseQuery = $this->query();

        $total = (clone $baseQuery)->countPrepared();

        $active = (clone $baseQuery)
            ->where('UsIsActive', '=', 1)
            ->countPrepared();

        $inactive = (clone $baseQuery)
            ->where('UsIsActive', '=', 0)
            ->countPrepared();

        $admins = (clone $baseQuery)
            ->where('UsRole', '=', User::ROLE_ADMIN)
            ->countPrepared();

        $wordPressLinked = (clone $baseQuery)
            ->whereNotNull('UsWordPressId')
            ->countPrepared();

        $googleLinked = (clone $baseQuery)
            ->whereNotNull('UsGoogleId')
            ->countPrepared();

        $microsoftLinked = (clone $baseQuery)
            ->whereNotNull('UsMicrosoftId')
            ->countPrepared();

        return [
            'total' => $total,
            'active' => $active,
            'inactive' => $inactive,
            'admins' => $admins,
            'wordpress_linked' => $wordPressLinked,
            'google_linked' => $googleLinked,
            'microsoft_linked' => $microsoftLinked,
        ];
    }

    /**
     * Find users with expired API tokens.
     *
     * @return User[]
     */
    public function findWithExpiredApiTokens(): array
    {
        $now = date('Y-m-d H:i:s');

        $rows = $this->query()
            ->whereNotNull('UsApiToken')
            ->where('UsApiTokenExpires', '<', $now)
            ->getPrepared();

        return array_map(
            fn(array $row) => $this->mapToEntity($row),
            $rows
        );
    }

    /**
     * Clear expired API tokens.
     *
     * @return int Number of cleared tokens
     */
    public function clearExpiredApiTokens(): int
    {
        $now = date('Y-m-d H:i:s');

        return $this->query()
            ->whereNotNull('UsApiToken')
            ->where('UsApiTokenExpires', '<', $now)
            ->updatePrepared([
                'UsApiToken' => null,
                'UsApiTokenExpires' => null,
            ]);
    }

    /**
     * Delete multiple users by IDs.
     *
     * @param int[] $userIds Array of user IDs
     *
     * @return int Number of deleted users
     */
    public function deleteMultiple(array $userIds): int
    {
        if (empty($userIds)) {
            return 0;
        }

        return $this->query()
            ->whereIn('UsID', array_map('intval', $userIds))
            ->deletePrepared();
    }

    /**
     * {@inheritdoc}
     */
    public function countAdmins(): int
    {
        $sql = "SELECT COUNT(*) as cnt FROM users "
             . "WHERE UsRole = ? AND UsPasswordHash IS NOT NULL";
        $rows = Connection::preparedFetchAll($sql, [User::ROLE_ADMIN]);

        return (int) ($rows[0]['cnt'] ?? 0);
    }

    /**
     * Count users by role.
     *
     * @return array<string, int> Role => count
     */
    public function countByRole(): array
    {
        $sql = "SELECT UsRole, COUNT(*) as cnt FROM users GROUP BY UsRole";
        $rows = Connection::preparedFetchAll($sql, []);

        $counts = [];
        foreach ($rows as $row) {
            $counts[(string) $row['UsRole']] = (int) $row['cnt'];
        }

        return $counts;
    }

    /**
     * Count all users.
     *
     * @return int
     */
    public function count(): int
    {
        return $this->query()->countPrepared();
    }
}
