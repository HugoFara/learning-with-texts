<?php

/**
 * Unit tests for GetFeedList use case.
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

use Lwt\Modules\Feed\Application\UseCases\GetFeedList;
use Lwt\Modules\Feed\Domain\ArticleRepositoryInterface;
use Lwt\Modules\Feed\Domain\Feed;
use Lwt\Modules\Feed\Domain\FeedRepositoryInterface;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Tests for the GetFeedList use case.
 *
 * Verifies paginated feed listing, article count retrieval,
 * language filtering, and dropdown select formatting.
 *
 * @since 3.0.0
 */
class GetFeedListTest extends TestCase
{
    private FeedRepositoryInterface&MockObject $feedRepository;
    private ArticleRepositoryInterface&MockObject $articleRepository;
    private GetFeedList $useCase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->feedRepository = $this->createMock(FeedRepositoryInterface::class);
        $this->articleRepository = $this->createMock(ArticleRepositoryInterface::class);
        $this->useCase = new GetFeedList(
            $this->feedRepository,
            $this->articleRepository
        );
    }

    /**
     * Create a Feed entity for testing.
     */
    private function makeFeed(
        int $id,
        int $langId,
        string $name,
        string $uri,
        int $timestamp = 0
    ): Feed {
        return Feed::reconstitute(
            $id,
            $langId,
            $name,
            $uri,
            '',
            '',
            $timestamp,
            ''
        );
    }

    // -----------------------------------------------------------------
    // execute (paginated list)
    // -----------------------------------------------------------------

    #[Test]
    public function executeReturnsFeedsWithTotalAndArticleCounts(): void
    {
        $feed1 = $this->makeFeed(1, 1, 'Feed A', 'https://a.com/rss', 1000);
        $feed2 = $this->makeFeed(2, 1, 'Feed B', 'https://b.com/rss', 2000);

        $this->feedRepository
            ->expects($this->once())
            ->method('findPaginated')
            ->with(0, 50, null, null, 'NfUpdate', 'DESC')
            ->willReturn([$feed1, $feed2]);

        $this->feedRepository
            ->expects($this->once())
            ->method('countFeeds')
            ->with(null, null)
            ->willReturn(2);

        $this->articleRepository
            ->expects($this->once())
            ->method('getCountPerFeed')
            ->with([1, 2])
            ->willReturn([1 => 5, 2 => 12]);

        $result = $this->useCase->execute();

        $this->assertCount(2, $result['feeds']);
        $this->assertSame(2, $result['total']);
        $this->assertSame([1 => 5, 2 => 12], $result['article_counts']);
    }

    #[Test]
    public function executeWithPaginationAndFilters(): void
    {
        $feed = $this->makeFeed(3, 2, 'French Feed', 'https://fr.com/rss', 500);

        $this->feedRepository
            ->expects($this->once())
            ->method('findPaginated')
            ->with(10, 25, 2, '%french%', 'NfName', 'ASC')
            ->willReturn([$feed]);

        $this->feedRepository
            ->expects($this->once())
            ->method('countFeeds')
            ->with(2, '%french%')
            ->willReturn(1);

        $this->articleRepository
            ->expects($this->once())
            ->method('getCountPerFeed')
            ->with([3])
            ->willReturn([3 => 8]);

        $result = $this->useCase->execute(
            offset: 10,
            limit: 25,
            languageId: 2,
            queryPattern: '%french%',
            orderBy: 'NfName',
            direction: 'ASC'
        );

        $this->assertCount(1, $result['feeds']);
        $this->assertSame(1, $result['total']);
        $this->assertSame([3 => 8], $result['article_counts']);
    }

    #[Test]
    public function executeEmptyResultSkipsArticleCounts(): void
    {
        $this->feedRepository
            ->expects($this->once())
            ->method('findPaginated')
            ->willReturn([]);

        $this->feedRepository
            ->expects($this->once())
            ->method('countFeeds')
            ->willReturn(0);

        $this->articleRepository
            ->expects($this->never())
            ->method('getCountPerFeed');

        $result = $this->useCase->execute();

        $this->assertSame([], $result['feeds']);
        $this->assertSame(0, $result['total']);
        $this->assertSame([], $result['article_counts']);
    }

    #[Test]
    public function executeReturnsCorrectStructure(): void
    {
        $this->feedRepository
            ->method('findPaginated')
            ->willReturn([]);
        $this->feedRepository
            ->method('countFeeds')
            ->willReturn(0);

        $result = $this->useCase->execute();

        $this->assertArrayHasKey('feeds', $result);
        $this->assertArrayHasKey('total', $result);
        $this->assertArrayHasKey('article_counts', $result);
    }

    // -----------------------------------------------------------------
    // executeAll
    // -----------------------------------------------------------------

    #[Test]
    public function executeAllWithLanguageFilterCallsFindByLanguage(): void
    {
        $feed = $this->makeFeed(1, 2, 'Feed', 'https://a.com/rss');

        $this->feedRepository
            ->expects($this->once())
            ->method('findByLanguage')
            ->with(2, 'NfUpdate', 'DESC')
            ->willReturn([$feed]);

        $this->feedRepository
            ->expects($this->never())
            ->method('findAll');

        $result = $this->useCase->executeAll(languageId: 2);

        $this->assertCount(1, $result);
        $this->assertSame('Feed', $result[0]->name());
    }

    #[Test]
    public function executeAllWithNullLanguageCallsFindAll(): void
    {
        $this->feedRepository
            ->expects($this->once())
            ->method('findAll')
            ->with('NfName', 'ASC')
            ->willReturn([]);

        $this->feedRepository
            ->expects($this->never())
            ->method('findByLanguage');

        $result = $this->useCase->executeAll(
            languageId: null,
            orderBy: 'NfName',
            direction: 'ASC'
        );

        $this->assertSame([], $result);
    }

    #[Test]
    public function executeAllWithZeroLanguageIdCallsFindAll(): void
    {
        $this->feedRepository
            ->expects($this->once())
            ->method('findAll')
            ->with('NfUpdate', 'DESC')
            ->willReturn([]);

        $this->feedRepository
            ->expects($this->never())
            ->method('findByLanguage');

        $result = $this->useCase->executeAll(languageId: 0);

        $this->assertSame([], $result);
    }

    #[Test]
    public function executeAllPassesOrderingParameters(): void
    {
        $this->feedRepository
            ->expects($this->once())
            ->method('findByLanguage')
            ->with(1, 'NfName', 'ASC')
            ->willReturn([]);

        $result = $this->useCase->executeAll(
            languageId: 1,
            orderBy: 'NfName',
            direction: 'ASC'
        );

        $this->assertSame([], $result);
    }

    // -----------------------------------------------------------------
    // executeForSelect
    // -----------------------------------------------------------------

    #[Test]
    public function executeForSelectDelegatesToRepository(): void
    {
        $expected = [
            ['id' => 1, 'name' => 'Feed A', 'language_id' => 1],
            ['id' => 2, 'name' => 'Feed B', 'language_id' => 1],
        ];

        $this->feedRepository
            ->expects($this->once())
            ->method('getForSelect')
            ->with(1, 40)
            ->willReturn($expected);

        $result = $this->useCase->executeForSelect(languageId: 1);

        $this->assertSame($expected, $result);
    }

    #[Test]
    public function executeForSelectWithDefaultParameters(): void
    {
        $this->feedRepository
            ->expects($this->once())
            ->method('getForSelect')
            ->with(0, 40)
            ->willReturn([]);

        $result = $this->useCase->executeForSelect();

        $this->assertSame([], $result);
    }

    #[Test]
    public function executeForSelectWithCustomMaxNameLength(): void
    {
        $this->feedRepository
            ->expects($this->once())
            ->method('getForSelect')
            ->with(0, 20)
            ->willReturn([]);

        $result = $this->useCase->executeForSelect(languageId: 0, maxNameLength: 20);

        $this->assertSame([], $result);
    }
}
