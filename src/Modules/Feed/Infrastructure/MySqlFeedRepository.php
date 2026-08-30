<?php

/**
 * MySQL Feed Repository
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
use Lwt\Shared\Infrastructure\Database\QueryBuilder;
use Lwt\Modules\Feed\Domain\Feed;
use Lwt\Modules\Feed\Domain\FeedRepositoryInterface;

/**
 * MySQL repository for Feed entities.
 *
 * Provides database access for feed management operations.
 *
 * @extends AbstractRepository<Feed>
 *
 * @since 3.0.0
 */
class MySqlFeedRepository extends AbstractRepository implements FeedRepositoryInterface
{
    /**
     * @var string Table name without prefix
     */
    protected string $tableName = 'news_feeds';

    /**
     * @var string Primary key column
     */
    protected string $primaryKey = 'NfID';

    /**
     * @var array<string, string> Property to column mapping
     */
    protected array $columnMap = [
        'id' => 'NfID',
        'languageId' => 'NfLgID',
        'name' => 'NfName',
        'sourceUri' => 'NfSourceURI',
        'articleSectionTags' => 'NfArticleSectionTags',
        'filterTags' => 'NfFilterTags',
        'updateTimestamp' => 'NfUpdate',
        'options' => 'NfOptions',
    ];

    /**
     * {@inheritdoc}
     */
    protected function mapToEntity(array $row): Feed
    {
        return Feed::reconstitute(
            (int) $row['NfID'],
            (int) $row['NfLgID'],
            (string) $row['NfName'],
            (string) $row['NfSourceURI'],
            (string) ($row['NfArticleSectionTags'] ?? ''),
            (string) ($row['NfFilterTags'] ?? ''),
            (int) ($row['NfUpdate'] ?? 0),
            (string) ($row['NfOptions'] ?? '')
        );
    }

    /**
     * {@inheritdoc}
     *
     * @param Feed $entity
     *
     * @return array<string, scalar|null>
     */
    protected function mapToRow(object $entity): array
    {
        return [
            'NfLgID' => $entity->languageId(),
            'NfName' => $entity->name(),
            'NfSourceURI' => $entity->sourceUri(),
            'NfArticleSectionTags' => $entity->articleSectionTags(),
            'NfFilterTags' => $entity->filterTags(),
            'NfUpdate' => $entity->updateTimestamp(),
            'NfOptions' => $entity->options()->toString(),
        ];
    }

    /**
     * {@inheritdoc}
     *
     * @param Feed $entity
     */
    protected function getEntityId(object $entity): int
    {
        return $entity->id() ?? 0;
    }

    /**
     * {@inheritdoc}
     *
     * @param Feed $entity
     */
    protected function setEntityId(object $entity, int $id): void
    {
        $entity->setId($id);
    }

    /**
     * {@inheritdoc}
     */
    public function find(int $id): ?Feed
    {
        $row = $this->query()
            ->where($this->primaryKey, '=', $id)
            ->firstPrepared();

        if ($row === null) {
            return null;
        }

        return $this->mapToEntity($row);
    }

    /**
     * {@inheritdoc}
     *
     * @return Feed[]
     *
     * @psalm-suppress LessSpecificImplementedReturnType
     */
    public function findAll(string $orderBy = 'NfUpdate', string $direction = 'DESC'): array
    {
        $rows = $this->query()
            ->orderBy($orderBy, $direction)
            ->getPrepared();

        return array_map(
            fn(array $row) => $this->mapToEntity($row),
            $rows
        );
    }

    /**
     * {@inheritdoc}
     */
    public function findByLanguage(
        int $languageId,
        string $orderBy = 'NfUpdate',
        string $direction = 'DESC'
    ): array {
        $rows = $this->query()
            ->where('NfLgID', '=', $languageId)
            ->orderBy($orderBy, $direction)
            ->getPrepared();

        return array_map(
            fn(array $row) => $this->mapToEntity($row),
            $rows
        );
    }

