<?php

/**
 * Dictionary Controller
 *
 * PHP version 8.1
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

use Lwt\Shared\Http\BaseController;
use Lwt\Modules\Dictionary\Application\DictionaryFacade;
use Lwt\Modules\Dictionary\Application\Services\DictionaryImportFileResolver;
use Lwt\Modules\Language\Application\LanguageFacade;
use Lwt\Shared\Infrastructure\Database\Validation;
use Lwt\Shared\Infrastructure\Http\InputValidator;
use Lwt\Shared\UI\Helpers\PageLayoutHelper;
use RuntimeException;

/**
 * Controller for local dictionary management.
 *
 * Handles:
 * - Dictionary listing and browsing
 * - Dictionary creation and deletion
 * - Import wizard for dictionary files
 *
 * @since 3.0.0
 */
class DictionaryController extends BaseController
{
    private DictionaryFacade $dictionaryFacade;
    private LanguageFacade $languageFacade;

    /**
     * Create a new DictionaryController.
     *
     * @param DictionaryFacade $dictionaryFacade Dictionary facade
     * @param LanguageFacade   $languageFacade   Language facade
     */
    public function __construct(DictionaryFacade $dictionaryFacade, LanguageFacade $languageFacade)
    {
        parent::__construct();
        $this->dictionaryFacade = $dictionaryFacade;
        $this->languageFacade = $languageFacade;
    }

    /**
     * Index page - list dictionaries for a language.
     *
     * @param array $params Route parameters (may contain 'id' from RESTful route)
     *
     * @return void
     */
    public function index(array $params): void
    {
        // Support both /languages/{id}/dictionaries and /dictionaries?lang=
        $langId = isset($params['id'])
            ? (int)$params['id']
            : (int)Validation::language(InputValidator::getStringWithDb('lang', 'currentlanguage'));

        $langName = $this->languageFacade->getLanguageName($langId);
        PageLayoutHelper::renderPageStart($langName . ' - Local Dictionaries', true);

        // The dictionary table is fetched by the dictionaryList component from
        // GET /local-dictionaries; only the lookup mode is still rendered here.
        $localDictMode = $this->dictionaryFacade->getLocalDictMode($langId);

        // Get languages for dropdown
        $languages = $this->languageFacade->getLanguagesForSelect();

        // Extract flash messages from query string
        $message = $this->param('message');
        $error = $this->param('error');

        // Include view
        include __DIR__ . '/../Views/index.php';

        PageLayoutHelper::renderPageEnd();
    }

    /**
     * Import wizard - show import form or process upload.
     *
     * @param array $params Route parameters (may contain 'id' from RESTful route)
     *
     * @return void
     */
    public function import(array $params): void
    {
        // Support both /languages/{id}/dictionaries/import and /dictionaries/import?lang=
        $langId = isset($params['id'])
            ? (int)$params['id']
            : (int)Validation::language(InputValidator::getStringWithDb('lang', 'currentlanguage'));
        $dictId = $this->paramInt('dict_id');

        $langName = $this->languageFacade->getLanguageName($langId);
        PageLayoutHelper::renderPageStart($langName . ' - Import Dictionary', true);

        // Get dictionary if specified
        $dictionary = null;
        if ($dictId !== null) {
            $dictionary = $this->dictionaryFacade->getById($dictId);
        }

        // Get or create dictionaries for this language
        $dictionaries = $this->dictionaryFacade->getAllForLanguage($langId);

        // Extract flash error from query string
        $error = $this->param('error');

        // Include view
        include __DIR__ . '/../Views/import.php';

        PageLayoutHelper::renderPageEnd();
    }

