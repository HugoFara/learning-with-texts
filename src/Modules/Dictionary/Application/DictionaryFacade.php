<?php

/**
 * Dictionary Module Facade
 *
 * PHP version 8.2
 *
 * @category Lwt
 * @package  Lwt\Modules\Dictionary\Application
 * @author   HugoFara <git@hugofara.net>
 * @license  Unlicense <http://unlicense.org/>
 * @link     https://hugofara.github.io/lwt/developer/api
 * @since    3.0.0
 */

declare(strict_types=1);

namespace Lwt\Modules\Dictionary\Application;

use Lwt\Modules\Dictionary\Domain\LocalDictionary;
use Lwt\Modules\Dictionary\Application\Services\LocalDictionaryService;
use Lwt\Modules\Dictionary\Infrastructure\Import\ImporterInterface;
use Lwt\Modules\Dictionary\Infrastructure\Import\CsvImporter;
use Lwt\Modules\Dictionary\Infrastructure\Import\JsonImporter;
use Lwt\Modules\Dictionary\Infrastructure\Import\StarDictImporter;
use Lwt\Modules\Dictionary\Infrastructure\Translation\GoogleTranslateClient;
use RuntimeException;

/**
 * Facade for the Dictionary module.
 *
 * Provides a simplified interface for dictionary operations,
 * wrapping the underlying LocalDictionaryService.
 *
 * @since 3.0.0
 */
class DictionaryFacade
{
    private LocalDictionaryService $dictionaryService;

    /**
     * Create a new DictionaryFacade.
     *
     * @param LocalDictionaryService $dictionaryService The dictionary service
     */
    public function __construct(LocalDictionaryService $dictionaryService)
    {
        $this->dictionaryService = $dictionaryService;
    }

    /**
     * Get all dictionaries for a language.
     *
     * @param int $languageId Language ID
     *
     * @return LocalDictionary[]
     */
    public function getAllForLanguage(int $languageId): array
    {
        return $this->dictionaryService->getAllForLanguage($languageId);
    }

    /**
     * Get enabled dictionaries for a language.
     *
     * @param int $languageId Language ID
     *
     * @return LocalDictionary[]
     */
    public function getForLanguage(int $languageId): array
    {
        return $this->dictionaryService->getForLanguage($languageId);
    }

    /**
     * Get the local dictionary mode for a language.
     *
     * @param int $languageId Language ID
     *
     * @return int Mode (0=online only, 1=local first, 2=local only, 3=combined)
     */
    public function getLocalDictMode(int $languageId): int
    {
        return $this->dictionaryService->getLocalDictMode($languageId);
    }

    /**
     * Get a dictionary by ID.
     *
     * @param int $dictId Dictionary ID
     *
     * @return LocalDictionary|null
     */
    public function getById(int $dictId): ?LocalDictionary
    {
        return $this->dictionaryService->getById($dictId);
    }

    /**
     * Create a new dictionary.
     *
     * @param int         $languageId   Language ID
     * @param string      $name         Dictionary name
     * @param string      $sourceFormat Source format (csv, json, stardict)
     * @param string|null $description  Optional description
     *
     * @return int The new dictionary ID
     */
    public function create(
        int $languageId,
        string $name,
        string $sourceFormat = 'csv',
        ?string $description = null
    ): int {
        return $this->dictionaryService->create($languageId, $name, $sourceFormat, $description);
    }

    /**
     * Update a dictionary.
     *
     * @param LocalDictionary $dictionary Dictionary entity
     *
     * @return bool Success
     */
    public function update(LocalDictionary $dictionary): bool
    {
        return $this->dictionaryService->update($dictionary);
    }

    /**
     * Delete a dictionary.
     *
     * @param int $dictId Dictionary ID
     *
     * @return bool Success
     */
    public function delete(int $dictId): bool
    {
        return $this->dictionaryService->delete($dictId);
    }

