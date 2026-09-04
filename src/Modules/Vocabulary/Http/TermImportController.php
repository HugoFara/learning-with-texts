<?php

/**
 * Term Import Controller
 *
 * PHP version 8.2
 *
 * @category Lwt
 * @package  Lwt\Modules\Vocabulary\Http
 * @author   HugoFara <git@hugofara.net>
 * @license  Unlicense <http://unlicense.org/>
 * @link     https://hugofara.github.io/lwt/developer/api
 * @since    3.0.0
 */

declare(strict_types=1);

namespace Lwt\Modules\Vocabulary\Http;

use Lwt\Shared\Infrastructure\Http\InputValidator;
use Lwt\Shared\Infrastructure\Database\Settings;
use Lwt\Modules\Vocabulary\Application\Services\WordUploadService;
use Lwt\Modules\Vocabulary\Application\Services\FrequencyLanguageMap;
use Lwt\Modules\Language\Application\LanguageFacade;
use Lwt\Modules\Dictionary\Application\DictionaryFacade;
use Lwt\Modules\Dictionary\Application\Services\DictionaryImportFileResolver;
use Lwt\Shared\UI\Helpers\PageLayoutHelper;
use Lwt\Shared\UI\Helpers\FormHelper;
use Lwt\Shared\UI\Helpers\NotificationHelper;
use RuntimeException;

/**
 * Controller for bulk translate and file import operations.
 *
 * Handles:
 * - /word/upload - Import terms from file
 * - /word/bulk-translate - Bulk translate terms
 *
 * @since 3.0.0
 */
class TermImportController extends VocabularyBaseController
{
    /**
     * Language facade.
     */
    private LanguageFacade $languageFacade;

    /**
     * Dictionary facade.
     *
     * @psalm-suppress PropertyNotSetInConstructor Set conditionally when dictionary features are available
     */
    private DictionaryFacade $dictionaryFacade;

    /**
     * Constructor.
     *
     * @param LanguageFacade|null   $languageFacade   Language facade
     * @param DictionaryFacade|null $dictionaryFacade Dictionary facade
     */
    public function __construct(
        ?LanguageFacade $languageFacade = null,
        ?DictionaryFacade $dictionaryFacade = null
    ) {
        parent::__construct();
        $this->languageFacade = $languageFacade ?? new LanguageFacade();
        if ($dictionaryFacade !== null) {
            $this->dictionaryFacade = $dictionaryFacade;
        }
    }

    /**
     * Bulk translate words.
     *
     * @param array<string, string> $params Route parameters
     *
     * @return void
     */
    public function bulkTranslate(array $params): void
    {
        $tid = InputValidator::getInt('tid', 0) ?? 0;
        $pos = InputValidator::getInt('offset');

        // Saving goes to POST /api/v1/terms/bulk and the next batch is a plain
        // GET, so this only ever renders a batch of terms.
        PageLayoutHelper::renderPageStartNobody(__('vocabulary.bulk.page_title'));

        if ($pos !== null) {
            $sl = InputValidator::getString('sl');
            $tl = InputValidator::getString('tl');
            $this->displayBulkTranslateForm($tid, $sl !== '' ? $sl : null, $tl !== '' ? $tl : null, $pos);
        }

        PageLayoutHelper::renderPageEnd();
    }

    /**
     * Display the bulk translate form.
     *
     * @param int         $tid Text ID
     * @param string|null $sl  Source language code
     * @param string|null $tl  Target language code
     * @param int         $pos Offset position
     *
     * @return void
     */
    private function displayBulkTranslateForm(int $tid, ?string $sl, ?string $tl, int $pos): void
    {
        // The rows come from GET /api/v1/terms/unknown-for-translate; only the
        // page size travels with the page. The setting counts the extra
        // look-ahead row the old server-side loop used, so drop it again.
        $this->render('bulk_translate_form', [
            'tid' => $tid,
            'sl' => $sl,
            'tl' => $tl,
            'pos' => $pos,
            'dictionaries' => $this->getContextService()->getLanguageDictionaries($tid),
            'limit' => max(1, (int) Settings::getWithDefault('set-ggl-translation-per-page')),
        ]);
    }

