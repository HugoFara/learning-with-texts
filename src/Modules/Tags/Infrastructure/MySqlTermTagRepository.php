<?php

/**
 * MySQL Term Tag Repository
 *
 * Infrastructure adapter for term tag persistence using MySQL.
 *
 * PHP version 8.2
 *
 * @category Lwt
 * @package  Lwt\Modules\Tags\Infrastructure
 * @author   HugoFara <git@hugofara.net>
 * @license  Unlicense <http://unlicense.org/>
 * @link     https://hugofara.github.io/lwt/developer/api
 * @since    3.0.0
 */

declare(strict_types=1);

namespace Lwt\Modules\Tags\Infrastructure;

use Lwt\Shared\Infrastructure\Database\Connection;
use Lwt\Shared\Infrastructure\Database\Maintenance;
use Lwt\Shared\Infrastructure\Database\QueryBuilder;
use Lwt\Shared\Infrastructure\Database\UserScopedQuery;
use Lwt\Modules\Tags\Domain\Tag;
use Lwt\Modules\Tags\Domain\TagRepositoryInterface;
use Lwt\Modules\Tags\Domain\TagType;
use Lwt\Modules\Tags\Domain\ValueObject\TagId;

/**
 * MySQL implementation of TagRepositoryInterface for term tags.
 *
 * Operates on the 'tags' table for term/vocabulary tags.
 *
 * @since 3.0.0
 */
class MySqlTermTagRepository implements TagRepositoryInterface
{
    private const TABLE_NAME = 'tags';
    private const COL_PREFIX = 'Tg';

    /**
     * {@inheritdoc}
     */
    public function getTagType(): TagType
    {
        return TagType::TERM;
    }

    /**
     * Get a query builder for this repository's table.
     *
     * @return QueryBuilder
     */
    private function query(): QueryBuilder
    {
        return QueryBuilder::table(self::TABLE_NAME);
    }

    /**
     * Map a database row to a Tag entity.
     *
     * @param array<string, mixed> $row Database row
     *
     * @return Tag
     */
    private function mapToEntity(array $row): Tag
    {
        return Tag::reconstitute(
            (int) $row['TgID'],
            TagType::TERM,
            (string) $row['TgText'],
            (string) ($row['TgComment'] ?? '')
        );
    }

    /**
     * {@inheritdoc}
     */
    public function find(int $id): ?Tag
    {
        $row = $this->query()
            ->where('TgID', '=', $id)
            ->firstPrepared();

        if ($row === null) {
            return null;
        }

        return $this->mapToEntity($row);
    }

    /**
     * {@inheritdoc}
     */
    public function findByText(string $text): ?Tag
    {
        $row = $this->query()
            ->where('TgText', '=', $text)
            ->firstPrepared();

        if ($row === null) {
            return null;
        }

        return $this->mapToEntity($row);
    }

    /**
     * {@inheritdoc}
     */
    public function save(Tag $tag): void
    {
        $id = $tag->id()->toInt();

        if ($id > 0 && !$tag->id()->isNew()) {
            // Update existing
            $this->query()
                ->where('TgID', '=', $id)
                ->updatePrepared([
                    'TgText' => $tag->text(),
                    'TgComment' => $tag->comment(),
                ]);
            return;
        }

        // Insert new
        $newId = (int) $this->query()->insertPrepared([
            'TgText' => $tag->text(),
            'TgComment' => $tag->comment(),
        ]);
        $tag->setId(TagId::fromInt($newId));
    }

    /**
     * {@inheritdoc}
     */
    public function delete(int $id): bool
    {
        if ($id <= 0) {
            return false;
        }

        $affected = $this->query()
            ->where('TgID', '=', $id)
            ->deletePrepared();

        if ($affected > 0) {
            Maintenance::adjustAutoIncrement(self::TABLE_NAME, 'TgID');
        }

        return $affected > 0;
    }

    /**
     * {@inheritdoc}
     */
    public function deleteMultiple(array $ids): int
    {
        if (empty($ids)) {
            return 0;
        }

        $ids = array_map('intval', $ids);
        $affected = $this->query()
            ->whereIn('TgID', $ids)
            ->deletePrepared();

        if ($affected > 0) {
            Maintenance::adjustAutoIncrement(self::TABLE_NAME, 'TgID');
        }

        return $affected;
    }

    /**
     * {@inheritdoc}
     */
    public function deleteAll(string $query = ''): int
    {
        if ($query === '') {
            $affected = $this->query()->deletePrepared();
        } else {
            $whereData = $this->buildWhereClause($query);
            /** @var array<int, mixed> $bindings */
            $bindings = $whereData['params'];
            $userScope = UserScopedQuery::forTablePrepared(self::TABLE_NAME, $bindings);
            $affected = Connection::preparedExecute(
                'DELETE FROM ' . self::TABLE_NAME . ' WHERE (1=1) '
                    . $whereData['clause'] . $userScope,
                $bindings
            );
        }

        if ($affected > 0) {
            Maintenance::adjustAutoIncrement(self::TABLE_NAME, 'TgID');
        }

        return $affected;
    }

