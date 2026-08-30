<?php

/**
 * Web Page Content Extractor Service
 *
 * Fetches a URL, extracts the main text content (title + body),
 * and returns it in a structured format for text import.
 *
 * PHP version 8.2
 *
 * @category Lwt
 * @package  Lwt\Shared\Infrastructure\Http
 * @author   HugoFara <git@hugofara.net>
 * @license  Unlicense <http://unlicense.org/>
 * @link     https://hugofara.github.io/lwt/developer/api
 * @since    3.0.0
 */

declare(strict_types=1);

namespace Lwt\Shared\Infrastructure\Http;

/**
 * Extracts readable text content from web pages.
 *
 * Performs URL validation (SSRF protection), fetches HTML,
 * detects charset, and extracts the main article text.
 *
 * @since 3.0.0
 */
class WebPageExtractor
{
    /**
     * Maximum response size in bytes (2 MB).
     */
    private const MAX_RESPONSE_SIZE = 2 * 1024 * 1024;

    /**
     * HTTP fetch timeout in seconds.
     */
    private const FETCH_TIMEOUT = 15;

    /**
     * Tags to strip before extracting text.
     *
     * @var list<string>
     */
    private const STRIP_TAGS = [
        'script', 'style', 'nav', 'header', 'footer', 'aside',
        'form', 'noscript', 'iframe', 'svg', 'figure', 'figcaption',
    ];

    /**
     * XPath selectors for noisy elements to remove (class/id-based).
     *
     * @var list<string>
     */
    private const NOISE_XPATHS = [
        // Common reference/citation sections
        '//*[contains(@class,"reflist") or contains(@class,"references") or contains(@class,"refbegin")]',
        '//*[contains(@class,"navbox") or contains(@class,"sidebar") or contains(@class,"infobox")]',
        '//*[contains(@class,"catlinks") or contains(@class,"mw-jump-link")]',
        '//*[@id="toc" or @class="toc"]',
        '//nav[contains(@class,"toc")]',
        '//*[contains(@class,"noprint")]',
        // Common ad/social/cookie elements
        '//*[contains(@class,"share") or contains(@class,"social")]',
        '//*[contains(@class,"cookie") or contains(@class,"banner")]',
        '//*[contains(@class,"related-") or contains(@class,"recommended")]',
        '//*[contains(@class,"comment") and not(contains(@class,"content"))]',
        // Wikipedia-specific
        '//*[contains(@class,"mw-editsection")]',
        '//*[contains(@class,"sistersitebox")]',
        '//*[contains(@class,"authority-control")]',
    ];

    /**
     * Tags that indicate main content areas (in priority order).
     *
     * @var list<string>
     */
    private const CONTENT_TAGS = ['article', 'main', '[role="main"]', '[id="mw-content-text"]'];

    /**
     * Extract title and text content from a URL.
     *
     * @param string $url       The URL to fetch and extract from
     * @param string $titleHint Optional pre-filled title (used as fallback for plain text files)
     *
     * @return array{title: string, text: string, sourceUri: string}|array{error: string}
     */
    public function extractFromUrl(string $url, string $titleHint = ''): array
    {
        $url = trim($url);

        // Pre-flight URL validation for a clearer error message — the
        // actual SSRF fence lives inside fetchPage()'s safeHttpGet,
        // which re-validates the entry URL *and* every redirect hop.
        $validation = UrlUtilities::validateUrlForFetch($url);
        if (!$validation['valid']) {
            return ['error' => $validation['error'] ?? 'Invalid URL'];
        }

        // Fetch the page
        $html = $this->fetchPage($url);
        if ($html === null) {
            return ['error' => 'Could not fetch the page. The site may be unreachable or blocking requests.'];
        }

        // Check for binary content (PDFs, images, etc.)
        if ($this->looksLikeBinary($html)) {
            return [
                'error' => 'URL points to a binary file (PDF, image, etc.). '
                    . 'Only HTML and plain text are supported.',
            ];
        }

        // Plain text files (no HTML tags) — return directly
        if ($this->isPlainText($html)) {
            $html = $this->stripGutenbergBoilerplate($html);
            $text = $this->unwrapHardLineBreaks($html);
            $text = $this->cleanText($text);
            $title = $titleHint !== '' ? $titleHint : $this->titleFromUrl($url);
            return [
                'title' => $title,
                'text' => $text,
                'sourceUri' => $url,
            ];
        }

        // Detect and convert charset
        $html = $this->normalizeCharset($html);

        // Parse HTML
        $dom = $this->parseHtml($html);
        if ($dom === null) {
            return ['error' => 'Could not parse the page HTML.'];
        }

        // Extract title
        $title = $this->extractTitle($dom);

        // Extract body text
        $text = $this->extractBodyText($dom);

        if ($text === '') {
            return ['error' => 'Could not extract any text content from the page.'];
        }

        return [
            'title' => $title,
            'text' => $text,
            'sourceUri' => $url,
        ];
    }

