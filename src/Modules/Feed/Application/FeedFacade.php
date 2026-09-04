<?php

/**
 * Feed Facade
 *
 * PHP version 8.2
 *
 * @category Lwt
 * @package  Lwt\Modules\Feed\Application
 * @author   HugoFara <git@hugofara.net>
 * @license  Unlicense <http://unlicense.org/>
 * @link     https://hugofara.github.io/lwt/developer/api
 * @since    3.0.0
 */

declare(strict_types=1);

namespace Lwt\Modules\Feed\Application;

use Lwt\Modules\Feed\Application\Services\ArticleExtractor;
use Lwt\Modules\Feed\Application\Services\RssParser;
use Lwt\Modules\Feed\Application\UseCases\CreateFeed;
use Lwt\Modules\Feed\Application\UseCases\DeleteArticles;
use Lwt\Modules\Feed\Application\UseCases\DeleteFeeds;
use Lwt\Modules\Feed\Application\UseCases\GetArticles;
use Lwt\Modules\Feed\Application\UseCases\GetFeedById;
use Lwt\Modules\Feed\Application\UseCases\GetFeedList;
use Lwt\Modules\Feed\Application\UseCases\ImportArticles;
use Lwt\Modules\Feed\Application\UseCases\LoadFeed;
use Lwt\Modules\Feed\Application\UseCases\ResetErrorArticles;
use Lwt\Modules\Feed\Application\UseCases\UpdateFeed;
use Lwt\Modules\Feed\Domain\ArticleRepositoryInterface;
use Lwt\Modules\Feed\Domain\Feed;
use Lwt\Modules\Feed\Domain\FeedRepositoryInterface;
use Lwt\Modules\Feed\Domain\TextCreationInterface;

/**
 * Facade providing backward-compatible interface to Feed module.
 *
 * This facade wraps the use cases and services to provide a similar
 * interface to the original FeedService for gradual migration.
 *
 * @since 3.0.0
 */
class FeedFacade
{
    private CreateFeed $createFeed;
    private UpdateFeed $updateFeed;
    private DeleteFeeds $deleteFeeds;
    private LoadFeed $loadFeed;
    private GetFeedList $getFeedList;
    private GetFeedById $getFeedById;
    private GetArticles $getArticles;
    private ImportArticles $importArticles;
    private DeleteArticles $deleteArticles;
    private ResetErrorArticles $resetErrorArticles;
    private RssParser $rssParser;
    private ArticleExtractor $articleExtractor;
    private FeedRepositoryInterface $feedRepository;
    private ArticleRepositoryInterface $articleRepository;
    private TextCreationInterface $textCreation;

    /**
     * Constructor.
     *
     * @param FeedRepositoryInterface    $feedRepository    Feed repository
     * @param ArticleRepositoryInterface $articleRepository Article repository
     * @param TextCreationInterface      $textCreation      Text creation adapter
     * @param RssParser                  $rssParser         RSS parser
     * @param ArticleExtractor           $articleExtractor  Article extractor
     */
    public function __construct(
        FeedRepositoryInterface $feedRepository,
        ArticleRepositoryInterface $articleRepository,
        TextCreationInterface $textCreation,
        RssParser $rssParser,
        ArticleExtractor $articleExtractor
    ) {
        $this->feedRepository = $feedRepository;
        $this->articleRepository = $articleRepository;
        $this->textCreation = $textCreation;
        $this->rssParser = $rssParser;
        $this->articleExtractor = $articleExtractor;

        // Initialize use cases
        $this->createFeed = new CreateFeed($feedRepository);
        $this->updateFeed = new UpdateFeed($feedRepository);
        $this->deleteFeeds = new DeleteFeeds($feedRepository, $articleRepository);
        $this->loadFeed = new LoadFeed($feedRepository, $articleRepository, $rssParser);
        $this->getFeedList = new GetFeedList($feedRepository, $articleRepository);
        $this->getFeedById = new GetFeedById($feedRepository);
        $this->getArticles = new GetArticles($articleRepository);
        $this->importArticles = new ImportArticles(
            $articleRepository,
            $feedRepository,
            $textCreation,
            $articleExtractor
        );
        $this->deleteArticles = new DeleteArticles($articleRepository, $feedRepository);
        $this->resetErrorArticles = new ResetErrorArticles($articleRepository);
    }

