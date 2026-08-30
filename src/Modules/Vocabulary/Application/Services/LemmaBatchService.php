<?php

/**
 * Lemma Batch Service
 *
 * Handles suggesting, applying, propagating, and linking lemmas.
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

use Lwt\Modules\Vocabulary\Domain\LemmatizerInterface;
use Lwt\Modules\Vocabulary\Infrastructure\MySqlTermRepository;
use Lwt\Shared\Infrastructure\Database\Connection;
use Lwt\Shared\Infrastructure\Database\UserScopedQuery;

/**
 * Service for suggesting, applying, propagating, and linking lemmas.
 *
 * @since 3.0.0
 */
class LemmaBatchService
{
    private LemmatizerInterface $lemmatizer;
    private MySqlTermRepository $repository;

    /**
     * Constructor.
     *
     * @param LemmatizerInterface $lemmatizer Lemmatizer implementation
     * @param MySqlTermRepository $repository Term repository
     */
    public function __construct(
        LemmatizerInterface $lemmatizer,
        MySqlTermRepository $repository
    ) {
        $this->lemmatizer = $lemmatizer;
        $this->repository = $repository;
    }

    /**
     * Suggest a lemma for a word.
     *
     * @param string $word         The word to lemmatize
     * @param string $languageCode ISO language code (e.g., 'en', 'de')
     *
     * @return string|null The suggested lemma, or null if not found
     */
    public function suggestLemma(string $word, string $languageCode): ?string
    {
        if ($word === '' || $languageCode === '') {
            return null;
        }

        return $this->lemmatizer->lemmatize($word, $languageCode);
    }

    /**
     * Suggest lemmas for multiple words.
     *
     * @param string[] $words        Array of words
     * @param string   $languageCode ISO language code
     *
     * @return array<string, string|null> Word => lemma mapping
     */
    public function suggestLemmasBatch(array $words, string $languageCode): array
    {
        if (empty($words) || $languageCode === '') {
            return [];
        }

        return $this->lemmatizer->lemmatizeBatch($words, $languageCode);
    }

    /**
     * Apply lemmas to existing vocabulary for a language.
     *
     * @param int    $languageId   Language ID
     * @param string $languageCode ISO language code for lemmatizer
     * @param int    $batchSize    Number of words to process per batch
     *
     * @return array{processed: int, updated: int, skipped: int}
     */
    public function applyLemmasToVocabulary(
        int $languageId,
        string $languageCode,
        int $batchSize = 100
    ): array {
        if (!$this->lemmatizer->supportsLanguage($languageCode)) {
            return ['processed' => 0, 'updated' => 0, 'skipped' => 0];
        }

        $stats = [
            'processed' => 0,
            'updated' => 0,
            'skipped' => 0,
        ];

        $offset = 0;

        while (true) {
            // Fetch batch of terms without lemmas
            $terms = $this->fetchTermsWithoutLemma($languageId, $batchSize, $offset);

            if (empty($terms)) {
                break;
            }

            // Collect words for batch lemmatization
            /** @var array<int, string> $words */
            $words = [];
            foreach ($terms as $term) {
                $wordId = (int)($term['WoID'] ?? 0);
                $textLc = (string)($term['WoTextLC'] ?? '');
                $words[$wordId] = $textLc;
            }

            // Get lemmas for all words in batch
            $lemmas = $this->lemmatizer->lemmatizeBatch(array_values($words), $languageCode);

            // Update terms with found lemmas
            foreach ($terms as $term) {
                $stats['processed']++;
                $textLc = (string)($term['WoTextLC'] ?? '');
                $lemma = $lemmas[$textLc] ?? null;

                if ($lemma !== null) {
                    $this->updateTermLemma((int)($term['WoID'] ?? 0), $lemma);
                    $stats['updated']++;
                } else {
                    $stats['skipped']++;
                }
            }

            $offset += $batchSize;

            // Safety limit to prevent infinite loops
            if ($offset > 100000) {
                break;
            }
        }

        return $stats;
    }

