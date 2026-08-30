<?php

/**
 * Word Upload Service - Facade for importing terms from files or text
 *
 * PHP version 8.2
 *
 * @category Lwt
 * @package  Lwt\Modules\Vocabulary\Application\Services
 * @author   HugoFara <git@hugofara.net>
 * @license  Unlicense <http://unlicense.org/>
 * @link     https://hugofara.github.io/lwt/developer/api
 * @since    3.0.0
 */

declare(strict_types=1);

namespace Lwt\Modules\Vocabulary\Application\Services;

/**
 * Facade for term import operations.
 *
 * Delegates to ImportUtilities, SimpleImportService, and CompleteImportService.
 * Preserves the original public API for all callers.
 *
 * @category Lwt
 * @package  Lwt\Modules\Vocabulary\Application\Services
 * @author   HugoFara <git@hugofara.net>
 * @license  Unlicense <http://unlicense.org/>
 * @link     https://hugofara.github.io/lwt/developer/api
 * @since    3.0.0
 */
class WordUploadService
{
    private ImportUtilities $utilities;
    private SimpleImportService $simpleImport;
    private CompleteImportService $completeImport;

    public function __construct()
    {
        $this->utilities = new ImportUtilities();
        $this->simpleImport = new SimpleImportService($this->utilities);
        $this->completeImport = new CompleteImportService($this->utilities);
    }

    /**
     * Get language data for a specific language.
     *
     * @param int $langId Language ID
     *
     * @return array|null Language data or null if not found
     */
    public function getLanguageData(int $langId): ?array
    {
        return $this->utilities->getLanguageData($langId);
    }

    /**
     * Check if local infile is enabled in MySQL and PHP.
     *
     * @return bool True if local_infile is enabled on both server and client
     */
    public function isLocalInfileEnabled(): bool
    {
        return $this->utilities->isLocalInfileEnabled();
    }

    /**
     * Parse column mapping from request.
     *
     * @param array $columns      Column assignments from form (Col1-Col5)
     * @param bool  $removeSpaces Whether language removes spaces
     *
     * @return array{columns: array<int, string>, fields: array{txt: int, tr: int, ro: int, se: int, tl: int}}
     */
    public function parseColumnMapping(array $columns, bool $removeSpaces): array
    {
        return $this->utilities->parseColumnMapping($columns, $removeSpaces);
    }

    /**
     * Get delimiter character from tab type.
     *
     * @param string $tabType Tab type (c, t, h)
     *
     * @return string Delimiter character
     */
    public function getDelimiter(string $tabType): string
    {
        return $this->utilities->getDelimiter($tabType);
    }

    /**
     * Get delimiter for SQL LOAD DATA statement.
     *
     * @param string $tabType Tab type (c, t, h)
     *
     * @return string SQL delimiter string
     */
    public function getSqlDelimiter(string $tabType): string
    {
        return $this->utilities->getSqlDelimiter($tabType);
    }

    /**
     * Create a temporary file from text input.
     *
     * @param string $content Text content to write
     *
     * @return string Path to temporary file
     */
    public function createTempFile(string $content): string
    {
        return $this->utilities->createTempFile($content);
    }

    /**
     * Import terms using simple import (no tags, no overwrite).
     *
     * @param int    $langId        Language ID
     * @param array{txt: int, tr: int, ro: int, se: int, tl?: int} $fields Field indexes
     * @param string $columnsClause SQL columns clause
     * @param string $delimiter     Field delimiter
     * @param string $fileName      Path to input file
     * @param int    $status        Word status
     * @param bool   $ignoreFirst   Ignore first line
     *
     * @return void
     */
    public function importSimple(
        int $langId,
        array $fields,
        string $columnsClause,
        string $delimiter,
        string $fileName,
        int $status,
        bool $ignoreFirst
    ): void {
        $this->simpleImport->importSimple(
            $langId,
            $fields,
            $columnsClause,
            $delimiter,
            $fileName,
            $status,
            $ignoreFirst
        );
    }

    /**
     * Import terms with complete processing (handles tags, overwrite modes).
     *
     * @param int    $langId        Language ID
     * @param array{txt: int, tr: int, ro: int, se: int, tl: int} $fields Field indexes
     * @param string $columnsClause SQL columns clause
     * @param string $delimiter     Field delimiter
     * @param string $fileName      Path to input file
     * @param int    $status        Word status
     * @param int    $overwrite     Overwrite mode
     * @param bool   $ignoreFirst   Ignore first line
     * @param string $translDelim   Translation delimiter
     * @param string $tabType       Tab type (c, t, h)
     *
     * @return void
     */
    public function importComplete(
        int $langId,
        array $fields,
        string $columnsClause,
        string $delimiter,
        string $fileName,
        int $status,
        int $overwrite,
        bool $ignoreFirst,
        string $translDelim,
        string $tabType
    ): void {
        $this->completeImport->importComplete(
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

    /**
     * Import tags only (no terms).
     *
     * @param array{tl: int} $fields      Field indexes
     * @param string         $tabType     Tab type (c, t, h)
     * @param string         $fileName    Path to input file
     * @param bool           $ignoreFirst Ignore first line
     *
     * @return void
     */
    public function importTagsOnly(array $fields, string $tabType, string $fileName, bool $ignoreFirst): void
    {
        $this->completeImport->importTagsOnly($fields, $tabType, $fileName, $ignoreFirst);
    }

    /**
     * Handle multi-word expressions after import.
     *
     * @param int    $langId     Language ID
     * @param string $lastUpdate Last update timestamp
     *
     * @return void
     */
    public function handleMultiwords(int $langId, string $lastUpdate): void
    {
        $this->utilities->handleMultiwords($langId, $lastUpdate);
    }

    /**
     * Get the last word status change timestamp.
     *
     * @return string|null Last update timestamp
     */
    public function getLastWordUpdate(): ?string
    {
        return $this->utilities->getLastWordUpdate();
    }

    /**
     * Link imported words to text items.
     *
     * @return void
     */
    public function linkWordsToTextItems(): void
    {
        $this->utilities->linkWordsToTextItems();
    }

    /**
     * Count imported terms.
     *
     * @param string $lastUpdate Last update timestamp
     *
     * @return int Number of imported terms
     */
    public function countImportedTerms(string $lastUpdate): int
    {
        return $this->utilities->countImportedTerms($lastUpdate);
    }

    /**
     * Get imported terms for display.
     *
     * @param string $lastUpdate Last update timestamp
     * @param int    $offset     Offset for pagination
     * @param int    $limit      Limit for pagination
     *
     * @return array Imported terms data
     */
    public function getImportedTerms(string $lastUpdate, int $offset, int $limit): array
    {
        return $this->utilities->getImportedTerms($lastUpdate, $offset, $limit);
    }

    /**
     * Get right-to-left setting for a language.
     *
     * @param int $langId Language ID
     *
     * @return bool True if language is RTL
     */
    public function isRightToLeft(int $langId): bool
    {
        return $this->utilities->isRightToLeft($langId);
    }
}
