<?php

/**
 * MySQL Archived Text Tag Association
 *
 * Infrastructure adapter for archived text-tag associations using MySQL.
 * Archived texts now share the text_tag_map table with active texts,
 * differentiated by TxArchivedAt IS NOT NULL.
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
use Lwt\Shared\Infrastructure\Database\DB;
use Lwt\Shared\Infrastructure\Database\QueryBuilder;
use Lwt\Modules\Tags\Domain\TagAssociationInterface;
use Lwt\Modules\Tags\Domain\TagRepositoryInterface;

/**
 * MySQL implementation of TagAssociationInterface for archived text-tag links.
 *
 * Operates on the 'text_tag_map' junction table for archived texts
 * (texts where TxArchivedAt IS NOT NULL).
 *
 * @since 3.0.0
 */
class MySqlArchivedTextTagAssociation implements TagAssociationInterface
{
    private const TABLE_NAME = 'text_tag_map';
    private const ITEM_COLUMN = 'TtTxID';
    private const TAG_COLUMN = 'TtT2ID';

    private TagRepositoryInterface $tagRepository;

    /**
     * Constructor.
     *
     * @param TagRepositoryInterface $tagRepository Text tag repository
     */
    public function __construct(TagRepositoryInterface $tagRepository)
    {
        $this->tagRepository = $tagRepository;
    }

    /**
     * Get a query builder for this association's table.
     *
     * @return QueryBuilder
     */
    private function query(): QueryBuilder
    {
        return QueryBuilder::table(self::TABLE_NAME);
    }

    /**
     * {@inheritdoc}
     */
    public function getTagIdsForItem(int $itemId): array
    {
        $rows = $this->query()
            ->select([self::TAG_COLUMN])
            ->where(self::ITEM_COLUMN, '=', $itemId)
            ->getPrepared();

        return array_map('intval', array_column($rows, self::TAG_COLUMN));
    }

    /**
     * {@inheritdoc}
     */
    public function getTagTextsForItem(int $itemId): array
    {
        $rows = Connection::preparedFetchAll(
            'SELECT T2Text FROM text_tag_map, text_tags WHERE T2ID = TtT2ID AND TtTxID = ? ORDER BY T2Text',
            [$itemId]
        );

        /** @var list<string> */
        return array_column($rows, 'T2Text');
    }

    /**
     * {@inheritdoc}
     */
    public function setTagsForItem(int $itemId, array $tagIds): void
    {
        DB::beginTransaction();
        try {
            // Delete existing associations
            $this->clearTagsForItem($itemId);

            // Insert new associations
            foreach ($tagIds as $tagId) {
                $this->addTag($itemId, $tagId);
            }

            DB::commit();
        } catch (\Throwable $e) {
            DB::rollback();
            throw $e;
        }
    }

    /**
     * {@inheritdoc}
     */
    public function setTagsByName(int $itemId, array $tagNames): void
    {
        DB::beginTransaction();
        try {
            // Delete existing associations
            $this->clearTagsForItem($itemId);

            // Create/get tags and associate them
            foreach ($tagNames as $tagName) {
                $tagName = trim($tagName);
                if ($tagName === '') {
                    continue;
                }

                // Get or create the tag for the current user. getOrCreate is
                // user-scoped, so $tagId already points at the right row; using
                // it directly avoids a T2Text re-lookup that would otherwise
                // match another user's tag with the same name.
                $tagId = $this->tagRepository->getOrCreate($tagName);

                Connection::preparedExecute(
                    'INSERT IGNORE INTO text_tag_map (TtTxID, TtT2ID) VALUES (?, ?)',
                    [$itemId, $tagId]
                );
            }

            DB::commit();
        } catch (\Throwable $e) {
            DB::rollback();
            throw $e;
        }
    }

    /**
     * {@inheritdoc}
     */
    public function addTag(int $itemId, int $tagId): bool
    {
        if ($this->hasTag($itemId, $tagId)) {
            return false;
        }

        $this->query()->insertPrepared([
            self::ITEM_COLUMN => $itemId,
            self::TAG_COLUMN => $tagId,
        ]);

        return true;
    }

    /**
     * {@inheritdoc}
     */
    public function removeTag(int $itemId, int $tagId): bool
    {
        $affected = $this->query()
            ->where(self::ITEM_COLUMN, '=', $itemId)
            ->where(self::TAG_COLUMN, '=', $tagId)
            ->deletePrepared();

        return $affected > 0;
    }

    /**
     * {@inheritdoc}
     */
    public function addTagToItems(int $tagId, array $itemIds): int
    {
        if (empty($itemIds)) {
            return 0;
        }

        $count = 0;
        foreach ($itemIds as $itemId) {
            if ($this->addTag($itemId, $tagId)) {
                $count++;
            }
        }

        return $count;
    }

    /**
     * {@inheritdoc}
     */
    public function removeTagFromItems(int $tagId, array $itemIds): int
    {
        if (empty($itemIds)) {
            return 0;
        }

        $itemIds = array_map('intval', $itemIds);
        return $this->query()
            ->where(self::TAG_COLUMN, '=', $tagId)
            ->whereIn(self::ITEM_COLUMN, $itemIds)
            ->deletePrepared();
    }

    /**
     * {@inheritdoc}
     */
    public function clearTagsForItem(int $itemId): int
    {
        return $this->query()
            ->where(self::ITEM_COLUMN, '=', $itemId)
            ->deletePrepared();
    }

    /**
     * {@inheritdoc}
     */
    public function clearItemsForTag(int $tagId): int
    {
        return $this->query()
            ->where(self::TAG_COLUMN, '=', $tagId)
            ->deletePrepared();
    }

    /**
     * {@inheritdoc}
     */
    public function cleanupOrphanedLinks(): int
    {
        // Delete text_tag_map where the tag no longer exists
        return Connection::preparedExecute(
            'DELETE FROM text_tag_map WHERE TtT2ID NOT IN (SELECT T2ID FROM text_tags)',
            []
        );
    }

    /**
     * {@inheritdoc}
     *
     * Returns count of archived texts with this tag (TxArchivedAt IS NOT NULL).
     */
    public function getItemCount(int $tagId): int
    {
        // Count only archived texts with this tag
        $row = Connection::preparedFetchOne(
            'SELECT COUNT(*) as cnt FROM text_tag_map
             JOIN texts ON TtTxID = TxID
             WHERE TtT2ID = ? AND TxArchivedAt IS NOT NULL',
            [$tagId]
        );
        return (int) ($row['cnt'] ?? 0);
    }

    /**
     * {@inheritdoc}
     */
    public function hasTag(int $itemId, int $tagId): bool
    {
        return $this->query()
            ->where(self::ITEM_COLUMN, '=', $itemId)
            ->where(self::TAG_COLUMN, '=', $tagId)
            ->existsPrepared();
    }
}
