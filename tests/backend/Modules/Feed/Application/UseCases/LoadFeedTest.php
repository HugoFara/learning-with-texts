<?php

/**
 * Unit tests for LoadFeed use case.
 *
 * PHP version 8.2
 *
 * @category Testing
 * @package  Lwt\Tests\Modules\Feed\Application\UseCases
 * @license  Unlicense <http://unlicense.org/>
 * @since    3.0.0
 */

declare(strict_types=1);

namespace Lwt\Tests\Modules\Feed\Application\UseCases;

use Lwt\Modules\Feed\Application\Services\RssParser;
use Lwt\Modules\Feed\Application\UseCases\LoadFeed;
use Lwt\Modules\Feed\Domain\ArticleRepositoryInterface;
use Lwt\Modules\Feed\Domain\Feed;
use Lwt\Modules\Feed\Domain\FeedRepositoryInterface;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Tests for the LoadFeed use case.
 *
 * @since 3.0.0
 */
class LoadFeedTest extends TestCase
{
    private FeedRepositoryInterface&MockObject $feedRepository;
    private ArticleRepositoryInterface&MockObject $articleRepository;
    private RssParser&MockObject $rssParser;
    private LoadFeed $useCase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->feedRepository = $this->createMock(FeedRepositoryInterface::class);
        $this->articleRepository = $this->createMock(ArticleRepositoryInterface::class);
        $this->rssParser = $this->createMock(RssParser::class);

