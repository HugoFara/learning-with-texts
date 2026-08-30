<?php

/**
 * Text Facade
 *
 * Backward-compatible facade for text operations.
 * Delegates to use case classes for actual implementation.
 *
 * PHP version 8.1
 *
 * @category Lwt
 * @package  Lwt\Modules\Text\Application
 * @author   HugoFara <git@hugofara.net>
 * @license  Unlicense <http://unlicense.org/>
 * @link     https://hugofara.github.io/lwt/developer/api
 * @since    3.0.0
 */

declare(strict_types=1);

namespace Lwt\Modules\Text\Application;

use Lwt\Shared\Infrastructure\Utilities\StringUtils;
use Lwt\Shared\Infrastructure\Database\Connection;
use Lwt\Shared\Infrastructure\Database\Escaping;
use Lwt\Shared\Infrastructure\Database\Maintenance;
use Lwt\Shared\Infrastructure\Database\QueryBuilder;
use Lwt\Shared\Infrastructure\Database\Settings;
use Lwt\Shared\Infrastructure\Database\TextParsing;
use Lwt\Shared\Infrastructure\Database\UserScopedQuery;
use Lwt\Modules\Text\Application\UseCases\ArchiveText;
use Lwt\Modules\Text\Application\UseCases\BuildTextFilters;
use Lwt\Modules\Text\Application\UseCases\DeleteText;
use Lwt\Modules\Text\Application\UseCases\GetTextForEdit;
use Lwt\Modules\Text\Application\UseCases\GetTextForReading;
use Lwt\Modules\Text\Application\UseCases\ImportText;
use Lwt\Modules\Text\Application\UseCases\ListTexts;
use Lwt\Modules\Text\Application\UseCases\ParseText;
use Lwt\Modules\Text\Application\UseCases\UpdateText;
use Lwt\Modules\Text\Domain\AudioUriValidator;
use Lwt\Modules\Text\Domain\TextRepositoryInterface;
use Lwt\Modules\Text\Infrastructure\MySqlTextRepository;
use Lwt\Modules\Vocabulary\Application\Services\ExportService;
use Lwt\Modules\Text\Application\Services\SentenceService;
use Lwt\Modules\Tags\Application\TagsFacade;

/**
 * Facade for text module operations.
 *
 * Provides a unified interface to all text-related use cases.
 * Designed for backward compatibility with existing TextService callers.
 *
 * @since 3.0.0
 */
class TextFacade
{
    protected ArchiveText $archiveText;
    protected BuildTextFilters $buildTextFilters;
    protected DeleteText $deleteText;
    protected GetTextForEdit $getTextForEdit;
    protected GetTextForReading $getTextForReading;
    protected ImportText $importText;
    protected ListTexts $listTexts;
    protected ParseText $parseText;
    protected UpdateText $updateText;
    protected TextRepositoryInterface $textRepository;
    protected SentenceService $sentenceService;

    /**
     * Constructor.
     *
     * @param TextRepositoryInterface|null $textRepository    Text repository
     * @param ArchiveText|null             $archiveText       Archive use case
     * @param BuildTextFilters|null        $buildTextFilters  Filter builder use case
     * @param DeleteText|null              $deleteText        Delete use case
     * @param GetTextForEdit|null          $getTextForEdit    Get for edit use case
     * @param GetTextForReading|null       $getTextForReading Get for reading use case
     * @param ImportText|null              $importText        Import use case
     * @param ListTexts|null               $listTexts         List use case
     * @param ParseText|null               $parseText         Parse use case
     * @param UpdateText|null              $updateText        Update use case
     * @param SentenceService|null         $sentenceService   Sentence service
     */
    public function __construct(
        ?TextRepositoryInterface $textRepository = null,
        ?ArchiveText $archiveText = null,
        ?BuildTextFilters $buildTextFilters = null,
        ?DeleteText $deleteText = null,
        ?GetTextForEdit $getTextForEdit = null,
        ?GetTextForReading $getTextForReading = null,
        ?ImportText $importText = null,
        ?ListTexts $listTexts = null,
        ?ParseText $parseText = null,
        ?UpdateText $updateText = null,
        ?SentenceService $sentenceService = null
    ) {
        $this->textRepository = $textRepository ?? new MySqlTextRepository();
        $this->archiveText = $archiveText ?? new ArchiveText();
        $this->buildTextFilters = $buildTextFilters ?? new BuildTextFilters();
        $this->deleteText = $deleteText ?? new DeleteText();
        $this->getTextForEdit = $getTextForEdit ?? new GetTextForEdit($this->textRepository);
        $this->getTextForReading = $getTextForReading ?? new GetTextForReading($this->textRepository);
        $this->importText = $importText ?? new ImportText($this->textRepository);
        $this->listTexts = $listTexts ?? new ListTexts($this->textRepository);
        $this->parseText = $parseText ?? new ParseText();
        $this->updateText = $updateText ?? new UpdateText();
        $this->sentenceService = $sentenceService ?? new SentenceService();
    }

