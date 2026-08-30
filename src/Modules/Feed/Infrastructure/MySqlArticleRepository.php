<?php

/**
 * MySQL Article Repository
 *
 * PHP version 8.2
 *
 * @category Lwt
 * @package  Lwt\Modules\Feed\Infrastructure
 * @author   HugoFara <git@hugofara.net>
 * @license  Unlicense <http://unlicense.org/>
 * @link     https://hugofara.github.io/lwt/developer/api
 * @since    3.0.0
 */

declare(strict_types=1);

namespace Lwt\Modules\Feed\Infrastructure;

use Lwt\Shared\Infrastructure\Repository\AbstractRepository;
use Lwt\Shared\Infrastructure\Database\Connection;
use Lwt\Shared\Infrastructure\Database\QueryBuilder;
use Lwt\Shared\Infrastructure\Database\UserScopedQuery;
use Lwt\Shared\Infrastructure\Globals;
use Lwt\Modules\Feed\Domain\Article;
use Lwt\Modules\Feed\Domain\ArticleRepositoryInterface;

/**
 * MySQL repository for Article entities (feed_links).
 *
 * Provides database access for article management operations.
 *
 * @extends AbstractRepository<Article>
 *
 * @since 3.0.0
 */
class MySqlArticleRepository extends AbstractRepository implements ArticleRepositoryInterface
{
    /**
     * @var string Table name without prefix
     */
    protected string $tableName = 'feed_links';

    /**
     * @var string Primary key column
     */
    protected string $primaryKey = 'FlID';

    /**
     * @var array<string, string> Property to column mapping
     */
    protected array $columnMap = [
        'id' => 'FlID',
        'feedId' => 'FlNfID',
        'title' => 'FlTitle',
        'link' => 'FlLink',
        'description' => 'FlDescription',
        'date' => 'FlDate',
        'audio' => 'FlAudio',
        'text' => 'FlText',
    ];

    /**
     * {@inheritdoc}
     */
    protected function mapToEntity(array $row): Article
    {
        return Article::reconstitute(
            (int) $row['FlID'],
            (int) $row['FlNfID'],
            (string) $row['FlTitle'],
            (string) $row['FlLink'],
            (string) ($row['FlDescription'] ?? ''),
            (string) ($row['FlDate'] ?? ''),
            (string) ($row['FlAudio'] ?? ''),
            (string) ($row['FlText'] ?? '')
        );
    }

    /**
     * {@inheritdoc}
     *
     * @param Article $entity
     *
     * @return array<string, scalar|null>
     */
    protected function mapToRow(object $entity): array
    {
        return [
            'FlNfID' => $entity->feedId(),
            'FlTitle' => $entity->title(),
            'FlLink' => $entity->link(),
            'FlDescription' => $entity->description(),
            'FlDate' => $entity->date(),
            'FlAudio' => $entity->audio(),
            'FlText' => $entity->text(),
        ];
    }

    /**
     * {@inheritdoc}
     *
     * @param Article $entity
     */
    protected function getEntityId(object $entity): int
    {
        return $entity->id() ?? 0;
    }

    /**
     * {@inheritdoc}
     *
     * @param Article $entity
     */
    protected function setEntityId(object $entity, int $id): void
    {
        $entity->setId($id);
    }

    /**
     * Append a `FlNfID IN (SELECT NfID FROM news_feeds WHERE NfUsID = ?)`
     * fragment to $bindings when multi-user mode is on with an
     * authenticated user.
     *
     * `feed_links` doesn't carry its own `UsID` column, so QueryBuilder's
     * auto-scoping doesn't fire. Every code path that takes a
     * caller-supplied FlID/FlNfID must run through this scope or
     * accept the full multi-tenant blast radius (read-foreign-articles,
     * delete-foreign-articles, etc.). The fragment is empty in
     * single-user installs so legacy tests stay green.
     *
     * @param array<int, mixed> &$bindings Reference to the bindings array
     *
     * @return string SQL fragment ready to splice after WHERE / AND, or ''
     */
    private function feedOwnerScope(array &$bindings): string
    {
        if (!Globals::isMultiUserEnabled()) {
            return '';
        }
        $userId = Globals::getCurrentUserId();
        if ($userId === null) {
            return '';
        }
        $bindings[] = $userId;
        return ' AND FlNfID IN (SELECT NfID FROM news_feeds WHERE NfUsID = ?)';
    }

