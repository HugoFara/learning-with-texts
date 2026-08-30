<?php

/**
 * Word CRUD Service - Basic CRUD operations for words
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

use Lwt\Modules\Vocabulary\Domain\Term;
use Lwt\Modules\Vocabulary\Infrastructure\MySqlTermRepository;
use Lwt\Shared\Infrastructure\Database\Connection;
use Lwt\Shared\Infrastructure\Database\Escaping;
use Lwt\Shared\Infrastructure\Database\QueryBuilder;
use Lwt\Shared\Infrastructure\Database\UserScopedQuery;
use Lwt\Modules\Activity\Infrastructure\MySqlActivityRepository;

/**
 * Service for basic CRUD operations on words/terms.
 *
 * Handles:
 * - Create, update, delete operations
 * - Find by ID or text
 * - Get word data and details
 * - Inline edit operations
 *
 * @since 3.0.0
 */
class WordCrudService
{
    private MySqlTermRepository $repository;
    private MySqlActivityRepository $activityRepository;

    /**
     * Constructor.
     *
     * @param MySqlTermRepository|null     $repository         Term repository
     * @param MySqlActivityRepository|null $activityRepository Activity repository
     */
    public function __construct(
        ?MySqlTermRepository $repository = null,
        ?MySqlActivityRepository $activityRepository = null
    ) {
        $this->repository = $repository ?? new MySqlTermRepository();
        $this->activityRepository = $activityRepository ?? new MySqlActivityRepository();
    }

    /**
     * Create a new word/term.
     *
     * @param array $data Word data with keys:
     *                    - WoLgID: Language ID
     *                    - WoText: Term text
     *                    - WoStatus: Learning status (1-5)
     *                    - WoTranslation: Translation text
     *                    - WoSentence: Example sentence
     *                    - WoNotes: Personal notes
     *                    - WoRomanization: Romanization/phonetic
     *                    - WoLemma: Lemma/base form (optional)
     *
     * @return array{id: int, message: string, success: bool, textlc: string, text: string}
     */
    public function create(array $data): array
    {
        $text = trim(Escaping::prepareTextdata((string) $data['WoText']));
        $textlc = mb_strtolower($text, 'UTF-8');
        $translation = $this->normalizeTranslation((string) ($data['WoTranslation'] ?? ''));

        // Handle lemma field
        $lemma = isset($data['WoLemma']) && $data['WoLemma'] !== ''
            ? trim((string) $data['WoLemma'])
            : null;
        $lemmaLc = $lemma !== null ? mb_strtolower($lemma, 'UTF-8') : null;

        try {
            $bindings = [
                $data['WoLgID'],
                $textlc,
                $text,
                $lemma,
                $lemmaLc,
                $data['WoStatus'],
                $translation,
                ExportService::replaceTabNewline((string) ($data['WoSentence'] ?? '')),
                ExportService::replaceTabNewline((string) ($data['WoNotes'] ?? '')),
                (string) ($data['WoRomanization'] ?? '')
            ];
            $sql = "INSERT INTO words (
                    WoLgID, WoTextLC, WoText, WoLemma, WoLemmaLC, WoStatus, WoTranslation,
                    WoSentence, WoNotes, WoRomanization, WoStatusChanged"
                    . UserScopedQuery::insertColumn('words')
                . ") VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW()"
                    . UserScopedQuery::insertValuePrepared('words', $bindings)
                . ")";

            $wid = (int) Connection::preparedInsert($sql, $bindings);

            $this->activityRepository->incrementTermsCreated();

            return [
                'id' => $wid,
                'message' => __('vocabulary.flash.term_saved'),
                'success' => true,
                'textlc' => $textlc,
                'text' => $text
            ];
        } catch (\RuntimeException $e) {
            $errorMsg = $e->getMessage();
            if (strpos($errorMsg, 'Duplicate entry') !== false) {
                $message = __('vocabulary.flash.error_prefix', ['message' => 'Duplicate entry for "' . $textlc . '"']);
            } else {
                $message = __('vocabulary.flash.error_prefix', ['message' => $errorMsg]);
            }

            return [
                'id' => 0,
                'message' => $message,
                'success' => false,
                'textlc' => $textlc,
                'text' => $text
            ];
        }
    }

