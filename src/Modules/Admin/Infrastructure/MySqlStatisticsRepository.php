<?php

/**
 * MySQL Statistics Repository
 *
 * PHP version 8.2
 *
 * @category Lwt
 * @package  Lwt\Modules\Admin\Infrastructure
 * @author   HugoFara <git@hugofara.net>
 * @license  Unlicense <http://unlicense.org/>
 * @link     https://hugofara.github.io/lwt/developer/api
 * @since    3.0.0
 */

declare(strict_types=1);

namespace Lwt\Modules\Admin\Infrastructure;

use Lwt\Shared\Infrastructure\Database\QueryBuilder;
use Lwt\Modules\Vocabulary\Domain\ValueObject\TermStatus;

/**
 * MySQL repository for statistics queries.
 *
 * Provides database access for learning statistics.
 *
 * @since 3.0.0
 */
class MySqlStatisticsRepository
{
    /**
     * Get term counts grouped by language and status.
     *
     * @return array<string, array<int, int>> Term counts indexed by language ID and status
     */
    public function getTermCountsByLanguageAndStatus(): array
    {
        $results = QueryBuilder::table('words')
            ->selectRaw('WoLgID, WoStatus, COUNT(*) AS term_count')
            ->groupBy(['WoLgID', 'WoStatus'])
            ->getPrepared();

        $termStat = [];
        foreach ($results as $record) {
            $lgId = (string) $record['WoLgID'];
            $status = (int) $record['WoStatus'];
            $termStat[$lgId][$status] = (int) $record['term_count'];
        }

        return $termStat;
    }

    /**
     * Get list of languages with IDs and names.
     *
     * Returns records with LgID (int) and LgName (string) fields.
     *
     * @return array<int, array<string, mixed>> Language records
     */
    public function getLanguageList(): array
    {
        return QueryBuilder::table('languages')
            ->select(['LgID', 'LgName'])
            ->where('LgName', '<>', '')
            ->orderBy('LgName')
            ->getPrepared();
    }

    /**
     * Get terms created grouped by language and days ago.
     *
     * @return array<int, array<int, int>> Terms by language ID and days since creation
     */
    public function getTermsCreatedByDay(): array
    {
        $results = QueryBuilder::table('words')
            ->select([
                'WoLgID',
                'TO_DAYS(curdate()) - TO_DAYS(cast(WoCreated as date)) AS Created',
                'count(WoID) as value'
            ])
            ->whereIn('WoStatus', [1, 2, 3, 4, 5, 99])
            ->groupBy(['WoLgID', 'Created'])
            ->getPrepared();

        /** @var array<int, array<int, int>> $termCreated */
        $termCreated = [];
        foreach ($results as $record) {
            $lgId = (int) ($record['WoLgID'] ?? 0);
            $created = (int) ($record['Created'] ?? 0);
            $termCreated[$lgId][$created] = (int) ($record['value'] ?? 0);
        }

        return $termCreated;
    }

    /**
     * Get term activity grouped by language and days ago.
     *
     * @return array{active: array<int, array<int, int>>, known: array<int, array<int, int>>}
     */
    public function getTermActivityByDay(): array
    {
        $results = QueryBuilder::table('words')
            ->select([
                'WoLgID',
                'WoStatus',
                'TO_DAYS(curdate()) - TO_DAYS(cast(WoStatusChanged as date)) AS Changed',
                'count(WoID) as value'
            ])
            ->groupBy(['WoLgID', 'WoStatus', 'WoStatusChanged'])
            ->getPrepared();

        /** @var array<int, array<int, int>> $termActive */
        $termActive = [];
        /** @var array<int, array<int, int>> $termKnown */
        $termKnown = [];

        foreach ($results as $record) {
            $status = (int) ($record['WoStatus'] ?? 0);
            if ($status > 0) {
                $lgId = (int) ($record['WoLgID'] ?? 0);
                $changed = (int) ($record['Changed'] ?? 0);
                $value = (int) ($record['value'] ?? 0);

                if (TermStatus::isKnownValue($status)) {
                    if (!isset($termKnown[$lgId][$changed])) {
                        $termKnown[$lgId][$changed] = 0;
                    }
                    $termKnown[$lgId][$changed] += $value;

                    if (!isset($termActive[$lgId][$changed])) {
                        $termActive[$lgId][$changed] = 0;
                    }
                    $termActive[$lgId][$changed] += $value;
                } elseif ($status > 0 && $status < 5) {
                    if (!isset($termActive[$lgId][$changed])) {
                        $termActive[$lgId][$changed] = 0;
                    }
                    $termActive[$lgId][$changed] += $value;
                }
            }
        }

        return [
            'active' => $termActive,
            'known' => $termKnown
        ];
    }

    /**
     * Get language count.
     *
     * @return int Number of languages
     */
    public function getLanguageCount(): int
    {
        return QueryBuilder::table('languages')->count();
    }
}
