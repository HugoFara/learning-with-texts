<?php

/**
 * RSS Feed Parser Service
 *
 * PHP version 8.2
 *
 * @category Lwt
 * @package  Lwt\Modules\Feed\Application\Services
 * @author   HugoFara <git@hugofara.net>
 * @license  Unlicense <http://unlicense.org/>
 * @link     https://hugofara.github.io/lwt/developer/api
 * @since    3.0.0
 */

declare(strict_types=1);

namespace Lwt\Modules\Feed\Application\Services;

use Lwt\Shared\Infrastructure\Http\UrlUtilities;

/**
 * Service for parsing RSS and Atom feeds.
 *
 * Provides pure parsing functionality without database access.
 * Supports both RSS 2.0 and Atom feed formats.
 *
 * @since 3.0.0
 */
class RssParser
{
    /**
     * Cap individual feed downloads at 8 MB. The largest legitimate
     * podcast feeds we've seen are ~3 MB; anything beyond that is
     * either misconfigured or an attempt to OOM the parser.
     */
    private const MAX_FEED_BYTES = 8 * 1024 * 1024;

    /**
     * Fetch the feed body with SSRF guards applied.
     *
     * `DOMDocument::load($url)` would route the network fetch through
     * PHP's stream wrappers — `LIBXML_NONET` only blocks libxml's
     * *external entity* resolution, not the initial document load.
     * So we have to do the fetch ourselves via `safeHttpGet` to get
     * URL validation and per-hop redirect re-validation, then feed
     * the bytes to `loadXML` instead.
     *
     * @return string|null Raw feed XML, or null if the URL is invalid,
     *                     unreachable, or redirects to a private range.
     */
    private function fetchFeedBody(string $sourceUri): ?string
    {
        return UrlUtilities::safeHttpGet($sourceUri, [
            'timeout' => 30,
            'maxBytes' => self::MAX_FEED_BYTES,
            'accept' => 'application/rss+xml, application/atom+xml, application/xml, text/xml, */*',
        ]);
    }

    /**
     * Parse RSS/Atom feed and return article items with metadata.
     *
     * Supports both RSS 2.0 and Atom feed formats. Extracts:
     * - Title, description, link, publication date
     * - Audio enclosures (podcast support)
     * - Inline text content (if article section specified)
     *
     * @param string $sourceUri      Feed URL
     * @param string $articleSection Tag name for inline text extraction
     *
     * @return array<int, array{
     *     title: string, link: string, desc: string,
     *     date: string, audio: string, text: string
     * }>|null Array of feed items or null on error
     */
    public function parse(string $sourceUri, string $articleSection = ''): ?array
    {
        $body = $this->fetchFeedBody($sourceUri);
        if ($body === null) {
            return null;
        }

        return $this->parseXml($body, $articleSection);
    }

    /**
     * Parse RSS/Atom feed XML already fetched into a string.
     *
     * The URI variant `parse()` adds an SSRF-guarded HTTP fetch on
     * top of this; tests and any other in-memory caller should land
     * here directly to skip the network entirely.
     *
     * @param string $xml            Raw feed XML
     * @param string $articleSection Tag name for inline text extraction
     *
     * @return array<int, array{
     *     title: string, link: string, desc: string,
     *     date: string, audio: string, text: string
     * }>|null Array of feed items or null on parse error
     */
    public function parseXml(string $xml, string $articleSection = ''): ?array
    {
        $rss = new \DOMDocument('1.0', 'utf-8');
        // LIBXML_NONET blocks libxml from fetching external entities
        // (the XXE/SSRF vector); LIBXML_NOCDATA keeps CDATA sections
        // inlined as text. We deliberately do NOT set LIBXML_NOENT —
        // that flag *enables* entity expansion, which would re-open
        // the billion-laughs DoS.
        if (!@$rss->loadXML($xml, LIBXML_NOCDATA | LIBXML_NONET)) {
            return null;
        }

        $rssData = [];
        $feedTags = $this->getFeedTagMapping($rss);

        if ($feedTags === null) {
            return null;
        }

        foreach ($rss->getElementsByTagName($feedTags['item']) as $node) {
            $item = $this->parseItem($node, $feedTags, count($rssData), $articleSection);

            if ($item !== null) {
                $rssData[] = $item;
            }
        }

        return $rssData;
    }

