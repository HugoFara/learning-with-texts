<?php

/**
 * Dictionary API Handler
 *
 * PHP version 8.2
 *
 * @category Lwt
 * @package  Lwt\Modules\Dictionary\Http
 * @author   HugoFara <git@hugofara.net>
 * @license  Unlicense <http://unlicense.org/>
 * @link     https://hugofara.github.io/lwt/developer/api
 * @since    3.0.0
 */

declare(strict_types=1);

namespace Lwt\Modules\Dictionary\Http;

use Lwt\Api\V1\Response;
use Lwt\Modules\Dictionary\Application\DictionaryFacade;
use Lwt\Modules\Dictionary\Application\Services\CuratedDictImportService;
use Lwt\Modules\Dictionary\Application\Services\LocalDictionaryService;
use Lwt\Modules\Dictionary\Infrastructure\Import\CsvImporter;
use Lwt\Modules\Dictionary\Infrastructure\Import\JsonImporter;
use Lwt\Modules\Dictionary\Infrastructure\Import\StarDictImporter;
use Lwt\Shared\Http\ApiRoutableInterface;
use Lwt\Shared\Http\ApiRoutableTrait;
use Lwt\Shared\Infrastructure\Http\JsonResponse;
use RuntimeException;

/**
 * API handler for dictionary operations.
 *
 * Provides endpoints for:
 * - Listing dictionaries for a language
 * - Creating and deleting dictionaries
 * - Importing dictionary entries from files
 * - Looking up terms
 *
 * @since 3.0.0
 */
class DictionaryApiHandler implements ApiRoutableInterface
{
    use ApiRoutableTrait;

    private DictionaryFacade $facade;
    private LocalDictionaryService $dictService;
    private ?CuratedDictImportService $curatedImportService;

    /**
     * Create a new DictionaryApiHandler.
     *
     * @param DictionaryFacade|null          $facade               Facade instance
     * @param CuratedDictImportService|null  $curatedImportService Curated import service
     */
    public function __construct(
        ?DictionaryFacade $facade = null,
        ?CuratedDictImportService $curatedImportService = null
    ) {
        $this->dictService = new LocalDictionaryService();
        $this->facade = $facade ?? new DictionaryFacade($this->dictService);
        $this->curatedImportService = $curatedImportService;
    }

    // =========================================================================
    // Dictionary CRUD
    // =========================================================================

    /**
     * Get all dictionaries for a language.
     *
     * @param int $langId Language ID
     *
     * @return array{dictionaries: array, mode: int}
     */
    public function getDictionaries(int $langId): array
    {
        $dictionaries = $this->facade->getAllForLanguage($langId);
        $mode = $this->facade->getLocalDictMode($langId);

        return [
            'dictionaries' => array_map([$this, 'formatDictionary'], $dictionaries),
            'mode' => $mode,
        ];
    }

    /**
     * Get a single dictionary by ID.
     *
     * @param int $dictId Dictionary ID
     *
     * @return array Dictionary data or error
     */
    public function getDictionary(int $dictId): array
    {
        $dictionary = $this->facade->getById($dictId);

        if ($dictionary === null) {
            return ['error' => 'Dictionary not found'];
        }

        return $this->formatDictionary($dictionary);
    }

