<?php

/**
 * MySQL Text Repository
 *
 * PHP version 8.2
 *
 * @category Lwt
 * @package  Lwt\Modules\Text\Infrastructure
 * @author   HugoFara <git@hugofara.net>
 * @license  Unlicense <http://unlicense.org/>
 * @link     https://hugofara.github.io/lwt/developer/api
 * @since    3.0.0
 */

declare(strict_types=1);

namespace Lwt\Modules\Text\Infrastructure;

use Lwt\Shared\Infrastructure\Repository\AbstractRepository;
use Lwt\Modules\Text\Domain\Text;
use Lwt\Modules\Text\Domain\TextRepositoryInterface;
use Lwt\Modules\Text\Domain\ValueObject\TextId;

/**
 * MySQL repository for Text entities.
 *
 * Provides database access for text management operations.
 * Handles both basic CRUD and text-specific queries.
 *
 * @extends AbstractRepository<Text>
 *
 * @since 3.0.0
 */
class MySqlTextRepository extends AbstractRepository implements TextRepositoryInterface
{
    /**
     * @var string Table name without prefix
     */
    protected string $tableName = 'texts';

    /**
     * @var string Primary key column
     */
    protected string $primaryKey = 'TxID';

    /**
     * @var array<string, string> Property to column mapping
     */
    protected array $columnMap = [
        'id' => 'TxID',
        'languageId' => 'TxLgID',
        'title' => 'TxTitle',
        'text' => 'TxText',
        'annotatedText' => 'TxAnnotatedText',
        'mediaUri' => 'TxAudioURI',
        'sourceUri' => 'TxSourceURI',
        'position' => 'TxPosition',
        'audioPosition' => 'TxAudioPosition',
    ];

    /**
     * Override base query to filter out archived texts.
     *
     * Active texts have TxArchivedAt IS NULL. Archived texts use
     * a separate set of methods that explicitly include TxArchivedAt IS NOT NULL.
     *
     * @return \Lwt\Shared\Infrastructure\Database\QueryBuilder
     */
    protected function query(): \Lwt\Shared\Infrastructure\Database\QueryBuilder
    {
        return \Lwt\Shared\Infrastructure\Database\QueryBuilder::table($this->tableName)
            ->whereNull('TxArchivedAt');
    }

    /**
     * {@inheritdoc}
     */
    protected function mapToEntity(array $row): Text
    {
        return Text::reconstitute(
            (int) $row['TxID'],
            (int) $row['TxLgID'],
            (string) $row['TxTitle'],
            (string) $row['TxText'],
            (string) ($row['TxAnnotatedText'] ?? ''),
            (string) ($row['TxAudioURI'] ?? ''),
            (string) ($row['TxSourceURI'] ?? ''),
            (int) ($row['TxPosition'] ?? 0),
            (float) ($row['TxAudioPosition'] ?? 0.0)
        );
    }

    /**
     * {@inheritdoc}
     *
     * @param Text $entity
     *
     * @return array<string, mixed>
     */
    protected function mapToRow(object $entity): array
    {
        return [
            'TxID' => $entity->id()->toInt(),
            'TxLgID' => $entity->languageId()->toInt(),
            'TxTitle' => $entity->title(),
            'TxText' => $entity->text(),
            'TxAnnotatedText' => $entity->annotatedText(),
            'TxAudioURI' => $entity->mediaUri(),
            'TxSourceURI' => $entity->sourceUri(),
            'TxPosition' => $entity->position(),
            'TxAudioPosition' => $entity->audioPosition(),
        ];
    }

    /**
     * {@inheritdoc}
     *
     * @param Text $entity
     */
    protected function getEntityId(object $entity): int
    {
        return $entity->id()->toInt();
    }

    /**
     * {@inheritdoc}
     *
     * @param Text $entity
     */
    protected function setEntityId(object $entity, int $id): void
    {
        $entity->setId(TextId::fromInt($id));
    }

    /**
     * {@inheritdoc}
     */
    public function find(int $id): ?Text
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
     */
    public function findAll(): array
    {
        $rows = $this->query()->getPrepared();

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
        ?string $orderBy = 'TxTitle',
        string $direction = 'ASC'
    ): array {
        $rows = $this->query()
            ->where('TxLgID', '=', $languageId)
            ->orderBy($orderBy ?? 'TxTitle', $direction)
            ->getPrepared();

        return array_map(
            fn(array $row) => $this->mapToEntity($row),
            $rows
        );
    }

