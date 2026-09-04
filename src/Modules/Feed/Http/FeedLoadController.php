<?php

declare(strict_types=1);

namespace Lwt\Modules\Feed\Http;

use Lwt\Modules\Feed\Application\FeedFacade;
use Lwt\Modules\Language\Application\LanguageFacade;
use Lwt\Shared\Infrastructure\Database\Validation;
use Lwt\Shared\Infrastructure\Http\InputValidator;
use Lwt\Shared\UI\Helpers\PageLayoutHelper;
use Lwt\Shared\UI\Helpers\ConfigIsland;

/**
 * Controller for feed loading/importing operations.
 *
 * Handles feed loading, multi-load interface, and the
 * renderFeedLoadInterface method for Alpine.js feed loader.
 *
 * @since 3.0.0
 */
class FeedLoadController
{
    private string $viewPath;
    private FeedFacade $feedFacade;
    private LanguageFacade $languageFacade;

    public function __construct(
        FeedFacade $feedFacade,
        LanguageFacade $languageFacade
    ) {
        $this->viewPath = __DIR__ . '/../Views/';
        $this->feedFacade = $feedFacade;
        $this->languageFacade = $languageFacade;
    }

    /**
     * Render feed load interface.
     *
     * @param int    $currentFeed     Feed ID
     * @param bool   $checkAutoupdate Check auto-update
     * @param string $redirectUrl     Redirect URL
     *
     * @return void
     */
    public function renderFeedLoadInterface(
        int $currentFeed,
        bool $checkAutoupdate,
        string $redirectUrl
    ): void {
        /** @var array{feeds: array, count: int} $config */
        $config = $this->feedFacade->getFeedLoadConfig($currentFeed, $checkAutoupdate);

        // Boot parameters for the feedLoader Alpine component; the markup is
        // the view's.
        ConfigIsland::render('feed-loader-config', [
            'feeds' => $config['feeds'],
            'redirectUrl' => $redirectUrl,
        ]);

        $feedCount = $config['count'];
        include __DIR__ . '/../Views/feed_loader.php';
    }

    /**
     * Refresh every feed whose auto-update interval has elapsed.
     *
     * Route: GET /feeds/autoupdate
     *
     * This used to hang off `/feeds?check_autoupdate=1`, which meant the
     * retired browse page had to stay routable just to carry the flag. The
     * language page links straight here instead.
     *
     * @return void
     */
    public function autoupdateRoute(): void
    {
        $currentLang = Validation::language(
            InputValidator::getStringWithDb("filterlang", 'currentlanguage')
        );

        $langName = $this->languageFacade->getLanguageName($currentLang);
        PageLayoutHelper::renderPageStart('Updating Feeds - ' . $langName, true);

        $this->renderFeedLoadInterface(
            InputValidator::getIntParam('selected_feed', 0, 0),
            true,
            '/feeds/manage'
        );

        PageLayoutHelper::renderPageEnd();
    }

    /**
     * Load/refresh a single feed.
     *
     * Route: GET /feeds/{id}/load
     *
     * @param int $id Feed ID from route parameter
     *
     * @return void
     */
    public function loadFeedRoute(int $id): void
    {
        $currentLang = Validation::language(
            InputValidator::getStringWithDb("filterlang", 'currentlanguage')
        );

        $langName = $this->languageFacade->getLanguageName($currentLang);
        PageLayoutHelper::renderPageStart('Loading Feed - ' . $langName, true);

        $this->renderFeedLoadInterface(
            $id,
            false,
            '/feeds/manage'
        );

        PageLayoutHelper::renderPageEnd();
    }
}
