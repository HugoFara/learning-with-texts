<?php

/**
 * Statistics Controller
 *
 * PHP version 8.2
 *
 * @category Lwt
 * @package  Lwt\Modules\User\Http
 * @author   HugoFara <git@hugofara.net>
 * @license  Unlicense <http://unlicense.org/>
 * @link     https://hugofara.github.io/lwt/developer/api
 * @since    3.0.0
 */

declare(strict_types=1);

namespace Lwt\Modules\User\Http;

use Lwt\Modules\User\Application\UseCases\Statistics\GetFrequencyStatistics;
use Lwt\Modules\User\Application\UseCases\Statistics\GetIntensityStatistics;
use Lwt\Shared\Http\BaseController;
use Lwt\Shared\Infrastructure\Http\RedirectResponse;

/**
 * Controller for per-user learning statistics.
 *
 * Displays reading intensity and frequency statistics scoped to the
 * current user at /profile/statistics.
 *
 * @since 3.0.0
 */
class StatisticsController extends BaseController
{
    /**
     * Intensity statistics use case.
     */
    private GetIntensityStatistics $getIntensityStatistics;

    /**
     * Frequency statistics use case.
     */
    private GetFrequencyStatistics $getFrequencyStatistics;

    /**
     * Path to view templates.
     */
    private string $viewPath;

    /**
     * Constructor.
     *
     * @param GetIntensityStatistics|null $getIntensityStatistics Intensity use case
     * @param GetFrequencyStatistics|null $getFrequencyStatistics Frequency use case
     */
    public function __construct(
        ?GetIntensityStatistics $getIntensityStatistics = null,
        ?GetFrequencyStatistics $getFrequencyStatistics = null
    ) {
        parent::__construct();
        $this->getIntensityStatistics = $getIntensityStatistics ?? new GetIntensityStatistics();
        $this->getFrequencyStatistics = $getFrequencyStatistics ?? new GetFrequencyStatistics();
        $this->viewPath = __DIR__ . '/../Views/';
    }

    /**
     * Display the statistics page.
     *
     * GET /profile/statistics
     *
     * @param array<string, string> $params Route parameters
     *
     * @return void
     *
     * @psalm-suppress UnusedVariable Variables are used in included view files
     * @psalm-suppress UnresolvableInclude View path is constructed at runtime
     */
    public function show(array $params = []): void
    {
        $intensityStats = $this->getIntensityStatistics->execute();
        $frequencyStats = $this->getFrequencyStatistics->execute();

        $this->render('Statistics', true);
        include $this->viewPath . 'statistics.php';
        $this->endRender();
    }

    /**
     * Redirect the legacy /admin/statistics URL to /profile/statistics.
     *
     * GET /admin/statistics
     *
     * @param array<string, string> $params Route parameters
     *
     * @return RedirectResponse
     */
    public function redirectFromAdmin(array $params = []): RedirectResponse
    {
        return $this->redirect('/profile/statistics', 301);
    }
}