    /**
     * {@inheritdoc}
     */
    public function find(int $id): ?Article
    {
        /** @var array<int, mixed> $bindings */
        $bindings = [$id];
        $scope = $this->feedOwnerScope($bindings);
        $row = Connection::preparedFetchOne(
            'SELECT * FROM feed_links WHERE FlID = ?' . $scope . ' LIMIT 1',
            $bindings
        );
        if ($row === null) {
            return null;
        }
        return $this->mapToEntity($row);
    }

    /**
     * {@inheritdoc}
     */
    public function findByFeed(
        int $feedId,
        int $offset = 0,
        int $limit = 50,
        string $orderBy = 'FlDate',
        string $direction = 'DESC'
    ): array {
        $rows = $this->query()
            ->where('FlNfID', '=', $feedId)
            ->orderBy($orderBy, $direction)
            ->limit($limit)
            ->offset($offset)
            ->getPrepared();

        return array_map(
            fn(array $row) => $this->mapToEntity($row),
            $rows
        );
    }

    /**
     * {@inheritdoc}
     */
    public function findByIds(array $ids): array
    {
        if (empty($ids)) {
            return [];
        }

        /** @var array<int, mixed> $bindings */
        $bindings = [];
        $inClause = Connection::buildPreparedInClause(array_map('intval', $ids), $bindings);
        $scope = $this->feedOwnerScope($bindings);
        $rows = Connection::preparedFetchAll(
            'SELECT * FROM feed_links WHERE FlID IN ' . $inClause . $scope,
            $bindings
        );
        return array_map(
            fn(array $row) => $this->mapToEntity($row),
            $rows
        );
    }

    /**
     * {@inheritdoc}
     */
    private const ALLOWED_ORDER_COLUMNS = [
        'FlDate', 'FlTitle', 'FlID', 'FlLink',
    ];

    private const ALLOWED_DIRECTIONS = ['ASC', 'DESC'];

    public function findByFeedsWithStatus(
        array $feedIds,
        int $offset = 0,
        int $limit = 50,
        string $orderBy = 'FlDate',
        string $direction = 'DESC',
        string $search = ''
    ): array {
        if (empty($feedIds)) {
            return [];
        }

        if (!in_array($orderBy, self::ALLOWED_ORDER_COLUMNS, true)) {
            $orderBy = 'FlDate';
        }
        $direction = strtoupper($direction);
        if (!in_array($direction, self::ALLOWED_DIRECTIONS, true)) {
            $direction = 'DESC';
        }

        $bindings = [];
        $feedInClause = Connection::buildPreparedInClause($feedIds, $bindings);

        // UserScopedQuery for texts LEFT JOIN (bindings added before WHERE)
        // We build this first since it goes in the JOIN clause
        $textScopeBindings = [];
        $textScope = UserScopedQuery::forTablePrepared('texts', $textScopeBindings, 'texts');

        // Build WHERE clause for search
        $searchClause = '';
        $searchBindings = [];
        if ($search !== '') {
            $searchClause = " AND (FlTitle LIKE ? OR FlDescription LIKE ?)";
            $searchBindings[] = '%' . $search . '%';
            $searchBindings[] = '%' . $search . '%';
        }

        // Merge all bindings in correct order: text scope, feed IDs, search
        $allBindings = array_merge($textScopeBindings, $bindings, $searchBindings);

        // Complex query with LEFT JOIN to texts (archived texts are in same table with TxArchivedAt)
        $sql = "SELECT FlID, FlNfID, FlTitle, FlLink, FlDescription, FlDate, FlAudio, FlText,
                       TxID, TxArchivedAt
                FROM feed_links
                LEFT JOIN texts ON TxSourceURI = TRIM(FlLink)"
                . $textScope
                . " WHERE FlNfID IN {$feedInClause} {$searchClause}"
                . " ORDER BY {$orderBy} {$direction}"
                . " LIMIT {$offset}, {$limit}";

        $result = [];
        $rows = Connection::preparedFetchAll($sql, $allBindings);

        foreach ($rows as $row) {
            $article = $this->mapToEntity($row);
            $textId = isset($row['TxID']) ? (int) $row['TxID'] : null;
            // Archived texts are identified by TxArchivedAt being non-null
            $isArchived = $textId !== null && isset($row['TxArchivedAt']) && $row['TxArchivedAt'] !== null;
            $archivedId = $isArchived ? $textId : null;
            $activeTextId = ($textId !== null && !$isArchived) ? $textId : null;

            $result[] = [
                'article' => $article,
                'text_id' => $activeTextId,
                'archived_id' => $archivedId,
                'status' => $article->determineStatus($activeTextId, $archivedId),
            ];
        }

        return $result;
    }

