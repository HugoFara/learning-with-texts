<?php

/**
 * Get Intensity Statistics Use Case
 *
 * PHP version 8.2
 *
 * @category Lwt
 * @package  Lwt\Modules\User\Application\UseCases\Statistics
 * @author   HugoFara <git@hugofara.net>
 * @license  Unlicense <http://unlicense.org/>
 * @link     https://hugofara.github.io/lwt/developer/api
 * @since    3.0.0
 */

declare(strict_types=1);

namespace Lwt\Modules\User\Application\UseCases\Statistics;

use Lwt\Modules\Admin\Infrastructure\MySqlStatisticsRepository;

/**
 * Use case for getting term intensity statistics.
 *
 * Returns term counts grouped by language and status.
 *
 * @since 3.0.0
 */
class GetIntensityStatistics
{
    private MySqlStatisticsRepository $repository;

    /**
     * Constructor.
     *
     * @param MySqlStatisticsRepository|null $repository Statistics repository
     */
    public function __construct(?MySqlStatisticsRepository $repository = null)
    {
        $this->repository = $repository ?? new MySqlStatisticsRepository();
    }

    /**
     * Execute the use case.
     *
     * @return array{languages: array, totals: array} Statistics data
     */
    public function execute(): array
    {
        $termStat = $this->repository->getTermCountsByLanguageAndStatus();
        $languages = $this->repository->getLanguageList();

        $totals = [
            's1' => 0, 's2' => 0, 's3' => 0, 's4' => 0, 's5' => 0,
            's98' => 0, 's99' => 0, 's14' => 0, 's15' => 0, 's599' => 0, 'all' => 0
        ];

        $languageStats = [];

        foreach ($languages as $language) {
            $lgId = (string) $language['LgID'];

            $s1 = $termStat[$lgId][1] ?? 0;
            $s2 = $termStat[$lgId][2] ?? 0;
            $s3 = $termStat[$lgId][3] ?? 0;
            $s4 = $termStat[$lgId][4] ?? 0;
            $s5 = $termStat[$lgId][5] ?? 0;
            $s98 = $termStat[$lgId][98] ?? 0;
            $s99 = $termStat[$lgId][99] ?? 0;
            $s14 = $s1 + $s2 + $s3 + $s4;
            $s15 = $s14 + $s5;
            $s599 = $s5 + $s99;
            $all = $s15 + $s98 + $s99;

            $languageStats[] = [
                'id' => $lgId,
                'name' => $language['LgName'],
                's1' => $s1, 's2' => $s2, 's3' => $s3, 's4' => $s4, 's5' => $s5,
                's98' => $s98, 's99' => $s99, 's14' => $s14, 's15' => $s15,
                's599' => $s599, 'all' => $all
            ];

            $totals['s1'] += $s1;
            $totals['s2'] += $s2;
            $totals['s3'] += $s3;
            $totals['s4'] += $s4;
            $totals['s5'] += $s5;
            $totals['s98'] += $s98;
            $totals['s99'] += $s99;
            $totals['s14'] += $s14;
            $totals['s15'] += $s15;
            $totals['s599'] += $s599;
            $totals['all'] += $all;
        }

        return [
            'languages' => $languageStats,
            'totals' => $totals
        ];
    }
}
