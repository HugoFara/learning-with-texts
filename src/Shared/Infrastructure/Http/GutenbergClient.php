<?php

/**
 * Project Gutenberg API Client
 *
 * Searches the Gutendex catalog API (gutendex.com) for free e-books
 * and resolves plain-text download URLs.
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
 * Client for the Gutendex Project Gutenberg catalog API.
 *
 * @since 3.0.0
 */
class GutenbergClient
{
    /**
     * Gutendex API base URL.
     */
    // Trailing slash matters: hitting /books without it produces a 301 to
    // /books/ which doubles the round-trip and pushes the upstream past our
    // TIMEOUT on production. Request the canonical URL directly.
    private const API_BASE = 'https://gutendex.com/books/';

    /**
     * HTTP fetch timeout in seconds.
     */
    // Gutendex can take 30+ s to respond from some networks (notably small
    // VPSes). The result is cached per-language, so a slow first request is
    // acceptable; what's not acceptable is timing out before reaching the
    // cache-fill path and leaving the suggestions panel permanently empty.
    private const TIMEOUT = 60;

    /**
     * Common language name to ISO 639-1 code mapping.
     *
     * Used as fallback when LgSourceLang is not set.
     *
     * @var array<string, string>
     */
    private const LANGUAGE_NAME_MAP = [
        'english' => 'en',
        'french' => 'fr',
        'german' => 'de',
        'spanish' => 'es',
        'italian' => 'it',
        'portuguese' => 'pt',
        'dutch' => 'nl',
        'finnish' => 'fi',
        'swedish' => 'sv',
        'danish' => 'da',
        'norwegian' => 'no',
        'hungarian' => 'hu',
        'polish' => 'pl',
        'czech' => 'cs',
        'greek' => 'el',
        'russian' => 'ru',
        'chinese' => 'zh',
        'japanese' => 'ja',
        'korean' => 'ko',
        'arabic' => 'ar',
        'hebrew' => 'he',
        'turkish' => 'tr',
        'romanian' => 'ro',
        'catalan' => 'ca',
        'latin' => 'la',
        'esperanto' => 'eo',
        'tagalog' => 'tl',
    ];

    /**
     * Browse popular books for a language (no search query).
     *
     * @param string $languageCode ISO 639-1 language code (e.g. "en", "fr")
     * @param int    $page         Page number (1-based)
     *
     * @return array{results: list<array>, count: int, next: bool}|array{error: string}
     */
    public function browse(string $languageCode, int $page = 1): array
    {
        return $this->search('', $languageCode, $page);
    }

    /**
     * Search Project Gutenberg catalog.
     *
     * @param string      $query        Search query (title or author)
     * @param string|null $languageCode ISO 639-1 language code (e.g. "en", "fr")
     * @param int         $page         Page number (1-based)
     *
     * @return array{results: list<array>, count: int, next: bool}|array{error: string}
     */
    public function search(string $query, ?string $languageCode = null, int $page = 1): array
    {
        $params = [];

        if ($query !== '') {
            $params['search'] = $query;
        }

        if ($languageCode !== null && $languageCode !== '') {
            $params['languages'] = strtolower($languageCode);
        }

        if ($page > 1) {
            $params['page'] = $page;
        }

        $url = self::API_BASE . '?' . http_build_query($params);
        $response = $this->fetchJson($url);

        if ($response === null) {
            return ['error' => 'Could not reach the Gutenberg catalog. Please try again later.'];
        }

        $results = [];
        /** @var list<array<string, mixed>> $responseResults */
        $responseResults = $response['results'] ?? [];
        foreach ($responseResults as $book) {
            $textUrl = $this->extractTextUrl($book);
            if ($textUrl === null) {
                continue; // Skip books without plain text
            }

            $authors = [];
            /** @var list<array{name?: string}> $bookAuthors */
            $bookAuthors = $book['authors'] ?? [];
            foreach ($bookAuthors as $author) {
                $authors[] = $author['name'] ?? '';
            }

            /** @var list<string> $subjects */
            $subjects = $book['subjects'] ?? [];

            $results[] = [
                'id' => (int) ($book['id'] ?? 0),
                'title' => (string) ($book['title'] ?? ''),
                'authors' => $authors,
                'languages' => $book['languages'] ?? [],
                'subjects' => array_slice($subjects, 0, 3),
                'downloadCount' => (int) ($book['download_count'] ?? 0),
                'textUrl' => $textUrl,
            ];
        }

        return [
            'results' => $results,
            'count' => (int) ($response['count'] ?? 0),
            'next' => ($response['next'] ?? null) !== null,
        ];
    }

    /**
     * Guess ISO 639-1 language code from a language name.
     *
     * @param string $languageName Language name (e.g. "English", "French")
     *
     * @return string|null ISO code or null if unknown
     */
    public static function guessLanguageCode(string $languageName): ?string
    {
        $lower = strtolower(trim($languageName));

        // Direct match
        if (isset(self::LANGUAGE_NAME_MAP[$lower])) {
            return self::LANGUAGE_NAME_MAP[$lower];
        }

        // Partial match (e.g. "Brazilian Portuguese" → "portuguese")
        foreach (self::LANGUAGE_NAME_MAP as $name => $code) {
            if (str_contains($lower, $name)) {
                return $code;
            }
        }

        return null;
    }

    /**
     * Extract the best plain-text URL from a Gutenberg book record.
     *
     * Prefers UTF-8 plain text, falls back to ASCII.
     *
     * @param array $book Gutendex book record
     *
     * @return string|null Plain text URL or null
     */
    protected function extractTextUrl(array $book): ?string
    {
        /** @var array<string, string> $formats */
        $formats = $book['formats'] ?? [];

        // Prefer UTF-8 plain text
        foreach ($formats as $mime => $url) {
            if (
                str_starts_with($mime, 'text/plain')
                && str_contains($mime, 'utf-8')
                && !$this->isNonBookFile($url)
            ) {
                return $url;
            }
        }

        // Fall back to any plain text
        foreach ($formats as $mime => $url) {
            if (str_starts_with($mime, 'text/plain') && !$this->isNonBookFile($url)) {
                return $url;
            }
        }

        return null;
    }

    /**
     * Check if a URL points to a non-book file (readme, about, license).
     *
     * @param string $url File URL
     *
     * @return bool True if the URL should be skipped
     */
    private function isNonBookFile(string $url): bool
    {
        $lower = strtolower($url);
        return str_contains($lower, 'readme')
            || str_contains($lower, 'license')
            || str_contains($lower, 'about.');
    }

    /**
     * Fetch plain text content from a Gutenberg text URL.
     *
     * Strips the standard Project Gutenberg header/footer boilerplate.
     *
     * @param string $url Plain text URL from Gutendex
     *
     * @return string|null Text content or null on failure
     */
    public function fetchText(string $url): ?string
    {
        // Gutendex returns book-text URLs that point at
        // gutenberg.org and friends — but a compromised or MITM'd
        // Gutendex (or any 3xx in the chain) could rotate that URL
        // into a private address. safeHttpGet revalidates every hop.
        $text = UrlUtilities::safeHttpGet($url, [
            'timeout' => 15,
            'maxBytes' => 2 * 1024 * 1024,
            'maxRedirects' => 5,
            'userAgent' => 'LWT/3.0 (Language Learning Tool)',
            'accept' => 'text/plain',
        ]);

        return $text !== null && $text !== '' ? $text : null;
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
            'maxBytes' => 1024 * 1024,
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
