<?php

declare(strict_types=1);

namespace Lwt\Modules\Vocabulary\Http;

use Lwt\Modules\Language\Application\LanguageFacade;
use Lwt\Modules\Vocabulary\Application\Services\Anki\ApkgExportService;
use Lwt\Modules\Vocabulary\Application\Services\Anki\ApkgImportService;
use Lwt\Modules\Vocabulary\Application\Services\Anki\ImportResult;
use Lwt\Shared\Infrastructure\Database\Settings;
use Lwt\Shared\Infrastructure\Http\InputValidator;
use Lwt\Shared\UI\Helpers\FormHelper;
use Lwt\Shared\UI\Helpers\PageLayoutHelper;
use RuntimeException;

/**
 * HTTP entry points for round-trip Anki .apkg interop:
 *
 *   GET  /vocabulary/apkg/export?lang_id=N   stream a .apkg download
 *   GET  /vocabulary/apkg/import             render the upload form
 *   POST /vocabulary/apkg/import             accept upload + render summary
 *
 * The orchestration lives in ApkgExportService / ApkgImportService;
 * this controller is just request parsing + response shaping.
 */
class ApkgController extends VocabularyBaseController
{
    private LanguageFacade $languageFacade;
    private ?ApkgExportService $exportService;
    private ?ApkgImportService $importService;

    public function __construct(
        ?LanguageFacade $languageFacade = null,
        ?ApkgExportService $exportService = null,
        ?ApkgImportService $importService = null,
    ) {
        parent::__construct();
        $this->languageFacade = $languageFacade ?? new LanguageFacade();
        $this->exportService = $exportService;
        $this->importService = $importService;
    }

    /**
     * Export terms as a .apkg, streamed to the browser.
     *
     * Accepted parameters (any source — query string for GET, body for POST):
     *   - lang_id   int   target language; defaults to the current language
     *   - marked[]  int[] optional subset of WoIDs; empty/missing = whole language
     *
     * @param array<string, string> $params Route params (unused).
     */
    public function export(array $params): never
    {
        $langId = InputValidator::getPositiveInt('lang_id') ?? 0;
        if ($langId <= 0) {
            $current = Settings::get('currentlanguage');
            $langId = $current !== '' ? (int) $current : 0;
        }
        if ($langId <= 0) {
            throw new RuntimeException('lang_id parameter is required');
        }

        $marked = InputValidator::getIntArray('marked');
        $termIds = $marked !== [] ? $marked : null;

        $tmpPath = tempnam(sys_get_temp_dir(), 'lwt_apkg_dl_');
        if ($tmpPath === false) {
            throw new RuntimeException('Could not allocate temporary file for export');
        }

        try {
            $result = $this->exportSvc()->exportTerms($langId, $termIds, $tmpPath);
            $suffix = $termIds !== null ? '-selection' : '';
            $filename = sprintf(
                'lwt-%s%s-%s.apkg',
                $this->slugify($result->languageName),
                $suffix,
                date('Y-m-d')
            );
            $this->streamDownload($tmpPath, $filename);
        } finally {
            if (is_file($tmpPath)) {
                unlink($tmpPath);
            }
        }
    }

    /**
     * Render the import upload form (GET) or process the upload (POST).
     *
     * @param array<string, string> $params Route params (unused).
     */
    public function importForm(array $params): void
    {
        PageLayoutHelper::renderPageStart('Import Anki .apkg', true);

        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
            $this->handleImportSubmit();
        } else {
            $this->renderImportForm(null, null);
        }

