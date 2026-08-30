<?php

/**
 * Global Digital Library API Client
 *
 * Searches the Global Digital Library content API (content.digitallibrary.io)
 * for openly-licensed early-grade readers and resolves their ePUB download
 * URLs. GDL aggregates content from StoryWeaver, African Storybook and others
 * under CC-BY / CC-BY-SA.
 *
 * Unlike Project Gutenberg (plain text), GDL books are ePUB; turning an ePUB
 * into reading text needs the Book module's parser, so that step lives in a
 * module-layer service rather than here — this client stays dependency-free
 * and deals only in catalog metadata and the raw ePUB URL.
 *
 * PHP version 8.2
 *
 * @category Lwt
 * @package  Lwt\Shared\Infrastructure\Http
 * @author   HugoFara <git@hugofara.net>
 * @license  Unlicense <http://unlicense.org/>
 * @link     https://hugofara.github.io/lwt/developer/api
 * @since    3.1.0
 */

declare(strict_types=1);

namespace Lwt\Shared\Infrastructure\Http;

/**
 * Client for the Global Digital Library content API.
 *
 * @since 3.1.0
 */
class GdlClient
{
    /**
     * GDL content API base URL (WordPress REST API).
     *
     * The legacy standalone OPDS feed (opds.digitallibrary.io) and book-api
     * (api.digitallibrary.io) were decommissioned; both hosts no longer
     * resolve. This WordPress JSON API is the current surface.
     */
    private const API_BASE = 'https://content.digitallibrary.io/wp-json/content-api/v1/';

    /**
     * Results per page. Server-fixed at 20; the only paging lever is `_skip`
     * (an offset), so a page maps to an offset of (page - 1) * PAGE_SIZE.
     */
    private const PAGE_SIZE = 20;

    /**
     * HTTP fetch timeout in seconds.
     */
    private const TIMEOUT = 30;

    /**
     * Browse books for a language (no search query).
     *
     * @param string $languageCode GDL language slug (e.g. "en", "swa")
     * @param int    $page         Page number (1-based)
     *
     * @return array{results: list<array>, count: int, next: bool}|array{error: string}
     */
    public function browse(string $languageCode, int $page = 1): array
    {
        return $this->search('', $languageCode, $page);
    }

    /**
     * Search the Global Digital Library catalog.
     *
     * @param string      $query        Search query (title/keyword)
     * @param string|null $languageCode GDL language slug (e.g. "en", "swa")
     * @param int         $page         Page number (1-based)
     *
     * @return array{results: list<array>, count: int, next: bool}|array{error: string}
     */
    public function search(string $query, ?string $languageCode = null, int $page = 1): array
    {
        $params = [];

        if ($query !== '') {
            $params['query'] = $query;
        }

        if ($languageCode !== null && $languageCode !== '') {
            $params['language'] = strtolower($languageCode);
        }

        if ($page > 1) {
            $params['_skip'] = ($page - 1) * self::PAGE_SIZE;
        }

        $url = self::API_BASE . 'contentsearch?' . http_build_query($params);
        $response = $this->fetchJson($url);

        if ($response === null) {
            return ['error' => 'Could not reach the Global Digital Library. Please try again later.'];
        }

        return $this->parseSearchResponse($response, $page);
    }

    /**
     * Map a raw contentsearch response into normalised result rows.
     *
     * Kept separate from the HTTP fetch so the mapping is unit-testable
     * without the network (mirrors GutenbergClient's extractTextUrl split).
     *
     * @param array $response Decoded contentsearch JSON
     * @param int   $page     Page number the response is for
     *
     * @return array{results: list<array>, count: int, next: bool}
     */
    protected function parseSearchResponse(array $response, int $page): array
    {
        $results = [];
        /** @var list<array<string, mixed>> $books */
        $books = $response['books'] ?? [];
        foreach ($books as $book) {
            $epubUrl = $this->extractEpubUrl($book);
            if ($epubUrl === null) {
                continue; // Skip entries without a downloadable ePUB.
            }

            $level = $this->extractLevel($book);
            $results[] = [
                'id' => (int) ($book['postId'] ?? 0),
                'title' => $this->decodeText((string) ($book['title'] ?? '')),
                'publisher' => $this->decodeText((string) ($book['publisher'] ?? '')),
                'description' => trim($this->decodeText((string) ($book['description'] ?? ''))),
                'language' => $this->firstTermSlug($book['language'] ?? []),
                'license' => $this->firstTermName($book['license'] ?? []),
                'level' => $level,
                'difficultyTier' => self::levelToTier($level),
                'thumbnail' => $this->thumbnailUrl($book),
                'sourceUri' => (string) ($book['postLink'] ?? ''),
                'epubUrl' => $epubUrl,
            ];
        }

        // meta.count is the total across all pages; absent it, fall back to
        // this page's size so `next` errs toward "no more pages".
        $count = (int) ($response['meta']['count'] ?? count($results));

        return [
            'results' => $results,
            'count' => $count,
            'next' => ($page * self::PAGE_SIZE) < $count,
        ];
    }