    // =========================================================================
    // Feed CRUD Operations
    // =========================================================================

    /**
     * Get all news_feeds for a language (or all languages).
     *
     * @param int|null $langId Language ID filter (null for all)
     *
     * @return array<int, array{
     *     NfID: int|null, NfLgID: int, NfName: string, NfSourceURI: string,
     *     NfArticleSectionTags: string, NfFilterTags: string, NfUpdate: int, NfOptions: string
     * }> Array of feed records
     */
    public function getFeeds(?int $langId = null): array
    {
        $feeds = $this->getFeedList->executeAll($langId);

        return array_values(array_map(
            fn(Feed $feed) => $this->feedToArray($feed),
            $feeds
        ));
    }

    /**
     * Get a single feed by ID.
     *
     * @param int $feedId Feed ID
     *
     * @return array{
     *     NfID: int|null, NfLgID: int, NfName: string, NfSourceURI: string,
     *     NfArticleSectionTags: string, NfFilterTags: string, NfUpdate: int, NfOptions: string
     * }|null Feed record or null if not found
     */
    public function getFeedById(int $feedId): ?array
    {
        $feed = $this->getFeedById->execute($feedId);
        return $feed !== null ? $this->feedToArray($feed) : null;
    }

    /**
     * Count news_feeds with optional language and query filter.
     *
     * @param int|null    $langId       Language ID filter (null for all)
     * @param string|null $queryPattern LIKE pattern for name filter
     *
     * @return int Number of matching feeds
     */
    public function countFeeds(?int $langId = null, ?string $queryPattern = null): int
    {
        return $this->feedRepository->countFeeds($langId, $queryPattern);
    }

    /**
     * Create a new feed.
     *
     * @param array $data Feed data
     *
     * @return int New feed ID
     */
    public function createFeed(array $data): int
    {
        $feed = $this->createFeed->execute(
            (int) $data['NfLgID'],
            (string) $data['NfName'],
            (string) $data['NfSourceURI'],
            (string) ($data['NfArticleSectionTags'] ?? ''),
            (string) ($data['NfFilterTags'] ?? ''),
            rtrim((string) ($data['NfOptions'] ?? ''), ',')
        );

        return (int) $feed->id();
    }

    /**
     * Update an existing feed.
     *
     * @param int   $feedId Feed ID
     * @param array $data   Feed data
     *
     * @return void
     */
    public function updateFeed(int $feedId, array $data): void
    {
        $this->updateFeed->execute(
            $feedId,
            (int) $data['NfLgID'],
            (string) $data['NfName'],
            (string) $data['NfSourceURI'],
            (string) ($data['NfArticleSectionTags'] ?? ''),
            (string) ($data['NfFilterTags'] ?? ''),
            rtrim((string) ($data['NfOptions'] ?? ''), ',')
        );
    }

    /**
     * Delete feeds by ID(s).
     *
     * @param string $feedIds Comma-separated feed IDs
     *
     * @return array{feeds: int, articles: int} Counts of deleted items
     */
    public function deleteFeeds(string $feedIds): array
    {
        $ids = array_map('intval', explode(',', $feedIds));
        return $this->deleteFeeds->execute($ids);
    }

    // =========================================================================
    // Article Operations
    // =========================================================================

    /**
     * Get feed links (articles) for specified feeds.
     *
     * @param string $feedIds Comma-separated feed IDs
     * @param string $search  Search term (optional)
     * @param string $orderBy ORDER BY clause
     * @param int    $offset  Pagination offset
     * @param int    $limit   Pagination limit
     *
     * @return array Array of feed link records
     */
    public function getFeedLinks(
        string $feedIds,
        string $search = '',
        string $orderBy = 'FlDate DESC',
        int $offset = 0,
        int $limit = 50
    ): array {
        $ids = array_map('intval', explode(',', $feedIds));

        // Parse orderBy into column and direction
        $parts = explode(' ', trim($orderBy));
        $column = $parts[0];
        $direction = strtoupper($parts[1] ?? 'DESC');

        $result = $this->getArticles->execute(
            $ids,
            $offset,
            $limit,
            $column,
            $direction,
            $search
        );

        // Convert to legacy format
        return array_map(
            fn(array $item) => $this->articleToLegacyArray($item),
            $result['articles']
        );
    }

