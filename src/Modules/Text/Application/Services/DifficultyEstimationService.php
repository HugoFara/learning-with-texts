<?php

/**
 * Text Difficulty Estimation Service
 *
 * Provides two tiers of difficulty estimation for Gutenberg texts:
 * 1. Quick tier: heuristic based on user vocabulary size + subject categories
 * 2. Accurate coverage: samples text and computes known-word percentage
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

use Lwt\Shared\Infrastructure\Cache\FileCache;
use Lwt\Shared\Infrastructure\Database\Connection;
use Lwt\Shared\Infrastructure\Database\QueryBuilder;
use Lwt\Shared\Infrastructure\Database\UserScopedQuery;
use Lwt\Shared\Infrastructure\Http\WebPageExtractor;

/**
 * Estimates text difficulty relative to a user's known vocabulary.
 *
 * @since 3.0.0
 */
class DifficultyEstimationService
{
    /**
     * Known-word count below which a reader is treated as a beginner.
     *
     * Matches the lower vocabulary threshold used by computeQuickTier(): under
     * ~500 known words, even "easy" classics read hard, so beginners are
     * better served by the Global Digital Library's early-grade readers.
     */
    public const BEGINNER_VOCAB_THRESHOLD = 500;

    /**
     * Maximum number of words to sample for accurate coverage.
     */
    private const SAMPLE_WORD_COUNT = 2000;

    /**
     * How long a fetched source document stays cached, in seconds.
     *
     * Published books do not change, so this only needs to be short enough to
     * recover from a truncated or mis-extracted fetch. A day matches the
     * Gutenberg suggestion cache.
     */
    private const SOURCE_CACHE_TTL = 86400;

    /**
     * Maximum number of words per vocabulary lookup batch.
     */
    private const LOOKUP_BATCH_SIZE = 500;

    /**
     * Subject keywords that indicate easier texts.
     *
     * @var list<string>
     */
    private const EASY_SUBJECTS = [
        'children',
        'juvenile',
        'fairy tale',
        'nursery',
        'picture book',
        'fable',
        'primer',
        'easy reading',
    ];

    /**
     * Subject keywords that indicate harder texts.
     *
     * @var list<string>
     */
    private const HARD_SUBJECTS = [
        'philosophy',
        'science',
        'law',
        'economics',
        'political science',
        'mathematics',
        'psychology',
        'theology',
        'metaphysics',
        'logic',
        'jurisprudence',
        'historiography',
    ];

    /**
     * Estimate quick difficulty tiers for a batch of books.
     *
     * Performs a single DB query for vocabulary size, then classifies
     * each book based on its subjects.
     *
     * @param int                        $languageId    Language ID
     * @param array<int, list<string>>   $booksSubjects Map of bookId => subjects
     *
     * @return array<int, string> Map of bookId => 'easy'|'medium'|'hard'
     */
    public function estimateQuickTiers(int $languageId, array $booksSubjects): array
    {
        $knownCount = $this->getKnownWordCount($languageId);

        $tiers = [];
        foreach ($booksSubjects as $bookId => $subjects) {
            $tiers[$bookId] = $this->computeQuickTier($knownCount, $subjects);
        }

        return $tiers;
    }

    /**
     * Analyze a text sample for accurate vocabulary coverage.
     *
     * Fetches the text, extracts a sample, tokenizes it, and computes
     * the percentage of unique words the user already knows.
     *
     * @param string $textUrl    URL of the plain text
     * @param int    $languageId Language ID (for tokenization and vocab lookup)
     *
     * @return array{
     *     total_words: int,
     *     total_unique_words: int,
     *     known_words: int,
     *     unknown_words: int,
     *     coverage_percent: float,
     *     difficulty_label: string,
     *     sample_unknown_words: list<string>
     * }|array{error: string}
     */
    public function analyzeTextSample(string $textUrl, int $languageId): array
    {
        $text = $this->fetchTextContent($textUrl);
        if ($text === null) {
            return ['error' => 'Could not fetch text. The site may be unreachable.'];
        }

        if ($text === '') {
            return ['error' => 'No text content could be extracted.'];
        }

        // Get language parsing settings
        $parseSettings = $this->getLanguageParseSettings($languageId);
        if ($parseSettings === null) {
            return ['error' => 'Language not found.'];
        }

        $wordRegex = $parseSettings['regex'];
        $splitEachChar = $parseSettings['splitEachChar'];

        // Estimate total words from full text length and sample average
        $tokens = $this->tokenize($text, $wordRegex, self::SAMPLE_WORD_COUNT, $splitEachChar);
        $sampleLen = mb_strlen(implode(' ', $tokens));
        $totalLen = mb_strlen($text);
        $sampleCount = count($tokens);
        // Extrapolate total word count from the ratio of sampled text to full text
        $totalWords = $sampleLen > 0
            ? (int) round($sampleCount * ($totalLen / $sampleLen))
            : $sampleCount;

        $uniqueWords = array_unique(array_map('mb_strtolower', $tokens));
        $uniqueWords = array_values($uniqueWords);

        $totalUnique = count($uniqueWords);
        if ($totalUnique === 0) {
            return ['error' => 'No words could be extracted from the text sample.'];
        }

        // Look up which words the user knows
        $knownSet = $this->lookupKnownWords($languageId, $uniqueWords);
        $knownCount = count($knownSet);
        $unknownCount = $totalUnique - $knownCount;
        $coveragePercent = round(($knownCount / $totalUnique) * 100, 1);

        // Collect a sample of unknown words
        $unknownWords = array_values(array_diff($uniqueWords, $knownSet));
        $sampleUnknown = array_slice($unknownWords, 0, 20);

        return [
            'total_words' => $totalWords,
            'total_unique_words' => $totalUnique,
            'known_words' => $knownCount,
            'unknown_words' => $unknownCount,
            'coverage_percent' => $coveragePercent,
            'difficulty_label' => $this->labelFromCoverage($coveragePercent),
            'sample_unknown_words' => $sampleUnknown,
        ];
    }

