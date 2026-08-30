<?php

/**
 * Unit tests for ResetErrorArticles use case.
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

use Lwt\Modules\Feed\Application\UseCases\ResetErrorArticles;
use Lwt\Modules\Feed\Domain\ArticleRepositoryInterface;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Tests for the ResetErrorArticles use case.
 *
 * Verifies that error article reset works for multiple feeds,
 * single feeds, and empty arrays.
 *
 * @since 3.0.0
 */
class ResetErrorArticlesTest extends TestCase
{
    private ArticleRepositoryInterface&MockObject $articleRepository;
    private ResetErrorArticles $useCase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->articleRepository = $this->createMock(ArticleRepositoryInterface::class);
        $this->useCase = new ResetErrorArticles($this->articleRepository);
    }

    // -----------------------------------------------------------------
    // execute (multiple feeds)
    // -----------------------------------------------------------------

    #[Test]
    public function executeResetsErrorArticlesForMultipleFeeds(): void
    {
        $this->articleRepository
            ->expects($this->once())
            ->method('resetErrorsByFeeds')
            ->with([1, 2, 3])
            ->willReturn(5);

        $result = $this->useCase->execute([1, 2, 3]);

        $this->assertSame(5, $result);
    }

    #[Test]
    public function executeWithEmptyArrayReturnsZero(): void
    {
        $this->articleRepository
            ->expects($this->never())
            ->method('resetErrorsByFeeds');

        $result = $this->useCase->execute([]);

        $this->assertSame(0, $result);
    }

    #[Test]
    public function executeWithSingleFeedIdInArray(): void
    {
        $this->articleRepository
            ->expects($this->once())
            ->method('resetErrorsByFeeds')
            ->with([42])
            ->willReturn(3);

        $result = $this->useCase->execute([42]);

        $this->assertSame(3, $result);
    }

    #[Test]
    public function executeReturnsZeroWhenNoErrorArticlesExist(): void
    {
        $this->articleRepository
            ->expects($this->once())
            ->method('resetErrorsByFeeds')
            ->with([10, 20])
            ->willReturn(0);

        $result = $this->useCase->execute([10, 20]);

        $this->assertSame(0, $result);
    }

    // -----------------------------------------------------------------
    // executeForFeed (single feed convenience method)
    // -----------------------------------------------------------------

    #[Test]
    public function executeForFeedDelegatesToExecuteWithSingleId(): void
    {
        $this->articleRepository
            ->expects($this->once())
            ->method('resetErrorsByFeeds')
            ->with([7])
            ->willReturn(2);

        $result = $this->useCase->executeForFeed(7);

        $this->assertSame(2, $result);
    }

    #[Test]
    public function executeForFeedReturnsZeroWhenNoErrors(): void
    {
        $this->articleRepository
            ->expects($this->once())
            ->method('resetErrorsByFeeds')
            ->with([99])
            ->willReturn(0);

        $result = $this->useCase->executeForFeed(99);

        $this->assertSame(0, $result);
    }

    #[Test]
    public function executeForFeedReturnsResetCount(): void
    {
        $this->articleRepository
            ->expects($this->once())
            ->method('resetErrorsByFeeds')
            ->with([5])
            ->willReturn(10);

        $result = $this->useCase->executeForFeed(5);

        $this->assertSame(10, $result);
    }
}
