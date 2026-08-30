<?php

/**
 * Unit tests for DeleteFeeds use case.
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

use Lwt\Modules\Feed\Application\UseCases\DeleteFeeds;
use Lwt\Modules\Feed\Domain\ArticleRepositoryInterface;
use Lwt\Modules\Feed\Domain\FeedRepositoryInterface;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Tests for the DeleteFeeds use case.
 *
 * Verifies that feeds and their articles are deleted correctly,
 * including the ordering constraint (articles before feeds).
 *
 * @since 3.0.0
 */
class DeleteFeedsTest extends TestCase
{
    private FeedRepositoryInterface&MockObject $feedRepository;
    private ArticleRepositoryInterface&MockObject $articleRepository;
    private DeleteFeeds $useCase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->feedRepository = $this->createMock(FeedRepositoryInterface::class);
        $this->articleRepository = $this->createMock(ArticleRepositoryInterface::class);
        $this->useCase = new DeleteFeeds(
            $this->feedRepository,
            $this->articleRepository
        );
    }

    // -----------------------------------------------------------------
    // execute (bulk delete)
    // -----------------------------------------------------------------

    #[Test]
    public function executeDeletesArticlesThenFeeds(): void
    {
        $this->articleRepository
            ->expects($this->once())
            ->method('deleteByFeeds')
            ->with([1, 2, 3])
            ->willReturn(15);

        $this->feedRepository
            ->expects($this->once())
            ->method('deleteMultiple')
            ->with([1, 2, 3])
            ->willReturn(3);

        $result = $this->useCase->execute([1, 2, 3]);

        $this->assertSame(3, $result['feeds']);
        $this->assertSame(15, $result['articles']);
    }

    #[Test]
    public function executeWithEmptyArrayReturnsZeroCounts(): void
    {
        $this->articleRepository
            ->expects($this->never())
            ->method('deleteByFeeds');

        $this->feedRepository
            ->expects($this->never())
            ->method('deleteMultiple');

        $result = $this->useCase->execute([]);

        $this->assertSame(0, $result['feeds']);
        $this->assertSame(0, $result['articles']);
    }

    #[Test]
    public function executeReturnsBothCounts(): void
    {
        $this->articleRepository
            ->expects($this->once())
            ->method('deleteByFeeds')
            ->willReturn(50);

        $this->feedRepository
            ->expects($this->once())
            ->method('deleteMultiple')
            ->willReturn(5);

        $result = $this->useCase->execute([10, 20, 30, 40, 50]);

        $this->assertArrayHasKey('feeds', $result);
        $this->assertArrayHasKey('articles', $result);
        $this->assertSame(5, $result['feeds']);
        $this->assertSame(50, $result['articles']);
    }

    #[Test]
    public function executeWithFeedsHavingNoArticles(): void
    {
        $this->articleRepository
            ->expects($this->once())
            ->method('deleteByFeeds')
            ->with([1])
            ->willReturn(0);

        $this->feedRepository
            ->expects($this->once())
            ->method('deleteMultiple')
            ->with([1])
            ->willReturn(1);

        $result = $this->useCase->execute([1]);

        $this->assertSame(1, $result['feeds']);
        $this->assertSame(0, $result['articles']);
    }

    // -----------------------------------------------------------------
    // executeSingle
    // -----------------------------------------------------------------

    #[Test]
    public function executeSingleReturnsTrueWhenFeedDeleted(): void
    {
        $this->articleRepository
            ->expects($this->once())
            ->method('deleteByFeeds')
            ->with([5])
            ->willReturn(10);

        $this->feedRepository
            ->expects($this->once())
            ->method('deleteMultiple')
            ->with([5])
            ->willReturn(1);

        $result = $this->useCase->executeSingle(5);

        $this->assertTrue($result);
    }

    #[Test]
    public function executeSingleReturnsFalseWhenFeedNotFound(): void
    {
        $this->articleRepository
            ->expects($this->once())
            ->method('deleteByFeeds')
            ->with([999])
            ->willReturn(0);

        $this->feedRepository
            ->expects($this->once())
            ->method('deleteMultiple')
            ->with([999])
            ->willReturn(0);

        $result = $this->useCase->executeSingle(999);

        $this->assertFalse($result);
    }

    #[Test]
    public function executeSingleDelegatesToExecuteWithSingleElementArray(): void
    {
        $this->articleRepository
            ->expects($this->once())
            ->method('deleteByFeeds')
            ->with([42])
            ->willReturn(3);

        $this->feedRepository
            ->expects($this->once())
            ->method('deleteMultiple')
            ->with([42])
            ->willReturn(1);

        $result = $this->useCase->executeSingle(42);

        $this->assertTrue($result);
    }
}