    /**
     * Fetch page content from URL.
     *
     * Routes through `UrlUtilities::safeHttpGet` so the entry URL and
     * every redirect hop are run through `validateUrlForFetch`. With
     * the older `follow_location => true` setup, an attacker-owned
     * public host could 302 the fetch into a private range; that
     * vector is closed here.
     *
     * @param string $url URL to fetch
     *
     * @return string|null HTML content or null on failure
     */
    protected function fetchPage(string $url): ?string
    {
        return UrlUtilities::safeHttpGet($url, [
            'timeout' => self::FETCH_TIMEOUT,
            'maxBytes' => self::MAX_RESPONSE_SIZE,
            'maxRedirects' => 5,
            'userAgent' => 'Mozilla/5.0 (X11; Linux x86_64; rv:128.0) Gecko/20100101 Firefox/128.0',
            'accept' => 'text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8',
        ]);
    }

    /**
     * Check if content looks like binary (not HTML/text).
     *
     * @param string $content Content to check
     *
     * @return bool True if content appears to be binary
     */
    protected function looksLikeBinary(string $content): bool
    {
        // Check first 512 bytes for null bytes (binary indicator)
        $sample = substr($content, 0, 512);
        return str_contains($sample, "\0");
    }

    /**
     * Check if content is plain text (no HTML tags).
     *
     * @param string $content Content to check
     *
     * @return bool True if content appears to be plain text
     */
    protected function isPlainText(string $content): bool
    {
        // If there are no HTML-like tags, treat as plain text
        return !preg_match('/<[a-z!\/][^>]*>/i', $content);
    }

    /**
     * Derive a title from a URL's path.
     *
     * @param string $url The source URL
     *
     * @return string A human-readable title
     */
    protected function titleFromUrl(string $url): string
    {
        $path = parse_url($url, PHP_URL_PATH);
        if ($path === null || $path === false) {
            return '';
        }
        $filename = basename($path);
        // Remove extension
        $name = pathinfo($filename, PATHINFO_FILENAME);
        // Replace common separators with spaces
        return str_replace(['-', '_', '.'], ' ', $name);
    }

    /**
     * Detect charset and convert to UTF-8.
     *
     * @param string $html HTML content
     *
     * @return string UTF-8 encoded HTML
     */
    protected function normalizeCharset(string $html): string
    {
        $charset = $this->detectCharset($html);

        if ($charset !== null && strcasecmp($charset, 'UTF-8') !== 0) {
            $converted = @mb_convert_encoding($html, 'UTF-8', $charset);
            if ($converted !== false) {
                return $converted;
            }
        }

        return $html;
    }

    /**
     * Detect charset from HTML meta tags.
     *
     * @param string $html HTML content
     *
     * @return string|null Detected charset or null
     */
    protected function detectCharset(string $html): ?string
    {
        // Check <meta charset="...">
        if (preg_match('/<meta\s+charset=["\']?([^"\'\s>]+)/i', $html, $m)) {
            return $m[1];
        }

        // Check <meta http-equiv="Content-Type" content="...; charset=...">
        if (preg_match('/charset=([^"\'\s;>]+)/i', $html, $m)) {
            return $m[1];
        }

        return null;
    }

    /**
     * Parse HTML string into DOMDocument.
     *
     * @param string $html HTML content
     *
     * @return \DOMDocument|null Parsed document or null on failure
     */
    private function parseHtml(string $html): ?\DOMDocument
    {
        $dom = new \DOMDocument();
        $previousValue = libxml_use_internal_errors(true);

        // Force UTF-8 encoding
        $success = $dom->loadHTML(
            '<?xml encoding="UTF-8">' . $html,
            LIBXML_NONET | LIBXML_NOERROR
        );

        // Remove the XML processing instruction
        foreach ($dom->childNodes as $item) {
            if ($item->nodeType === XML_PI_NODE) {
                $dom->removeChild($item);
            }
        }
        $dom->encoding = 'UTF-8';

        libxml_clear_errors();
        libxml_use_internal_errors($previousValue);

        return $success ? $dom : null;
    }

