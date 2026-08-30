<?php

/**
 * \file
 * \brief Prepared statement wrapper class for safe parameterized queries.
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

use Lwt\Shared\Infrastructure\Exception\DatabaseException;

/**
 * Wrapper class for mysqli prepared statements.
 *
 * Provides a fluent interface for executing parameterized queries safely.
 *
 * Usage:
 * ```php
 * // Simple query with parameters
 * $stmt = Connection::prepare('SELECT * FROM words WHERE WoLgID = ? AND WoStatus = ?');
 * $rows = $stmt->bind('ii', $langId, $status)->fetchAll();
 *
 * // Insert with parameters
 * $stmt = Connection::prepare('INSERT INTO words (WoText, WoLgID) VALUES (?, ?)');
 * $stmt->bind('si', $text, $langId)->execute();
 * $insertId = $stmt->insertId();
 *
 * // Update with parameters
 * $stmt = Connection::prepare('UPDATE words SET WoStatus = ? WHERE WoID = ?');
 * $affected = $stmt->bind('ii', $status, $wordId)->execute();
 * ```
 *
 * @since 3.0.0
 */
class PreparedStatement
{
    /**
     * @var \mysqli_stmt The underlying mysqli statement
     */
    private \mysqli_stmt $stmt;

    /**
     * @var \mysqli The database connection
     */
    private \mysqli $connection;

    /**
     * @var string The original SQL query (for error messages)
     */
    private string $sql;

    /**
     * Create a new prepared statement wrapper.
     *
     * @param \mysqli $connection The database connection
     * @param string  $sql        The SQL query with placeholders
     *
     * @throws DatabaseException If the statement cannot be prepared
     */
    public function __construct(\mysqli $connection, string $sql)
    {
        $this->connection = $connection;
        $this->sql = $sql;

        $stmt = $connection->prepare($sql);
        if ($stmt === false) {
            throw DatabaseException::prepareFailed(
                $sql,
                $connection->error
            );
        }

        $this->stmt = $stmt;
    }

    /**
     * @var list<int|float|string|null> Bound parameters for execution
     */
    private array $boundParams = [];

    /**
     * Normalize mixed params array to list of scalars.
     *
     * @param array<int, mixed> $params
     * @return list<int|float|string|null>
     */
    private static function normalizeParams(array $params): array
    {
        $result = [];
        /** @var mixed $value */
        foreach (array_values($params) as $value) {
            // Convert to scalar or null - non-scalars become their string representation
            if ($value === null || is_int($value) || is_float($value) || is_string($value)) {
                $result[] = $value;
            } elseif (is_bool($value)) {
                $result[] = $value ? 1 : 0;
            } else {
                $result[] = (string)$value;
            }
        }
        return $result;
    }

    /**
     * Bind parameters to the prepared statement.
     *
     * Uses PHP 8.1's execute() with params array instead of bind_param()
     * to avoid reference-related issues.
     *
     * @param string $types  Type string (i=integer, d=double, s=string, b=blob) - kept for API compatibility
     * @param int|float|string|null  ...$params Parameters to bind
     *
     * @return $this For method chaining
     *
     * @throws \RuntimeException If parameter count doesn't match
     */
    public function bind(string $types, int|float|string|null ...$params): static
    {
        if (strlen($types) !== count($params)) {
            throw new \RuntimeException(
                'Type string length (' . strlen($types) . ') does not match ' .
                'parameter count (' . count($params) . ')'
            );
        }

        $this->boundParams = array_values($params);

        return $this;
    }

    /**
     * Bind parameters using an associative array.
     *
     * Uses PHP 8.1's execute() with params array instead of bind_param().
     *
     * @param array<int, mixed> $params Parameters to bind (indexed array)
     *
     * @return $this For method chaining
     */
    public function bindValues(array $params): static
    {
        $this->boundParams = self::normalizeParams($params);
        return $this;
    }