    /**
     * Update an existing word/term.
     *
     * @param int   $wordId Word ID
     * @param array $data   Word data (same keys as create())
     *
     * @return array{id: int, message: string, success: bool, textlc: string, text: string}
     */
    public function update(int $wordId, array $data): array
    {
        $text = trim(Escaping::prepareTextdata((string) $data['WoText']));
        $textlc = mb_strtolower($text, 'UTF-8');
        $translation = $this->normalizeTranslation((string) ($data['WoTranslation'] ?? ''));
        $sentence = ExportService::replaceTabNewline((string) ($data['WoSentence'] ?? ''));
        $notes = ExportService::replaceTabNewline((string) ($data['WoNotes'] ?? ''));
        $roman = (string) ($data['WoRomanization'] ?? '');

        // Handle lemma field
        $lemma = isset($data['WoLemma']) && $data['WoLemma'] !== ''
            ? trim((string) $data['WoLemma'])
            : null;
        $lemmaLc = $lemma !== null ? mb_strtolower($lemma, 'UTF-8') : null;


        $bindings = [$text, $translation, $sentence, $notes, $roman, $lemma, $lemmaLc];

        if (isset($data['WoOldStatus']) && $data['WoOldStatus'] != $data['WoStatus']) {
            // Status changed - update status and timestamp
            $bindings[] = (int) $data['WoStatus'];
            $bindings[] = $wordId;
            $sql = "UPDATE words SET
                WoText = ?, WoTranslation = ?, WoSentence = ?, WoNotes = ?, WoRomanization = ?,
                WoLemma = ?, WoLemmaLC = ?,
                WoStatus = ?, WoStatusChanged = NOW()
                WHERE WoID = ?"
                . UserScopedQuery::forTablePrepared('words', $bindings);
            Connection::preparedExecute($sql, $bindings);
        } else {
            // Status unchanged
            $bindings[] = $wordId;
            $sql = "UPDATE words SET
                WoText = ?, WoTranslation = ?, WoSentence = ?, WoNotes = ?, WoRomanization = ?,
                WoLemma = ?, WoLemmaLC = ?
                WHERE WoID = ?"
                . UserScopedQuery::forTablePrepared('words', $bindings);
            Connection::preparedExecute($sql, $bindings);
        }

        return [
            'id' => $wordId,
            'message' => __('vocabulary.flash.term_updated_short'),
            'success' => true,
            'textlc' => $textlc,
            'text' => $text
        ];
    }

    /**
     * Find a word by ID.
     *
     * @param int $wordId Word ID
     *
     * @return array|null Word data or null if not found
     */
    public function findById(int $wordId): ?array
    {
        $term = $this->repository->find($wordId);
        if ($term === null) {
            return null;
        }

        return $this->termEntityToArray($term);
    }

    /**
     * Find a word by text and language.
     *
     * @param string $textlc Lowercase text
     * @param int    $langId Language ID
     *
     * @return int|null Word ID or null if not found
     */
    public function findByText(string $textlc, int $langId): ?int
    {
        $term = $this->repository->findByTextLc($langId, $textlc);
        return $term !== null ? $term->id()->toInt() : null;
    }

    /**
     * Delete a word by ID.
     *
     * @param int $wordId Word ID to delete
     *
     * @return void
     */
    public function delete(int $wordId): void
    {
        // Delete multi-word text items first (before word deletion triggers FK SET NULL)
        QueryBuilder::table('word_occurrences')
            ->where('Ti2WoID', '=', $wordId)
            ->where('Ti2WordCount', '>', 1)
            ->deletePrepared();

        // Delete the word - FK constraints handle:
        // - Single-word word_occurrences.Ti2WoID set to NULL (ON DELETE SET NULL)
        // - word_tag_map deleted (ON DELETE CASCADE)
        QueryBuilder::table('words')
            ->where('WoID', '=', $wordId)
            ->deletePrepared();
    }

    /**
     * Get word count for a term.
     *
     * @param int $wordId Word ID
     *
     * @return int Word count
     */
    public function getWordCount(int $wordId): int
    {
        $bindings = [$wordId];
        /** @var int $count */
        $count = (int) Connection::preparedFetchValue(
            "SELECT WoWordCount FROM words WHERE WoID = ?"
            . UserScopedQuery::forTablePrepared('words', $bindings),
            $bindings,
            'WoWordCount'
        );
        return $count;
    }

    /**
     * Get word data including translation and romanization.
     *
     * @param int $wordId Word ID
     *
     * @return array{text: string, translation: string, romanization: string}|null
     */
    public function getWordData(int $wordId): ?array
    {
        $bindings = [$wordId];
        $record = Connection::preparedFetchOne(
            "SELECT WoText, WoTranslation, WoRomanization
             FROM words WHERE WoID = ?"
             . UserScopedQuery::forTablePrepared('words', $bindings),
            $bindings
        );

        if ($record === null) {
            return null;
        }

        return [
            'text' => (string) $record['WoText'],
            'translation' => ExportService::replaceTabNewline((string) $record['WoTranslation']),
            'romanization' => (string) $record['WoRomanization']
        ];
    }

