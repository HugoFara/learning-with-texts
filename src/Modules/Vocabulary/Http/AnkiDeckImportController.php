<?php

declare(strict_types=1);

namespace Lwt\Modules\Vocabulary\Http;

use Lwt\Modules\Language\Application\LanguageFacade;
use Lwt\Modules\Vocabulary\Application\Services\Anki\AnkiDeckImportService;
use Lwt\Modules\Vocabulary\Application\Services\Anki\DeckImportResult;
use Lwt\Modules\Vocabulary\Application\Services\Anki\DeckImportSettings;
use Lwt\Modules\Vocabulary\Infrastructure\Anki\ForeignApkgReader;
use Lwt\Modules\Vocabulary\Infrastructure\Anki\ForeignNotetype;
use Lwt\Shared\Infrastructure\Http\InputValidator;
use Lwt\Shared\UI\Helpers\PageLayoutHelper;
use RuntimeException;
use Throwable;

/**
 * Import a deck built in Anki as new LWT terms (issue #228).
 *
 * Two steps, because LWT cannot guess any of the mapping: an .apkg carries no
 * language, and field names in a shared deck are arbitrary.
 *
 *   GET  /vocabulary/anki-deck/import   upload form
 *   POST /vocabulary/anki-deck/import   upload -> configure, or configure -> import
 *
 * The uploaded file is parked in a temp path between the two steps, keyed by a
 * token held in the session so the second request cannot be pointed at an
 * arbitrary path.
 */
class AnkiDeckImportController extends VocabularyBaseController
{
    private const SESSION_KEY = 'lwt_anki_deck_import';

    private LanguageFacade $languageFacade;
    private ForeignApkgReader $reader;
    private ?AnkiDeckImportService $importService;

    public function __construct(
        ?LanguageFacade $languageFacade = null,
        ?ForeignApkgReader $reader = null,
        ?AnkiDeckImportService $importService = null,
    ) {
        parent::__construct();
        $this->languageFacade = $languageFacade ?? new LanguageFacade();
        $this->reader = $reader ?? new ForeignApkgReader();
        $this->importService = $importService;
    }

    /**
     * @param array<string, string> $params Route params (unused).
     */
    public function index(array $params): void
    {
        PageLayoutHelper::renderPageStart(__('vocabulary.anki.deck.title'), true);

        try {
            if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
                $this->renderUploadForm(null);
            } elseif (InputValidator::getString('step') === 'import') {
                $this->handleImport();
            } else {
                $this->handleUpload();
            }
        } catch (Throwable $e) {
            $this->renderUploadForm($e->getMessage());
        }

        PageLayoutHelper::renderPageEnd();
    }

    /**
     * Step 1: accept the upload, park it, show the mapping form.
     */
    private function handleUpload(): void
    {
        $file = InputValidator::getUploadedFile('apkg');
        if ($file === null) {
            $this->renderUploadForm(__('vocabulary.anki.deck.error_no_file'));
            return;
        }

        if (!str_ends_with(strtolower($file['name']), '.apkg')) {
            $this->renderUploadForm(__('vocabulary.anki.deck.error_not_apkg'));
            return;
        }

        $parked = tempnam(sys_get_temp_dir(), 'lwt_deck_');
        if ($parked === false) {
            $this->renderUploadForm(__('vocabulary.anki.deck.error_store_failed'));
            return;
        }
        if (!move_uploaded_file($file['tmp_name'], $parked)) {
            // move_uploaded_file only accepts a genuine HTTP upload; fall back
            // to a copy so the flow stays exercisable outside a web request.
            if (!copy($file['tmp_name'], $parked)) {
                @unlink($parked);
                $this->renderUploadForm(__('vocabulary.anki.deck.error_store_failed'));
                return;
            }
        }

        $notetypes = $this->reader->notetypes($parked);
        if ($notetypes === []) {
            @unlink($parked);
            $this->renderUploadForm(__('vocabulary.anki.deck.error_no_notetypes'));
            return;
        }

        $this->rememberParkedFile($parked);
        $this->renderMappingForm($notetypes, null);
    }

    /**
     * Step 2: apply the chosen mapping.
     */
    private function handleImport(): void
    {
        $parked = $this->parkedFile();
        if ($parked === null) {
            $this->renderUploadForm(__('vocabulary.anki.deck.error_expired'));
            return;
        }

        $notetypes = $this->reader->notetypes($parked);

        try {
            $settings = new DeckImportSettings(
                notetypeId: InputValidator::getInt('notetype') ?? 0,
                termField: InputValidator::getString('term_field'),
                translationField: $this->optionalField('translation_field'),
                languageId: InputValidator::getInt('language') ?? 0,
                deriveStatus: InputValidator::getString('status_mode') !== 'fixed',
                fixedStatus: InputValidator::getInt('fixed_status') ?? 1,
                importTags: InputValidator::getString('import_tags') !== '',
            );
        } catch (\InvalidArgumentException $e) {
            $this->renderMappingForm($notetypes, $e->getMessage());
            return;
        }

        $result = $this->importSvc()->import($parked, $settings);

        $this->forgetParkedFile();
        $this->renderSummary($result, $settings);
    }

    private function optionalField(string $key): ?string
    {
        $value = InputValidator::getString($key);

        return $value === '' ? null : $value;
    }

    /**
     * Step 1's form.
     *
     * @param string|null $error Message to show above it, if any
     */
    private function renderUploadForm(?string $error): void
    {
        $this->render('anki_deck_upload', ['error' => $error]);
    }

    /**
     * Step 2's form: which field is the word, and what language is this.
     *
     * The field pickers offer every field name across every note type rather
     * than only the selected one's, because the note type is chosen in the
     * same form -- the two selects cannot see each other server-side.
     *
     * @param list<ForeignNotetype> $notetypes Note types found in the file
     * @param string|null           $error     Message to show above the form
     */
    private function renderMappingForm(array $notetypes, ?string $error): void
    {
        $allFields = [];
        foreach ($notetypes as $notetype) {
            foreach ($notetype->fields as $field) {
                $allFields[$field] = true;
            }
        }

        $this->render('anki_deck_mapping', [
            'notetypes' => $notetypes,
            'fieldNames' => array_keys($allFields),
            'languages' => $this->languageFacade->getAllLanguages(),
            'matureDays' => DeckImportSettings::MATURE_INTERVAL_DAYS,
            'error' => $error,
        ]);
    }

    /**
     * What the import did.
     */
    private function renderSummary(DeckImportResult $result, DeckImportSettings $settings): void
    {
        $this->render('anki_deck_result', [
            'result' => $result,
            'languageId' => $settings->languageId,
        ]);
    }

    private function rememberParkedFile(string $path): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            $_SESSION[self::SESSION_KEY] = $path;
        }
    }

    /**
     * The parked upload for this session, if it is still there.
     *
     * Reading the path from the session rather than the request is what stops
     * step 2 being pointed at an arbitrary file on disk.
     */
    private function parkedFile(): ?string
    {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            return null;
        }

        $path = $_SESSION[self::SESSION_KEY] ?? null;
        if (!is_string($path) || !is_file($path)) {
            return null;
        }

        return $path;
    }

    private function forgetParkedFile(): void
    {
        $path = $this->parkedFile();
        if ($path !== null) {
            @unlink($path);
        }
        unset($_SESSION[self::SESSION_KEY]);
    }

    private function importSvc(): AnkiDeckImportService
    {
        return $this->importService ??= AnkiDeckImportService::default();
    }
}
