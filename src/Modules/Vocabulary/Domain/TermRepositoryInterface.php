<?php

/**
 * Term Repository Interface
 *
 * PHP version 8.1
 *
 * @category Lwt
 * @package  Lwt\Modules\Vocabulary\Domain
 * @author   HugoFara <git@hugofara.net>
 * @license  Unlicense <http://unlicense.org/>
 * @link     https://hugofara.github.io/lwt/developer/api
 * @since    3.0.0
 */

declare(strict_types=1);

namespace Lwt\Modules\Vocabulary\Domain;

/**
 * Repository interface for Term entities.
 *
 * Defines the contract for vocabulary/word management operations.
 *
 * @since 3.0.0
 */
interface TermRepositoryInterface
{
    /**
     * Find a term by ID.
     *
     * @param int $id Term ID
     *
     * @return Term|null
     */
    public function find(int $id): ?Term;

    /**
     * Find all terms.
     *
     * @return Term[]
     */
    public function findAll(): array;

    /**
     * Save a term (insert or update).
     *
     * @param Term $term The term to save
     *
     * @return int The term ID
     */
    public function save(Term $term): int;

    /**
     * Delete a term by ID.
     *
     * @param int $id Term ID
     *
     * @return bool True if deleted
     */
    public function delete(int $id): bool;

    /**
     * Check if a term exists.
     *
     * @param int $id Term ID
     *
     * @return bool
     */
    public function exists(int $id): bool;

    /**
     * Find all terms for a specific language.
     *
     * @param int         $languageId Language ID
     * @param string|null $orderBy    Column to order by
     * @param string      $direction  Sort direction
     *
     * @return Term[]
     */
    public function findByLanguage(
        int $languageId,
        ?string $orderBy = 'WoText',
        string $direction = 'ASC'
    ): array;

    /**
     * Find a term by lowercase text within a language.
     *
     * @param int    $languageId Language ID
     * @param string $textLc     Lowercase term text
     *
     * @return Term|null
     */
    public function findByTextLc(int $languageId, string $textLc): ?Term;

    /**
     * Check if a term exists within a language.
     *
     * @param int      $languageId Language ID
     * @param string   $textLc     Lowercase term text
     * @param int|null $excludeId  Term ID to exclude (for updates)
     *
     * @return bool
     */
    public function termExists(int $languageId, string $textLc, ?int $excludeId = null): bool;

    /**
     * Count terms matching criteria.
     *
     * @param array<string, mixed> $criteria Field => value pairs
     *
     * @return int The count
     */
    public function count(array $criteria = []): int;

    /**
     * Count terms for a specific language.
     *
     * @param int $languageId Language ID
     *
     * @return int
     */
    public function countByLanguage(int $languageId): int;

    /**
     * Find terms by status.
     *
     * @param int      $status     Status value (1-5, 98, 99)
     * @param int|null $languageId Language ID (null for all)
     *
     * @return Term[]
     */
    public function findByStatus(int $status, ?int $languageId = null): array;

    /**
     * Find terms in learning stages (status 1-4).
     *
     * @param int|null $languageId Language ID (null for all)
     *
     * @return Term[]
     */
    public function findLearning(?int $languageId = null): array;

    /**
     * Find terms that are known (status 5 or 99).
     *
     * @param int|null $languageId Language ID (null for all)
     *
     * @return Term[]
     */
    public function findKnown(?int $languageId = null): array;

    /**
     * Find ignored terms (status 98).
     *
     * @param int|null $languageId Language ID (null for all)
     *
     * @return Term[]
     */
    public function findIgnored(?int $languageId = null): array;

    /**
     * Find multi-word expressions (word count > 1).
     *
     * @param int|null $languageId Language ID (null for all)
     *
     * @return Term[]
     */
    public function findMultiWord(?int $languageId = null): array;

    /**
     * Update the status of a term.
     *
     * @param int $termId Term ID
     * @param int $status New status value
     *
     * @return bool True if updated
     */
    public function updateStatus(int $termId, int $status): bool;

    /**
     * Update the translation of a term.
     *
     * @param int    $termId      Term ID
     * @param string $translation New translation
     *
     * @return bool True if updated
     */
    public function updateTranslation(int $termId, string $translation): bool;

    /**
     * Update the romanization of a term.
     *
     * @param int    $termId       Term ID
     * @param string $romanization New romanization
     *
     * @return bool True if updated
     */
    public function updateRomanization(int $termId, string $romanization): bool;

    /**
     * Update the lemma (base form) of a term.
     *
     * @param int         $termId Term ID
     * @param string|null $lemma  New lemma (null to clear)
     *
     * @return bool True if updated
     */
    public function updateLemma(int $termId, ?string $lemma): bool;

    /**
     * Find all terms sharing a lemma in a language (word family).
     *
     * @param int    $languageId Language ID
     * @param string $lemmaLc    Lowercase lemma
     *
     * @return Term[]
     */
    public function findByLemma(int $languageId, string $lemmaLc): array;

    /**
     * Get statistics for terms.
     *
     * @param int|null $languageId Language ID (null for all)
     *
     * @return array{total: int, learning: int, known: int, ignored: int, multi_word: int}
     */
    public function getStatistics(?int $languageId = null): array;

    /**
     * Delete multiple terms by IDs.
     *
     * @param int[] $termIds Array of term IDs
     *
     * @return int Number of deleted terms
     */
    public function deleteMultiple(array $termIds): int;

    /**
     * Update status for multiple terms.
     *
     * @param int[] $termIds Array of term IDs
     * @param int   $status  New status value
     *
     * @return int Number of updated terms
     */
    public function updateStatusMultiple(array $termIds, int $status): int;

    /**
     * Get terms with pagination.
     *
     * @param int    $languageId Language ID (0 for all)
     * @param int    $page       Page number (1-based)
     * @param int    $perPage    Items per page
     * @param string $orderBy    Column to order by
     * @param string $direction  Sort direction
     *
     * @return array{items: Term[], total: int, page: int, per_page: int, total_pages: int}
     */
    public function findPaginated(
        int $languageId = 0,
        int $page = 1,
        int $perPage = 20,
        string $orderBy = 'WoText',
        string $direction = 'ASC'
    ): array;

    /**
     * Search terms by text.
     *
     * @param string   $query      Search query
     * @param int|null $languageId Language ID (null for all)
     * @param int      $limit      Maximum results
     *
     * @return Term[]
     */
    public function searchByText(string $query, ?int $languageId = null, int $limit = 50): array;

    /**
     * Find recently added terms.
     *
     * @param int|null $languageId Language ID (null for all)
     * @param int      $limit      Maximum results
     *
     * @return Term[]
     */
    public function findRecent(?int $languageId = null, int $limit = 50): array;
}
