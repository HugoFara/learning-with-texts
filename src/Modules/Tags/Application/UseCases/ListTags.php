<?php

/**
 * List Tags Use Case
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

use Lwt\Shared\Infrastructure\Database\Settings;
use Lwt\Modules\Tags\Domain\Tag;
use Lwt\Modules\Tags\Domain\TagRepositoryInterface;

/**
 * Use case for listing and filtering tags.
 *
 * @since 3.0.0
 */
class ListTags
{
    private TagRepositoryInterface $repository;

    /**
     * Constructor.
     *
     * @param TagRepositoryInterface $repository Tag repository
     */
    public function __construct(TagRepositoryInterface $repository)
    {
        $this->repository = $repository;
    }

    /**
     * Get paginated list of tags.
     *
     * @param int    $page    Page number (1-based)
     * @param int    $perPage Items per page (0 for default)
     * @param string $query   Filter query (supports * wildcard)
     * @param string $orderBy Sort column ('text', 'comment', 'newest', 'oldest')
     *
     * @return array{tags: Tag[], usageCounts: array<int, int>, totalCount: int, pagination: array}
     */
    public function execute(
        int $page = 1,
        int $perPage = 0,
        string $query = '',
        string $orderBy = 'text'
    ): array {
        if ($perPage <= 0) {
            $perPage = $this->getMaxPerPage();
        }

        $result = $this->repository->paginate($page, $perPage, $query, $orderBy);

        // Add pagination info
        $result['pagination'] = $this->getPagination(
            $result['totalCount'],
            $page,
            $perPage
        );

        return $result;
    }

    /**
     * Get all tags as array.
     *
     * @param string $orderBy   Sort column
     * @param string $direction Sort direction
     *
     * @return Tag[]
     */
    public function findAll(string $orderBy = 'text', string $direction = 'ASC'): array
    {
        return $this->repository->findAll($orderBy, $direction);
    }

    /**
     * Get total count of tags.
     *
     * @param string $query Filter query
     *
     * @return int
     */
    public function count(string $query = ''): int
    {
        return $this->repository->count($query);
    }

    /**
     * Get all tag texts as array (for caching/autocomplete).
     *
     * @return string[]
     */
    public function getAllTexts(): array
    {
        return $this->repository->getAllTexts();
    }

    /**
     * Get the maximum items per page setting.
     *
     * @return int
     */
    public function getMaxPerPage(): int
    {
        return (int) Settings::getWithDefault('set-tags-per-page');
    }

    /**
     * Calculate pagination info.
     *
     * @param int $totalCount Total number of items
     * @param int $currentPage Current page number
     * @param int $perPage Items per page
     *
     * @return array{pages: int, currentPage: int, perPage: int}
     */
    public function getPagination(int $totalCount, int $currentPage, int $perPage): array
    {
        $pages = $totalCount === 0 ? 0 : (int) ceil($totalCount / $perPage);

        if ($currentPage < 1) {
            $currentPage = 1;
        }
        if ($currentPage > $pages && $pages > 0) {
            $currentPage = $pages;
        }

        return [
            'pages' => $pages,
            'currentPage' => $currentPage,
            'perPage' => $perPage,
        ];
    }

    /**
     * Get sort options for dropdown.
     *
     * @return array<array{value: int, text: string}>
     */
    public function getSortOptions(): array
    {
        return [
            ['value' => 1, 'text' => 'Tag Text (A-Z)'],
            ['value' => 2, 'text' => 'Tag Comment (A-Z)'],
            ['value' => 3, 'text' => 'Newest first'],
            ['value' => 4, 'text' => 'Oldest first'],
        ];
    }

    /**
     * Get sort column from index.
     *
     * @param int $index Sort index (1-4)
     *
     * @return string Order by column
     */
    public function getSortColumn(int $index): string
    {
        return match ($index) {
            1 => 'text',
            2 => 'comment',
            3 => 'newest',
            4 => 'oldest',
            default => 'text',
        };
    }
}