    /**
     * Create a new dictionary.
     *
     * @param array $data Dictionary data:
     *                    - language_id: int (required)
     *                    - name: string (required)
     *                    - description: string (optional)
     *                    - source_format: string (optional, default: 'csv')
     *
     * @return array{success: bool, dictionary?: array, error?: string}
     */
    public function createDictionary(array $data): array
    {
        $langId = (int)($data['language_id'] ?? 0);
        $name = trim((string) ($data['name'] ?? ''));
        $description = isset($data['description']) ? trim((string) $data['description']) : null;
        /** @var string $sourceFormat */
        $sourceFormat = $data['source_format'] ?? 'csv';

        if ($langId <= 0) {
            return ['success' => false, 'error' => 'Language ID is required'];
        }

        // In multi-user mode, refuse to create a dictionary against
        // another user's language. The DB column `LdLgID` has no
        // foreign-key constraint that fences cross-user references,
        // so an authenticated user could otherwise plant rows pinned
        // to a stranger's LgID.
        if (!\Lwt\Shared\Infrastructure\Globals::languageBelongsToCurrentUser($langId)) {
            return ['success' => false, 'error' => 'Language not found or access denied'];
        }

        if (empty($name)) {
            return ['success' => false, 'error' => 'Dictionary name is required'];
        }

        $dictId = $this->facade->create($langId, $name, $sourceFormat, $description);
        $dictionary = $this->facade->getById($dictId);

        $result = ['success' => true];
        if ($dictionary !== null) {
            $result['dictionary'] = $this->formatDictionary($dictionary);
        }

        return $result;
    }

    /**
     * Import a curated dictionary from a remote URL.
     *
     * @param array $data Request data:
     *                    - language_id: int (required)
     *                    - url: string (required, must be in curated registry)
     *                    - format: string (required, e.g. 'stardict')
     *                    - name: string (required)
     *
     * @return array{success: bool, dictId?: int, imported?: int, skipped?: int,
     *         vocabCreated?: int, error?: string} `skipped` counts entries
     *         whose headword was too long to store
     */
    public function importCurated(array $data): array
    {
        if ($this->curatedImportService === null) {
            return ['success' => false, 'error' => 'Curated import service not available'];
        }

        $langId = (int) ($data['language_id'] ?? 0);
        $url = trim((string) ($data['url'] ?? ''));
        $format = trim((string) ($data['format'] ?? 'stardict'));
        $name = trim((string) ($data['name'] ?? ''));

        if ($langId <= 0) {
            return ['success' => false, 'error' => 'Language ID is required'];
        }
        if (!\Lwt\Shared\Infrastructure\Globals::languageBelongsToCurrentUser($langId)) {
            // Same fence as createDictionary — keep it consistent so
            // both paths refuse cross-user LgID references.
            return ['success' => false, 'error' => 'Language not found or access denied'];
        }
        if ($url === '') {
            return ['success' => false, 'error' => 'URL is required'];
        }
        if ($name === '') {
            return ['success' => false, 'error' => 'Dictionary name is required'];
        }

        return $this->curatedImportService->importFromUrl($langId, $url, $format, $name);
    }

    /**
     * Update a dictionary.
     *
     * @param int   $dictId Dictionary ID
     * @param array $data   Dictionary data
     *
     * @return array{success: bool, dictionary?: array, error?: string}
     */
    public function updateDictionary(int $dictId, array $data): array
    {
        $dictionary = $this->facade->getById($dictId);

        if ($dictionary === null) {
            return ['success' => false, 'error' => 'Dictionary not found'];
        }

        if (isset($data['name'])) {
            $dictionary->rename(trim((string) $data['name']));
        }

        if (array_key_exists('description', $data)) {
            $dictionary->setDescription(
                $data['description'] !== null ? trim((string) $data['description']) : null
            );
        }

        if (isset($data['priority'])) {
            $dictionary->setPriority((int)$data['priority']);
        }

        if (isset($data['enabled'])) {
            if ((bool)$data['enabled']) {
                $dictionary->enable();
            } else {
                $dictionary->disable();
            }
        }

        $this->facade->update($dictionary);

        return [
            'success' => true,
            'dictionary' => $this->formatDictionary($dictionary),
        ];
    }

    /**
     * Delete a dictionary.
     *
     * @param int $dictId Dictionary ID
     *
     * @return array{success: bool, error?: string}
     */
    public function deleteDictionary(int $dictId): array
    {
        $deleted = $this->facade->delete($dictId);

        if (!$deleted) {
            return ['success' => false, 'error' => 'Dictionary not found'];
        }

        return ['success' => true];
    }

    // =========================================================================
    // Lookup
    // =========================================================================

