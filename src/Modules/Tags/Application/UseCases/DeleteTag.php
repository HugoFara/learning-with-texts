<?php

/**
 * Delete Tag Use Case
 *
 * PHP version 8.2
 *
 * @category Lwt
 * @package  Lwt\Modules\Tags\Application\UseCases
 * @author   HugoFara <git@hugofara.net>
 * @license  Unlicense <http://unlicense.org/>
 * @link     https://hugofara.github.io/lwt/developer/api
 * @since    3.0.0
 */

declare(strict_types=1);

namespace Lwt\Modules\Tags\Application\UseCases;

use Lwt\Modules\Tags\Domain\TagAssociationInterface;
use Lwt\Modules\Tags\Domain\TagRepositoryInterface;

/**
 * Use case for deleting tags.
 *
 * @since 3.0.0
 */
class DeleteTag
{
    private TagRepositoryInterface $repository;
    private TagAssociationInterface $association;

    /**
     * Constructor.
     *
     * @param TagRepositoryInterface  $repository  Tag repository
     * @param TagAssociationInterface $association Tag association handler
     */
    public function __construct(
        TagRepositoryInterface $repository,
        TagAssociationInterface $association
    ) {
        $this->repository = $repository;
        $this->association = $association;
    }

    /**
     * Delete a single tag by ID.
     *
     * @param int $id Tag ID
     *
     * @return bool True if deleted
     */
    public function execute(int $id): bool
    {
        $deleted = $this->repository->delete($id);

        if ($deleted) {
            $this->association->cleanupOrphanedLinks();
        }

        return $deleted;
    }

    /**
     * Delete a single tag and return result.
     *
     * @param int $id Tag ID
     *
     * @return array{success: bool, count: int} Result
     */
    public function executeWithResult(int $id): array
    {
        $deleted = $this->execute($id);
        return ['success' => $deleted, 'count' => $deleted ? 1 : 0];
    }

    /**
     * Delete multiple tags by IDs.
     *
     * @param int[] $ids Tag IDs
     *
     * @return int Number of deleted tags
     */
    public function executeMultiple(array $ids): int
    {
        if (empty($ids)) {
            return 0;
        }

        $deleted = $this->repository->deleteMultiple($ids);

        if ($deleted > 0) {
            $this->association->cleanupOrphanedLinks();
        }

        return $deleted;
    }

    /**
     * Delete multiple tags and return result.
     *
     * @param int[] $ids Tag IDs
     *
     * @return array{success: bool, count: int} Result
     */
    public function executeMultipleWithResult(array $ids): array
    {
        $deleted = $this->executeMultiple($ids);
        return ['success' => $deleted > 0, 'count' => $deleted];
    }

    /**
     * Delete all tags matching a filter.
     *
     * @param string $query Filter query (supports * wildcard)
     *
     * @return int Number of deleted tags
     */
    public function executeAll(string $query = ''): int
    {
        $deleted = $this->repository->deleteAll($query);

        if ($deleted > 0) {
            $this->association->cleanupOrphanedLinks();
        }

        return $deleted;
    }

    /**
     * Delete all tags matching filter and return result.
     *
     * @param string $query Filter query
     *
     * @return array{success: bool, count: int} Result
     */
    public function executeAllWithResult(string $query = ''): array
    {
        $deleted = $this->executeAll($query);
        return ['success' => $deleted > 0, 'count' => $deleted];
    }
}