    /**
     * Detect and parse feed, determining best text source.
     *
     * Analyzes feed to determine whether to use:
     * - content (Atom)
     * - description (RSS)
     * - encoded (RSS with content:encoded)
     * - webpage link (external fetch)
     *
     * @param string $sourceUri Feed URL
     *
     * @return array<int|string, array<string, string>|string>|null Feed data with feed_text indicator or null on error
     */
    public function detectAndParse(string $sourceUri): ?array
    {
        $body = $this->fetchFeedBody($sourceUri);
        if ($body === null) {
            return null;
        }

        return $this->detectAndParseXml($body);
    }

    /**
     * Detect and parse feed from XML already in memory.
     *
     * Same role as `parseXml` for the detection variant — splits the
     * SSRF-guarded fetch from the parsing logic so tests don't have
     * to round-trip through HTTP.
     *
     * @param string $xml Raw feed XML
     *
     * @return array<int|string, array<string, string>|string>|null
     *     Feed data with feed_text indicator or null on parse error
     */
    public function detectAndParseXml(string $xml): ?array
    {
        $rss = new \DOMDocument('1.0', 'utf-8');
        // LIBXML_NONET blocks libxml from fetching external entities
        // (the XXE/SSRF vector); LIBXML_NOCDATA keeps CDATA sections
        // inlined as text. We deliberately do NOT set LIBXML_NOENT —
        // that flag *enables* entity expansion, which would re-open
        // the billion-laughs DoS.
        if (!@$rss->loadXML($xml, LIBXML_NOCDATA | LIBXML_NONET)) {
            return null;
        }

        $rssData = [];
        $descCount = 0;
        $descNocount = 0;
        $encCount = 0;
        $encNocount = 0;

        $feedTags = $this->getFeedTagMapping($rss);
        if ($feedTags === null) {
            return null;
        }

        foreach ($rss->getElementsByTagName($feedTags['item']) as $node) {
            $item = $this->parseItemForDetection($node, $feedTags);

            // Count text lengths for source detection
            if ($feedTags['item'] === 'item') {
                $counts = $this->countTextLengths($item, 'desc', 'encoded');
                $descCount += $counts['desc']['long'];
                $descNocount += $counts['desc']['short'];
                $encCount += $counts['encoded']['long'];
                $encNocount += $counts['encoded']['short'];
            } elseif ($feedTags['item'] === 'entry') {
                if (isset($item['content'])) {
                    if (mb_strlen($item['content'], 'UTF-8') > 900) {
                        $descCount++;
                    } else {
                        $descNocount++;
                    }
                }
            }

            if ($item['title'] !== '' && $item['link'] !== '') {
                $rssData[] = $item;
            }
        }

        // Determine best text source
        $rssData = $this->determineBestTextSource(
            $rssData,
            $feedTags,
            $descCount,
            $descNocount,
            $encCount,
            $encNocount
        );

        $rssData['feed_title'] = $rss->getElementsByTagName('title')->item(0)->nodeValue ?? '';

        return $rssData;
    }

    /**
     * Get the feed title from a feed URL.
     *
     * @param string $sourceUri Feed URL
     *
     * @return string|null Feed title or null on error
     */
    public function getFeedTitle(string $sourceUri): ?string
    {
        $body = $this->fetchFeedBody($sourceUri);
        if ($body === null) {
            return null;
        }

        return $this->getFeedTitleFromXml($body);
    }

    /**
     * Extract the feed title from XML already in memory.
     *
     * @param string $xml Raw feed XML
     *
     * @return string|null Feed title or null on parse error
     */
    public function getFeedTitleFromXml(string $xml): ?string
    {
        $rss = new \DOMDocument('1.0', 'utf-8');
        // LIBXML_NONET blocks libxml from fetching external entities
        // (the XXE/SSRF vector); LIBXML_NOCDATA keeps CDATA sections
        // inlined as text. We deliberately do NOT set LIBXML_NOENT —
        // that flag *enables* entity expansion, which would re-open
        // the billion-laughs DoS.
        if (!@$rss->loadXML($xml, LIBXML_NOCDATA | LIBXML_NONET)) {
            return null;
        }

        $titleNode = $rss->getElementsByTagName('title')->item(0);
        return $titleNode ? $titleNode->nodeValue : null;
    }

