<?php

/**
 * Feed Wizard API Handler
 *
 * The two server-side capabilities the feed wizard needs: reading a feed's
 * article list, and fetching one of those articles so the user can point at
 * the part of it worth keeping.
 *
 * Both used to happen inside a page render, with the parsed feed and the
 * fetched article HTML held in `$_SESSION` between the wizard's four page
 * loads. They are plain reads, so they answer with data here and the wizard
 * keeps its state in the browser (#262, #266).
 *
 * PHP version 8.1
 *
 * @category Lwt
 * @package  Lwt\Modules\Feed\Http
 * @author   HugoFara <git@hugofara.net>
 * @license  Unlicense <http://unlicense.org/>
 * @link     https://hugofara.github.io/lwt/developer/api
 * @since    3.5.1
 */

declare(strict_types=1);

namespace Lwt\Modules\Feed\Http;

use Lwt\Modules\Feed\Application\FeedFacade;

/**
 * Read-only endpoints backing the feed wizard.
 *
 * @since 3.5.1
 */
class FeedWizardApiHandler
{
    /**
     * Elements dropped from every previewed article.
     *
     * The wizard shows the article so the user can click its elements; script,
     * style and frame content is neither clickable nor wanted in the saved
     * text, and the same list was hard-coded in the wizard's two preview
     * builders before this.
     */
    private const PREVIEW_STRIP_TAGS = 'iframe!?!script!?!noscript!?!head!?!meta!?!link!?!style';

    /**
     * Feed element names that can carry the article body inline.
     */
    private const INLINE_SOURCES = ['description', 'encoded', 'content'];

    /**
     * Feed facade.
     */
    private FeedFacade $feedFacade;

    /**
     * Constructor.
     *
     * @param FeedFacade $feedFacade Feed facade
     */
    public function __construct(FeedFacade $feedFacade)
    {
        $this->feedFacade = $feedFacade;
    }

    /**
     * Read a feed and describe its articles.
     *
     * @param array<string, mixed> $data Request payload carrying `rss_url`
     *
     * @return array{success: bool, error?: string, title?: string,
     *               articleSource?: string, articleSources?: list<string>,
     *               items?: list<array{index: int, title: string, link: string, host: string}>}
     */
    public function previewFeed(array $data): array
    {
        $rssUrl = trim((string)($data['rss_url'] ?? ''));
        if ($rssUrl === '') {
            return ['success' => false, 'error' => 'Feed URL is required'];
        }

        $feed = $this->feedFacade->detectAndParseFeed($rssUrl);
        if (!is_array($feed) || $this->itemCount($feed) === 0) {
            return ['success' => false, 'error' => 'Could not read any articles from that feed URL'];
        }

        return [
            'success' => true,
            'title' => $this->stringField($feed, 'feed_title'),
            'articleSource' => $this->stringField($feed, 'feed_text'),
            'articleSources' => $this->availableSources($feed),
            'items' => $this->describeItems($feed),
        ];
    }

    /**
     * Fetch one article of a feed, as the HTML the wizard lets the user pick in.
     *
     * The article is addressed by its position in the feed rather than by URL:
     * the server then only ever fetches links the feed the user named actually
     * advertises, which is the boundary the session-backed wizard had.
     *
     * @param array<string, mixed> $data Request payload carrying `rss_url` and `index`
     *
     * @return array{success: bool, error?: string, html?: string}
     */
    public function previewArticle(array $data): array
    {
        $rssUrl = trim((string)($data['rss_url'] ?? ''));
        if ($rssUrl === '') {
            return ['success' => false, 'error' => 'Feed URL is required'];
        }

        $index = (int)($data['index'] ?? 0);
        if ($index < 0) {
            return ['success' => false, 'error' => 'Article index out of range'];
        }

        $feed = $this->feedFacade->detectAndParseFeed($rssUrl);
        if (!is_array($feed)) {
            return ['success' => false, 'error' => 'Could not read any articles from that feed URL'];
        }

        $item = $feed[$index] ?? null;
        if (!is_array($item)) {
            return ['success' => false, 'error' => 'Article index out of range'];
        }

        $articleSource = trim((string)($data['article_source'] ?? ''));
        $charset = trim((string)($data['charset'] ?? ''));
        $redirect = trim((string)($data['redirect'] ?? ''));

        $extraction = $this->feedFacade->extractTextFromArticle(
            [$this->extractionItem($item, $articleSource)],
            $redirect === '' ? 'new' : $redirect . ' | new',
            self::PREVIEW_STRIP_TAGS,
            $charset === '' ? null : $charset
        );

        /** @var mixed $errorEntry */
        $errorEntry = $extraction['error'] ?? null;
        $error = is_array($errorEntry) ? $this->stringField($errorEntry, 'message') : '';
        if ($error !== '') {
            return ['success' => false, 'error' => $error];
        }

        /** @var mixed $first */
        $first = $extraction[0] ?? null;

        return [
            'success' => true,
            'html' => is_array($first) ? $this->stringField($first, 'TxText') : '',
        ];
    }

