<?php

/**
 * Feed CRUD API Handler
 *
 * Handles feed CRUD operations: list, get, create, update, delete.
 *
 * PHP version 8.2
 *
 * @category Lwt
 * @package  Lwt\Modules\Feed\Http
 * @author   HugoFara <git@hugofara.net>
 * @license  Unlicense <http://unlicense.org/>
 * @link     https://hugofara.github.io/lwt/developer/api
 * @since    3.0.0
 */

declare(strict_types=1);

namespace Lwt\Modules\Feed\Http;

use Lwt\Shared\Infrastructure\Database\Connection;
use Lwt\Shared\Infrastructure\Database\QueryBuilder;
use Lwt\Shared\Infrastructure\Database\UserScopedQuery;
use Lwt\Modules\Feed\Application\FeedFacade;

/**
 * Sub-handler for feed CRUD API operations.
 *
 * @since 3.0.0
 */
class FeedCrudApiHandler
{
    private FeedFacade $feedFacade;

    public function __construct(FeedFacade $feedFacade)
    {
        $this->feedFacade = $feedFacade;
    }

    /**
     * Get list of feeds with pagination and filtering.
     *
     * @param array $params Filter parameters:
     *                      - lang: int|null (language ID filter)
     *                      - query: string|null (search query)
     *                      - page: int (default 1)
     *                      - per_page: int (default 50)
     *                      - sort: int (1=name, 2=update desc, 3=update asc)
     *
     * @return array{feeds: array, pagination: array, languages: array}
     */
    public function getFeedList(array $params): array
    {
        $page = max(1, (int)($params['page'] ?? 1));
        $perPage = max(1, min(100, (int)($params['per_page'] ?? 50)));
        $langId = isset($params['lang']) && $params['lang'] !== '' ? (int)$params['lang'] : null;
        $query = (string)($params['query'] ?? '');
        $sort = max(1, min(3, (int)($params['sort'] ?? 2)));

        // Build WHERE clause with parameters
        $whereConditions = ['1=1'];
        /** @var array<int, mixed> $queryParams */
        $queryParams = [];

        if ($langId !== null && $langId > 0) {
            $whereConditions[] = "NfLgID = ?";
            $queryParams[] = $langId;
        }
        if (is_string($query) && $query !== '') {
            $whereConditions[] = "NfName LIKE ?";
            $queryParams[] = '%' . str_replace('*', '%', $query) . '%';
        }

        // Scope to current user when multi-user mode is on. UserScopedQuery
        // returns "" in single-user mode, so legacy behaviour is preserved.
        // forTablePrepared yields " AND NfUsID = ?"; we need just
        // "NfUsID = ?" to fit the implode(' AND ', …) below, so strip the
        // five-char " AND " prefix.
        $userScope = UserScopedQuery::forTablePrepared('news_feeds', $queryParams);
        if ($userScope !== '') {
            $whereConditions[] = substr($userScope, 5);
        }

        $where = implode(' AND ', $whereConditions);

        // Count total using raw SQL with fixed table name
        $total = (int)Connection::preparedFetchValue(
            "SELECT COUNT(*) AS cnt FROM news_feeds WHERE $where",
            $queryParams,
            'cnt'
        );

        // Calculate pagination
        $totalPages = (int)ceil($total / $perPage);
        if ($page > $totalPages && $totalPages > 0) {
            $page = $totalPages;
        }
        $offset = ($page - 1) * $perPage;

        // Sort order
        $sorts = ['NfName ASC', 'NfUpdate DESC', 'NfUpdate ASC'];
        $orderBy = $sorts[$sort - 1] ?? 'NfUpdate DESC';

        // Get feeds with language names and article counts. The same
        // $where covers the user-scope filter we appended above.
        $sql = "SELECT nf.*, lg.LgName,
                       (SELECT COUNT(*) FROM feed_links WHERE FlNfID = NfID) AS articleCount
                FROM news_feeds nf
                LEFT JOIN languages lg ON lg.LgID = nf.NfLgID
                WHERE $where
                ORDER BY $orderBy
                LIMIT ?, ?";

        // Add pagination parameters
        $queryParams[] = $offset;
        $queryParams[] = $perPage;

        $feeds = [];
        $rows = Connection::preparedFetchAll($sql, $queryParams);
        foreach ($rows as $row) {
            $feeds[] = $this->formatFeedRecord($row);
        }

        // Get languages for filter dropdown
        $languages = $this->getLanguagesForSelect();

        return [
            'feeds' => $feeds,
            'pagination' => [
                'page' => $page,
                'per_page' => $perPage,
                'total' => $total,
                'total_pages' => $totalPages
            ],
            'languages' => $languages
        ];
    }

