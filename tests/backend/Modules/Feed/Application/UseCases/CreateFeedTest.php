<?php

/**
 * Unit tests for CreateFeed use case.
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
use Lwt\Modules\Feed\Application\UseCases\CreateFeed;
use Lwt\Modules\Feed\Domain\Feed;
use Lwt\Modules\Feed\Domain\FeedRepositoryInterface;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Tests for the CreateFeed use case.
 *
 * Verifies feed creation with valid data, validation errors,
 * and parameter passthrough to the repository.
 *
 * @since 3.0.0
 */
class CreateFeedTest extends TestCase
{
    private FeedRepositoryInterface&MockObject $feedRepository;
    private CreateFeed $useCase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->feedRepository = $this->createMock(FeedRepositoryInterface::class);
        $this->useCase = new CreateFeed($this->feedRepository);
    }

    #[Test]
    public function executeCreatesAndSavesFeed(): void
    {
        $this->feedRepository
            ->expects($this->once())
            ->method('save')
            ->with($this->isInstanceOf(Feed::class))
            ->willReturn(42);

        $feed = $this->useCase->execute(
            languageId: 1,
            name: 'Test Feed',
            sourceUri: 'https://example.com/rss'
        );

        $this->assertInstanceOf(Feed::class, $feed);
        $this->assertSame('Test Feed', $feed->name());
        $this->assertSame('https://example.com/rss', $feed->sourceUri());
        $this->assertSame(1, $feed->languageId());
        $this->assertTrue($feed->isNew());
    }

    #[Test]
    public function executePassesAllParametersToFeedEntity(): void
    {
        $this->feedRepository
            ->expects($this->once())
            ->method('save')
            ->willReturn(10);

        $feed = $this->useCase->execute(
            languageId: 3,
            name: 'Full Feed',
            sourceUri: 'https://example.com/atom',
            articleSectionTags: '//article//p',
            filterTags: '//div[@class="ads"]',
            options: 'tag=my_tag,max_texts=50'
        );

        $this->assertSame('Full Feed', $feed->name());
        $this->assertSame('https://example.com/atom', $feed->sourceUri());
        $this->assertSame(3, $feed->languageId());
        $this->assertSame('//article//p', $feed->articleSectionTags());
        $this->assertSame('//div[@class="ads"]', $feed->filterTags());
        $this->assertSame('my_tag', $feed->options()->tag());
        $this->assertSame(50, $feed->options()->maxTexts());
    }

    #[Test]
    public function executeWithDefaultOptionalParameters(): void
    {
        $this->feedRepository
            ->expects($this->once())
            ->method('save')
            ->willReturn(1);

        $feed = $this->useCase->execute(
            languageId: 1,
            name: 'Minimal Feed',
            sourceUri: 'https://example.com/rss'
        );

        $this->assertSame('', $feed->articleSectionTags());
        $this->assertSame('', $feed->filterTags());
        $this->assertSame('', $feed->options()->toString());
    }

    #[Test]
    public function executeWithEmptyNameThrowsException(): void
    {
        $this->feedRepository
            ->expects($this->never())
            ->method('save');

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Feed name cannot be empty');

        $this->useCase->execute(
            languageId: 1,
            name: '',
            sourceUri: 'https://example.com/rss'
        );
    }

    #[Test]
    public function executeWithWhitespaceOnlyNameThrowsException(): void
    {
        $this->feedRepository
            ->expects($this->never())
            ->method('save');

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Feed name cannot be empty');

        $this->useCase->execute(
            languageId: 1,
            name: '   ',
            sourceUri: 'https://example.com/rss'
        );
    }

    #[Test]
    public function executeWithNameExceedingMaxLengthThrowsException(): void
    {
        $this->feedRepository
            ->expects($this->never())
            ->method('save');

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Feed name cannot exceed 40 characters');

        $this->useCase->execute(
            languageId: 1,
            name: str_repeat('a', 41),
            sourceUri: 'https://example.com/rss'
        );
    }

    #[Test]
    public function executeWithEmptyUriThrowsException(): void
    {
        $this->feedRepository
            ->expects($this->never())
            ->method('save');

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Feed source URI cannot be empty');

        $this->useCase->execute(
            languageId: 1,
            name: 'Test Feed',
            sourceUri: ''
        );
    }

    #[Test]
    public function executeWithUriExceedingMaxLengthThrowsException(): void
    {
        $this->feedRepository
            ->expects($this->never())
            ->method('save');

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Feed source URI cannot exceed 200 characters');

        $this->useCase->execute(
            languageId: 1,
            name: 'Test Feed',
            sourceUri: 'https://example.com/' . str_repeat('a', 200)
        );
    }

    #[Test]
    public function executeWithZeroLanguageIdThrowsException(): void
    {
        $this->feedRepository
            ->expects($this->never())
            ->method('save');

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Language ID must be positive');

        $this->useCase->execute(
            languageId: 0,
            name: 'Test Feed',
            sourceUri: 'https://example.com/rss'
        );
    }

    #[Test]
    public function executeWithNegativeLanguageIdThrowsException(): void
    {
        $this->feedRepository
            ->expects($this->never())
            ->method('save');

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Language ID must be positive');

        $this->useCase->execute(
            languageId: -1,
            name: 'Test Feed',
            sourceUri: 'https://example.com/rss'
        );
    }

    #[Test]
    public function executeReturnsFeedEntityDirectly(): void
    {
        $this->feedRepository
            ->method('save')
            ->willReturn(99);

        $feed = $this->useCase->execute(
            languageId: 2,
            name: 'Return Test',
            sourceUri: 'https://example.com/feed'
        );

        // The returned feed should be the same object created internally
        $this->assertNull($feed->id());
        $this->assertSame(0, $feed->updateTimestamp());
    }
}
