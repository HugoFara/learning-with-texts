<?php

/**
 * Word Family Service
 *
 * Handles word family queries, details, and status updates.
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
use Lwt\Modules\Vocabulary\Domain\ValueObject\TermStatus;
use Lwt\Modules\Vocabulary\Infrastructure\MySqlTermRepository;
use Lwt\Shared\Infrastructure\Database\Connection;
use Lwt\Shared\Infrastructure\Database\UserScopedQuery;

/**
 * Service for word family queries, details, and status updates.
 *
 * @since 3.0.0
 */
class WordFamilyService
{
    private MySqlTermRepository $repository;

    /**
     * Constructor.
     *
     * @param MySqlTermRepository $repository Term repository
     */
    public function __construct(MySqlTermRepository $repository)
    {
        $this->repository = $repository;
    }

    /**
     * Get the word family (all words sharing a lemma).
     *
     * @param int    $languageId Language ID
     * @param string $lemmaLc    Lowercase lemma
     *
     * @return Term[] Array of terms in the word family
     */
    public function getWordFamily(int $languageId, string $lemmaLc): array
    {
        return $this->repository->findByLemma($languageId, $lemmaLc);
    }

    /**
     * Get words grouped by their lemma.
     *
     * @param int $languageId Language ID
     * @param int $limit      Maximum number of lemma groups to return
     *
     * @return array<string, array{lemma: string, count: int, terms: string[]}>
     */
    public function getWordFamilies(int $languageId, int $limit = 50): array
    {
        $bindings = [$languageId];
        $userScope = UserScopedQuery::forTablePrepared('words', $bindings);
        $bindings[] = $limit;

        $groups = Connection::preparedFetchAll(
            "SELECT WoLemma, WoLemmaLC, COUNT(*) as family_size,
                    GROUP_CONCAT(WoText ORDER BY WoText SEPARATOR ', ') as terms
             FROM words
             WHERE WoLgID = ?
               AND WoLemmaLC IS NOT NULL
               AND WoLemmaLC != ''{$userScope}
             GROUP BY WoLemmaLC
             HAVING family_size > 1
             ORDER BY family_size DESC, WoLemma
             LIMIT ?",
            $bindings
        );

        $result = [];
        foreach ($groups as $group) {
            $lemmaLc = (string) $group['WoLemmaLC'];
            $result[$lemmaLc] = [
                'lemma' => (string) $group['WoLemma'],
                'count' => (int) $group['family_size'],
                'terms' => explode(', ', (string) $group['terms']),
            ];
        }

        return $result;
    }

    /**
     * Find terms that might benefit from lemmatization.
     *
     * Identifies terms with similar text that could share a lemma.
     *
     * @param int $languageId Language ID
     * @param int $limit      Maximum suggestions
     *
     * @return array<int, array{base: string, variants: string[]}>
     */
    public function findPotentialLemmaGroups(int $languageId, int $limit = 20): array
    {
        // Find words without lemma that share common prefixes
        $bindings = [$languageId, $languageId];
        $userScope = UserScopedQuery::forTablePrepared('words', $bindings, 'w1');
        $bindings[] = $limit;

        $results = Connection::preparedFetchAll(
            "SELECT w1.WoText as base_word, w1.WoTextLC as base_lc,
                    GROUP_CONCAT(DISTINCT w2.WoText ORDER BY w2.WoText SEPARATOR ', ') as variants
             FROM words w1
             JOIN words w2 ON w2.WoLgID = w1.WoLgID
                          AND w2.WoID != w1.WoID
                          AND LEFT(w2.WoTextLC, CHAR_LENGTH(w1.WoTextLC)) = w1.WoTextLC
                          AND CHAR_LENGTH(w2.WoTextLC) > CHAR_LENGTH(w1.WoTextLC)
                          AND CHAR_LENGTH(w2.WoTextLC) <= CHAR_LENGTH(w1.WoTextLC) + 5
             WHERE w1.WoLgID = ?
               AND w1.WoWordCount = 1
               AND w1.WoLemma IS NULL
               AND w2.WoLemma IS NULL
               AND w2.WoLgID = ?
               AND w2.WoWordCount = 1{$userScope}
             GROUP BY w1.WoID
             HAVING COUNT(DISTINCT w2.WoID) >= 1
             ORDER BY COUNT(DISTINCT w2.WoID) DESC
             LIMIT ?",
            $bindings
        );

        $suggestions = [];
        foreach ($results as $row) {
            $suggestions[] = [
                'base' => (string) $row['base_word'],
                'variants' => explode(', ', (string) $row['variants']),
            ];
        }

        return $suggestions;
    }