    /**
     * Fetch terms without a lemma.
     *
     * @param int $languageId Language ID
     * @param int $limit      Maximum number to fetch
     * @param int $offset     Starting offset
     *
     * @return array<int, array<string, mixed>>
     */
    private function fetchTermsWithoutLemma(int $languageId, int $limit, int $offset): array
    {
        $bindings = [$languageId];
        $userScope = UserScopedQuery::forTablePrepared('words', $bindings);
        $bindings[] = $limit;
        $bindings[] = $offset;

        /** @var array<int, array<string, mixed>> */
        return Connection::preparedFetchAll(
            "SELECT WoID, WoText, WoTextLC
             FROM words
             WHERE WoLgID = ?
               AND WoWordCount = 1
               AND (WoLemma IS NULL OR WoLemma = ''){$userScope}
             ORDER BY WoID
             LIMIT ? OFFSET ?",
            $bindings
        );
    }

    /**
     * Update the lemma for a term.
     *
     * @param int    $termId Term ID
     * @param string $lemma  The lemma to set
     */
    private function updateTermLemma(int $termId, string $lemma): void
    {
        $lemmaLc = mb_strtolower($lemma, 'UTF-8');
        $bindings = [$lemma, $lemmaLc, $termId];

        Connection::preparedExecute(
            "UPDATE words SET WoLemma = ?, WoLemmaLC = ? WHERE WoID = ?"
            . UserScopedQuery::forTablePrepared('words', $bindings),
            $bindings
        );
    }

    /**
     * Set lemma for a specific term.
     *
     * @param int    $termId Term ID
     * @param string $lemma  The lemma to set
     *
     * @return bool True if updated
     */
    public function setLemma(int $termId, string $lemma): bool
    {
        return $this->repository->updateLemma($termId, $lemma);
    }

    /**
     * Copy lemma from one term to all related terms.
     *
     * When a user sets a lemma for "running", this can propagate
     * the lemma "run" to other forms like "runs", "ran" if they
     * match the lemmatizer's suggestions.
     *
     * @param int    $termId       Source term ID
     * @param int    $languageId   Language ID
     * @param string $languageCode Language code for lemmatizer
     *
     * @return int Number of terms updated
     */
    public function propagateLemma(int $termId, int $languageId, string $languageCode): int
    {
        $term = $this->repository->find($termId);
        if ($term === null) {
            return 0;
        }

        $lemma = $term->lemma();
        $lemmaLc = $term->lemmaLc();

        if ($lemma === null || $lemmaLc === null) {
            return 0;
        }

        // Find other terms in the same language without a lemma
        // that the lemmatizer suggests should have the same lemma
        $bindings = [$languageId, $termId];
        $userScope = UserScopedQuery::forTablePrepared('words', $bindings);
        $candidates = Connection::preparedFetchAll(
            "SELECT WoID, WoTextLC FROM words
             WHERE WoLgID = ?
               AND WoWordCount = 1
               AND (WoLemma IS NULL OR WoLemma = '')
               AND WoID != ?{$userScope}",
            $bindings
        );

        $updated = 0;
        foreach ($candidates as $candidate) {
            $wordText = (string)($candidate['WoTextLC'] ?? '');
            $suggestedLemma = $this->lemmatizer->lemmatize($wordText, $languageCode);
            if ($suggestedLemma !== null && mb_strtolower($suggestedLemma, 'UTF-8') === $lemmaLc) {
                $this->updateTermLemma((int)($candidate['WoID'] ?? 0), $lemma);
                $updated++;
            }
        }

        return $updated;
    }

