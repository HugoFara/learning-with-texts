<?php

/**
 * Word Discovery Service - Finding and creating unknown words
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

use Lwt\Shared\Infrastructure\Utilities\StringUtils;
use Lwt\Shared\Infrastructure\Database\Connection;
use Lwt\Shared\Infrastructure\Database\Escaping;
use Lwt\Shared\Infrastructure\Database\UserScopedQuery;

/**
 * Service for discovering and creating unknown words.
 *
 * Handles:
 * - Finding unknown words in texts
 * - Quick word creation with status
 * - Hover-based word creation
 * - Bulk word status operations
 *
 * @since 3.0.0
 */
class WordDiscoveryService
{
    private WordContextService $contextService;
    private WordLinkingService $linkingService;

    /**
     * Constructor.
     *
     * @param WordContextService|null $contextService Context service
     * @param WordLinkingService|null $linkingService Linking service
     */
    public function __construct(
        ?WordContextService $contextService = null,
        ?WordLinkingService $linkingService = null
    ) {
        $this->contextService = $contextService ?? new WordContextService();
        $this->linkingService = $linkingService ?? new WordLinkingService();
    }

    /**
     * Get unknown words in a text (words without a WoID).
     *
     * @param int $textId Text ID
     *
     * @return array<int, array<string, mixed>> Array of rows with Ti2Text and Ti2TextLC columns
     */
    public function getUnknownWordsInText(int $textId): array
    {
        // word_occurrences inherits user context via Ti2TxID -> texts FK.
        // words is LEFT JOINed; the user-scope must live in the JOIN ON
        // clause, otherwise WHERE WoID IS NULL would still match other
        // users' words (and a flat AND in WHERE would block the IS-NULL).
        $joinScopeBindings = [];
        $joinScope = UserScopedQuery::forTablePrepared('words', $joinScopeBindings, 'words');
        $bindings = array_merge($joinScopeBindings, [$textId]);
        return Connection::preparedFetchAll(
            "SELECT DISTINCT Ti2Text, LOWER(Ti2Text) AS Ti2TextLC
             FROM (word_occurrences LEFT JOIN words
                   ON LOWER(Ti2Text) = WoTextLC AND Ti2LgID = WoLgID{$joinScope})
             WHERE WoID IS NULL AND Ti2WordCount = 1 AND Ti2TxID = ?
             ORDER BY Ti2Order",
            $bindings
        );
    }

    /**
     * Get all unknown words in a text (words without a WoID).
     *
     * @param int $textId Text ID
     *
     * @return array<int, array<string, mixed>> Array of rows with Ti2Text and Ti2TextLC columns
     */
    public function getAllUnknownWordsInText(int $textId): array
    {
        // Use Ti2WoID IS NULL to match how the reading interface identifies
        // unknown words (joined via Ti2WoID = WoID). This avoids disagreement
        // with the text-based join when a word was learned in another text
        // but Ti2WoID was not yet updated in this text.
        $bindings = [$textId];
        return Connection::preparedFetchAll(
            "SELECT DISTINCT Ti2Text, LOWER(Ti2Text) AS Ti2TextLC
             FROM word_occurrences
             WHERE Ti2WoID IS NULL AND Ti2WordCount = 1 AND Ti2TxID = ?
             ORDER BY Ti2Order",
            $bindings
        );
    }

    /**
     * Get unknown words for bulk translation with pagination.
     *
     * @param int $textId Text ID
     * @param int $offset Starting position
     * @param int $limit  Number of words to return
     *
     * @return array<int, array<string, mixed>> Array of rows with word, Ti2LgID, pos columns
     */
    public function getUnknownWordsForBulkTranslate(
        int $textId,
        int $offset,
        int $limit
    ): array {
        // word_occurrences inherits user context via Ti2TxID -> texts FK
        return Connection::preparedFetchAll(
            "SELECT Ti2Text AS word, Ti2LgID, MIN(Ti2Order) AS pos
             FROM word_occurrences
             WHERE Ti2WoID IS NULL AND Ti2TxID = ? AND Ti2WordCount = 1
             GROUP BY LOWER(Ti2Text)
             ORDER BY pos
             LIMIT ?, ?",
            [$textId, $offset, $limit]
        );
    }

