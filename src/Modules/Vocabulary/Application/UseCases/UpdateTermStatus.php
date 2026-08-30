<?php

/**
 * Update Term Status Use Case
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
use Lwt\Modules\Vocabulary\Domain\ValueObject\TermStatus;

/**
 * Use case for updating a term's learning status.
 *
 * @since 3.0.0
 */
class UpdateTermStatus
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
     * Execute the update status use case.
     *
     * @param int $termId Term ID
     * @param int $status New status value (1-5, 98, 99)
     *
     * @return bool True if updated, false if term not found
     *
     * @throws \InvalidArgumentException If status is invalid
     */
    public function execute(int $termId, int $status): bool
    {
        if ($termId <= 0) {
            return false;
        }

        // Validate status (this will throw if invalid)
        TermStatus::fromInt($status);

        return $this->repository->updateStatus($termId, $status);
    }

    /**
     * Advance a term's status to the next learning stage.
     *
     * @param int $termId Term ID
     *
     * @return bool True if updated
     */
    public function advance(int $termId): bool
    {
        $term = $this->repository->find($termId);

        if ($term === null) {
            return false;
        }

        $currentStatus = $term->status()->toInt();
        $newStatus = TermStatus::fromInt($currentStatus)->advance()->toInt();

        if ($newStatus === $currentStatus) {
            return false; // No change
        }

        return $this->repository->updateStatus($termId, $newStatus);
    }

    /**
     * Decrease a term's status to the previous learning stage.
     *
     * @param int $termId Term ID
     *
     * @return bool True if updated
     */
    public function decrease(int $termId): bool
    {
        $term = $this->repository->find($termId);

        if ($term === null) {
            return false;
        }

        $currentStatus = $term->status()->toInt();
        $newStatus = TermStatus::fromInt($currentStatus)->decrease()->toInt();

        if ($newStatus === $currentStatus) {
            return false; // No change
        }

        return $this->repository->updateStatus($termId, $newStatus);
    }

    /**
     * Update status for multiple terms.
     *
     * @param int[] $termIds Array of term IDs
     * @param int   $status  New status value
     *
     * @return int Number of terms updated
     *
     * @throws \InvalidArgumentException If status is invalid
     */
    public function executeMultiple(array $termIds, int $status): int
    {
        if (empty($termIds)) {
            return 0;
        }

        // Validate status
        TermStatus::fromInt($status);

        return $this->repository->updateStatusMultiple($termIds, $status);
    }

    /**
     * Mark a term as ignored.
     *
     * @param int $termId Term ID
     *
     * @return bool True if updated
     */
    public function markAsIgnored(int $termId): bool
    {
        return $this->execute($termId, TermStatus::IGNORED);
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
        return $this->execute($termId, TermStatus::WELL_KNOWN);
    }

    /**
     * Mark a term as learned (status 5).
     *
     * @param int $termId Term ID
     *
     * @return bool True if updated
     */
    public function markAsLearned(int $termId): bool
    {
        return $this->execute($termId, TermStatus::LEARNED);
    }
}