    /**
     * Count feed links for specified feeds.
     *
     * @param string $feedIds Comma-separated feed IDs
     * @param string $search  Search term (optional)
     *
     * @return int Number of matching feed links
     */
    public function countFeedLinks(string $feedIds, string $search = ''): int
    {
        $ids = array_map('intval', explode(',', $feedIds));
        return $this->articleRepository->countByFeeds($ids, $search);
    }

    /**
     * Delete all articles for specified feeds.
     *
     * @param string $feedIds Comma-separated feed IDs
     *
     * @return int Number of deleted articles
     */
    public function deleteArticles(string $feedIds): int
    {
        $ids = array_map('intval', explode(',', $feedIds));
        return $this->deleteArticles->executeByFeeds($ids);
    }

    /**
     * Reset unloadable articles (remove leading space from links).
     *
     * @param string $feedIds Comma-separated feed IDs
     *
     * @return int Number of reset articles
     */
    public function resetUnloadableArticles(string $feedIds): int
    {
        $ids = array_map('intval', explode(',', $feedIds));
        return $this->resetErrorArticles->execute($ids);
    }

    /**
     * Mark feed link as having an error.
     *
     * @param string $link Original link
     *
     * @return void
     */
    public function markLinkAsError(string $link): void
    {
        $this->articleRepository->markAsError($link);
    }

    /**
     * Get marked feed links for processing.
     *
     * @param array|string $markedItems Array or comma-separated string of IDs
     *
     * @return array<int, array{
     *     FlID: int|null, FlNfID: int, FlTitle: string, FlLink: string, FlDescription: string,
     *     FlDate: string, FlAudio: string, FlText: string, NfID: int|null, NfLgID: int,
     *     NfName: string, NfSourceURI: string, NfArticleSectionTags: string, NfFilterTags: string,
     *     NfUpdate: int, NfOptions: string
     * }> Array of feed link data with feed options
     */
    public function getMarkedFeedLinks($markedItems): array
    {
        if (is_array($markedItems)) {
            $ids = array_filter($markedItems, 'is_numeric');
        } else {
            $ids = array_map('intval', explode(',', $markedItems));
        }

        $articles = $this->getArticles->getByIds(array_map('intval', $ids));

        /** @var array<int, array{FlID: int|null, FlNfID: int, FlTitle: string, FlLink: string, FlDescription: string, FlDate: string, FlAudio: string, FlText: string, NfID: int|null, NfLgID: int, NfName: string, NfSourceURI: string, NfArticleSectionTags: string, NfFilterTags: string, NfUpdate: int, NfOptions: string}> $result */
        $result = [];
        foreach ($articles as $article) {
            $feed = $this->feedRepository->find($article->feedId());
            if ($feed === null) {
                continue;
            }

            $result[] = [
                ...$this->articleEntityToArray($article),
                ...$this->feedToArray($feed)
            ];
        }

        return $result;
    }

    // =========================================================================
    // RSS Feed Operations
    // =========================================================================

    /**
     * Parse RSS/Atom feed and return article links.
     *
     * @param string $sourceUri      Feed URL
     * @param string $articleSection Tag for inline text extraction
     *
     * @return array|false Array of feed items or false on error
     */
    public function parseRssFeed(string $sourceUri, string $articleSection): array|false
    {
        $result = $this->rssParser->parse($sourceUri, $articleSection);
        return $result ?? false;
    }

    /**
     * Detect and parse feed, determining best text source.
     *
     * @param string $sourceUri Feed URL
     *
     * @return array<int|string, array<string, string>|string>|false Feed data or false on error
     */
    public function detectAndParseFeed(string $sourceUri): array|false
    {
        $result = $this->rssParser->detectAndParse($sourceUri);
        return $result ?? false;
    }

    /**
     * Extract text content from RSS feed article links.
     *
     * @param array<int|string, array{link: string, title: string, audio?: string, text?: string}> $feedData
     *        Array of feed items
     * @param string      $articleSection XPath selector(s) for article content
     * @param string      $filterTags     XPath selector(s) for elements to remove
     * @param string|null $charset        Override charset
     *
     * @return array<int|string, array<string, mixed>> Extracted text data
     */
    public function extractTextFromArticle(
        array $feedData,
        string $articleSection,
        string $filterTags,
        ?string $charset = null
    ): array {
        return $this->articleExtractor->extract(
            $feedData,
            $articleSection,
            $filterTags,
            $charset
        );
    }