    /**
     * Format a feed record for API response.
     *
     * @param array $row Database record
     *
     * @return array Formatted feed data
     */
    public function formatFeedRecord(array $row): array
    {
        $options = $this->feedFacade->getFeedOption((string)$row['NfOptions'], 'all');
        $updateTimestamp = (int)$row['NfUpdate'];
        $lastUpdate = $updateTimestamp > 0
            ? $this->feedFacade->formatLastUpdate(time() - $updateTimestamp)
            : 'never';

        return [
            'id' => (int)$row['NfID'],
            'name' => (string)$row['NfName'],
            'sourceUri' => (string)$row['NfSourceURI'],
            'langId' => (int)$row['NfLgID'],
            'langName' => (string)($row['LgName'] ?? ''),
            'articleSectionTags' => (string)$row['NfArticleSectionTags'],
            'filterTags' => (string)$row['NfFilterTags'],
            'options' => is_array($options) ? $options : [],
            'optionsString' => (string)$row['NfOptions'],
            'updateTimestamp' => $updateTimestamp,
            'lastUpdate' => $lastUpdate,
            'articleCount' => (int)($row['articleCount'] ?? 0)
        ];
    }

    /**
     * Get languages for filter dropdown.
     *
     * @return array Array of language options
     */
    public function getLanguagesForSelect(): array
    {
        $languages = [];

        $rows = QueryBuilder::table('languages')
            ->select(['LgID', 'LgName'])
            ->orderBy('LgName', 'ASC')
            ->getPrepared();

        foreach ($rows as $row) {
            $languages[] = [
                'id' => (int)$row['LgID'],
                'name' => (string)$row['LgName']
            ];
        }

        return $languages;
    }

    /**
     * Get a single feed by ID.
     *
     * @param int $feedId Feed ID
     *
     * @return array Feed data or error
     */
    public function getFeed(int $feedId): array
    {
        $feed = $this->feedFacade->getFeedById($feedId);
        if ($feed === null) {
            return ['error' => 'Feed not found'];
        }

        $feed['LgName'] = '';
        $feed['articleCount'] = 0;

        // Get language name
        $langResult = QueryBuilder::table('languages')
            ->select(['LgName'])
            ->where('LgID', '=', $feed['NfLgID'])
            ->firstPrepared();
        if ($langResult !== null) {
            $feed['LgName'] = (string)$langResult['LgName'];
        }

        // Get article count
        $countResult = QueryBuilder::table('feed_links')
            ->select(['COUNT(*) AS cnt'])
            ->where('FlNfID', '=', $feedId)
            ->firstPrepared();
        if ($countResult !== null) {
            $feed['articleCount'] = (int)$countResult['cnt'];
        }

        return $this->formatFeedRecord($feed);
    }

    /**
     * Create a new feed.
     *
     * @param array $data Feed data
     *
     * @return array{success: bool, feed?: array, error?: string}
     */
    public function createFeed(array $data): array
    {
        $langId = (int)($data['langId'] ?? 0);
        $name = trim((string)($data['name'] ?? ''));
        $sourceUri = trim((string)($data['sourceUri'] ?? ''));

        if ($langId <= 0) {
            return ['success' => false, 'error' => 'Language is required'];
        }
        // Multi-user mass-assignment fence: NfLgID is a client-supplied
        // reference into `languages`, so without an ownership check an
        // attacker can pin their feed to another user's LgID.
        if (!\Lwt\Shared\Infrastructure\Globals::languageBelongsToCurrentUser($langId)) {
            return ['success' => false, 'error' => 'Language not found or access denied'];
        }
        if (empty($name)) {
            return ['success' => false, 'error' => 'Feed name is required'];
        }
        if (empty($sourceUri)) {
            return ['success' => false, 'error' => 'Source URI is required'];
        }

        $feedId = $this->feedFacade->createFeed([
            'NfLgID' => $langId,
            'NfName' => $name,
            'NfSourceURI' => $sourceUri,
            'NfArticleSectionTags' => $data['articleSectionTags'] ?? '',
            'NfFilterTags' => $data['filterTags'] ?? '',
            'NfOptions' => $data['options'] ?? ''
        ]);

        return [
            'success' => true,
            'feed' => $this->getFeed($feedId)
        ];
    }