    /**
     * Parse a single feed item.
     *
     * @param \DOMElement $node           Item node
     * @param array{
     *     item: string, title: string, description: string, link: string,
     *     pubDate: string, enclosure: string, url: string
     * } $feedTags Tag mapping
     * @param int    $index          Item index (for date fallback)
     * @param string $articleSection Tag for inline text extraction
     *
     * @return array{
     *     title: string, link: string, desc: string,
     *     date: string, audio: string, text: string
     * }|null Parsed item or null if invalid
     */
    private function parseItem(
        \DOMElement $node,
        array $feedTags,
        int $index,
        string $articleSection
    ): ?array {
        $titleNode = $node->getElementsByTagName($feedTags['title'])->item(0);
        $descNode = $node->getElementsByTagName($feedTags['description'])->item(0);
        $linkNode = $node->getElementsByTagName($feedTags['link'])->item(0);
        $dateNode = $node->getElementsByTagName($feedTags['pubDate'])->item(0);

        $item = [
            'title' => $this->cleanTitle($titleNode?->nodeValue ?? ''),
            'desc' => $this->cleanDescription($descNode?->nodeValue ?? ''),
            'link' => $this->extractLink($linkNode, $feedTags),
            'date' => $this->parseFeedDate($dateNode?->nodeValue, $index),
            'audio' => '',
            'text' => '',
        ];

        // Truncate description
        if (strlen($item['desc']) > 1000) {
            $item['desc'] = mb_substr($item['desc'], 0, 995, 'utf-8') . '...';
        }

        // Extract inline text if article section specified
        if ($articleSection !== '') {
            $item['text'] = $this->extractInlineText($node, $articleSection) ?? '';
        }

        // Extract audio enclosure
        $item['audio'] = $this->extractAudioEnclosure($node, $feedTags);

        // Validate item
        $hasValidContent = $item['title'] !== '' &&
            ($item['link'] !== '' || ($articleSection !== '' && isset($item['text']) && $item['text'] !== ''));

        return $hasValidContent ? $item : null;
    }

    /**
     * Parse item for detection mode (includes raw text content).
     *
     * @param \DOMElement $node     Item node
     * @param array{
     *     item: string, title: string, description: string, link: string,
     *     pubDate: string, enclosure: string, url: string
     * } $feedTags Tag mapping
     *
     * @return array{
     *     title: string, desc: string, link: string,
     *     encoded?: string, description?: string, content?: string
     * } Parsed item
     */
    private function parseItemForDetection(\DOMElement $node, array $feedTags): array
    {
        $titleNode = $node->getElementsByTagName($feedTags['title'])->item(0);
        $descNode = $node->getElementsByTagName($feedTags['description'])->item(0);
        $linkNode = $node->getElementsByTagName($feedTags['link'])->item(0);

        $item = [
            'title' => $this->cleanTitleForDetection($titleNode?->nodeValue ?? ''),
            'desc' => $this->cleanDescriptionForDetection($descNode?->nodeValue ?? ''),
            'link' => $this->extractLink($linkNode, $feedTags),
        ];

        // Handle RSS items
        if ($feedTags['item'] === 'item') {
            foreach ($node->getElementsByTagName('encoded') as $txtNode) {
                if ($txtNode->parentNode === $node) {
                    $item['encoded'] = $this->convertToHtmlEntities(
                        $txtNode->ownerDocument->saveHTML($txtNode)
                    );
                }
            }
            foreach ($node->getElementsByTagName('description') as $txtNode) {
                if ($txtNode->parentNode === $node) {
                    $item['description'] = $this->convertToHtmlEntities(
                        $txtNode->ownerDocument->saveHTML($txtNode)
                    );
                }
            }
        }

        // Handle Atom entries
        if ($feedTags['item'] === 'entry') {
            foreach ($node->getElementsByTagName('content') as $txtNode) {
                if ($txtNode->parentNode === $node) {
                    $item['content'] = $this->convertToHtmlEntities(
                        $txtNode->ownerDocument->saveHTML($txtNode)
                    );
                }
            }
        }

        return $item;
    }

    /**
     * Get tag mapping for RSS/Atom feed format.
     *
     * @param \DOMDocument $rss Feed document
     *
     * @return array{
     *     item: string, title: string, description: string, link: string,
     *     pubDate: string, enclosure: string, url: string
     * }|null Tag mapping or null if unknown format
     */
    private function getFeedTagMapping(\DOMDocument $rss): ?array
    {
        if ($rss->getElementsByTagName('rss')->length !== 0) {
            return [
                'item' => 'item',
                'title' => 'title',
                'description' => 'description',
                'link' => 'link',
                'pubDate' => 'pubDate',
                'enclosure' => 'enclosure',
                'url' => 'url',
            ];
        }

        if ($rss->getElementsByTagName('feed')->length !== 0) {
            return [
                'item' => 'entry',
                'title' => 'title',
                'description' => 'summary',
                'link' => 'link',
                'pubDate' => 'published',
                'enclosure' => 'link',
                'url' => 'href',
            ];
        }

        return null;
    }

