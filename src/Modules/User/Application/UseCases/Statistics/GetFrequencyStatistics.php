<?php

/**
 * Get Frequency Statistics Use Case
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
 * Use case for getting term frequency statistics.
 *
 * Returns terms created, active, and known by time range.
 *
 * @since 3.0.0
 */
class GetFrequencyStatistics
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
     * @return array{languages: array, totals: array} Frequency statistics
     */
    public function execute(): array
    {
        $termCreated = $this->repository->getTermsCreatedByDay();
        $activityData = $this->repository->getTermActivityByDay();
        $termActive = $activityData['active'];
        $termKnown = $activityData['known'];

        $languages = $this->repository->getLanguageList();

        $totals = [
            'ct' => 0, 'at' => 0, 'kt' => 0,
            'cy' => 0, 'ay' => 0, 'ky' => 0,
            'cw' => 0, 'aw' => 0, 'kw' => 0,
            'cm' => 0, 'am' => 0, 'km' => 0,
            'ca' => 0, 'aa' => 0, 'ka' => 0,
            'call' => 0, 'aall' => 0, 'kall' => 0
        ];

        $languageStats = [];

        foreach ($languages as $language) {
            $lgId = (int) $language['LgID'];

            $stats = $this->calculateFrequencyForLanguage(
                $termCreated[$lgId] ?? [],
                $termActive[$lgId] ?? [],
                $termKnown[$lgId] ?? []
            );

            $stats['id'] = $lgId;
            $stats['name'] = (string) ($language['LgName'] ?? '');
            $languageStats[] = $stats;

            foreach ($stats as $key => $value) {
                if (isset($totals[$key]) && is_int($value)) {
                    $totals[$key] += $value;
                }
            }
        }

        return [
            'languages' => $languageStats,
            'totals' => $totals
        ];
    }

    /**
     * Calculate frequency statistics for a single language.
     *
     * @param array<int, int> $termCreated Terms created data
     * @param array<int, int> $termActive  Terms active data
     * @param array<int, int> $termKnown   Terms known data
     *
     * @return array<string, int|string> Frequency statistics
     */
    private function calculateFrequencyForLanguage(
        array $termCreated,
        array $termActive,
        array $termKnown
    ): array {
        // Calculate created stats
        $cw = 0;
        $cm = 0;
        $ca = 0;
        $call = 0;

        foreach ($termCreated as $created => $val) {
            if ($created === 0) {
                $cw += $val;
            } elseif ($created > 364) {
                $call += $val;
            } elseif ($created > 29) {
                $ca += $val;
            } elseif ($created > 6) {
                $cm += $val;
            } else {
                $cw += $val;
            }
        }

        $ct = $termCreated[0] ?? 0;
        $cy = $termCreated[1] ?? 0;
        $cm += $cw;
        $ca += $cm;
        $call += $ca;

        // Calculate active stats
        $aw = 0;
        $am = 0;
        $aa = 0;
        $aall = 0;

        foreach ($termActive as $active => $val) {
            if ($active === 0) {
                $aw += $val;
            } elseif ($active > 364) {
                $aall += $val;
            } elseif ($active > 29) {
                $aa += $val;
            } elseif ($active > 6) {
                $am += $val;
            } else {
                $aw += $val;
            }
        }

        $at = $termActive[0] ?? 0;
        $ay = $termActive[1] ?? 0;
        $am += $aw;
        $aa += $am;
        $aall += $aa;

        // Calculate known stats
        $kw = 0;
        $km = 0;
        $ka = 0;
        $kall = 0;

        foreach ($termKnown as $known => $val) {
            if ($known === 0) {
                $kw += $val;
            } elseif ($known > 364) {
                $kall += $val;
            } elseif ($known > 29) {
                $ka += $val;
            } elseif ($known > 6) {
                $km += $val;
            } else {
                $kw += $val;
            }
        }

        $kt = $termKnown[0] ?? 0;
        $ky = $termKnown[1] ?? 0;
        $km += $kw;
        $ka += $km;
        $kall += $ka;

        return [
            'ct' => $ct, 'at' => $at, 'kt' => $kt,
            'cy' => $cy, 'ay' => $ay, 'ky' => $ky,
            'cw' => $cw, 'aw' => $aw, 'kw' => $kw,
            'cm' => $cm, 'am' => $am, 'km' => $km,
            'ca' => $ca, 'aa' => $aa, 'ka' => $ka,
            'call' => $call, 'aall' => $aall, 'kall' => $kall
        ];
    }
}
