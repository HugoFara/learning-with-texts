<?php

declare(strict_types=1);

namespace Lwt\Tests\Modules\Feed\Http;

use Lwt\Modules\Feed\Http\FeedApiHandler;
use Lwt\Modules\Feed\Application\FeedFacade;
use Lwt\Shared\Http\ApiRoutableInterface;
use Lwt\Shared\Infrastructure\Http\JsonResponse;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\MockObject\MockObject;

/**
 * Unit tests for FeedApiHandler.
 *
 * Tests feed API operations including CRUD, articles, import functionality,
 * routing dispatch, formatArticleRecord, and structural validation.
 */
class FeedApiHandlerTest extends TestCase
{
    /** @var FeedFacade&MockObject */
    private FeedFacade $feedFacade;

    private FeedApiHandler $handler;

    protected function setUp(): void
    {
        if (!defined('LWT_TEST_DB_AVAILABLE') || !LWT_TEST_DB_AVAILABLE) {
            $this->markTestSkipped('Database connection required');
        }
        $this->feedFacade = $this->createMock(FeedFacade::class);
        $this->handler = new FeedApiHandler($this->feedFacade);
    }

    // =========================================================================
    // Constructor tests
    // =========================================================================

    public function testConstructorCreatesValidHandler(): void
    {
        $this->assertInstanceOf(FeedApiHandler::class, $this->handler);
    }

    // =========================================================================
    // getFeedsList tests
    // =========================================================================

    public function testGetFeedsListReturnsZerosForEmptyFeed(): void
    {
        $result = $this->handler->getFeedsList([], 1);

        $this->assertSame([0, 0], $result);
    }

    // =========================================================================
    // loadFeed tests
    // =========================================================================

    public function testLoadFeedReturnsErrorWhenParsingFails(): void
    {
        // loadFeed() refuses a feed the caller does not own; this test is
        // about parsing, so give it one it owns. FeedLoadOwnershipTest covers
        // the refusal.
        $this->feedFacade->method('getFeedById')->willReturn(['NfID' => 1]);
        $this->feedFacade->method('getFeedOption')
            ->willReturn('');
        $this->feedFacade->method('parseRssFeed')
            ->willReturn(false);

        $result = $this->handler->loadFeed('Test Feed', 1, 'http://example.com/feed', '');

        $this->assertArrayHasKey('error', $result);
        $this->assertStringContainsString('Could not load', $result['error']);
    }

    public function testLoadFeedReturnsErrorWhenParsingReturnsEmptyArray(): void
    {
        // loadFeed() refuses a feed the caller does not own; this test is
        // about parsing, so give it one it owns. FeedLoadOwnershipTest covers
        // the refusal.
        $this->feedFacade->method('getFeedById')->willReturn(['NfID' => 1]);
        $this->feedFacade->method('getFeedOption')
            ->willReturn('');
        $this->feedFacade->method('parseRssFeed')
            ->willReturn([]);

        $result = $this->handler->loadFeed('Test Feed', 1, 'http://example.com/feed', '');

        $this->assertArrayHasKey('error', $result);
    }

    // =========================================================================
    // createFeed tests
    // =========================================================================

    public function testCreateFeedReturnsErrorWhenLanguageIdMissing(): void
    {
        $result = $this->handler->createFeed([
            'name' => 'Test Feed',
            'sourceUri' => 'http://example.com/feed'
        ]);

        $this->assertFalse($result['success']);
        $this->assertSame('Language is required', $result['error']);
    }

    public function testCreateFeedReturnsErrorWhenLanguageIdZero(): void
    {
        $result = $this->handler->createFeed([
            'langId' => 0,
            'name' => 'Test Feed',
            'sourceUri' => 'http://example.com/feed'
        ]);

        $this->assertFalse($result['success']);
        $this->assertSame('Language is required', $result['error']);
    }

    public function testCreateFeedReturnsErrorWhenNameEmpty(): void
    {
        $result = $this->handler->createFeed([
            'langId' => 1,
            'name' => '',
            'sourceUri' => 'http://example.com/feed'
        ]);

        $this->assertFalse($result['success']);
        $this->assertSame('Feed name is required', $result['error']);
    }

    public function testCreateFeedReturnsErrorWhenNameOnlyWhitespace(): void
    {
        $result = $this->handler->createFeed([
            'langId' => 1,
            'name' => '   ',
            'sourceUri' => 'http://example.com/feed'
        ]);

        $this->assertFalse($result['success']);
        $this->assertSame('Feed name is required', $result['error']);
    }

    public function testCreateFeedReturnsErrorWhenSourceUriEmpty(): void
    {
        $result = $this->handler->createFeed([
            'langId' => 1,
            'name' => 'Test Feed',
            'sourceUri' => ''
        ]);

        $this->assertFalse($result['success']);
        $this->assertSame('Source URI is required', $result['error']);
    }

    public function testCreateFeedReturnsErrorWhenSourceUriMissing(): void
    {
        $result = $this->handler->createFeed([
            'langId' => 1,
            'name' => 'Test Feed'
        ]);

        $this->assertFalse($result['success']);
        $this->assertSame('Source URI is required', $result['error']);
    }

    // =========================================================================
    // updateFeed tests
    // =========================================================================

