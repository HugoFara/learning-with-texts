<?php

/**
 * Unit tests for Feed module use cases.
 *
 * Tests CreateFeed, ImportArticles, DeleteFeeds, and GetFeedList use cases
 * with mocked repository dependencies.
 *
 * PHP version 8.2
 *
 * @category Lwt
 * @package  Lwt\Tests\Modules\Feed\UseCases
 */

declare(strict_types=1);

namespace Lwt\Tests\Modules\Feed\UseCases;

use InvalidArgumentException;
use Lwt\Modules\Feed\Application\Services\ArticleExtractor;
use Lwt\Modules\Feed\Application\UseCases\CreateFeed;
use Lwt\Modules\Feed\Application\UseCases\DeleteFeeds;
use Lwt\Modules\Feed\Application\UseCases\GetFeedList;
use Lwt\Modules\Feed\Application\UseCases\ImportArticles;
use Lwt\Modules\Feed\Domain\Article;
use Lwt\Modules\Feed\Domain\ArticleRepositoryInterface;
use Lwt\Modules\Feed\Domain\Feed;
use Lwt\Modules\Feed\Domain\FeedRepositoryInterface;
use Lwt\Modules\Feed\Domain\TextCreationInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Tests for Feed module use cases.
 *
 * Verifies business logic for feed creation, article importing,
 * feed deletion, and feed listing with mocked repositories.
 */
class FeedUseCaseTest extends TestCase
{
    private FeedRepositoryInterface&MockObject $feedRepository;
    private ArticleRepositoryInterface&MockObject $articleRepository;
    private TextCreationInterface&MockObject $textCreation;
    private ArticleExtractor&MockObject $articleExtractor;

    protected function setUp(): void
    {
        parent::setUp();

        $this->feedRepository = $this->createMock(FeedRepositoryInterface::class);
        $this->articleRepository = $this->createMock(ArticleRepositoryInterface::class);
        $this->textCreation = $this->createMock(TextCreationInterface::class);
        $this->articleExtractor = $this->createMock(ArticleExtractor::class);
    }

    /**
     * Create a Feed entity for testing.
     *
     * @param int    $id        Feed ID
     * @param int    $langId    Language ID
     * @param string $name      Feed name
     * @param string $uri       Source URI
     * @param string $section   Article section tags
     * @param string $filter    Filter tags
     * @param int    $timestamp Update timestamp
     * @param string $options   Options string
     *
     * @return Feed
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

    /**
     * Create an Article entity for testing.
     *
     * @param int    $id     Article ID
     * @param int    $feedId Feed ID
     * @param string $title  Title
     * @param string $link   Link
     * @param string $desc   Description
     * @param string $date   Date
     * @param string $audio  Audio URL
     * @param string $text   Text content
     *
     * @return Article
     */
    private function makeArticle(
        int $id,
        int $feedId,
        string $title,
        string $link,
        string $desc = '',
        string $date = '2026-01-01',
        string $audio = '',
        string $text = ''
    ): Article {
        return Article::reconstitute(
            $id,
            $feedId,
            $title,
            $link,
            $desc,
            $date,
            $audio,
            $text
        );
    }

    // ---------------------------------------------------------------
    // CreateFeed
    // ---------------------------------------------------------------