    /**
     * Update an existing feed.
     *
     * @param int   $feedId Feed ID
     * @param array $data   Feed data
     *
     * @return array{success: bool, feed?: array, error?: string}
     */
    public function updateFeed(int $feedId, array $data): array
    {
        $existing = $this->feedFacade->getFeedById($feedId);
        if ($existing === null) {
            return ['success' => false, 'error' => 'Feed not found'];
        }

        // If the request reassigns NfLgID, the new LgID must also
        // belong to the caller — otherwise an attacker who owns one
        // feed could rotate it into another user's language.
        if (isset($data['langId'])) {
            $newLangId = (int) $data['langId'];
            if (
                $newLangId !== $existing['NfLgID']
                && !\Lwt\Shared\Infrastructure\Globals::languageBelongsToCurrentUser($newLangId)
            ) {
                return ['success' => false, 'error' => 'Language not found or access denied'];
            }
        }

        $this->feedFacade->updateFeed($feedId, [
            'NfLgID' => $data['langId'] ?? $existing['NfLgID'],
            'NfName' => $data['name'] ?? $existing['NfName'],
            'NfSourceURI' => $data['sourceUri'] ?? $existing['NfSourceURI'],
            'NfArticleSectionTags' => $data['articleSectionTags'] ?? $existing['NfArticleSectionTags'],
            'NfFilterTags' => $data['filterTags'] ?? $existing['NfFilterTags'],
            'NfOptions' => $data['options'] ?? $existing['NfOptions']
        ]);

        return [
            'success' => true,
            'feed' => $this->getFeed($feedId)
        ];
    }

    /**
     * Delete feeds.
     *
     * @param array $feedIds Array of feed IDs to delete
     *
     * @return array{success: bool, deleted: int}
     */
    public function deleteFeeds(array $feedIds): array
    {
        if (empty($feedIds)) {
            return ['success' => false, 'deleted' => 0];
        }

        $ids = implode(',', array_map('intval', $feedIds));
        $result = $this->feedFacade->deleteFeeds($ids);

        return [
            'success' => true,
            'deleted' => $result['feeds'] ?? 0
        ];
    }

    // =========================================================================
    // Format Wrappers
    // =========================================================================

    /**
     * Format response for getting feed list.
     *
     * @param array $params Filter parameters
     *
     * @return array Feed list with pagination
     */
    public function formatGetFeedList(array $params): array
    {
        return $this->getFeedList($params);
    }

    /**
     * Format response for getting single feed.
     *
     * @param int $feedId Feed ID
     *
     * @return array Feed data
     */
    public function formatGetFeed(int $feedId): array
    {
        return $this->getFeed($feedId);
    }

    /**
     * Format response for creating feed.
     *
     * @param array $data Feed data
     *
     * @return array Creation result
     */
    public function formatCreateFeed(array $data): array
    {
        return $this->createFeed($data);
    }

    /**
     * Format response for updating feed.
     *
     * @param int   $feedId Feed ID
     * @param array $data   Feed data
     *
     * @return array Update result
     */
    public function formatUpdateFeed(int $feedId, array $data): array
    {
        return $this->updateFeed($feedId, $data);
    }

    /**
     * Format response for deleting feeds.
     *
     * @param array $feedIds Feed IDs
     *
     * @return array Deletion result
     */
    public function formatDeleteFeeds(array $feedIds): array
    {
        return $this->deleteFeeds($feedIds);
    }
}
