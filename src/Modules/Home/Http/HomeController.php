<?php

/**
 * Home Controller - Dashboard and home page
 *
 * PHP version 8.2
 *
 * @category Lwt
 * @package  Lwt\Modules\Home\Http
 * @author   HugoFara <git@hugofara.net>
 * @license  Unlicense <http://unlicense.org/>
 * @link     https://hugofara.github.io/lwt/developer/api
 * @since    3.0.0
 */

declare(strict_types=1);

namespace Lwt\Modules\Home\Http;

use Lwt\Shared\Http\BaseController;
use Lwt\Modules\Home\Application\HomeFacade;
use Lwt\Modules\Language\Application\LanguageFacade;
use Lwt\Shared\UI\Helpers\PageLayoutHelper;

/**
 * Controller for home/dashboard page.
 *
 * @since 3.0.0
 */
class HomeController extends BaseController
{
    private HomeFacade $homeFacade;
    private LanguageFacade $languageFacade;

    /**
     * Create a new HomeController.
     *
     * @param HomeFacade     $homeFacade     Home facade for dashboard data
     * @param LanguageFacade $languageFacade Language facade for language operations
     */
    public function __construct(HomeFacade $homeFacade, LanguageFacade $languageFacade)
    {
        parent::__construct();
        $this->homeFacade = $homeFacade;
        $this->languageFacade = $languageFacade;
    }

    /**
     * Home page (replaces home.php)
     *
     * @param array $params Route parameters
     *
     * @return void
     */
    public function index(array $params): void
    {
        /** @psalm-suppress UnusedVariable - Used by included view */
        $dashboardData = $this->homeFacade->getDashboardData();

        /** @psalm-suppress UnusedVariable - Used by included view */
        $homeFacade = $this->homeFacade;

        /** @psalm-suppress UnusedVariable - Used by included view */
        $languages = $this->languageFacade->getLanguagesForSelect();

        // Pre-compute text statistics for view
        $currenttext = $dashboardData['current_text_id'] ?? null;
        $currentTextInfo = $dashboardData['current_text_info'] ?? null;
        /** @psalm-suppress UnusedVariable - Used by included view */
        $lastTextInfo = null;
        if ($currentTextInfo !== null && $currenttext !== null) {
            $textStatsService = new \Lwt\Modules\Text\Application\Services\TextStatisticsService();
            $textStats = $textStatsService->getTextWordCount([$currenttext]);
            $todoCount = $textStatsService->getTodoWordsCount($currenttext);

            $stats = [
                'unknown' => $todoCount,
                's1' => $textStats['statu'][$currenttext][1] ?? 0,
                's2' => $textStats['statu'][$currenttext][2] ?? 0,
                's3' => $textStats['statu'][$currenttext][3] ?? 0,
                's4' => $textStats['statu'][$currenttext][4] ?? 0,
                's5' => $textStats['statu'][$currenttext][5] ?? 0,
                's98' => $textStats['statu'][$currenttext][98] ?? 0,
                's99' => $textStats['statu'][$currenttext][99] ?? 0,
            ];
            $stats['total'] = $stats['unknown'] + $stats['s1'] + $stats['s2'] + $stats['s3']
                + $stats['s4'] + $stats['s5'] + $stats['s98'] + $stats['s99'];

            $lastTextInfo = [
                'id' => $currenttext,
                'title' => isset($currentTextInfo['title']) ? (string) $currentTextInfo['title'] : '',
                'language_id' => isset($currentTextInfo['language_id']) ? (int) $currentTextInfo['language_id'] : 0,
                'language_name' => isset($currentTextInfo['language_name'])
                    ? (string) $currentTextInfo['language_name'] : '',
                'annotated' => isset($currentTextInfo['annotated']) ? (bool) $currentTextInfo['annotated'] : false,
                'stats' => $stats,
            ];
        }

        PageLayoutHelper::renderPageStart("Home", true, 'home');

        include __DIR__ . '/../Views/index.php';

        PageLayoutHelper::renderPageEnd();
    }

    /**
     * Get the HomeFacade instance.
     *
     * @return HomeFacade
     */
    public function getHomeFacade(): HomeFacade
    {
        return $this->homeFacade;
    }
}