    // =====================
    // ARCHIVED TEXT METHODS
    // =====================

    /**
     * Get count of archived texts matching filters.
     */
    public function getArchivedTextCount(
        string $whLang,
        string $whQuery,
        string $whTag,
        array $params = []
    ): int {
        return $this->listTexts->getArchivedTextCount($whLang, $whQuery, $whTag, $params);
    }

    /**
     * Get archived texts list with pagination.
     */
    public function getArchivedTextsList(
        string $whLang,
        string $whQuery,
        string $whTag,
        int $sort,
        int $page,
        int $perPage,
        array $params = []
    ): array {
        return $this->listTexts->getArchivedTextsList($whLang, $whQuery, $whTag, $sort, $page, $perPage, $params);
    }

    /**
     * Get a single archived text by ID.
     */
    public function getArchivedTextById(int $textId): ?array
    {
        return $this->getTextForEdit->getArchivedTextById($textId);
    }

    /**
     * Delete an archived text.
     *
     * @return array{count: int}
     */
    public function deleteArchivedText(int $textId): array
    {
        return $this->deleteText->deleteArchivedText($textId);
    }

    /**
     * Delete multiple archived texts.
     *
     * @return array{count: int}
     */
    public function deleteArchivedTexts(array $textIds): array
    {
        return $this->deleteText->deleteArchivedTexts($textIds);
    }

    /**
     * Unarchive a text.
     *
     * @return array{success: bool, textId: ?int, unarchived: int, sentences: int, textItems: int, error: ?string}
     */
    public function unarchiveText(int $archivedId): array
    {
        return $this->archiveText->unarchive($archivedId);
    }

    /**
     * Unarchive multiple texts.
     *
     * @return array{count: int}
     */
    public function unarchiveTexts(array $archivedIds): array
    {
        return $this->archiveText->unarchiveMultiple($archivedIds);
    }

    /**
     * Update an archived text.
     *
     * @return int Number of rows affected
     */
    public function updateArchivedText(
        int $textId,
        int $lgId,
        string $title,
        string $text,
        string $audioUri,
        string $sourceUri
    ): int {
        return $this->updateText->updateArchivedText($textId, $lgId, $title, $text, $audioUri, $sourceUri);
    }

    // =======================
    // FILTER BUILDING METHODS
    // =======================

    /**
     * Build WHERE clause for language filtering.
     */
    public function buildLangWhereClause(string|int $langId): array
    {
        return $this->buildTextFilters->buildLangWhereClause($langId);
    }

    /**
     * Build WHERE clause for archived text query.
     */
    public function buildArchivedQueryWhereClause(
        string $query,
        string $queryMode,
        string $regexMode
    ): array {
        return $this->buildTextFilters->buildArchivedQueryWhereClause($query, $queryMode, $regexMode);
    }

    /**
     * Build HAVING clause for archived text tag filtering.
     */
    public function buildArchivedTagHavingClause(string|int $tag1, string|int $tag2, string $tag12): string
    {
        return $this->buildTextFilters->buildArchivedTagHavingClause($tag1, $tag2, $tag12);
    }

    /**
     * Build HAVING clause for tag filtering (parameterized version).
     */
    public function buildTagHavingClausePrepared(
        string|int $tag1,
        string|int $tag2,
        string $tag12,
        string $tagIdCol = 'TtT2ID'
    ): array {
        return $this->buildTextFilters->buildTagHavingClausePrepared($tag1, $tag2, $tag12, $tagIdCol);
    }