    /**
     * {@inheritdoc}
     */
    public function countByFeed(int $feedId, string $search = ''): int
    {
        if ($search !== '') {
            // Use raw SQL for OR condition
            $searchPattern = '%' . $search . '%';
            $bindings = [$feedId, $searchPattern, $searchPattern];
            $sql = "SELECT COUNT(*) as cnt FROM feed_links
                    WHERE FlNfID = ?
                    AND (FlTitle LIKE ? OR FlDescription LIKE ?)";
            $row = Connection::preparedFetchOne($sql, $bindings);
            return (int) ($row['cnt'] ?? 0);
        }

        return $this->query()
            ->where('FlNfID', '=', $feedId)
            ->countPrepared();
    }

    /**
     * {@inheritdoc}
     */
    public function countByFeeds(array $feedIds, string $search = ''): int
    {
        if (empty($feedIds)) {
            return 0;
        }

        if ($search !== '') {
            // Use raw SQL for OR condition with IN clause
            $bindings = [];
            $feedInClause = Connection::buildPreparedInClause($feedIds, $bindings);
            $searchPattern = '%' . $search . '%';
            $bindings[] = $searchPattern;
            $bindings[] = $searchPattern;
            $sql = "SELECT COUNT(*) as cnt FROM feed_links
                    WHERE FlNfID IN {$feedInClause}
                    AND (FlTitle LIKE ? OR FlDescription LIKE ?)";
            $row = Connection::preparedFetchOne($sql, $bindings);
            return (int) ($row['cnt'] ?? 0);
        }

        return $this->query()
            ->whereIn('FlNfID', $feedIds)
            ->countPrepared();
    }

    /**
     * {@inheritdoc}
     *
     * @param Article $entity
     */
    public function save(object $entity): int
    {
        $data = $this->mapToRow($entity);

        if ($entity->isNew()) {
            // Insert
            $id = (int) $this->query()->insertPrepared($data);
            $entity->setId($id);
            return $id;
        }

        // Update - entity must have an ID at this point
        $id = (int) $entity->id();
        $this->query()
            ->where($this->primaryKey, '=', $id)
            ->updatePrepared($data);

        return $id;
    }

    /**
     * {@inheritdoc}
     */
    public function insertBatch(array $articles, int $feedId): array
    {
        $inserted = 0;
        $duplicates = 0;

        foreach ($articles as $article) {
            // Check for duplicate by title (unique key: FlNfID, FlTitle)
            if ($this->titleExistsForFeed($feedId, $article->title())) {
                $duplicates++;
                continue;
            }

            try {
                $this->save($article);
                $inserted++;
            } catch (\mysqli_sql_exception $e) {
                if ($e->getCode() === 1062) {
                    // Duplicate key error
                    $duplicates++;
                } else {
                    error_log(
                        'MySqlArticleRepository::insertBatch: '
                        . $e->getMessage()
                    );
                    $duplicates++;
                }
            } catch (\Exception $e) {
                error_log(
                    'MySqlArticleRepository::insertBatch: '
                    . $e->getMessage()
                );
                $duplicates++;
            }
        }

        return [
            'inserted' => $inserted,
            'duplicates' => $duplicates,
        ];
    }

    /**
     * {@inheritdoc}
     */
    public function delete(object|int $entityOrId): bool
    {
        /** @var Article|int $entityOrId */
        $id = is_int($entityOrId) ? $entityOrId : $this->getEntityId($entityOrId);
        /** @var array<int, mixed> $bindings */
        $bindings = [$id];
        $scope = $this->feedOwnerScope($bindings);
        $deleted = Connection::preparedExecute(
            'DELETE FROM feed_links WHERE FlID = ?' . $scope,
            $bindings
        );
        return $deleted > 0;
    }

