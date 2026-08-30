<?php

/**
 * Unit tests for ImportArticles use case.
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

use Lwt\Modules\Feed\Application\Services\ArticleExtractor;
use Lwt\Modules\Feed\Application\UseCases\ImportArticles;
use Lwt\Modules\Feed\Domain\Article;
use Lwt\Modules\Feed\Domain\ArticleRepositoryInterface;
use Lwt\Modules\Feed\Domain\Feed;
use Lwt\Modules\Feed\Domain\FeedRepositoryInterface;
use Lwt\Modules\Feed\Domain\TextCreationInterface;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Tests for the ImportArticles use case.
 *
 * @since 3.0.0
 */
class ImportArticlesTest extends TestCase
{
    private ArticleRepositoryInterface&MockObject $articleRepository;
    private FeedRepositoryInterface&MockObject $feedRepository;
    private TextCreationInterface&MockObject $textCreation;
    private ArticleExtractor&MockObject $articleExtractor;
    private ImportArticles $useCase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->articleRepository = $this->createMock(ArticleRepositoryInterface::class);
        $this->feedRepository = $this->createMock(FeedRepositoryInterface::class);
        $this->textCreation = $this->createMock(TextCreationInterface::class);
        $this->articleExtractor = $this->createMock(ArticleExtractor::class);