    /**
     * {@inheritdoc}
     *
     * @param Feed $entity
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
    public function delete(object|int $entityOrId): bool
    {
        /** @var \Lwt\Modules\Feed\Domain\Feed|int $entityOrId */
        $id = is_int($entityOrId) ? $entityOrId : $this->getEntityId($entityOrId);
        $deleted = $this->query()
            ->where($this->primaryKey, '=', $id)
            ->delete();

        return $deleted > 0;
    }

    /**
     * {@inheritdoc}
     */
    public function deleteMultiple(array $ids): int
    {
        if (empty($ids)) {
            return 0;
        }

        return $this->query()
            ->whereIn($this->primaryKey, $ids)
            ->delete();
    }

    /**
     * {@inheritdoc}
     */
    public function exists(int $id): bool
    {
        return $this->query()
            ->where($this->primaryKey, '=', $id)
            ->existsPrepared();
    }

    /**
     * Count feeds with optional filtering.
     *
     * @param int|null    $languageId   Optional language ID filter
     * @param string|null $queryPattern Optional search pattern
     *
     * @return int Count of matching feeds
     */
    public function countFeeds(?int $languageId = null, ?string $queryPattern = null): int
    {
        $query = $this->query();

        if ($languageId !== null && $languageId > 0) {
            $query->where('NfLgID', '=', $languageId);
        }
        if ($queryPattern !== null) {
            $query->where('NfName', 'LIKE', $queryPattern);
        }

        return $query->countPrepared();
    }

    /**
     * {@inheritdoc}
     */
    public function updateTimestamp(int $feedId, int $timestamp): void
    {
        $this->query()
            ->where($this->primaryKey, '=', $feedId)
            ->updatePrepared(['NfUpdate' => $timestamp]);
    }

    /**
     * {@inheritdoc}
     */
    public function findNeedingAutoUpdate(int $currentTime): array
    {
        // Get all feeds with autoupdate option
        $rows = $this->query()
            ->where('NfOptions', 'LIKE', '%autoupdate=%')
            ->getPrepared();

        $needUpdate = [];
        foreach ($rows as $row) {
            $feed = $this->mapToEntity($row);
            if ($feed->needsUpdate($currentTime)) {
                $needUpdate[] = $feed;
            }
        }

        return $needUpdate;
    }

    /**
     * {@inheritdoc}
     */
    public function getForSelect(int $languageId = 0, int $maxNameLength = 40): array
    {
        $query = $this->query()
            ->select(['NfID', 'NfName', 'NfLgID'])
            ->orderBy('NfName', 'ASC');

        if ($languageId > 0) {
            $query->where('NfLgID', '=', $languageId);
        }

        $rows = $query->getPrepared();
        $result = [];

        foreach ($rows as $row) {
            $name = (string) $row['NfName'];
            if (mb_strlen($name) > $maxNameLength) {
                $name = mb_substr($name, 0, $maxNameLength - 3) . '...';
            }

            $result[] = [
                'id' => (int) $row['NfID'],
                'name' => $name,
                'language_id' => (int) $row['NfLgID'],
            ];
        }

        return $result;
    }

    /**
     * {@inheritdoc}
     */
    public function findPaginated(
        int $offset,
        int $limit,
        ?int $languageId = null,
        ?string $queryPattern = null,
        string $orderBy = 'NfUpdate',
        string $direction = 'DESC'
    ): array {
        $query = $this->query()
            ->orderBy($orderBy, $direction)
            ->limit($limit)
            ->offset($offset);

        if ($languageId !== null && $languageId > 0) {
            $query->where('NfLgID', '=', $languageId);
        }
        if ($queryPattern !== null) {
            $query->where('NfName', 'LIKE', $queryPattern);
        }

        $rows = $query->getPrepared();

        return array_map(
            fn(array $row) => $this->mapToEntity($row),
            $rows
        );
    }
}