    /**
     * Build WHERE clause for text query.
     */
    public function buildTextQueryWhereClause(
        string $query,
        string $queryMode,
        string $regexMode
    ): array {
        return $this->buildTextFilters->buildQueryWhereClause($query, $queryMode, $regexMode, 'Tx');
    }

    /**
     * Build HAVING clause for text tag filtering.
     */
    public function buildTextTagHavingClause(string|int $tag1, string|int $tag2, string $tag12): string
    {
        return $this->buildTextFilters->buildTextTagHavingClause($tag1, $tag2, $tag12);
    }

    /**
     * Validate regex query.
     */
    public function validateRegexQuery(string $query, string $regexMode): bool
    {
        return $this->buildTextFilters->validateRegexQuery($query, $regexMode);
    }

    // ==================
    // PAGINATION METHODS
    // ==================

    /**
     * Get archived texts per page setting.
     */
    public function getArchivedTextsPerPage(): int
    {
        return $this->listTexts->getArchivedTextsPerPage();
    }

    /**
     * Get texts per page setting.
     */
    public function getTextsPerPage(): int
    {
        return $this->listTexts->getTextsPerPage();
    }

    /**
     * Calculate pagination info.
     */
    public function getPagination(int $totalCount, int $currentPage, int $perPage): array
    {
        return $this->listTexts->getPagination($totalCount, $currentPage, $perPage);
    }

    // =====================
    // ACTIVE TEXT METHODS
    // =====================

    /**
     * Get a single active text by ID.
     */
    public function getTextById(int $textId): ?array
    {
        return $this->getTextForEdit->getTextById($textId);
    }

    /**
     * Delete an active text.
     *
     * @return array{texts: int, sentences: int, textItems: int}
     */
    public function deleteText(int $textId): array
    {
        return $this->deleteText->execute($textId);
    }

    /**
     * Archive an active text.
     *
     * @return array{sentences: int, textItems: int, archived: int}
     */
    public function archiveText(int $textId): array
    {
        return $this->archiveText->execute($textId);
    }

    /**
     * Get count of active texts matching filters.
     */
    public function getTextCount(string $whLang, string $whQuery, string $whTag, array $params = []): int
    {
        return $this->listTexts->getTextCount($whLang, $whQuery, $whTag, $params);
    }

    /**
     * Get active texts list with pagination.
     */
    public function getTextsList(
        string $whLang,
        string $whQuery,
        string $whTag,
        int $sort,
        int $page,
        int $perPage,
        array $params = []
    ): array {
        return $this->listTexts->getTextsList($whLang, $whQuery, $whTag, $sort, $page, $perPage, $params);
    }

    /**
     * Get texts for a specific language (basic version without sort).
     *
     * Note: TextService::getTextsForLanguage() has an additional sort parameter
     * for BC. Use that method if you need sorting.
     */
    public function getBasicTextsForLanguage(int $languageId, int $page = 1, int $perPage = 20): array
    {
        return $this->listTexts->getTextsForLanguage($languageId, $page, $perPage);
    }

    /**
     * Create a new text.
     */
    public function createText(
        int $lgId,
        string $title,
        string $text,
        string $audioUri,
        string $sourceUri
    ): array {
        return $this->importText->execute($lgId, $title, $text, $audioUri, $sourceUri);
    }

    /**
     * Update an active text.
     */
    public function updateText(
        int $textId,
        int $lgId,
        string $title,
        string $text,
        string $audioUri,
        string $sourceUri
    ): array {
        return $this->updateText->execute($textId, $lgId, $title, $text, $audioUri, $sourceUri);
    }

    /**
     * Delete multiple active texts.
     *
     * @return array{count: int}
     */
    public function deleteTexts(array $textIds): array
    {
        return $this->deleteText->deleteMultiple($textIds);
    }

    /**
     * Archive multiple texts.
     *
     * @return array{count: int}
     */
    public function archiveTexts(array $textIds): array
    {
        return $this->archiveText->archiveMultiple($textIds);
    }

