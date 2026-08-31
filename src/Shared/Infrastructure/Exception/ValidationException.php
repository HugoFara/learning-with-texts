<?php

/**
 * Validation Exception
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
 * Exception thrown for input validation errors.
 *
 * Supports both single-field and multi-field validation errors
 * with structured error messages for API responses.
 *
 * @since 3.0.0
 */
class ValidationException extends LwtException
{
    /**
     * Validation errors by field.
     *
     * @var array<string, string[]>
     */
    protected array $errors = [];

    /**
     * The field that caused the validation error (for single-field errors).
     *
     * @var string|null
     */
    protected ?string $field = null;

    /**
     * Create a new validation exception.
     *
     * @param string                   $message  The exception message
     * @param array<string, string[]>  $errors   Validation errors by field
     * @param int                      $code     The exception code
     * @param Throwable|null           $previous Previous exception
     */
    public function __construct(
        string $message = 'Validation failed',
        array $errors = [],
        int $code = 0,
        ?Throwable $previous = null
    ) {
        parent::__construct($message, $code, $previous);
        $this->errors = $errors;
        $this->httpStatusCode = 422; // Unprocessable Entity
        $this->shouldLog = false; // Validation errors typically don't need logging

        if (!empty($errors)) {
            $this->context['errors'] = $errors;
        }
    }

    /**
     * Create exception for a single field validation error.
     *
     * @param string $field   Field name
     * @param string $message Error message
     * @param mixed  $value   The invalid value (optional, for context)
     *
     * @return self
     */
    public static function forField(
        string $field,
        string $message,
        mixed $value = null
    ): self {
        $exception = new self(
            sprintf('Validation failed for field "%s": %s', $field, $message),
            [$field => [$message]]
        );
        $exception->field = $field;
        if ($value !== null) {
            $exception->context['value'] = $value;
        }
        return $exception;
    }

    /**
     * Create exception for a required field that is missing.
     *
     * @param string $field Field name
     *
     * @return self
     */
    public static function requiredField(string $field): self
    {
        return self::forField($field, 'This field is required');
    }

    /**
     * Create exception for an invalid type.
     *
     * @param string $field    Field name
     * @param string $expected Expected type
     * @param string $actual   Actual type received
     *
     * @return self
     */
    public static function invalidType(
        string $field,
        string $expected,
        string $actual
    ): self {
        return self::forField(
            $field,
            sprintf('Expected %s, got %s', $expected, $actual)
        );
    }

    /**
     * Create exception for a value out of allowed range.
     *
     * @param string   $field Field name
     * @param int|null $min   Minimum allowed value
     * @param int|null $max   Maximum allowed value
     * @param mixed    $value The actual value
     *
     * @return self
     */
    public static function outOfRange(
        string $field,
        ?int $min,
        ?int $max,
        mixed $value
    ): self {
        if ($min !== null && $max !== null) {
            $message = sprintf('Value must be between %d and %d', $min, $max);
        } elseif ($min !== null) {
            $message = sprintf('Value must be at least %d', $min);
        } elseif ($max !== null) {
            $message = sprintf('Value must be at most %d', $max);
        } else {
            $message = 'Value is out of range';
        }

        return self::forField($field, $message, $value);
    }

    /**
     * Create exception for string length violation.
     *
     * @param string   $field     Field name
     * @param int|null $minLength Minimum length
     * @param int|null $maxLength Maximum length
     * @param int      $actual    Actual length
     *
     * @return self
     */
    public static function invalidLength(
        string $field,
        ?int $minLength,
        ?int $maxLength,
        int $actual
    ): self {
        if ($minLength !== null && $maxLength !== null) {
            $message = sprintf(
                'Length must be between %d and %d characters (got %d)',
                $minLength,
                $maxLength,
                $actual
            );
        } elseif ($minLength !== null) {
            $message = sprintf(
                'Length must be at least %d characters (got %d)',
                $minLength,
                $actual
            );
        } elseif ($maxLength !== null) {
            $message = sprintf(
                'Length must be at most %d characters (got %d)',
                $maxLength,
                $actual
            );
        } else {
            $message = 'Invalid length';
        }

        return self::forField($field, $message);
    }

