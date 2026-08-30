<?php

/**
 * Get Text Statistics Use Case
 *
 * PHP version 8.2
 *
 * @category Lwt
 * @package  Lwt\Modules\Home\Application\UseCases
 * @author   HugoFara <git@hugofara.net>
 * @license  Unlicense <http://unlicense.org/>
 * @link     https://hugofara.github.io/lwt/developer/api
 * @since    3.0.0
 */

declare(strict_types=1);

namespace Lwt\Modules\Home\Application\UseCases;

use Lwt\Modules\Text\Application\Services\TextStatisticsService;

/**
 * Use case for retrieving text statistics for home page display.
 *
 * @since 3.0.0
 */
class GetTextStatistics
{
    private TextStatisticsService $statsService;

    /**
     * Constructor.
     *
     * @param TextStatisticsService|null $statsService Optional stats service
     */
    public function __construct(?TextStatisticsService $statsService = null)
    {
        $this->statsService = $statsService ?? new TextStatisticsService();
    }

    /**
     * Execute the use case.
     *
     * @param int   $textId   Text ID
     * @param array $textInfo Text info from GetDashboardData
     *
     * @return array|null Text info with statistics, or null if no text
     */
    public function execute(int $textId, array $textInfo): ?array
    {
        $textStats = $this->statsService->getTextWordCount([$textId]);
        $todoCount = $this->statsService->getTodoWordsCount($textId);

        // Build statistics array with status counts
        $stats = [
            'unknown' => $todoCount,
            's1' => $textStats['statu'][$textId][1] ?? 0,
            's2' => $textStats['statu'][$textId][2] ?? 0,
            's3' => $textStats['statu'][$textId][3] ?? 0,
            's4' => $textStats['statu'][$textId][4] ?? 0,
            's5' => $textStats['statu'][$textId][5] ?? 0,
            's98' => $textStats['statu'][$textId][98] ?? 0,
            's99' => $textStats['statu'][$textId][99] ?? 0,
        ];
        $stats['total'] = $stats['unknown'] + $stats['s1'] + $stats['s2'] + $stats['s3']
            + $stats['s4'] + $stats['s5'] + $stats['s98'] + $stats['s99'];

        return [
            'id' => $textId,
            'title' => $textInfo['title'],
            'language_id' => $textInfo['language_id'],
            'language_name' => $textInfo['language_name'],
            'annotated' => $textInfo['annotated'],
            'stats' => $stats,
        ];
    }
}