    public function testUpdateFeedReturnsErrorWhenFeedNotFound(): void
    {
        $this->feedFacade->method('getFeedById')
            ->willReturn(null);

        $result = $this->handler->updateFeed(999, ['name' => 'Updated']);

        $this->assertFalse($result['success']);
        $this->assertSame('Feed not found', $result['error']);
    }

    // =========================================================================
    // deleteFeeds tests
    // =========================================================================

    public function testDeleteFeedsReturnsFailureForEmptyArray(): void
    {
        $result = $this->handler->deleteFeeds([]);

        $this->assertFalse($result['success']);
        $this->assertSame(0, $result['deleted']);
    }

    public function testDeleteFeedsCallsFacadeWithFormattedIds(): void
    {
        $this->feedFacade->expects($this->once())
            ->method('deleteFeeds')
            ->with('1,2,3')
            ->willReturn(['feeds' => 3]);

        $result = $this->handler->deleteFeeds([1, 2, 3]);

        $this->assertTrue($result['success']);
        $this->assertSame(3, $result['deleted']);
    }

    public function testDeleteFeedsSanitizesIdValues(): void
    {
        $this->feedFacade->expects($this->once())
            ->method('deleteFeeds')
            ->with('1,2,0')
            ->willReturn(['feeds' => 2]);

        $result = $this->handler->deleteFeeds(['1', '2', 'invalid']);

        $this->assertTrue($result['success']);
    }

    // =========================================================================
    // getFeed tests
    // =========================================================================

    public function testGetFeedReturnsErrorWhenNotFound(): void
    {
        $this->feedFacade->method('getFeedById')
            ->willReturn(null);

        $result = $this->handler->getFeed(999);

        $this->assertArrayHasKey('error', $result);
        $this->assertSame('Feed not found', $result['error']);
    }

    // =========================================================================
    // getArticles tests
    // =========================================================================

    public function testGetArticlesReturnsErrorWhenFeedIdMissing(): void
    {
        $result = $this->handler->getArticles([]);

        $this->assertArrayHasKey('error', $result);
        $this->assertSame('Feed ID is required', $result['error']);
    }

    public function testGetArticlesReturnsErrorWhenFeedIdZero(): void
    {
        $result = $this->handler->getArticles(['feed_id' => 0]);

        $this->assertArrayHasKey('error', $result);
        $this->assertSame('Feed ID is required', $result['error']);
    }

    public function testGetArticlesReturnsErrorWhenFeedNotFound(): void
    {
        $this->feedFacade->method('getFeedById')
            ->willReturn(null);

        $result = $this->handler->getArticles(['feed_id' => 999]);

        $this->assertArrayHasKey('error', $result);
        $this->assertSame('Feed not found', $result['error']);
    }

    // =========================================================================
    // importArticles tests
    // =========================================================================

    public function testImportArticlesReturnsErrorWhenNoArticlesSelected(): void
    {
        $result = $this->handler->importArticles([]);

        $this->assertFalse($result['success']);
        $this->assertSame(0, $result['imported']);
        $this->assertContains('No articles selected', $result['errors']);
    }

    public function testImportArticlesReturnsErrorWhenArticleIdsEmpty(): void
    {
        $result = $this->handler->importArticles(['article_ids' => []]);

        $this->assertFalse($result['success']);
        $this->assertContains('No articles selected', $result['errors']);
    }

    public function testImportArticlesReturnsErrorWhenArticleIdsNotArray(): void
    {
        $result = $this->handler->importArticles(['article_ids' => 'not-array']);

        $this->assertFalse($result['success']);
    }

    // =========================================================================
    // resetErrorArticles tests
    // =========================================================================

    public function testResetErrorArticlesCallsFacade(): void
    {
        $this->feedFacade->expects($this->once())
            ->method('resetUnloadableArticles')
            ->with('5')
            ->willReturn(3);

        $result = $this->handler->resetErrorArticles(5);

        $this->assertTrue($result['success']);
        $this->assertSame(3, $result['reset']);
    }

    // =========================================================================
    // parseFeed tests
    // =========================================================================

    public function testParseFeedReturnsNullOnFailure(): void
    {
        $this->feedFacade->method('parseRssFeed')
            ->willReturn(false);

        $result = $this->handler->parseFeed('http://example.com/feed');

        $this->assertNull($result);
    }

    public function testParseFeedReturnsArrayOnSuccess(): void
    {
        $feedData = [
            ['title' => 'Article 1', 'link' => 'http://example.com/1'],
            ['title' => 'Article 2', 'link' => 'http://example.com/2']
        ];
        $this->feedFacade->method('parseRssFeed')
            ->willReturn($feedData);

        $result = $this->handler->parseFeed('http://example.com/feed');

        $this->assertSame($feedData, $result);
    }

    public function testParseFeedPassesArticleSectionToFacade(): void
    {
        $this->feedFacade->expects($this->once())
            ->method('parseRssFeed')
            ->with('http://example.com/feed', 'article.content')
            ->willReturn([]);

        $this->handler->parseFeed('http://example.com/feed', 'article.content');
    }

    // =========================================================================
    // detectFeed tests
    // =========================================================================

    public function testDetectFeedReturnsNullOnFailure(): void
    {
        $this->feedFacade->method('detectAndParseFeed')
            ->willReturn(false);

        $result = $this->handler->detectFeed('http://example.com/feed');

        $this->assertNull($result);
    }