    /**
     * Get detailed word family information for a term.
     *
     * Returns all words sharing the same lemma with full details for display.
     *
     * @param int $termId Term ID to get family for
     *
     * @return array{lemma: string, lemmaLc: string, langId: int, terms: array, stats: array}|null
     */
    public function getWordFamilyDetails(int $termId): ?array
    {
        // Get the term's lemma
        $bindings = [$termId];
        $term = Connection::preparedFetchOne(
            "SELECT WoID, WoLemma, WoLemmaLC, WoLgID FROM words WHERE WoID = ?"
            . UserScopedQuery::forTablePrepared('words', $bindings),
            $bindings
        );

        if ($term === null) {
            return null;
        }

        $lemmaLc = (string) ($term['WoLemmaLC'] ?? '');
        if ($lemmaLc === '') {
            // Term has no lemma, return just itself
            return $this->buildSingleTermFamily($termId);
        }

        $languageId = (int) $term['WoLgID'];

        // Get all family members
        $bindings = [$languageId, $lemmaLc];
        $userScope = UserScopedQuery::forTablePrepared('words', $bindings);
        $members = Connection::preparedFetchAll(
            "SELECT WoID, WoText, WoTextLC, WoLemma, WoTranslation, WoRomanization,
                    WoStatus, WoStatusChanged, WoWordCount
             FROM words
             WHERE WoLgID = ? AND WoLemmaLC = ?{$userScope}
             ORDER BY WoWordCount ASC, WoStatus DESC, WoText ASC",
            $bindings
        );

        $terms = [];
        $statusCounts = array_fill_keys(TermStatus::values(), 0);
        $totalOccurrences = 0;

        foreach ($members as $member) {
            $status = (int) $member['WoStatus'];
            $statusCounts[$status]++;

            // Get occurrence count for this word
            $occurrences = $this->getWordOccurrenceCount((int) $member['WoID']);
            $totalOccurrences += $occurrences;

            $terms[] = [
                'id' => (int) $member['WoID'],
                'text' => (string) $member['WoText'],
                'textLc' => (string) $member['WoTextLC'],
                'translation' => (string) ($member['WoTranslation'] ?? ''),
                'romanization' => (string) ($member['WoRomanization'] ?? ''),
                'status' => $status,
                'statusChanged' => (string) ($member['WoStatusChanged'] ?? ''),
                'wordCount' => (int) $member['WoWordCount'],
                'occurrences' => $occurrences,
                'isBaseForm' => mb_strtolower((string) $member['WoText'], 'UTF-8') === $lemmaLc,
            ];
        }

        // Calculate aggregate statistics
        $averageStatus = count($terms) > 0
            ? array_sum(array_map(fn($t) => $t['status'] <= 5 ? $t['status'] : 0, $terms))
                / count(array_filter($terms, fn($t) => $t['status'] <= 5))
            : 0;

        return [
            'lemma' => (string) ($term['WoLemma'] ?? ''),
            'lemmaLc' => $lemmaLc,
            'langId' => $languageId,
            'terms' => $terms,
            'stats' => [
                'formCount' => count($terms),
                'statusCounts' => $statusCounts,
                'averageStatus' => round($averageStatus, 1),
                'totalOccurrences' => $totalOccurrences,
                'knownCount' => $statusCounts[5] + $statusCounts[99],
                'learningCount' => $statusCounts[1] + $statusCounts[2] + $statusCounts[3] + $statusCounts[4],
                'ignoredCount' => $statusCounts[98],
            ],
        ];
    }

    /**
     * Build a "family" response for a term without a lemma.
     *
     * @param int $termId Term ID
     *
     * @return array{lemma: string, lemmaLc: string, langId: int, terms: array, stats: array}|null
     */
    private function buildSingleTermFamily(int $termId): ?array
    {
        $bindings = [$termId];
        $term = Connection::preparedFetchOne(
            "SELECT WoID, WoText, WoTextLC, WoLemma, WoTranslation, WoRomanization,
                    WoStatus, WoStatusChanged, WoWordCount, WoLgID
             FROM words WHERE WoID = ?"
            . UserScopedQuery::forTablePrepared('words', $bindings),
            $bindings
        );

        if ($term === null) {
            return null;
        }

        $occurrences = $this->getWordOccurrenceCount($termId);
        $status = (int) $term['WoStatus'];

        return [
            'lemma' => (string) ($term['WoText'] ?? ''),
            'lemmaLc' => (string) ($term['WoTextLC'] ?? ''),
            'langId' => (int) $term['WoLgID'],
            'terms' => [[
                'id' => $termId,
                'text' => (string) $term['WoText'],
                'textLc' => (string) $term['WoTextLC'],
                'translation' => (string) ($term['WoTranslation'] ?? ''),
                'romanization' => (string) ($term['WoRomanization'] ?? ''),
                'status' => $status,
                'statusChanged' => (string) ($term['WoStatusChanged'] ?? ''),
                'wordCount' => (int) $term['WoWordCount'],
                'occurrences' => $occurrences,
                'isBaseForm' => true,
            ]],
            'stats' => [
                'formCount' => 1,
                'statusCounts' => array_merge(
                    array_fill_keys(TermStatus::values(), 0),
                    [$status => 1]
                ),
                'averageStatus' => $status <= 5 ? (float) $status : 0.0,
                'totalOccurrences' => $occurrences,
                'knownCount' => TermStatus::isKnownValue($status) ? 1 : 0,
                'learningCount' => $status >= 1 && $status <= 4 ? 1 : 0,
                'ignoredCount' => TermStatus::isIgnoredValue($status) ? 1 : 0,
            ],
        ];
    }