    /**
     * Upload words from file.
     *
     * @param array<string, string> $params Route parameters
     *
     * @return void
     */
    public function upload(array $params): void
    {
        PageLayoutHelper::renderPageStart(__('vocabulary.upload.page_title'), true);

        $op = InputValidator::getString('op');
        if ($op === 'Import') {
            $this->handleUploadImport();
        } elseif ($op === 'ImportDictionary') {
            $this->handleDictionaryImport();
        } else {
            $this->displayUploadForm();
        }

        PageLayoutHelper::renderPageEnd();
    }

    /**
     * Display the word upload form.
     *
     * @return void
     */
    private function displayUploadForm(): void
    {
        $activeTab = InputValidator::getString('tab') ?: 'frequency';
        // Map legacy tab values
        if ($activeTab === 'file' || $activeTab === 'text' || $activeTab === 'paste') {
            $activeTab = 'manual';
        }
        $this->render('upload_form', $this->uploadFormData($activeTab));
    }

    /**
     * Everything the upload form needs to render.
     *
     * Built here rather than twice, because a dictionary import re-displays
     * the same form once it has reported what it did. That second copy used
     * to take its language *name* from the current-language setting while
     * taking its language *id* from the submitted form, so a dictionary
     * imported into any other language redisplayed the form labelled with one
     * language and wired to another. One language resolves both here.
     *
     * @param string   $activeTab Tab the form should open on
     * @param int|null $langId    Language in context; the current one if null
     *
     * @return array<string, mixed>
     */
    private function uploadFormData(string $activeTab, ?int $langId = null): array
    {
        if ($langId === null) {
            $currentLanguage = Settings::get('currentlanguage');
            $langId = $currentLanguage !== '' ? (int) $currentLanguage : 0;
        }

        $currentLanguageName = '';
        $isFrequencyAvailable = false;
        if ($langId > 0) {
            $currentLanguageName = $this->languageFacade->getLanguageName($langId);
            $isFrequencyAvailable = FrequencyLanguageMap::isSupported($currentLanguageName);
        }

        return [
            'currentLanguageName' => $currentLanguageName,
            'isFrequencyAvailable' => $isFrequencyAvailable,
            'langId' => $langId,
            'languages' => $this->languageFacade->getLanguagesForSelect(),
            'activeTab' => $activeTab,
            'curatedDictionaries' => $this->loadCuratedDictionaries(),
            'csrfToken' => FormHelper::csrfToken(),
            'importUrl' => $langId > 0 ? '/languages/' . $langId . '/starter-vocab/import' : '',
            'enrichUrl' => $langId > 0 ? '/languages/' . $langId . '/starter-vocab/enrich' : '',
        ];
    }

    /**
     * Load curated dictionaries from the JSON registry.
     *
     * @return list<array<string, mixed>>
     */
    private function loadCuratedDictionaries(): array
    {
        $path = dirname(__DIR__, 4) . '/data/curated_dictionaries.json';
        if (!file_exists($path)) {
            return [];
        }
        $json = file_get_contents($path);
        if ($json === false) {
            return [];
        }
        $data = json_decode($json, true);
        if (!is_array($data) || !isset($data['dictionaries'])) {
            return [];
        }
        /** @var list<array<string, mixed>> */
        $dictionaries = $data['dictionaries'];
        return $dictionaries;
    }