    public function testDetectFeedReturnsArrayOnSuccess(): void
    {
        $feedData = ['type' => 'rss', 'items' => []];
        $this->feedFacade->method('detectAndParseFeed')
            ->willReturn($feedData);

        $result = $this->handler->detectFeed('http://example.com/feed');

        $this->assertSame($feedData, $result);
    }

    // =========================================================================
    // getFeeds tests
    // =========================================================================

    public function testGetFeedsCallsFacadeWithoutLanguageId(): void
    {
        $feeds = [['NfID' => 1, 'NfName' => 'Feed 1']];
        $this->feedFacade->expects($this->once())
            ->method('getFeeds')
            ->with(null)
            ->willReturn($feeds);

        $result = $this->handler->getFeeds();

        $this->assertSame($feeds, $result);
    }

    public function testGetFeedsCallsFacadeWithLanguageId(): void
    {
        $feeds = [['NfID' => 1, 'NfName' => 'Feed 1']];
        $this->feedFacade->expects($this->once())
            ->method('getFeeds')
            ->with(5)
            ->willReturn($feeds);

        $result = $this->handler->getFeeds(5);

        $this->assertSame($feeds, $result);
    }

    // =========================================================================
    // getFeedsNeedingAutoUpdate tests
    // =========================================================================

    public function testGetFeedsNeedingAutoUpdateCallsFacade(): void
    {
        $feeds = [['NfID' => 1], ['NfID' => 2]];
        $this->feedFacade->expects($this->once())
            ->method('getFeedsNeedingAutoUpdate')
            ->willReturn($feeds);

        $result = $this->handler->getFeedsNeedingAutoUpdate();

        $this->assertSame($feeds, $result);
    }

    // =========================================================================
    // getFeedLoadConfig tests
    // =========================================================================

    public function testGetFeedLoadConfigCallsFacadeWithDefaults(): void
    {
        $config = ['feedId' => 1, 'autoUpdate' => false];
        $this->feedFacade->expects($this->once())
            ->method('getFeedLoadConfig')
            ->with(1, false)
            ->willReturn($config);

        $result = $this->handler->getFeedLoadConfig(1);

        $this->assertSame($config, $result);
    }

    public function testGetFeedLoadConfigPassesAutoUpdateFlag(): void
    {
        $this->feedFacade->expects($this->once())
            ->method('getFeedLoadConfig')
            ->with(1, true)
            ->willReturn([]);

        $this->handler->getFeedLoadConfig(1, true);
    }

    // =========================================================================
    // Format method tests (thin wrappers)
    // =========================================================================

    public function testFormatLoadFeedDelegatesToLoadFeed(): void
    {
        $this->feedFacade->method('getFeedOption')->willReturn('');
        $this->feedFacade->method('parseRssFeed')->willReturn(false);

        $result = $this->handler->formatLoadFeed('Test', 1, 'http://test.com', '');

        $this->assertArrayHasKey('error', $result);
    }

    public function testFormatDeleteFeedsDelegatesToDeleteFeeds(): void
    {
        $result = $this->handler->formatDeleteFeeds([]);

        $this->assertFalse($result['success']);
    }

    public function testFormatImportArticlesDelegatesToImportArticles(): void
    {
        $result = $this->handler->formatImportArticles([]);

        $this->assertFalse($result['success']);
    }

    public function testFormatResetErrorArticlesDelegatesToResetErrorArticles(): void
    {
        $this->feedFacade->method('resetUnloadableArticles')->willReturn(0);

        $result = $this->handler->formatResetErrorArticles(1);

        $this->assertTrue($result['success']);
    }

    // =========================================================================
    // deleteArticles tests
    // =========================================================================

    public function testDeleteArticlesCallsFacadeWhenArticleIdsEmpty(): void
    {
        // Multi-user defence runs getFeedById first; make it succeed so
        // we still reach the underlying facade call.
        $this->feedFacade->method('getFeedById')
            ->willReturn(['NfID' => 5, 'NfUsID' => 1, 'NfName' => 'Test']);
        $this->feedFacade->expects($this->once())
            ->method('deleteArticles')
            ->with('5')
            ->willReturn(10);

        $result = $this->handler->deleteArticles(5, []);

        $this->assertTrue($result['success']);
        $this->assertSame(10, $result['deleted']);
    }

    // =========================================================================
    // Additional format method tests
    // =========================================================================

    public function testFormatGetFeedListDelegatesToGetFeedList(): void
    {
        // This will fail without DB, but tests structure
        $result = $this->handler->formatGetFeedList([]);

        $this->assertIsArray($result);
    }

    public function testFormatGetFeedDelegatesToGetFeed(): void
    {
        $this->feedFacade->method('getFeedById')->willReturn(null);

        $result = $this->handler->formatGetFeed(999);

        $this->assertArrayHasKey('error', $result);
    }

    public function testFormatCreateFeedDelegatesToCreateFeed(): void
    {
        $result = $this->handler->formatCreateFeed([]);

        $this->assertFalse($result['success']);
    }

    public function testFormatUpdateFeedDelegatesToUpdateFeed(): void
    {
        $this->feedFacade->method('getFeedById')->willReturn(null);

        $result = $this->handler->formatUpdateFeed(999, []);

        $this->assertFalse($result['success']);
    }

