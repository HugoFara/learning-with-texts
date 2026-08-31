<?php

/**
 * Tag CRUD API Handler
 *
 * The paginated, writable half of the tag API. `TagApiHandler` keeps serving
 * the flat name lists that autocomplete and the Tagify inputs rely on; this
 * handler backs the tag management pages.
 *
 * PHP version 8.2
 *
 * @category Lwt
 * @package  Lwt\Modules\Tags\Http
 * @author   HugoFara <git@hugofara.net>
 * @license  Unlicense <http://unlicense.org/>
 * @link     https://hugofara.github.io/lwt/developer/api
 * @since    3.4.0
 */

declare(strict_types=1);

namespace Lwt\Modules\Tags\Http;

use Lwt\Modules\Tags\Application\TagsFacade;

/**
 * Paginated listing and mutation for term and text tags.
 *
 * @since 3.4.0
 */
class TagCrudApiHandler
{
    /**
     * Resolve the facade for a tag type.
     *
     * @param string $type Either `term` or `text`
     *
     * @return TagsFacade|null Facade, or null when the type is unknown
     */
    private function facadeFor(string $type): ?TagsFacade
    {
        if ($type === 'term') {
            return TagsFacade::forTermTags();
        }
        if ($type === 'text') {
            return TagsFacade::forTextTags();
        }
        return null;
    }

    /**
     * List tags of one type, paginated and filtered.
     *
     * @param string               $type   Either `term` or `text`
     * @param array<string, mixed> $params Query parameters
     *
     * @return array<string, mixed> Tags, pagination and the sort options
     */
    public function list(string $type, array $params): array
    {
        $facade = $this->facadeFor($type);
        if ($facade === null) {
            return ['error' => 'Expected tag type "term" or "text"'];
        }

        $query = (string) ($params['query'] ?? '');
        $sort = max(1, (int) ($params['sort'] ?? 1));
        $page = max(1, (int) ($params['page'] ?? 1));

        $total = $facade->getCount($query);
        $pagination = $facade->getPagination($total, $page);

        /** @var list<array{id: int, text: string, comment: string, usageCount: int,
         *                  archivedUsageCount?: int}> $rows */
        $rows = $facade->getList(
            $query,
            $facade->getSortColumn($sort),
            $pagination['currentPage'],
            $pagination['perPage']
        );

        // The "items with this tag" links are built from patterns the tag type
        // owns, so they travel with the rows rather than being reconstructed
        // client-side from a hardcoded shape.
        $tags = [];
        foreach ($rows as $row) {
            $row['itemsUrl'] = $facade->getItemsUrl($row['id']);
            $row['archivedItemsUrl'] = $facade->getArchivedItemsUrl($row['id']);
            $tags[] = $row;
        }

        return [
            'tags' => $tags,
            'pagination' => [
                'page' => $pagination['currentPage'],
                'per_page' => $pagination['perPage'],
                'total' => $total,
                'total_pages' => $pagination['pages'],
            ],
            'sortOptions' => $facade->getSortOptions(),
            'type' => $type,
            'baseUrl' => $facade->getBaseUrl(),
        ];
    }

    /**
     * Read one tag.
     *
     * @param string $type Either `term` or `text`
     * @param int    $id   Tag ID
     *
     * @return array<string, mixed> The tag, or an error
     */
    public function get(string $type, int $id): array
    {
        $facade = $this->facadeFor($type);
        if ($facade === null) {
            return ['error' => 'Expected tag type "term" or "text"'];
        }

        $tag = $facade->getById($id);
        if ($tag === null) {
            return ['error' => 'Tag not found'];
        }

        return ['tag' => $tag];
    }

    /**
     * Create a tag.
     *
     * @param string               $type Either `term` or `text`
     * @param array<string, mixed> $data Payload carrying `text` and `comment`
     *
     * @return array{success: bool, id?: int, error?: string}
     */
    public function create(string $type, array $data): array
    {
        $facade = $this->facadeFor($type);
        if ($facade === null) {
            return ['success' => false, 'error' => 'Expected tag type "term" or "text"'];
        }

        $text = trim((string) ($data['text'] ?? ''));
        if ($text === '') {
            return ['success' => false, 'error' => 'Tag text is required'];
        }

        $result = $facade->create($text, (string) ($data['comment'] ?? ''));
        if (!$result['success'] || $result['tag'] === null) {
            return ['success' => false, 'error' => $result['error'] ?? 'Failed to create tag'];
        }

        return ['success' => true, 'id' => $result['tag']->id()->toInt()];
    }

    /**
     * Update a tag.
     *
     * @param string               $type Either `term` or `text`
     * @param int                  $id   Tag ID
     * @param array<string, mixed> $data Payload carrying `text` and `comment`
     *
     * @return array{success: bool, error?: string}
     */
    public function update(string $type, int $id, array $data): array
    {
        $facade = $this->facadeFor($type);
        if ($facade === null) {
            return ['success' => false, 'error' => 'Expected tag type "term" or "text"'];
        }

        if ($facade->getById($id) === null) {
            return ['success' => false, 'error' => 'Tag not found'];
        }

        $text = trim((string) ($data['text'] ?? ''));
        if ($text === '') {
            return ['success' => false, 'error' => 'Tag text is required'];
        }

        $result = $facade->update($id, $text, (string) ($data['comment'] ?? ''));
        if (!$result['success']) {
            return ['success' => false, 'error' => $result['error'] ?? 'Failed to update tag'];
        }

        return ['success' => true];
    }

    /**
     * Delete one tag.
     *
     * @param string $type Either `term` or `text`
     * @param int    $id   Tag ID
     *
     * @return array{success: bool, deleted: int, error?: string}
     */
    public function delete(string $type, int $id): array
    {
        $facade = $this->facadeFor($type);
        if ($facade === null) {
            return ['success' => false, 'deleted' => 0, 'error' => 'Expected tag type "term" or "text"'];
        }

        if ($facade->getById($id) === null) {
            return ['success' => false, 'deleted' => 0, 'error' => 'Tag not found'];
        }

        $facade->delete($id);
        $facade->cleanupOrphanedLinks();

        return ['success' => true, 'deleted' => 1];
    }

    /**
     * Delete several tags, or every tag matching a filter.
     *
     * `all` deletes everything the current filter selects, which is what the
     * list page's "delete all" action does; without it only `ids` are removed.
     *
     * @param string               $type Either `term` or `text`
     * @param array<string, mixed> $data Payload carrying `ids`, or `all` + `query`
     *
     * @return array{success: bool, deleted: int, error?: string}
     */
    public function deleteMany(string $type, array $data): array
    {
        $facade = $this->facadeFor($type);
        if ($facade === null) {
            return ['success' => false, 'deleted' => 0, 'error' => 'Expected tag type "term" or "text"'];
        }

        if (!empty($data['all'])) {
            $result = $facade->deleteAll((string) ($data['query'] ?? ''));
            $facade->cleanupOrphanedLinks();
            return ['success' => true, 'deleted' => $result['count']];
        }

        $ids = $data['ids'] ?? [];
        if (!is_array($ids) || count($ids) === 0) {
            return ['success' => false, 'deleted' => 0, 'error' => 'No tags selected'];
        }

        $result = $facade->deleteMultiple(array_map('intval', $ids));
        $facade->cleanupOrphanedLinks();

        return ['success' => true, 'deleted' => $result['count']];
    }
}
