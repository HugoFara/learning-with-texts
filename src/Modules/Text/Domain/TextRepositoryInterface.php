<?php

/**
 * Text Repository Interface
 *
 * Domain port for text persistence operations.
 *
 * PHP version 8.2
 *
 * @category Lwt
 * @package  Lwt\Modules\Text\Domain
 * @author   HugoFara <git@hugofara.net>
 * @license  Unlicense <http://unlicense.org/>
 * @link     https://hugofara.github.io/lwt/developer/api
 * @since    3.0.0
 */

declare(strict_types=1);

namespace Lwt\Modules\Text\Domain;

/**
 * Repository interface for Text entities.
 *
 * This is a domain port defining the contract for text persistence.
 * Infrastructure implementations (e.g., MySqlTextRepository) provide
 * the actual database access.
 *
 * @since 3.0.0
 */
interface TextRepositoryInterface
{
    /**
     * Find a text by its ID.
     *
     * @param int $id Text ID
     *
     * @return Text|null The text entity or null if not found
     */
    public function find(int $id): ?Text;

    /**
     * Find all texts.
     *
     * @return Text[]
     */
    public function findAll(): array;

    /**
     * Save a text entity.
     *
     * Inserts if new, updates if existing.
     *
     * @param Text $entity The text entity to save
     *
     * @return int The text ID (newly generated for inserts)
     */
    public function save(Text $entity): int;

    /**
     * Delete a text by its ID.
     *
     * @param int $id Text ID
     *
     * @return bool True if deleted, false if not found
     */
    public function delete(int $id): bool;

    /**
     * Check if a text exists.
     *
     * @param int $id Text ID
     *
     * @return bool
     */
    public function exists(int $id): bool;

    /**
     * Find all texts for a specific language.
     *
     * @param int         $languageId Language ID
     * @param string|null $orderBy    Column to order by
     * @param string      $direction  Sort direction (ASC/DESC)
     *
     * @return Text[]
     */
    public function findByLanguage(
        int $languageId,
        ?string $orderBy = 'TxTitle',
        string $direction = 'ASC'
    ): array;

    /**
     * Count texts for a specific language.
     *
     * @param int $languageId Language ID
     *
     * @return int
     */
    public function countByLanguage(int $languageId): int;

    /**
     * Find a text by title within a language.
     *
     * @param int    $languageId Language ID
     * @param string $title      Text title
     *
     * @return Text|null
     */
    public function findByTitle(int $languageId, string $title): ?Text;

    /**
     * Check if a title exists within a language.
     *
     * @param int      $languageId Language ID
     * @param string   $title      Text title
     * @param int|null $excludeId  Text ID to exclude (for updates)
     *
     * @return bool
     */
    public function titleExists(int $languageId, string $title, ?int $excludeId = null): bool;

    /**
     * Update the reading position for a text.
     *
     * @param int $textId   Text ID
     * @param int $position New reading position
     *
     * @return bool True if updated
     */
    public function updatePosition(int $textId, int $position): bool;

    /**
     * Update the audio position for a text.
     *
     * @param int   $textId   Text ID
     * @param float $position New audio position in seconds
     *
     * @return bool True if updated
     */
    public function updateAudioPosition(int $textId, float $position): bool;

    /**
     * Get the previous text ID in the language.
     *
     * @param int $textId     Current text ID
     * @param int $languageId Language ID
     *
     * @return int|null Previous text ID or null
     */
    public function getPreviousTextId(int $textId, int $languageId): ?int;

    /**
     * Get the next text ID in the language.
     *
     * @param int $textId     Current text ID
     * @param int $languageId Language ID
     *
     * @return int|null Next text ID or null
     */
    public function getNextTextId(int $textId, int $languageId): ?int;

    /**
     * Get texts formatted for select dropdown options.
     *
     * @param int $languageId    Language ID (0 for all languages)
     * @param int $maxNameLength Maximum title length before truncation
     *
     * @return array<int, array{id: int, title: string, language_id: int}>
     */
    public function getForSelect(int $languageId = 0, int $maxNameLength = 40): array;

    /**
     * Get basic text info (minimal data for lists).
     *
     * @param int $textId Text ID
     *
     * @return array{id: int, title: string, language_id: int, has_media: bool, has_annotation: bool}|null
     */
    public function getBasicInfo(int $textId): ?array;

    /**
     * Get texts with pagination.
     *
     * @param int    $languageId Language ID (0 for all languages)
     * @param int    $page       Page number (1-based)
     * @param int    $perPage    Items per page
     * @param string $orderBy    Column to order by
     * @param string $direction  Sort direction (ASC/DESC)
     *
     * @return array{items: Text[], total: int, page: int, per_page: int, total_pages: int}
     */
    public function findPaginated(
        int $languageId = 0,
        int $page = 1,
        int $perPage = 20,
        string $orderBy = 'TxTitle',
        string $direction = 'ASC'
    ): array;
}