    /**
     * Parse feed date string to MySQL datetime format.
     *
     * @param string|null $dateStr  Date string from feed
     * @param int         $fallback Fallback offset for ordering
     *
     * @return string MySQL datetime string
     */
    private function parseFeedDate(?string $dateStr, int $fallback): string
    {
        if ($dateStr === null || $dateStr === '') {
            return date('Y-m-d H:i:s', time() - $fallback);
        }

        // Try RFC 2822 format (RSS)
        $pubDate = date_parse_from_format('D, d M Y H:i:s T', $dateStr);
        if ($pubDate['error_count'] === 0 && $pubDate['warning_count'] === 0) {
            return $this->formatParsedDate($pubDate, $fallback);
        }

        // Try ISO 8601 format (Atom)
        $timestamp = strtotime($dateStr);
        if ($timestamp !== false) {
            return date('Y-m-d H:i:s', $timestamp);
        }

        return date('Y-m-d H:i:s', time() - $fallback);
    }

    /**
     * Format parsed date array to MySQL datetime.
     *
     * @param array $pubDate Parsed date array
     * @param int   $fallback Fallback offset
     *
     * @return string MySQL datetime string
     */
    private function formatParsedDate(array $pubDate, int $fallback): string
    {
        if (
            !isset(
                $pubDate['hour'],
                $pubDate['minute'],
                $pubDate['second'],
                $pubDate['month'],
                $pubDate['day'],
                $pubDate['year']
            )
        ) {
            return date('Y-m-d H:i:s', time() - $fallback);
        }

        $timestamp = mktime(
            (int)$pubDate['hour'],
            (int)$pubDate['minute'],
            (int)$pubDate['second'],
            (int)$pubDate['month'],
            (int)$pubDate['day'],
            (int)$pubDate['year']
        );

        return date('Y-m-d H:i:s', $timestamp);
    }

    /**
     * Clean and normalize title text.
     *
     * @param string $title Raw title
     *
     * @return string Cleaned title
     */
    private function cleanTitle(string $title): string
    {
        $trimmed = trim($title);
        return preg_replace(
            ['/\s\s+/', '/\ \&\ /'],
            [' ', ' &amp; '],
            $trimmed
        ) ?? $trimmed;
    }

    /**
     * Clean title for detection mode.
     *
     * @param string $title Raw title
     *
     * @return string Cleaned title
     */
    private function cleanTitleForDetection(string $title): string
    {
        $trimmed = trim($title);
        return preg_replace(
            ['/\s\s+/', '/\ \&\ /', '/\"/'],
            [' ', ' &amp; ', '\"'],
            $trimmed
        ) ?? $trimmed;
    }

    /**
     * Clean and normalize description text.
     *
     * @param string $desc Raw description
     *
     * @return string Cleaned description
     */
    private function cleanDescription(string $desc): string
    {
        $trimmed = trim($desc);
        return preg_replace(
            ['/\ \&\ /', '/<br(\s+)?\/?>/i', '/<br [^>]*?>/i', '/\<[^\>]*\>/', '/(\n)[\s^\n]*\n[\s]*/'],
            [' &amp; ', "\n", "\n", '', '$1$1'],
            $trimmed
        ) ?? $trimmed;
    }

    /**
     * Clean description for detection mode.
     *
     * @param string $desc Raw description
     *
     * @return string Cleaned description
     */
    private function cleanDescriptionForDetection(string $desc): string
    {
        $trimmed = trim($desc);
        return preg_replace(
            ['/\s\s+/', '/\ \&\ /', '/\<[^\>]*\>/', '/\"/'],
            [' ', ' &amp; ', '', '\"'],
            $trimmed
        ) ?? $trimmed;
    }

    /**
     * Extract link from node based on feed type.
     *
     * @param \DOMElement|null $linkNode Link node
     * @param array            $feedTags Tag mapping
     *
     * @return string Link URL
     */
    private function extractLink(?\DOMElement $linkNode, array $feedTags): string
    {
        if ($linkNode === null) {
            return '';
        }

        // Atom uses href attribute, RSS uses node value
        if ($feedTags['item'] === 'entry') {
            return trim($linkNode->getAttribute('href'));
        }

        return trim($linkNode->nodeValue ?? '');
    }