    /**
     * Map a GDL reading level to a coarse difficulty tier.
     *
     * GDL levels run 1–5 with word-count bands (Level 4 = "more than 1500
     * words"). Books outside the levelled "Library Books" collection have no
     * level and fall through to "medium".
     *
     * @param string $level Level label (e.g. "Level 3"), or '' when unknown
     *
     * @return string One of "easy", "medium", "hard"
     */
    public static function levelToTier(string $level): string
    {
        if (preg_match('/(\d+)/', $level, $m) !== 1) {
            return 'medium';
        }

        $n = (int) $m[1];
        if ($n <= 2) {
            return 'easy';
        }
        if ($n >= 4) {
            return 'hard';
        }

        return 'medium';
    }

    /**
     * Decode HTML entities in GDL text fields.
     *
     * GDL's WordPress API HTML-encodes apostrophes and spaces in titles and
     * descriptions (e.g. "d&#039;Ali", "&nbsp;"). The frontend renders these
     * via Alpine `x-text`, which sets textContent and does not decode
     * entities — so they must be decoded here, at the data boundary.
     *
     * @param string $text Raw text from the GDL API
     *
     * @return string Text with HTML entities decoded
     */
    private function decodeText(string $text): string
    {
        if ($text === '') {
            return '';
        }

        return html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    }

    /**
     * Extract the ePUB download URL from a GDL book record.
     *
     * @param array $book GDL book record
     *
     * @return string|null ePUB URL, or null when the book has no ePUB
     */
    protected function extractEpubUrl(array $book): ?string
    {
        $url = trim((string) ($book['epubUrl'] ?? ''));
        return $url !== '' ? $url : null;
    }

    /**
     * Extract the reading-level label from a book's topic terms.
     *
     * GDL exposes the level as a `topic` term named "Level N" (not via the
     * `level` taxonomy, which is unpopulated in the current API).
     *
     * @param array $book GDL book record
     *
     * @return string Level label (e.g. "Level 2"), or '' when absent
     */
    protected function extractLevel(array $book): string
    {
        /** @var list<array{name?: string}> $topics */
        $topics = $book['topic'] ?? [];
        foreach ($topics as $topic) {
            $name = $topic['name'] ?? '';
            if (preg_match('/^Level\s*\d+/i', $name) === 1) {
                return $name;
            }
        }

        return '';
    }

    /**
     * Resolve the cover thumbnail, which is `false` when a book has none.
     *
     * @param array $book GDL book record
     *
     * @return string Thumbnail URL, or '' when absent
     */
    private function thumbnailUrl(array $book): string
    {
        /** @var mixed $thumb */
        $thumb = $book['thumbnail'] ?? false;
        return is_string($thumb) ? $thumb : '';
    }

    /**
     * Read the slug of the first term in a GDL taxonomy array.
     *
     * @param mixed $terms Taxonomy term list from a book record
     *
     * @return string First term slug, or '' when empty
     */
    private function firstTermSlug(mixed $terms): string
    {
        if (is_array($terms) && isset($terms[0]['slug'])) {
            return (string) $terms[0]['slug'];
        }

        return '';
    }

    /**
     * Read the name of the first term in a GDL taxonomy array.
     *
     * @param mixed $terms Taxonomy term list from a book record
     *
     * @return string First term name, or '' when empty
     */
    private function firstTermName(mixed $terms): string
    {
        if (is_array($terms) && isset($terms[0]['name'])) {
            return (string) $terms[0]['name'];
        }

        return '';
    }

    /**
     * Download the raw bytes of a GDL ePUB.
     *
     * Returns the binary so the (Book-module) ePUB parser can run on it
     * elsewhere — keeping this client free of module dependencies. The same
     * per-hop SSRF revalidation as fetchJson applies: a redirect could
     * otherwise rotate the URL into a private address.
     *
     * @param string $url ePUB URL (epub-generator endpoint)
     *
     * @return string|null Raw ePUB bytes, or null on failure
     */
    public function fetchEpub(string $url): ?string
    {
        $bytes = UrlUtilities::safeHttpGet($url, [
            'timeout' => self::TIMEOUT,
            'maxBytes' => 20 * 1024 * 1024, // ePUBs are far larger than .txt.
            'maxRedirects' => 5,
            'userAgent' => 'LWT/3.0 (Language Learning Tool)',
            'accept' => 'application/epub+zip',
        ]);

        return ($bytes !== null && $bytes !== '') ? $bytes : null;
    }

    /**
     * Fetch and decode JSON from a URL.
     *
     * @param string $url URL to fetch
     *
     * @return array|null Decoded JSON or null on failure
     */
    protected function fetchJson(string $url): ?array
    {
        $response = UrlUtilities::safeHttpGet($url, [
            'timeout' => self::TIMEOUT,
            'maxBytes' => 2 * 1024 * 1024,
            'maxRedirects' => 5,
            'userAgent' => 'LWT/3.0 (Language Learning Tool)',
            'accept' => 'application/json',
        ]);

        if ($response === null || $response === '') {
            return null;
        }

        /** @var array|null $data */
        $data = json_decode($response, true);
        return is_array($data) ? $data : null;
    }
}
