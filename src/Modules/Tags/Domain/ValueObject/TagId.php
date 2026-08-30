<?php

/**
 * Tag ID Value Object
 *
 * PHP version 8.2
 *
 * @category Lwt
 * @package  Lwt\Modules\Tags\Domain\ValueObject
 * @author   HugoFara <git@hugofara.net>
 * @license  Unlicense <http://unlicense.org/>
 * @link     https://hugofara.github.io/lwt/developer/api
 * @since    3.0.0
 */

declare(strict_types=1);

namespace Lwt\Modules\Tags\Domain\ValueObject;

use InvalidArgumentException;

/**
 * Value object representing a Tag's unique identifier.
 *
 * Immutable and self-validating. A value of 0 indicates an unsaved entity.
 *
 * @since 3.0.0
 */
final readonly class TagId
{
    /**
     * @param int $value The tag ID value (0 for unsaved, positive for persisted)
     */
    private function __construct(private int $value)
    {
    }

    /**
     * Create from an existing database ID.
     *
     * @param int $id The database ID (must be positive)
     *
     * @return self
     *
     * @throws InvalidArgumentException If ID is not positive
     */
    public static function fromInt(int $id): self
    {
        if ($id <= 0) {
            throw new InvalidArgumentException('Tag ID must be positive, got: ' . $id);
        }
        return new self($id);
    }

    /**
     * Create a new ID for an unsaved entity.
     *
     * @return self
     */
    public static function new(): self
    {
        return new self(0);
    }

    /**
     * Get the integer value.
     *
     * @return int
     */
    public function toInt(): int
    {
        return $this->value;
    }

    /**
     * Check if this represents an unsaved entity.
     *
     * @return bool
     */
    public function isNew(): bool
    {
        return $this->value === 0;
    }

    /**
     * Check equality with another TagId.
     *
     * @param TagId $other The other TagId to compare
     *
     * @return bool
     */
    public function equals(TagId $other): bool
    {
        return $this->value === $other->value;
    }

    /**
     * String representation for debugging.
     *
     * @return string
     */
    public function __toString(): string
    {
        return (string) $this->value;
    }
}
