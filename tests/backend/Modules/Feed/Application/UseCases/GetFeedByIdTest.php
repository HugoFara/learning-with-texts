<?php

/**
 * Unit tests for GetFeedById use case.
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

use Lwt\Modules\Feed\Application\UseCases\GetFeedById;
use Lwt\Modules\Feed\Domain\Feed;
use Lwt\Modules\Feed\Domain\FeedRepositoryInterface;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Tests for the GetFeedById use case.
 *
 * Verifies feed retrieval by ID and existence checks.
 *
 * @since 3.0.0
 */
class GetFeedByIdTest extends TestCase
{
    private FeedRepositoryInterface&MockObject $feedRepository;
    private GetFeedById $useCase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->feedRepository = $this->createMock(FeedRepositoryInterface::class);
        $this->useCase = new GetFeedById($this->feedRepository);
    }

    /**
     * Create a Feed entity for testing.
     */
    private function makeFeed(
        int $id,
        int $langId,
        string $name,
        string $uri
    ): Feed {
        return Feed::reconstitute(
            $id,
            $langId,
            $name,
            $uri,
            '',
            '',
            0,
            ''
        );
    }

    // -----------------------------------------------------------------
    // execute
    // -----------------------------------------------------------------

    #[Test]
    public function executeReturnsFeedWhenFound(): void
    {
        $feed = $this->makeFeed(42, 1, 'My Feed', 'https://example.com/rss');

        $this->feedRepository
            ->expects($this->once())
            ->method('find')
            ->with(42)
            ->willReturn($feed);

        $result = $this->useCase->execute(42);

        $this->assertInstanceOf(Feed::class, $result);
        $this->assertSame(42, $result->id());
        $this->assertSame('My Feed', $result->name());
        $this->assertSame('https://example.com/rss', $result->sourceUri());
        $this->assertSame(1, $result->languageId());
    }

    #[Test]
    public function executeReturnsNullWhenNotFound(): void
    {
        $this->feedRepository
            ->expects($this->once())
            ->method('find')
            ->with(999)
            ->willReturn(null);

        $result = $this->useCase->execute(999);

        $this->assertNull($result);
    }

    #[Test]
    public function executeReturnsFeedWithAllProperties(): void
    {
        $feed = Feed::reconstitute(
            10,
            3,
            'Full Feed',
            'https://example.com/atom',
            '//article//p',
            '//div[@class="ads"]',
            1700000000,
            'tag=my_tag,max_texts=50'
        );

        $this->feedRepository
            ->expects($this->once())
            ->method('find')
            ->with(10)
            ->willReturn($feed);

        $result = $this->useCase->execute(10);

        $this->assertSame(10, $result->id());
        $this->assertSame(3, $result->languageId());
        $this->assertSame('Full Feed', $result->name());
        $this->assertSame('https://example.com/atom', $result->sourceUri());
        $this->assertSame('//article//p', $result->articleSectionTags());
        $this->assertSame('//div[@class="ads"]', $result->filterTags());
        $this->assertSame(1700000000, $result->updateTimestamp());
        $this->assertSame('my_tag', $result->options()->tag());
        $this->assertSame(50, $result->options()->maxTexts());
    }

    // -----------------------------------------------------------------
    // exists
    // -----------------------------------------------------------------

    #[Test]
    public function existsReturnsTrueWhenFeedExists(): void
    {
        $this->feedRepository
            ->expects($this->once())
            ->method('exists')
            ->with(42)
            ->willReturn(true);

        $result = $this->useCase->exists(42);

        $this->assertTrue($result);
    }

    #[Test]
    public function existsReturnsFalseWhenFeedDoesNotExist(): void
    {
        $this->feedRepository
            ->expects($this->once())
            ->method('exists')
            ->with(999)
            ->willReturn(false);

        $result = $this->useCase->exists(999);

        $this->assertFalse($result);
    }
}
