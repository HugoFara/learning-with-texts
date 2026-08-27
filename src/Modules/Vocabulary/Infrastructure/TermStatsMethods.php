<?php

/**
 * Term Stats Methods Trait
 *
 * PHP version 8.1
 *
 * @category Lwt
 * @package  Lwt\Modules\Vocabulary\Infrastructure
 * @author   HugoFara <git@hugofara.net>
 * @license  Unlicense <http://unlicense.org/>
 * @link     https://hugofara.github.io/lwt/developer/api
 * @since    3.0.0
 */

declare(strict_types=1);

namespace Lwt\Modules\Vocabulary\Infrastructure;

use Lwt\Shared\Infrastructure\Database\Connection;
use Lwt\Shared\Infrastructure\Database\QueryBuilder;
use Lwt\Modules\Vocabulary\Domain\ValueObject\TermStatus;

/**
 * Statistics, update, and bulk operation methods for MySqlTermRepository.
 *
 * Provides methods for updating individual term fields, bulk operations
 * on multiple terms, and statistical/distribution queries.
 *
 * @since 3.0.0
 */
trait TermStatsMethods
{
    /**
     * Get a query builder for this repository's table.
     *
     * @return QueryBuilder
     */
    abstract protected function query(): QueryBuilder;

    /**
     * {@inheritdoc}
     */
    public function updateStatus(int $termId, int $status): bool
    {
        $affected = $this->query()
            ->where('WoID', '=', $termId)
            ->updatePrepared([
                'WoStatus' => $status,
                'WoStatusChanged' => date('Y-m-d H:i:s'),
            ]);

        return $affected > 0;
    }

    /**
     * {@inheritdoc}
     */
    public function updateTranslation(int $termId, string $translation): bool
    {
        $affected = $this->query()
            ->where('WoID', '=', $termId)
            ->updatePrepared(['WoTranslation' => $translation]);

        return $affected > 0;
    }

    /**
     * {@inheritdoc}
     */
    public function updateRomanization(int $termId, string $romanization): bool
    {
        $affected = $this->query()
            ->where('WoID', '=', $termId)
            ->updatePrepared(['WoRomanization' => $romanization]);

        return $affected > 0;
    }

    /**
     * Update the example sentence of a term.
     *
     * @param int    $termId   Term ID
     * @param string $sentence New sentence
     *
     * @return bool True if updated
     */
    public function updateSentence(int $termId, string $sentence): bool
    {
        $affected = $this->query()
            ->where('WoID', '=', $termId)
            ->updatePrepared(['WoSentence' => $sentence]);

        return $affected > 0;
    }

    /**
     * Update the notes of a term.
     *
     * @param int    $termId Term ID
     * @param string $notes  New notes
     *
     * @return bool True if updated
     */
    public function updateNotes(int $termId, string $notes): bool
    {
        $affected = $this->query()
            ->where('WoID', '=', $termId)
            ->updatePrepared(['WoNotes' => $notes]);

        return $affected > 0;
    }

    /**
     * Update the lemma (base form) of a term.
     *
     * @param int         $termId Term ID
     * @param string|null $lemma  New lemma (null to clear)
     *
     * @return bool True if updated
     */
    public function updateLemma(int $termId, ?string $lemma): bool
    {
        $lemmaLc = $lemma !== null && $lemma !== '' ? mb_strtolower($lemma, 'UTF-8') : null;

        $affected = $this->query()
            ->where('WoID', '=', $termId)
            ->updatePrepared([
                'WoLemma' => $lemma,
                'WoLemmaLC' => $lemmaLc,
            ]);

        return $affected > 0;
    }


    /**
     * Get language IDs that have terms.
     *
     * @return int[] Array of language IDs
     */
    public function getLanguagesWithTerms(): array
    {
        $rows = $this->query()
            ->select('DISTINCT WoLgID')
            ->getPrepared();

        return array_map(
            fn(array $row) => (int) $row['WoLgID'],
            $rows
        );
    }

    /**
     * {@inheritdoc}
     */
    public function getStatistics(?int $languageId = null): array
    {
        $baseQuery = $this->query();
        if ($languageId !== null) {
            $baseQuery->where('WoLgID', '=', $languageId);
        }

        $total = (clone $baseQuery)->countPrepared();

        $learning = (clone $baseQuery)
            ->whereIn('WoStatus', [
                TermStatus::NEW,
                TermStatus::LEARNING_2,
                TermStatus::LEARNING_3,
                TermStatus::LEARNING_4
            ])
            ->countPrepared();

        $known = (clone $baseQuery)
            ->whereIn('WoStatus', [TermStatus::LEARNED, TermStatus::WELL_KNOWN])
            ->countPrepared();

        $ignored = (clone $baseQuery)
            ->where('WoStatus', '=', TermStatus::IGNORED)
            ->countPrepared();

        $multiWord = (clone $baseQuery)
            ->where('WoWordCount', '>', 1)
            ->countPrepared();

        return [
            'total' => $total,
            'learning' => $learning,
            'known' => $known,
            'ignored' => $ignored,
            'multi_word' => $multiWord,
        ];
    }

    /**
     * Get status distribution counts.
     *
     * @param int|null $languageId Language ID (null for all)
     *
     * @return array<int, int> Status value => count
     */
    public function getStatusDistribution(?int $languageId = null): array
    {
        $baseQuery = $this->query();
        if ($languageId !== null) {
            $baseQuery->where('WoLgID', '=', $languageId);
        }

        $statuses = [
            TermStatus::NEW,
            TermStatus::LEARNING_2,
            TermStatus::LEARNING_3,
            TermStatus::LEARNING_4,
            TermStatus::LEARNED,
            TermStatus::IGNORED,
            TermStatus::WELL_KNOWN,
        ];

        $distribution = [];
        foreach ($statuses as $status) {
            $distribution[$status] = (clone $baseQuery)
                ->where('WoStatus', '=', $status)
                ->countPrepared();
        }

        return $distribution;
    }

    /**
     * {@inheritdoc}
     */
    public function deleteMultiple(array $termIds): int
    {
        if (empty($termIds)) {
            return 0;
        }

        return $this->query()
            ->whereIn('WoID', array_map('intval', $termIds))
            ->deletePrepared();
    }

    /**
     * {@inheritdoc}
     */
    public function updateStatusMultiple(array $termIds, int $status): int
    {
        if (empty($termIds)) {
            return 0;
        }

        return $this->query()
            ->whereIn('WoID', array_map('intval', $termIds))
            ->updatePrepared([
                'WoStatus' => $status,
                'WoStatusChanged' => date('Y-m-d H:i:s'),
            ]);
    }

    /**
     * Get term count by word count.
     *
     * @param int|null $languageId Language ID (null for all)
     *
     * @return array<int, int> Word count => term count
     */
    public function getWordCountDistribution(?int $languageId = null): array
    {
        $sql = "SELECT WoWordCount, COUNT(*) as cnt FROM words";
        $params = [];

        if ($languageId !== null) {
            $sql .= " WHERE WoLgID = ?";
            $params[] = $languageId;
        }

        $sql .= " GROUP BY WoWordCount ORDER BY WoWordCount";

        $rows = Connection::preparedFetchAll($sql, $params);

        $distribution = [];
        foreach ($rows as $row) {
            $distribution[(int) $row['WoWordCount']] = (int) $row['cnt'];
        }

        return $distribution;
    }
}