    /**
     * Link unmatched text items to words by lemma.
     *
     * When a text item doesn't have an exact word match (Ti2WoID IS NULL),
     * this method tries to find a word whose lemma matches the text item's
     * lemmatized form.
     *
     * Example: Text item "runs" with no exact match -> lemmatize to "run"
     * -> find word with WoLemmaLC = "run" -> link text item to that word
     *
     * @param int    $languageId   Language ID
     * @param string $languageCode ISO language code for lemmatizer
     * @param int|null $textId     Optional: limit to specific text
     *
     * @return array{linked: int, unmatched: int, errors: int}
     */
    public function linkTextItemsByLemma(
        int $languageId,
        string $languageCode,
        ?int $textId = null
    ): array {
        if (!$this->lemmatizer->supportsLanguage($languageCode)) {
            return ['linked' => 0, 'unmatched' => 0, 'errors' => 0];
        }

        $stats = ['linked' => 0, 'unmatched' => 0, 'errors' => 0];

        // Get unmatched single-word text items
        $unmatchedItems = $this->fetchUnmatchedTextItems($languageId, $textId);

        if (empty($unmatchedItems)) {
            return $stats;
        }

        // Group items by their lowercase text for efficient processing
        $itemsByText = [];
        foreach ($unmatchedItems as $item) {
            $textLc = (string)($item['Ti2TextLC'] ?? mb_strtolower((string)$item['Ti2Text'], 'UTF-8'));
            if (!isset($itemsByText[$textLc])) {
                $itemsByText[$textLc] = [];
            }
            $itemsByText[$textLc][] = $item;
        }

        // Batch lemmatize all unique words
        $lemmas = $this->lemmatizer->lemmatizeBatch(array_keys($itemsByText), $languageCode);

        // Find words by lemma and link
        foreach ($itemsByText as $textLc => $items) {
            $lemmaLc = $lemmas[$textLc] ?? null;

            if ($lemmaLc === null) {
                // Can't lemmatize this word - try matching text directly to lemma
                $lemmaLc = $textLc;
            }

            $lemmaLc = mb_strtolower($lemmaLc, 'UTF-8');

            // Find a word with this lemma
            $wordId = $this->findWordIdByLemma($languageId, $lemmaLc);

            if ($wordId !== null) {
                // Link all text items with this text to the found word
                $linkedCount = $this->linkItemsToWord($items, $wordId);
                $stats['linked'] += $linkedCount;
            } else {
                $stats['unmatched'] += count($items);
            }
        }

        return $stats;
    }

    /**
     * Fetch unmatched text items (Ti2WoID IS NULL) for a language.
     *
     * @param int      $languageId Language ID
     * @param int|null $textId     Optional text ID filter
     *
     * @return array<int, array<string, mixed>>
     */
    private function fetchUnmatchedTextItems(int $languageId, ?int $textId = null): array
    {
        // word_occurrences is not auto-scoped — inherit user scope through
        // texts.TxUsID (joined via Ti2TxID) so cross-user rows never sneak
        // into the batch.
        $bindings = [$languageId];
        $sql = "SELECT ti.Ti2ID, ti.Ti2Text, LOWER(ti.Ti2Text) as Ti2TextLC, ti.Ti2TxID
                FROM word_occurrences ti
                JOIN texts ON ti.Ti2TxID = TxID
                WHERE ti.Ti2LgID = ?
                  AND ti.Ti2WoID IS NULL
                  AND ti.Ti2WordCount = 1"
                . UserScopedQuery::forTablePrepared('texts', $bindings);

        if ($textId !== null) {
            $sql .= " AND ti.Ti2TxID = ?";
            $bindings[] = $textId;
        }

        $sql .= " ORDER BY ti.Ti2Text";

        return Connection::preparedFetchAll($sql, $bindings);
    }