    /**
     * Read one entry of an untyped array as a string.
     *
     * Both the feed parser and the article extractor answer with loosely
     * shaped arrays, so every field is read through here rather than each
     * caller re-stating the same is_string guard.
     *
     * @param array<array-key, mixed> $source The array to read
     * @param array-key               $key    The entry to read
     *
     * @return string The entry, or '' when it is absent or not a string
     */
    private function stringField(array $source, string|int $key): string
    {
        /** @var mixed $value */
        $value = $source[$key] ?? null;

        return is_string($value) ? $value : '';
    }

    /**
     * Count the numerically keyed entries of a parsed feed.
     *
     * `detectAndParseFeed()` returns the articles under integer keys and mixes
     * in `feed_title` / `feed_text` alongside them, so the item count is not
     * the array's length.
     *
     * @param array<int|string, mixed> $feed Parsed feed
     *
     * @return int Number of articles
     */
    private function itemCount(array $feed): int
    {
        return count(array_filter(array_keys($feed), 'is_int'));
    }

    /**
     * Describe every article of a parsed feed for the wizard's picker.
     *
     * @param array<int|string, mixed> $feed Parsed feed
     *
     * @return list<array{index: int, title: string, link: string, host: string}>
     */
    private function describeItems(array $feed): array
    {
        $items = [];
        $count = $this->itemCount($feed);

        for ($i = 0; $i < $count; $i++) {
            $item = $feed[$i] ?? null;
            if (!is_array($item)) {
                continue;
            }
            $link = $this->stringField($item, 'link');
            $host = parse_url($link, PHP_URL_HOST);

            $items[] = [
                'index' => $i,
                'title' => $this->stringField($item, 'title'),
                'link' => $link,
                'host' => is_string($host) ? $host : '',
            ];
        }

        return $items;
    }

    /**
     * List the inline article sources this feed offers.
     *
     * @param array<int|string, mixed> $feed Parsed feed
     *
     * @return list<string> Source element names
     */
    private function availableSources(array $feed): array
    {
        $first = $feed[0] ?? null;
        if (!is_array($first)) {
            return [];
        }

        $sources = [];
        foreach (self::INLINE_SOURCES as $source) {
            if (isset($first[$source])) {
                $sources[] = $source;
            }
        }

        return $sources;
    }

    /**
     * Shape one feed entry the way the article extractor expects it.
     *
     * Choosing an inline source means reading the article out of the feed
     * entry rather than fetching its link; leaving it empty means the opposite,
     * so `text` has to go rather than keep whatever the feed detection put
     * there.
     *
     * @param array<string, mixed> $item          Feed entry
     * @param string               $articleSource Inline source, or '' for the linked page
     *
     * @return array{link: string, title: string, audio?: string, text?: string}
     */
    private function extractionItem(array $item, string $articleSource): array
    {
        $extraction = [
            'link' => $this->stringField($item, 'link'),
            'title' => $this->stringField($item, 'title'),
        ];

        if (isset($item['audio'])) {
            $extraction['audio'] = $this->stringField($item, 'audio');
        }

        if ($articleSource !== '' && isset($item[$articleSource])) {
            $extraction['text'] = $this->stringField($item, $articleSource);
        }

        return $extraction;
    }
}
