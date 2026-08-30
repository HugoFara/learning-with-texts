<?php

/**
 * Load Feed Use Case
 *
 * PHP version 8.2
 *
 * @category Lwt
 * @package  Lwt\Modules\Feed\Application\UseCases
 * @author   HugoFara <git@hugofara.net>
 * @license  Unlicense <http://unlicense.org/>
 * @link     https://hugofara.github.io/lwt/developer/api
 * @since    3.0.0
 */

declare(strict_types=1);

namespace Lwt\Modules\Feed\Application\UseCases;

use Lwt\Modules\Feed\Application\Services\RssParser;
use Lwt\Modules\Feed\Domain\Article;
use Lwt\Modules\Feed\Domain\ArticleRepositoryInterface;
use Lwt\Modules\Feed\Domain\Feed;
use Lwt\Modules\Feed\Domain\FeedRepositoryInterface;

/**
 * Use case for loading/refreshing a feed from its RSS source.
 *
 * Fetches the RSS feed, parses articles, inserts new ones,
 * and updates the feed timestamp.
 *
 * @since 3.0.0
 */
class LoadFeed
{
    /**
     * Constructor.
     *
     * @param FeedRepositoryInterface    $feedRepository    Feed repository
     * @param ArticleRepositoryInterface $articleRepository Article repository
     * @param RssParser                  $rssParser         RSS parser service
     */
    public function __construct(
        private FeedRepositoryInterface $feedRepository,
        private ArticleRepositoryInterface $articleRepository,
        private RssParser $rssParser
    ) {
    }

    /**
     * Execute the use case.
     *
     * @param int $feedId Feed ID to load
     *
     * @return array{
     *     success: bool,
     *     feed: Feed|null,
     *     inserted: int,
     *     duplicates: int,
     *     error: string|null
     * }
     */
    public function execute(int $feedId): array
    {
        $feed = $this->feedRepository->find($feedId);

        if ($feed === null) {
            return [
                'success' => false,
                'feed' => null,
                'inserted' => 0,
                'duplicates' => 0,
                'error' => 'Feed not found',
            ];
        }

        return $this->loadFeed($feed);
    }

    /**
     * Load a feed entity.
     *
     * @param Feed $feed Feed to load
     *
     * @return array{success: bool, feed: Feed|null, inserted: int, duplicates: int, error: string|null} Load result
     */
    public function loadFeed(Feed $feed): array
    {
        // Parse RSS feed
        $articleSection = $feed->options()->get('feed_text') ?? '';
        $items = $this->rssParser->parse($feed->sourceUri(), $articleSection);

        if ($items === null) {
            return [
                'success' => false,
                'feed' => $feed,
                'inserted' => 0,
                'duplicates' => 0,
                'error' => 'Failed to parse RSS feed',
            ];
        }

        // Convert to Article entities
        $articles = [];
        foreach ($items as $item) {
            $articles[] = Article::create(
                (int) $feed->id(),
                $item['title'],
                $item['link'],
                $item['desc'] ?? '',
                $item['date'] ?? '',
                $item['audio'] ?? '',
                $item['text'] ?? ''
            );
        }

        // Insert articles (duplicates are handled by repository)
        $result = $this->articleRepository->insertBatch($articles, (int) $feed->id());

        // Update feed timestamp
        $this->feedRepository->updateTimestamp((int) $feed->id(), time());

        return [
            'success' => true,
            'feed' => $feed,
            'inserted' => $result['inserted'],
            'duplicates' => $result['duplicates'],
            'error' => null,
        ];
    }

    /**
     * Load multiple feeds.
     *
     * @param int[] $feedIds Feed IDs to load
     *
     * @return array<int, array> Results keyed by feed ID
     */
    public function executeMultiple(array $feedIds): array
    {
        $results = [];

        foreach ($feedIds as $feedId) {
            $results[$feedId] = $this->execute($feedId);
        }

        return $results;
    }

    /**
     * Load feeds that need auto-update.
     *
     * @return array<int, array> Results keyed by feed ID
     */
    public function executeAutoUpdate(): array
    {
        $feeds = $this->feedRepository->findNeedingAutoUpdate(time());
        $results = [];

        foreach ($feeds as $feed) {
            $results[(int) $feed->id()] = $this->loadFeed($feed);
        }

        return $results;
    }
}