    /**
     * Handle the word import operation.
     *
     * @return void
     */
    private function handleUploadImport(): void
    {
        $uploadService = $this->getUploadService();
        $tabType = InputValidator::getString("Tab");
        if ($tabType === '') {
            $tabType = 'c';
        }
        $langId = InputValidator::getInt("LgID", 0) ?? 0;

        if ($langId === 0) {
            NotificationHelper::render(__('vocabulary.upload.errors.no_language'));
            return;
        }

        $langData = $uploadService->getLanguageData($langId);
        if ($langData === null) {
            NotificationHelper::render(__('vocabulary.upload.errors.invalid_language'));
            return;
        }

        $removeSpaces = (bool) $langData['LgRemoveSpaces'];

        // Parse column mapping
        $columns = [
            1 => InputValidator::getString("Col1"),
            2 => InputValidator::getString("Col2"),
            3 => InputValidator::getString("Col3"),
            4 => InputValidator::getString("Col4"),
            5 => InputValidator::getString("Col5"),
        ];
        $columns = array_unique($columns);

        $parsed = $uploadService->parseColumnMapping($columns, $removeSpaces);
        /** @var array<int, string> $col */
        $col = $parsed['columns'];
        /** @var array{txt: int, tr: int, ro: int, se: int, tl: int} $fields */
        $fields = $parsed['fields'];

        // Check for file upload vs text input
        $uploadedFile = InputValidator::getUploadedFile('thefile');

        // Get or create the input file
        $uploadText = InputValidator::getString("Upload");
        $createdTempFile = false;
        if ($uploadedFile !== null) {
            $fileName = $uploadedFile["tmp_name"];
        } else {
            if ($uploadText === '') {
                NotificationHelper::render(__('vocabulary.upload.errors.no_data'));
                return;
            }
            $fileName = $uploadService->createTempFile($uploadText);
            $createdTempFile = true;
        }

        try {
            $ignoreFirst = InputValidator::getString("IgnFirstLine") === '1';
            $overwrite = InputValidator::getInt("Over", 0) ?? 0;
            $status = InputValidator::getInt("WoStatus", 1) ?? 1;
            $translDelim = InputValidator::getString("transl_delim");

            // Get last update timestamp before import
            $lastUpdate = $uploadService->getLastWordUpdate() ?? '';

            if ($fields["txt"] > 0) {
                // Import terms
                $this->importTerms(
                    $uploadService,
                    $langId,
                    $fields,
                    $col,
                    $tabType,
                    $fileName,
                    $status,
                    $overwrite,
                    $ignoreFirst,
                    $translDelim,
                    $lastUpdate
                );

                // Display results. The view reads $rtl as a boolean -- it
                // used to be handed 1/0, which its own assertion rejected.
                $this->render('upload_result', [
                    'lastUpdate' => $lastUpdate,
                    'rtl' => $uploadService->isRightToLeft($langId),
                    'recno' => $uploadService->countImportedTerms($lastUpdate),
                ]);
            } elseif ($fields["tl"] > 0) {
                // Import tags only
                $uploadService->importTagsOnly(['tl' => $fields['tl']], $tabType, $fileName, $ignoreFirst);
                NotificationHelper::render(__('vocabulary.upload.tags_imported'), false);
            } else {
                NotificationHelper::render(__('vocabulary.upload.errors.no_term_column'));
            }
        } finally {
            // Clean up temp file if we created it
            if ($createdTempFile && file_exists($fileName)) {
                unlink($fileName);
            }
        }
    }

