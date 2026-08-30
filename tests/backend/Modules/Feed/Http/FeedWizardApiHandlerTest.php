<?php

declare(strict_types=1);

namespace Lwt\Tests\Modules\Feed\Http;

use Lwt\Modules\Feed\Application\FeedFacade;
use Lwt\Modules\Feed\Http\FeedWizardApiHandler;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for FeedWizardApiHandler.
 *
 * The wizard's two reads: describing a feed's articles, and fetching one of
 * them as pickable HTML.
 */
#[CoversClass(FeedWizardApiHandler::class)]
class FeedWizardApiHandlerTest extends TestCase
{
    /** @var FeedFacade&MockObject */
    private FeedFacade $feedFacade;

    private FeedWizardApiHandler $handler;

    protected function setUp(): void
    {
        $this->feedFacade = $this->createMock(FeedFacade::class);
        $this->handler = new FeedWizardApiHandler($this->feedFacade);
    }

    /**
     * A feed as detectAndParseFeed() answers with one: articles under integer
     * keys, with feed_title and feed_text mixed in alongside them.
     *
     * @return array<int|string, mixed>
     */
    private function parsedFeed(): array
    {
        return [
            0 => [
                'title' => 'First',
                'link' => 'https://news.example/1',
                'description' => '<p>Inline one</p>',
                'audio' => 'https://news.example/1.mp3',
                'text' => '<p>Inline one</p>',
            ],
            1 => [
                'title' => 'Second',
                'link' => 'https://other.example/2',
                'description' => '<p>Inline two</p>',
                'text' => '<p>Inline two</p>',
            ],
            'feed_title' => 'Example News',
            'feed_text' => 'description',
        ];
    }

    // =========================================================================
    // previewFeed
    // =========================================================================

    #[Test]
    public function previewFeedDescribesEveryArticle(): void
    {
        $this->feedFacade->method('detectAndParseFeed')->willReturn($this->parsedFeed());

        $result = $this->handler->previewFeed(['rss_url' => 'https://news.example/rss']);

        $this->assertTrue($result['success']);
        $this->assertSame('Example News', $result['title']);
        $this->assertSame('description', $result['articleSource']);
        $this->assertSame(
            [
                ['index' => 0, 'title' => 'First', 'link' => 'https://news.example/1',
                 'host' => 'news.example'],
                ['index' => 1, 'title' => 'Second', 'link' => 'https://other.example/2',
                 'host' => 'other.example'],
            ],
            $result['items']
        );
    }

    #[Test]
    public function previewFeedListsTheInlineSourcesTheFeedOffers(): void
    {
        $this->feedFacade->method('detectAndParseFeed')->willReturn($this->parsedFeed());

        $result = $this->handler->previewFeed(['rss_url' => 'https://news.example/rss']);

        $this->assertSame(['description'], $result['articleSources']);
    }

    #[Test]
    public function previewFeedPassesTheGivenUrlToTheParser(): void
    {
        $this->feedFacade->expects($this->once())
            ->method('detectAndParseFeed')
            ->with('https://news.example/rss')
            ->willReturn($this->parsedFeed());

        $this->handler->previewFeed(['rss_url' => '  https://news.example/rss  ']);
    }

    #[Test]
    public function previewFeedRejectsAMissingUrl(): void
    {
        $this->feedFacade->expects($this->never())->method('detectAndParseFeed');

        $result = $this->handler->previewFeed([]);

        $this->assertFalse($result['success']);
        $this->assertSame('Feed URL is required', $result['error']);
    }

    #[Test]
    public function previewFeedReportsAFeedThatWouldNotParse(): void
    {
        $this->feedFacade->method('detectAndParseFeed')->willReturn(false);

        $result = $this->handler->previewFeed(['rss_url' => 'https://news.example/rss']);

        $this->assertFalse($result['success']);
        $this->assertArrayHasKey('error', $result);
    }

    #[Test]
    public function previewFeedReportsAFeedWithNoArticles(): void
    {
        $this->feedFacade->method('detectAndParseFeed')
            ->willReturn(['feed_title' => 'Empty', 'feed_text' => '']);

        $result = $this->handler->previewFeed(['rss_url' => 'https://news.example/rss']);

        $this->assertFalse($result['success']);
    }

    // =========================================================================
    // previewArticle
    // =========================================================================

    #[Test]
    public function previewArticleAnswersWithTheExtractedHtml(): void
    {
        $this->feedFacade->method('detectAndParseFeed')->willReturn($this->parsedFeed());
        $this->feedFacade->method('extractTextFromArticle')
            ->willReturn([0 => ['TxText' => '<p>Bonjour</p>']]);

        $result = $this->handler->previewArticle([
            'rss_url' => 'https://news.example/rss',
            'index' => 1,
        ]);

        $this->assertTrue($result['success']);
        $this->assertSame('<p>Bonjour</p>', $result['html']);
    }

