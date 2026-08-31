<?php

declare(strict_types=1);

namespace Lwt\Modules\Feed\Http;

use Lwt\Modules\Feed\Application\FeedFacade;
use Lwt\Modules\Language\Application\LanguageFacade;
use Lwt\Shared\Infrastructure\Http\FlashMessageService;
use Lwt\Shared\Infrastructure\Http\InputValidator;
use Lwt\Shared\Infrastructure\Language\CurrentLanguage;
use Lwt\Shared\UI\Helpers\PageLayoutHelper;

/**
 * Controller for feed CRUD operations.
 *
 * Handles feed creation, editing, deletion, and the management list.
 *
 * @since 3.0.0
 */
class FeedEditController
{
    use FeedFlashTrait;

    private string $viewPath;
    private FeedFacade $feedFacade;
    private LanguageFacade $languageFacade;
    private FlashMessageService $flashService;

    public function __construct(
        FeedFacade $feedFacade,
        LanguageFacade $languageFacade,
        ?FlashMessageService $flashService = null
    ) {
        $this->viewPath = __DIR__ . '/../Views/';
        $this->feedFacade = $feedFacade;
        $this->languageFacade = $languageFacade;
        $this->flashService = $flashService ?? new FlashMessageService();
    }

        /**
     * Feeds SPA page - modern Alpine.js single page application.
     *
     * @param array<string, string> $params Route parameters
     *
     * @return void
     *
     * @psalm-suppress UnresolvableInclude View path is constructed at runtime
     */
    public function spa(array $params): void
    {
        // The manager lists the feeds of the language the navbar is on, so it
        // needs to know which that is before it asks the API for them.
        $currentLanguageId = CurrentLanguage::resolveId();
        $currentLanguageName = $this->languageFacade->getLanguageName($currentLanguageId);

        PageLayoutHelper::renderPageStart('Feed Manager', true);
        /** @psalm-suppress UnresolvableInclude */
        include $this->viewPath . 'spa.php';
        PageLayoutHelper::renderPageEnd();
    }

    /**
     * The feed wizard, whichever way the user came in.
     *
     * Route: GET /feeds/new, and GET /feeds/wizard for the links that still
     * name it — including `?edit_feed={id}`, which reopens a saved feed.
     *
     * Renders the scaffold only. Every path through the wizard reads and
     * saves through /api/v1, which is also the only path that checks the
     * submitted NfLgID belongs to the caller — the save branch that used to
     * live here passed it straight to the facade (#262).
     *
     * @param array<string, string> $params Route parameters
     *
     * @return void
     */
    public function newFeed(array $params): void
    {
        PageLayoutHelper::renderPageStart('Add a Feed', true);

        $this->showNewForm();
        PageLayoutHelper::renderPageEnd();
    }

    /**
     * Edit feed form.
     *
     * Route: GET /feeds/{id}/edit
     *
     * Renders the scaffold only; the form saves through
     * PUT /api/v1/feeds/{id} (#262).
     *
     * @param int $id Feed ID from route parameter
     *
     * @return void
     */
    public function editFeed(int $id): void
    {
        $feed = $this->feedFacade->getFeedById($id);

        if ($feed === null) {
            $this->flashService->error(__('feed.flash.not_found'));
            $this->redirect(url('/feeds/manage'));
            return;
        }

        $langName = $this->languageFacade->getLanguageName($feed['NfLgID']);
        PageLayoutHelper::renderPageStart('Edit Feed - ' . $langName, true);

        $this->showEditForm($id);
        PageLayoutHelper::renderPageEnd();
    }

    /**
     * Delete a feed.
     *
     * Route: DELETE /feeds/{id}
     *
     * @param int $id Feed ID from route parameter
     *
     * @return void
     */
    public function deleteFeed(int $id): void
    {
        $result = $this->feedFacade->deleteFeeds((string)$id);

        if ($result['feeds'] > 0) {
            $this->flashService->success(__('feed.flash.deleted'));
        } else {
            $this->flashService->error(__('feed.flash.delete_failed'));
        }

        $this->redirect(url('/feeds/manage'));
    }

    /**
     * Send a redirect response.
     *
     * Extracted to allow tests to override and prevent exit().
     *
     * @param string $url Target URL
     *
     * @return void
     *
     * @codeCoverageIgnore
     */
    protected function redirect(string $url): void
    {
        header('Location: ' . $url);
        exit;
    }

    /**
     * Show the wizard page.
     *
     * @return void
     *
     * @psalm-suppress UnresolvableInclude View path is constructed at runtime
     */
    private function showNewForm(): void
    {
        // Reopening a saved feed is a GET with the feed's id; the page loads
        // the feed itself from /api/v1/feeds/{id}, so only the id is passed.
        $editFeedId = InputValidator::getInt('edit_feed');
        $languages = $this->languageFacade->getLanguagesForSelect();
        $curatedFeeds = $this->loadCuratedFeeds();
        // Resolves the unset-'currentlanguage' case centrally; without a
        // language the curated-feed wizard posts NfLgID=0 and the server
        // rejects with 500.
        $currentLanguageId = CurrentLanguage::resolveId();
        $currentLanguageName = $this->languageFacade->getLanguageName($currentLanguageId);

        include $this->viewPath . 'wizard.php';
    }

    /**
     * Load curated feeds from the JSON registry.
     *
     * @return list<array<string, mixed>>
     */
    private function loadCuratedFeeds(): array
    {
        $path = dirname(__DIR__, 4) . '/data/curated_feeds.json';
        if (!file_exists($path)) {
            return [];
        }
        $json = file_get_contents($path);
        if ($json === false) {
            return [];
        }
        $data = json_decode($json, true);
        if (!is_array($data) || !isset($data['feeds'])) {
            return [];
        }
        /** @var list<array<string, mixed>> */
        $feeds = $data['feeds'];
        return $feeds;
    }

    /**
     * Show the edit feed form.
     *
     * @param int $feedId Feed ID to edit
     *
     * @return void
     */
    private function showEditForm(int $feedId): void
    {
        $feed = $this->feedFacade->getFeedById($feedId);

        if ($feed === null) {
            $this->renderNotification(__('feed.edit_feed_not_found'));
            return;
        }

        // The view showed the feed's language by scanning the whole list for a
        // matching id. Looking a value up is not the view's job, and it only
        // needed the list to do it, so the answer is resolved here instead.
        $feedLanguageName = '';
        foreach ($this->feedFacade->getLanguages() as $language) {
            if ($feed['NfLgID'] === $language['LgID']) {
                $feedLanguageName = (string) $language['LgName'];
                break;
            }
        }

        // Parse options
        $options = $this->feedFacade->getFeedOption($feed['NfOptions'], '');
        if (!is_array($options)) {
            $options = [];
        }

        // Parse auto-update interval
        $autoUpdateRaw = $this->feedFacade->getFeedOption($feed['NfOptions'], 'autoupdate');
        if ($autoUpdateRaw === null || !is_string($autoUpdateRaw)) {
            $autoUpdateInterval = null;
            $autoUpdateUnit = null;
        } else {
            $autoUpdateUnit = substr($autoUpdateRaw, -1);
            $autoUpdateInterval = substr($autoUpdateRaw, 0, -1);
        }

        /** @psalm-suppress UnresolvableInclude View path is constructed at runtime */
        include $this->viewPath . 'edit.php';
    }
}
