<?php

/**
 * Term Query Methods Trait
 *
 * PHP version 8.2
 *
 * @category Lwt
 * @package  Lwt\Modules\Vocabulary\Infrastructure
 * @author   HugoFara <git@hugofara.net>
 * @license  Unlicense <http://unlicense.org/>
 * @link     https://hugofara.github.io/lwt/developer/api
 * @since    3.0.0
 */

declare(strict_types=1);

namespace Lwt\Modules\Vocabulary\Infrastructure;

use Lwt\Shared\Infrastructure\Database\Connection;
use Lwt\Shared\Infrastructure\Database\QueryBuilder;
use Lwt\Modules\Vocabulary\Domain\Term;
use Lwt\Modules\Vocabulary\Domain\ValueObject\TermStatus;

/**
 * Query methods for MySqlTermRepository.
 *
 * Provides find/search/pagination methods that read term data
 * from the database without modifying it.
 *
 * @since 3.0.0
 */
trait TermQueryMethods
{
    /**
     * Get a query builder for this repository's table.
     *
     * @return QueryBuilder
     */
    abstract protected function query(): QueryBuilder;

    /**
     * Map a database row to a Term entity.
     *
     * @param array<string, mixed> $row Database row
     *
     * @return Term
     */
    abstract protected function mapToEntity(array $row): Term;

    /**
     * {@inheritdoc}
     */
    public function findByStatus(int $status, ?int $languageId = null): array
    {
        $query = $this->query()
            ->where('WoStatus', '=', $status)
            ->orderBy('WoText');

        if ($languageId !== null) {
            $query->where('WoLgID', '=', $languageId);
        }

        $rows = $query->getPrepared();

        return array_map(
            fn(array $row) => $this->mapToEntity($row),
            $rows
        );
    }

    /**
     * {@inheritdoc}
     */
    public function findLearning(?int $languageId = null): array
    {
        $query = $this->query()
            ->whereIn('WoStatus', [
                TermStatus::NEW,
                TermStatus::LEARNING_2,
                TermStatus::LEARNING_3,
                TermStatus::LEARNING_4
            ])
            ->orderBy('WoText');

        if ($languageId !== null) {
            $query->where('WoLgID', '=', $languageId);
        }

        $rows = $query->getPrepared();

        return array_map(
            fn(array $row) => $this->mapToEntity($row),
            $rows
        );
    }

    /**
     * {@inheritdoc}
     */
    public function findKnown(?int $languageId = null): array
    {
        $query = $this->query()
            ->whereIn('WoStatus', [TermStatus::LEARNED, TermStatus::WELL_KNOWN])
            ->orderBy('WoText');

        if ($languageId !== null) {
            $query->where('WoLgID', '=', $languageId);
        }

        $rows = $query->getPrepared();

        return array_map(
            fn(array $row) => $this->mapToEntity($row),
            $rows
        );
    }

    /**
     * {@inheritdoc}
     */
    public function findIgnored(?int $languageId = null): array
    {
        return $this->findByStatus(TermStatus::IGNORED, $languageId);
    }

    /**
     * {@inheritdoc}
     */
    public function findMultiWord(?int $languageId = null): array
    {
        $query = $this->query()
            ->where('WoWordCount', '>', 1)
            ->orderBy('WoText');

        if ($languageId !== null) {
            $query->where('WoLgID', '=', $languageId);
        }

        $rows = $query->getPrepared();

        return array_map(
            fn(array $row) => $this->mapToEntity($row),
            $rows
        );
    }

    /**
     * Find single-word terms (word count = 1).
     *
     * @param int|null $languageId Language ID (null for all)
     *
     * @return Term[]
     */
    public function findSingleWord(?int $languageId = null): array
    {
        $query = $this->query()
            ->where('WoWordCount', '=', 1)
            ->orderBy('WoText');

        if ($languageId !== null) {
            $query->where('WoLgID', '=', $languageId);
        }

        $rows = $query->getPrepared();

        return array_map(
            fn(array $row) => $this->mapToEntity($row),
            $rows
        );
    }

    /**
     * Find all terms sharing a lemma in a language (word family).
     *
     * @param int    $languageId Language ID
     * @param string $lemmaLc    Lowercase lemma
     *
     * @return Term[]
     */
    public function findByLemma(int $languageId, string $lemmaLc): array
    {
        $rows = $this->query()
            ->where('WoLgID', '=', $languageId)
            ->where('WoLemmaLC', '=', $lemmaLc)
            ->orderBy('WoWordCount')
            ->orderBy('WoText')
            ->getPrepared();

        return array_map(
            fn(array $row) => $this->mapToEntity($row),
            $rows
        );
    }

    /**
     * {@inheritdoc}
     */
    public function findPaginated(
        int $languageId = 0,
        int $page = 1,
        int $perPage = 20,
        string $orderBy = 'WoText',
        string $direction = 'ASC'
    ): array {
        $query = $this->query();

        if ($languageId > 0) {
            $query->where('WoLgID', '=', $languageId);
        }

        $total = (clone $query)->countPrepared();
        $totalPages = $total > 0 ? (int) ceil($total / $perPage) : 0;

        // Ensure page is within bounds
        $page = max(1, min($page, max(1, $totalPages)));
        $offset = ($page - 1) * $perPage;

        $rows = $query
            ->orderBy($orderBy, $direction)
            ->limit($perPage)
            ->offset($offset)
            ->getPrepared();

        $items = array_map(
            fn(array $row) => $this->mapToEntity($row),
            $rows
        );

        return [
            'items' => $items,
            'total' => $total,
            'page' => $page,
            'per_page' => $perPage,
            'total_pages' => $totalPages,
        ];
    }