    public function testCreateFeedWithValidData(): void
    {
        $useCase = new CreateFeed($this->feedRepository);

        $this->feedRepository
            ->expects($this->once())
            ->method('save')
            ->with($this->isInstanceOf(Feed::class))
            ->willReturn(42);

        $feed = $useCase->execute(
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

    public function testCreateFeedWithAllParameters(): void
    {
        $useCase = new CreateFeed($this->feedRepository);

        $this->feedRepository
            ->expects($this->once())
            ->method('save')
            ->willReturn(10);

        $feed = $useCase->execute(
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

    public function testCreateFeedWithEmptyNameThrowsException(): void
    {
        $useCase = new CreateFeed($this->feedRepository);

        $this->feedRepository
            ->expects($this->never())
            ->method('save');

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Feed name cannot be empty');

        $useCase->execute(
            languageId: 1,
            name: '',
            sourceUri: 'https://example.com/rss'
        );
    }

    public function testCreateFeedWithWhitespaceOnlyNameThrowsException(): void
    {
        $useCase = new CreateFeed($this->feedRepository);

        $this->feedRepository
            ->expects($this->never())
            ->method('save');

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Feed name cannot be empty');

        $useCase->execute(
            languageId: 1,
            name: '   ',
            sourceUri: 'https://example.com/rss'
        );
    }

    public function testCreateFeedWithEmptyUriThrowsException(): void
    {
        $useCase = new CreateFeed($this->feedRepository);

        $this->feedRepository
            ->expects($this->never())
            ->method('save');

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Feed source URI cannot be empty');

        $useCase->execute(
            languageId: 1,
            name: 'Test Feed',
            sourceUri: ''
        );
    }

    public function testCreateFeedWithNameExceedingMaxLengthThrowsException(): void
    {
        $useCase = new CreateFeed($this->feedRepository);

        $this->feedRepository
            ->expects($this->never())
            ->method('save');

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Feed name cannot exceed 40 characters');

        $useCase->execute(
            languageId: 1,
            name: str_repeat('a', 41),
            sourceUri: 'https://example.com/rss'
        );
    }

    public function testCreateFeedWithUriExceedingMaxLengthThrowsException(): void
    {
        $useCase = new CreateFeed($this->feedRepository);

        $this->feedRepository
            ->expects($this->never())
            ->method('save');

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Feed source URI cannot exceed 200 characters');

        $useCase->execute(
            languageId: 1,
            name: 'Test Feed',
            sourceUri: 'https://example.com/' . str_repeat('a', 200)
        );
    }

    public function testCreateFeedWithInvalidLanguageIdThrowsException(): void
    {
        $useCase = new CreateFeed($this->feedRepository);

        $this->feedRepository
            ->expects($this->never())
            ->method('save');

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Language ID must be positive');

        $useCase->execute(
            languageId: 0,
            name: 'Test Feed',
            sourceUri: 'https://example.com/rss'
        );
    }

    public function testCreateFeedWithNegativeLanguageIdThrowsException(): void
    {
        $useCase = new CreateFeed($this->feedRepository);

        $this->feedRepository
            ->expects($this->never())
            ->method('save');

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Language ID must be positive');

        $useCase->execute(
            languageId: -1,
            name: 'Test Feed',
            sourceUri: 'https://example.com/rss'
        );
    }

    // ---------------------------------------------------------------
    // DeleteFeeds
    // ---------------------------------------------------------------

    public function testDeleteFeedsDeletesArticlesFirst(): void
    {
        $useCase = new DeleteFeeds(
            $this->feedRepository,
            $this->articleRepository
        );

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

        $result = $useCase->execute([1, 2, 3]);

        $this->assertSame(3, $result['feeds']);
        $this->assertSame(15, $result['articles']);
    }

    public function testDeleteFeedsWithEmptyArrayReturnsZeroCounts(): void
    {
        $useCase = new DeleteFeeds(
            $this->feedRepository,
            $this->articleRepository
        );

        $this->articleRepository
            ->expects($this->never())
            ->method('deleteByFeeds');

        $this->feedRepository
            ->expects($this->never())
            ->method('deleteMultiple');

        $result = $useCase->execute([]);

        $this->assertSame(0, $result['feeds']);
        $this->assertSame(0, $result['articles']);
    }

    public function testDeleteFeedsSingleDeletesOneFeed(): void
    {
        $useCase = new DeleteFeeds(
            $this->feedRepository,
            $this->articleRepository
        );

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

        $result = $useCase->executeSingle(5);

        $this->assertTrue($result);
    }

    public function testDeleteFeedsSingleReturnsFalseWhenNotFound(): void
    {
        $useCase = new DeleteFeeds(
            $this->feedRepository,
            $this->articleRepository
        );

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

        $result = $useCase->executeSingle(999);

        $this->assertFalse($result);
    }

    // ---------------------------------------------------------------
    // GetFeedList
    // ---------------------------------------------------------------

    public function testGetFeedListWithDefaults(): void
    {
        $useCase = new GetFeedList(
            $this->feedRepository,
            $this->articleRepository
        );

        $feed1 = $this->makeFeed(1, 1, 'Feed A', 'https://a.com/rss', '', '', 1000);
        $feed2 = $this->makeFeed(2, 1, 'Feed B', 'https://b.com/rss', '', '', 2000);

        $this->feedRepository
            ->expects($this->once())
            ->method('findPaginated')
            ->with(
                0,
                50,
                null,
                null,
                'NfUpdate',
                'DESC'
            )
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

        $result = $useCase->execute();

        $this->assertCount(2, $result['feeds']);
        $this->assertSame(2, $result['total']);
        $this->assertSame([1 => 5, 2 => 12], $result['article_counts']);
    }

    public function testGetFeedListWithPaginationAndFilters(): void
    {
        $useCase = new GetFeedList(
            $this->feedRepository,
            $this->articleRepository
        );

        $feed = $this->makeFeed(3, 2, 'French Feed', 'https://fr.com/rss', '', '', 500);

        $this->feedRepository
            ->expects($this->once())
            ->method('findPaginated')
            ->with(
                10,
                25,
                2,
                '%french%',
                'NfName',
                'ASC'
            )
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

        $result = $useCase->execute(
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

    public function testGetFeedListEmptyResultSkipsArticleCounts(): void
    {
        $useCase = new GetFeedList(
            $this->feedRepository,
            $this->articleRepository
        );

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

        $result = $useCase->execute();

        $this->assertSame([], $result['feeds']);
        $this->assertSame(0, $result['total']);
        $this->assertSame([], $result['article_counts']);
    }

    public function testGetFeedListExecuteAllWithLanguageFilter(): void
    {
        $useCase = new GetFeedList(
            $this->feedRepository,
            $this->articleRepository
        );

        $feed = $this->makeFeed(1, 2, 'Feed', 'https://a.com/rss');

        $this->feedRepository
            ->expects($this->once())
            ->method('findByLanguage')
            ->with(2, 'NfUpdate', 'DESC')
            ->willReturn([$feed]);

        $this->feedRepository
            ->expects($this->never())
            ->method('findAll');

        $result = $useCase->executeAll(languageId: 2);

        $this->assertCount(1, $result);
        $this->assertSame('Feed', $result[0]->name());
    }

    public function testGetFeedListExecuteAllWithoutLanguageFilter(): void
    {
        $useCase = new GetFeedList(
            $this->feedRepository,
            $this->articleRepository
        );

        $this->feedRepository
            ->expects($this->once())
            ->method('findAll')
            ->with('NfName', 'ASC')
            ->willReturn([]);

        $this->feedRepository
            ->expects($this->never())
            ->method('findByLanguage');

        $result = $useCase->executeAll(
            languageId: null,
            orderBy: 'NfName',
            direction: 'ASC'
        );

        $this->assertSame([], $result);
    }

    public function testGetFeedListExecuteAllWithZeroLanguageIdFetchesAll(): void
    {
        $useCase = new GetFeedList(
            $this->feedRepository,
            $this->articleRepository
        );

        $this->feedRepository
            ->expects($this->once())
            ->method('findAll')
            ->with('NfUpdate', 'DESC')
            ->willReturn([]);

        $this->feedRepository
            ->expects($this->never())
            ->method('findByLanguage');

        $result = $useCase->executeAll(languageId: 0);

        $this->assertSame([], $result);
    }

    public function testGetFeedListExecuteForSelect(): void
    {
        $useCase = new GetFeedList(
            $this->feedRepository,
            $this->articleRepository
        );

        $expected = [
            ['id' => 1, 'name' => 'Feed A', 'language_id' => 1],
            ['id' => 2, 'name' => 'Feed B', 'language_id' => 1],
        ];

        $this->feedRepository
            ->expects($this->once())
            ->method('getForSelect')
            ->with(1, 40)
            ->willReturn($expected);

        $result = $useCase->executeForSelect(languageId: 1);

        $this->assertSame($expected, $result);
    }

    // ---------------------------------------------------------------
    // ImportArticles
    // ---------------------------------------------------------------

    public function testImportArticlesWithEmptyArrayReturnsZeroCounts(): void
    {
        $useCase = new ImportArticles(
            $this->articleRepository,
            $this->feedRepository,
            $this->textCreation,
            $this->articleExtractor
        );

        $result = $useCase->execute([]);

        $this->assertSame(0, $result['imported']);
        $this->assertSame(0, $result['failed']);
        $this->assertSame(0, $result['archived']);
        $this->assertSame([], $result['errors']);
    }

    public function testImportArticlesSuccessfulImport(): void
    {
        $useCase = new ImportArticles(
            $this->articleRepository,
            $this->feedRepository,
            $this->textCreation,
            $this->articleExtractor
        );

        $article = $this->makeArticle(
            10,
            1,
            'Article Title',
            'https://example.com/article',
            'desc'
        );
        $feed = $this->makeFeed(
            1,
            5,
            'Test Feed',
            'https://example.com/rss',
            '//article//p',
            '',
            1000,
            'tag=my_feed,max_texts=100'
        );

        $this->articleRepository
            ->expects($this->once())
            ->method('findByIds')
            ->with([10])
            ->willReturn([$article]);

        $this->feedRepository
            ->expects($this->once())
            ->method('find')
            ->with(1)
            ->willReturn($feed);

        $this->articleExtractor
            ->expects($this->once())
            ->method('extract')
            ->willReturn([
                0 => [
                    'TxTitle' => 'Article Title',
                    'TxText' => 'Extracted text content.',
                    'TxSourceURI' => 'https://example.com/article',
                    'TxAudioURI' => '',
                ],
            ]);

        $this->textCreation
            ->expects($this->once())
            ->method('sourceUriExists')
            ->with('https://example.com/article')
            ->willReturn(false);

        $this->textCreation
            ->expects($this->once())
            ->method('createText')
            ->with(
                5,
                'Article Title',
                'Extracted text content.',
                '',
                'https://example.com/article',
                'my_feed'
            )
            ->willReturn(100);

        $this->textCreation
            ->expects($this->once())
            ->method('archiveOldTexts')
            ->with('my_feed', 100)
            ->willReturn(['archived' => 2, 'sentences' => 10, 'textitems' => 50]);

        $result = $useCase->execute([10]);

        $this->assertSame(1, $result['imported']);
        $this->assertSame(0, $result['failed']);
        $this->assertSame(2, $result['archived']);
    }

    public function testImportArticlesSkipsDuplicateSourceUri(): void
    {
        $useCase = new ImportArticles(
            $this->articleRepository,
            $this->feedRepository,
            $this->textCreation,
            $this->articleExtractor
        );

        $article = $this->makeArticle(
            10,
            1,
            'Existing Article',
            'https://example.com/existing'
        );
        $feed = $this->makeFeed(
            1,
            5,
            'Test Feed',
            'https://example.com/rss',
            '//article',
            '',
            1000,
            'max_texts=50'
        );

        $this->articleRepository
            ->expects($this->once())
            ->method('findByIds')
            ->willReturn([$article]);

        $this->feedRepository
            ->expects($this->once())
            ->method('find')
            ->willReturn($feed);

        $this->articleExtractor
            ->expects($this->once())
            ->method('extract')
            ->willReturn([
                0 => [
                    'TxTitle' => 'Existing Article',
                    'TxText' => 'Some text',
                    'TxSourceURI' => 'https://example.com/existing',
                    'TxAudioURI' => '',
                ],
            ]);

        $this->textCreation
            ->expects($this->once())
            ->method('sourceUriExists')
            ->with('https://example.com/existing')
            ->willReturn(true);

        $this->textCreation
            ->expects($this->never())
            ->method('createText');

        // No imports means no archiving
        $this->textCreation
            ->expects($this->never())
            ->method('archiveOldTexts');

        $result = $useCase->execute([10]);

        $this->assertSame(0, $result['imported']);
        $this->assertSame(0, $result['failed']);
    }

    public function testImportArticlesHandlesExtractionErrors(): void
    {
        $useCase = new ImportArticles(
            $this->articleRepository,
            $this->feedRepository,
            $this->textCreation,
            $this->articleExtractor
        );

        $article = $this->makeArticle(
            10,
            1,
            'Bad Article',
            'https://example.com/bad'
        );
        $feed = $this->makeFeed(
            1,
            5,
            'Test Feed',
            'https://example.com/rss',
            '//article',
            '',
            1000,
            'max_texts=50'
        );

        $this->articleRepository
            ->expects($this->once())
            ->method('findByIds')
            ->willReturn([$article]);

        $this->feedRepository
            ->expects($this->once())
            ->method('find')
            ->willReturn($feed);

        $this->articleExtractor
            ->expects($this->once())
            ->method('extract')
            ->willReturn([
                'error' => [
                    'message' => 'Failed to extract',
                    'link' => ['https://example.com/bad'],
                ],
            ]);

        $this->articleRepository
            ->expects($this->once())
            ->method('markAsError')
            ->with('https://example.com/bad');

        $this->textCreation
            ->expects($this->never())
            ->method('createText');

        $result = $useCase->execute([10]);

        $this->assertSame(0, $result['imported']);
        $this->assertSame(1, $result['failed']);
        $this->assertArrayHasKey('1', $result['errors']);
    }

    public function testImportArticlesHandlesTextCreationException(): void
    {
        $useCase = new ImportArticles(
            $this->articleRepository,
            $this->feedRepository,
            $this->textCreation,
            $this->articleExtractor
        );

        $article = $this->makeArticle(
            10,
            1,
            'Article',
            'https://example.com/article'
        );
        $feed = $this->makeFeed(
            1,
            5,
            'Test Feed',
            'https://example.com/rss',
            '//article',
            '',
            1000,
            'tag=test,max_texts=50'
        );

        $this->articleRepository
            ->expects($this->once())
            ->method('findByIds')
            ->willReturn([$article]);

        $this->feedRepository
            ->expects($this->once())
            ->method('find')
            ->willReturn($feed);

        $this->articleExtractor
            ->expects($this->once())
            ->method('extract')
            ->willReturn([
                0 => [
                    'TxTitle' => 'Article',
                    'TxText' => 'Content',
                    'TxSourceURI' => 'https://example.com/article',
                    'TxAudioURI' => '',
                ],
            ]);

        $this->textCreation
            ->method('sourceUriExists')
            ->willReturn(false);

        $this->textCreation
            ->expects($this->once())
            ->method('createText')
            ->willThrowException(new \Exception('DB error'));

        // No successful imports, so no archiving
        $this->textCreation
            ->expects($this->never())
            ->method('archiveOldTexts');

        $result = $useCase->execute([10]);

        $this->assertSame(0, $result['imported']);
        $this->assertSame(1, $result['failed']);
        $this->assertContains(
            'https://example.com/article',
            $result['errors']['1']
        );
    }

    public function testImportArticlesSkipsMissingFeed(): void
    {
        $useCase = new ImportArticles(
            $this->articleRepository,
            $this->feedRepository,
            $this->textCreation,
            $this->articleExtractor
        );

        $article = $this->makeArticle(
            10,
            999,
            'Orphan Article',
            'https://example.com/orphan'
        );

        $this->articleRepository
            ->expects($this->once())
            ->method('findByIds')
            ->willReturn([$article]);

        $this->feedRepository
            ->expects($this->once())
            ->method('find')
            ->with(999)
            ->willReturn(null);

        $this->articleExtractor
            ->expects($this->never())
            ->method('extract');

        $result = $useCase->execute([10]);

        $this->assertSame(0, $result['imported']);
        $this->assertSame(0, $result['failed']);
    }

    public function testImportArticlesUsesDefaultTagWhenOptionNotSet(): void
    {
        $useCase = new ImportArticles(
            $this->articleRepository,
            $this->feedRepository,
            $this->textCreation,
            $this->articleExtractor
        );

        $article = $this->makeArticle(
            10,
            1,
            'Article',
            'https://example.com/art'
        );
        // Feed with no tag option set, ID=7
        $feed = $this->makeFeed(
            7,
            5,
            'No Tag Feed',
            'https://example.com/rss',
            '//article',
            '',
            1000,
            'max_texts=100'
        );

        $this->articleRepository
            ->method('findByIds')
            ->willReturn([$article]);

        $this->feedRepository
            ->method('find')
            ->willReturn($feed);

        $this->articleExtractor
            ->method('extract')
            ->willReturn([
                0 => [
                    'TxTitle' => 'Article',
                    'TxText' => 'Content',
                    'TxSourceURI' => 'https://example.com/art',
                    'TxAudioURI' => '',
                ],
            ]);

        $this->textCreation
            ->method('sourceUriExists')
            ->willReturn(false);

        // Expect tag to be 'feed_7' (feed_ prefix + feed ID)
        $this->textCreation
            ->expects($this->once())
            ->method('createText')
            ->with(
                5,
                'Article',
                'Content',
                '',
                'https://example.com/art',
                'feed_7'
            )
            ->willReturn(100);

        $this->textCreation
            ->method('archiveOldTexts')
            ->willReturn(['archived' => 0, 'sentences' => 0, 'textitems' => 0]);

        $useCase->execute([10]);
    }

    public function testImportArticlesGroupsByFeed(): void
    {
        $useCase = new ImportArticles(
            $this->articleRepository,
            $this->feedRepository,
            $this->textCreation,
            $this->articleExtractor
        );

        $article1 = $this->makeArticle(1, 10, 'Art 1', 'https://a.com/1');
        $article2 = $this->makeArticle(2, 20, 'Art 2', 'https://b.com/2');

        $feed10 = $this->makeFeed(
            10,
            1,
            'Feed 10',
            'https://a.com/rss',
            '//p',
            '',
            0,
            'tag=f10,max_texts=50'
        );
        $feed20 = $this->makeFeed(
            20,
            2,
            'Feed 20',
            'https://b.com/rss',
            '//p',
            '',
            0,
            'tag=f20,max_texts=50'
        );

        $this->articleRepository
            ->method('findByIds')
            ->willReturn([$article1, $article2]);

        $this->feedRepository
            ->method('find')
            ->willReturnMap([
                [10, $feed10],
                [20, $feed20],
            ]);

        $callCount = 0;
        $this->articleExtractor
            ->method('extract')
            ->willReturnCallback(function () use (&$callCount) {
                $callCount++;
                return [
                    0 => [
                        'TxTitle' => 'Title',
                        'TxText' => 'Text',
                        'TxSourceURI' => 'https://example.com/' . $callCount,
                        'TxAudioURI' => '',
                    ],
                ];
            });

        $this->textCreation
            ->method('sourceUriExists')
            ->willReturn(false);

        $this->textCreation
            ->expects($this->exactly(2))
            ->method('createText')
            ->willReturn(1);

        $this->textCreation
            ->method('archiveOldTexts')
            ->willReturn(['archived' => 0, 'sentences' => 0, 'textitems' => 0]);

        $result = $useCase->execute([1, 2]);

        $this->assertSame(2, $result['imported']);
        $this->assertSame(2, $callCount);
    }
}
