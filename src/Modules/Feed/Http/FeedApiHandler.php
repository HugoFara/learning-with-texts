<?php

/**
 * Feed API Handler
 *
 * Thin facade delegating to focused sub-handlers:
 * - FeedCrudApiHandler: feed CRUD operations (list, get, create, update, delete)
 * - FeedArticleApiHandler: article management (list, delete, import, reset errors)
 * - FeedLoadApiHandler: feed loading, parsing, and auto-update
 * - FeedWizardApiHandler: feed and article previews for the wizard
 *
 * PHP version 8.1
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

use Lwt\Api\V1\Response;
use Lwt\Shared\Http\ApiRoutableInterface;
use Lwt\Shared\Http\ApiRoutableTrait;
use Lwt\Shared\Infrastructure\Http\JsonResponse;
use Lwt\Modules\Feed\Application\FeedFacade;

/**
 * API handler for feed-related operations.
 *
 * Delegates to FeedCrudApiHandler, FeedArticleApiHandler,
 * FeedLoadApiHandler and FeedWizardApiHandler for actual logic.
 *
 * @since 3.0.0
 */
class FeedApiHandler implements ApiRoutableInterface
{
    use ApiRoutableTrait;

    private FeedCrudApiHandler $crud;
    private FeedArticleApiHandler $article;
    private FeedLoadApiHandler $load;
    private FeedWizardApiHandler $wizard;

    public function __construct(FeedFacade $feedFacade)
    {
        $this->crud = new FeedCrudApiHandler($feedFacade);
        $this->article = new FeedArticleApiHandler($feedFacade);
        $this->load = new FeedLoadApiHandler($feedFacade);
        $this->wizard = new FeedWizardApiHandler($feedFacade);
    }

    // =========================================================================
    // Feed CRUD (delegates to FeedCrudApiHandler)
    // =========================================================================

    /**
     * Get list of feeds with pagination and filtering.
     *
     * @param array $params Filter parameters
     *
     * @return array{feeds: array, pagination: array, languages: array}
     */
    public function getFeedList(array $params): array
    {
        return $this->crud->getFeedList($params);
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
        return $this->crud->formatFeedRecord($row);
    }

    /**
     * Get languages for filter dropdown.
     *
     * @return array Array of language options
     */
    public function getLanguagesForSelect(): array
    {
        return $this->crud->getLanguagesForSelect();
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
        return $this->crud->getFeed($feedId);
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
        return $this->crud->createFeed($data);
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
        return $this->crud->updateFeed($feedId, $data);
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
        return $this->crud->deleteFeeds($feedIds);
    }

    /**
     * Format response for getting feed list.
     *
     * @param array $params Filter parameters
     *
     * @return array Feed list with pagination
     */
    public function formatGetFeedList(array $params): array
    {
        return $this->crud->formatGetFeedList($params);
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
        return $this->crud->formatGetFeed($feedId);
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
        return $this->crud->formatCreateFeed($data);
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
        return $this->crud->formatUpdateFeed($feedId, $data);
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
        return $this->crud->formatDeleteFeeds($feedIds);
    }

    // =========================================================================
    // Articles (delegates to FeedArticleApiHandler)
    // =========================================================================

    /**
     * Get articles for a feed.
     *
     * @param array $params Parameters
     *
     * @return array{articles?: array, pagination?: array, feed?: array, error?: string}
     */
    public function getArticles(array $params): array
    {
        return $this->article->getArticles($params);
    }

    /**
     * Format an article record for API response.
     *
     * @param array $row Database record
     *
     * @return array Formatted article data
     */
    public function formatArticleRecord(array $row): array
    {
        return $this->article->formatArticleRecord($row);
    }

    /**
     * Delete articles.
     *
     * @param int   $feedId     Feed ID
     * @param array $articleIds Article IDs to delete (empty = all)
     *
     * @return array{success: bool, deleted: int, error?: string}
     */
    public function deleteArticles(int $feedId, array $articleIds = []): array
    {
        return $this->article->deleteArticles($feedId, $articleIds);
    }

    /**
     * Import articles as texts.
     *
     * @param array $data Import data
     *
     * @return array{success: bool, imported: int, errors: array}
     */
    public function importArticles(array $data): array
    {
        return $this->article->importArticles($data);
    }