    public function testFormatGetArticlesDelegatesToGetArticles(): void
    {
        $result = $this->handler->formatGetArticles([]);

        $this->assertArrayHasKey('error', $result);
    }

    public function testFormatDeleteArticlesDelegatesToDeleteArticles(): void
    {
        // The DELETE path now goes through a feed-ownership pre-check
        // (FeedArticleApiHandler::deleteArticles); make the lookup
        // succeed so we can still reach the delegated facade call.
        $this->feedFacade->method('getFeedById')
            ->willReturn(['NfID' => 1, 'NfUsID' => 1, 'NfName' => 'TestFeed']);
        $this->feedFacade->method('deleteArticles')->willReturn(0);

        $result = $this->handler->formatDeleteArticles(1, []);

        $this->assertTrue($result['success']);
    }

    // =========================================================================
    // createFeed additional validation tests
    // =========================================================================

    public function testCreateFeedCallsFacadeWithValidData(): void
    {
        $this->feedFacade->expects($this->once())
            ->method('createFeed')
            ->willReturn(123);
        $this->feedFacade->method('getFeedById')
            ->willReturn([
                'NfID' => 123,
                'NfName' => 'Test Feed',
                'NfSourceURI' => 'http://example.com/feed',
                'NfLgID' => 1,
                'NfArticleSectionTags' => '',
                'NfFilterTags' => '',
                'NfOptions' => '',
                'NfUpdate' => 0,
            ]);
        $this->feedFacade->method('getFeedOption')->willReturn([]);
        $this->feedFacade->method('formatLastUpdate')->willReturn('never');

        $result = $this->handler->createFeed([
            'langId' => 1,
            'name' => 'Test Feed',
            'sourceUri' => 'http://example.com/feed'
        ]);

        $this->assertTrue($result['success']);
    }

    // =========================================================================
    // updateFeed additional tests
    // =========================================================================

    public function testUpdateFeedCallsFacadeWithPartialData(): void
    {
        $this->feedFacade->method('getFeedById')
            ->willReturn([
                'NfID' => 1,
                'NfName' => 'Old Name',
                'NfSourceURI' => 'http://old.com',
                'NfLgID' => 1,
                'NfArticleSectionTags' => '',
                'NfFilterTags' => '',
                'NfOptions' => '',
                'NfUpdate' => 0,
            ]);
        $this->feedFacade->expects($this->once())
            ->method('updateFeed');
        $this->feedFacade->method('getFeedOption')->willReturn([]);
        $this->feedFacade->method('formatLastUpdate')->willReturn('never');

        $result = $this->handler->updateFeed(1, ['name' => 'New Name']);

        $this->assertTrue($result['success']);
    }

    // =========================================================================
    // importArticles additional tests
    // =========================================================================

    public function testImportArticlesCallsFacadeWithArticleIds(): void
    {
        $this->feedFacade->expects($this->once())
            ->method('getMarkedFeedLinks')
            ->with('1,2,3')
            ->willReturn([]);

        $result = $this->handler->importArticles(['article_ids' => [1, 2, 3]]);

        $this->assertTrue($result['success']);
        $this->assertSame(0, $result['imported']);
    }

    public function testImportArticlesHandlesExtractionErrors(): void
    {
        $row = [
            'NfID' => 1,
            'NfName' => 'Feed',
            'NfLgID' => 1,
            'NfOptions' => '',
            'NfArticleSectionTags' => '',
            'NfFilterTags' => '',
            'FlID' => 10,
            'FlTitle' => 'Article',
            'FlLink' => 'http://example.com/1',
            'FlAudio' => '',
            'FlText' => 'Text',
        ];

        $this->feedFacade->method('getMarkedFeedLinks')->willReturn([$row]);
        $this->feedFacade->method('getFeedOption')->willReturn(null);
        $this->feedFacade->method('extractTextFromArticle')
            ->willReturn([
                'error' => [
                    'message' => 'Parse error',
                    'link' => ['http://example.com/1']
                ]
            ]);

        $this->feedFacade->expects($this->once())
            ->method('markLinkAsError')
            ->with('http://example.com/1');

        $this->feedFacade->method('archiveOldTexts')
            ->willReturn(['archived' => 0, 'sentences' => 0, 'textitems' => 0]);

        $result = $this->handler->importArticles(['article_ids' => [10]]);

        $this->assertTrue($result['success']);
        $this->assertContains('Parse error', $result['errors']);
    }