    /**
     * Rebuild/reparse multiple texts.
     *
     * @return int Number of texts rebuilt
     */
    public function rebuildTexts(array $textIds): int
    {
        return $this->updateText->rebuildTexts($textIds);
    }

    // ====================
    // TEXT CHECK METHODS
    // ====================

    /**
     * Get text parsing preview (sentences, words, unknown percent).
     *
     * Returns parsing statistics without saving. Use this for new code.
     * TextService::checkText() is kept for BC (outputs HTML directly).
     */
    public function getParsingPreview(string $text, int $languageId): array
    {
        return $this->parseText->execute($text, $languageId);
    }

    /**
     * Validate text length.
     */
    public function validateTextLength(string $text): bool
    {
        return $this->parseText->validateTextLength($text);
    }

    // ======================
    // TEXT READING METHODS
    // ======================

    /**
     * Get text data for reading interface.
     */
    public function getTextForReading(int $textId): ?array
    {
        return $this->getTextForReading->execute($textId);
    }

    /**
     * Get language settings for reading.
     */
    public function getLanguageSettingsForReading(int $languageId): ?array
    {
        return $this->getTextForReading->getLanguageSettingsForReading($languageId);
    }

    /**
     * Get TTS voice API for language.
     */
    public function getTtsVoiceApi(int $languageId): ?string
    {
        $result = $this->getTextForReading->getTtsVoiceApi($languageId);
        return $result === '' ? null : $result;
    }

    /**
     * Get language ID by name.
     */
    public function getLanguageIdByName(string $languageName): ?int
    {
        return $this->getTextForReading->getLanguageIdByName($languageName);
    }

    /**
     * Get Google Translate URIs by language.
     *
     * @return array<int, string> Map of language ID to translate URI
     */
    public function getLanguageTranslateUris(): array
    {
        return $this->getTextForReading->getLanguageTranslateUris();
    }

    // =======================
    // TEXT EDIT PAGE METHODS
    // =======================

    /**
     * Set term sentences for words from texts.
     *
     * @param array $textIds    Text IDs
     * @param bool  $activeOnly Only update active words
     *
     * @return int Number of terms updated
     */
    public function setTermSentences(array $textIds, bool $activeOnly = false): int
    {
        return $this->parseText->setTermSentences($textIds, $activeOnly);
    }

    /**
     * Get text for edit form.
     */
    public function getTextForEdit(int $textId): ?array
    {
        return $this->getTextForEdit->getTextForEdit($textId);
    }

    /**
     * Get language data for form.
     */
    public function getLanguageDataForForm(): array
    {
        return $this->getTextForEdit->getLanguageDataForForm();
    }

    /**
     * Save text and reparse (returns message only).
     *
     * Use this for new code. TextService::saveTextAndReparse() returns
     * additional data (textId, redirect) for BC with existing controllers.
     */
    public function saveAndReparseText(
        int $textId,
        int $lgId,
        string $title,
        string $text,
        string $audioUri,
        string $sourceUri
    ): string {
        return $this->updateText->saveTextAndReparse($textId, $lgId, $title, $text, $audioUri, $sourceUri);
    }

    /**
     * Get texts formatted for select dropdown.
     */
    public function getTextsForSelect(int $languageId = 0, int $maxNameLength = 30): array
    {
        return $this->getTextForEdit->getTextsForSelect($languageId, $maxNameLength);
    }

    // ===========================
    // BC METHODS FROM TextService
    // ===========================