    /**
     * Look up a term in local dictionaries.
     *
     * @param int    $langId Language ID
     * @param string $term   Term to look up
     *
     * @return array{results: array, mode: int}
     */
    public function lookup(int $langId, string $term): array
    {
        $results = $this->facade->lookup($langId, $term);
        $mode = $this->facade->getLocalDictMode($langId);

        return [
            'results' => $results,
            'mode' => $mode,
        ];
    }

    /**
     * Look up terms with prefix matching (autocomplete).
     *
     * @param int    $langId Language ID
     * @param string $prefix Term prefix
     * @param int    $limit  Max results
     *
     * @return array{results: array}
     */
    public function lookupPrefix(int $langId, string $prefix, int $limit = 10): array
    {
        $results = $this->facade->lookupPrefix($langId, $prefix, $limit);

        return ['results' => $results];
    }

    // =========================================================================
    // Import
    // =========================================================================

    /**
     * Import entries into a dictionary from uploaded file.
     *
     * @param int   $dictId  Dictionary ID
     * @param array $data    Import data:
     *                       - file_path: string (temporary file path)
     *                       - original_name: string|null (original upload filename, used for extension detection)
     *                       - format: string (csv, json, stardict)
     *                       - options: array (format-specific options)
     *
     * @return array{success: bool, imported?: int, skipped?: int, error?: string}
     *         `skipped` counts entries whose headword was too long to store
     */
    public function importFile(int $dictId, array $data): array
    {
        $dictionary = $this->facade->getById($dictId);
        if ($dictionary === null) {
            return ['success' => false, 'error' => 'Dictionary not found'];
        }

        $filePath = $data['file_path'] ?? '';
        /** @var string|null $originalName */
        $originalName = $data['original_name'] ?? null;
        /** @var string $format */
        $format = $data['format'] ?? 'csv';
        /** @var array<string, mixed> $options */
        $options = $data['options'] ?? [];

        if (!is_string($filePath) || $filePath === '' || !file_exists($filePath)) {
            return ['success' => false, 'error' => 'File not found'];
        }
        $originalName = is_string($originalName) ? $originalName : null;

        try {
            $importer = $this->facade->getImporter($format, $originalName ?? '');

            /** @psalm-suppress UndefinedClass Psalm incorrectly resolves namespace */
            if (!$importer->canImport($filePath, $originalName)) {
                return ['success' => false, 'error' => 'Invalid file format'];
            }

            /** @psalm-suppress UndefinedClass Psalm incorrectly resolves namespace */
            $entries = $importer->parse($filePath, $options);
            $result = $this->facade->addEntriesBatch($dictId, $entries);

            return [
                'success' => true,
                'imported' => $result['added'],
                'skipped' => $result['skipped'],
            ];
        } catch (RuntimeException $e) {
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    /**
     * Preview entries from a file before import.
     *
     * @param array $data Preview data:
     *                    - file_path: string
     *                    - original_name: string|null (original upload filename, used for extension detection)
     *                    - format: string
     *                    - limit: int (default: 10)
     *                    - options: array
     *
     * @return array{success: bool, entries?: array, structure?: array, error?: string}
     */
    public function previewFile(array $data): array
    {
        $filePath = $data['file_path'] ?? '';
        /** @var string|null $originalName */
        $originalName = $data['original_name'] ?? null;
        /** @var string $format */
        $format = $data['format'] ?? 'csv';
        $limit = (int)($data['limit'] ?? 10);
        /** @var array<string, mixed> $options */
        $options = $data['options'] ?? [];

        if (!is_string($filePath) || $filePath === '' || !file_exists($filePath)) {
            return ['success' => false, 'error' => 'File not found'];
        }
        $originalName = is_string($originalName) ? $originalName : null;

        try {
            $importer = $this->facade->getImporter($format, $originalName ?? '');

            /** @psalm-suppress UndefinedClass Psalm incorrectly resolves namespace */
            if (!$importer->canImport($filePath, $originalName)) {
                return ['success' => false, 'error' => 'Invalid file format'];
            }

            /** @psalm-suppress UndefinedClass Psalm incorrectly resolves namespace */
            $entries = $importer->preview($filePath, $limit, $options);

            $result = [
                'success' => true,
                'entries' => $entries,
            ];

            // Add format-specific metadata
            /** @psalm-suppress UndefinedClass Psalm incorrectly resolves namespace */
            if ($format === 'csv' && $importer instanceof CsvImporter) {
                $delimiter = $importer->detectDelimiter($filePath);
                $headers = $importer->detectHeaders($filePath, $delimiter);
                $result['structure'] = [
                    'delimiter' => $delimiter,
                    'headers' => $headers,
                    'suggested_mapping' => $importer->suggestColumnMap($headers),
                ];
            } elseif ($format === 'json' && $importer instanceof JsonImporter) {
                $result['structure'] = $importer->detectStructure($filePath);
            } elseif ($format === 'stardict' && $importer instanceof StarDictImporter) {
                $result['structure'] = [
                    'info' => $importer->getInfo($filePath),
                ];
            }

            return $result;
        } catch (RuntimeException $e) {
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    /**
     * Clear all entries from a dictionary (for re-import).
     *
     * @param int $dictId Dictionary ID
     *
     * @return array{success: bool, deleted?: int, error?: string}
     */
    public function clearEntries(int $dictId): array
    {
        $dictionary = $this->facade->getById($dictId);
        if ($dictionary === null) {
            return ['success' => false, 'error' => 'Dictionary not found'];
        }

        $deleted = $this->dictService->clearEntries($dictId);

        return [
            'success' => true,
            'deleted' => $deleted,
        ];
    }

    // =========================================================================
    // Entries
    // =========================================================================

    /**
     * Get entries for a dictionary (paginated).
     *
     * @param int   $dictId Dictionary ID
     * @param array $params Pagination params:
     *                      - page: int (default: 1)
     *                      - per_page: int (default: 50)
     *
     * @return array{entries?: array, pagination?: array, error?: string}
     */
    public function getEntries(int $dictId, array $params = []): array
    {
        $dictionary = $this->facade->getById($dictId);
        if ($dictionary === null) {
            return ['error' => 'Dictionary not found'];
        }

        $page = max(1, (int)($params['page'] ?? 1));
        $perPage = max(1, min(100, (int)($params['per_page'] ?? 50)));

        $result = $this->facade->getEntries($dictId, $page, $perPage);

        return [
            'entries' => $result['entries'],
            'pagination' => [
                'page' => $result['page'],
                'per_page' => $result['perPage'],
                'total' => $result['total'],
                'total_pages' => (int)ceil($result['total'] / $result['perPage']),
            ],
        ];
    }

    /**
     * Add a single entry to a dictionary.
     *
     * @param int   $dictId Dictionary ID
     * @param array $data   Entry data:
     *                      - term: string (required)
     *                      - definition: string (required)
     *                      - reading: string (optional)
     *                      - pos: string (optional)
     *
     * @return array{success: bool, entry_id?: int, error?: string}
     */
    public function addEntry(int $dictId, array $data): array
    {
        $dictionary = $this->facade->getById($dictId);
        if ($dictionary === null) {
            return ['success' => false, 'error' => 'Dictionary not found'];
        }

        $term = trim((string) ($data['term'] ?? ''));
        $definition = trim((string) ($data['definition'] ?? ''));
        $reading = isset($data['reading']) ? trim((string) $data['reading']) : null;
        $pos = isset($data['pos']) ? trim((string) $data['pos']) : null;

        if (empty($term)) {
            return ['success' => false, 'error' => 'Term is required'];
        }

        if (empty($definition)) {
            return ['success' => false, 'error' => 'Definition is required'];
        }

        $entryId = $this->dictService->addEntry($dictId, $term, $definition, $reading, $pos);

        return [
            'success' => true,
            'entry_id' => $entryId,
        ];
    }

    /**
     * Update an entry.
     *
     * @param int   $entryId Entry ID
     * @param array $data    Entry data
     *
     * @return array{success: bool, error?: string}
     */
    public function updateEntry(int $entryId, array $data): array
    {
        $term = trim((string) ($data['term'] ?? ''));
        $definition = trim((string) ($data['definition'] ?? ''));
        $reading = isset($data['reading']) ? trim((string) $data['reading']) : null;
        $pos = isset($data['pos']) ? trim((string) $data['pos']) : null;

        if (empty($term)) {
            return ['success' => false, 'error' => 'Term is required'];
        }

        if (empty($definition)) {
            return ['success' => false, 'error' => 'Definition is required'];
        }

        $updated = $this->dictService->updateEntry($entryId, $term, $definition, $reading, $pos);

        if (!$updated) {
            return ['success' => false, 'error' => 'Entry not found'];
        }

        return ['success' => true];
    }

    /**
     * Delete an entry.
     *
     * @param int $entryId Entry ID
     *
     * @return array{success: bool, error?: string}
     */
    public function deleteEntry(int $entryId): array
    {
        $deleted = $this->dictService->deleteEntry($entryId);

        if (!$deleted) {
            return ['success' => false, 'error' => 'Entry not found'];
        }

        return ['success' => true];
    }

    // =========================================================================
    // API Response Formatters
    // =========================================================================

    /**
     * Format response for getting dictionaries.
     *
     * @param int $langId Language ID
     *
     * @return array
     */
    public function formatGetDictionaries(int $langId): array
    {
        return $this->getDictionaries($langId);
    }

    /**
     * Format response for getting a single dictionary.
     *
     * @param int $dictId Dictionary ID
     *
     * @return array
     */
    public function formatGetDictionary(int $dictId): array
    {
        return $this->getDictionary($dictId);
    }

    /**
     * Format response for creating a dictionary.
     *
     * @param array $data Dictionary data
     *
     * @return array
     */
    public function formatCreateDictionary(array $data): array
    {
        return $this->createDictionary($data);
    }

    /**
     * Format response for updating a dictionary.
     *
     * @param int   $dictId Dictionary ID
     * @param array $data   Dictionary data
     *
     * @return array
     */
    public function formatUpdateDictionary(int $dictId, array $data): array
    {
        return $this->updateDictionary($dictId, $data);
    }

    /**
     * Format response for deleting a dictionary.
     *
     * @param int $dictId Dictionary ID
     *
     * @return array
     */
    public function formatDeleteDictionary(int $dictId): array
    {
        return $this->deleteDictionary($dictId);
    }

    /**
     * Format response for term lookup.
     *
     * @param int    $langId Language ID
     * @param string $term   Term to look up
     *
     * @return array
     */
    public function formatLookup(int $langId, string $term): array
    {
        return $this->lookup($langId, $term);
    }

    /**
     * Format response for import.
     *
     * @param int   $dictId Dictionary ID
     * @param array $data   Import data
     *
     * @return array
     */
    public function formatImport(int $dictId, array $data): array
    {
        return $this->importFile($dictId, $data);
    }

    /**
     * Format response for preview.
     *
     * @param array $data Preview data
     *
     * @return array
     */
    public function formatPreview(array $data): array
    {
        return $this->previewFile($data);
    }

    /**
     * Format response for getting entries.
     *
     * @param int   $dictId Dictionary ID
     * @param array $params Pagination params
     *
     * @return array
     */
    public function formatGetEntries(int $dictId, array $params): array
    {
        return $this->getEntries($dictId, $params);
    }

    /**
     * Format response for adding an entry.
     *
     * @param int   $dictId Dictionary ID
     * @param array $data   Entry data
     *
     * @return array
     */
    public function formatAddEntry(int $dictId, array $data): array
    {
        return $this->addEntry($dictId, $data);
    }

    /**
     * Format response for clearing entries.
     *
     * @param int $dictId Dictionary ID
     *
     * @return array
     */
    public function formatClearEntries(int $dictId): array
    {
        return $this->clearEntries($dictId);
    }

    // =========================================================================
    // Routing
    // =========================================================================

    public function routeGet(array $fragments, array $params): JsonResponse
    {
        $frag1 = $this->frag($fragments, 1);
        $frag2 = $this->frag($fragments, 2);

        if ($frag1 === 'lookup') {
            $languageId = (int) ($params['language_id'] ?? 0);
            $term = (string) ($params['term'] ?? '');
            if ($languageId <= 0) {
                return Response::error('language_id is required', 400);
            }
            if ($term === '') {
                return Response::error('term is required', 400);
            }
            return Response::success($this->formatLookup($languageId, $term));
        }
        if ($frag1 === 'entries' && $frag2 !== '' && ctype_digit($frag2)) {
            return Response::success($this->formatGetEntries((int) $frag2, $params));
        }
        if ($frag1 !== '' && ctype_digit($frag1)) {
            return Response::success($this->formatGetDictionary((int) $frag1));
        }

        $languageId = (int) ($params['language_id'] ?? 0);
        if ($languageId <= 0) {
            return Response::error('language_id is required', 400);
        }
        return Response::success($this->formatGetDictionaries($languageId));
    }

    public function routePost(array $fragments, array $params): JsonResponse
    {
        $frag1 = $this->frag($fragments, 1);
        $frag2 = $this->frag($fragments, 2);

        if ($frag1 === 'import-curated') {
            return Response::success($this->importCurated($params));
        }
        if ($frag1 === 'preview') {
            return Response::success($this->formatPreview($params));
        }
        if ($frag1 === 'entries' && $frag2 !== '' && ctype_digit($frag2)) {
            return Response::success($this->formatAddEntry((int) $frag2, $params));
        }
        if ($frag1 !== '' && ctype_digit($frag1) && $frag2 === 'import') {
            return Response::success($this->formatImport((int) $frag1, $params));
        }
        if ($frag1 !== '' && ctype_digit($frag1) && $frag2 === 'clear') {
            return Response::success($this->formatClearEntries((int) $frag1));
        }
        if ($frag1 === '') {
            return Response::success($this->formatCreateDictionary($params));
        }

        return Response::error('Endpoint Not Found: local-dictionaries/' . $frag1, 404);
    }

    public function routePut(array $fragments, array $params): JsonResponse
    {
        $frag1 = $this->frag($fragments, 1);
        $frag2 = $this->frag($fragments, 2);

        if ($frag1 !== '' && ctype_digit($frag1) && $frag2 === '') {
            return Response::success($this->formatUpdateDictionary((int) $frag1, $params));
        }

        return Response::error('Dictionary ID (Integer) Expected', 404);
    }

    public function routeDelete(array $fragments, array $params): JsonResponse
    {
        $frag1 = $this->frag($fragments, 1);

        if ($frag1 !== '' && ctype_digit($frag1)) {
            return Response::success($this->formatDeleteDictionary((int) $frag1));
        }

        return Response::error('Dictionary ID (Integer) Expected', 404);
    }

    // =========================================================================
    // Private Helpers
    // =========================================================================

    /**
     * Format a LocalDictionary entity for API response.
     *
     * @param \Lwt\Modules\Dictionary\Domain\LocalDictionary $dictionary Dictionary entity
     *
     * @return array Formatted dictionary data
     */
    private function formatDictionary(\Lwt\Modules\Dictionary\Domain\LocalDictionary $dictionary): array
    {
        return [
            'id' => $dictionary->id(),
            'language_id' => $dictionary->languageId(),
            'name' => $dictionary->name(),
            'description' => $dictionary->description(),
            'source_format' => $dictionary->sourceFormat(),
            'entry_count' => $dictionary->entryCount(),
            'priority' => $dictionary->priority(),
            'enabled' => $dictionary->isEnabled(),
            'created' => $dictionary->created()->format('Y-m-d H:i:s'),
        ];
    }
}