    /**
     * Reset error articles (remove leading space from links).
     *
     * @param int $feedId Feed ID
     *
     * @return array{success: bool, reset: int}
     */
    public function resetErrorArticles(int $feedId): array
    {
        return $this->article->resetErrorArticles($feedId);
    }

    /**
     * Format response for getting articles.
     *
     * @param array $params Filter parameters
     *
     * @return array Articles with pagination
     */
    public function formatGetArticles(array $params): array
    {
        return $this->article->formatGetArticles($params);
    }

    /**
     * Format response for deleting articles.
     *
     * @param int   $feedId     Feed ID
     * @param array $articleIds Article IDs (empty = all)
     *
     * @return array Deletion result
     */
    public function formatDeleteArticles(int $feedId, array $articleIds = []): array
    {
        return $this->article->formatDeleteArticles($feedId, $articleIds);
    }

    /**
     * Format response for importing articles.
     *
     * @param array $data Import data
     *
     * @return array Import result
     */
    public function formatImportArticles(array $data): array
    {
        return $this->article->formatImportArticles($data);
    }

    /**
     * Format response for resetting error articles.
     *
     * @param int $feedId Feed ID
     *
     * @return array Reset result
     */
    public function formatResetErrorArticles(int $feedId): array
    {
        return $this->article->formatResetErrorArticles($feedId);
    }

    // =========================================================================
    // Feed Loading (delegates to FeedLoadApiHandler)
    // =========================================================================

    /**
     * Get the list of feeds and insert them into the database.
     *
     * @param array<array<string, string>> $feed   A feed with articles
     * @param int                          $feedId Feed ID
     *
     * @return array{0: int, 1: int} Number of imported feeds and number of duplicated feeds.
     */
    public function getFeedsList(array $feed, int $feedId): array
    {
        return $this->load->getFeedsList($feed, $feedId);
    }

    /**
     * Update the feeds database and return a result message.
     *
     * @param int    $importedCount  Number of imported feeds
     * @param int    $duplicateCount Number of duplicated feeds
     * @param string $feedName       Feed name
     * @param int    $feedId         Feed ID
     * @param string $feedOptions    Feed options
     *
     * @return string Result message
     */
    public function getFeedResult(
        int $importedCount,
        int $duplicateCount,
        string $feedName,
        int $feedId,
        string $feedOptions
    ): string {
        return $this->load->getFeedResult($importedCount, $duplicateCount, $feedName, $feedId, $feedOptions);
    }

    /**
     * Load a feed and return result.
     *
     * @param string $feedName      Feed name
     * @param int    $feedId        Feed ID
     * @param string $feedSourceUri Feed source URI
     * @param string $feedOptions   Feed options
     *
     * @return array{success?: true, message?: string, imported?: int, duplicates?: int, error?: string}
     */
    public function loadFeed(string $feedName, int $feedId, string $feedSourceUri, string $feedOptions): array
    {
        return $this->load->loadFeed($feedName, $feedId, $feedSourceUri, $feedOptions);
    }

    /**
     * Format response for loading a feed.
     *
     * @param string $name      Feed name
     * @param int    $feedId    Feed ID
     * @param string $sourceUri Feed source URI
     * @param string $options   Feed options
     *
     * @return array{success?: true, message?: string, imported?: int, duplicates?: int, error?: string}
     */
    public function formatLoadFeed(string $name, int $feedId, string $sourceUri, string $options): array
    {
        return $this->load->formatLoadFeed($name, $feedId, $sourceUri, $options);
    }

    /**
     * Parse an RSS feed for preview.
     *
     * @param string $sourceUri      Feed URL
     * @param string $articleSection Article section tag
     *
     * @return array|null Feed data or null on error
     */
    public function parseFeed(string $sourceUri, string $articleSection = ''): ?array
    {
        return $this->load->parseFeed($sourceUri, $articleSection);
    }

    /**
     * Detect feed format and parse.
     *
     * @param string $sourceUri Feed URL
     *
     * @return array|null Feed data with metadata or null on error
     */
    public function detectFeed(string $sourceUri): ?array
    {
        return $this->load->detectFeed($sourceUri);
    }

    /**
     * Get list of feeds (simple version).
     *
     * @param int|null $languageId Language ID filter (null for all)
     *
     * @return array Array of feeds
     */
    public function getFeeds(?int $languageId = null): array
    {
        return $this->load->getFeeds($languageId);
    }