    /**
     * Load/refresh a feed from its RSS source.
     *
     * @param int $feedId Feed ID to load
     *
     * @return array Load result
     */
    public function loadFeed(int $feedId): array
    {
        return $this->loadFeed->execute($feedId);
    }

    /**
     * Get feeds that need auto-update.
     *
     * @return array Array of feeds needing update
     */
    public function getFeedsNeedingAutoUpdate(): array
    {
        $feeds = $this->feedRepository->findNeedingAutoUpdate(time());

        return array_map(
            fn(Feed $feed) => $this->feedToArray($feed),
            $feeds
        );
    }

    // =========================================================================
    // Text Creation Operations
    // =========================================================================

    /**
     * Create a text from feed link data.
     *
     * @param array  $textData Text data
     * @param string $tagName  Tag name to apply
     *
     * @return int New text ID
     */
    public function createTextFromFeed(array $textData, string $tagName): int
    {
        return $this->textCreation->createText(
            (int) $textData['TxLgID'],
            (string) $textData['TxTitle'],
            (string) $textData['TxText'],
            (string) ($textData['TxAudioURI'] ?? ''),
            (string) ($textData['TxSourceURI'] ?? ''),
            $tagName
        );
    }

    /**
     * Archive old texts with a specific tag.
     *
     * @param string $tagName  Tag name to filter
     * @param int    $maxTexts Maximum texts to keep
     *
     * @return array{archived: int, sentences: int, textitems: int}
     */
    public function archiveOldTexts(string $tagName, int $maxTexts): array
    {
        return $this->textCreation->archiveOldTexts($tagName, $maxTexts);
    }

    /**
     * Import articles as texts.
     *
     * @param int[] $articleIds Article IDs to import
     *
     * @return array Import result
     */
    public function importArticles(array $articleIds): array
    {
        return $this->importArticles->execute($articleIds);
    }

    // =========================================================================
    // Utility Methods
    // =========================================================================

    /**
     * Get a specific option from the feed options string.
     *
     * @param string $optionsStr Options string
     * @param string $option     Option name ('all' for array)
     *
     * @return string|array|null Option value
     */
    public function getFeedOption(string $optionsStr, string $option): string|array|null
    {
        $optionsStr = trim($optionsStr);
        if (empty($optionsStr)) {
            return ($option === 'all') ? [] : null;
        }

        $optionList = explode(',', $optionsStr);
        $result = [];

        foreach ($optionList as $opt) {
            $parts = explode('=', $opt);
            $key = trim($parts[0] ?? '');
            $value = trim($parts[1] ?? '');

            if (!empty($key)) {
                if ($option !== 'all' && $key === $option) {
                    return $value;
                }
                $result[$key] = $value;
            }
        }

        return $option === 'all' ? $result : null;
    }

    /**
     * Parse auto-update interval string to seconds.
     *
     * @param string $autoupdate Interval string (e.g., "2h", "1d", "1w")
     *
     * @return int|null Interval in seconds or null if invalid
     */
    public function parseAutoUpdateInterval(string $autoupdate): ?int
    {
        if (strpos($autoupdate, 'h') !== false) {
            return 60 * 60 * (int)str_replace('h', '', $autoupdate);
        } elseif (strpos($autoupdate, 'd') !== false) {
            return 60 * 60 * 24 * (int)str_replace('d', '', $autoupdate);
        } elseif (strpos($autoupdate, 'w') !== false) {
            return 60 * 60 * 24 * 7 * (int)str_replace('w', '', $autoupdate);
        }
        return null;
    }

