<?php

/**
 * Vocabulary Facade
 *
 * PHP version 8.1
 *
 * @category Lwt
 * @package  Lwt\Modules\Vocabulary\Application
 * @author   HugoFara <git@hugofara.net>
 * @license  Unlicense <http://unlicense.org/>
 * @link     https://hugofara.github.io/lwt/developer/api
 * @since    3.0.0
 */

declare(strict_types=1);

namespace Lwt\Modules\Vocabulary\Application;

use Lwt\Modules\Vocabulary\Application\UseCases\CreateTerm;
use Lwt\Modules\Vocabulary\Application\UseCases\DeleteTerm;
use Lwt\Modules\Vocabulary\Application\UseCases\GetTermById;
use Lwt\Modules\Vocabulary\Application\UseCases\UpdateTerm;
use Lwt\Modules\Vocabulary\Application\UseCases\UpdateTermStatus;
use Lwt\Modules\Vocabulary\Domain\Term;
use Lwt\Modules\Vocabulary\Domain\TermRepositoryInterface;
use Lwt\Modules\Vocabulary\Infrastructure\MySqlTermRepository;

/**
 * Vocabulary module facade - main entry point for vocabulary operations.
 *
 * This class provides a unified API for all vocabulary/term operations,
 * delegating to specific use cases internally.
 *
 * @since 3.0.0
 */
class VocabularyFacade
{
    private TermRepositoryInterface $repository;
    private CreateTerm $createTerm;
    private GetTermById $getTermById;
    private UpdateTerm $updateTerm;
    private DeleteTerm $deleteTerm;
    private UpdateTermStatus $updateTermStatus;

    /**
     * Constructor.
     *
     * @param TermRepositoryInterface|null $repository Term repository
     * @param CreateTerm|null              $createTerm Create use case
     * @param GetTermById|null             $getTermById Get use case
     * @param UpdateTerm|null              $updateTerm Update use case
     * @param DeleteTerm|null              $deleteTerm Delete use case
     * @param UpdateTermStatus|null        $updateTermStatus Status use case
     */
    public function __construct(
        ?TermRepositoryInterface $repository = null,
        ?CreateTerm $createTerm = null,
        ?GetTermById $getTermById = null,
        ?UpdateTerm $updateTerm = null,
        ?DeleteTerm $deleteTerm = null,
        ?UpdateTermStatus $updateTermStatus = null
    ) {
        $this->repository = $repository ?? new MySqlTermRepository();
        $this->createTerm = $createTerm ?? new CreateTerm($this->repository);
        $this->getTermById = $getTermById ?? new GetTermById($this->repository);
        $this->updateTerm = $updateTerm ?? new UpdateTerm($this->repository);
        $this->deleteTerm = $deleteTerm ?? new DeleteTerm($this->repository);
        $this->updateTermStatus = $updateTermStatus ?? new UpdateTermStatus($this->repository);
    }

    // ==========================================================================
    // CRUD Operations
    // ==========================================================================

    /**
     * Create a new term.
     *
     * @param int    $languageId    Language ID
     * @param string $text          Term text
     * @param int    $status        Learning status (1-5, 98, 99)
     * @param string $translation   Translation
     * @param string $sentence      Example sentence
     * @param string $notes         Personal notes
     * @param string $romanization  Romanization
     * @param int    $wordCount     Word count (0 = auto-calculate)
     *
     * @return Term The created term
     *
     * @throws \InvalidArgumentException If validation fails
     */
    public function createTerm(
        int $languageId,
        string $text,
        int $status = 1,
        string $translation = '',
        string $sentence = '',
        string $notes = '',
        string $romanization = '',
        int $wordCount = 0
    ): Term {
        return $this->createTerm->execute(
            $languageId,
            $text,
            $status,
            $translation,
            $sentence,
            $notes,
            $romanization,
            $wordCount
        );
    }

    /**
     * Create term from array data (backward compatible).
     *
     * @param array $data Term data with WoLgID, WoText, WoStatus, etc.
     *
     * @return array{id: int, message: string, success: bool, textlc: string, text: string}
     */
    public function createTermFromArray(array $data): array
    {
        return $this->createTerm->executeFromArray($data);
    }

    /**
     * Get a term by ID.
     *
     * @param int $termId Term ID
     *
     * @return Term|null The term or null if not found
     */
    public function getTerm(int $termId): ?Term
    {
        return $this->getTermById->execute($termId);
    }

    /**
     * Get term as array (backward compatible).
     *
     * @param int $termId Term ID
     *
     * @return array|null Term data array or null
     */
    public function getTermAsArray(int $termId): ?array
    {
        return $this->getTermById->executeAsArray($termId);
    }

    /**
     * Update a term.
     *
     * @param int         $termId       Term ID
     * @param int|null    $status       New status
     * @param string|null $translation  New translation
     * @param string|null $sentence     New sentence
     * @param string|null $notes        New notes
     * @param string|null $romanization New romanization
     *
     * @return Term The updated term
     *
     * @throws \InvalidArgumentException If term not found
     */
    public function updateTerm(
        int $termId,
        ?int $status = null,
        ?string $translation = null,
        ?string $sentence = null,
        ?string $notes = null,
        ?string $romanization = null
    ): Term {
        return $this->updateTerm->execute(
            $termId,
            $status,
            $translation,
            $sentence,
            $notes,
            $romanization
        );
    }