    /**
     * Create exception for invalid enum value.
     *
     * @param string        $field   Field name
     * @param array<scalar> $allowed Allowed values
     * @param mixed         $value   The invalid value
     *
     * @return self
     */
    public static function invalidEnum(
        string $field,
        array $allowed,
        mixed $value
    ): self {
        $allowedStr = implode(', ', array_map(
            fn($v) => is_string($v) ? "\"$v\"" : (string)$v,
            $allowed
        ));
        return self::forField(
            $field,
            sprintf('Value must be one of: %s', $allowedStr),
            $value
        );
    }

    /**
     * Create exception for invalid URL.
     *
     * @param string $field Field name
     * @param string $value The invalid URL
     *
     * @return self
     */
    public static function invalidUrl(string $field, string $value): self
    {
        return self::forField($field, 'Invalid URL format', $value);
    }

    /**
     * Create exception for invalid email.
     *
     * @param string $field Field name
     * @param string $value The invalid email
     *
     * @return self
     */
    public static function invalidEmail(string $field, string $value): self
    {
        return self::forField($field, 'Invalid email format', $value);
    }

    /**
     * Create exception for entity not found during validation.
     *
     * @param string     $field      Field name
     * @param string     $entityType Entity type (e.g., "language", "text")
     * @param int|string $id         The ID that was not found
     *
     * @return self
     */
    public static function entityNotFound(
        string $field,
        string $entityType,
        int|string $id
    ): self {
        $exception = self::forField(
            $field,
            sprintf('%s with ID %s not found', ucfirst($entityType), $id)
        );
        $exception->httpStatusCode = 404;
        return $exception;
    }

    /**
     * Create exception for multiple validation errors.
     *
     * @param array<string, string[]> $errors Errors by field
     *
     * @return self
     */
    public static function withErrors(array $errors): self
    {
        $count = array_sum(array_map('count', $errors));
        return new self(
            sprintf('Validation failed with %d error(s)', $count),
            $errors
        );
    }

    /**
     * Get all validation errors.
     *
     * @return array<string, string[]>
     */
    public function getErrors(): array
    {
        return $this->errors;
    }

    /**
     * Get errors for a specific field.
     *
     * @param string $field Field name
     *
     * @return string[]
     */
    public function getFieldErrors(string $field): array
    {
        return $this->errors[$field] ?? [];
    }

    /**
     * Check if a field has errors.
     *
     * @param string $field Field name
     *
     * @return bool
     */
    public function hasFieldError(string $field): bool
    {
        return isset($this->errors[$field]) && !empty($this->errors[$field]);
    }

    /**
     * Get the field name (for single-field errors).
     *
     * @return string|null
     */
    public function getField(): ?string
    {
        return $this->field;
    }

    /**
     * Add an error for a field.
     *
     * @param string $field   Field name
     * @param string $message Error message
     *
     * @return self
     */
    public function addError(string $field, string $message): self
    {
        if (!isset($this->errors[$field])) {
            $this->errors[$field] = [];
        }
        $this->errors[$field][] = $message;
        $this->context['errors'] = $this->errors;
        return $this;
    }

    /**
     * Get the first error message.
     *
     * @return string|null
     */
    public function getFirstError(): ?string
    {
        foreach ($this->errors as $fieldErrors) {
            if (!empty($fieldErrors)) {
                return $fieldErrors[0];
            }
        }
        return null;
    }

    /**
     * {@inheritDoc}
     */
    public function getUserMessage(): string
    {
        $first = $this->getFirstError();
        return $first ?? 'Validation failed. Please check your input.';
    }

    /**
     * Convert to array format suitable for JSON API responses.
     *
     * @return array{message: string, errors: array<string, string[]>}
     */
    public function toApiResponse(): array
    {
        return [
            'message' => $this->getMessage(),
            'errors' => $this->errors,
        ];
    }
}
