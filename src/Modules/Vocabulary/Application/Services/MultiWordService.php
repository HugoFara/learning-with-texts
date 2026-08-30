<?php

/**
 * Multi-Word Service - Multi-word expression operations
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

use Lwt\Modules\Tags\Application\TagsFacade;
use Lwt\Shared\Infrastructure\Database\Connection;
use Lwt\Shared\Infrastructure\Database\QueryBuilder;
use Lwt\Shared\Infrastructure\Database\UserScopedQuery;
use Lwt\Shared\Infrastructure\Database\Maintenance;

/**
 * Service for managing multi-word expressions.
 *
 * Handles:
 * - Creating multi-word terms
 * - Updating multi-word terms
 * - Retrieving multi-word data
 * - Deleting multi-word expressions
 * - Finding multi-word by text
 *
 * @since 3.0.0
 */
class MultiWordService
{
    private ExpressionService $expressionService;

    /**
     * Constructor.
     *
     * @param ExpressionService|null $expressionService Expression service
     */
    public function __construct(?ExpressionService $expressionService = null)
    {
        $this->expressionService = $expressionService ?? new ExpressionService();
    }

    /**
     * Create a new multi-word expression.
     *
     * @param array<string, mixed> $data Multi-word data:
     *                    - lgid: Language ID
     *                    - textlc: Lowercase text
     *                    - text: Original text
     *                    - status: Word status
     *                    - translation: Translation text
     *                    - sentence: Example sentence
     *                    - notes: Personal notes
     *                    - roman: Romanization/phonetic
     *                    - wordcount: Number of words in expression
     *
     * @return array{id: int, message: string}
     */
    public function createMultiWord(array $data): array
    {

        $sentence = ExportService::replaceTabNewline((string) $data['sentence']);
        $notes = ExportService::replaceTabNewline((string) ($data['notes'] ?? ''));

        $bindings = [
            (int) $data['lgid'],
            $data['textlc'],
            $data['text'],
            (int) $data['status'],
            $data['translation'],
            $sentence,
            $notes,
            $data['roman'],
            (int) $data['wordcount']
        ];

        $sql = "INSERT INTO words (
                WoLgID, WoTextLC, WoText, WoStatus, WoTranslation, WoSentence,
                WoNotes, WoRomanization, WoWordCount, WoStatusChanged"
                . UserScopedQuery::insertColumn('words')
            . ") VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, NOW()"
                . UserScopedQuery::insertValuePrepared('words', $bindings)
            . ")";

        $wid = (int) Connection::preparedInsert($sql, $bindings);

        Maintenance::initWordCount();
        TagsFacade::saveWordTagsFromForm($wid);
        $this->expressionService->insertExpressions(
            (string) $data['textlc'],
            (int) $data['lgid'],
            $wid,
            (int) $data['wordcount'],
            0
        );

        return [
            'id' => $wid,
            'message' => __('vocabulary.flash.term_saved')
        ];
    }

    /**
     * Update an existing multi-word expression.
     *
     * @param int   $wordId    Word ID
     * @param array $data      Multi-word data (same keys as createMultiWord)
     * @param int   $oldStatus Previous status for comparison
     * @param int   $newStatus New status to set
     *
     * @return array{id: int, message: string, status: int}
     */
    public function updateMultiWord(int $wordId, array $data, int $oldStatus, int $newStatus): array
    {
        $sentence = ExportService::replaceTabNewline((string) $data['sentence']);
        $notes = ExportService::replaceTabNewline((string) ($data['notes'] ?? ''));

        if ($oldStatus != $newStatus) {
            // Status changed - update status and timestamp
            $bindings = [
                $data['text'],
                $data['translation'],
                $sentence,
                $notes,
                $data['roman'],
                $newStatus,
                $wordId
            ];
            $sql = "UPDATE words SET
                    WoText = ?, WoTranslation = ?, WoSentence = ?, WoNotes = ?, WoRomanization = ?,
                    WoStatus = ?, WoStatusChanged = NOW()
                    WHERE WoID = ?"
                    . UserScopedQuery::forTablePrepared('words', $bindings);
            Connection::preparedExecute($sql, $bindings);
        } else {
            // Status unchanged
            $bindings = [
                $data['text'],
                $data['translation'],
                $sentence,
                $notes,
                $data['roman'],
                $wordId
            ];
            $sql = "UPDATE words SET
                    WoText = ?, WoTranslation = ?, WoSentence = ?, WoNotes = ?, WoRomanization = ?
                    WHERE WoID = ?"
                    . UserScopedQuery::forTablePrepared('words', $bindings);
            Connection::preparedExecute($sql, $bindings);
        }

        TagsFacade::saveWordTagsFromForm($wordId);

        return [
            'id' => $wordId,
            'message' => __('vocabulary.flash.term_updated_short'),
            'status' => $newStatus
        ];
    }

    /**
     * Get multi-word data for editing.
     *
     * @param int $wordId Word ID
     *
     * @return array{
     *     text: string, lgid: int, translation: string, sentence: string,
     *     notes: string, romanization: string, status: int
     * }|null Multi-word data or null if not found
     */
    public function getMultiWordData(int $wordId): ?array
    {
        $bindings = [$wordId];
        $record = Connection::preparedFetchOne(
            "SELECT WoText, WoLgID, WoTranslation, WoSentence, WoNotes, WoRomanization, WoStatus
             FROM words WHERE WoID = ?"
             . UserScopedQuery::forTablePrepared('words', $bindings),
            $bindings
        );

        if ($record === null) {
            return null;
        }

        return [
            'text' => (string) $record['WoText'],
            'lgid' => (int) $record['WoLgID'],
            'translation' => ExportService::replaceTabNewline((string) $record['WoTranslation']),
            'sentence' => ExportService::replaceTabNewline((string) $record['WoSentence']),
            'notes' => ExportService::replaceTabNewline((string) ($record['WoNotes'] ?? '')),
            'romanization' => (string) $record['WoRomanization'],
            'status' => (int) $record['WoStatus']
        ];
    }

    /**
     * Delete a multi-word expression.
     *
     * Deletes the word and its associated text items with word count > 1.
     *
     * @param int $wordId Word ID to delete
     *
     * @return int Number of affected rows
     */
    public function deleteMultiWord(int $wordId): int
    {
        $result = QueryBuilder::table('words')
            ->where('WoID', '=', $wordId)
            ->delete();

        Maintenance::adjustAutoIncrement('words', 'WoID');

        QueryBuilder::table('word_occurrences')
            ->where('Ti2WordCount', '>', 1)
            ->where('Ti2WoID', '=', $wordId)
            ->delete();

        return $result;
    }

    /**
     * Find multi-word by text and language.
     *
     * @param string $textlc Lowercase text
     * @param int    $langId Language ID
     *
     * @return int|null Word ID or null if not found
     */
    public function findMultiWordByText(string $textlc, int $langId): ?int
    {
        $bindings = [$langId, $textlc];
        /** @var int|null $wid */
        $wid = Connection::preparedFetchValue(
            "SELECT WoID FROM words
             WHERE WoLgID = ? AND WoTextLC = ?"
             . UserScopedQuery::forTablePrepared('words', $bindings),
            $bindings,
            'WoID'
        );
        return $wid;
    }
}