    /**
     * {@inheritdoc}
     */
    public function searchByText(string $query, ?int $languageId = null, int $limit = 50): array
    {
        $searchPattern = '%' . str_replace(['%', '_'], ['\\%', '\\_'], $query) . '%';

        $dbQuery = $this->query()
            ->where('WoText', 'LIKE', $searchPattern)
            ->orderBy('WoText')
            ->limit($limit);

        if ($languageId !== null) {
            $dbQuery->where('WoLgID', '=', $languageId);
        }

        $rows = $dbQuery->getPrepared();

        return array_map(
            fn(array $row) => $this->mapToEntity($row),
            $rows
        );
    }

    /**
     * Search terms by translation.
     *
     * @param string   $query      Search query
     * @param int|null $languageId Language ID (null for all)
     * @param int      $limit      Maximum results
     *
     * @return Term[]
     */
    public function searchByTranslation(string $query, ?int $languageId = null, int $limit = 50): array
    {
        $searchPattern = '%' . str_replace(['%', '_'], ['\\%', '\\_'], $query) . '%';

        $dbQuery = $this->query()
            ->where('WoTranslation', 'LIKE', $searchPattern)
            ->orderBy('WoText')
            ->limit($limit);

        if ($languageId !== null) {
            $dbQuery->where('WoLgID', '=', $languageId);
        }

        $rows = $dbQuery->getPrepared();

        return array_map(
            fn(array $row) => $this->mapToEntity($row),
            $rows
        );
    }


    /**
     * {@inheritdoc}
     */
    public function findRecent(?int $languageId = null, int $limit = 50): array
    {
        $query = $this->query()
            ->orderBy('WoCreated', 'DESC')
            ->limit($limit);

        if ($languageId !== null) {
            $query->where('WoLgID', '=', $languageId);
        }

        $rows = $query->getPrepared();

        return array_map(
            fn(array $row) => $this->mapToEntity($row),
            $rows
        );
    }

    /**
     * Find terms with status changed recently.
     *
     * @param int|null $languageId Language ID (null for all)
     * @param int      $days       Number of days to look back
     * @param int      $limit      Maximum results
     *
     * @return Term[]
     */
    public function findRecentlyChanged(
        ?int $languageId = null,
        int $days = 7,
        int $limit = 50
    ): array {
        $timestamp = strtotime("-{$days} days");
        $sinceDate = date('Y-m-d H:i:s', $timestamp !== false ? $timestamp : time());

        $query = $this->query()
            ->where('WoStatusChanged', '>=', $sinceDate)
            ->orderBy('WoStatusChanged', 'DESC')
            ->limit($limit);

        if ($languageId !== null) {
            $query->where('WoLgID', '=', $languageId);
        }

        $rows = $query->getPrepared();

        return array_map(
            fn(array $row) => $this->mapToEntity($row),
            $rows
        );
    }

    /**
     * Find terms without translation.
     *
     * @param int|null $languageId Language ID (null for all)
     *
     * @return Term[]
     */
    public function findWithoutTranslation(?int $languageId = null): array
    {
        // Use raw SQL for OR condition on translation (empty or '*')
        $rows = Connection::preparedFetchAll(
            "SELECT * FROM words WHERE (WoTranslation = '' OR WoTranslation = '*')"
            . ($languageId !== null ? " AND WoLgID = ?" : "")
            . " ORDER BY WoText",
            $languageId !== null ? [$languageId] : []
        );

        return array_map(
            fn(array $row) => $this->mapToEntity($row),
            $rows
        );
    }

    /**
     * Get terms formatted for select dropdown options.
     *
     * @param int $languageId    Language ID (0 for all languages)
     * @param int $maxNameLength Maximum text length before truncation
     *
     * @return array<int, array{id: int, text: string, language_id: int}>
     */
    public function getForSelect(int $languageId = 0, int $maxNameLength = 40): array
    {
        $query = $this->query()
            ->select(['WoID', 'WoText', 'WoLgID'])
            ->orderBy('WoText');

        if ($languageId > 0) {
            $query->where('WoLgID', '=', $languageId);
        }

        $rows = $query->getPrepared();
        $result = [];

        foreach ($rows as $row) {
            $text = (string) $row['WoText'];
            if (mb_strlen($text, 'UTF-8') > $maxNameLength) {
                $text = mb_substr($text, 0, $maxNameLength, 'UTF-8') . '...';
            }
            $result[] = [
                'id' => (int) $row['WoID'],
                'text' => $text,
                'language_id' => (int) $row['WoLgID'],
            ];
        }

        return $result;
    }

    /**
     * Get basic term info (minimal data for lists).
     *
     * @param int $termId Term ID
     *
     * @return array{id: int, text: string, language_id: int, status: int, has_translation: bool}|null
     */
    public function getBasicInfo(int $termId): ?array
    {
        $row = $this->query()
            ->select([
                'WoID',
                'WoText',
                'WoLgID',
                'WoStatus',
                'WoTranslation',
            ])
            ->where('WoID', '=', $termId)
            ->firstPrepared();

        if ($row === null) {
            return null;
        }

        $translation = (string) ($row['WoTranslation'] ?? '');

        return [
            'id' => (int) $row['WoID'],
            'text' => (string) $row['WoText'],
            'language_id' => (int) $row['WoLgID'],
            'status' => (int) $row['WoStatus'],
            'has_translation' => $translation !== '' && $translation !== '*',
        ];
    }
}
