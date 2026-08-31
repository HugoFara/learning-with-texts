<?php

/**
 * LocalDictionary Entity
 *
 * PHP version 8.2
 *
 * @category Lwt
 * @package  Lwt\Entity
 * @author   HugoFara <git@hugofara.net>
 * @license  Unlicense <http://unlicense.org/>
 * @link     https://hugofara.github.io/lwt/developer/api
 * @since    3.0.0
 */

declare(strict_types=1);

namespace Lwt\Modules\Dictionary\Domain;

use DateTimeImmutable;
use InvalidArgumentException;

/**
 * A local dictionary represented as a domain object.
 *
 * Local dictionaries store imported dictionary data for offline lookups.
 * They are associated with a language and can have multiple entries.
 *
 * @since 3.0.0
 */
class LocalDictionary
{
    private ?int $id;
    private int $languageId;
    private string $name;
    private ?string $description;
    private string $sourceFormat;
    private int $entryCount;
    private int $priority;
    private bool $enabled;
    private DateTimeImmutable $created;
    private ?int $userId;

    /**
     * Private constructor - use factory methods instead.
     */
    private function __construct(
        ?int $id,
        int $languageId,
        string $name,
        ?string $description,
        string $sourceFormat,
        int $entryCount,
        int $priority,
        bool $enabled,
        DateTimeImmutable $created,
        ?int $userId
    ) {
        $this->id = $id;
        $this->languageId = $languageId;
        $this->name = $name;
        $this->description = $description;
        $this->sourceFormat = $sourceFormat;
        $this->entryCount = $entryCount;
        $this->priority = $priority;
        $this->enabled = $enabled;
        $this->created = $created;
        $this->userId = $userId;
    }

    /**
     * Create a new local dictionary.
     *
     * @param int         $languageId   Language ID this dictionary belongs to
     * @param string      $name         Dictionary name
     * @param string      $sourceFormat Source format (csv, json, stardict)
     * @param int|null    $userId       User ID for multi-user mode
     *
     * @return self
     *
     * @throws InvalidArgumentException If name is empty or format is invalid
     */
    public static function create(
        int $languageId,
        string $name,
        string $sourceFormat = 'csv',
        ?int $userId = null
    ): self {
        $trimmedName = trim($name);
        if ($trimmedName === '') {
            throw new InvalidArgumentException('Dictionary name cannot be empty');
        }

        $validFormats = ['csv', 'json', 'stardict'];
        if (!in_array($sourceFormat, $validFormats, true)) {
            throw new InvalidArgumentException(
                'Invalid source format. Must be one of: ' . implode(', ', $validFormats)
            );
        }

        return new self(
            null,
            $languageId,
            $trimmedName,
            null,
            $sourceFormat,
            0,
            1,
            true,
            new DateTimeImmutable(),
            $userId
        );
    }

    /**
     * Reconstitute a dictionary from persistence.
     *
     * @param int              $id           Dictionary ID
     * @param int              $languageId   Language ID
     * @param string           $name         Dictionary name
     * @param string|null      $description  Description
     * @param string           $sourceFormat Source format
     * @param int              $entryCount   Number of entries
     * @param int              $priority     Lookup priority (1=highest)
     * @param bool             $enabled      Whether dictionary is active
     * @param DateTimeImmutable $created     Creation timestamp
     * @param int|null         $userId       User ID
     *
     * @return self
     *
     * @internal This method is for repository use only
     */
    public static function reconstitute(
        int $id,
        int $languageId,
        string $name,
        ?string $description,
        string $sourceFormat,
        int $entryCount,
        int $priority,
        bool $enabled,
        DateTimeImmutable $created,
        ?int $userId
    ): self {
        return new self(
            $id,
            $languageId,
            $name,
            $description,
            $sourceFormat,
            $entryCount,
            $priority,
            $enabled,
            $created,
            $userId
        );
    }

    // Domain behavior methods

    /**
     * Update the dictionary name.
     *
     * @param string $name The new name
     *
     * @return void
     *
     * @throws InvalidArgumentException If name is empty
     */
    public function rename(string $name): void
    {
        $trimmedName = trim($name);
        if ($trimmedName === '') {
            throw new InvalidArgumentException('Dictionary name cannot be empty');
        }
        $this->name = $trimmedName;
    }

    /**
     * Set the description.
     *
     * @param string|null $description Description text
     *
     * @return void
     */
    public function setDescription(?string $description): void
    {
        $this->description = $description !== null ? trim($description) : null;
        if ($this->description === '') {
            $this->description = null;
        }
    }

    /**
     * Update the entry count.
     *
     * @param int $count Number of entries
     *
     * @return void
     *
     * @throws InvalidArgumentException If count is negative
     */
    public function setEntryCount(int $count): void
    {
        if ($count < 0) {
            throw new InvalidArgumentException('Entry count cannot be negative');
        }
        $this->entryCount = $count;
    }

    /**
     * Increment the entry count.
     *
     * @param int $amount Amount to add (default 1)
     *
     * @return void
     */
    public function incrementEntryCount(int $amount = 1): void
    {
        $this->entryCount += max(0, $amount);
    }

    /**
     * Set the lookup priority.
     *
     * @param int $priority Priority (1 = highest)
     *
     * @return void
     *
     * @throws InvalidArgumentException If priority is invalid
     */
    public function setPriority(int $priority): void
    {
        if ($priority < 1 || $priority > 99) {
            throw new InvalidArgumentException('Priority must be between 1 and 99');
        }
        $this->priority = $priority;
    }

    /**
     * Enable the dictionary for lookups.
     *
     * @return void
     */
    public function enable(): void
    {
        $this->enabled = true;
    }

    /**
     * Disable the dictionary for lookups.
     *
     * @return void
     */
    public function disable(): void
    {
        $this->enabled = false;
    }

    /**
     * Check if this dictionary is new (not yet persisted).
     *
     * @return bool
     */
    public function isNew(): bool
    {
        return $this->id === null;
    }

    // Getters

    public function id(): ?int
    {
        return $this->id;
    }

    public function languageId(): int
    {
        return $this->languageId;
    }

    public function name(): string
    {
        return $this->name;
    }

    public function description(): ?string
    {
        return $this->description;
    }

    public function sourceFormat(): string
    {
        return $this->sourceFormat;
    }

    public function entryCount(): int
    {
        return $this->entryCount;
    }

    public function priority(): int
    {
        return $this->priority;
    }

    public function isEnabled(): bool
    {
        return $this->enabled;
    }

    public function created(): DateTimeImmutable
    {
        return $this->created;
    }

    public function userId(): ?int
    {
        return $this->userId;
    }

    /**
     * Set the ID after persistence.
     *
     * @param int $id The new ID
     *
     * @return void
     *
     * @internal This method is for repository use only
     */
    public function setId(int $id): void
    {
        if ($this->id !== null) {
            throw new \LogicException('Cannot change ID of a persisted dictionary');
        }
        $this->id = $id;
    }

    /**
     * Export to array for JSON serialization.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'languageId' => $this->languageId,
            'name' => $this->name,
            'description' => $this->description,
            'sourceFormat' => $this->sourceFormat,
            'entryCount' => $this->entryCount,
            'priority' => $this->priority,
            'enabled' => $this->enabled,
            'created' => $this->created->format('Y-m-d H:i:s'),
        ];
    }
}