    /**
     * Get paginated texts for a specific language (with sort).
     *
     * @param int $langId  Language ID
     * @param int $page    Page number
     * @param int $perPage Items per page
     * @param int $sort    Sort option (1=title, 2=newest, 3=oldest)
     *
     * @return array{texts: array, pagination: array}
     */
    public function getTextsForLanguage(
        int $langId,
        int $page = 1,
        int $perPage = 20,
        int $sort = 1
    ): array {
        $sorts = ['TxTitle', 'TxID DESC', 'TxID ASC'];
        $sortColumn = $sorts[max(0, min($sort - 1, count($sorts) - 1))];
        $offset = ($page - 1) * $perPage;

        $bindings1 = [$langId];
        $total = (int) Connection::preparedFetchValue(
            "SELECT COUNT(*) AS cnt FROM texts WHERE TxLgID = ?"
            . UserScopedQuery::forTablePrepared('texts', $bindings1),
            $bindings1,
            'cnt'
        );
        $totalPages = (int) ceil($total / $perPage);

        // text_tags scope must live in the LEFT JOIN ON clause so untagged
        // texts survive the join; its binding is at the head of the list.
        $tagJoinBindings = [];
        $tagJoinScope = UserScopedQuery::forTablePrepared('text_tags', $tagJoinBindings);

        $bindings2 = array_merge($tagJoinBindings, [$langId]);
        $textScope = UserScopedQuery::forTablePrepared('texts', $bindings2);
        $bindings2[] = $offset;
        $bindings2[] = $perPage;
        $records = Connection::preparedFetchAll(
            "SELECT TxID, TxTitle, TxAudioURI, TxSourceURI,
            LENGTH(TxAnnotatedText) AS annotlen,
            IFNULL(GROUP_CONCAT(DISTINCT T2Text ORDER BY T2Text SEPARATOR ','), '') AS taglist
            FROM (
                (texts LEFT JOIN text_tag_map ON TxID = TtTxID)
                LEFT JOIN text_tags ON T2ID = TtT2ID{$tagJoinScope}
            )
            WHERE TxLgID = ?{$textScope}
            GROUP BY TxID
            ORDER BY {$sortColumn}
            LIMIT ?, ?",
            $bindings2
        );

        $texts = [];
        foreach ($records as $record) {
            $texts[] = [
                'id' => (int) $record['TxID'],
                'title' => (string) $record['TxTitle'],
                'has_audio' => !empty($record['TxAudioURI']),
                'source_uri' => (string) ($record['TxSourceURI'] ?? ''),
                'has_source' => !empty($record['TxSourceURI'])
                    && substr((string)($record['TxSourceURI'] ?? ''), 0, 1) !== '#',
                'annotated' => !empty($record['annotlen']),
                'taglist' => (string) $record['taglist']
            ];
        }

        return [
            'texts' => $texts,
            'pagination' => [
                'current_page' => $page,
                'per_page' => $perPage,
                'total' => $total,
                'total_pages' => $totalPages
            ]
        ];
    }

    /**
     * Get paginated archived texts for a specific language.
     *
     * @param int $langId  Language ID
     * @param int $page    Page number
     * @param int $perPage Items per page
     * @param int $sort    Sort option (1=title, 2=newest, 3=oldest)
     *
     * @return array{texts: array, pagination: array}
     */
    public function getArchivedTextsForLanguage(
        int $langId,
        int $page,
        int $perPage,
        int $sort
    ): array {
        $sorts = ['TxTitle', 'TxID DESC', 'TxID ASC'];
        $sortColumn = $sorts[max(0, min($sort - 1, count($sorts) - 1))];
        $offset = ($page - 1) * $perPage;

        $bindings1 = [$langId];
        $total = (int) Connection::preparedFetchValue(
            "SELECT COUNT(*) AS cnt FROM texts WHERE TxLgID = ? AND TxArchivedAt IS NOT NULL"
            . UserScopedQuery::forTablePrepared('texts', $bindings1),
            $bindings1,
            'cnt'
        );
        $totalPages = (int) ceil($total / $perPage);

        // See getTextsForLanguage for the binding-order rationale.
        $tagJoinBindings = [];
        $tagJoinScope = UserScopedQuery::forTablePrepared('text_tags', $tagJoinBindings);

        $bindings2 = array_merge($tagJoinBindings, [$langId]);
        $textScope = UserScopedQuery::forTablePrepared('texts', $bindings2);
        $bindings2[] = $offset;
        $bindings2[] = $perPage;
        $records = Connection::preparedFetchAll(
            "SELECT TxID, TxTitle, TxAudioURI, TxSourceURI,
            LENGTH(TxAnnotatedText) AS annotlen,
            IFNULL(GROUP_CONCAT(DISTINCT T2Text ORDER BY T2Text SEPARATOR ','), '') AS taglist
            FROM (
                (texts LEFT JOIN text_tag_map ON TxID = TtTxID)
                LEFT JOIN text_tags ON T2ID = TtT2ID{$tagJoinScope}
            )
            WHERE TxLgID = ? AND TxArchivedAt IS NOT NULL{$textScope}
            GROUP BY TxID
            ORDER BY {$sortColumn}
            LIMIT ?, ?",
            $bindings2
        );

        $texts = [];
        foreach ($records as $record) {
            $texts[] = [
                'id' => (int) $record['TxID'],
                'title' => (string) $record['TxTitle'],
                'has_audio' => !empty($record['TxAudioURI']),
                'source_uri' => (string) ($record['TxSourceURI'] ?? ''),
                'has_source' => !empty($record['TxSourceURI']),
                'annotated' => !empty($record['annotlen']),
                'taglist' => (string) $record['taglist']
            ];
        }

        return [
            'texts' => $texts,
            'pagination' => [
                'current_page' => $page,
                'per_page' => $perPage,
                'total' => $total,
                'total_pages' => $totalPages
            ]
        ];
    }