    /**
     * Create a word with a specific status.
     *
     * @param int    $langId Language ID
     * @param string $term   The term text
     * @param string $termlc Lowercase version of the term
     * @param int    $status Status to set (98=ignored, 99=well-known)
     *
     * @return array{id: int, rows: int} Word ID and number of inserted rows
     */
    public function createWithStatus(int $langId, string $term, string $termlc, int $status): array
    {
        // Check if already exists
        $bindings = [$termlc];
        /** @var int|null $existingId */
        $existingId = Connection::preparedFetchValue(
            "SELECT WoID FROM words WHERE WoTextLC = ?"
            . UserScopedQuery::forTablePrepared('words', $bindings),
            $bindings,
            'WoID'
        );

        if ($existingId !== null) {
            return ['id' => $existingId, 'rows' => 0];
        }


        $bindings = [$langId, $term, $termlc, $status];
        $sql = "INSERT INTO words (
                WoLgID, WoText, WoTextLC, WoStatus, WoStatusChanged"
                . UserScopedQuery::insertColumn('words')
            . ") VALUES (?, ?, ?, ?, NOW()"
                . UserScopedQuery::insertValuePrepared('words', $bindings)
            . ")";

        $wid = Connection::preparedInsert($sql, $bindings);
        return ['id' => (int)$wid, 'rows' => 1];
    }

    /**
     * Insert a word with a specific status and link to text items.
     *
     * Used for quick insert operations (mark as known/ignored).
     *
     * @param int    $textId Text ID (to get language)
     * @param string $term   Word text
     * @param int    $status Status (98=ignored, 99=well-known)
     *
     * @return array{id: int, term: string, termlc: string, hex: string}
     */
    public function insertWordWithStatus(int $textId, string $term, int $status): array
    {
        $termlc = mb_strtolower($term, 'UTF-8');
        $langId = $this->contextService->getLanguageIdFromText($textId);

        if ($langId === null) {
            throw new \RuntimeException("Text ID $textId not found");
        }

        // The reader calls a word unknown when its occurrence has no Ti2WoID,
        // but what `words` is unique on is (WoTextLC, WoLgID). Those two
        // disagree whenever a term was created without linking its occurrences
        // — a dictionary import does exactly that for the whole language — and
        // this used to INSERT straight into the collision, failing with
        // "Duplicate entry" for every word in the text (issue #283). Resolve
        // the term by what actually identifies it, and treat an existing row
        // as the term being asked for rather than as an error.
        $wid = $this->findTermIdByText($langId, $termlc);

        if ($wid === null) {
            $bindings = [$langId, $term, $termlc, $status];
            $sql = "INSERT INTO words (
                    WoLgID, WoText, WoTextLC, WoStatus, WoWordCount, WoStatusChanged"
                    . UserScopedQuery::insertColumn('words')
                . ") VALUES (?, ?, ?, ?, 1, NOW()"
                    . UserScopedQuery::insertValuePrepared('words', $bindings)
                . ")";

            $wid = (int) Connection::preparedInsert($sql, $bindings);
        } else {
            /** @var list<int|string> $updateBindings */
            $updateBindings = [$status, $wid];
            Connection::preparedExecute(
                "UPDATE words SET WoStatus = ?, WoStatusChanged = NOW() WHERE WoID = ?"
                . UserScopedQuery::forTablePrepared('words', $updateBindings),
                $updateBindings
            );
        }

        // Either way the occurrences may still be unlinked — that is the state
        // that made the term invisible to the reader in the first place.
        $this->linkingService->linkToTextItems($wid, $langId, $termlc);

        return [
            'id' => $wid,
            'term' => $term,
            'termlc' => $termlc,
            'hex' => StringUtils::toClassName($termlc)
        ];
    }

    /**
     * The ID of the term this language already holds for `$termlc`, if any.
     *
     * Looks the term up by the pair `words` is unique on, which is the only
     * reliable answer to "does this term exist" — the reader's own notion of a
     * new word comes from the occurrence table and can be stale.
     *
     * @param int    $langId Language ID
     * @param string $termlc Lowercased term text
     *
     * @return int|null Existing word ID, or null when the term is genuinely new
     */
    private function findTermIdByText(int $langId, string $termlc): ?int
    {
        $bindings = [$termlc, $langId];
        /** @var int|string|null $wid */
        $wid = Connection::preparedFetchValue(
            "SELECT WoID FROM words WHERE WoTextLC = ? AND WoLgID = ?"
            . UserScopedQuery::forTablePrepared('words', $bindings),
            $bindings,
            'WoID'
        );

        return $wid === null ? null : (int) $wid;
    }