    /**
     * Get a single word's text by ID.
     *
     * @param int $wordId Word ID
     *
     * @return string|null Word text or null if not found
     */
    public function getWordText(int $wordId): ?string
    {
        $bindings = [$wordId];
        /** @var string|null $term */
        $term = Connection::preparedFetchValue(
            "SELECT WoText FROM words WHERE WoID = ?"
            . UserScopedQuery::forTablePrepared('words', $bindings),
            $bindings,
            'WoText'
        );
        return $term;
    }

    /**
     * Get full word details for display.
     *
     * @param int $wordId Word ID
     *
     * @return array|null Word details or null if not found
     */
    public function getWordDetails(int $wordId): ?array
    {
        $bindings = [$wordId];
        $record = Connection::preparedFetchOne(
            "SELECT WoLgID, WoText, WoTranslation, WoSentence, WoNotes, WoRomanization, WoStatus
             FROM words WHERE WoID = ?"
             . UserScopedQuery::forTablePrepared('words', $bindings),
            $bindings
        );

        if ($record === null) {
            return null;
        }

        $translation = ExportService::replaceTabNewline((string) $record['WoTranslation']);
        if ($translation === '*') {
            $translation = '';
        }

        return [
            'langId' => (int) $record['WoLgID'],
            'text' => (string) $record['WoText'],
            'translation' => $translation,
            'sentence' => (string) $record['WoSentence'],
            'notes' => (string) ($record['WoNotes'] ?? ''),
            'romanization' => (string) $record['WoRomanization'],
            'status' => (int) $record['WoStatus']
        ];
    }

    /**
     * Update translation for a word (inline edit).
     *
     * @param int    $wordId Word ID
     * @param string $value  New translation value
     *
     * @return string Updated translation value
     */
    public function updateTranslation(int $wordId, string $value): string
    {
        $value = trim($value);
        if ($value === '') {
            $value = '*';
        }

        $bindings = [ExportService::replaceTabNewline($value), $wordId];
        $sql = "UPDATE words SET WoTranslation = ? WHERE WoID = ?"
            . UserScopedQuery::forTablePrepared('words', $bindings);
        Connection::preparedExecute($sql, $bindings);

        $bindings = [$wordId];
        /** @var string $translation */
        $translation = (string) Connection::preparedFetchValue(
            "SELECT WoTranslation FROM words WHERE WoID = ?"
            . UserScopedQuery::forTablePrepared('words', $bindings),
            $bindings,
            'WoTranslation'
        );
        return $translation;
    }

    /**
     * Update romanization for a word (inline edit).
     *
     * @param int    $wordId Word ID
     * @param string $value  New romanization value
     *
     * @return string Updated romanization value (returns '*' if empty for display)
     */
    public function updateRomanization(int $wordId, string $value): string
    {
        $bindings = [trim($value), $wordId];
        $sql = "UPDATE words SET WoRomanization = ? WHERE WoID = ?"
            . UserScopedQuery::forTablePrepared('words', $bindings);
        Connection::preparedExecute($sql, $bindings);

        $bindings = [$wordId];
        /** @var string $roman */
        $roman = (string) Connection::preparedFetchValue(
            "SELECT WoRomanization FROM words WHERE WoID = ?"
            . UserScopedQuery::forTablePrepared('words', $bindings),
            $bindings,
            'WoRomanization'
        );
        return $roman === '' ? '*' : $roman;
    }

    /**
     * Normalize translation value.
     *
     * @param string $translation Raw translation
     *
     * @return string Normalized translation (empty becomes '*')
     */
    private function normalizeTranslation(string $translation): string
    {
        $translation = trim(ExportService::replaceTabNewline($translation));
        return $translation === '' ? '*' : $translation;
    }

    /**
     * Convert a Term entity to an array format for backward compatibility.
     *
     * @param Term $term Term entity
     *
     * @return array Term data as associative array
     */
    private function termEntityToArray(Term $term): array
    {
        // Preserve null semantics for WoSentence - empty string means null in DB
        $sentence = $term->sentence();
        if ($sentence === '') {
            $sentence = null;
        }

        // Preserve null semantics for WoNotes - empty string means null in DB
        $notes = $term->notes();
        if ($notes === '') {
            $notes = null;
        }

        return [
            'WoID' => $term->id()->toInt(),
            'WoLgID' => $term->languageId()->toInt(),
            'WoText' => $term->text(),
            'WoTextLC' => $term->textLowercase(),
            'WoLemma' => $term->lemma(),
            'WoLemmaLC' => $term->lemmaLc(),
            'WoStatus' => $term->status()->toInt(),
            'WoTranslation' => $term->translation(),
            'WoSentence' => $sentence,
            'WoNotes' => $notes,
            'WoRomanization' => $term->romanization(),
            'WoWordCount' => $term->wordCount(),
            'WoCreated' => $term->createdAt()->format('Y-m-d H:i:s'),
            'WoStatusChanged' => $term->statusChangedAt()->format('Y-m-d H:i:s'),
        ];
    }
}