    /**
     * Check text for parsing without saving (outputs HTML).
     *
     * @param string $text Text to check
     * @param int    $lgId Language ID
     *
     * @return void
     */
    public function checkText(string $text, int $lgId): void
    {
        if (strlen(Escaping::prepareTextdata($text)) > 65000) {
            echo "<p>Error: Text too long, must be below 65000 Bytes.</p>";
        } else {
            TextParsing::parseAndDisplayPreview($text, $lgId);
        }
    }

    /**
     * Build the "check a text" report as data, without saving.
     *
     * @param string $text Text to check
     * @param int    $lgId Language ID
     *
     * @return array{preview: string, sentences: list<string>,
     *         words: list<array{0: string, 1: int, 2: string}>,
     *         nonWords: list<array{0: string, 1: int}>,
     *         multiWords: list<array{0: string, 1: int, 2: string}>,
     *         rtlScript: bool, warning: string}
     */
    public function checkTextReport(string $text, int $lgId): array
    {
        return TextParsing::checkTextReport($text, $lgId);
    }

    /**
     * Get text data for text content display.
     *
     * @param int $textId Text ID
     *
     * @return array|null Text data or null if not found
     */
    public function getTextDataForContent(int $textId): ?array
    {
        $bindings = [$textId];
        return Connection::preparedFetchOne(
            "SELECT TxLgID, TxTitle, TxAnnotatedText, TxPosition
                FROM texts
                WHERE TxID = ?"
                . UserScopedQuery::forTablePrepared('texts', $bindings),
            $bindings
        );
    }

    /**
     * Set term sentences from texts (with SentenceService).
     *
     * Overrides the base implementation to use SentenceService for formatting.
     *
     * @param array $textIds    Text IDs to process
     * @param bool  $activeOnly Only process active terms (status != 98, 99)
     *
     * @return int Number of terms updated
     */
    public function setTermSentencesWithService(array $textIds, bool $activeOnly = false): int
    {
        if (empty($textIds)) {
            return 0;
        }

        /**
 * @var array<int, int> $ids
*/
        $ids = array_values(array_map('intval', $textIds));
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $count = 0;

        $statusFilter = $activeOnly
            ? " AND WoStatus != 98 AND WoStatus != 99"
            : "";

        $wordScope = UserScopedQuery::forTablePrepared('words', $ids);
        $occScope = UserScopedQuery::forTablePrepared('word_occurrences', $ids, '', 'texts');
        $sql = "SELECT WoID, WoTextLC, MIN(Ti2SeID) AS SeID
            FROM words, word_occurrences
            WHERE Ti2LgID = WoLgID AND Ti2WoID = WoID AND Ti2TxID IN ({$placeholders})
            {$statusFilter}
            AND IFNULL(WoSentence,'') NOT LIKE CONCAT('%{',WoText,'}%'){$wordScope}{$occScope}
            GROUP BY WoID
            ORDER BY WoID, MIN(Ti2SeID)";

        $records = Connection::preparedFetchAll($sql, $ids);
        $sentenceCount = (int) Settings::getWithDefault('set-term-sentence-count');

        foreach ($records as $record) {
            $sent = $this->sentenceService->formatSentence(
                (int)$record['SeID'],
                (string)$record['WoTextLC'],
                $sentenceCount
            );
            $bindings = [ExportService::replaceTabNewline($sent[1]), $record['WoID']];
            $count += Connection::preparedExecute(
                "UPDATE words SET WoSentence = ? WHERE WoID = ?"
                . UserScopedQuery::forTablePrepared('words', $bindings),
                $bindings
            );
        }

        return $count;
    }