    /**
     * Handle dictionary file import.
     *
     * @return void
     */
    private function handleDictionaryImport(): void
    {
        $langId = InputValidator::getInt("LgID", 0) ?? 0;
        if ($langId === 0) {
            NotificationHelper::render(__('vocabulary.upload.errors.no_language'));
            return;
        }

        $format = InputValidator::getString('dict_format') ?: 'csv';
        $dictName = InputValidator::getString('dict_name');

        $uploadedFile = InputValidator::getUploadedFile('dict_file');
        if ($uploadedFile === null) {
            NotificationHelper::render(__('vocabulary.upload.errors.no_file'));
            return;
        }

        if (empty($dictName)) {
            $dictName = pathinfo($uploadedFile['name'], PATHINFO_FILENAME) ?: 'Imported Dictionary';
        }

        $resolver = new DictionaryImportFileResolver();

        try {
            $resolved = $resolver->resolve($uploadedFile['tmp_name'], $uploadedFile['name'], $format);
            $importPath = $resolved['path'];
            $importName = $resolved['name'];

            $importer = $this->dictionaryFacade->getImporter($format, $importName);

            if (!$importer->canImport($importPath, $importName)) {
                NotificationHelper::render(__('vocabulary.upload.errors.invalid_format'));
                return;
            }

            // Build import options
            $options = $this->getDictImportOptions($format);

            $dictId = $this->dictionaryFacade->create($langId, $dictName, $format);
            $entries = $importer->parse($importPath, $options);
            $importResult = $this->dictionaryFacade->addEntriesBatch($dictId, $entries);
            $count = $importResult['added'];

            // Create vocabulary terms (status 1) from dictionary entries
            $vocabCreated = $this->dictionaryFacade->createVocabularyFromEntries($dictId, $langId);

            // Auto-enable local dict mode if currently online-only
            $this->dictionaryFacade->autoEnableLocalDictMode($langId);

            $this->render('dictionary_import_result', [
                'dictName' => $dictName,
                'entryCount' => $count,
                'vocabCreated' => $vocabCreated,
                'skipped' => $importResult['skipped'],
            ]);
        } catch (RuntimeException $e) {
            NotificationHelper::render(
                __('vocabulary.upload.errors.dictionary_failed', ['message' => $e->getMessage()])
            );
            return;
        } finally {
            $resolver->cleanup();
        }

        // Re-display the form with the manual tab active (dictionary file
        // sub-tab), so the result reads as a step in the same page.
        $this->render('upload_form', $this->uploadFormData('manual', $langId));
    }

    /**
     * Get dictionary import options from form parameters.
     *
     * @param string $format Import format
     *
     * @return array<string, mixed>
     */
    private function getDictImportOptions(string $format): array
    {
        $options = [];

        if ($format === 'csv' || $format === 'tsv') {
            $delimiter = InputValidator::getString('dict_delimiter') ?: ',';
            if ($delimiter === 'tab') {
                $delimiter = "\t";
            }
            $options['delimiter'] = $delimiter;
            $options['hasHeader'] = InputValidator::getString('dict_has_header') !== 'no';

            $termCol = InputValidator::getInt('dict_term_column');
            $defCol = InputValidator::getInt('dict_definition_column');

            $options['columnMap'] = [
                'term' => $termCol ?? 0,
                'definition' => $defCol ?? 1,
            ];
        }

        return $options;
    }

    /**
     * Import terms from the uploaded file.
     *
     * @param WordUploadService       $uploadService  The upload service
     * @param int                     $langId         Language ID
     * @param array{txt: int, tr: int, ro: int, se: int, tl: int} $fields Field indexes
     * @param array<int, string>      $col            Column mapping
     * @param string                  $tabType        Tab type (c, t, h)
     * @param string                  $fileName       Path to input file
     * @param int                     $status         Word status
     * @param int                     $overwrite      Overwrite mode
     * @param bool                    $ignoreFirst    Ignore first line
     * @param string                  $translDelim    Translation delimiter
     * @param string                  $lastUpdate     Last update timestamp
     *
     * @return void
     */
    private function importTerms(
        WordUploadService $uploadService,
        int $langId,
        array $fields,
        array $col,
        string $tabType,
        string $fileName,
        int $status,
        int $overwrite,
        bool $ignoreFirst,
        string $translDelim,
        string $lastUpdate
    ): void {
        $columnsClause = '(' . rtrim(implode(',', $col), ',') . ')';
        $delimiter = $uploadService->getSqlDelimiter($tabType);

        // Use simple import for no tags and no overwrite, complete import otherwise
        if ($fields["tl"] == 0 && $overwrite == 0) {
            $uploadService->importSimple(
                $langId,
                $fields,
                $columnsClause,
                $delimiter,
                $fileName,
                $status,
                $ignoreFirst
            );
        } else {
            $uploadService->importComplete(
                $langId,
                $fields,
                $columnsClause,
                $delimiter,
                $fileName,
                $status,
                $overwrite,
                $ignoreFirst,
                $translDelim,
                $tabType
            );
        }

        // Post-import processing
        \Lwt\Shared\Infrastructure\Database\Maintenance::initWordCount();
        $uploadService->linkWordsToTextItems();
        $uploadService->handleMultiwords($langId, $lastUpdate);
    }
}