    /**
     * Execute the prepared statement.
     *
     * Uses PHP 8.1's execute() with params array.
     *
     * @return int Number of affected rows (for INSERT/UPDATE/DELETE), -1 on error
     *
     * @throws DatabaseException If execution fails
     */
    public function execute(): int
    {
        $params = empty($this->boundParams) ? null : $this->boundParams;
        if (!$this->stmt->execute($params)) {
            throw new DatabaseException(
                'Failed to execute statement: ' . $this->stmt->error,
                0,
                null,
                $this->sql,
                $this->stmt->errno
            );
        }

        return (int) $this->stmt->affected_rows;
    }

    /**
     * Execute and fetch all rows as an associative array.
     *
     * Uses PHP 8.1's execute() with params array.
     *
     * @return array<int, array<string, mixed>> Array of rows
     */
    public function fetchAll(): array
    {
        $params = empty($this->boundParams) ? null : $this->boundParams;
        if (!$this->stmt->execute($params)) {
            throw new DatabaseException(
                'Failed to execute statement: ' . $this->stmt->error,
                0,
                null,
                $this->sql,
                $this->stmt->errno
            );
        }

        $result = $this->stmt->get_result();
        if ($result === false) {
            // For queries that don't return a result set
            return [];
        }

        $rows = [];
        while ($row = $result->fetch_assoc()) {
            $rows[] = $row;
        }
        $result->free();

        return $rows;
    }

    /**
     * Execute and fetch the first row.
     *
     * Uses PHP 8.1's execute() with params array.
     *
     * @return array<string, mixed>|null The first row or null if no results
     */
    public function fetchOne(): ?array
    {
        $params = empty($this->boundParams) ? null : $this->boundParams;
        if (!$this->stmt->execute($params)) {
            throw new DatabaseException(
                'Failed to execute statement: ' . $this->stmt->error,
                0,
                null,
                $this->sql,
                $this->stmt->errno
            );
        }

        $result = $this->stmt->get_result();
        if ($result === false) {
            return null;
        }

        $row = $result->fetch_assoc();
        $result->free();

        return $row !== false ? $row : null;
    }

    /**
     * Execute and fetch a single column value from the first row.
     *
     * @param string $column The column name to retrieve
     *
     * @return mixed The value or null if not found
     */
    public function fetchValue(string $column = 'value'): mixed
    {
        $row = $this->fetchOne();

        if ($row === null || !array_key_exists($column, $row)) {
            return null;
        }

        return $row[$column];
    }

    /**
     * Execute and fetch a single column from all rows.
     *
     * @param string $column The column name to retrieve
     *
     * @return array<int, mixed> Array of values
     */
    public function fetchColumn(string $column): array
    {
        $rows = $this->fetchAll();

        return array_column($rows, $column);
    }

    /**
     * Get the ID generated by the last INSERT query.
     *
     * @return int|string The insert ID
     */
    public function insertId(): int|string
    {
        return $this->stmt->insert_id;
    }

    /**
     * Get the number of affected rows from the last query.
     *
     * @return int Number of affected rows, -1 on error
     */
    public function affectedRows(): int
    {
        return (int) $this->stmt->affected_rows;
    }

    /**
     * Get the number of rows in the result set.
     *
     * Note: This only works after fetchAll() has been called,
     * or after execute() for queries that return a result.
     *
     * @return int Number of rows (0 or more)
     */
    public function numRows(): int
    {
        return (int) $this->stmt->num_rows;
    }

    /**
     * Close the prepared statement.
     *
     * @return void
     */
    public function close(): void
    {
        $this->stmt->close();
    }

    /**
     * Get the underlying mysqli_stmt object.
     *
     * For advanced operations not covered by this wrapper.
     *
     * @return \mysqli_stmt The underlying statement
     */
    public function getStatement(): \mysqli_stmt
    {
        return $this->stmt;
    }

    /**
     * Destructor - close the statement when done.
     */
    public function __destruct()
    {
        // Only close if the connection is still valid
        // thread_id will be 0/falsy if the connection has been closed
        try {
            if ($this->connection->thread_id) {
                $this->stmt->close();
            }
        } catch (\Throwable $e) {
            // Connection may have been closed already - ignore
        }
    }
}