    /**
     * {@inheritdoc}
     */
    public function findByTitle(int $languageId, string $title): ?Text
    {
        $row = $this->query()
            ->where('TxLgID', '=', $languageId)
            ->where('TxTitle', '=', $title)
            ->firstPrepared();

        return $row !== null ? $this->mapToEntity($row) : null;
    }

    /**
     * {@inheritdoc}
     */
    public function titleExists(int $languageId, string $title, ?int $excludeId = null): bool
    {
        $query = $this->query()
            ->where('TxLgID', '=', $languageId)
            ->where('TxTitle', '=', $title);

        if ($excludeId !== null) {
            $query->where('TxID', '!=', $excludeId);
        }

        return $query->existsPrepared();
    }

    /**
     * {@inheritdoc}
     */
    public function countByLanguage(int $languageId): int
    {
        return $this->query()
            ->where('TxLgID', '=', $languageId)
            ->countPrepared();
    }

    /**
     * {@inheritdoc}
     */
    public function getForSelect(int $languageId = 0, int $maxNameLength = 40): array
    {
        $query = $this->query()
            ->select(['TxID', 'TxTitle', 'TxLgID'])
            ->orderBy('TxTitle');

        if ($languageId > 0) {
            $query->where('TxLgID', '=', $languageId);
        }

        $rows = $query->getPrepared();
        $result = [];

        foreach ($rows as $row) {
            $title = (string) $row['TxTitle'];
            if (mb_strlen($title, 'UTF-8') > $maxNameLength) {
                $title = mb_substr($title, 0, $maxNameLength, 'UTF-8') . '...';
            }
            $result[] = [
                'id' => (int) $row['TxID'],
                'title' => $title,
                'language_id' => (int) $row['TxLgID'],
            ];
        }

        return $result;
    }

    /**
     * Find texts with media (audio/video).
     *
     * @param int|null $languageId Language ID (null for all)
     *
     * @return Text[]
     */
    public function findWithMedia(?int $languageId = null): array
    {
        $query = $this->query()
            ->where('TxAudioURI', '!=', '')
            ->orderBy('TxTitle');

        if ($languageId !== null) {
            $query->where('TxLgID', '=', $languageId);
        }

        $rows = $query->getPrepared();

        return array_map(
            fn(array $row) => $this->mapToEntity($row),
            $rows
        );
    }

    /**
     * Find annotated texts.
     *
     * @param int|null $languageId Language ID (null for all)
     *
     * @return Text[]
     */
    public function findAnnotated(?int $languageId = null): array
    {
        $query = $this->query()
            ->where('TxAnnotatedText', '!=', '')
            ->orderBy('TxTitle');

        if ($languageId !== null) {
            $query->where('TxLgID', '=', $languageId);
        }

        $rows = $query->getPrepared();

        return array_map(
            fn(array $row) => $this->mapToEntity($row),
            $rows
        );
    }

