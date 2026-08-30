<?php

/**
 * Text Print Controller
 *
 * HTTP controller for text printing functionality.
 *
 * PHP version 8.2
 *
 * @category Lwt
 * @package  Lwt\Modules\Text\Http
 * @author   HugoFara <git@hugofara.net>
 * @license  Unlicense <http://unlicense.org/>
 * @link     https://hugofara.github.io/lwt/developer/api
 * @since    3.0.0
 */

declare(strict_types=1);

namespace Lwt\Modules\Text\Http;

use Lwt\Shared\Http\BaseController;
use Lwt\Modules\Text\Application\Services\TextPrintService;
use Lwt\Modules\Text\Application\Services\AnnotationService;
use Lwt\Modules\Text\Http\TextApiHandler;
use Lwt\Shared\UI\Helpers\PageLayoutHelper;

/**
 * Controller for text printing functionality.
 *
 * Handles:
 * - Plain text printing with annotations
 * - Improved/annotated text printing
 * - Annotation editing
 *
 * @category Lwt
 * @package  Lwt\Modules\Text\Http
 * @author   HugoFara <git@hugofara.net>
 * @license  Unlicense <http://unlicense.org/>
 * @link     https://hugofara.github.io/lwt/developer/api
 * @since    3.0.0
 */
class TextPrintController extends BaseController
{
    /**
     * Module views path.
     */
    private const MODULE_VIEWS = __DIR__ . '/../Views';
    /**
     * Text print service for business logic.
     *
     * @var TextPrintService
     */
    private TextPrintService $printService;

    /**
     * Create a new TextPrintController.
     *
     * @param TextPrintService|null $printService Print service for text printing operations
     */
    public function __construct(?TextPrintService $printService = null)
    {
        parent::__construct();
        $this->printService = $printService ?? new TextPrintService();
    }

    /**
     * Get the print service instance (for testing).
     *
     * @return TextPrintService
     */
    public function getPrintService(): TextPrintService
    {
        return $this->printService;
    }

    /**
     * Print plain text with annotations (replaces text_print_plain.php).
     *
     * Routes:
     * - GET /text/{text:int}/print-plain (new RESTful route)
     * - GET /text/print-plain?text=[textid] (legacy route)
     *
     * Optional query params: ann=[annotationcode]&status=[statuscode]
     *
     * @param int|null $text Text ID (injected from route parameter)
     *
     * @return void
     *
     * @psalm-suppress UnusedVariable Variables are used in included view files
     */
    public function printPlain(?int $text = null): void
    {
        // Support both new route param injection and legacy query param
        $textId = $text ?? (int) $this->param('text', '0');

        if ($textId === 0) {
            $this->redirect('/text/edit');
        }

        // Get print settings from request or saved settings
        $savedAnn = $this->printService->getAnnotationSetting($this->param('ann'));
        $savedStatus = $this->printService->getStatusRangeSetting($this->param('status'));
        $savedPlacement = $this->printService->getAnnotationPlacementSetting($this->param('annplcmnt'));

        // Prepare view data
        $viewData = $this->printService->preparePlainPrintData($textId);
        if ($viewData === null) {
            $this->redirect('/text/edit');
        }

        // Save settings
        $this->printService->savePrintSettings($textId, $savedAnn, $savedStatus, $savedPlacement);

        // Set mode for Alpine view
        $mode = 'plain';

        // Pre-compute service output for view
        $navLinksHtml = (new \Lwt\Modules\Text\Application\Services\TextNavigationService())
            ->getPreviousAndNextTextLinks($textId, $printUrl = '/text/{id}/print-plain', false, '');
        $annotationLinkHtml = (new AnnotationService())->getAnnotationLink($textId);

        // Render the view
        PageLayoutHelper::renderPageStartNobody('Print');

        include self::MODULE_VIEWS . '/print_alpine.php';

        PageLayoutHelper::renderPageEnd();
    }