    /**
     * Extract inline text from item node.
     *
     * @param \DOMElement $node           Item node
     * @param string      $articleSection Tag name for text extraction
     *
     * @return string|null Extracted text or null
     */
    private function extractInlineText(\DOMElement $node, string $articleSection): ?string
    {
        foreach ($node->getElementsByTagName($articleSection) as $txtNode) {
            if ($txtNode->parentNode === $node) {
                $html = $txtNode->ownerDocument->saveHTML($txtNode);
                return $this->convertToHtmlEntities($html);
            }
        }
        return null;
    }

    /**
     * Extract audio enclosure URL.
     *
     * @param \DOMElement $node Item node
     * @param array{
     *     item: string, title: string, description: string, link: string,
     *     pubDate: string, enclosure: string, url: string
     * } $feedTags Tag mapping
     *
     * @return string Audio URL or empty string
     */
    private function extractAudioEnclosure(\DOMElement $node, array $feedTags): string
    {
        foreach ($node->getElementsByTagName($feedTags['enclosure']) as $enc) {
            $type = $enc->getAttribute('type');
            if ($type === 'audio/mpeg') {
                return $enc->getAttribute($feedTags['url']);
            }
        }
        return '';
    }

    /**
     * Convert HTML to HTML entities.
     *
     * @param string $html HTML content
     *
     * @return string Converted content
     */
    private function convertToHtmlEntities(string $html): string
    {
        $decoded = html_entity_decode($html, ENT_NOQUOTES, 'UTF-8');
        // Convert non-ASCII characters to numeric HTML entities
        // Convmap: [start, end, offset, mask] - converts chars 0x80-0x10FFFF
        return mb_encode_numericentity($decoded, [0x80, 0x10FFFF, 0, 0x1FFFFF], 'UTF-8');
    }

    /**
     * Count text lengths for source detection.
     *
     * @param array{
     *     title: string, desc: string, link: string,
     *     encoded?: string, description?: string, content?: string
     * } $item Item data
     * @param string $descKey  Description key
     * @param string $encKey   Encoded key
     *
     * @return array{desc: array{long: int, short: int}, encoded: array{long: int, short: int}} Counts array
     */
    private function countTextLengths(array $item, string $descKey, string $encKey): array
    {
        $counts = [
            'desc' => ['long' => 0, 'short' => 0],
            'encoded' => ['long' => 0, 'short' => 0],
        ];

        if (isset($item[$descKey])) {
            if (mb_strlen($item[$descKey], 'UTF-8') > 900) {
                $counts['desc']['long']++;
            } else {
                $counts['desc']['short']++;
            }
        }

        if (isset($item[$encKey])) {
            if (mb_strlen($item[$encKey], 'UTF-8') > 900) {
                $counts['encoded']['long']++;
            } else {
                $counts['encoded']['short']++;
            }
        }

        return $counts;
    }

    /**
     * Determine best text source and update items.
     *
     * @param array<int|string, array<string, string>|string> $rssData   Feed items
     * @param array{
     *     item: string, title: string, description: string, link: string,
     *     pubDate: string, enclosure: string, url: string
     * } $feedTags Tag mapping
     * @param int   $descCount   Long description count
     * @param int   $descNocount Short description count
     * @param int   $encCount    Long encoded count
     * @param int   $encNocount  Short encoded count
     *
     * @return array<int|string, array<string, string>|string> Updated feed data
     */
    private function determineBestTextSource(
        array $rssData,
        array $feedTags,
        int $descCount,
        int $descNocount,
        int $encCount,
        int $encNocount
    ): array {
        if ($descCount > $descNocount) {
            $source = ($feedTags['item'] === 'entry') ? 'content' : 'description';
            $rssData['feed_text'] = $source;
            foreach ($rssData as $i => $val) {
                if (is_int($i) && is_array($val)) {
                    /** @var array<string, string> $item */
                    $item = $val;
                    $item['text'] = $val[$source] ?? '';
                    $rssData[$i] = $item;
                }
            }
        } elseif ($encCount > $encNocount) {
            $rssData['feed_text'] = 'encoded';
            foreach ($rssData as $i => $val) {
                if (is_int($i) && is_array($val)) {
                    /** @var array<string, string> $item */
                    $item = $val;
                    $item['text'] = $val['encoded'] ?? '';
                    $rssData[$i] = $item;
                }
            }
        } else {
            $rssData['feed_text'] = '';
        }

        return $rssData;
    }
}
