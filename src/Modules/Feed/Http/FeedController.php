<?php

/**
 * Feed Controller (Facade)
 *
 * Thin facade delegating to FeedIndexController, FeedEditController,
 * and FeedLoadController. Maintained for backward compatibility with
 * existing route registrations.
 *
 * PHP version 8.1
 *
 * @category Lwt
 * @package  Lwt\Modules\Feed\Http
 * @author   HugoFara <git@hugofara.net>
 * @license  Unlicense <http://unlicense.org/>
 * @link     https://hugofara.github.io/lwt/developer/api
 * @since    3.0.0
 */

declare(strict_types=1);

namespace Lwt\Modules\Feed\Http;

use Lwt\Modules\Feed\Application\FeedFacade;
use Lwt\Modules\Language\Application\LanguageFacade;
use Lwt\Shared\Infrastructure\Http\FlashMessageService;

/**
 * Facade controller delegating to specialized sub-controllers.
 *
 * @since 3.0.0
 */
class FeedController
{
    private FeedFacade $feedFacade;
    private FeedEditController $editController;
    private FeedLoadController $loadController;

    public function __construct(
        FeedFacade $feedFacade,
        LanguageFacade $languageFacade,
        ?FlashMessageService $flashService = null
    ) {
        $this->feedFacade = $feedFacade;
        $this->editController = new FeedEditController(
            $feedFacade,
            $languageFacade,
            $flashService
        );
        $this->loadController = new FeedLoadController(
            $feedFacade,
            $languageFacade
        );
    }

    /**
     * Get the FeedFacade instance.
     *
     * @return FeedFacade
     */
    public function getFacade(): FeedFacade
    {
        return $this->feedFacade;
    }

    // =========================================================================
    // Delegated Route Handlers
    // =========================================================================

    /**
     * Legacy server-rendered feed pages — now all the SPA.
     *
     * `/feeds` (the article browser), `/feeds/edit` and `/feeds/multi-load`
     * were a second implementation of what `/feeds/manage` already does
     * entirely from `/api/v1`, including the edit-before-import flow that
     * kept the browser alive. They redirect rather than 404 so existing
     * bookmarks keep working.
     *
     * @param array<string, string> $params Route parameters
     */
    public function index(array $params): void
    {
        $this->redirectToManager();
    }

    /** @param array<string, string> $params Route parameters */
    public function edit(array $params): void
    {
        $this->redirectToManager();
    }

    /**
     * Refresh feeds that are due for auto-update.
     *
     * @param array<string, string> $params Route parameters
     */
    public function autoupdate(array $params): void
    {
        $this->loadController->autoupdateRoute();
    }

    /**
     * Send the caller to the feeds manager SPA.
     */
    private function redirectToManager(): void
    {
        header('Location: ' . url('/feeds/manage'), true, 302);
        exit;
    }

    /** @param array<string, string> $params */
    public function spa(array $params): void
    {
        $this->editController->spa($params);
    }

    /** @param array<string, string> $params */
    public function newFeed(array $params): void
    {
        $this->editController->newFeed($params);
    }

    /**
     * The feed wizard.
     *
     * `/feeds/wizard` used to be a four-step state machine driven by POSTs
     * and `$_SESSION`, with `?step=` saying which page to render. The wizard
     * is one page now — the same page `/feeds/new` serves — so the route only
     * still exists because links to it do, including the one that reopens a
     * saved feed as `?edit_feed={id}` (#262, #266).
     *
     * @param array<string, string> $params Route parameters
     */
    public function wizard(array $params): void
    {
        $this->editController->newFeed($params);
    }

    public function editFeed(int $id): void
    {
        $this->editController->editFeed($id);
    }

    public function deleteFeed(int $id): void
    {
        $this->editController->deleteFeed($id);
    }

    public function loadFeedRoute(int $id): void
    {
        $this->loadController->loadFeedRoute($id);
    }

    /** @param array<string, string> $params */
    public function multiLoad(array $params): void
    {
        $this->redirectToManager();
    }

    /**
     * Render feed load interface (used by renderFeedLoadInterfaceModern delegation).
     */
    public function renderFeedLoadInterface(
        int $currentFeed,
        bool $checkAutoupdate,
        string $redirectUrl
    ): void {
        $this->loadController->renderFeedLoadInterface($currentFeed, $checkAutoupdate, $redirectUrl);
    }
}