    public function testImportArticlesCreatesTextsSuccessfully(): void
    {
        $row = [
            'NfID' => 1,
            'NfName' => 'Feed',
            'NfLgID' => 2,
            'NfOptions' => 'tag:custom',
            'NfArticleSectionTags' => '',
            'NfFilterTags' => '',
            'FlID' => 10,
            'FlTitle' => 'Article',
            'FlLink' => 'http://example.com/1',
            'FlAudio' => 'http://example.com/audio.mp3',
            'FlText' => 'Article text',
        ];

        $this->feedFacade->method('getMarkedFeedLinks')->willReturn([$row]);
        $this->feedFacade->method('getFeedOption')
            ->willReturnCallback(function (string $opts, string $opt) {
                if ($opt === 'tag') {
                    return 'custom';
                }
                if ($opt === 'max_texts') {
                    return '5';
                }
                return null;
            });
        $this->feedFacade->method('extractTextFromArticle')
            ->willReturn([
                ['TxTitle' => 'Art1', 'TxText' => 'Body1', 'TxAudioURI' => '', 'TxSourceURI' => 'http://example.com/1'],
                ['TxTitle' => 'Art2', 'TxText' => 'Body2', 'TxAudioURI' => '', 'TxSourceURI' => 'http://example.com/2'],
            ]);

        $this->feedFacade->expects($this->exactly(2))
            ->method('createTextFromFeed');
        $this->feedFacade->method('archiveOldTexts')
            ->willReturn(['archived' => 0, 'sentences' => 0, 'textitems' => 0]);

        $result = $this->handler->importArticles(['article_ids' => [10]]);

        $this->assertTrue($result['success']);
        $this->assertSame(2, $result['imported']);
    }

    // =========================================================================
    // loadFeed additional tests
    // =========================================================================

    public function testLoadFeedErrorMessageContainsFeedName(): void
    {
        // loadFeed() refuses a feed the caller does not own; this test is
        // about parsing, so give it one it owns. FeedLoadOwnershipTest covers
        // the refusal.
        $this->feedFacade->method('getFeedById')->willReturn(['NfID' => 1]);
        $this->feedFacade->method('getFeedOption')->willReturn('');
        $this->feedFacade->method('parseRssFeed')->willReturn(false);

        $result = $this->handler->loadFeed('My Special Feed', 1, 'http://example.com', '');

        $this->assertStringContainsString('My Special Feed', $result['error']);
    }

    public function testLoadFeedPassesArticleSourceToParser(): void
    {
        // loadFeed() refuses a feed the caller does not own; this test is
        // about parsing, so give it one it owns. FeedLoadOwnershipTest covers
        // the refusal.
        $this->feedFacade->method('getFeedById')->willReturn(['NfID' => 1]);
        $this->feedFacade->method('getFeedOption')
            ->willReturnCallback(function (string $opts, string $option) {
                if ($option === 'article_source') {
                    return 'full_text';
                }
                return '';
            });
        $this->feedFacade->expects($this->once())
            ->method('parseRssFeed')
            ->with('http://example.com/rss', 'full_text')
            ->willReturn(false);

        $this->handler->loadFeed('Feed', 1, 'http://example.com/rss', 'article_source:full_text');
    }

    // =========================================================================
    // Class structure tests
    // =========================================================================

    #[Test]
    public function handlerImplementsApiRoutableInterface(): void
    {
        $this->assertInstanceOf(ApiRoutableInterface::class, $this->handler);
    }

    #[Test]
    public function classUsesApiRoutableTrait(): void
    {
        $reflection = new \ReflectionClass(FeedApiHandler::class);
        $traitNames = array_map(
            fn(\ReflectionClass $t) => $t->getName(),
            $reflection->getTraits()
        );

        $this->assertContains(
            'Lwt\Shared\Http\ApiRoutableTrait',
            $traitNames
        );
    }

    #[Test]
    public function classHasAllRouteMethods(): void
    {
        $reflection = new \ReflectionClass(FeedApiHandler::class);

        foreach (['routeGet', 'routePost', 'routePut', 'routeDelete'] as $method) {
            $this->assertTrue($reflection->hasMethod($method));
            $m = $reflection->getMethod($method);
            $this->assertTrue($m->isPublic());
        }
    }

    // =========================================================================
    // routeGet tests
    // =========================================================================

    #[Test]
    public function routeGetListDelegatestoGetFeedList(): void
    {
        // getFeedList calls Connection:: which needs DB
        $result = $this->handler->routeGet(['feeds', 'list'], []);

        $this->assertInstanceOf(JsonResponse::class, $result);
    }

    #[Test]
    public function routeGetArticlesDelegatesToGetArticles(): void
    {
        $result = $this->handler->routeGet(['feeds', 'articles'], []);

        $this->assertInstanceOf(JsonResponse::class, $result);
    }

    #[Test]
    public function routeGetWithNumericIdDelegatesToGetFeed(): void
    {
        $this->feedFacade->method('getFeedById')->willReturn(null);

        $result = $this->handler->routeGet(['feeds', '999'], []);

        $this->assertInstanceOf(JsonResponse::class, $result);
    }

    #[Test]
    public function routeGetWithInvalidFragmentReturnsError(): void
    {
        $result = $this->handler->routeGet(['feeds', 'invalid'], []);

        $this->assertInstanceOf(JsonResponse::class, $result);
    }

    #[Test]
    public function routeGetWithEmptyFragmentReturnsError(): void
    {
        $result = $this->handler->routeGet(['feeds', ''], []);

        $this->assertInstanceOf(JsonResponse::class, $result);
    }

    // =========================================================================
    // routePost tests
    // =========================================================================

    #[Test]
    public function routePostArticlesImportDelegatesToImportArticles(): void
    {
        $result = $this->handler->routePost(['feeds', 'articles', 'import'], []);

        $this->assertInstanceOf(JsonResponse::class, $result);
    }

    #[Test]
    public function routePostEmptyFragmentDelegatesToCreateFeed(): void
    {
        $result = $this->handler->routePost(['feeds', ''], []);

        $this->assertInstanceOf(JsonResponse::class, $result);
    }

