<?php

declare(strict_types=1);

namespace Lwt\Modules\Vocabulary\Http;

use Lwt\Modules\Language\Application\LanguageFacade;
use Lwt\Modules\Vocabulary\Application\Services\Anki\ApkgExportService;
use Lwt\Modules\Vocabulary\Application\Services\Anki\ApkgImportService;
use Lwt\Modules\Vocabulary\Application\Services\Anki\ImportResult;
use Lwt\Shared\Infrastructure\Database\Settings;
use Lwt\Shared\Infrastructure\Http\InputValidator;
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
        PageLayoutHelper::renderPageStart(__('vocabulary.anki.apkg.title'), true);

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
            $this->renderImportForm(__('vocabulary.anki.apkg.error_no_file'), null);
            return;
        }

        $name = $file['name'];
        if (!str_ends_with(strtolower($name), '.apkg')) {
            $this->renderImportForm(__('vocabulary.anki.apkg.error_not_apkg'), null);
            return;
        }

        try {
            $result = $this->importSvc()->importApkg($file['tmp_name']);
        } catch (RuntimeException $e) {
            $this->renderImportForm(__('vocabulary.anki.apkg.error_failed', ['message' => $e->getMessage()]), null);
            return;
        }

        $this->renderImportForm(null, $result);
    }

    /**
     * The upload form, plus the summary once an import has run.
     *
     * @param string|null       $error  Message to show above the form
     * @param ImportResult|null $result What the import did, if one did
     */
    private function renderImportForm(?string $error, ?ImportResult $result): void
    {
        $this->render('apkg_import', [
            'error' => $error,
            'result' => $result,
            // Where PHP can decompress Anki's current format, the advice to
            // switch on its legacy export does not apply.
            'needsLegacyExport' => !function_exists('zstd_uncompress'),
        ]);
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