    /**
     * Calculate last update time components.
     *
     * @param int $diff Time difference in seconds
     *
     * @return array{value: int, unit: string}|null Returns time data or null if up to date
     */
    public function getLastUpdateData(int $diff): ?array
    {
        $periods = [
            [60 * 60 * 24 * 365, 'year'],
            [60 * 60 * 24 * 30, 'month'],
            [60 * 60 * 24 * 7, 'week'],
            [60 * 60 * 24, 'day'],
            [60 * 60, 'hour'],
            [60, 'minute'],
            [1, 'second'],
        ];

        if ($diff < 1) {
            return null;
        }

        foreach ($periods as $period) {
            $x = intval($diff / $period[0]);
            if ($x >= 1) {
                $unit = $period[1] . ($x > 1 ? 's' : '');
                return ['value' => $x, 'unit' => $unit];
            }
        }

        return null;
    }

    /**
     * Format last update time as human-readable string.
     *
     * @param int $diff Time difference in seconds
     *
     * @return string Formatted string
     */
    public function formatLastUpdate(int $diff): string
    {
        $data = $this->getLastUpdateData($diff);
        if ($data === null) {
            return 'up to date';
        }
        return "last update: {$data['value']} {$data['unit']} ago";
    }

    /**
     * Get the sort options for feed/article lists.
     *
     * @return array Array of sort option arrays
     */
    public function getSortOptions(): array
    {
        return [
            ['value' => 1, 'text' => 'Title A-Z'],
            ['value' => 2, 'text' => 'Date Newest First'],
            ['value' => 3, 'text' => 'Date Oldest First'],
        ];
    }

    /**
     * Get the sort column for feeds/articles.
     *
     * @param int    $sortIndex Sort option index (1-3)
     * @param string $prefix    Column prefix
     *
     * @return string SQL ORDER BY column
     */
    public function getSortColumn(int $sortIndex, string $prefix = 'Fl'): string
    {
        if ($prefix === 'Nf') {
            $cols = [
                1 => 'NfName',
                2 => 'NfUpdate DESC',
                3 => 'NfUpdate ASC',
            ];
        } else {
            $cols = [
                1 => "{$prefix}Title",
                2 => "{$prefix}Date DESC",
                3 => "{$prefix}Date ASC",
            ];
        }

        return $cols[$sortIndex] ?? $cols[2];
    }

    /**
     * Build query filter condition for feed links.
     *
     * Returns structured filter data for use with prepared statements.
     * For backward compatibility, also returns a legacy SQL clause.
     *
     * @param string $query     Search query
     * @param string $queryMode Query mode ('title', 'title,desc,text')
     * @param string $regexMode Regex mode ('' for LIKE, 'R' for RLIKE)
     *
     * @return array{clause: string, search: string, mode: string, regex: string}
     *               Filter data with clause for legacy use and search for prepared statements
     */
    public function buildQueryFilter(string $query, string $queryMode, string $regexMode): array
    {
        if (empty($query)) {
            return ['clause' => '', 'search' => '', 'mode' => $queryMode, 'regex' => $regexMode];
        }

        $searchValue = ($regexMode === '')
            ? str_replace('*', '%', mb_strtolower($query, 'UTF-8'))
            : $query;

        // Build clause pattern for backward compatibility (used by legacy code paths)
        // Note: The search value is passed separately for use with prepared statements
        $operator = $regexMode . 'LIKE';

        switch ($queryMode) {
            case 'title,desc,text':
                $clause = " AND (FlTitle $operator ? OR FlDescription $operator ? OR FlText $operator ?)";
                break;
            case 'title':
                $clause = " AND (FlTitle $operator ?)";
                break;
            default:
                $clause = " AND (FlTitle $operator ? OR FlDescription $operator ? OR FlText $operator ?)";
                break;
        }

        return [
            'clause' => $clause,
            'search' => $searchValue,
            'mode' => $queryMode,
            'regex' => $regexMode
        ];
    }

