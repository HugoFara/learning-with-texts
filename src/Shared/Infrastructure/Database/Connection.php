<?php

/**
 * \file
 * \brief Database connection wrapper class.
 *
 * PHP version 8.1
 *
 * @category Database
 * @package  Lwt
 * @author   HugoFara <git@hugofara.net>
 * @license  Unlicense <http://unlicense.org/>
 * @link     https://hugofara.github.io/lwt/developer/api
 * @since    3.0.0
 */

declare(strict_types=1);

namespace Lwt\Shared\Infrastructure\Database;

use Lwt\Shared\Infrastructure\Exception\DatabaseException;
use Lwt\Shared\Infrastructure\Globals;
use Lwt\Shared\Infrastructure\Database\PreparedStatement;

/**
 * Database connection wrapper providing a clean interface for database operations.
 *
 * This class wraps mysqli and provides methods for common database operations.
 * It uses Globals internally for backward compatibility.
 *
 * @since 3.0.0
 */
class Connection
{
    /**
     * @var \mysqli|null The mysqli connection instance
     */
    private static ?\mysqli $instance = null;

    /**
     * @var array<string, bool> Memoised tableExists() answers, for this request
     */
    private static array $tableExists = [];

    /**
     * Get the database connection instance.
     *
     * @return \mysqli The database connection
     * @throws \RuntimeException If no connection is available
     */
    public static function getInstance(): \mysqli
    {
        // Always check Globals first to ensure we use the current connection
        // This is important for tests that set connection via Globals::setDbConnection()
        $globalConnection = Globals::getDbConnection();
        if ($globalConnection !== null) {
            self::$instance = $globalConnection;
        }

        if (self::$instance === null) {
            throw new \RuntimeException('Database connection not initialized');
        }

        return self::$instance;
    }

    /**
     * Check if the current connection is still alive.
     *
     * @return bool True if connection is alive, false otherwise
     */
    public static function isAlive(): bool
    {
        if (self::$instance === null) {
            return false;
        }

        return @self::$instance->ping();
    }

    /**
     * Set the database connection instance.
     *
     * @param \mysqli $connection The mysqli connection
     *
     * @return void
     */
    public static function setInstance(\mysqli $connection): void
    {
        self::$instance = $connection;
        Globals::setDbConnection($connection);
    }

    /**
     * Execute a raw SQL query.
     *
     * @param string $sql The SQL query to execute
     *
     * @return \mysqli_result|true Query result or true for non-SELECT queries
     *
     * @throws DatabaseException On query failure
     */
    public static function query(string $sql): \mysqli_result|bool
    {
        $connection = self::getInstance();
        $result = mysqli_query($connection, $sql);

        if ($result === false) {
            throw DatabaseException::queryFailed(
                $sql,
                mysqli_error($connection),
                mysqli_errno($connection)
            );
        }

        return $result;
    }

    /**
     * Execute a SELECT query and return the result set.
     *
     * Use this instead of query() when you know the query returns a result set
     * (SELECT, SHOW, DESCRIBE, EXPLAIN). This provides better type safety.
     *
     * @param string $sql The SELECT query to execute
     *
     * @return \mysqli_result Query result set
     *
     * @throws DatabaseException On query failure or if query doesn't return a result set
     */
    public static function querySelect(string $sql): \mysqli_result
    {
        $result = self::query($sql);

        if ($result === true) {
            throw new DatabaseException(
                'Query did not return a result set. Use query() for INSERT/UPDATE/DELETE.',
                0,
                null,
                $sql
            );
        }

        return $result;
    }

    /**
     * Execute a query and return all rows as an array.
     *
     * @param string $sql The SQL query to execute
     *
     * @return (float|int|null|string)[][]
     *
     * @psalm-return list<non-empty-array<string, float|int|null|string>>
     */
    public static function fetchAll(string $sql): array
    {
        $result = self::query($sql);

        if ($result === true) {
            return [];
        }

        $rows = [];
        while ($row = mysqli_fetch_assoc($result)) {
            $rows[] = $row;
        }
        mysqli_free_result($result);

        return $rows;
    }