    /**
     * {@inheritdoc}
     */
    public function deleteByFeed(int $feedId): int
    {
        /** @var array<int, mixed> $bindings */
        $bindings = [$feedId];
        $scope = $this->feedOwnerScope($bindings);
        return Connection::preparedExecute(
            'DELETE FROM feed_links WHERE FlNfID = ?' . $scope,
            $bindings
        );
    }

    /**
     * {@inheritdoc}
     */
    public function deleteByFeeds(array $feedIds): int
    {
        if (empty($feedIds)) {
            return 0;
        }
        /** @var array<int, mixed> $bindings */
        $bindings = [];
        $inClause = Connection::buildPreparedInClause(array_map('intval', $feedIds), $bindings);
        $scope = $this->feedOwnerScope($bindings);
        return Connection::preparedExecute(
            'DELETE FROM feed_links WHERE FlNfID IN ' . $inClause . $scope,
            $bindings
        );
    }

    /**
     * {@inheritdoc}
     */
    public function deleteByIds(array $ids): int
    {
        if (empty($ids)) {
            return 0;
        }
        /** @var array<int, mixed> $bindings */
        $bindings = [];
        $inClause = Connection::buildPreparedInClause(array_map('intval', $ids), $bindings);
        $scope = $this->feedOwnerScope($bindings);
        return Connection::preparedExecute(
            'DELETE FROM feed_links WHERE FlID IN ' . $inClause . $scope,
            $bindings
        );
    }

    /**
     * {@inheritdoc}
     */
    public function resetErrorsByFeeds(array $feedIds): int
    {
        if (empty($feedIds)) {
            return 0;
        }

        /** @var array<int, mixed> $bindings */
        $bindings = [];
        $feedInClause = Connection::buildPreparedInClause($feedIds, $bindings);
        // feed_links carries no owner column, so filtering on FlNfID alone
        // would let any authenticated user reset another user's articles by
        // guessing a feed ID. deleteByFeeds()/deleteByIds() gate the same
        // table this way; this write was the one that did not.
        $scope = $this->feedOwnerScope($bindings);

        // Use raw SQL for TRIM() expression
        return Connection::preparedExecute(
            "UPDATE feed_links SET FlLink = TRIM(FlLink)
             WHERE FlNfID IN {$feedInClause}" . $scope,
            $bindings
        );
    }

    /**
     * {@inheritdoc}
     */
    public function markAsError(string $link): void
    {
        // Add space prefix to mark as error
        $this->query()
            ->where('FlLink', '=', $link)
            ->updatePrepared(['FlLink' => ' ' . $link]);
    }

    /**
     * {@inheritdoc}
     */
    public function titleExistsForFeed(int $feedId, string $title): bool
    {
        return $this->query()
            ->where('FlNfID', '=', $feedId)
            ->where('FlTitle', '=', $title)
            ->existsPrepared();
    }

    /**
     * {@inheritdoc}
     */
    public function getCountPerFeed(array $feedIds = []): array
    {
        /** @var array<int, mixed> $bindings */
        $bindings = [];
        $sql = "SELECT FlNfID, COUNT(*) as cnt FROM feed_links";

        if (!empty($feedIds)) {
            $feedInClause = Connection::buildPreparedInClause($feedIds, $bindings);
            $scope = $this->feedOwnerScope($bindings);
            $sql .= " WHERE FlNfID IN {$feedInClause}" . $scope;
        } else {
            // Empty feedIds means "give me counts for every feed". In
            // multi-user mode, restrict that to the caller's feeds.
            $scope = $this->feedOwnerScope($bindings);
            if ($scope !== '') {
                // feedOwnerScope yields ` AND FlNfID IN (...)`; drop the
                // leading ` AND ` (5 chars) so it can start a fresh WHERE.
                $sql .= ' WHERE ' . substr($scope, 5);
            }
        }

        $sql .= " GROUP BY FlNfID";

        $rows = Connection::preparedFetchAll($sql, $bindings);
        $counts = [];

        foreach ($rows as $row) {
            $counts[(int) $row['FlNfID']] = (int) $row['cnt'];
        }

        return $counts;
    }
}