    /**
     * Print improved annotated text (replaces text_print.php).
     *
     * Route: /text/{text}/print
     *
     * @param array $params Route parameters (expects 'text' key with text ID)
     *
     * @return void
     *
     * @psalm-suppress UnusedVariable Variables are used in included view files
     */
    public function printAnnotated(array $params): void
    {
        $textId = (int) ($params['text'] ?? 0);

        if ($textId === 0) {
            $this->redirect('/text/edit');
        }

        // Handle annotation recreation
        $ann = $this->printService->getAnnotatedText($textId);
        $annExists = $ann !== null;
        if ($annExists) {
            $annotationService = new AnnotationService();
            $ann = $annotationService->recreateSaveAnnotation($textId, $ann);
            $annExists = strlen($ann) > 0;
        }

        // Prepare view data
        $viewData = $this->printService->prepareAnnotatedPrintData($textId);
        if ($viewData === null) {
            $this->redirect('/text/edit');
        }

        // Save current text setting
        $this->printService->setCurrentText($textId);

        // Display mode (not edit)
        $mode = 'annotated';
        $editFormHtml = null;
        $savedAnn = 0;
        $savedStatus = 0;
        $savedPlacement = 0;

        // Pre-compute service output for view
        $navLinksHtml = (new \Lwt\Modules\Text\Application\Services\TextNavigationService())
            ->getPreviousAndNextTextLinks($textId, '/text/{id}/print', false, '');
        $annotationLinkHtml = (new AnnotationService())->getAnnotationLink($textId);

        // Render the view
        PageLayoutHelper::renderPageStartNobody('Annotated Text');

        include self::MODULE_VIEWS . '/print_alpine.php';

        PageLayoutHelper::renderPageEnd();
    }

    /**
     * Edit annotation for a text.
     *
     * Route: /text/{text}/print/edit
     *
     * @param array $params Route parameters (expects 'text' key with text ID)
     *
     * @return void
     *
     * @psalm-suppress UnusedVariable Variables are used in included view files
     */
    public function editAnnotation(array $params): void
    {
        $textId = (int) ($params['text'] ?? 0);

        if ($textId === 0) {
            $this->redirect('/text/edit');
        }

        // Handle annotation recreation or creation
        $ann = $this->printService->getAnnotatedText($textId);
        $annExists = $ann !== null;
        if ($annExists) {
            $annotationService = new AnnotationService();
            $ann = $annotationService->recreateSaveAnnotation($textId, $ann);
            $annExists = strlen($ann) > 0;
        }

        // Create annotation if it doesn't exist
        if (!$annExists) {
            $annotationService = new AnnotationService();
            $ann = $annotationService->createSaveAnnotation($textId);
            $annExists = strlen($ann) > 0;
        }

        // Prepare view data
        $viewData = $this->printService->prepareAnnotatedPrintData($textId);
        if ($viewData === null) {
            $this->redirect('/text/edit');
        }

        // Save current text setting
        $this->printService->setCurrentText($textId);

        // Edit mode
        $mode = 'edit';
        $editFormHtml = null;
        $savedAnn = 0;
        $savedStatus = 0;
        $savedPlacement = 0;

        if ($annExists) {
            $handler = new TextApiHandler();
            $editFormHtml = $handler->editTermForm($textId);
        }

        // Pre-compute service output for view
        $navLinksHtml = (new \Lwt\Modules\Text\Application\Services\TextNavigationService())
            ->getPreviousAndNextTextLinks($textId, '/text/{id}/print', false, '');
        $annotationLinkHtml = (new AnnotationService())->getAnnotationLink($textId);

        // Render the view
        PageLayoutHelper::renderPageStartNobody('Annotated Text');

        include self::MODULE_VIEWS . '/print_alpine.php';

        PageLayoutHelper::renderPageEnd();
    }

    /**
     * Delete annotation for a text.
     *
     * Route: DELETE /text/{text}/annotation
     *
     * @param array $params Route parameters (expects 'text' key with text ID)
     *
     * @return void
     */
    public function deleteAnnotation(array $params): void
    {
        $textId = (int) ($params['text'] ?? 0);

        if ($textId === 0) {
            $this->redirect('/text/edit');
        }

        $deleted = $this->printService->deleteAnnotation($textId);
        if ($deleted) {
            $this->redirect('/text/' . $textId . '/print-plain');
        }

        // If deletion failed, redirect back to print view
        $this->redirect('/text/' . $textId . '/print');
    }
}
