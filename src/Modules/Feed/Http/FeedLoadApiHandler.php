<?php

/**
 * Feed Load API Handler
 *
 * Handles feed loading, parsing, and auto-update operations.
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
use Lwt\Shared\Infrastructure\Database\Settings;
use Lwt\Modules\Feed\Application\FeedFacade;

/**
 * Sub-handler for feed loading API operations.
 *
 * @since 3.0.0
 */
class FeedLoadApiHandler
{
    private FeedFacade $feedFacade;

    public function __construct(FeedFacade $feedFacade)
    {
        $this->feedFacade = $feedFacade;
    }

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
        if (empty($feed)) {
            return [0, 0];
        }

        // Build parameterized query with placeholders
        $placeholderRow = '(?, ?, ?, ?, ?, ?, ?)';
        $placeholders = array_fill(0, count($feed), $placeholderRow);

        $sql = 'INSERT IGNORE INTO feed_links
                (FlTitle, FlLink, FlText, FlDescription, FlDate, FlAudio, FlNfID)
                VALUES ' . implode(', ', $placeholders);

        // Collect all parameters
        $params = [];
        foreach ($feed as $data) {
            $params[] = $data['title'] ?? '';
            $params[] = $data['link'] ?? '';
            $params[] = $data['text'] ?? null;
            $params[] = $data['desc'] ?? '';
            $params[] = $data['date'] ?? '';
            $params[] = $data['audio'] ?? '';
            $params[] = $feedId;
        }

        $stmt = Connection::prepare($sql);
        $stmt->bindValues($params);
        $stmt->execute();

        $importedCount = $stmt->affectedRows();
        $duplicateCount = count($feed) - $importedCount;

        return [$importedCount, $duplicateCount];
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
        // Update feed timestamp using QueryBuilder
        QueryBuilder::table('news_feeds')
            ->where('NfID', '=', $feedId)
            ->updatePrepared(['NfUpdate' => time()]);

        $maxLinksRaw = $this->feedFacade->getFeedOption($feedOptions, 'max_links');
        if ($maxLinksRaw === null || $maxLinksRaw === '' || is_array($maxLinksRaw)) {
            $articleSource = $this->feedFacade->getFeedOption($feedOptions, 'article_source');
            if ($articleSource !== null && $articleSource !== '' && !is_array($articleSource)) {
                $maxLinksRaw = Settings::getWithDefault('set-max-articles-with-text');
            } else {
                $maxLinksRaw = Settings::getWithDefault('set-max-articles-without-text');
            }
        }
        $maxLinks = (int)$maxLinksRaw;

        $msg = $feedName . ": ";
        if (!$importedCount) {
            $msg .= "no";
        } else {
            $msg .= $importedCount;
        }
        $msg .= " new article";
        if ($importedCount > 1) {
            $msg .= "s";
        }
        $msg .= " imported";
        if ($duplicateCount > 1) {
            $msg .= ", $duplicateCount articles are dublicates";
        } elseif ($duplicateCount == 1) {
            $msg .= ", $duplicateCount dublicated article";
        }

        // Count total feed_links using QueryBuilder
        $row = QueryBuilder::table('feed_links')
            ->select(['COUNT(*) AS total'])
            ->where('FlNfID', '=', $feedId)
            ->firstPrepared();

        $to = ($row !== null ? (int)$row['total'] : 0) - $maxLinks;
        if ($to > 0) {
            QueryBuilder::table('feed_links')
                ->whereIn('FlNfID', [$feedId])
                ->orderBy('FlDate', 'ASC')
                ->limit($to)
                ->deletePrepared();
            $msg .= ", $to old article(s) deleted";
        }
        return $msg;
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
        // getFeedById is user-scoped, so somebody else's feed comes back null.
        // This gate is what makes every feed_links write below safe: that
        // table has no owner column, so filtering on FlNfID alone would let
        // any authenticated caller inject articles into another user's feed
        // and — via the max_links trim in getFeedResult() — delete theirs.
        // news_feeds being scoped hides the problem: the timestamp update
        // silently affects zero rows, so the call still looks like it worked.
        if ($this->feedFacade->getFeedById($feedId) === null) {
            return ['error' => 'Feed not found'];
        }

        $articleSource = $this->feedFacade->getFeedOption($feedOptions, 'article_source');
        $feed = $this->feedFacade->parseRssFeed($feedSourceUri, is_string($articleSource) ? $articleSource : '');
        if (!is_array($feed) || count($feed) === 0) {
            return [
                "error" => 'Could not load "' . $feedName . '"'
            ];
        }
        /** @var array<array-key, array<string, string>> $feed */
        list($importedCount, $duplicateCount) = $this->getFeedsList($feed, $feedId);
        $msg = $this->getFeedResult($importedCount, $duplicateCount, $feedName, $feedId, $feedOptions);
        return [
            "success" => true,
            "message" => $msg,
            "imported" => $importedCount,
            "duplicates" => $duplicateCount
        ];
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
        return $this->loadFeed($name, $feedId, $sourceUri, $options);
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
        $result = $this->feedFacade->parseRssFeed($sourceUri, $articleSection);
        return $result !== false ? $result : null;
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
        $result = $this->feedFacade->detectAndParseFeed($sourceUri);
        return $result !== false ? $result : null;
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
        return $this->feedFacade->getFeeds($languageId);
    }

    /**
     * Get feeds needing auto-update.
     *
     * @return array Array of feeds
     */
    public function getFeedsNeedingAutoUpdate(): array
    {
        return $this->feedFacade->getFeedsNeedingAutoUpdate();
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
        return $this->feedFacade->getFeedLoadConfig($feedId, $checkAutoupdate);
    }
}