    /**
     * Fetch text content from a URL.
     *
     * Uses GutenbergClient for Gutenberg URLs (simpler, follows redirects),
     * falls back to WebPageExtractor for other URLs.
     *
     * @param string $url Text URL
     *
     * @return string|null Extracted text or null on fetch failure
     */
    private function fetchTextContent(string $url): ?string
    {
        // The download dominates this call — several seconds for a full book,
        // against milliseconds for the tokenizing and vocabulary lookup that
        // follow. The suggestion panels ask for a preview of every book they
        // list, on every page load, so without this the same handful of books
        // is refetched continuously and each request occupies a PHP worker for
        // the duration. What is cached is the source document, which is the
        // same for everyone; the coverage figures are still computed per user
        // on top of it.
        $cache = new FileCache('lwt_source_text_cache', self::SOURCE_CACHE_TTL);
        $cached = $cache->get($url);
        if ($cached !== null) {
            return $cached;
        }

        $text = $this->fetchTextContentUncached($url);
        if ($text !== null && $text !== '') {
            $cache->set($url, $text);
        }

        return $text;
    }

    /**
     * Fetch and extract the text of a source document.
     *
     * @param string $url URL to fetch
     *
     * @return string|null Extracted text or null on failure
     */
    private function fetchTextContentUncached(string $url): ?string
    {
        if (str_contains($url, 'gutenberg.org')) {
            $client = new \Lwt\Shared\Infrastructure\Http\GutenbergClient();
            $raw = $client->fetchText($url);
            if ($raw === null) {
                return null;
            }
            // Strip Gutenberg boilerplate
            $extractor = new WebPageExtractor();
            return $extractor->stripGutenbergBoilerplatePublic($raw);
        }

        // Non-Gutenberg URLs: use the full extractor pipeline
        $extractor = new WebPageExtractor();
        $result = $extractor->extractFromUrl($url);
        if (isset($result['error'])) {
            return null;
        }
        return $result['text'] ?? null;
    }

    /**
     * Count words the user knows for a language.
     *
     * "Known" = status 5 (learned), 98 (ignored), 99 (well-known).
     *
     * @param int $languageId Language ID
     *
     * @return int Number of known words
     */
    /**
     * Build a reader profile for a language used to order home suggestions.
     *
     * @param int $languageId Language ID
     *
     * @return array{vocabularySize: int, beginner: bool}
     */
    public function getReaderProfile(int $languageId): array
    {
        $known = $this->getKnownWordCount($languageId);
        return [
            'vocabularySize' => $known,
            'beginner' => self::isBeginnerVocabulary($known),
        ];
    }

    /**
     * Decide whether a known-word count puts the reader at beginner level.
     *
     * @param int $knownWordCount Number of known words in the language
     *
     * @return bool True if the reader is a beginner
     */
    public static function isBeginnerVocabulary(int $knownWordCount): bool
    {
        return $knownWordCount < self::BEGINNER_VOCAB_THRESHOLD;
    }

    private function getKnownWordCount(int $languageId): int
    {
        $bindings = [$languageId];
        $sql = "SELECT COUNT(*) FROM words
                WHERE WoLgID = ? AND WoStatus IN (5, 98, 99)"
            . UserScopedQuery::forTablePrepared('words', $bindings);

        /** @var int|string|false $count */
        $count = Connection::preparedFetchValue($sql, $bindings);

        return (int) $count;
    }

    /**
     * Compute quick difficulty tier from vocabulary size and subjects.
     *
     * @param int          $knownCount Number of known words
     * @param list<string> $subjects   Gutenberg subject categories
     *
     * @return string 'easy'|'medium'|'hard'
     */
    private function computeQuickTier(int $knownCount, array $subjects): string
    {
        $subjectTier = $this->classifySubjects($subjects);

        // Shift tier based on vocabulary size
        if ($knownCount === 0) {
            return 'hard';
        }

        if ($knownCount < 500) {
            // Shift up: easy→medium, medium→hard, hard→hard
            return match ($subjectTier) {
                'easy' => 'medium',
                default => 'hard',
            };
        }

        if ($knownCount > 2000) {
            // Shift down: hard→medium, medium→easy, easy→easy
            return match ($subjectTier) {
                'hard' => 'medium',
                default => 'easy',
            };
        }

        // 500–2000 words: use subject tier directly
        return $subjectTier;
    }