    /**
     * {@inheritdoc}
     */
    public function exists(int $id): bool
    {
        return $this->query()
            ->where('TgID', '=', $id)
            ->existsPrepared();
    }

    /**
     * {@inheritdoc}
     */
    public function textExists(string $text, ?int $excludeId = null): bool
    {
        $query = $this->query()->where('TgText', '=', $text);

        if ($excludeId !== null) {
            $query->where('TgID', '!=', $excludeId);
        }

        return $query->existsPrepared();
    }

    /**
     * {@inheritdoc}
     */
    public function findAll(string $orderBy = 'text', string $direction = 'ASC'): array
    {
        $column = $this->mapOrderByColumn($orderBy);
        $direction = strtoupper($direction) === 'DESC' ? 'DESC' : 'ASC';

        $rows = $this->query()
            ->orderBy($column, $direction)
            ->getPrepared();

        return array_map(fn($row) => $this->mapToEntity($row), $rows);
    }

    /**
     * {@inheritdoc}
     */
    public function paginate(
        int $page,
        int $perPage,
        string $query = '',
        string $orderBy = 'text'
    ): array {
        $whereData = $this->buildWhereClause($query);
        $column = $this->mapOrderByColumn($orderBy);
        $offset = ($page - 1) * $perPage;

        // Get total count
        $totalCount = $this->count($query);

        // Get paginated rows. The base WHERE (1=1) anchor lets us safely
        // append both the optional search clause and the user-scope clause
        // without worrying about whether either is empty.
        /** @var array<int, mixed> $bindings */
        $bindings = $whereData['params'];
        $userScope = UserScopedQuery::forTablePrepared(self::TABLE_NAME, $bindings);
        $sql = 'SELECT TgID, TgText, TgComment FROM ' . self::TABLE_NAME .
               ' WHERE (1=1) ' . $whereData['clause'] . $userScope .
               ' ORDER BY ' . $column .
               ' LIMIT ' . $offset . ',' . $perPage;

        $rows = Connection::preparedFetchAll($sql, $bindings);

        $tags = [];
        $usageCounts = [];

        foreach ($rows as $row) {
            $tag = $this->mapToEntity($row);
            $tags[] = $tag;
            $usageCounts[$tag->id()->toInt()] = $this->getUsageCount($tag->id()->toInt());
        }

        return [
            'tags' => $tags,
            'usageCounts' => $usageCounts,
            'totalCount' => $totalCount,
        ];
    }

    /**
     * {@inheritdoc}
     */
    public function count(string $query = ''): int
    {
        if ($query === '') {
            return $this->query()->count('TgID');
        }

        $whereData = $this->buildWhereClause($query);
        /** @var array<int, mixed> $bindings */
        $bindings = $whereData['params'];
        $userScope = UserScopedQuery::forTablePrepared(self::TABLE_NAME, $bindings);
        return (int) Connection::preparedFetchValue(
            'SELECT COUNT(TgID) AS cnt FROM ' . self::TABLE_NAME .
            ' WHERE (1=1) ' . $whereData['clause'] . $userScope,
            $bindings,
            'cnt'
        );
    }

    /**
     * {@inheritdoc}
     */
    public function getAllTexts(): array
    {
        $rows = $this->query()
            ->select(['TgText'])
            ->orderBy('TgText', 'ASC')
            ->getPrepared();

        /** @var list<string> */
        return array_column($rows, 'TgText');
    }

    /**
     * {@inheritdoc}
     */
    public function getUsageCount(int $tagId): int
    {
        return QueryBuilder::table('word_tag_map')
            ->where('WtTgID', '=', $tagId)
            ->count();
    }

    /**
     * {@inheritdoc}
     */
    public function getOrCreate(string $text): int
    {
        $text = trim(str_replace([' ', ','], '', $text));

        if ($text === '') {
            throw new \InvalidArgumentException('Tag text cannot be empty');
        }

        $existing = $this->findByText($text);
        if ($existing !== null) {
            return $existing->id()->toInt();
        }

        $tag = Tag::create(TagType::TERM, $text);
        $this->save($tag);

        return $tag->id()->toInt();
    }

    /**
     * Build WHERE clause for query filtering.
     *
     * @param string $query Filter query string
     *
     * @return array{clause: string, params: list<string>}
     */
    private function buildWhereClause(string $query): array
    {
        if ($query === '') {
            return ['clause' => '', 'params' => []];
        }

        $searchValue = str_replace('*', '%', $query);
        $clause = ' AND (TgText LIKE ? OR TgComment LIKE ?)';

        return ['clause' => $clause, 'params' => [$searchValue, $searchValue]];
    }

    /**
     * Map order by parameter to column name.
     *
     * @param string $orderBy Order by parameter
     *
     * @return string Column name with direction
     */
    private function mapOrderByColumn(string $orderBy): string
    {
        return match (strtolower($orderBy)) {
            'text' => 'TgText ASC',
            'comment' => 'TgComment ASC',
            'newest', 'id desc' => 'TgID DESC',
            'oldest', 'id asc' => 'TgID ASC',
            default => 'TgText ASC',
        };
    }
}