    /**
     * Get feeds needing auto-update.
     *
     * @return array Array of feeds
     */
    public function getFeedsNeedingAutoUpdate(): array
    {
        return $this->load->getFeedsNeedingAutoUpdate();
    }

    /**
     * Get feed load configuration for frontend.
     *
     * @param int  $feedId         Feed ID
     * @param bool $checkAutoupdate Check auto-update feeds
     *
     * @return array Configuration
     */
    public function getFeedLoadConfig(int $feedId, bool $checkAutoupdate = false): array
    {
        return $this->load->getFeedLoadConfig($feedId, $checkAutoupdate);
    }

    // =========================================================================
    // API Routing Methods
    // =========================================================================

    public function routeGet(array $fragments, array $params): JsonResponse
    {
        $frag1 = $this->frag($fragments, 1);

        if ($frag1 === 'list') {
            return Response::success($this->crud->formatGetFeedList($params));
        }
        if ($frag1 === 'articles') {
            return Response::success($this->article->formatGetArticles($params));
        }
        if ($frag1 !== '' && ctype_digit($frag1)) {
            return Response::success($this->crud->formatGetFeed((int) $frag1));
        }

        return Response::error('Expected "list", "articles", or feed ID', 404);
    }

    public function routePost(array $fragments, array $params): JsonResponse
    {
        $frag1 = $this->frag($fragments, 1);
        $frag2 = $this->frag($fragments, 2);

        if ($frag1 === 'articles' && $frag2 === 'import') {
            return Response::success($this->article->formatImportArticles($params));
        }
        if ($frag1 === 'articles' && $frag2 === 'extract') {
            return Response::success($this->article->extractArticles($params));
        }
        if ($frag1 === 'articles' && $frag2 === 'create-texts') {
            return Response::success($this->article->createTextsFromEdited($params));
        }
        if ($frag1 === 'wizard' && $frag2 === 'preview') {
            return Response::success($this->wizard->previewFeed($params));
        }
        if ($frag1 === 'wizard' && $frag2 === 'article') {
            return Response::success($this->wizard->previewArticle($params));
        }
        if ($frag1 === '') {
            return Response::success($this->crud->formatCreateFeed($params));
        }
        if (ctype_digit($frag1) && $frag2 === 'load') {
            return Response::success($this->load->formatLoadFeed(
                (string) ($params['name'] ?? ''),
                (int) $frag1,
                (string) ($params['source_uri'] ?? ''),
                (string) ($params['options'] ?? '')
            ));
        }

        return Response::error(
            'Expected "articles/import", "articles/extract", "articles/create-texts", '
            . '"wizard/preview", "wizard/article", feed data, or "{id}/load"',
            404
        );
    }

    public function routePut(array $fragments, array $params): JsonResponse
    {
        $frag1 = $this->frag($fragments, 1);

        if ($frag1 === '' || !ctype_digit($frag1)) {
            return Response::error('Feed ID (Integer) Expected', 404);
        }

        $feedId = (int) $frag1;
        return Response::success($this->crud->formatUpdateFeed($feedId, $params));
    }

    public function routeDelete(array $fragments, array $params): JsonResponse
    {
        $frag1 = $this->frag($fragments, 1);
        $frag2 = $this->frag($fragments, 2);

        if ($frag1 === 'articles' && $frag2 !== '' && ctype_digit($frag2)) {
            $feedId = (int) $frag2;
            /** @var array<int> $articleIds */
            $articleIds = is_array($params['article_ids'] ?? null) ? $params['article_ids'] : [];
            return Response::success($this->article->formatDeleteArticles($feedId, $articleIds));
        }
        if ($frag1 !== '' && ctype_digit($frag1) && $frag2 === 'reset-errors') {
            return Response::success($this->article->formatResetErrorArticles((int) $frag1));
        }
        if ($frag1 === '') {
            /** @var array<int> $feedIds */
            $feedIds = is_array($params['feed_ids'] ?? null) ? $params['feed_ids'] : [];
            return Response::success($this->crud->formatDeleteFeeds($feedIds));
        }
        if (ctype_digit($frag1)) {
            return Response::success($this->crud->formatDeleteFeeds([(int) $frag1]));
        }

        return Response::error('Expected feed ID or "articles/{feedId}"', 404);
    }
}