    /**
     * Create a word on hover with optional translation.
     *
     * Used when user hovers and clicks to set a word status directly from the text.
     *
     * @param int    $textId      Text ID
     * @param string $text        Word text
     * @param int    $status      Word status (1-5)
     * @param string $translation Optional translation
     *
     * @return array{
     *     wid: int,
     *     word: string,
     *     wordRaw: string,
     *     translation: string,
     *     status: int,
     *     hex: string
     * }
     */
    public function createOnHover(
        int $textId,
        string $text,
        int $status,
        string $translation = '*'
    ): array {
        $wordlc = mb_strtolower($text, 'UTF-8');

        $langId = $this->contextService->getLanguageIdFromText($textId);
        if ($langId === null) {
            throw new \RuntimeException("Text ID $textId not found");
        }


        $bindings = [$langId, $wordlc, $text, $status, $translation];
        $sql = "INSERT INTO words (
                WoLgID, WoTextLC, WoText, WoStatus, WoTranslation, WoSentence,
                WoRomanization, WoStatusChanged"
                . UserScopedQuery::insertColumn('words')
            . ") VALUES (?, ?, ?, ?, ?, '', '', NOW()"
                . UserScopedQuery::insertValuePrepared('words', $bindings)
            . ")";

        $wid = (int) Connection::preparedInsert($sql, $bindings);

        // Link to text items
        $this->linkingService->linkToTextItems($wid, $langId, $wordlc);

        $hex = StringUtils::toClassName(
            Escaping::prepareTextdata($wordlc)
        );

        return [
            'wid' => $wid,
            'word' => $text,
            'wordRaw' => $text,
            'translation' => $translation,
            'status' => $status,
            'hex' => $hex
        ];
    }

    /**
     * Process a single word for the "mark all as well-known" operation.
     *
     * @param int    $status New word status
     * @param string $term   Word text
     * @param string $termlc Lowercase word text
     * @param int    $langId Language ID
     *
     * @return array{int, array{wid: int, hex: string, term: string, status: int}|null} Rows modified and word data
     */
    public function processWordForWellKnown(int $status, string $term, string $termlc, int $langId): array
    {
        $wid = $this->findTermIdByText($langId, $termlc);

        if ($wid !== null) {
            // Word already exists — update its status
            /** @var list<int|string> $updateBindings */
            $updateBindings = [$status, $wid];
            Connection::preparedExecute(
                "UPDATE words SET WoStatus = ?, WoStatusChanged = NOW() WHERE WoID = ?"
                . UserScopedQuery::forTablePrepared('words', $updateBindings),
                $updateBindings
            );

            return [1, [
                'wid' => $wid,
                'hex' => StringUtils::toClassName($termlc),
                'term' => $term,
                'status' => $status
            ]];
        }

        try {

            /** @var list<int|string> $bindings */
            $bindings = [$langId, $term, $termlc, $status];
            $sql = "INSERT INTO words (
                    WoLgID, WoText, WoTextLC, WoStatus, WoStatusChanged"
                    . UserScopedQuery::insertColumn('words')
                . ") VALUES (?, ?, ?, ?, NOW()"
                    . UserScopedQuery::insertValuePrepared('words', $bindings)
                . ")";

            $stmt = Connection::prepare($sql);
            $stmt->bindValues($bindings);
            $rows = $stmt->execute();
            $wid = (int) $stmt->insertId();

            if ($rows == 0) {
                \Lwt\Shared\UI\Helpers\PageLayoutHelper::renderMessage(
                    "WARNING: No rows modified!",
                    false
                );
            }

            $wordData = [
                'wid' => $wid,
                'hex' => StringUtils::toClassName($termlc),
                'term' => $term,
                'status' => $status
            ];

            return [$rows, $wordData];
        } catch (\RuntimeException $e) {
            throw new \RuntimeException("Could not modify words: " . $e->getMessage(), 0, $e);
        }
    }

    /**
     * Update word status.
     *
     * @param int $wordId Word ID
     * @param int $status New status (1-5, 98, 99)
     *
     * @return void
     */
    public function setStatus(int $wordId, int $status): void
    {
        $bindings = [$status, $wordId];
        $sql = "UPDATE words SET WoStatus = ?, WoStatusChanged = NOW() WHERE WoID = ?"
            . UserScopedQuery::forTablePrepared('words', $bindings);
        Connection::preparedExecute($sql, $bindings);
    }

    /**
     * Mark all unknown words in a text with a specific status.
     *
     * @param int $textId Text ID
     * @param int $status Status to apply (98=ignored, 99=well-known)
     *
     * @return array{int, array<array{wid: int, hex: string, term: string, status: int}>} Total count and words data
     */
    public function markAllWordsWithStatus(int $textId, int $status): array
    {
        $langId = $this->contextService->getLanguageIdFromText($textId);
        if ($langId === null) {
            throw new \RuntimeException("Text ID $textId not found");
        }

        $wordsData = [];
        $count = 0;
        $records = $this->getAllUnknownWordsInText($textId);
        foreach ($records as $record) {
            list($modified_rows, $wordData) = $this->processWordForWellKnown(
                $status,
                (string) $record['Ti2Text'],
                (string) $record['Ti2TextLC'],
                $langId
            );
            if ($wordData !== null) {
                $wordsData[] = $wordData;
            }
            $count += $modified_rows;
        }

        // Associate existing textitems
        $this->linkingService->linkAllTextItems();

        return array($count, $wordsData);
    }
}
