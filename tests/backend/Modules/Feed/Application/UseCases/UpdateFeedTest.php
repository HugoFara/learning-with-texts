<?php

/**
 * Unit tests for UpdateFeed use case.
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

use InvalidArgumentException;
use Lwt\Modules\Feed\Application\UseCases\UpdateFeed;
use Lwt\Modules\Feed\Domain\Feed;
use Lwt\Modules\Feed\Domain\FeedRepositoryInterface;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Tests for the UpdateFeed use case.
 *
 * Verifies feed update with valid data, missing feeds,
 * and validation errors during update.
 *
 * @since 3.0.0
 */
class UpdateFeedTest extends TestCase
{
    private FeedRepositoryInterface&MockObject $feedRepository;
    private UpdateFeed $useCase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->feedRepository = $this->createMock(FeedRepositoryInterface::class);
        $this->useCase = new UpdateFeed($this->feedRepository);
    }

    /**
     * Create a Feed entity for testing.
     */
    private function makeFeed(
        int $id,
        int $langId,
        string $name,
        string $uri,
        string $section = '',
        string $filter = '',
        int $timestamp = 0,
        string $options = ''
    ): Feed {
        return Feed::reconstitute(
            $id,
            $langId,
            $name,
            $uri,
            $section,
            $filter,
            $timestamp,
            $options
        );
    }

    // -----------------------------------------------------------------
    // execute - happy path
    // -----------------------------------------------------------------

    #[Test]
    public function executeUpdatesFeedAndSaves(): void
    {
        $existingFeed = $this->makeFeed(
            1,
            1,
            'Old Name',
            'https://old.com/rss'
        );

        $this->feedRepository
            ->expects($this->once())
            ->method('find')
            ->with(1)
            ->willReturn($existingFeed);

        $this->feedRepository
            ->expects($this->once())
            ->method('save')
            ->with($this->identicalTo($existingFeed))
            ->willReturn(1);

        $result = $this->useCase->execute(
            feedId: 1,
            languageId: 2,
            name: 'New Name',
            sourceUri: 'https://new.com/rss',
            articleSectionTags: '//article',
            filterTags: '//nav',
            options: 'tag=updated'
        );

        $this->assertInstanceOf(Feed::class, $result);
        $this->assertSame(1, $result->id());
        $this->assertSame('New Name', $result->name());
        $this->assertSame('https://new.com/rss', $result->sourceUri());
        $this->assertSame(2, $result->languageId());
        $this->assertSame('//article', $result->articleSectionTags());
        $this->assertSame('//nav', $result->filterTags());
        $this->assertSame('updated', $result->options()->tag());
    }

    #[Test]
    public function executeWithDefaultOptionalParameters(): void
    {
        $existingFeed = $this->makeFeed(
            5,
            1,
            'Feed',
            'https://example.com/rss',
            '//old-section',
            '//old-filter',
            1000,
            'tag=old'
        );

        $this->feedRepository
            ->expects($this->once())
            ->method('find')
            ->with(5)
            ->willReturn($existingFeed);

        $this->feedRepository
            ->expects($this->once())
            ->method('save')
            ->willReturn(5);

        $result = $this->useCase->execute(
            feedId: 5,
            languageId: 1,
            name: 'Updated Feed',
            sourceUri: 'https://example.com/rss'
        );

        $this->assertSame('Updated Feed', $result->name());
        $this->assertSame('', $result->articleSectionTags());
        $this->assertSame('', $result->filterTags());
        $this->assertSame('', $result->options()->toString());
    }

    // -----------------------------------------------------------------
    // execute - feed not found
    // -----------------------------------------------------------------

    #[Test]
    public function executeReturnsNullWhenFeedNotFound(): void
    {
        $this->feedRepository
            ->expects($this->once())
            ->method('find')
            ->with(999)
            ->willReturn(null);

        $this->feedRepository
            ->expects($this->never())
            ->method('save');

        $result = $this->useCase->execute(
            feedId: 999,
            languageId: 1,
            name: 'Missing Feed',
            sourceUri: 'https://example.com/rss'
        );

        $this->assertNull($result);
    }

    // -----------------------------------------------------------------
    // execute - validation errors
    // -----------------------------------------------------------------

    #[Test]
    public function executeWithEmptyNameThrowsException(): void
    {
        $existingFeed = $this->makeFeed(
            1,
            1,
            'Old Name',
            'https://old.com/rss'
        );

        $this->feedRepository
            ->expects($this->once())
            ->method('find')
            ->with(1)
            ->willReturn($existingFeed);

        $this->feedRepository
            ->expects($this->never())
            ->method('save');

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Feed name cannot be empty');

        $this->useCase->execute(
            feedId: 1,
            languageId: 1,
            name: '',
            sourceUri: 'https://example.com/rss'
        );
    }

    #[Test]
    public function executeWithEmptyUriThrowsException(): void
    {
        $existingFeed = $this->makeFeed(
            1,
            1,
            'Old Name',
            'https://old.com/rss'
        );

        $this->feedRepository
            ->expects($this->once())
            ->method('find')
            ->with(1)
            ->willReturn($existingFeed);

        $this->feedRepository
            ->expects($this->never())
            ->method('save');

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Feed source URI cannot be empty');

        $this->useCase->execute(
            feedId: 1,
            languageId: 1,
            name: 'Valid Name',
            sourceUri: ''
        );
    }

    #[Test]
    public function executeWithInvalidLanguageIdThrowsException(): void
    {
        $existingFeed = $this->makeFeed(
            1,
            1,
            'Old Name',
            'https://old.com/rss'
        );

        $this->feedRepository
            ->expects($this->once())
            ->method('find')
            ->with(1)
            ->willReturn($existingFeed);

        $this->feedRepository
            ->expects($this->never())
            ->method('save');

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Language ID must be positive');

        $this->useCase->execute(
            feedId: 1,
            languageId: 0,
            name: 'Valid Name',
            sourceUri: 'https://example.com/rss'
        );
    }

    #[Test]
    public function executeWithNameExceedingMaxLengthThrowsException(): void
    {
        $existingFeed = $this->makeFeed(
            1,
            1,
            'Old Name',
            'https://old.com/rss'
        );

        $this->feedRepository
            ->expects($this->once())
            ->method('find')
            ->with(1)
            ->willReturn($existingFeed);

        $this->feedRepository
            ->expects($this->never())
            ->method('save');

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Feed name cannot exceed 40 characters');

        $this->useCase->execute(
            feedId: 1,
            languageId: 1,
            name: str_repeat('x', 41),
            sourceUri: 'https://example.com/rss'
        );
    }

    #[Test]
    public function executeWithUriExceedingMaxLengthThrowsException(): void
    {
        $existingFeed = $this->makeFeed(
            1,
            1,
            'Old Name',
            'https://old.com/rss'
        );

        $this->feedRepository
            ->expects($this->once())
            ->method('find')
            ->with(1)
            ->willReturn($existingFeed);

        $this->feedRepository
            ->expects($this->never())
            ->method('save');

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Feed source URI cannot exceed 200 characters');

        $this->useCase->execute(
            feedId: 1,
            languageId: 1,
            name: 'Valid Name',
            sourceUri: 'https://example.com/' . str_repeat('a', 200)
        );
    }

    // -----------------------------------------------------------------
    // execute - preserves feed identity
    // -----------------------------------------------------------------

    #[Test]
    public function executeReturnsSameFeedObjectAfterUpdate(): void
    {
        $existingFeed = $this->makeFeed(
            10,
            1,
            'Original',
            'https://original.com/rss',
            '',
            '',
            5000
        );

        $this->feedRepository
            ->expects($this->once())
            ->method('find')
            ->with(10)
            ->willReturn($existingFeed);

        $this->feedRepository
            ->expects($this->once())
            ->method('save')
            ->willReturn(10);

        $result = $this->useCase->execute(
            feedId: 10,
            languageId: 3,
            name: 'Modified',
            sourceUri: 'https://modified.com/feed'
        );

        // The returned feed should be the same object, mutated in place
        $this->assertSame($existingFeed, $result);
        $this->assertSame(10, $result->id());
        // updateTimestamp should be preserved (not reset)
        $this->assertSame(5000, $result->updateTimestamp());
    }
}