    /**
     * Process import form submission.
     *
     * @param array $params Route parameters
     *
     * @return void
     */
    public function processImport(array $params): void
    {
        // Support both route parameter and form field
        $langId = isset($params['id'])
            ? (int)$params['id']
            : ($this->paramInt('lang_id') ?? 0);
        $dictId = $this->paramInt('dict_id');
        $format = $this->param('format', 'csv');
        $dictName = $this->param('dict_name');

        if ($langId <= 0) {
            $this->redirect('/dictionaries?error=invalid_language');
        }

        // Create new dictionary if needed
        if ($dictId === null || $dictId <= 0) {
            if (empty($dictName)) {
                $dictName = 'Imported Dictionary ' . date('Y-m-d H:i');
            }
            $dictId = $this->dictionaryFacade->create($langId, $dictName, $format);
        }

        // Process uploaded file
        $uploadedFile = InputValidator::getUploadedFile('file');
        if ($uploadedFile === null) {
            $this->redirect("/dictionaries/import?lang=$langId&dict_id=$dictId&error=upload_failed");
            return;
        }

        $resolver = new DictionaryImportFileResolver();

        try {
            $resolved = $resolver->resolve($uploadedFile['tmp_name'], $uploadedFile['name'], $format);
            $importPath = $resolved['path'];
            $importName = $resolved['name'];

            $importer = $this->dictionaryFacade->getImporter($format, $importName);

            /** @psalm-suppress UndefinedClass Psalm incorrectly resolves namespace */
            if (!$importer->canImport($importPath, $importName)) {
                $this->redirect("/dictionaries/import?lang=$langId&dict_id=$dictId&error=invalid_file");
                return;
            }

            // Get import options from form
            $options = $this->getImportOptions($format);

            // Perform import
            /** @psalm-suppress UndefinedClass Psalm incorrectly resolves namespace */
            $entries = $importer->parse($importPath, $options);
            $result = $this->dictionaryFacade->addEntriesBatch($dictId, $entries);
            $count = $result['added'];

            $this->redirect("/dictionaries?lang=$langId&message=imported_$count");
        } catch (RuntimeException $e) {
            $errorMsg = urlencode($e->getMessage());
            $this->redirect("/dictionaries/import?lang=$langId&dict_id=$dictId&error=$errorMsg");
        } finally {
            $resolver->cleanup();
        }
    }

    /**
     * Delete a dictionary.
     *
     * @param array $params Route parameters
     *
     * @return void
     */
    public function delete(array $params): void
    {
        $dictId = $this->paramInt('dict_id');
        $langId = $this->paramInt('lang_id') ?? 0;

        if ($dictId !== null && $dictId > 0) {
            $this->dictionaryFacade->delete($dictId);
        }

        $this->redirect("/dictionaries?lang=$langId&message=deleted");
    }

    /**
     * Preview file contents via AJAX.
     *
     * @param array $params Route parameters
     *
     * @return void
     */
    public function preview(array $params): void
    {
        header('Content-Type: application/json');

        $uploadedFile = InputValidator::getUploadedFile('file');
        if ($uploadedFile === null) {
            echo json_encode(['error' => 'No file uploaded']);
            return;
        }

        $format = $this->param('format', 'csv');
        $filePath = $uploadedFile['tmp_name'];
        $originalName = $uploadedFile['name'];

        try {
            $importer = $this->dictionaryFacade->getImporter($format, $originalName);

            if (!$importer->canImport($filePath, $originalName)) {
                echo json_encode(['error' => 'Invalid file format']);
                return;
            }

            $entries = $importer->preview($filePath, 10);

            $result = ['success' => true, 'entries' => $entries];

            // Add structure info for CSV
            if ($format === 'csv') {
                $csvImporter = $this->dictionaryFacade->getImporter('csv', '');
                if ($csvImporter instanceof \Lwt\Modules\Dictionary\Infrastructure\Import\CsvImporter) {
                    $delimiter = $csvImporter->detectDelimiter($filePath);
                    $headers = $csvImporter->detectHeaders($filePath, $delimiter);
                    $result['structure'] = [
                        'delimiter' => $delimiter,
                        'headers' => $headers,
                        'suggested_mapping' => $csvImporter->suggestColumnMap($headers),
                    ];
                }
            }

            echo json_encode($result);
        } catch (RuntimeException $e) {
            echo json_encode(['error' => $e->getMessage()]);
        }
    }

    /**
     * Get import options from form parameters.
     *
     * @param string $format Import format
     *
     * @return array<string, mixed>
     */
    private function getImportOptions(string $format): array
    {
        $options = [];

        if ($format === 'csv' || $format === 'tsv') {
            $delimiter = $this->param('delimiter', ',');
            if ($delimiter === 'tab') {
                $delimiter = "\t";
            }
            $options['delimiter'] = $delimiter;
            $options['hasHeader'] = $this->param('has_header', 'yes') === 'yes';

            // Column mapping
            $termCol = $this->paramInt('term_column');
            $defCol = $this->paramInt('definition_column');
            $readingCol = $this->paramInt('reading_column');
            $posCol = $this->paramInt('pos_column');

            $options['columnMap'] = [
                'term' => $termCol ?? 0,
                'definition' => $defCol ?? 1,
                'reading' => $readingCol,
                'pos' => $posCol,
            ];
        } elseif ($format === 'json') {
            // Field mapping for JSON
            $termField = $this->param('term_field');
            $defField = $this->param('definition_field');
            $readingField = $this->param('reading_field');
            $posField = $this->param('pos_field');

            if (!empty($termField) || !empty($defField)) {
                $options['fieldMap'] = [
                    'term' => !empty($termField) ? $termField : null,
                    'definition' => !empty($defField) ? $defField : null,
                    'reading' => !empty($readingField) ? $readingField : null,
                    'pos' => !empty($posField) ? $posField : null,
                ];
            }
        }

        return $options;
    }
}