    /**
     * Get occurrence count for a word across all texts.
     *
     * @param int $wordId Word ID
     *
     * @return int
     */
    private function getWordOccurrenceCount(int $wordId): int
    {
        return (int) Connection::preparedFetchValue(
            "SELECT COUNT(*) as cnt FROM word_occurrences WHERE Ti2WoID = ?",
            [$wordId],
            'cnt'
        );
    }

    /**
     * Update status for all words in a word family.
     *
     * @param int $languageId Language ID
     * @param string $lemmaLc Lowercase lemma
     * @param int $status New status (1-5, 98, 99)
     *
     * @return int Number of words updated
     */
    public function updateWordFamilyStatus(int $languageId, string $lemmaLc, int $status): int
    {
        if (!TermStatus::isValid($status)) {
            return 0;
        }

        $bindings = [$status, $languageId, $lemmaLc];

        return Connection::preparedExecute(
            "UPDATE words SET WoStatus = ?, WoStatusChanged = NOW()
             WHERE WoLgID = ? AND WoLemmaLC = ?"
            . UserScopedQuery::forTablePrepared('words', $bindings),
            $bindings
        );
    }

    /**
     * Get paginated list of word families for a language.
     *
     * @param int    $languageId Language ID
     * @param int    $page       Page number (1-based)
     * @param int    $perPage    Items per page
     * @param string $sortBy     Sort field: 'lemma', 'count', 'status'
     * @param string $sortDir    Sort direction: 'asc', 'desc'
     *
     * @return array{families: array, pagination: array}
     */
    public function getWordFamilyList(
        int $languageId,
        int $page = 1,
        int $perPage = 50,
        string $sortBy = 'lemma',
        string $sortDir = 'asc'
    ): array {
        $page = max(1, $page);
        $perPage = max(1, min(100, $perPage));
        $offset = ($page - 1) * $perPage;

        // Get total count of families
        $bindings = [$languageId];
        $total = (int) Connection::preparedFetchValue(
            "SELECT COUNT(DISTINCT WoLemmaLC) as cnt FROM words
             WHERE WoLgID = ? AND WoLemmaLC IS NOT NULL AND WoLemmaLC != ''"
            . UserScopedQuery::forTablePrepared('words', $bindings),
            $bindings,
            'cnt'
        );

        // Determine sort clause
        $sortClause = match ($sortBy) {
            'count' => 'family_size ' . ($sortDir === 'desc' ? 'DESC' : 'ASC'),
            'status' => 'avg_status ' . ($sortDir === 'desc' ? 'DESC' : 'ASC'),
            default => 'WoLemma ' . ($sortDir === 'desc' ? 'DESC' : 'ASC'),
        };

        // Get families with aggregated data
        $bindings = [$languageId];
        $userScope = UserScopedQuery::forTablePrepared('words', $bindings);
        $bindings[] = $perPage;
        $bindings[] = $offset;
        $rows = Connection::preparedFetchAll(
            "SELECT WoLemma, WoLemmaLC,
                    COUNT(*) as family_size,
                    AVG(CASE WHEN WoStatus <= 5 THEN WoStatus ELSE NULL END) as avg_status,
                    SUM(CASE WHEN WoStatus IN (5, 99) THEN 1 ELSE 0 END) as known_count,
                    SUM(CASE WHEN WoStatus BETWEEN 1 AND 4 THEN 1 ELSE 0 END) as learning_count,
                    GROUP_CONCAT(WoText ORDER BY WoWordCount, WoText SEPARATOR ', ') as forms
             FROM words
             WHERE WoLgID = ? AND WoLemmaLC IS NOT NULL AND WoLemmaLC != ''{$userScope}
             GROUP BY WoLemmaLC
             ORDER BY {$sortClause}, WoLemma
             LIMIT ? OFFSET ?",
            $bindings
        );

        $families = [];
        foreach ($rows as $row) {
            $families[] = [
                'lemma' => (string) $row['WoLemma'],
                'lemmaLc' => (string) $row['WoLemmaLC'],
                'formCount' => (int) $row['family_size'],
                'averageStatus' => round((float) ($row['avg_status'] ?? 0), 1),
                'knownCount' => (int) $row['known_count'],
                'learningCount' => (int) $row['learning_count'],
                'forms' => (string) $row['forms'],
            ];
        }

        $totalPages = (int) ceil($total / $perPage);

        return [
            'families' => $families,
            'pagination' => [
                'page' => $page,
                'perPage' => $perPage,
                'total' => $total,
                'totalPages' => $totalPages,
            ],
        ];
    }