    /**
     * Extract title from HTML document.
     *
     * Tries og:title first, then <title> tag.
     *
     * @param \DOMDocument $dom Parsed HTML document
     *
     * @return string Extracted title
     */
    protected function extractTitle(\DOMDocument $dom): string
    {
        $xpath = new \DOMXPath($dom);

        // Try og:title first (usually cleaner)
        $ogTitle = $xpath->query('//meta[@property="og:title"]/@content');
        if ($ogTitle !== false && $ogTitle->length > 0) {
            $value = $ogTitle->item(0)?->nodeValue;
            if ($value !== null && trim($value) !== '') {
                return trim($value);
            }
        }

        // Fall back to <title> tag
        $titleNodes = $dom->getElementsByTagName('title');
        if ($titleNodes->length > 0) {
            $value = $titleNodes->item(0)?->nodeValue;
            if ($value !== null && trim($value) !== '') {
                return trim($value);
            }
        }

        return '';
    }

    /**
     * Extract the main body text from the HTML document.
     *
     * Strategy: strip noise elements, then try to find the main
     * content area (<article>, <main>, etc.). If none found,
     * fall back to the largest text-bearing <div>.
     *
     * @param \DOMDocument $dom Parsed HTML document
     *
     * @return string Extracted text content
     */
    protected function extractBodyText(\DOMDocument $dom): string
    {
        // Remove noise elements by tag name
        $this->stripElements($dom, self::STRIP_TAGS);

        $xpath = new \DOMXPath($dom);

        // Remove noise elements by class/id patterns
        $this->stripByXPath($xpath, self::NOISE_XPATHS);

        // Try content tags in priority order
        foreach (self::CONTENT_TAGS as $selector) {
            $nodes = $this->queryNodes($xpath, $selector);
            if ($nodes !== null && $nodes->length > 0) {
                $text = $this->getTextFromNodeList($nodes);
                if (mb_strlen($text) >= 50) {
                    return $text;
                }
            }
        }

        // Fallback: find the div/section with the most text
        $body = $dom->getElementsByTagName('body');
        if ($body->length > 0) {
            $bodyNode = $body->item(0);
            if ($bodyNode === null) {
                return '';
            }
            $bestNode = $this->findLargestTextBlock($bodyNode);
            if ($bestNode !== null) {
                $text = $this->cleanText($bestNode->textContent);
                if ($text !== '') {
                    return $text;
                }
            }

            // Last resort: entire body text
            $bodyText = $this->cleanText($bodyNode->textContent);
            if ($bodyText !== '') {
                return $bodyText;
            }
        }

        return '';
    }

    /**
     * Query nodes using either tag name or CSS-like selector.
     *
     * @param \DOMXPath $xpath    XPath instance
     * @param string    $selector Tag name or attribute selector
     *
     * @return \DOMNodeList|null
     */
    private function queryNodes(\DOMXPath $xpath, string $selector): ?\DOMNodeList
    {
        if (str_starts_with($selector, '[')) {
            // Convert CSS attribute selector [attr="val"] to XPath [@attr="val"]
            $xpathExpr = preg_replace(
                '/\[(\w+)="([^"]+)"\]/',
                '[@$1="$2"]',
                '//*' . $selector
            );
            if ($xpathExpr === null) {
                return null;
            }
            $result = @$xpath->query($xpathExpr);
            return $result !== false ? $result : null;
        }

        $result = @$xpath->query('//' . $selector);
        return $result !== false ? $result : null;
    }

    /**
     * Remove specified element types from the DOM.
     *
     * @param \DOMDocument $dom  Document to modify
     * @param list<string> $tags Tag names to remove
     */
    private function stripElements(\DOMDocument $dom, array $tags): void
    {
        foreach ($tags as $tag) {
            $nodes = $dom->getElementsByTagName($tag);
            $toRemove = [];
            foreach ($nodes as $node) {
                $toRemove[] = $node;
            }
            foreach ($toRemove as $node) {
                $node->parentNode?->removeChild($node);
            }
        }
    }

    /**
     * Remove elements matching XPath selectors.
     *
     * @param \DOMXPath     $xpath     XPath instance
     * @param list<string>  $selectors XPath selectors
     */
    private function stripByXPath(\DOMXPath $xpath, array $selectors): void
    {
        foreach ($selectors as $selector) {
            $nodes = @$xpath->query($selector);
            if ($nodes === false) {
                continue;
            }
            $toRemove = [];
            foreach ($nodes as $node) {
                $toRemove[] = $node;
            }
            foreach ($toRemove as $node) {
                if ($node instanceof \DOMNode && $node->parentNode !== null) {
                    $node->parentNode->removeChild($node);
                }
            }
        }
    }

