<?php

/**
 * Base LWT Exception
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

use RuntimeException;
use Throwable;

/**
 * Base exception class for all LWT-specific exceptions.
 *
 * Provides common functionality for exception handling including:
 * - Structured context data for logging
 * - HTTP status code mapping
 * - User-friendly message generation
 *
 * @since 3.0.0
 */
class LwtException extends RuntimeException
{
    /**
     * Additional context data for logging/debugging.
     *
     * @var array<string, mixed>
     */
    protected array $context = [];

    /**
     * HTTP status code to return (for web responses).
     *
     * @var int
     */
    protected int $httpStatusCode = 500;

    /**
     * Whether this exception should be logged.
     *
     * @var bool
     */
    protected bool $shouldLog = true;

    /**
     * Create a new LWT exception.
     *
     * @param string         $message  The exception message
     * @param int            $code     The exception code (default: 0)
     * @param Throwable|null $previous The previous exception for chaining
     * @param array<string, mixed> $context  Additional context data
     */
    public function __construct(
        string $message = '',
        int $code = 0,
        ?Throwable $previous = null,
        array $context = []
    ) {
        parent::__construct($message, $code, $previous);
        $this->context = $context;
    }

    /**
     * Get the context data for this exception.
     *
     * @return array<string, mixed>
     */
    public function getContext(): array
    {
        return $this->context;
    }

    /**
     * Add context data to the exception.
     *
     * @param string $key   Context key
     * @param mixed  $value Context value
     *
     * @return self
     */
    public function withContext(string $key, mixed $value): self
    {
        $this->context[$key] = $value;
        return $this;
    }

    /**
     * Get the HTTP status code for this exception.
     *
     * @return int
     */
    public function getHttpStatusCode(): int
    {
        return $this->httpStatusCode;
    }

    /**
     * Set the HTTP status code.
     *
     * @param int $code HTTP status code
     *
     * @return self
     */
    public function setHttpStatusCode(int $code): self
    {
        $this->httpStatusCode = $code;
        return $this;
    }

    /**
     * Whether this exception should be logged.
     *
     * @return bool
     */
    public function shouldLog(): bool
    {
        return $this->shouldLog;
    }

    /**
     * Get a user-friendly message for display.
     *
     * Override in subclasses to provide sanitized messages safe for users.
     *
     * @return string
     */
    public function getUserMessage(): string
    {
        return 'An unexpected error occurred. Please try again later.';
    }

    /**
     * Convert exception to array for logging/JSON responses.
     *
     * @param bool $includeTrace Whether to include stack trace
     *
     * @return array<string, mixed>
     */
    public function toArray(bool $includeTrace = false): array
    {
        $data = [
            'type' => static::class,
            'message' => $this->getMessage(),
            'code' => $this->getCode(),
            'file' => $this->getFile(),
            'line' => $this->getLine(),
            'context' => $this->context,
            'http_status' => $this->httpStatusCode,
        ];

        if ($includeTrace) {
            $data['trace'] = $this->getTraceAsString();
        }

        if ($this->getPrevious() !== null) {
            $data['previous'] = [
                'type' => get_class($this->getPrevious()),
                'message' => $this->getPrevious()->getMessage(),
            ];
        }

        return $data;
    }
}