    /**
     * Validate regex pattern for search.
     *
     * @param string $pattern Regex pattern
     *
     * @return bool True if valid
     */
    public function validateRegexPattern(string $pattern): bool
    {
        if (empty($pattern)) {
            return true;
        }

        try {
            $stmt = \Lwt\Shared\Infrastructure\Database\Connection::prepare(
                "SELECT 'test' RLIKE ?"
            );
            $stmt->bind('s', $pattern)->execute();
            return true;
        } catch (\mysqli_sql_exception $e) {
            return false;
        } catch (\Exception $e) {
            error_log('FeedFacade::validateRegexPattern: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Get feed load configuration for JavaScript.
     *
     * @param int  $currentFeed     Feed ID to load
     * @param bool $checkAutoupdate Whether to check auto-update
     *
     * @return array{feeds: array, count: int}
     */
    public function getFeedLoadConfig(int $currentFeed, bool $checkAutoupdate): array
    {
        $feeds = [];

        if ($checkAutoupdate) {
            $autoUpdateFeeds = $this->feedRepository->findNeedingAutoUpdate(time());
            foreach ($autoUpdateFeeds as $feed) {
                $feeds[] = [
                    'id' => (int) $feed->id(),
                    'name' => $feed->name(),
                    'sourceUri' => $feed->sourceUri(),
                    'options' => $feed->options()->toString(),
                ];
            }
        } else {
            $feed = $this->feedRepository->find($currentFeed);
            if ($feed !== null) {
                $feeds[] = [
                    'id' => (int) $feed->id(),
                    'name' => $feed->name(),
                    'sourceUri' => $feed->sourceUri(),
                    'options' => $feed->options()->toString(),
                ];
            }
        }

        return [
            'feeds' => $feeds,
            'count' => count($feeds),
        ];
    }

    // =========================================================================
    // Private Helpers
    // =========================================================================

    /**
     * Convert Feed entity to legacy array format.
     *
     * @param Feed $feed Feed entity
     *
     * @return array{
     *     NfID: int|null, NfLgID: int, NfName: string, NfSourceURI: string,
     *     NfArticleSectionTags: string, NfFilterTags: string, NfUpdate: int, NfOptions: string
     * } Legacy array format
     */
    private function feedToArray(Feed $feed): array
    {
        return [
            'NfID' => $feed->id(),
            'NfLgID' => $feed->languageId(),
            'NfName' => $feed->name(),
            'NfSourceURI' => $feed->sourceUri(),
            'NfArticleSectionTags' => $feed->articleSectionTags(),
            'NfFilterTags' => $feed->filterTags(),
            'NfUpdate' => $feed->updateTimestamp(),
            'NfOptions' => $feed->options()->toString(),
        ];
    }

    /**
     * Convert Article entity to legacy array format.
     *
     * @param \Lwt\Modules\Feed\Domain\Article $article Article entity
     *
     * @return array{
     *     FlID: int|null, FlNfID: int, FlTitle: string, FlLink: string, FlDescription: string,
     *     FlDate: string, FlAudio: string, FlText: string
     * } Legacy array format
     */
    private function articleEntityToArray($article): array
    {
        return [
            'FlID' => $article->id(),
            'FlNfID' => $article->feedId(),
            'FlTitle' => $article->title(),
            'FlLink' => $article->link(),
            'FlDescription' => $article->description(),
            'FlDate' => $article->date(),
            'FlAudio' => $article->audio(),
            'FlText' => $article->text(),
        ];
    }

    /**
     * Convert article result to legacy array format.
     *
     * @param array{
     *     article: \Lwt\Modules\Feed\Domain\Article, text_id: int|null, archived_id: int|null, status?: string
     * } $item Article result with status
     *
     * @return array{
     *     FlID: int, FlTitle: string, FlLink: string, FlDescription: string, FlDate: string,
     *     FlAudio: string, TxID: int|null, ArchivedTxID: int|null
     * } Legacy array format
     */
    private function articleToLegacyArray(array $item): array
    {
        $article = $item['article'];
        $id = $article->id();
        if ($id === null) {
            throw new \LogicException('Cannot convert unpersisted article to legacy format');
        }
        return [
            'FlID' => $id,
            'FlTitle' => $article->title(),
            'FlLink' => $article->link(),
            'FlDescription' => $article->description(),
            'FlDate' => $article->date(),
            'FlAudio' => $article->audio(),
            'TxID' => $item['text_id'],
            'ArchivedTxID' => $item['archived_id'],
        ];
    }

    /**
     * Get all languages for select dropdown.
     *
     * @return array<int, array<string, mixed>> Array of language records
     */
    public function getLanguages(): array
    {
        return \Lwt\Shared\Infrastructure\Database\QueryBuilder::table('languages')
            ->select(['LgID', 'LgName'])
            ->where('LgName', '<>', '')
            ->orderBy('LgName', 'ASC')
            ->getPrepared();
    }
}