    /**
     * Update term from array data (backward compatible).
     *
     * @param array $data Term data array
     *
     * @return array{id: int, message: string, success: bool}
     */
    public function updateTermFromArray(array $data): array
    {
        return $this->updateTerm->executeFromArray($data);
    }

    /**
     * Delete a term.
     *
     * @param int $termId Term ID
     *
     * @return bool True if deleted
     */
    public function deleteTerm(int $termId): bool
    {
        return $this->deleteTerm->execute($termId);
    }

    /**
     * Delete multiple terms.
     *
     * @param int[] $termIds Array of term IDs
     *
     * @return int Number deleted
     */
    public function deleteTerms(array $termIds): int
    {
        return $this->deleteTerm->executeMultiple($termIds);
    }

    // ==========================================================================
    // Status Operations
    // ==========================================================================

    /**
     * Update a term's status.
     *
     * @param int $termId Term ID
     * @param int $status New status value
     *
     * @return bool True if updated
     */
    public function updateStatus(int $termId, int $status): bool
    {
        return $this->updateTermStatus->execute($termId, $status);
    }

    /**
     * Advance a term's status to the next level.
     *
     * @param int $termId Term ID
     *
     * @return bool True if advanced
     */
    public function advanceStatus(int $termId): bool
    {
        return $this->updateTermStatus->advance($termId);
    }

    /**
     * Decrease a term's status to the previous level.
     *
     * @param int $termId Term ID
     *
     * @return bool True if decreased
     */
    public function decreaseStatus(int $termId): bool
    {
        return $this->updateTermStatus->decrease($termId);
    }

    /**
     * Update status for multiple terms.
     *
     * @param int[] $termIds Term IDs
     * @param int   $status  New status
     *
     * @return int Number updated
     */
    public function bulkUpdateStatus(array $termIds, int $status): int
    {
        return $this->updateTermStatus->executeMultiple($termIds, $status);
    }

    /**
     * Mark a term as ignored.
     *
     * @param int $termId Term ID
     *
     * @return bool True if updated
     */
    public function ignoreTerm(int $termId): bool
    {
        return $this->updateTermStatus->markAsIgnored($termId);
    }

    /**
     * Mark a term as well-known.
     *
     * @param int $termId Term ID
     *
     * @return bool True if updated
     */
    public function markAsWellKnown(int $termId): bool
    {
        return $this->updateTermStatus->markAsWellKnown($termId);
    }

    /**
     * Mark a term as learned.
     *
     * @param int $termId Term ID
     *
     * @return bool True if updated
     */
    public function markAsLearned(int $termId): bool
    {
        return $this->updateTermStatus->markAsLearned($termId);
    }

    // ==========================================================================
    // Query Operations (delegated to repository)
    // ==========================================================================

    /**
     * Check if a term exists.
     *
     * @param int $termId Term ID
     *
     * @return bool
     */
    public function termExists(int $termId): bool
    {
        return $this->repository->exists($termId);
    }

    /**
     * Find a term by lowercase text in a language.
     *
     * @param int    $languageId Language ID
     * @param string $textLc     Lowercase text
     *
     * @return Term|null
     */
    public function findByText(int $languageId, string $textLc): ?Term
    {
        return $this->repository->findByTextLc($languageId, $textLc);
    }

    /**
     * Count terms by language.
     *
     * @param int $languageId Language ID
     *
     * @return int
     */
    public function countByLanguage(int $languageId): int
    {
        return $this->repository->countByLanguage($languageId);
    }

    /**
     * Get term statistics.
     *
     * @param int|null $languageId Language ID (null for all)
     *
     * @return array{total: int, learning: int, known: int, ignored: int, multi_word: int}
     */
    public function getStatistics(?int $languageId = null): array
    {
        return $this->repository->getStatistics($languageId);
    }

    /**
     * Find recently added terms.
     *
     * @param int|null $languageId Language ID
     * @param int      $limit      Maximum results
     *
     * @return Term[]
     */
    public function findRecent(?int $languageId = null, int $limit = 50): array
    {
        return $this->repository->findRecent($languageId, $limit);
    }

    /**
     * Search terms by text.
     *
     * @param string   $query      Search query
     * @param int|null $languageId Language ID
     * @param int      $limit      Maximum results
     *
     * @return Term[]
     */
    public function searchTerms(string $query, ?int $languageId = null, int $limit = 50): array
    {
        return $this->repository->searchByText($query, $languageId, $limit);
    }

    /**
     * Get paginated term list.
     *
     * @param int    $languageId Language ID (0 for all)
     * @param int    $page       Page number
     * @param int    $perPage    Items per page
     * @param string $orderBy    Column to order by
     * @param string $direction  Sort direction
     *
     * @return array{items: Term[], total: int, page: int, per_page: int, total_pages: int}
     */
    public function listTerms(
        int $languageId = 0,
        int $page = 1,
        int $perPage = 20,
        string $orderBy = 'WoText',
        string $direction = 'ASC'
    ): array {
        return $this->repository->findPaginated($languageId, $page, $perPage, $orderBy, $direction);
    }

    /**
     * Get the underlying repository (for advanced operations).
     *
     * @return TermRepositoryInterface
     */
    public function getRepository(): TermRepositoryInterface
    {
        return $this->repository;
    }
}