    /**
     * Find unannotated texts.
     *
     * @param int|null $languageId Language ID (null for all)
     *
     * @return Text[]
     */
    public function findUnannotated(?int $languageId = null): array
    {
        $query = $this->query()
            ->where('TxAnnotatedText', '=', '')
            ->orderBy('TxTitle');

        if ($languageId !== null) {
            $query->where('TxLgID', '=', $languageId);
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
    public function updatePosition(int $textId, int $position): bool
    {
        $affected = $this->query()
            ->where('TxID', '=', $textId)
            ->updatePrepared(['TxPosition' => max(0, $position)]);

        return $affected > 0;
    }

    /**
     * {@inheritdoc}
     */
    public function updateAudioPosition(int $textId, float $position): bool
    {
        $affected = $this->query()
            ->where('TxID', '=', $textId)
            ->updatePrepared(['TxAudioPosition' => max(0.0, $position)]);

        return $affected > 0;
    }

    /**
     * Reset reading progress for a text.
     *
     * @param int $textId Text ID
     *
     * @return bool True if updated
     */
    public function resetProgress(int $textId): bool
    {
        $affected = $this->query()
            ->where('TxID', '=', $textId)
            ->updatePrepared(
                [
                'TxPosition' => 0,
                'TxAudioPosition' => 0.0,
                ]
            );

        return $affected > 0;
    }

    /**
     * Clear the annotated text for a text.
     *
     * @param int $textId Text ID
     *
     * @return bool True if updated
     */
    public function clearAnnotation(int $textId): bool
    {
        $affected = $this->query()
            ->where('TxID', '=', $textId)
            ->updatePrepared(['TxAnnotatedText' => '']);

        return $affected > 0;
    }

    /**
     * {@inheritdoc}
     */
    public function getPreviousTextId(int $textId, int $languageId): ?int
    {
        $row = $this->query()
            ->select('TxID')
            ->where('TxLgID', '=', $languageId)
            ->where('TxID', '<', $textId)
            ->orderBy('TxID', 'DESC')
            ->limit(1)
            ->firstPrepared();

        return $row !== null ? (int) $row['TxID'] : null;
    }

    /**
     * {@inheritdoc}
     */
    public function getNextTextId(int $textId, int $languageId): ?int
    {
        $row = $this->query()
            ->select('TxID')
            ->where('TxLgID', '=', $languageId)
            ->where('TxID', '>', $textId)
            ->orderBy('TxID', 'ASC')
            ->limit(1)
            ->firstPrepared();

        return $row !== null ? (int) $row['TxID'] : null;
    }

    /**
     * {@inheritdoc}
     */
    public function getBasicInfo(int $textId): ?array
    {
        $row = $this->query()
            ->select(
                [
                'TxID',
                'TxTitle',
                'TxLgID',
                'TxAudioURI',
                'LENGTH(TxAnnotatedText) AS annotlen'
                ]
            )
            ->where('TxID', '=', $textId)
            ->firstPrepared();

        if ($row === null) {
            return null;
        }

        return [
            'id' => (int) $row['TxID'],
            'title' => (string) $row['TxTitle'],
            'language_id' => (int) $row['TxLgID'],
            'has_media' => !empty($row['TxAudioURI']),
            'has_annotation' => !empty($row['annotlen']),
        ];
    }

    /**
     * Get texts with pagination and language info.
     *
     * @param int    $languageId Language ID (0 for all)
     * @param int    $page       Page number (1-based)
     * @param int    $perPage    Items per page
     * @param string $orderBy    Column to order by
     * @param string $direction  Sort direction
     *
     * @return array{items: Text[], total: int, page: int, per_page: int, total_pages: int}
     */
    public function findPaginated(
        int $languageId = 0,
        int $page = 1,
        int $perPage = 20,
        string $orderBy = 'TxTitle',
        string $direction = 'ASC'
    ): array {
        $query = $this->query();

        if ($languageId > 0) {
            $query->where('TxLgID', '=', $languageId);
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
     * Search texts by title.
     *
     * @param string   $query      Search query
     * @param int|null $languageId Language ID (null for all)
     * @param int      $limit      Maximum results
     *
     * @return Text[]
     */
    public function searchByTitle(string $query, ?int $languageId = null, int $limit = 50): array
    {
        $searchPattern = '%' . str_replace(['%', '_'], ['\\%', '\\_'], $query) . '%';

        $dbQuery = $this->query()
            ->where('TxTitle', 'LIKE', $searchPattern)
            ->orderBy('TxTitle')
            ->limit($limit);

        if ($languageId !== null) {
            $dbQuery->where('TxLgID', '=', $languageId);
        }

        $rows = $dbQuery->getPrepared();

        return array_map(
            fn(array $row) => $this->mapToEntity($row),
            $rows
        );
    }

    /**
     * Get language IDs that have texts.
     *
     * @return int[] Array of language IDs
     */
    public function getLanguagesWithTexts(): array
    {
        $rows = $this->query()
            ->select('DISTINCT TxLgID')
            ->getPrepared();

        return array_map(
            fn(array $row) => (int) $row['TxLgID'],
            $rows
        );
    }

    /**
     * Get statistics for texts.
     *
     * @param int|null $languageId Language ID (null for all)
     *
     * @return array{total: int, with_media: int, annotated: int, with_source: int}
     */
    public function getStatistics(?int $languageId = null): array
    {
        $baseQuery = $this->query();
        if ($languageId !== null) {
            $baseQuery->where('TxLgID', '=', $languageId);
        }

        $total = (clone $baseQuery)->countPrepared();

        $withMedia = (clone $baseQuery)
            ->where('TxAudioURI', '!=', '')
            ->countPrepared();

        $annotated = (clone $baseQuery)
            ->where('TxAnnotatedText', '!=', '')
            ->countPrepared();

        $withSource = (clone $baseQuery)
            ->where('TxSourceURI', '!=', '')
            ->countPrepared();

        return [
            'total' => $total,
            'with_media' => $withMedia,
            'annotated' => $annotated,
            'with_source' => $withSource,
        ];
    }
}