        PageLayoutHelper::renderPageEnd();
    }

    private function handleImportSubmit(): void
    {
        $file = InputValidator::getUploadedFile('apkg');
        if ($file === null) {
            $this->renderImportForm('No file was uploaded.', null);
            return;
        }

        $name = $file['name'];
        if (!str_ends_with(strtolower($name), '.apkg')) {
            $this->renderImportForm('Only .apkg files are accepted.', null);
            return;
        }

        try {
            $result = $this->importSvc()->importApkg($file['tmp_name']);
        } catch (RuntimeException $e) {
            $this->renderImportForm('Import failed: ' . $e->getMessage(), null);
            return;
        }

        $this->renderImportForm(null, $result);
    }

    private function renderImportForm(?string $error, ?ImportResult $result): void
    {
        $csrfToken = FormHelper::csrfToken();

        echo '<h1>Import Anki .apkg</h1>';

        if ($error !== null) {
            echo '<div class="notification is-danger">'
                . htmlspecialchars($error, ENT_QUOTES, 'UTF-8')
                . '</div>';
        }

        if ($result !== null) {
            echo '<div class="notification is-success">'
                . '<p><strong>Import complete.</strong></p>'
                . '<ul>'
                . '<li>Notes read: ' . $result->totalNotes . '</li>'
                . '<li>Updated: ' . $result->updated . '</li>'
                . '<li>Unchanged: ' . $result->unchanged . '</li>'
                . '<li>Skipped (term not found): ' . $result->skippedMissing . '</li>'
                . '<li>Not created by LWT: ' . $result->skippedUnknown . '</li>'
                . '<li>Demoted to Ignored from suspended: ' . $result->statusSetToIgnored . '</li>'
                . '<li>Tag changes applied: ' . $result->tagsChanged . '</li>'
                . '<li>Terms rescheduled from Anki: ' . $result->termsRescheduled . '</li>'
                . '<li>Reviews replayed: ' . $result->reviewsApplied . '</li>'
                . '<li>Due dates set by hand in Anki: ' . $result->dueDatesMoved . '</li>'
                . '</ul></div>';

            // A count of notes this page could do nothing with is not an
            // answer -- the user still has words in the file that never
            // reached their vocabulary, and no idea where to take them. Say
            // where, and only when there are some.
            if ($result->skippedUnknown > 0) {
                echo '<div class="notification is-warning">'
                    . '<strong>' . $result->skippedUnknown . ' note'
                    . ($result->skippedUnknown === 1 ? ' was' : 's were')
                    . ' not created by LWT, so nothing here matched'
                    . ($result->skippedUnknown === 1 ? ' it' : ' them') . '.</strong> '
                    . 'This page only updates terms LWT exported in the first place. To bring '
                    . 'these in as new terms, use '
                    . '<a href="/vocabulary/anki-deck/import">Import an Anki deck</a>, which asks '
                    . 'which language they belong to.'
                    . '</div>';
            }
        }

        echo '<form method="post" enctype="multipart/form-data" action="/vocabulary/apkg/import">';
        echo '<input type="hidden" name="csrf_token" value="'
            . htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') . '">';
        echo '<div class="field">'
            . '<label class="label" for="apkg-file">Anki package (.apkg)</label>'
            . '<div class="control">'
            . '<input class="input" type="file" name="apkg" id="apkg-file"'
            . ' accept=".apkg" required>'
            . '</div></div>';
        echo '<div class="field"><div class="control">'
            . '<button class="button is-primary" type="submit">Import</button>'
            . '</div></div>';
        echo '</form>';

        echo '<p class="help mt-4">'
            . 'Notes from this file are matched to existing LWT terms by guid. '
            . 'Translations, romanizations, notes, and tags are updated; reviews you did in '
            . 'Anki are replayed into the term\'s schedule, and a due date you set by hand there '
            . 'is kept. Cards suspended in Anki demote learning-status terms to <em>Ignored</em>. '
            . 'Deleting a note in Anki does not delete the term here — an .apkg carries no '
            . 'record of deletions, so a term missing from the file is left alone rather than '
            . 'guessed at.'
            . '</p>';

        // Anki's export and import defaults both work against the round-trip
        // and both fail quietly, so the settings are named here in Anki's own
        // wording rather than described. Verified against Anki 26.08.
        //
        // The legacy-format half only applies where PHP has no zstd: with the
        // extension the compressed format reads fine, and telling people to
        // change a setting they do not need to change is how advice stops
        // being read.
        $needsLegacyExport = !function_exists('zstd_uncompress');
        echo '<div class="notification is-warning is-light mt-4">'
            . '<strong>Settings to check in Anki.</strong> '
            . ($needsLegacyExport
                ? 'When you export, switch on <em>Support older Anki versions (slower/larger '
                    . 'files)</em> — without it Anki writes a compressed collection this server '
                    . 'cannot read, because PHP here has no zstd extension — and keep '
                    . '<em>Include scheduling information</em> on, '
                : 'When you export, keep <em>Include scheduling information</em> switched on, ')
            . 'without which your reviews stay behind in Anki. '
            . 'Going the other way, tick <em>Import any learning progress</em> when you import an '
            . 'LWT deck into Anki, or Anki starts every card from scratch and discards the schedule '
            . 'this page exported.'
            . '</div>';

        // The commonest wrong turn: arriving here with a deck built in Anki,
        // which has no LWT guids and so updates nothing at all.
        echo '<div class="notification is-info is-light mt-4">'
            . '<strong>Importing a deck you built in Anki?</strong> This page only updates terms '
            . 'that LWT exported in the first place — a deck from Anki or AnkiWeb has nothing here '
            . 'to match against, so nothing would change. Use '
            . '<a href="/vocabulary/anki-deck/import">Import an Anki deck</a> instead, which '
            . 'creates new terms and works out how well you know each word from Anki\'s own '
            . 'scheduling.'
            . '</div>';
    }

    private function streamDownload(string $path, string $filename): never
    {
        $size = filesize($path);
        header('Content-Type: application/octet-stream');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        if ($size !== false) {
            header('Content-Length: ' . $size);
        }
        readfile($path);
        exit();
    }

    private function slugify(string $name): string
    {
        $ascii = preg_replace('/[^A-Za-z0-9_-]+/', '-', $name);
        $ascii = trim((string) $ascii, '-');
        return $ascii !== '' ? strtolower($ascii) : 'language';
    }

    private function exportSvc(): ApkgExportService
    {
        return $this->exportService ??= ApkgExportService::default();
    }

    private function importSvc(): ApkgImportService
    {
        return $this->importService ??= ApkgImportService::default();
    }
}