    /**
     * Execute a query and return the first row.
     *
     * @param string $sql The SQL query to execute
     *
     * @return (float|int|null|string)[]|null
     *
     * @psalm-return array<string, float|int|null|string>|null
     */
    public static function fetchOne(string $sql): array|null
    {
        $result = self::query($sql);

        if ($result === true) {
            return null;
        }

        $row = mysqli_fetch_assoc($result);
        mysqli_free_result($result);

        return ($row !== false) ? $row : null;
    }

    /**
     * Execute a query and return a single value from the first row.
     *
     * @param string $sql    The SQL query to execute
     * @param string $column The column name to retrieve (default: 'value')
     *
     * @return mixed The value or null if not found
     */
    public static function fetchValue(string $sql, string $column = 'value'): mixed
    {
        $row = self::fetchOne($sql);

        if ($row === null || !array_key_exists($column, $row)) {
            return null;
        }

        return $row[$column];
    }

    /**
     * Execute an INSERT/UPDATE/DELETE query and return affected rows or a message.
     *
     * @param string      $sql     The SQL query to execute
     * @param string|null $message Optional message to return instead of affected rows count
     *
     * @return int|string Number of affected rows, or the message string if provided
     *
     * @psalm-return int<-1, max>|string
     */
    public static function execute(string $sql, ?string $message = null): int|string
    {
        self::query($sql);
        $affectedRows = mysqli_affected_rows(self::getInstance());

        if ($message !== null) {
            return $message;
        }

        return $affectedRows;
    }

    /**
     * Get the last inserted ID.
     *
     * @return int|string The last insert ID
     */
    public static function lastInsertId(): int|string
    {
        return mysqli_insert_id(self::getInstance());
    }

    /**
     * Escape a string for use in SQL queries.
     *
     * @param string $value The value to escape
     *
     * @return string The escaped string
     */
    public static function escape(string $value): string
    {
        $escaped = mysqli_real_escape_string(self::getInstance(), $value);
        // mysqli_real_escape_string always returns string, but Psalm's stubs are incomplete
        assert(is_string($escaped));
        return $escaped;
    }

    /**
     * Escape and quote a string for SQL, returning 'NULL' for empty strings.
     *
     * @param string $value The value to escape
     *
     * @return string The escaped and quoted string, or 'NULL'
     */
    public static function escapeOrNull(string $value): string
    {
        $value = trim(str_replace("\r\n", "\n", $value));
        if ($value === '') {
            return 'NULL';
        }
        return "'" . self::escape($value) . "'";
    }

    /**
     * Escape and quote a string for SQL (never returns NULL).
     *
     * @param string $value The value to escape
     *
     * @return string The escaped and quoted string
     */
    public static function escapeString(string $value): string
    {
        $value = trim(str_replace("\r\n", "\n", $value));
        return "'" . self::escape($value) . "'";
    }

    /**
     * Reset the connection instance (primarily for testing).
     *
     * @return void
     */
    public static function reset(): void
    {
        self::$instance = null;
        self::$tableExists = [];
    }

    /**
     * Whether a table is present in the current database.
     *
     * For code that has to keep working against a schema where a migration did
     * not run. A table named in raw SQL cannot be guarded by COALESCE or a LEFT
     * JOIN: MySQL resolves names at prepare time, so a missing table fails the
     * whole statement before any row is read. Callers ask this first and emit
     * different SQL when the table is absent.
     *
     * The answer is memoised for the request. Migrations run during bootstrap,
     * ahead of routing, and clear the cache when they finish, so a table
     * created during the request is still seen by the code that reads it.
     *
     * @param string $table Table name, as it appears in the SQL
     *
     * @return bool True when the table exists
     */
    public static function tableExists(string $table): bool
    {
        if (isset(self::$tableExists[$table])) {
            return self::$tableExists[$table];
        }

        /** @var int|string|null $found */
        $found = self::preparedFetchValue(
            'SELECT COUNT(*) AS value FROM information_schema.TABLES
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?',
            [$table]
        );

        self::$tableExists[$table] = ((int) $found) > 0;

        return self::$tableExists[$table];
    }

    /**
     * Forget memoised table lookups, after work that may have created tables.
     *
     * @return void
     */
    public static function forgetTableCache(): void
    {
        self::$tableExists = [];
    }

