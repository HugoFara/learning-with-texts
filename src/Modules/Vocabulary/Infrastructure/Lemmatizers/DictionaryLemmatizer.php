<?php

/**
 * Dictionary-based Lemmatizer
 *
 * Provides lemmatization using pre-built TSV dictionary files.
 *
 * PHP version 8.2
 *
 * @category Lwt
 * @package  Lwt\Modules\Vocabulary\Infrastructure\Lemmatizers
 * @author   HugoFara <git@hugofara.net>
 * @license  Unlicense <http://unlicense.org/>
 * @link     https://hugofara.github.io/lwt/developer/api
 * @since    3.0.0
 */

declare(strict_types=1);

namespace Lwt\Modules\Vocabulary\Infrastructure\Lemmatizers;

use Lwt\Modules\Vocabulary\Domain\LemmatizerInterface;

/**
 * Lemmatizer that uses dictionary files for lookup.
 *
 * Dictionary files are TSV format with columns: word_form, lemma
 * Files are loaded from data/lemma-dictionaries/{lang}_lemmas.tsv
 *
 * @since 3.0.0
 */
class DictionaryLemmatizer implements LemmatizerInterface
{
    /**
     * Base directory for dictionary files.
     */
    private string $dictionaryPath;

    /**
     * Loaded dictionaries keyed by language code.
     *
     * @var array<string, array<string, string>>
     */
    private array $dictionaries = [];

    /**
     * List of available dictionaries (language codes with dictionary files).
     *
     * @var string[]|null
     */
    private ?array $availableLanguages = null;

    /**
     * Constructor.
     *
     * @param string|null $dictionaryPath Base path for dictionary files
     */
    public function __construct(?string $dictionaryPath = null)
    {
        $this->dictionaryPath = $dictionaryPath ?? $this->getDefaultDictionaryPath();
    }

    /**
     * Get the default dictionary path.
     *
     * @return string
     */
    private function getDefaultDictionaryPath(): string
    {
        // Try multiple possible locations
        $basePaths = [
            dirname(__DIR__, 5) . '/data/lemma-dictionaries',
            dirname(__DIR__, 5) . '/resources/lemma-dictionaries',
        ];

        foreach ($basePaths as $path) {
            if (is_dir($path)) {
                return $path;
            }
        }

        // Return the preferred path even if it doesn't exist yet
        return dirname(__DIR__, 5) . '/data/lemma-dictionaries';
    }

    /**
     * {@inheritdoc}
     */
    public function lemmatize(string $word, string $languageCode): ?string
    {
        $normalizedCode = $this->normalizeLanguageCode($languageCode);
        $this->ensureDictionaryLoaded($normalizedCode);

        $wordLower = mb_strtolower($word, 'UTF-8');

        return $this->dictionaries[$normalizedCode][$wordLower] ?? null;
    }

    /**
     * {@inheritdoc}
     */
    public function lemmatizeBatch(array $words, string $languageCode): array
    {
        $normalizedCode = $this->normalizeLanguageCode($languageCode);
        $this->ensureDictionaryLoaded($normalizedCode);

        $results = [];
        foreach ($words as $word) {
            $wordLower = mb_strtolower($word, 'UTF-8');
            $results[$word] = $this->dictionaries[$normalizedCode][$wordLower] ?? null;
        }

        return $results;
    }

    /**
     * {@inheritdoc}
     */
    public function supportsLanguage(string $languageCode): bool
    {
        $normalizedCode = $this->normalizeLanguageCode($languageCode);
        return $this->dictionaryFileExists($normalizedCode);
    }

    /**
     * {@inheritdoc}
     */
    public function getSupportedLanguages(): array
    {
        if ($this->availableLanguages !== null) {
            return $this->availableLanguages;
        }

        $this->availableLanguages = [];

        if (!is_dir($this->dictionaryPath)) {
            return $this->availableLanguages;
        }

        $files = glob($this->dictionaryPath . '/*_lemmas.tsv');
        if ($files === false) {
            return $this->availableLanguages;
        }

        foreach ($files as $file) {
            $basename = basename($file, '_lemmas.tsv');
            $this->availableLanguages[] = $basename;
        }

        return $this->availableLanguages;
    }