    #[Test]
    public function routePostWithIdAndLoadDelegatesToLoadFeed(): void
    {
        $this->feedFacade->method('getFeedOption')->willReturn('');
        $this->feedFacade->method('parseRssFeed')->willReturn(false);

        $result = $this->handler->routePost(
            ['feeds', '42', 'load'],
            ['name' => 'Feed', 'source_uri' => 'http://test.com', 'options' => '']
        );

        $this->assertInstanceOf(JsonResponse::class, $result);
    }

    #[Test]
    public function routePostWizardPreviewDelegatesToTheWizardHandler(): void
    {
        $this->feedFacade->expects($this->once())
            ->method('detectAndParseFeed')
            ->willReturn(false);

        $result = $this->handler->routePost(
            ['feeds', 'wizard', 'preview'],
            ['rss_url' => 'https://news.example/rss']
        );

        $this->assertInstanceOf(JsonResponse::class, $result);
    }

    #[Test]
    public function routePostWizardArticleDelegatesToTheWizardHandler(): void
    {
        $this->feedFacade->expects($this->once())
            ->method('detectAndParseFeed')
            ->willReturn(false);

        $result = $this->handler->routePost(
            ['feeds', 'wizard', 'article'],
            ['rss_url' => 'https://news.example/rss', 'index' => 0]
        );

        $this->assertInstanceOf(JsonResponse::class, $result);
    }

    #[Test]
    public function routePostWithInvalidFragmentReturnsError(): void
    {
        $result = $this->handler->routePost(['feeds', 'invalid', 'bad'], []);

        $this->assertInstanceOf(JsonResponse::class, $result);
    }

    // =========================================================================
    // routePut tests
    // =========================================================================

    #[Test]
    public function routePutWithValidIdDelegatesToUpdateFeed(): void
    {
        $this->feedFacade->method('getFeedById')->willReturn(null);

        $result = $this->handler->routePut(['feeds', '1'], []);

        $this->assertInstanceOf(JsonResponse::class, $result);
    }

    #[Test]
    public function routePutWithEmptyIdReturnsError(): void
    {
        $result = $this->handler->routePut(['feeds', ''], []);

        $this->assertInstanceOf(JsonResponse::class, $result);
    }

    #[Test]
    public function routePutWithNonNumericIdReturnsError(): void
    {
        $result = $this->handler->routePut(['feeds', 'abc'], []);

        $this->assertInstanceOf(JsonResponse::class, $result);
    }

    // =========================================================================
    // routeDelete tests
    // =========================================================================

    #[Test]
    public function routeDeleteArticlesWithFeedIdDelegatesToDeleteArticles(): void
    {
        $this->feedFacade->method('deleteArticles')->willReturn(0);

        $result = $this->handler->routeDelete(['feeds', 'articles', '5'], []);

        $this->assertInstanceOf(JsonResponse::class, $result);
    }

    #[Test]
    public function routeDeleteResetErrorsDelegatesToResetErrorArticles(): void
    {
        $this->feedFacade->method('resetUnloadableArticles')->willReturn(0);

        $result = $this->handler->routeDelete(['feeds', '5', 'reset-errors'], []);

        $this->assertInstanceOf(JsonResponse::class, $result);
    }

    #[Test]
    public function routeDeleteEmptyFragmentDelegatesToDeleteFeeds(): void
    {
        $result = $this->handler->routeDelete(['feeds', ''], []);

        $this->assertInstanceOf(JsonResponse::class, $result);
    }

    #[Test]
    public function routeDeleteWithNumericIdDelegatesToDeleteSingleFeed(): void
    {
        $this->feedFacade->method('deleteFeeds')->willReturn(['feeds' => 1]);

        $result = $this->handler->routeDelete(['feeds', '42'], []);

        $this->assertInstanceOf(JsonResponse::class, $result);
    }

    #[Test]
    public function routeDeleteWithInvalidFragmentReturnsError(): void
    {
        $result = $this->handler->routeDelete(['feeds', 'invalid'], []);

        $this->assertInstanceOf(JsonResponse::class, $result);
    }

    #[Test]
    public function routeDeleteArticlesPassesArticleIdsFromParams(): void
    {
        $this->feedFacade->method('deleteArticles')->willReturn(0);

        $result = $this->handler->routeDelete(
            ['feeds', 'articles', '5'],
            ['article_ids' => [1, 2, 3]]
        );

        $this->assertInstanceOf(JsonResponse::class, $result);
    }

    #[Test]
    public function routeDeleteArticlesHandlesNullArticleIds(): void
    {
        $this->feedFacade->method('deleteArticles')->willReturn(0);

        $result = $this->handler->routeDelete(
            ['feeds', 'articles', '5'],
            []
        );

        $this->assertInstanceOf(JsonResponse::class, $result);
    }

    #[Test]
    public function routeDeleteEmptyFragmentHandlesFeedIdsParam(): void
    {
        $result = $this->handler->routeDelete(
            ['feeds', ''],
            ['feed_ids' => [1, 2]]
        );

        $this->assertInstanceOf(JsonResponse::class, $result);
    }

    // =========================================================================
    // formatArticleRecord tests (via reflection)
    // =========================================================================

