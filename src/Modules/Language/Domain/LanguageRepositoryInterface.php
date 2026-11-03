<?php

/**
 * Language Repository Interface
 *
 * Domain port for language persistence operations.
 *
 * PHP version 8.2
 *
 * @category Lwt
 * @package  Lwt\Modules\Language\Domain
 * @author   HugoFara <git@hugofara.net>
 * @license  Unlicense <http://unlicense.org/>
 * @link     https://hugofara.github.io/lwt/developer/api
 * @since    3.0.0
 */

declare(strict_types=1);

namespace Lwt\Modules\Language\Domain;

/**
 * Repository interface for Language entity.
 *
 * Defines the contract for language persistence operations.
 * Implementations may use different storage backends (MySQL, memory, etc.)
 *
 * @since 3.0.0
 */
interface LanguageRepositoryInterface
{
    /**
     * Find a language by its ID.
     *
     * @param int $id Language ID
     *
     * @return Language|null Language entity or null if not found
     */
    public function find(int $id): ?Language;

    /**
     * Save a language entity (create or update).
     *
     * @param Language $entity The language to save
     *
     * @return void
     */
    public function save(Language $entity): void;

    /**
     * Delete a language by ID.
     *
     * @param int $id Language ID
     *
     * @return void
     */
    public function delete(int $id): void;

    /**
     * Check if a language exists.
     *
     * @param int $id Language ID
     *
     * @return bool
     */
    public function exists(int $id): bool;

    /**
     * Find all non-empty languages (those with a name).
     *
     * @param string $orderBy   Column to order by (default: name)
     * @param string $direction Sort direction (default: ASC)
     *
     * @return Language[]
     */
    public function findAllActive(string $orderBy = 'LgName', string $direction = 'ASC'): array;

    /**
     * Find a language by name.
     *
     * @param string $name Language name
     *
     * @return Language|null
     */
    public function findByName(string $name): ?Language;

    /**
     * Check if a language name exists.
     *
     * @param string   $name      Language name
     * @param int|null $excludeId Language ID to exclude (for updates)
     *
     * @return bool
     */
    public function nameExists(string $name, ?int $excludeId = null): bool;

    /**
     * Get languages as name => id dictionary.
     *
     * @return array<string, int>
     */
    public function getAllAsDict(): array;

    /**
     * Get languages formatted for select dropdown options.
     *
     * @param int $maxNameLength Maximum name length before truncation
     *
     * @return array<int, array{id: int, name: string}>
     */
    public function getForSelect(int $maxNameLength = 30): array;

    /**
     * Find the first empty language record (for reuse on insert).
     *
     * @return int|null The empty language ID or null
     */
    public function findEmptyLanguageId(): ?int;

    /**
     * Check if a language is RTL (right-to-left).
     *
     * @param int $id Language ID
     *
     * @return bool
     */
    public function isRightToLeft(int $id): bool;

    /**
     * Get the word character regex for a language.
     *
     * @param int $id Language ID
     *
     * @return string|null The regex or null if not found
     */
    public function getWordCharacters(int $id): ?string;

    /**
     * Create a new empty language entity with default values.
     *
     * @return Language
     */
    public function createEmpty(): Language;

    /**
     * Get the name of a language by ID.
     *
     * @param int $id Language ID
     *
     * @return string|null The language name or null if not found
     */
    public function getName(int $id): ?string;

    /**
     * Get the translator URI for a language.
     *
     * @param int $id Language ID
     *
     * @return string|null The translator URI or null if not found
     */
    public function getTranslatorUri(int $id): ?string;
}