    /**
     * Load a dictionary file for a language.
     *
     * @param string $languageCode Normalized language code
     *
     * @return bool True if loaded successfully
     */
    public function loadDictionary(string $languageCode): bool
    {
        $filePath = $this->getDictionaryFilePath($languageCode);

        if (!file_exists($filePath)) {
            $this->dictionaries[$languageCode] = [];
            return false;
        }

        $handle = fopen($filePath, 'r');
        if ($handle === false) {
            $this->dictionaries[$languageCode] = [];
            return false;
        }

        $this->dictionaries[$languageCode] = [];

        // Skip header line if present
        $firstLine = fgets($handle);
        if ($firstLine !== false && !str_contains($firstLine, "\t")) {
            // No tab = header, skip it
            // Otherwise, process it
        } elseif ($firstLine !== false) {
            $this->parseDictionaryLine($firstLine, $languageCode);
        }

        while (($line = fgets($handle)) !== false) {
            $this->parseDictionaryLine($line, $languageCode);
        }

        fclose($handle);

        return count($this->dictionaries[$languageCode]) > 0;
    }

    /**
     * Parse a single dictionary line.
     *
     * @param string $line         The line to parse
     * @param string $languageCode The language code
     *
     * @return void
     */
    private function parseDictionaryLine(string $line, string $languageCode): void
    {
        $line = trim($line);
        if ($line === '' || $line[0] === '#') {
            return; // Skip empty lines and comments
        }

        $parts = explode("\t", $line);
        if (count($parts) >= 2) {
            $wordForm = mb_strtolower(trim($parts[0]), 'UTF-8');
            $lemma = trim($parts[1]);

            // Only store if lemma differs from word form
            if ($wordForm !== '' && $lemma !== '' && $wordForm !== mb_strtolower($lemma, 'UTF-8')) {
                $this->dictionaries[$languageCode][$wordForm] = $lemma;
            }
        }
    }

    /**
     * Ensure a dictionary is loaded.
     *
     * @param string $languageCode The language code
     *
     * @return void
     */
    private function ensureDictionaryLoaded(string $languageCode): void
    {
        if (!isset($this->dictionaries[$languageCode])) {
            $this->loadDictionary($languageCode);
        }
    }

    /**
     * Normalize a language code to standard format.
     *
     * Handles variations like "en-US" -> "en", "eng" -> "en"
     *
     * @param string $code The language code
     *
     * @return string Normalized code
     */
    private function normalizeLanguageCode(string $code): string
    {
        // Extract base language from locale codes (en-US -> en)
        $parts = preg_split('/[-_]/', $code);
        $base = $parts !== false && count($parts) > 0 ? strtolower($parts[0]) : strtolower($code);

        // Map 3-letter codes to 2-letter
        $map = [
            'eng' => 'en',
            'deu' => 'de',
            'fra' => 'fr',
            'spa' => 'es',
            'ita' => 'it',
            'por' => 'pt',
            'rus' => 'ru',
            'jpn' => 'ja',
            'zho' => 'zh',
            'kor' => 'ko',
            'ara' => 'ar',
            'nld' => 'nl',
            'pol' => 'pl',
            'tur' => 'tr',
            'fin' => 'fi',
            'swe' => 'sv',
            'nor' => 'no',
            'dan' => 'da',
            'hun' => 'hu',
            'ces' => 'cs',
            'ell' => 'el',
            'heb' => 'he',
            'ukr' => 'uk',
        ];

        return $map[$base] ?? $base;
    }

    /**
     * Get the file path for a language dictionary.
     *
     * @param string $languageCode The language code
     *
     * @return string The file path
     */
    private function getDictionaryFilePath(string $languageCode): string
    {
        return $this->dictionaryPath . '/' . $languageCode . '_lemmas.tsv';
    }

    /**
     * Check if a dictionary file exists.
     *
     * @param string $languageCode The language code
     *
     * @return bool
     */
    private function dictionaryFileExists(string $languageCode): bool
    {
        return file_exists($this->getDictionaryFilePath($languageCode));
    }

    /**
     * Get statistics about loaded dictionaries.
     *
     * @return array<string, array{entries: int, file_size: int|false}>
     */
    public function getStatistics(): array
    {
        $stats = [];

        foreach ($this->dictionaries as $code => $entries) {
            $filePath = $this->getDictionaryFilePath($code);
            $stats[$code] = [
                'entries' => count($entries),
                'file_size' => file_exists($filePath) ? filesize($filePath) : false,
            ];
        }

        return $stats;
    }

    /**
     * Clear all loaded dictionaries from memory.
     *
     * @return void
     */
    public function clearCache(): void
    {
        $this->dictionaries = [];
    }

    /**
     * Get the dictionary path.
     *
     * @return string
     */
    public function getDictionaryPath(): string
    {
        return $this->dictionaryPath;
    }
}