    #[Test]
    public function formatArticleRecordSetsNewStatusForFreshArticle(): void
    {
        $row = [
            'FlID' => 1,
            'FlTitle' => 'Test Article',
            'FlLink' => 'http://example.com/article',
            'FlDescription' => 'Description',
            'FlDate' => '2025-01-01',
            'FlAudio' => '',
            'FlText' => '',
            'TxID' => null,
            'TxArchivedAt' => null,
        ];

        $method = new \ReflectionMethod(FeedApiHandler::class, 'formatArticleRecord');

        $result = $method->invoke($this->handler, $row);

        $this->assertSame('new', $result['status']);
        $this->assertSame(1, $result['id']);
        $this->assertSame('Test Article', $result['title']);
        $this->assertNull($result['textId']);
        $this->assertNull($result['archivedTextId']);
    }

    #[Test]
    public function formatArticleRecordSetsImportedStatusForActiveText(): void
    {
        $row = [
            'FlID' => 2,
            'FlTitle' => 'Imported Article',
            'FlLink' => 'http://example.com/article',
            'FlDescription' => '',
            'FlDate' => '2025-01-01',
            'FlAudio' => '',
            'FlText' => 'some text',
            'TxID' => 42,
            'TxArchivedAt' => null,
        ];

        $method = new \ReflectionMethod(FeedApiHandler::class, 'formatArticleRecord');

        $result = $method->invoke($this->handler, $row);

        $this->assertSame('imported', $result['status']);
        $this->assertSame(42, $result['textId']);
        $this->assertNull($result['archivedTextId']);
        $this->assertTrue($result['hasText']);
    }

    #[Test]
    public function formatArticleRecordSetsArchivedStatusForArchivedText(): void
    {
        $row = [
            'FlID' => 3,
            'FlTitle' => 'Archived',
            'FlLink' => 'http://example.com/article',
            'FlDescription' => '',
            'FlDate' => '2025-01-01',
            'FlAudio' => '',
            'FlText' => '',
            'TxID' => 50,
            'TxArchivedAt' => '2025-02-01',
        ];

        $method = new \ReflectionMethod(FeedApiHandler::class, 'formatArticleRecord');

        $result = $method->invoke($this->handler, $row);

        $this->assertSame('archived', $result['status']);
        $this->assertNull($result['textId']);
        $this->assertSame(50, $result['archivedTextId']);
    }

    #[Test]
    public function formatArticleRecordSetsErrorStatusForLeadingSpaceLink(): void
    {
        $row = [
            'FlID' => 4,
            'FlTitle' => 'Error Article',
            'FlLink' => ' http://example.com/error',
            'FlDescription' => '',
            'FlDate' => '2025-01-01',
            'FlAudio' => '',
            'FlText' => '',
            'TxID' => null,
            'TxArchivedAt' => null,
        ];

        $method = new \ReflectionMethod(FeedApiHandler::class, 'formatArticleRecord');

        $result = $method->invoke($this->handler, $row);

        $this->assertSame('error', $result['status']);
        $this->assertSame('http://example.com/error', $result['link']);
    }

    #[Test]
    public function formatArticleRecordHandlesEmptyTxId(): void
    {
        $row = [
            'FlID' => 5,
            'FlTitle' => 'Test',
            'FlLink' => 'http://example.com',
            'FlDescription' => '',
            'FlDate' => '',
            'FlAudio' => 'audio.mp3',
            'FlText' => '',
            'TxID' => '',
            'TxArchivedAt' => null,
        ];

        $method = new \ReflectionMethod(FeedApiHandler::class, 'formatArticleRecord');

        $result = $method->invoke($this->handler, $row);

        $this->assertSame('new', $result['status']);
        $this->assertNull($result['textId']);
    }

    #[Test]
    public function formatArticleRecordDetectsHasText(): void
    {
        $row = [
            'FlID' => 6,
            'FlTitle' => 'Test',
            'FlLink' => 'http://example.com',
            'FlDescription' => 'Desc',
            'FlDate' => '2025-01-15',
            'FlAudio' => '',
            'FlText' => 'Full article text here',
            'TxID' => null,
            'TxArchivedAt' => null,
        ];

        $method = new \ReflectionMethod(FeedApiHandler::class, 'formatArticleRecord');

        $result = $method->invoke($this->handler, $row);

        $this->assertTrue($result['hasText']);
    }

    #[Test]
    public function formatArticleRecordDetectsNoText(): void
    {
        $row = [
            'FlID' => 7,
            'FlTitle' => 'Test',
            'FlLink' => 'http://example.com',
            'FlDescription' => 'Desc',
            'FlDate' => '2025-01-15',
            'FlAudio' => '',
            'FlText' => '',
            'TxID' => null,
            'TxArchivedAt' => null,
        ];

        $method = new \ReflectionMethod(FeedApiHandler::class, 'formatArticleRecord');

        $result = $method->invoke($this->handler, $row);

        $this->assertFalse($result['hasText']);
    }

    // =========================================================================
    // createFeed validation edge cases
    // =========================================================================

    #[Test]
    public function createFeedReturnsErrorWhenNegativeLanguageId(): void
    {
        $result = $this->handler->createFeed([
            'langId' => -1,
            'name' => 'Feed',
            'sourceUri' => 'http://example.com'
        ]);

        $this->assertFalse($result['success']);
        $this->assertSame('Language is required', $result['error']);
    }

