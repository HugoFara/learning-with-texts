<?php

/**
 * Review Controller
 *
 * HTTP controller for word review interface.
 *
 * PHP version 8.2
 *
 * @category Lwt
 * @package  Lwt\Modules\Review\Http
 * @author   HugoFara <git@hugofara.net>
 * @license  Unlicense <http://unlicense.org/>
 * @link     https://hugofara.github.io/lwt/developer/api
 * @since    3.0.0
 */

declare(strict_types=1);

namespace Lwt\Modules\Review\Http;

use Lwt\Shared\Http\BaseController;
use Lwt\Modules\Review\Application\ReviewFacade;
use Lwt\Modules\Review\Infrastructure\SessionStateManager;
use Lwt\Modules\Language\Application\LanguageFacade;
use Lwt\Shared\Infrastructure\Language\LanguagePresets;
use Lwt\Shared\UI\Helpers\PageLayoutHelper;

/**
 * Controller for word review interface.
 *
 * Handles:
 * - Review index (main review interface)
 * - Review header display
 * - Review status updates
 * - Table reviews
 *
 * @category Lwt
 * @package  Lwt\Modules\Review\Http
 * @author   HugoFara <git@hugofara.net>
 * @license  Unlicense <http://unlicense.org/>
 * @link     https://hugofara.github.io/lwt/developer/api
 * @since    3.0.0
 */
class ReviewController extends BaseController
{
    private ReviewFacade $reviewFacade;
    private LanguageFacade $languageService;
    private SessionStateManager $sessionManager;

    /**
     * Create a new ReviewController.
     *
     * @param ReviewFacade|null        $reviewFacade    Review facade (optional for BC)
     * @param LanguageFacade|null      $languageService Language facade (optional for BC)
     * @param SessionStateManager|null $sessionManager  Session state manager (optional for BC)
     */
    public function __construct(
        ?ReviewFacade $reviewFacade = null,
        ?LanguageFacade $languageService = null,
        ?SessionStateManager $sessionManager = null
    ) {
        parent::__construct();
        $this->reviewFacade = $reviewFacade ?? new ReviewFacade();
        $this->languageService = $languageService ?? new LanguageFacade();
        $this->sessionManager = $sessionManager ?? new SessionStateManager();
    }

    /**
     * Review index page (main entry point).
     *
     * Routes to appropriate review type based on parameters.
     *
     * @param array $params Route parameters
     *
     * @return \Lwt\Shared\Infrastructure\Http\RedirectResponse|null Redirect when no review
     *                                                              context, null when rendered
     */
    public function index(array $params): ?\Lwt\Shared\Infrastructure\Http\RedirectResponse
    {
        $property = $this->getReviewProperty();

        if ($property === '') {
            return $this->redirect('/text/edit');
        }

        $this->renderReviewPage();
        return null;
    }

    /**
     * Get review property from request parameters.
     *
     * @return string URL property string
     */
    private function getReviewProperty(): string
    {
        $selection = $this->param('selection');
        if ($selection !== '' && $this->sessionManager->hasCriteria()) {
            return "selection=" . $selection;
        }
        $lang = $this->param('lang');
        if ($lang !== '') {
            return "lang=" . $lang;
        }
        $text = $this->param('text');
        if ($text !== '') {
            return "text=" . $text;
        }
        return '';
    }

    /**
     * Render the main review page.
     *
     * Modern interface with reactive state management and no iframes.
     *
     * @return void
     *
     * @psalm-suppress UnusedVariable Variables are used in included view files
     */
    private function renderReviewPage(): void
    {
        $langId = $this->param('lang') !== '' ? (int) $this->param('lang') : null;
        $textId = $this->param('text') !== '' ? (int) $this->param('text') : null;
        $selection = $this->param('selection') !== '' ? (int) $this->param('selection') : null;

        // Get selection data from session criteria
        $sessReviewSql = null;
        if ($selection !== null && $this->sessionManager->hasCriteria()) {
            $sessReviewSql = $this->sessionManager->getSelectionString();
        }

        $testTypeParam = $this->param('type', '1');
        $isTableMode = $testTypeParam === 'table';

        // Get review data
        $testData = $this->reviewFacade->getReviewDataFromParams(
            $selection,
            $sessReviewSql,
            $langId,
            $textId
        );

        if ($testData === null) {
            $this->redirect('/text/edit');
            return;
        }

        // Get review identifier
        $identifier = $this->reviewFacade->getReviewIdentifier(
            $selection,
            $sessReviewSql,
            $langId,
            $textId
        );

        if ($identifier[0] === '') {
            $this->redirect('/text/edit');
            return;
        }

        /** @psalm-suppress InvalidScalarArgument */
        $reviewResult = $this->reviewFacade->getReviewSql($identifier[0], $identifier[1]);
        if ($reviewResult === null) {
            $this->redirect('/text/edit');
            return;
        }

        $reviewsql = $reviewResult['sql'];
        $reviewParams = $reviewResult['params'];

        $testType = $isTableMode ? 1 : $this->reviewFacade->clampReviewType((int) $testTypeParam);
        $wordMode = $this->reviewFacade->isWordMode($testType);
        $baseType = $this->reviewFacade->getBaseReviewType($testType);

        // Get language settings
        $langIdFromSql = $this->reviewFacade->getLanguageIdFromReviewSql($reviewsql, $reviewParams);
        if ($langIdFromSql === null) {
            PageLayoutHelper::renderPageStartNobody(__('review.page_title'), 'full-width');
            include __DIR__ . '/../Views/no_terms.php';
            PageLayoutHelper::renderPageEnd();
            return;
        }

        $langSettings = $this->reviewFacade->getLanguageSettings($langIdFromSql);

        // Get language code for TTS
        $langCode = $this->languageService->getLanguageCode(
            $langIdFromSql,
            LanguagePresets::getAll()
        );

        // Initialize session
        $dueCount = (int) ($testData['counts']['due'] ?? 0);
        $this->reviewFacade->initializeReviewSession($dueCount);
        $sessionData = $this->reviewFacade->getReviewSessionData();

        // Extract language settings with proper types
        /** @var mixed $regexWordRaw */
        $regexWordRaw = $langSettings['regexWord'] ?? '';
        $wordRegex = is_string($regexWordRaw) ? $regexWordRaw : '';

        // Build config for JavaScript
        $config = [
            'reviewKey' => $identifier[0],
            'selection' => is_array($identifier[1])
                ? implode(',', $identifier[1])
                : (string) $identifier[1],
            'reviewType' => $testType,
            'isTableMode' => $isTableMode,
            'wordMode' => $wordMode,
            'langId' => $langIdFromSql,
            'wordRegex' => $wordRegex,
            'langSettings' => [
                'name' => $langSettings['name'] ?? '',
                'dict1Uri' => $langSettings['dict1Uri'] ?? '',
                'dict2Uri' => $langSettings['dict2Uri'] ?? '',
                'translateUri' => $langSettings['translateUri'] ?? '',
                'textSize' => $langSettings['textSize'] ?? 100,
                'rtl' => $langSettings['rtl'] ?? false,
                'langCode' => $langCode
            ],
            'progress' => [
                'total' => $dueCount,
                'remaining' => $dueCount,
                'wrong' => 0,
                'correct' => 0
            ],
            'timer' => [
                'startTime' => $sessionData['start'],
                'serverTime' => time()
            ],
            'title' => $testData['title'],
            'property' => $testData['property']
        ];

        PageLayoutHelper::renderPageStartNobody(__('review.page_title'), 'full-width');
        include __DIR__ . '/../Views/review_desktop.php';
        PageLayoutHelper::renderPageEnd();
    }
}
