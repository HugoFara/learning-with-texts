<?php

/**
 * Word Linking Service - Manages word-to-text-item relationships
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

use Lwt\Shared\Infrastructure\Database\Connection;
use Lwt\Shared\Infrastructure\Database\QueryBuilder;

/**
 * Service for managing word-to-text-item relationships.
 *
 * Handles:
 * - Linking words to text items after creation
 * - Bulk linking operations
 * - Retrieving term data from text items
 *
 * @since 3.0.0
 */
class WordLinkingService
{
    /**
     * Get term data from a text item at a specific position.
     *
     * @param int $textId Text ID
     * @param int $ord    Word order/position
     *
     * @return array|null Term data with Ti2Text and Ti2LgID
     */
    public function getTermFromTextItem(int $textId, int $ord): ?array
    {
        // word_occurrences inherits user context via Ti2TxID -> texts FK
        return Connection::preparedFetchOne(
            "SELECT Ti2Text, Ti2LgID FROM word_occurrences
             WHERE Ti2TxID = ? AND Ti2WordCount = 1 AND Ti2Order = ?",
            [$textId, $ord]
        );
    }

    /**
     * Link word to text items after creation.
     *
     * @param int    $wordId Word ID
     * @param int    $langId Language ID
     * @param string $textlc Lowercase text
     *
     * @return void
     */
    public function linkToTextItems(int $wordId, int $langId, string $textlc): void
    {
        // word_occurrences has no owner column and this statement does not
        // join texts, so nothing here is filtered by user. What confines it is
        // that a languages row belongs to exactly one user — which only holds
        // once the language is known to be the caller's.
        //
        // Two callers derive it from a user-scoped text lookup, but
        // TermEditController::createWord() takes it from the request, and
        // /word/new is registered for every HTTP method. Without this check a
        // POST carrying somebody else's WoLgID re-points their occurrences at
        // the caller's word. ExpressionService::findStandardExpression() bails
        // the same way on an unknown language; this had no equivalent.
        if (!$this->ownsLanguage($langId)) {
            return;
        }

        Connection::preparedExecute(
            "UPDATE word_occurrences SET Ti2WoID = ?
             WHERE Ti2LgID = ? AND LOWER(Ti2Text) = ?",
            [$wordId, $langId, $textlc]
        );
    }

    /**
     * Is this language visible to the current user?
     *
     * `languages` is user-scoped, so the lookup returns nothing for a language
     * belonging to somebody else — and everything in single-user mode, where
     * there is no ownership to enforce.
     *
     * @param int $langId Language ID
     */
    private function ownsLanguage(int $langId): bool
    {
        return QueryBuilder::table('languages')
            ->where('LgID', '=', $langId)
            ->existsPrepared();
    }

    /**
     * Link all unlinked text items to their corresponding words.
     *
     * @return void
     */
    public function linkAllTextItems(): void
    {
        // words has WoUsID - user scope auto-applied
        // word_occurrences inherits user context via Ti2TxID -> texts FK
        Connection::execute(
            "UPDATE words
             JOIN word_occurrences
             ON Ti2WoID IS NULL AND LOWER(Ti2Text) = WoTextLC AND Ti2LgID = WoLgID
             SET Ti2WoID = WoID"
        );
    }

    /**
     * Get word text at a specific position in text.
     *
     * @param int $textId Text ID
     * @param int $ord    Position in text
     *
     * @return string|null Word text or null if not found
     */
    public function getWordAtPosition(int $textId, int $ord): ?string
    {
        // word_occurrences inherits user context via Ti2TxID -> texts FK
        /** @var string|null $word */
        $word = Connection::preparedFetchValue(
            "SELECT Ti2Text
             FROM word_occurrences
             WHERE Ti2WordCount = 1 AND Ti2TxID = ? AND Ti2Order = ?",
            [$textId, $ord],
            'Ti2Text'
        );
        return $word;
    }

    /**
     * Link newly created words to text items.
     *
     * Links words with ID greater than maxWoId to their corresponding text items.
     *
     * @param int $maxWoId Maximum word ID before bulk insert
     *
     * @return void
     */
    public function linkNewWordsToTextItems(int $maxWoId): void
    {
        // word_occurrences inherits user context via Ti2TxID -> texts FK
        // words has WoUsID - user scope auto-applied
        Connection::preparedExecute(
            "UPDATE word_occurrences
             JOIN words
             ON LOWER(Ti2Text) = WoTextLC AND Ti2WordCount = 1 AND Ti2LgID = WoLgID AND WoID > ?
             SET Ti2WoID = WoID",
            [$maxWoId]
        );
    }
}