    /**
     * Classify subjects into a difficulty tier (public API).
     *
     * @param list<string> $subjects Subject categories
     *
     * @return string 'easy'|'medium'|'hard'
     */
    public function classifySubjectsPublic(array $subjects): string
    {
        return $this->classifySubjects($subjects);
    }

    /**
     * Classify subject list into a difficulty tier.
     *
     * Picks the most favorable (lowest difficulty) match.
     *
     * @param list<string> $subjects Subject categories
     *
     * @return string 'easy'|'medium'|'hard'
     */
    private function classifySubjects(array $subjects): string
    {
        $subjectsLower = implode(' | ', array_map('strtolower', $subjects));

        foreach (self::EASY_SUBJECTS as $keyword) {
            if (str_contains($subjectsLower, $keyword)) {
                return 'easy';
            }
        }

        foreach (self::HARD_SUBJECTS as $keyword) {
            if (str_contains($subjectsLower, $keyword)) {
                return 'hard';
            }
        }

        return 'medium';
    }

    /**
     * Get language parsing settings for tokenization.
     *
     * @param int $languageId Language ID
     *
     * @return array{regex: string, splitEachChar: bool}|null Settings or null if not found
     */
    private function getLanguageParseSettings(int $languageId): ?array
    {
        $row = QueryBuilder::table('languages')
            ->select(['LgRegexpWordCharacters', 'LgSplitEachChar'])
            ->where('LgID', '=', $languageId)
            ->firstPrepared();

        if ($row === null) {
            return null;
        }

        $regex = (string) ($row['LgRegexpWordCharacters'] ?? '');

        return [
            'regex' => $regex !== '' ? $regex : '\\w',
            'splitEachChar' => (int) ($row['LgSplitEachChar'] ?? 0) === 1,
        ];
    }

    /**
     * Tokenize text into words using the language's word character regex.
     *
     * For languages with splitEachChar (Chinese, etc.), each matched
     * character becomes its own token.
     *
     * @param string $text         Text to tokenize
     * @param string $wordRegex    Word character regex class content
     * @param int    $maxWords     Maximum number of words to return
     * @param bool   $splitEachChar Whether to treat each character as a word
     *
     * @return list<string> Word tokens
     */
    private function tokenize(
        string $text,
        string $wordRegex,
        int $maxWords,
        bool $splitEachChar = false
    ): array {
        if ($splitEachChar) {
            // Match individual word characters (for CJK languages)
            $pattern = '/[' . $wordRegex . ']/u';
            $count = preg_match_all($pattern, $text, $matches);
            if ($count === false || $count === 0) {
                return [];
            }
            return array_slice($matches[0], 0, $maxWords);
        }

        // Split on non-word characters (for alphabetic languages)
        $pattern = '/[^' . $wordRegex . ']+/u';
        $tokens = preg_split($pattern, $text, -1, PREG_SPLIT_NO_EMPTY);

        if ($tokens === false) {
            return [];
        }

        $filtered = [];
        foreach ($tokens as $token) {
            if (mb_strlen($token) >= 1) {
                $filtered[] = $token;
                if (count($filtered) >= $maxWords) {
                    break;
                }
            }
        }

        return $filtered;
    }

    /**
     * Look up which words from a list the user already knows.
     *
     * Words with any status (1-5, 98, 99) are considered "encountered".
     *
     * @param int          $languageId Language ID
     * @param list<string> $words      Lowercase words to look up
     *
     * @return list<string> Words that exist in the user's vocabulary
     */
    private function lookupKnownWords(int $languageId, array $words): array
    {
        if (empty($words)) {
            return [];
        }

        $known = [];

        // Batch lookups to avoid huge IN clauses
        foreach (array_chunk($words, self::LOOKUP_BATCH_SIZE) as $batch) {
            $placeholders = implode(',', array_fill(0, count($batch), '?'));
            $params = array_merge([$languageId], $batch);
            $userScope = UserScopedQuery::forTablePrepared('words', $params);

            $sql = "SELECT WoTextLC FROM words
                    WHERE WoLgID = ? AND WoTextLC IN ($placeholders)"
                . $userScope;

            $rows = Connection::preparedFetchAll($sql, $params);
            foreach ($rows as $row) {
                $known[] = (string) $row['WoTextLC'];
            }
        }

        return $known;
    }

    /**
     * Map coverage percentage to a human-readable difficulty label.
     *
     * Based on research: 95%+ coverage = comfortable reading,
     * 90-95% = challenging but feasible, below 90% = frustrating.
     *
     * @param float $percent Coverage percentage
     *
     * @return string Difficulty label
     */
    private function labelFromCoverage(float $percent): string
    {
        if ($percent >= 95.0) {
            return 'easy';
        }

        if ($percent >= 85.0) {
            return 'medium';
        }

        return 'hard';
    }
}