    /**
     * Save text and reparse it (with additional return data).
     *
     * @param int           $textId    Text ID (0 for new)
     * @param int           $lgId      Language ID
     * @param string        $title     Text title
     * @param string        $text      Text content
     * @param string        $audioUri  Audio URI
     * @param string        $sourceUri Source URI
     * @param string[]|null $tagNames  Tag names to set, or null to read the
     *                                 request's TextTags field (form callers)
     *
     * @return array{message: string, textId: int, redirect: bool}
     */
    public function saveTextAndReparse(
        int $textId,
        int $lgId,
        string $title,
        string $text,
        string $audioUri,
        string $sourceUri,
        ?array $tagNames = null
    ): array {
        $cleanText = str_replace("\xC2\xAD", "", $text);

        // Validate TxAudioURI before persisting. On update, fetch the
        // prior value so the validator can grandfather unchanged URIs
        // (existing data from before per-user-subdir enforcement).
        $previousAudioUri = null;
        if ($textId !== 0) {
            $bindings0 = [$textId];
            /** @var string|null $previousAudioUri */
            $previousAudioUri = Connection::preparedFetchValue(
                "SELECT TxAudioURI FROM texts WHERE TxID = ?"
                . UserScopedQuery::forTablePrepared('texts', $bindings0),
                $bindings0,
                'TxAudioURI'
            );
        }
        $audioUri = AudioUriValidator::validate($audioUri, $previousAudioUri);
        $audioValue = $audioUri === '' ? null : $audioUri;

        if ($textId === 0) {
            $bindings1 = [$lgId, $title, $cleanText, $audioValue, $sourceUri];
            $textId = (int) Connection::preparedInsert(
                "INSERT INTO texts (
                    TxLgID, TxTitle, TxText, TxAnnotatedText, TxAudioURI, TxSourceURI"
                    . UserScopedQuery::insertColumn('texts')
                . ") VALUES (?, ?, ?, '', ?, ?"
                    . UserScopedQuery::insertValuePrepared('texts', $bindings1)
                . ")",
                $bindings1
            );
        } else {
            $bindings1 = [$lgId, $title, $cleanText, $audioValue, $sourceUri, $textId];
            Connection::preparedExecute(
                "UPDATE texts SET
                    TxLgID = ?, TxTitle = ?, TxText = ?, TxAudioURI = ?, TxSourceURI = ?
                 WHERE TxID = ?"
                . UserScopedQuery::forTablePrepared('texts', $bindings1),
                $bindings1
            );
        }

        // A JSON caller passes the names it wants; a form caller passes null and
        // the tag widget's TextTags[TagList][] field is read from the request.
        if ($tagNames === null) {
            TagsFacade::saveTextTagsFromForm($textId);
        } else {
            TagsFacade::saveTextTags($textId, $tagNames);
        }

        $sentencesDeleted = QueryBuilder::table('sentences')
            ->where('SeTxID', '=', $textId)
            ->delete();
        $textitemsDeleted = QueryBuilder::table('word_occurrences')
            ->where('Ti2TxID', '=', $textId)
            ->delete();
        Maintenance::adjustAutoIncrement('sentences', 'SeID');

        $bindings2 = [$textId];
        TextParsing::parseAndSave(
            (string)Connection::preparedFetchValue(
                "SELECT TxText FROM texts WHERE TxID = ?"
                . UserScopedQuery::forTablePrepared('texts', $bindings2),
                $bindings2,
                'TxText'
            ),
            $lgId,
            $textId
        );

        $bindings3 = [$textId];
        $sentenceCount = (int)Connection::preparedFetchValue(
            "SELECT COUNT(*) AS cnt FROM sentences WHERE SeTxID = ?"
            . UserScopedQuery::forTablePrepared('sentences', $bindings3, '', 'texts'),
            $bindings3,
            'cnt'
        );
        $bindings4 = [$textId];
        $itemCount = (int)Connection::preparedFetchValue(
            "SELECT COUNT(*) AS cnt FROM word_occurrences WHERE Ti2TxID = ?"
            . UserScopedQuery::forTablePrepared('word_occurrences', $bindings4, '', 'texts'),
            $bindings4,
            'cnt'
        );

        $message = "Sentences deleted: {$sentencesDeleted} / Textitems deleted: {$textitemsDeleted} " .
            "/ Sentences added: {$sentenceCount} / Text items added: {$itemCount}";

        return [
            'message' => $message,
            'textId' => $textId,
            'redirect' => false
        ];
    }