    /**
     * Get word family by lemma directly (without requiring a term ID).
     *
     * @param int    $languageId Language ID
     * @param string $lemmaLc    Lowercase lemma
     *
     * @return array|null
     */
    public function getWordFamilyByLemma(int $languageId, string $lemmaLc): ?array
    {
        if ($lemmaLc === '') {
            return null;
        }

        // Find any term with this lemma
        $bindings = [$languageId, $lemmaLc];
        $userScope = UserScopedQuery::forTablePrepared('words', $bindings);
        $term = Connection::preparedFetchOne(
            "SELECT WoID FROM words
             WHERE WoLgID = ? AND WoLemmaLC = ?{$userScope}
             LIMIT 1",
            $bindings
        );

        if ($term === null) {
            return null;
        }

        return $this->getWordFamilyDetails((int) $term['WoID']);
    }

    /**
     * Suggest status update for related forms when one form's status changes.
     *
     * Based on the "suggested" inheritance mode from the proposal.
     *
     * @param int $termId    Term that was updated
     * @param int $newStatus The new status that was set
     *
     * @return array{suggestion: string, affected_count: int, term_ids: int[]}
     */
    public function getSuggestedFamilyUpdate(int $termId, int $newStatus): array
    {
        $bindings = [$termId];
        $term = Connection::preparedFetchOne(
            "SELECT WoLemmaLC, WoLgID FROM words WHERE WoID = ?"
            . UserScopedQuery::forTablePrepared('words', $bindings),
            $bindings
        );

        if ($term === null || empty($term['WoLemmaLC'])) {
            return [
                'suggestion' => 'none',
                'affected_count' => 0,
                'term_ids' => [],
            ];
        }

        $lemmaLc = (string) $term['WoLemmaLC'];
        $languageId = (int) $term['WoLgID'];

        // Find family members with lower status (for "known" updates)
        // or higher status (for "learning" updates)
        if (TermStatus::isKnownValue($newStatus)) {
            // Marked as known - suggest updating forms with lower status
            $bindings = [$languageId, $lemmaLc, $termId];
            $affected = Connection::preparedFetchAll(
                "SELECT WoID, WoText, WoStatus FROM words
                 WHERE WoLgID = ? AND WoLemmaLC = ? AND WoID != ?
                   AND WoStatus < 5 AND WoStatus != 98"
                . UserScopedQuery::forTablePrepared('words', $bindings),
                $bindings
            );

            if (!empty($affected)) {
                return [
                    'suggestion' => 'mark_family_known',
                    'affected_count' => count($affected),
                    'term_ids' => array_map(fn($r) => (int) $r['WoID'], $affected),
                ];
            }
        } elseif ($newStatus >= 1 && $newStatus <= 4) {
            // Learning status - check if any forms are marked higher
            $bindings = [$languageId, $lemmaLc, $termId, $newStatus];
            $affected = Connection::preparedFetchAll(
                "SELECT WoID, WoText, WoStatus FROM words
                 WHERE WoLgID = ? AND WoLemmaLC = ? AND WoID != ?
                   AND WoStatus > ? AND WoStatus NOT IN (98, 99)"
                . UserScopedQuery::forTablePrepared('words', $bindings),
                $bindings
            );

            if (!empty($affected)) {
                return [
                    'suggestion' => 'sync_family_status',
                    'affected_count' => count($affected),
                    'term_ids' => array_map(fn($r) => (int) $r['WoID'], $affected),
                ];
            }
        }

        return [
            'suggestion' => 'none',
            'affected_count' => 0,
            'term_ids' => [],
        ];
    }

    /**
     * Apply status to multiple terms (for bulk family updates).
     *
     * @param int[] $termIds Term IDs to update
     * @param int   $status  New status
     *
     * @return int Number of terms updated
     */
    public function bulkUpdateTermStatus(array $termIds, int $status): int
    {
        if (empty($termIds) || !TermStatus::isValid($status)) {
            return 0;
        }

        $placeholders = implode(',', array_fill(0, count($termIds), '?'));
        /** @var array<int, int|string> $bindings */
        $bindings = array_merge([$status], $termIds);
        $userScope = UserScopedQuery::forTablePrepared('words', $bindings);

        return Connection::preparedExecute(
            "UPDATE words SET WoStatus = ?, WoStatusChanged = NOW()
             WHERE WoID IN ({$placeholders}){$userScope}",
            $bindings
        );
    }
}
