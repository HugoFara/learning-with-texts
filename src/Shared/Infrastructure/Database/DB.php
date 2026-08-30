<?php

/**
 * \file
 * \brief Database facade for simplified database operations.
 *
 * PHP version 8.2
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

use Lwt\Shared\Infrastructure\Database\PreparedStatement;

/**
 * Database facade providing a simplified interface for common operations.
 *
 * This is the main entry point for database operations in LWT 3.0+.
 *
 * Usage:
 * ```php
 * use Lwt\Shared\Infrastructure\Database\DB;
 *
 * // Query builder
 * $words = DB::table('words')->where('WoLgID', 1)->get();
 *
 * // Raw queries
 * $result = DB::query('SELECT * FROM words WHERE WoID = 1');
 * $rows = DB::fetchAll('SELECT * FROM words LIMIT 10');
 * $row = DB::fetchOne('SELECT * FROM words WHERE WoID = 1');
 * $value = DB::fetchValue('SELECT COUNT(*) AS cnt FROM words', 'cnt');
 *
 * // Execute non-SELECT queries
 * $affected = DB::execute('UPDATE words SET WoStatus = 2 WHERE WoID = 1');
 *
 * // Escaping
 * $safe = DB::escape($userInput);
 * $quoted = DB::escapeString($userInput);  // Returns 'escaped_value'
 * $nullable = DB::escapeOrNull($userInput); // Returns 'value' or NULL
 * ```
 *
 * @since 3.0.0
 */
class DB
{
    /**
     * Start a query builder for a table.
     *
     * @param string $tableName The table name (without prefix)
     *
     * @return QueryBuilder
     */
    public static function table(string $tableName): QueryBuilder
    {
        return QueryBuilder::table($tableName);
    }

    /**
     * Execute a raw SQL query.
     *
     * @param string $sql The SQL query to execute
     *
     * @return \mysqli_result|true Query result or true for non-SELECT queries
     */
    public static function query(string $sql): \mysqli_result|bool
    {
        return Connection::query($sql);
    }

    /**
     * Execute a query and return all rows.
     *
     * @param string $sql The SQL query to execute
     *
     * @return (float|int|null|string)[][] Array of associative arrays
     *
     * @psalm-return list<non-empty-array<string, float|int|null|string>>
     */
    public static function fetchAll(string $sql): array
    {
        return Connection::fetchAll($sql);
    }

    /**
     * Execute a query and return the first row.
     *
     * @param string $sql The SQL query to execute
     *
     * @return (float|int|null|string)[]|null The first row or null
     *
     * @psalm-return array<string, float|int|null|string>|null
     */
    public static function fetchOne(string $sql): array|null
    {
        return Connection::fetchOne($sql);
    }

    /**
     * Execute a query and return a single value.
     *
     * @param string $sql    The SQL query to execute
     * @param string $column The column name to retrieve (default: 'value')
     *
     * @return mixed The value or null
     */
    public static function fetchValue(string $sql, string $column = 'value'): mixed
    {
        return Connection::fetchValue($sql, $column);
    }

    /**
     * Execute an INSERT/UPDATE/DELETE query.
     *
     * @param string $sql The SQL query to execute
     *
     * @return int Number of affected rows
     */
    public static function execute(string $sql): int
    {
        return (int) Connection::execute($sql);
    }

    /**
     * Get the last inserted ID.
     *
     * @return int|string The last insert ID
     */
    public static function lastInsertId(): int|string
    {
        return Connection::lastInsertId();
    }

    /**
     * Escape a value for use in SQL.
     *
     * @param string $value The value to escape
     *
     * @return string The escaped string (without quotes)
     */
    public static function escape(string $value): string
    {
        return Connection::escape($value);
    }

    /**
     * Escape and quote a string for SQL.
     *
     * @param string $value The value to escape
     *
     * @return string The escaped and quoted string
     */
    public static function escapeString(string $value): string
    {
        return Connection::escapeString($value);
    }

    /**
     * Escape and quote a string, returning 'NULL' for empty strings.
     *
     * @param string $value The value to escape
     *
     * @return string The escaped and quoted string, or 'NULL'
     */
    public static function escapeOrNull(string $value): string
    {
        return Connection::escapeOrNull($value);
    }

    /**
     * Get the raw mysqli connection.
     *
     * For backward compatibility and advanced operations.
     *
     * @return \mysqli The database connection
     */
    public static function connection(): \mysqli
    {
        return Connection::getInstance();
    }

    /**
     * Begin a transaction.
     *
     * @return bool True on success
     */
    public static function beginTransaction(): bool
    {
        return mysqli_begin_transaction(Connection::getInstance());
    }

    /**
     * Commit a transaction.
     *
     * @return bool True on success
     */
    public static function commit(): bool
    {
        return mysqli_commit(Connection::getInstance());
    }

    /**
     * Rollback a transaction.
     *
     * @return bool True on success
     */
    public static function rollback(): bool
    {
        return mysqli_rollback(Connection::getInstance());
    }

    /**
     * Create a prepared statement.
     *
     * @param string $sql The SQL query with ? placeholders
     *
     * @return PreparedStatement The prepared statement wrapper
     */
    public static function prepare(string $sql): PreparedStatement
    {
        return Connection::prepare($sql);
    }

    /**
     * Execute a parameterized query and return all rows.
     *
     * @param string             $sql    The SQL query with ? placeholders
     * @param array<int, mixed>  $params Parameters to bind
     *
     * @return array<int, array<string, mixed>> Array of rows
     */
    public static function preparedFetchAll(string $sql, array $params = []): array
    {
        return Connection::preparedFetchAll($sql, $params);
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
        return Connection::preparedFetchOne($sql, $params);
    }

    /**
     * Execute a parameterized query and return a single value.
     *
     * @param string             $sql    The SQL query with ? placeholders
     * @param array<int, mixed>  $params Parameters to bind
     * @param string             $column Column name to retrieve
     *
     * @return mixed The value or null
     */
    public static function preparedFetchValue(string $sql, array $params = [], string $column = 'value'): mixed
    {
        return Connection::preparedFetchValue($sql, $params, $column);
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
        return Connection::preparedExecute($sql, $params);
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
        return Connection::preparedInsert($sql, $params);
    }
}