        $this->useCase = new LoadFeed(
            $this->feedRepository,
            $this->articleRepository,
            $this->rssParser
        );
    }

    private function createFeed(
        int $id = 1,
        string $sourceUri = 'https://example.com/rss',
        string $options = ''
    ): Feed {
        return Feed::reconstitute(
            $id,
            1,
            'Test Feed',
            $sourceUri,
            '',
            '',
            0,
            $options
        );
    }

    #[Test]
    public function executeReturnsFeedNotFoundWhenInvalidId(): void
    {
        $this->feedRepository->method('find')->with(999)->willReturn(null);

        $result = $this->useCase->execute(999);

        $this->assertFalse($result['success']);
        $this->assertNull($result['feed']);
        $this->assertSame(0, $result['inserted']);
        $this->assertSame(0, $result['duplicates']);
        $this->assertSame('Feed not found', $result['error']);
    }

    #[Test]
    public function executeReturnsErrorWhenParseFails(): void
    {
        $feed = $this->createFeed();
        $this->feedRepository->method('find')->willReturn($feed);
        $this->rssParser->method('parse')->willReturn(null);

        $result = $this->useCase->execute(1);

        $this->assertFalse($result['success']);
        $this->assertSame($feed, $result['feed']);
        $this->assertSame('Failed to parse RSS feed', $result['error']);
    }

    #[Test]
    public function executeSuccessfullyInsertsArticles(): void
    {
        $feed = $this->createFeed();
        $this->feedRepository->method('find')->willReturn($feed);

        $this->rssParser->method('parse')->willReturn([
            [
                'title' => 'Article 1',
                'link' => 'https://example.com/a1',
                'desc' => 'Description',
                'date' => '2026-01-01',
                'audio' => '',
                'text' => '',
            ],
        ]);

        $this->articleRepository->expects($this->once())
            ->method('insertBatch')
            ->willReturn(['inserted' => 1, 'duplicates' => 0]);

        $this->feedRepository->expects($this->once())
            ->method('updateTimestamp')
            ->with(1, $this->isType('int'));

        $result = $this->useCase->execute(1);

        $this->assertTrue($result['success']);
        $this->assertSame(1, $result['inserted']);
        $this->assertSame(0, $result['duplicates']);
        $this->assertNull($result['error']);
    }

    #[Test]
    public function executeReportsDuplicates(): void
    {
        $feed = $this->createFeed();
        $this->feedRepository->method('find')->willReturn($feed);

        $this->rssParser->method('parse')->willReturn([
            ['title' => 'A1', 'link' => 'https://e.com/1', 'desc' => '', 'date' => '', 'audio' => '', 'text' => ''],
            ['title' => 'A2', 'link' => 'https://e.com/2', 'desc' => '', 'date' => '', 'audio' => '', 'text' => ''],
        ]);

        $this->articleRepository->method('insertBatch')
            ->willReturn(['inserted' => 1, 'duplicates' => 1]);

        $this->feedRepository->method('updateTimestamp');

        $result = $this->useCase->execute(1);

        $this->assertTrue($result['success']);
        $this->assertSame(1, $result['inserted']);
        $this->assertSame(1, $result['duplicates']);
    }

    #[Test]
    public function executeUpdatesTimestampAfterLoad(): void
    {
        $feed = $this->createFeed(5);
        $this->feedRepository->method('find')->willReturn($feed);
        $this->rssParser->method('parse')->willReturn([]);
        $this->articleRepository->method('insertBatch')
            ->willReturn(['inserted' => 0, 'duplicates' => 0]);

        $timeBefore = time();

        $this->feedRepository->expects($this->once())
            ->method('updateTimestamp')
            ->with(
                5,
                $this->callback(function (int $timestamp) use ($timeBefore) {
                    return $timestamp >= $timeBefore && $timestamp <= time() + 1;
                })
            );

        $this->useCase->execute(5);
    }

    #[Test]
    public function executeWithEmptyRssResultsInZeroInserted(): void
    {
        $feed = $this->createFeed();
        $this->feedRepository->method('find')->willReturn($feed);
        $this->rssParser->method('parse')->willReturn([]);
        $this->articleRepository->method('insertBatch')
            ->willReturn(['inserted' => 0, 'duplicates' => 0]);
        $this->feedRepository->method('updateTimestamp');

        $result = $this->useCase->execute(1);

        $this->assertTrue($result['success']);
        $this->assertSame(0, $result['inserted']);
    }

    #[Test]
    public function executePassesFeedTextOptionToParser(): void
    {
        $feed = $this->createFeed(1, 'https://example.com/rss', 'feed_text=description');
        $this->feedRepository->method('find')->willReturn($feed);

        $this->rssParser->expects($this->once())
            ->method('parse')
            ->with('https://example.com/rss', 'description')
            ->willReturn([]);

        $this->articleRepository->method('insertBatch')
            ->willReturn(['inserted' => 0, 'duplicates' => 0]);
        $this->feedRepository->method('updateTimestamp');

        $this->useCase->execute(1);
    }

    #[Test]
    public function executePassesEmptyStringWhenNoFeedTextOption(): void
    {
        $feed = $this->createFeed(1, 'https://example.com/rss', '');
        $this->feedRepository->method('find')->willReturn($feed);

        $this->rssParser->expects($this->once())
            ->method('parse')
            ->with('https://example.com/rss', '')
            ->willReturn(null);

        $this->useCase->execute(1);
    }

    #[Test]
    public function executeReturnsFeedEntityInResult(): void
    {
        $feed = $this->createFeed(42, 'https://example.com/feed');
        $this->feedRepository->method('find')->willReturn($feed);
        $this->rssParser->method('parse')->willReturn([]);
        $this->articleRepository->method('insertBatch')
            ->willReturn(['inserted' => 0, 'duplicates' => 0]);
        $this->feedRepository->method('updateTimestamp');

        $result = $this->useCase->execute(42);

        $this->assertSame($feed, $result['feed']);
        $this->assertSame(42, $result['feed']->id());
    }

    #[Test]
    public function loadFeedWorksDirect(): void
    {
        $feed = $this->createFeed(1);
        $this->rssParser->method('parse')->willReturn([]);
        $this->articleRepository->method('insertBatch')
            ->willReturn(['inserted' => 0, 'duplicates' => 0]);
        $this->feedRepository->method('updateTimestamp');

        $result = $this->useCase->loadFeed($feed);

        $this->assertTrue($result['success']);
        $this->assertSame($feed, $result['feed']);
    }

    #[Test]
    public function executeMultipleProcessesAllFeeds(): void
    {
        $feed1 = $this->createFeed(1);
        $feed2 = $this->createFeed(2);

        $this->feedRepository->method('find')
            ->willReturnCallback(fn(int $id) => match ($id) {
                1 => $feed1,
                2 => $feed2,
                default => null,
            });

        $this->rssParser->method('parse')->willReturn([]);
        $this->articleRepository->method('insertBatch')
            ->willReturn(['inserted' => 0, 'duplicates' => 0]);
        $this->feedRepository->method('updateTimestamp');

        $results = $this->useCase->executeMultiple([1, 2]);

        $this->assertCount(2, $results);
        $this->assertArrayHasKey(1, $results);
        $this->assertArrayHasKey(2, $results);
        $this->assertTrue($results[1]['success']);
        $this->assertTrue($results[2]['success']);
    }

    #[Test]
    public function executeMultipleHandlesMissingFeed(): void
    {
        $this->feedRepository->method('find')->willReturn(null);

        $results = $this->useCase->executeMultiple([999]);

        $this->assertCount(1, $results);
        $this->assertFalse($results[999]['success']);
        $this->assertSame('Feed not found', $results[999]['error']);
    }

    #[Test]
    public function executeMultipleWithEmptyArrayReturnsEmpty(): void
    {
        $results = $this->useCase->executeMultiple([]);

        $this->assertEmpty($results);
    }

    #[Test]
    public function executeAutoUpdateFindsAndLoadsFeeds(): void
    {
        $feed1 = $this->createFeed(10);
        $feed2 = $this->createFeed(20);

        $this->feedRepository->expects($this->once())
            ->method('findNeedingAutoUpdate')
            ->with($this->isType('int'))
            ->willReturn([$feed1, $feed2]);

        $this->rssParser->method('parse')->willReturn([]);
        $this->articleRepository->method('insertBatch')
            ->willReturn(['inserted' => 0, 'duplicates' => 0]);
        $this->feedRepository->method('updateTimestamp');

        $results = $this->useCase->executeAutoUpdate();

        $this->assertCount(2, $results);
        $this->assertArrayHasKey(10, $results);
        $this->assertArrayHasKey(20, $results);
    }

    #[Test]
    public function executeAutoUpdateReturnsEmptyWhenNoFeedsNeedUpdate(): void
    {
        $this->feedRepository->method('findNeedingAutoUpdate')->willReturn([]);

        $results = $this->useCase->executeAutoUpdate();

        $this->assertEmpty($results);
    }

    #[Test]
    public function executePassesFeedIdToInsertBatch(): void
    {
        $feed = $this->createFeed(7);
        $this->feedRepository->method('find')->willReturn($feed);

        $this->rssParser->method('parse')->willReturn([
            ['title' => 'Art', 'link' => 'https://e.com/1', 'desc' => '', 'date' => '', 'audio' => '', 'text' => ''],
        ]);

        $this->articleRepository->expects($this->once())
            ->method('insertBatch')
            ->with($this->anything(), 7)
            ->willReturn(['inserted' => 1, 'duplicates' => 0]);

        $this->feedRepository->method('updateTimestamp');

        $this->useCase->execute(7);
    }

    #[Test]
    public function executeConvertsRssItemsToArticleEntities(): void
    {
        $feed = $this->createFeed(3);
        $this->feedRepository->method('find')->willReturn($feed);

        $this->rssParser->method('parse')->willReturn([
            [
                'title' => 'Article Title',
                'link' => 'https://example.com/article',
                'desc' => 'A description',
                'date' => '2026-03-01',
                'audio' => 'https://example.com/audio.mp3',
                'text' => 'Inline text content',
            ],
        ]);

        $this->articleRepository->expects($this->once())
            ->method('insertBatch')
            ->with(
                $this->callback(function (array $articles) {
                    $this->assertCount(1, $articles);
                    $article = $articles[0];
                    $this->assertSame('Article Title', $article->title());
                    $this->assertSame('https://example.com/article', $article->link());
                    $this->assertSame('https://example.com/audio.mp3', $article->audio());
                    $this->assertSame('Inline text content', $article->text());
                    $this->assertSame(3, $article->feedId());
                    return true;
                }),
                3
            )
            ->willReturn(['inserted' => 1, 'duplicates' => 0]);

        $this->feedRepository->method('updateTimestamp');

        $this->useCase->execute(3);
    }

    #[Test]
    public function executeHandlesItemsWithMissingOptionalFields(): void
    {
        $feed = $this->createFeed(1);
        $this->feedRepository->method('find')->willReturn($feed);

        $this->rssParser->method('parse')->willReturn([
            [
                'title' => 'Minimal Article',
                'link' => 'https://example.com/min',
                // No desc, date, audio, text
            ],
        ]);

        $this->articleRepository->expects($this->once())
            ->method('insertBatch')
            ->with(
                $this->callback(function (array $articles) {
                    $article = $articles[0];
                    $this->assertSame('', $article->description());
                    $this->assertSame('', $article->audio());
                    $this->assertSame('', $article->text());
                    return true;
                }),
                $this->anything()
            )
            ->willReturn(['inserted' => 1, 'duplicates' => 0]);

        $this->feedRepository->method('updateTimestamp');

        $this->useCase->execute(1);
    }

    #[Test]
    public function executeMultipleHandlesMixOfSuccessAndFailure(): void
    {
        $feed1 = $this->createFeed(1);

        $this->feedRepository->method('find')
            ->willReturnCallback(fn(int $id) => $id === 1 ? $feed1 : null);

        $this->rssParser->method('parse')->willReturn([]);
        $this->articleRepository->method('insertBatch')
            ->willReturn(['inserted' => 0, 'duplicates' => 0]);
        $this->feedRepository->method('updateTimestamp');

        $results = $this->useCase->executeMultiple([1, 999]);

        $this->assertTrue($results[1]['success']);
        $this->assertFalse($results[999]['success']);
    }
}