    // =========================
    // TERM TRANSLATION METHODS
    // =========================

    /**
     * Get translations for a word by ID.
     *
     * @param int $wordId Word ID
     *
     * @return string[] List of translation strings
     */
    public function getWordTranslations(int $wordId): array
    {
        $translations = [];
        $alltrans = (string) QueryBuilder::table('words')
            ->where('WoID', '=', $wordId)
            ->valuePrepared('WoTranslation');
        $transarr = preg_split('/[' . StringUtils::getSeparators() . ']/u', $alltrans);
        if ($transarr === false) {
            return $translations;
        }
        foreach ($transarr as $t) {
            $tt = trim($t);
            if ($tt === '*' || $tt === '') {
                continue;
            }
            $translations[] = $tt;
        }
        return $translations;
    }

    /**
     * Get term translation data for annotation editing.
     *
     * @param string $termLc Lowercase term text
     * @param int    $textId Text ID
     *
     * @return array{term_lc?: string, wid?: int|null, trans?: string,
     *               ann_index?: int, term_ord?: int, translations?: string[],
     *               language_id?: int, error?: string}
     */
    public function getTermTranslations(string $termLc, int $textId): array
    {
        $record = QueryBuilder::table('texts')
            ->select(['TxLgID', 'TxAnnotatedText'])
            ->where('TxID', '=', $textId)
            ->firstPrepared();
        if ($record === null) {
            return ['error' => 'Text not found'];
        }
        $langid = (int) $record['TxLgID'];
        $ann = (string) $record['TxAnnotatedText'];
        if (strlen($ann) > 0) {
            $annotationService = new Services\AnnotationService();
            $ann = $annotationService->recreateSaveAnnotation($textId, $ann);
        }

        $annotations = preg_split('/[\n]/u', $ann);
        if ($annotations === false) {
            return ['error' => 'Failed to parse annotations'];
        }
        $i = -1;
        foreach ($annotations as $index => $annotationLine) {
            $vals = preg_split('/[\t]/u', $annotationLine);
            if ($vals === false) {
                continue;
            }
            if ($vals[0] <= -1) {
                continue;
            }
            if (trim($termLc) != mb_strtolower(trim($vals[1]), 'UTF-8')) {
                continue;
            }
            $i = $index;
            break;
        }

        $annData = [];
        if ($i === -1) {
            $annData['error'] = 'Annotation not found';
            return $annData;
        }

        $annotationLine = $annotations[$i];
        $vals = preg_split('/[\t]/u', $annotationLine);
        if ($vals === false) {
            $annData['error'] = 'Annotation line is ill-formatted';
            return $annData;
        }
        $annData['term_lc'] = trim($termLc);
        $annData['wid'] = null;
        $annData['trans'] = '';
        $annData['ann_index'] = $i;
        $annData['term_ord'] = (int) $vals[0];

        $wid = null;
        if (count($vals) > 2 && ctype_digit($vals[2])) {
            $wid = (int) $vals[2];
            $tempWid = QueryBuilder::table('words')
                ->where('WoID', '=', $wid)
                ->countPrepared();
            if ($tempWid < 1) {
                $wid = null;
            }
        }
        if ($wid !== null) {
            $annData['wid'] = $wid;
            $annData['translations'] = $this->getWordTranslations($wid);
        }
        if (count($vals) > 3) {
            $annData['trans'] = $vals[3];
        }
        $annData['language_id'] = $langid;
        return $annData;
    }
}