    /**
     * Get cleaned text from a node list.
     *
     * @param \DOMNodeList $nodes Node list
     *
     * @return string Combined text
     */
    private function getTextFromNodeList(\DOMNodeList $nodes): string
    {
        $parts = [];
        foreach ($nodes as $node) {
            if (!$node instanceof \DOMNode) {
                continue;
            }
            $text = $this->cleanText($node->textContent);
            if ($text !== '') {
                $parts[] = $text;
            }
        }
        return implode("\n\n", $parts);
    }

    /**
     * Find the child element with the most text content.
     *
     * @param \DOMNode $parent Parent node to search within
     *
     * @return \DOMNode|null The node with the most text, or null
     */
    private function findLargestTextBlock(\DOMNode $parent): ?\DOMNode
    {
        $best = null;
        $bestLen = 0;

        foreach ($parent->childNodes as $child) {
            if (!$child instanceof \DOMElement) {
                continue;
            }

            $tagName = strtolower($child->nodeName);
            if (in_array($tagName, ['div', 'section', 'td', 'article', 'main'], true)) {
                $len = mb_strlen(trim($child->textContent));
                if ($len > $bestLen) {
                    $bestLen = $len;
                    $best = $child;
                }
            }
        }

        return $best;
    }

    /**
     * Strip Gutenberg boilerplate and clean up text (public API).
     *
     * @param string $text Raw Gutenberg text
     *
     * @return string Cleaned text
     */
    public function stripGutenbergBoilerplatePublic(string $text): string
    {
        $text = $this->stripGutenbergBoilerplate($text);
        $text = $this->unwrapHardLineBreaks($text);
        return $this->cleanText($text);
    }

    /**
     * Strip Project Gutenberg header and footer boilerplate.
     *
     * Gutenberg plain text files have a preamble ending with
     * "*** START OF THE PROJECT GUTENBERG EBOOK ..." and a footer
     * starting with "*** END OF THE PROJECT GUTENBERG EBOOK ...".
     *
     * @param string $text Raw Gutenberg text
     *
     * @return string Text with boilerplate removed (unchanged if no markers found)
     */
    protected function stripGutenbergBoilerplate(string $text): string
    {
        // Strip header: everything up to and including the START marker line
        // Use non-greedy .*? so we match the FIRST occurrence, not the last
        $text = (string) preg_replace(
            '/\A.*?\*{3}\s*START OF (?:THE |THIS )?PROJECT GUTENBERG[^\n]*\n/si',
            '',
            $text
        );

        // Strip footer: everything from the END marker line onward
        $text = (string) preg_replace(
            '/\n\*{3}\s*END OF (?:THE |THIS )?PROJECT GUTENBERG.*\z/si',
            '',
            $text
        );

        // Strip common post-header boilerplate (donation/license notices before actual text)
        // These lines appear after the START marker but before the real content
        $text = (string) preg_replace(
            '/\A\s*(?:This eBook is (?:for the use of|donated to)[^\n]*\n\s*)+/i',
            '',
            $text
        );

        return $text;
    }

    /**
     * Unwrap hard line breaks typical of plain text files (e.g. ~72-char wraps).
     *
     * Joins consecutive non-blank lines into paragraphs. Blank lines are
     * treated as paragraph separators.
     *
     * @param string $text Text with hard line breaks
     *
     * @return string Text with natural paragraphs
     */
    protected function unwrapHardLineBreaks(string $text): string
    {
        $lines = explode("\n", $text);
        $paragraphs = [];
        $buffer = '';

        foreach ($lines as $line) {
            $trimmed = trim($line);

            if ($trimmed === '') {
                // Blank line = paragraph break
                if ($buffer !== '') {
                    $paragraphs[] = $buffer;
                    $buffer = '';
                }
                $paragraphs[] = '';
                continue;
            }

            if ($buffer === '') {
                $buffer = $trimmed;
            } else {
                $buffer .= ' ' . $trimmed;
            }
        }

        if ($buffer !== '') {
            $paragraphs[] = $buffer;
        }

        return implode("\n", $paragraphs);
    }

    /**
     * Clean extracted text: normalize whitespace and line breaks.
     *
     * @param string $text Raw text content
     *
     * @return string Cleaned text
     */
    protected function cleanText(string $text): string
    {
        // Replace tabs and carriage returns with spaces
        $text = str_replace(["\r", "\t"], ["\n", ' '], $text);

        // Collapse multiple spaces
        $text = (string) preg_replace('/  +/', ' ', $text);

        // Collapse 3+ newlines into 2
        $text = (string) preg_replace('/\n{3,}/', "\n\n", $text);

        // Trim each line
        $lines = explode("\n", $text);
        $lines = array_map('trim', $lines);
        $text = implode("\n", $lines);

        // Remove leading/trailing whitespace
        return trim($text);
    }
}
