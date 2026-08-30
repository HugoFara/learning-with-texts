<?php

/**
 * Database Exception
 *
 * PHP version 8.2
 *
 * @category Lwt
 * @package  Lwt\Shared\Infrastructure\Exception
 * @author   HugoFara <git@hugofara.net>
 * @license  Unlicense <http://unlicense.org/>
 * @link     https://hugofara.github.io/lwt/developer/api
 * @since    3.0.0
 */

declare(strict_types=1);

namespace Lwt\Shared\Infrastructure\Exception;

use Throwable;

/**
 * Exception thrown for database-related errors.
 *
 * Covers connection failures, query errors, transaction issues,
 * and constraint violations.
 *
 * @since 3.0.0
 */
class DatabaseException extends LwtException
{
    /**
     * The SQL query that caused the error (if available).
     *
     * @var string|null
     */
    protected ?string $query = null;

    /**
     * The MySQL/MariaDB error code.
     *
     * @var int|null
     */
    protected ?int $sqlErrorCode = null;

    /**
     * The SQL state (5-character code).
     *
     * @var string|null
     */
    protected ?string $sqlState = null;

    /**
     * Create a new database exception.
     *
     * @param string         $message      The exception message
     * @param int            $code         The exception code (default: 0)
     * @param Throwable|null $previous     The previous exception for chaining
     * @param string|null    $query        The SQL query that caused the error
     * @param int|null       $sqlErrorCode The MySQL error code
     * @param string|null    $sqlState     The SQL state
     */
    public function __construct(
        string $message = '',
        int $code = 0,
        ?Throwable $previous = null,
        ?string $query = null,
        ?int $sqlErrorCode = null,
        ?string $sqlState = null
    ) {
        parent::__construct($message, $code, $previous);
        $this->query = $query;
        $this->sqlErrorCode = $sqlErrorCode;
        $this->sqlState = $sqlState;
        $this->httpStatusCode = 500;

        // Add SQL-specific context
        if ($query !== null) {
            $this->context['query'] = $this->sanitizeQuery($query);
        }
        if ($sqlErrorCode !== null) {
            $this->context['sql_error_code'] = $sqlErrorCode;
        }
        if ($sqlState !== null) {
            $this->context['sql_state'] = $sqlState;
        }
    }

    /**
     * Create exception for connection failure.
     *
     * @param string         $host     Database host
     * @param string         $database Database name
     * @param string         $error    Error message from driver
     * @param Throwable|null $previous Previous exception
     *
     * @return self
     */
    public static function connectionFailed(
        string $host,
        string $database,
        string $error,
        ?Throwable $previous = null
    ): self {
        $exception = new self(
            sprintf(
                'Failed to connect to database "%s" on host "%s": %s',
                $database,
                $host,
                $error
            ),
            0,
            $previous
        );
        $exception->context['host'] = $host;
        $exception->context['database'] = $database;
        return $exception;
    }

    /**
     * Create exception for query execution failure.
     *
     * @param string         $query        The failed query
     * @param string         $error        Error message from driver
     * @param int|null       $sqlErrorCode MySQL error code
     * @param string|null    $sqlState     SQL state
     * @param Throwable|null $previous     Previous exception
     *
     * @return self
     */
    public static function queryFailed(
        string $query,
        string $error,
        ?int $sqlErrorCode = null,
        ?string $sqlState = null,
        ?Throwable $previous = null
    ): self {
        return new self(
            sprintf('Query failed: %s', $error),
            0,
            $previous,
            $query,
            $sqlErrorCode,
            $sqlState
        );
    }

    /**
     * Create exception for prepared statement failure.
     *
     * @param string         $query    The failed query
     * @param string         $error    Error message
     * @param Throwable|null $previous Previous exception
     *
     * @return self
     */
    public static function prepareFailed(
        string $query,
        string $error,
        ?Throwable $previous = null
    ): self {
        return new self(
            sprintf('Failed to prepare statement: %s', $error),
            0,
            $previous,
            $query
        );
    }