    #[Test]
    public function createFeedTrimsNameAndUri(): void
    {
        $this->feedFacade->expects($this->once())
            ->method('createFeed')
            ->with($this->callback(function (array $data) {
                return $data['NfName'] === 'Trimmed Feed'
                    && $data['NfSourceURI'] === 'http://trimmed.com';
            }))
            ->willReturn(1);

        $this->feedFacade->method('getFeedById')->willReturn([
            'NfID' => 1, 'NfName' => 'Trimmed Feed',
            'NfSourceURI' => 'http://trimmed.com', 'NfLgID' => 1,
            'NfArticleSectionTags' => '', 'NfFilterTags' => '',
            'NfOptions' => '', 'NfUpdate' => 0,
        ]);
        $this->feedFacade->method('getFeedOption')->willReturn([]);
        $this->feedFacade->method('formatLastUpdate')->willReturn('never');

        $this->handler->createFeed([
            'langId' => 1,
            'name' => '  Trimmed Feed  ',
            'sourceUri' => '  http://trimmed.com  '
        ]);
    }

    // =========================================================================
    // updateFeed merges existing data tests
    // =========================================================================

    #[Test]
    public function updateFeedPreservesExistingFieldsWhenNotProvided(): void
    {
        $existing = [
            'NfID' => 1,
            'NfName' => 'Original',
            'NfSourceURI' => 'http://original.com',
            'NfLgID' => 3,
            'NfArticleSectionTags' => 'article',
            'NfFilterTags' => 'script',
            'NfOptions' => 'tag:old',
            'NfUpdate' => time(),
        ];

        $this->feedFacade->method('getFeedById')->willReturn($existing);
        $this->feedFacade->expects($this->once())
            ->method('updateFeed')
            ->with(1, $this->callback(function (array $data) {
                return $data['NfLgID'] === 3
                    && $data['NfSourceURI'] === 'http://original.com'
                    && $data['NfArticleSectionTags'] === 'article'
                    && $data['NfFilterTags'] === 'script'
                    && $data['NfOptions'] === 'tag:old'
                    && $data['NfName'] === 'Updated Name';
            }));
        $this->feedFacade->method('getFeedOption')->willReturn([]);
        $this->feedFacade->method('formatLastUpdate')->willReturn('1h ago');

        $this->handler->updateFeed(1, ['name' => 'Updated Name']);
    }

    // =========================================================================
    // deleteFeeds with single ID
    // =========================================================================

    #[Test]
    public function deleteFeedsWithSingleId(): void
    {
        $this->feedFacade->expects($this->once())
            ->method('deleteFeeds')
            ->with('42')
            ->willReturn(['feeds' => 1]);

        $result = $this->handler->deleteFeeds([42]);

        $this->assertTrue($result['success']);
        $this->assertSame(1, $result['deleted']);
    }

    // =========================================================================
    // resetErrorArticles additional tests
    // =========================================================================

    #[Test]
    public function resetErrorArticlesReturnsZeroResetForUnknownFeed(): void
    {
        $this->feedFacade->method('resetUnloadableArticles')->willReturn(0);

        $result = $this->handler->resetErrorArticles(999);

        $this->assertTrue($result['success']);
        $this->assertSame(0, $result['reset']);
    }

    // =========================================================================
    // getFeed returning formatted record tests
    // =========================================================================

    #[Test]
    public function getFeedReturnsFormattedRecordWhenFound(): void
    {
        $feed = [
            'NfID' => 1,
            'NfName' => 'Test Feed',
            'NfSourceURI' => 'http://test.com',
            'NfLgID' => 2,
            'NfArticleSectionTags' => 'article',
            'NfFilterTags' => 'script',
            'NfOptions' => '',
            'NfUpdate' => 0,
        ];

        $this->feedFacade->method('getFeedById')->willReturn($feed);
        $this->feedFacade->method('getFeedOption')->willReturn([]);
        $this->feedFacade->method('formatLastUpdate')->willReturn('never');

        $result = $this->handler->getFeed(1);

        $this->assertArrayHasKey('id', $result);
        $this->assertArrayHasKey('name', $result);
        $this->assertArrayHasKey('sourceUri', $result);
        $this->assertArrayHasKey('langId', $result);
        $this->assertArrayHasKey('lastUpdate', $result);
        $this->assertSame(1, $result['id']);
        $this->assertSame('Test Feed', $result['name']);
    }

    // =========================================================================
    // getArticles with negative feed_id
    // =========================================================================

    #[Test]
    public function getArticlesReturnsErrorForNegativeFeedId(): void
    {
        $result = $this->handler->getArticles(['feed_id' => -1]);

        $this->assertArrayHasKey('error', $result);
        $this->assertSame('Feed ID is required', $result['error']);
    }

    // =========================================================================
    // Method return types
    // =========================================================================

    #[Test]
    public function routeMethodsReturnJsonResponse(): void
    {
        foreach (['routeGet', 'routePost', 'routePut', 'routeDelete'] as $method) {
            $rm = new \ReflectionMethod(FeedApiHandler::class, $method);
            $returnType = $rm->getReturnType();
            $this->assertNotNull($returnType);
            $this->assertSame(JsonResponse::class, $returnType->getName());
        }
    }
}