        $this->useCase = new ImportArticles(
            $this->articleRepository,
            $this->feedRepository,
            $this->textCreation,
            $this->articleExtractor
        );
    }

    private function createFeed(
        int $id = 1,
        int $languageId = 1,
        string $options = 'tag=my_tag,max_texts=100'
    ): Feed {
        return Feed::reconstitute(
            $id,
            $languageId,
            'Test Feed',
            'https://example.com/rss',
            '//article//p',
            '//div[@class="ads"]',
            1000,
            $options
        );
    }

    private function createArticle(int $id, int $feedId, string $title = 'Article', string $link = ''): Article
    {
        if ($link === '') {
            $link = 'https://example.com/article/' . $id;
        }
        return Article::reconstitute(
            $id,
            $feedId,
            $title,
            $link,
            'Description',
            '2026-01-01 00:00:00',
            '',
            ''
        );
    }

    #[Test]
    public function executeWithEmptyArrayReturnsZeroCounts(): void
    {
        $result = $this->useCase->execute([]);

        $this->assertSame(0, $result['imported']);
        $this->assertSame(0, $result['failed']);
        $this->assertSame(0, $result['archived']);
        $this->assertEmpty($result['errors']);
    }

    #[Test]
    public function executeWithNoMatchingArticlesReturnsZeroCounts(): void
    {
        $this->articleRepository
            ->expects($this->once())
            ->method('findByIds')
            ->with([999])
            ->willReturn([]);

        $result = $this->useCase->execute([999]);

        $this->assertSame(0, $result['imported']);
        $this->assertSame(0, $result['failed']);
    }

    #[Test]
    public function executeSkipsFeedWhenFeedNotFound(): void
    {
        $article = $this->createArticle(1, 42);
        $this->articleRepository->method('findByIds')->willReturn([$article]);
        $this->feedRepository->method('find')->with(42)->willReturn(null);

        $this->textCreation->expects($this->never())->method('createText');

        $result = $this->useCase->execute([1]);

        $this->assertSame(0, $result['imported']);
    }

    #[Test]
    public function executeImportsSuccessfullyExtractedArticles(): void
    {
        $feed = $this->createFeed(1, 5);
        $article = $this->createArticle(10, 1, 'My Article', 'https://example.com/a1');

        $this->articleRepository->method('findByIds')->willReturn([$article]);
        $this->feedRepository->method('find')->with(1)->willReturn($feed);

        $this->articleExtractor->method('extract')->willReturn([
            0 => [
                'TxTitle' => 'My Article',
                'TxText' => 'Some text content',
                'TxSourceURI' => 'https://example.com/a1',
                'TxAudioURI' => '',
            ],
        ]);

        $this->textCreation->method('sourceUriExists')->willReturn(false);
        $this->textCreation->expects($this->once())->method('createText')->with(
            5,
            'My Article',
            'Some text content',
            '',
            'https://example.com/a1',
            'my_tag'
        )->willReturn(100);
        $this->textCreation->method('archiveOldTexts')->willReturn(['archived' => 0]);

        $result = $this->useCase->execute([10]);

        $this->assertSame(1, $result['imported']);
        $this->assertSame(0, $result['failed']);
    }

    #[Test]
    public function executeSkipsDuplicateSourceUri(): void
    {
        $feed = $this->createFeed(1, 1);
        $article = $this->createArticle(10, 1);

        $this->articleRepository->method('findByIds')->willReturn([$article]);
        $this->feedRepository->method('find')->willReturn($feed);

        $this->articleExtractor->method('extract')->willReturn([
            0 => [
                'TxTitle' => 'Title',
                'TxText' => 'Text',
                'TxSourceURI' => 'https://example.com/existing',
                'TxAudioURI' => '',
            ],
        ]);

        $this->textCreation->method('sourceUriExists')
            ->with('https://example.com/existing')
            ->willReturn(true);

        $this->textCreation->expects($this->never())->method('createText');

        $result = $this->useCase->execute([10]);

        $this->assertSame(0, $result['imported']);
    }

    #[Test]
    public function executeHandlesExtractionErrors(): void
    {
        $feed = $this->createFeed(1, 1);
        $article = $this->createArticle(10, 1);

        $this->articleRepository->method('findByIds')->willReturn([$article]);
        $this->feedRepository->method('find')->willReturn($feed);

        $this->articleExtractor->method('extract')->willReturn([
            'error' => [
                'message' => 'Failed to load',
                'link' => ['https://example.com/bad'],
            ],
        ]);

        $this->articleRepository->expects($this->once())
            ->method('markAsError')
            ->with('https://example.com/bad');

        $result = $this->useCase->execute([10]);

        $this->assertSame(0, $result['imported']);
        $this->assertSame(1, $result['failed']);
        $this->assertArrayHasKey('1', $result['errors']);
    }

    #[Test]
    public function executeHandlesMultipleErrorLinks(): void
    {
        $feed = $this->createFeed(1, 1);
        $article1 = $this->createArticle(10, 1);
        $article2 = $this->createArticle(11, 1);

        $this->articleRepository->method('findByIds')->willReturn([$article1, $article2]);
        $this->feedRepository->method('find')->willReturn($feed);

        $this->articleExtractor->method('extract')->willReturn([
            'error' => [
                'link' => ['https://example.com/bad1', 'https://example.com/bad2'],
            ],
        ]);

        $this->articleRepository->expects($this->exactly(2))->method('markAsError');

        $result = $this->useCase->execute([10, 11]);

        $this->assertSame(2, $result['failed']);
    }

    #[Test]
    public function executeHandlesTextCreationException(): void
    {
        $feed = $this->createFeed(1, 1);
        $article = $this->createArticle(10, 1);

        $this->articleRepository->method('findByIds')->willReturn([$article]);
        $this->feedRepository->method('find')->willReturn($feed);

        $this->articleExtractor->method('extract')->willReturn([
            0 => [
                'TxTitle' => 'Title',
                'TxText' => 'Text',
                'TxSourceURI' => 'https://example.com/a1',
                'TxAudioURI' => '',
            ],
        ]);

        $this->textCreation->method('sourceUriExists')->willReturn(false);
        $this->textCreation->method('createText')
            ->willThrowException(new \Exception('DB error'));

        $result = $this->useCase->execute([10]);

        $this->assertSame(0, $result['imported']);
        $this->assertSame(1, $result['failed']);
        $this->assertContains('https://example.com/a1', $result['errors']['1']);
    }

    #[Test]
    public function executeArchivesOldTextsWhenImported(): void
    {
        $feed = $this->createFeed(1, 1, 'tag=my_tag,max_texts=50');
        $article = $this->createArticle(10, 1);

        $this->articleRepository->method('findByIds')->willReturn([$article]);
        $this->feedRepository->method('find')->willReturn($feed);

        $this->articleExtractor->method('extract')->willReturn([
            0 => [
                'TxTitle' => 'Title',
                'TxText' => 'Text',
                'TxSourceURI' => 'https://example.com/a1',
                'TxAudioURI' => '',
            ],
        ]);

        $this->textCreation->method('sourceUriExists')->willReturn(false);
        $this->textCreation->method('createText')->willReturn(1);

        $this->textCreation->expects($this->once())
            ->method('archiveOldTexts')
            ->with('my_tag', 50)
            ->willReturn(['archived' => 3]);

        $result = $this->useCase->execute([10]);

        $this->assertSame(1, $result['imported']);
        $this->assertSame(3, $result['archived']);
    }

    #[Test]
    public function executeDoesNotArchiveWhenNothingImported(): void
    {
        $feed = $this->createFeed(1, 1);
        $article = $this->createArticle(10, 1);

        $this->articleRepository->method('findByIds')->willReturn([$article]);
        $this->feedRepository->method('find')->willReturn($feed);

        // All extracted articles are duplicates
        $this->articleExtractor->method('extract')->willReturn([
            0 => [
                'TxTitle' => 'Title',
                'TxText' => 'Text',
                'TxSourceURI' => 'https://example.com/dup',
                'TxAudioURI' => '',
            ],
        ]);
        $this->textCreation->method('sourceUriExists')->willReturn(true);

        $this->textCreation->expects($this->never())->method('archiveOldTexts');

        $result = $this->useCase->execute([10]);

        $this->assertSame(0, $result['imported']);
        $this->assertSame(0, $result['archived']);
    }

    #[Test]
    public function executeUsesDefaultTagNameWhenNotConfigured(): void
    {
        $feed = $this->createFeed(7, 1, '');
        $article = $this->createArticle(10, 7);

        $this->articleRepository->method('findByIds')->willReturn([$article]);
        $this->feedRepository->method('find')->with(7)->willReturn($feed);

        $this->articleExtractor->method('extract')->willReturn([
            0 => [
                'TxTitle' => 'Title',
                'TxText' => 'Text',
                'TxSourceURI' => 'https://example.com/a1',
                'TxAudioURI' => '',
            ],
        ]);

        $this->textCreation->method('sourceUriExists')->willReturn(false);

        // Default tag should be 'feed_<feedId>'
        $this->textCreation->expects($this->once())
            ->method('createText')
            ->with(
                $this->anything(),
                $this->anything(),
                $this->anything(),
                $this->anything(),
                $this->anything(),
                'feed_7'
            )
            ->willReturn(1);

        $this->textCreation->method('archiveOldTexts')->willReturn(['archived' => 0]);

        $this->useCase->execute([10]);
    }

    #[Test]
    public function executeUsesDefaultMaxTexts100WhenNotConfigured(): void
    {
        $feed = $this->createFeed(1, 1, 'tag=test');
        $article = $this->createArticle(10, 1);

        $this->articleRepository->method('findByIds')->willReturn([$article]);
        $this->feedRepository->method('find')->willReturn($feed);

        $this->articleExtractor->method('extract')->willReturn([
            0 => [
                'TxTitle' => 'Title',
                'TxText' => 'Text',
                'TxSourceURI' => 'https://example.com/a1',
                'TxAudioURI' => '',
            ],
        ]);

        $this->textCreation->method('sourceUriExists')->willReturn(false);
        $this->textCreation->method('createText')->willReturn(1);

        $this->textCreation->expects($this->once())
            ->method('archiveOldTexts')
            ->with('test', 100)
            ->willReturn(['archived' => 0]);

        $this->useCase->execute([10]);
    }

    #[Test]
    public function executeGroupsArticlesByFeed(): void
    {
        $feed1 = $this->createFeed(1, 1, 'tag=feed1');
        $feed2 = $this->createFeed(2, 2, 'tag=feed2');

        $article1 = $this->createArticle(10, 1, 'Art1', 'https://example.com/a1');
        $article2 = $this->createArticle(11, 2, 'Art2', 'https://example.com/a2');

        $this->articleRepository->method('findByIds')->willReturn([$article1, $article2]);

        $this->feedRepository->method('find')
            ->willReturnCallback(function (int $id) use ($feed1, $feed2) {
                return match ($id) {
                    1 => $feed1,
                    2 => $feed2,
                    default => null,
                };
            });

        $this->articleExtractor->method('extract')
            ->willReturn([
                0 => [
                    'TxTitle' => 'Title',
                    'TxText' => 'Text',
                    'TxSourceURI' => 'https://example.com/x',
                    'TxAudioURI' => '',
                ],
            ]);

        $this->textCreation->method('sourceUriExists')->willReturn(false);
        $this->textCreation->method('createText')->willReturn(1);
        $this->textCreation->method('archiveOldTexts')->willReturn(['archived' => 0]);

        // Should call extract twice, once per feed
        $this->articleExtractor->expects($this->exactly(2))->method('extract');

        $result = $this->useCase->execute([10, 11]);

        $this->assertSame(2, $result['imported']);
    }

    #[Test]
    public function executePassesAudioUriFromExtractedData(): void
    {
        $feed = $this->createFeed(1, 3);
        $article = $this->createArticle(10, 1);

        $this->articleRepository->method('findByIds')->willReturn([$article]);
        $this->feedRepository->method('find')->willReturn($feed);

        $this->articleExtractor->method('extract')->willReturn([
            0 => [
                'TxTitle' => 'Podcast Episode',
                'TxText' => 'Episode content',
                'TxSourceURI' => 'https://example.com/ep1',
                'TxAudioURI' => 'https://example.com/audio.mp3',
            ],
        ]);

        $this->textCreation->method('sourceUriExists')->willReturn(false);

        $this->textCreation->expects($this->once())
            ->method('createText')
            ->with(
                3,
                'Podcast Episode',
                'Episode content',
                'https://example.com/audio.mp3',
                'https://example.com/ep1',
                'my_tag'
            )
            ->willReturn(1);

        $this->textCreation->method('archiveOldTexts')->willReturn(['archived' => 0]);

        $this->useCase->execute([10]);
    }

    #[Test]
    public function executePassesCharsetAndFilterTagsToExtractor(): void
    {
        $feed = $this->createFeed(1, 1, 'tag=test,charset=ISO-8859-1');
        $article = $this->createArticle(10, 1);

        $this->articleRepository->method('findByIds')->willReturn([$article]);
        $this->feedRepository->method('find')->willReturn($feed);

        $this->articleExtractor->expects($this->once())
            ->method('extract')
            ->with(
                $this->anything(),
                '//article//p',
                '//div[@class="ads"]',
                'ISO-8859-1'
            )
            ->willReturn([]);

        $this->useCase->execute([10]);
    }

    #[Test]
    public function executeHandlesMixedSuccessAndErrors(): void
    {
        $feed = $this->createFeed(1, 1);
        $article1 = $this->createArticle(10, 1);
        $article2 = $this->createArticle(11, 1);

        $this->articleRepository->method('findByIds')->willReturn([$article1, $article2]);
        $this->feedRepository->method('find')->willReturn($feed);

        $this->articleExtractor->method('extract')->willReturn([
            0 => [
                'TxTitle' => 'Good',
                'TxText' => 'Content',
                'TxSourceURI' => 'https://example.com/good',
                'TxAudioURI' => '',
            ],
            'error' => [
                'link' => ['https://example.com/bad'],
            ],
        ]);

        $this->textCreation->method('sourceUriExists')->willReturn(false);
        $this->textCreation->method('createText')->willReturn(1);
        $this->textCreation->method('archiveOldTexts')->willReturn(['archived' => 0]);
        $this->articleRepository->method('markAsError');

        $result = $this->useCase->execute([10, 11]);

        $this->assertSame(1, $result['imported']);
        $this->assertSame(1, $result['failed']);
    }

    #[Test]
    public function executeDoesNotArchiveWhenMaxTextsIsZero(): void
    {
        $feed = $this->createFeed(1, 1, 'tag=test,max_texts=0');
        $article = $this->createArticle(10, 1);

        $this->articleRepository->method('findByIds')->willReturn([$article]);
        $this->feedRepository->method('find')->willReturn($feed);

        $this->articleExtractor->method('extract')->willReturn([
            0 => [
                'TxTitle' => 'Title',
                'TxText' => 'Text',
                'TxSourceURI' => 'https://example.com/a1',
                'TxAudioURI' => '',
            ],
        ]);

        $this->textCreation->method('sourceUriExists')->willReturn(false);
        $this->textCreation->method('createText')->willReturn(1);

        // max_texts=0 means (int)"0" = 0, so archiveOldTexts should NOT be called
        $this->textCreation->expects($this->never())->method('archiveOldTexts');

        $this->useCase->execute([10]);
    }

    #[Test]
    public function executePassesArticleDataToExtractor(): void
    {
        $feed = $this->createFeed(1, 1);
        $article = Article::reconstitute(
            10,
            1,
            'My Title',
            'https://example.com/link',
            'My Description',
            '2026-01-01',
            'https://example.com/audio.mp3',
            'Inline text'
        );

        $this->articleRepository->method('findByIds')->willReturn([$article]);
        $this->feedRepository->method('find')->willReturn($feed);

        $this->articleExtractor->expects($this->once())
            ->method('extract')
            ->with(
                $this->callback(function (array $data) {
                    $this->assertCount(1, $data);
                    $this->assertSame('My Title', $data[0]['title']);
                    $this->assertSame('https://example.com/link', $data[0]['link']);
                    $this->assertSame('https://example.com/audio.mp3', $data[0]['audio']);
                    $this->assertSame('Inline text', $data[0]['text']);
                    return true;
                }),
                $this->anything(),
                $this->anything(),
                $this->anything()
            )
            ->willReturn([]);

        $this->useCase->execute([10]);
    }

    #[Test]
    public function executeAggregatesResultsFromMultipleFeeds(): void
    {
        $feed1 = $this->createFeed(1, 1, 'tag=f1');
        $feed2 = $this->createFeed(2, 2, 'tag=f2');

        $article1 = $this->createArticle(10, 1);
        $article2 = $this->createArticle(11, 2);
        $article3 = $this->createArticle(12, 2);

        $this->articleRepository->method('findByIds')->willReturn([$article1, $article2, $article3]);

        $callCount = 0;
        $this->feedRepository->method('find')->willReturnCallback(
            function (int $id) use ($feed1, $feed2) {
                return $id === 1 ? $feed1 : $feed2;
            }
        );

        $this->articleExtractor->method('extract')->willReturn([
            0 => [
                'TxTitle' => 'T',
                'TxText' => 'C',
                'TxSourceURI' => 'https://example.com/' . (++$callCount),
                'TxAudioURI' => '',
            ],
        ]);

        $this->textCreation->method('sourceUriExists')->willReturn(false);
        $this->textCreation->method('createText')->willReturn(1);
        $this->textCreation->method('archiveOldTexts')->willReturn(['archived' => 1]);

        $result = $this->useCase->execute([10, 11, 12]);

        $this->assertSame(2, $result['imported']);
        $this->assertSame(2, $result['archived']);
    }

    #[Test]
    public function executeHandlesErrorWithoutLinkKey(): void
    {
        $feed = $this->createFeed(1, 1);
        $article = $this->createArticle(10, 1);

        $this->articleRepository->method('findByIds')->willReturn([$article]);
        $this->feedRepository->method('find')->willReturn($feed);

        // Error entry without 'link' key
        $this->articleExtractor->method('extract')->willReturn([
            'error' => [
                'message' => 'Something went wrong',
            ],
        ]);

        $result = $this->useCase->execute([10]);

        $this->assertSame(0, $result['failed']);
    }
}