    /**
     * Create exception for transaction failure.
     *
     * @param string         $operation Transaction operation (begin, commit, rollback)
     * @param string         $error     Error message
     * @param Throwable|null $previous  Previous exception
     *
     * @return self
     */
    public static function transactionFailed(
        string $operation,
        string $error,
        ?Throwable $previous = null
    ): self {
        $exception = new self(
            sprintf('Transaction %s failed: %s', $operation, $error),
            0,
            $previous
        );
        $exception->context['transaction_operation'] = $operation;
        return $exception;
    }

    /**
     * Create exception for foreign key constraint violation.
     *
     * @param string         $table       Table with the constraint
     * @param string         $constraint  Constraint name
     * @param string         $error       Error message
     * @param Throwable|null $previous    Previous exception
     *
     * @return self
     */
    public static function foreignKeyViolation(
        string $table,
        string $constraint,
        string $error,
        ?Throwable $previous = null
    ): self {
        $exception = new self(
            sprintf(
                'Foreign key constraint "%s" violated on table "%s": %s',
                $constraint,
                $table,
                $error
            ),
            0,
            $previous
        );
        $exception->context['table'] = $table;
        $exception->context['constraint'] = $constraint;
        $exception->httpStatusCode = 409; // Conflict
        return $exception;
    }

    /**
     * Create exception for unique constraint violation.
     *
     * @param string         $table    Table with the constraint
     * @param string         $column   Column with duplicate value
     * @param mixed          $value    The duplicate value
     * @param Throwable|null $previous Previous exception
     *
     * @return self
     */
    public static function duplicateEntry(
        string $table,
        string $column,
        mixed $value,
        ?Throwable $previous = null
    ): self {
        $exception = new self(
            sprintf(
                'Duplicate entry for "%s" in table "%s"',
                $column,
                $table
            ),
            0,
            $previous
        );
        $exception->context['table'] = $table;
        $exception->context['column'] = $column;
        $exception->context['value'] = $value;
        $exception->httpStatusCode = 409; // Conflict
        return $exception;
    }

    /**
     * Create exception for record not found.
     *
     * @param string     $table Table name
     * @param string     $key   Primary key column
     * @param int|string $id    The ID that was not found
     *
     * @return self
     */
    public static function recordNotFound(
        string $table,
        string $key,
        int|string $id
    ): self {
        $exception = new self(
            sprintf('Record with %s=%s not found in table "%s"', $key, $id, $table)
        );
        $exception->context['table'] = $table;
        $exception->context['key'] = $key;
        $exception->context['id'] = $id;
        $exception->httpStatusCode = 404;
        return $exception;
    }

    /**
     * Get the SQL query that caused the error (sanitized for security).
     *
     * Returns a sanitized version with string values masked to prevent
     * information disclosure. For the raw query (logging only), access
     * the protected $query property directly in subclasses.
     *
     * @return string|null The sanitized query or null
     */
    public function getQuery(): ?string
    {
        return $this->query !== null ? $this->sanitizeQuery($this->query) : null;
    }

    /**
     * Get the MySQL/MariaDB error code.
     *
     * @return int|null
     */
    public function getSqlErrorCode(): ?int
    {
        return $this->sqlErrorCode;
    }

    /**
     * Get the SQL state.
     *
     * @return string|null
     */
    public function getSqlState(): ?string
    {
        return $this->sqlState;
    }

    /**
     * {@inheritDoc}
     */
    public function getUserMessage(): string
    {
        return 'A database error occurred. Please try again later.';
    }

    /**
     * Sanitize a query for logging (remove sensitive data).
     *
     * Truncates very long queries and masks potential passwords.
     *
     * @param string $query The query to sanitize
     *
     * @return string
     */
    private function sanitizeQuery(string $query): string
    {
        // Truncate very long queries
        if (strlen($query) > 1000) {
            $query = substr($query, 0, 1000) . '... [TRUNCATED]';
        }

        // Mask potential password values (basic pattern matching)
        $query = preg_replace(
            "/(['\"])([^'\"]{0,100})\\1/",
            "'***'",
            $query
        ) ?? $query;

        return $query;
    }
}
