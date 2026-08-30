<?php

/**
 * Gutenberg Suggestion Service
 *
 * Provides cached, difficulty-ranked book suggestions from Project Gutenberg,
 * tailored to the user's current language and vocabulary level.
 *
 * PHP version 8.2
 *
 * @category Lwt
 * @package  Lwt\Modules\Text\Application\Services
 * @author   HugoFara <git@hugofara.net>
 * @license  Unlicense <http://unlicense.org/>
 * @link     https://hugofara.github.io/lwt/developer/api
 * @since    3.0.0
 */

declare(strict_types=1);

namespace Lwt\Modules\Text\Application\Services;

use Lwt\Shared\Infrastructure\Database\QueryBuilder;
use Lwt\Shared\Infrastructure\Http\GutenbergClient;

/**
 * Fetches and caches popular Gutenberg books with difficulty tiers.
 *
 * Uses a file-based cache (24h TTL) to avoid hitting the Gutendex API
 * on every home page load.
 *
 * @since 3.0.0
 */
class GutenbergSuggestionService
{
    /**
     * Cache time-to-live in seconds (24 hours).
     */
    private const CACHE_TTL = 86400;

    /**
     * Number of books per page from Gutendex.
     */
    private const PAGE_SIZE = 32;

    /**
     * Get book suggestions for a language, with caching and difficulty tiers.
     *
     * @param int $languageId Language ID
     * @param int $page       Page number (1-based)
     *
     * @return array
     */
    public function getSuggestions(int $languageId, int $page = 1): array
    {
        $languageCode = $this->resolveLanguageCode($languageId);
        if ($languageCode === null) {
            return ['error' => 'Could not determine language code for suggestions.'];
        }

        // Try cache first (per language + page)
        $cacheKey = $languageCode . '_p' . $page;
        $cached = $this->readCache($cacheKey);
        if ($cached !== null) {
            // Re-compute difficulty tiers (user vocabulary may have changed)
            $this->enrichWithTiers($cached);
            $cached['cached'] = true;
            return $cached;
        }

        // Fetch from Gutendex
        $client = new GutenbergClient();
        $result = $client->browse($languageCode, $page);

        if (isset($result['error'])) {
            return $result;
        }

        // Cache the raw result (without user-specific tiers)
        $this->writeCache($cacheKey, $result);

        // Add difficulty tiers
        $this->enrichWithTiers($result);
        $result['cached'] = false;

        return $result;
    }

    /**
     * Enrich results with subject-based difficulty tiers, sorted easy-first.
     *
     * Uses subject classification only (not vocabulary-adjusted) so that
     * suggestions show relative difficulty between books, regardless of
     * the user's current vocabulary size.
     *
     * @param array $result Search result (modified in place)
     */
    private function enrichWithTiers(array &$result): void
    {
        /** @var list<array{id: int, subjects: list<string>}> $books */
        $books = $result['results'] ?? [];
        if ($books === []) {
            return;
        }

        $service = new DifficultyEstimationService();
        $tierOrder = ['easy' => 0, 'medium' => 1, 'hard' => 2];

        $enriched = [];
        foreach ($books as $book) {
            $book['difficultyTier'] = $service->classifySubjectsPublic($book['subjects'] ?? []);
            $enriched[] = $book;
        }

        // Sort: easy first, then medium, then hard
        usort($enriched, static function (array $a, array $b) use ($tierOrder): int {
            $aTierKey = (string) ($a['difficultyTier'] ?? 'medium');
            $bTierKey = (string) ($b['difficultyTier'] ?? 'medium');
            $aTier = $tierOrder[$aTierKey] ?? 1;
            $bTier = $tierOrder[$bTierKey] ?? 1;
            return $aTier <=> $bTier;
        });

        $result['results'] = $enriched;
    }

    /**
     * Resolve language ID to ISO 639-1 code.
     *
     * @param int $languageId Language ID
     *
     * @return string|null ISO code or null
     */
    private function resolveLanguageCode(int $languageId): ?string
    {
        $row = QueryBuilder::table('languages')
            ->select(['LgSourceLang', 'LgName'])
            ->where('LgID', '=', $languageId)
            ->firstPrepared();

        if ($row === null) {
            return null;
        }

        $sourceLang = (string) ($row['LgSourceLang'] ?? '');
        if ($sourceLang !== '') {
            // Gutendex uses bare ISO 639-1 codes (e.g. "zh"), not BCP 47
            // subtags like "zh-CN" or "zh-Hans". Strip everything after
            // the first hyphen so that "zh-CN" → "zh", "pt-BR" → "pt", etc.
            $parts = explode('-', $sourceLang, 2);
            return strtolower($parts[0]);
        }

        $name = (string) ($row['LgName'] ?? '');
        return GutenbergClient::guessLanguageCode($name);
    }

    /**
     * Get the file path for a cache key.
     *
     * @param string $key Cache key
     *
     * @return string Absolute file path
     */
    private function cachePath(string $key): string
    {
        $dir = sys_get_temp_dir() . '/lwt_gutenberg_cache';
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }
        return $dir . '/' . preg_replace('/[^a-z0-9_]/', '_', $key) . '.json';
    }

    /**
     * Read a cached result if still valid.
     *
     * @param string $key Cache key
     *
     * @return array|null Cached data or null if expired/missing
     */
    private function readCache(string $key): ?array
    {
        $path = $this->cachePath($key);

        if (!file_exists($path)) {
            return null;
        }

        $mtime = filemtime($path);
        if ($mtime === false || (time() - $mtime) > self::CACHE_TTL) {
            @unlink($path);
            return null;
        }

        $content = @file_get_contents($path);
        if ($content === false || $content === '') {
            return null;
        }

        /** @var array|null $data */
        $data = json_decode($content, true);
        return is_array($data) ? $data : null;
    }

    /**
     * Write a result to cache.
     *
     * @param string $key  Cache key
     * @param array  $data Data to cache
     */
    private function writeCache(string $key, array $data): void
    {
        $path = $this->cachePath($key);
        $json = json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if ($json !== false) {
            @file_put_contents($path, $json, LOCK_EX);
        }
    }
}
