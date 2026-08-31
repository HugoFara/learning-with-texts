<?php

/**
 * Delete Term Use Case
 *
 * PHP version 8.2
 *
 * @category Lwt
 * @package  Lwt\Modules\Vocabulary\Application\UseCases
 * @author   HugoFara <git@hugofara.net>
 * @license  Unlicense <http://unlicense.org/>
 * @link     https://hugofara.github.io/lwt/developer/api
 * @since    3.0.0
 */

declare(strict_types=1);

namespace Lwt\Modules\Vocabulary\Application\UseCases;

use Lwt\Modules\Vocabulary\Domain\TermRepositoryInterface;

/**
 * Use case for deleting a term.
 *
 * @since 3.0.0
 */
class DeleteTerm
{
    private TermRepositoryInterface $repository;

    /**
     * Constructor.
     *
     * @param TermRepositoryInterface $repository Term repository
     */
    public function __construct(TermRepositoryInterface $repository)
    {
        $this->repository = $repository;
    }

    /**
     * Execute the delete term use case.
     *
     * @param int $termId Term ID to delete
     *
     * @return bool True if deleted, false if not found
     */
    public function execute(int $termId): bool
    {
        if ($termId <= 0) {
            return false;
        }

        return $this->repository->delete($termId);
    }

    /**
     * Execute for multiple term IDs.
     *
     * @param int[] $termIds Array of term IDs to delete
     *
     * @return int Number of terms deleted
     */
    public function executeMultiple(array $termIds): int
    {
        if (empty($termIds)) {
            return 0;
        }

        return $this->repository->deleteMultiple($termIds);
    }

    /**
     * Execute and return structured result.
     *
     * @param int $termId Term ID to delete
     *
     * @return array{success: bool, error: ?string}
     */
    public function executeWithResult(int $termId): array
    {
        $deleted = $this->execute($termId);

        return [
            'success' => $deleted,
            'error' => $deleted ? null : 'Term not found'
        ];
    }
}