    #[Test]
    public function previewArticleFetchesTheArticleAtTheGivenIndex(): void
    {
        $this->feedFacade->method('detectAndParseFeed')->willReturn($this->parsedFeed());
        $this->feedFacade->expects($this->once())
            ->method('extractTextFromArticle')
            ->with(
                [['link' => 'https://other.example/2', 'title' => 'Second']],
                'new',
                $this->anything(),
                null
            )
            ->willReturn([0 => ['TxText' => '']]);

        $this->handler->previewArticle([
            'rss_url' => 'https://news.example/rss',
            'index' => 1,
        ]);
    }

    #[Test]
    public function previewArticleReadsTheBodyFromTheChosenInlineSource(): void
    {
        $this->feedFacade->method('detectAndParseFeed')->willReturn($this->parsedFeed());
        $this->feedFacade->expects($this->once())
            ->method('extractTextFromArticle')
            ->with(
                [[
                    'link' => 'https://news.example/1',
                    'title' => 'First',
                    'audio' => 'https://news.example/1.mp3',
                    'text' => '<p>Inline one</p>',
                ]],
                $this->anything(),
                $this->anything(),
                $this->anything()
            )
            ->willReturn([0 => ['TxText' => '']]);

        $this->handler->previewArticle([
            'rss_url' => 'https://news.example/rss',
            'index' => 0,
            'article_source' => 'description',
        ]);
    }

    #[Test]
    public function previewArticleDropsTheInlineBodyWhenTheLinkedPageIsWanted(): void
    {
        $this->feedFacade->method('detectAndParseFeed')->willReturn($this->parsedFeed());
        $this->feedFacade->expects($this->once())
            ->method('extractTextFromArticle')
            ->willReturnCallback(function (array $items): array {
                $this->assertArrayNotHasKey('text', $items[0]);
                return [0 => ['TxText' => '']];
            });

        $this->handler->previewArticle([
            'rss_url' => 'https://news.example/rss',
            'index' => 0,
            'article_source' => '',
        ]);
    }

    #[Test]
    public function previewArticlePutsTheRedirectHopBeforeTheArticleSection(): void
    {
        $this->feedFacade->method('detectAndParseFeed')->willReturn($this->parsedFeed());
        $this->feedFacade->expects($this->once())
            ->method('extractTextFromArticle')
            ->with($this->anything(), 'redirect://a/@href | new', $this->anything(), $this->anything())
            ->willReturn([0 => ['TxText' => '']]);

        $this->handler->previewArticle([
            'rss_url' => 'https://news.example/rss',
            'index' => 0,
            'redirect' => 'redirect://a/@href',
        ]);
    }

    #[Test]
    public function previewArticlePassesAnOverriddenCharsetAlong(): void
    {
        $this->feedFacade->method('detectAndParseFeed')->willReturn($this->parsedFeed());
        $this->feedFacade->expects($this->once())
            ->method('extractTextFromArticle')
            ->with($this->anything(), $this->anything(), $this->anything(), 'ISO-8859-1')
            ->willReturn([0 => ['TxText' => '']]);

        $this->handler->previewArticle([
            'rss_url' => 'https://news.example/rss',
            'index' => 0,
            'charset' => 'ISO-8859-1',
        ]);
    }

    #[Test]
    public function previewArticleReportsAnExtractionFailure(): void
    {
        $this->feedFacade->method('detectAndParseFeed')->willReturn($this->parsedFeed());
        $this->feedFacade->method('extractTextFromArticle')
            ->willReturn(['error' => ['message' => 'Could not fetch the article']]);

        $result = $this->handler->previewArticle([
            'rss_url' => 'https://news.example/rss',
            'index' => 0,
        ]);

        $this->assertFalse($result['success']);
        $this->assertSame('Could not fetch the article', $result['error']);
    }

    #[Test]
    public function previewArticleRejectsAnIndexTheFeedDoesNotHave(): void
    {
        $this->feedFacade->method('detectAndParseFeed')->willReturn($this->parsedFeed());
        $this->feedFacade->expects($this->never())->method('extractTextFromArticle');

        $result = $this->handler->previewArticle([
            'rss_url' => 'https://news.example/rss',
            'index' => 99,
        ]);

        $this->assertFalse($result['success']);
        $this->assertSame('Article index out of range', $result['error']);
    }

    #[Test]
    public function previewArticleRejectsANegativeIndex(): void
    {
        $this->feedFacade->expects($this->never())->method('detectAndParseFeed');

        $result = $this->handler->previewArticle([
            'rss_url' => 'https://news.example/rss',
            'index' => -1,
        ]);

        $this->assertFalse($result['success']);
    }

    #[Test]
    public function previewArticleRejectsAMissingUrl(): void
    {
        $this->feedFacade->expects($this->never())->method('detectAndParseFeed');

        $result = $this->handler->previewArticle(['index' => 0]);

        $this->assertFalse($result['success']);
        $this->assertSame('Feed URL is required', $result['error']);
    }
}