    /**
     * Find a word ID by its lemma.
     *
     * Returns the word that has this lemma (preferring the base form).
     *
     * @param int    $languageId Language ID
     * @param string $lemmaLc    Lowercase lemma to match
     *
     * @return int|null Word ID or null if not found
     */
    public function findWordIdByLemma(int $languageId, string $lemmaLc): ?int
    {
        $bindings = [$languageId, $lemmaLc, $lemmaLc];
        $userScope = UserScopedQuery::forTablePrepared('words', $bindings);
        $bindings[] = $lemmaLc;

        // Prefer words where WoTextLC equals the lemma (base form),
        // otherwise any word with matching lemma
        $row = Connection::preparedFetchOne(
            "SELECT WoID FROM words
             WHERE WoLgID = ?
               AND WoWordCount = 1
               AND (WoLemmaLC = ? OR WoTextLC = ?){$userScope}
             ORDER BY CASE WHEN WoTextLC = ? THEN 0 ELSE 1 END, WoID
             LIMIT 1",
            $bindings
        );

        return $row !== null ? (int)$row['WoID'] : null;
    }

    /**
     * Link text items to a word.
     *
     * @param array<int, array<string, mixed>> $items  Text items to link
     * @param int                              $wordId Word ID to link to
     *
     * @return int Number of items linked
     */
    private function linkItemsToWord(array $items, int $wordId): int
    {
        if (empty($items)) {
            return 0;
        }

        $itemIds = array_map(
            fn(array $item) => (int)($item['Ti2ID'] ?? 0),
            $items
        );
        $itemIds = array_filter($itemIds, fn(int $id) => $id > 0);

        if (empty($itemIds)) {
            return 0;
        }

        $placeholders = implode(',', array_fill(0, count($itemIds), '?'));
        $bindings = array_merge([$wordId], $itemIds);

        return Connection::preparedExecute(
            "UPDATE word_occurrences SET Ti2WoID = ? WHERE Ti2ID IN ({$placeholders})",
            $bindings
        );
    }

    /**
     * Link text items directly using SQL (efficient for large datasets).
     *
     * This method links text items to words where the text item's lowercase text
     * matches a word's lemma. It's more efficient than the PHP-based approach
     * for large datasets.
     *
     * @param int      $languageId Language ID
     * @param int|null $textId     Optional text ID filter
     *
     * @return int Number of text items linked
     */
    public function linkTextItemsByLemmaSql(int $languageId, ?int $textId = null): int
    {
        // Scope both the words subquery (by WoUsID) and the unmatched text
        // items pool (by TxUsID, joined via Ti2TxID → texts) so this never
        // links one user's rows to another user's vocabulary.
        $bindings = [$languageId];
        $wordsScope = UserScopedQuery::forTablePrepared('words', $bindings, 'w');
        $bindings[] = $languageId;
        $itemsScope = UserScopedQuery::forTablePrepared('texts', $bindings);

        $sql = "UPDATE word_occurrences ti
                JOIN (
                    SELECT ti2.Ti2ID,
                           (SELECT w.WoID FROM words w
                            WHERE w.WoLgID = ?
                              AND w.WoWordCount = 1
                              AND (w.WoLemmaLC = LOWER(ti2.Ti2Text) OR w.WoTextLC = LOWER(ti2.Ti2Text))"
                              . $wordsScope . "
                            ORDER BY CASE
                                WHEN w.WoTextLC = LOWER(ti2.Ti2Text) THEN 0
                                WHEN w.WoLemmaLC = LOWER(ti2.Ti2Text) THEN 1
                                ELSE 2
                            END, w.WoID
                            LIMIT 1
                           ) as MatchedWoID
                    FROM word_occurrences ti2
                    JOIN texts ON ti2.Ti2TxID = TxID
                    WHERE ti2.Ti2LgID = ?
                      AND ti2.Ti2WoID IS NULL
                      AND ti2.Ti2WordCount = 1"
                  . $itemsScope;

        if ($textId !== null) {
            $sql .= " AND ti2.Ti2TxID = ?";
            $bindings[] = $textId;
        }

        $sql .= ") AS matches ON ti.Ti2ID = matches.Ti2ID
                SET ti.Ti2WoID = matches.MatchedWoID
                WHERE matches.MatchedWoID IS NOT NULL";

        return Connection::preparedExecute($sql, $bindings);
    }
}