    /**
     * Look up a term in local dictionaries.
     *
     * @param int    $languageId Language ID
     * @param string $term       Term to look up
     *
     * @return array<array{term: string, definition: string, reading: ?string, pos: ?string, dictionary: string}>
     */
    public function lookup(int $languageId, string $term): array
    {
        return $this->dictionaryService->lookup($languageId, $term);
    }

    /**
     * Look up a term with prefix matching.
     *
     * @param int    $languageId Language ID
     * @param string $prefix     Term prefix
     * @param int    $limit      Maximum results
     *
     * @return array<array{term: string, definition: string}>
     */
    public function lookupPrefix(int $languageId, string $prefix, int $limit = 10): array
    {
        return $this->dictionaryService->lookupPrefix($languageId, $prefix, $limit);
    }

    /**
     * Add entries to a dictionary in batch.
     *
     * @param int $dictId Dictionary ID
     * @param iterable<array{term: string, definition: string, reading?: ?string, pos?: ?string}> $entries
     *        Entries to add
     *
     * @return array{added: int, skipped: int} Entries inserted, and entries
     *         skipped for an unstorable headword
     */
    public function addEntriesBatch(int $dictId, iterable $entries): array
    {
        return $this->dictionaryService->addEntriesBatch($dictId, $entries);
    }

    /**
     * Get entries for a dictionary (paginated).
     *
     * @param int $dictId  Dictionary ID
     * @param int $page    Page number (1-based)
     * @param int $perPage Entries per page
     *
     * @return array{entries: array, total: int, page: int, perPage: int}
     */
    public function getEntries(int $dictId, int $page = 1, int $perPage = 50): array
    {
        return $this->dictionaryService->getEntries($dictId, $page, $perPage);
    }

    /**
     * Check if a language has any local dictionaries.
     *
     * @param int $languageId Language ID
     *
     * @return bool
     */
    public function hasLocalDictionaries(int $languageId): bool
    {
        return $this->dictionaryService->hasLocalDictionaries($languageId);
    }

    /**
     * Create vocabulary terms (status 1) from dictionary entries.
     *
     * @param int $dictId     Dictionary ID
     * @param int $languageId Language ID
     *
     * @return int Number of vocabulary terms created
     */
    public function createVocabularyFromEntries(int $dictId, int $languageId): int
    {
        return $this->dictionaryService->createVocabularyFromEntries($dictId, $languageId);
    }

    /**
     * Auto-enable local dictionary mode if currently online-only.
     *
     * @param int $languageId Language ID
     *
     * @return void
     */
    public function autoEnableLocalDictMode(int $languageId): void
    {
        $this->dictionaryService->autoEnableLocalDictMode($languageId);
    }

    /**
     * Translate a word using Google Translate.
     *
     * @param string $word       Word to translate
     * @param string $sourceLang Source language code
     * @param string $targetLang Target language code
     *
     * @return string[]|false Array of translations, or false on failure
     */
    public function translate(string $word, string $sourceLang, string $targetLang): array|false
    {
        return GoogleTranslateClient::staticTranslate($word, $sourceLang, $targetLang);
    }

    /**
     * Get the appropriate importer for a format.
     *
     * @param string $format       Import format (csv, json, stardict)
     * @param string $originalName Original filename for auto-detection
     *
     * @return ImporterInterface
     *
     * @throws RuntimeException If format is unsupported
     *
     * @psalm-suppress UndefinedClass Psalm incorrectly resolves namespace
     */
    public function getImporter(string $format, string $originalName = ''): ImporterInterface
    {
        // Auto-detect format from extension if not specified
        if ($format === 'auto' && !empty($originalName)) {
            $ext = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
            $format = match ($ext) {
                'csv', 'tsv', 'txt' => 'csv',
                'json' => 'json',
                'ifo', 'idx', 'dict', 'dz' => 'stardict',
                default => 'csv',
            };
        }

        return match ($format) {
            'csv', 'tsv' => new CsvImporter(),
            'json' => new JsonImporter(),
            'stardict', 'ifo' => new StarDictImporter(),
            default => throw new RuntimeException("Unsupported format: $format"),
        };
    }
}