    /**
     * Create a prepared statement.
     *
     * @param string $sql The SQL query with ? placeholders
     *
     * @return PreparedStatement The prepared statement wrapper
     *
     * @throws \RuntimeException If preparation fails
     */
    public static function prepare(string $sql): PreparedStatement
    {
        return new PreparedStatement(self::getInstance(), $sql);
    }

    /**
     * Execute a parameterized query and return all rows.
     *
     * This is a convenience method combining prepare(), bind(), and fetchAll().
     *
     * @param string             $sql    The SQL query with ? placeholders
     * @param array<int, mixed>  $params Parameters to bind (indexed array)
     *
     * @return array<int, array<string, mixed>> Array of rows
     */
    public static function preparedFetchAll(string $sql, array $params = []): array
    {
        $stmt = self::prepare($sql);
        if (!empty($params)) {
            $stmt->bindValues($params);
        }
        return $stmt->fetchAll();
    }

    /**
     * Execute a parameterized query and return the first row.
     *
     * @param string             $sql    The SQL query with ? placeholders
     * @param array<int, mixed>  $params Parameters to bind
     *
     * @return array<string, mixed>|null The first row or null
     */
    public static function preparedFetchOne(string $sql, array $params = []): ?array
    {
        $stmt = self::prepare($sql);
        if (!empty($params)) {
            $stmt->bindValues($params);
        }
        return $stmt->fetchOne();
    }

    /**
     * Execute a parameterized query and return a single value.
     *
     * @param string             $sql    The SQL query with ? placeholders
     * @param array<int, mixed>  $params Parameters to bind
     * @param string             $column Column name to retrieve (default: 'value')
     *
     * @return mixed The value or null
     */
    public static function preparedFetchValue(string $sql, array $params = [], string $column = 'value'): mixed
    {
        $stmt = self::prepare($sql);
        if (!empty($params)) {
            $stmt->bindValues($params);
        }
        return $stmt->fetchValue($column);
    }

    /**
     * Execute a parameterized INSERT/UPDATE/DELETE query.
     *
     * @param string             $sql    The SQL query with ? placeholders
     * @param array<int, mixed>  $params Parameters to bind
     *
     * @return int Number of affected rows
     */
    public static function preparedExecute(string $sql, array $params = []): int
    {
        $stmt = self::prepare($sql);
        if (!empty($params)) {
            $stmt->bindValues($params);
        }
        return $stmt->execute();
    }

    /**
     * Execute a parameterized INSERT and return the insert ID.
     *
     * @param string             $sql    The SQL query with ? placeholders
     * @param array<int, mixed>  $params Parameters to bind
     *
     * @return int|string The last insert ID
     */
    public static function preparedInsert(string $sql, array $params = []): int|string
    {
        $stmt = self::prepare($sql);
        if (!empty($params)) {
            $stmt->bindValues($params);
        }
        $stmt->execute();
        return $stmt->insertId();
    }

    /**
     * Build a safe SQL IN clause from an array of integer IDs.
     *
     * Each value is strictly cast to int to prevent SQL injection.
     * Returns a string like "(1,2,3)" suitable for use in SQL IN clauses.
     *
     * @param int[] $ids Array of integer IDs
     *
     * @return string SQL IN clause, e.g., "(1,2,3)" or "()" if empty
     */
    public static function buildIntInClause(array $ids): string
    {
        if (empty($ids)) {
            return '()';
        }

        $safeIds = array_map('intval', $ids);

        return '(' . implode(',', $safeIds) . ')';
    }

    /**
     * Build a prepared-statement SQL IN clause from an array of integer IDs.
     *
     * Returns a placeholder string like "(?,?,?)" and appends the int-cast
     * values to the provided bindings array. For empty arrays, returns
     * "(NULL)" as a safe no-match sentinel.
     *
     * @param int[]             $ids      Array of integer IDs
     * @param array<int, mixed> &$bindings Reference to bindings array
     *
     * @return string SQL IN clause, e.g., "(?,?,?)" or "(NULL)" if empty
     */
    public static function buildPreparedInClause(array $ids, array &$bindings): string
    {
        if (empty($ids)) {
            return '(NULL)';
        }

        $placeholders = [];
        foreach ($ids as $id) {
            $bindings[] = $id;
            $placeholders[] = '?';
        }

        return '(' . implode(',', $placeholders) . ')';
    }
}
