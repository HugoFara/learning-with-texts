<?php

/**
 * Term Status Controller
 *
 * PHP version 8.2
 *
 * @category Lwt
 * @package  Lwt\Modules\Vocabulary\Http
 * @author   HugoFara <git@hugofara.net>
 * @license  Unlicense <http://unlicense.org/>
 * @link     https://hugofara.github.io/lwt/developer/api
 * @since    3.0.0
 */

declare(strict_types=1);

namespace Lwt\Modules\Vocabulary\Http;

use Lwt\Shared\Infrastructure\Http\InputValidator;
use Lwt\Shared\Infrastructure\Database\Settings;
use Lwt\Modules\Vocabulary\Application\VocabularyFacade;
use Lwt\Shared\UI\Helpers\PageLayoutHelper;

/**
 * Controller for term status operations.
 *
 * Handles:
 * - PUT /vocabulary/term/{wid}/status - Update status
 * - /word/set-review-status - Set review status (iframe view)
 * - /word/set-all-status - Mark all words with status
 *
 * @since 3.0.0
 */
class TermStatusController extends VocabularyBaseController
{
    /**
     * Vocabulary facade.
     */
    private VocabularyFacade $facade;

    /**
     * Constructor.
     *
     * @param VocabularyFacade|null $facade Vocabulary facade
     */
    public function __construct(
        ?VocabularyFacade $facade = null
    ) {
        parent::__construct();
        $this->facade = $facade ?? new VocabularyFacade();
    }

    /**
     * Update term status.
     *
     * Routes:
     * - PUT /vocabulary/term/{wid:int}/status (new RESTful route)
     * - PUT /vocabulary/term/status?wid=[id] (legacy route)
     *
     * Body: {"status": 1-5|98|99}
     *
     * @param int|null $wid Term ID (injected from route parameter)
     *
     * @return void
     */
    public function updateStatus(?int $wid = null): void
    {
        // Support both new route param injection and legacy query param
        $termId = $wid ?? InputValidator::getInt('wid', 0) ?? 0;
        $status = InputValidator::getInt('status', 0) ?? 0;

        if ($termId === 0 || $status === 0) {
            http_response_code(400);
            echo json_encode(['error' => 'Term ID and status required']);
            return;
        }

        $result = $this->facade->updateStatus($termId, $status);

        header('Content-Type: application/json');
        echo json_encode(['success' => $result]);
    }

    /**
     * Set review status (iframe view).
     *
     * Replaces set_test_status.php - sets status during review and renders result.
     *
     * @param array<string, string> $params Route parameters
     *
     * @return void
     */
    public function setReviewStatusView(array $params): void
    {
        $wid = InputValidator::getInt('wid', 0) ?? 0;
        $status = InputValidator::getInt('status');
        $stchange = InputValidator::getInt('stchange');
        $ajax = InputValidator::getString('ajax');

        if ($wid === 0) {
            PageLayoutHelper::renderPageStartNobody('Error');
            echo '<p>Invalid word ID</p>';
            PageLayoutHelper::renderPageEnd();
            return;
        }

        $apiHandler = new TermStatusApiHandler();

        // Handle status change (increment/decrement)
        if ($stchange !== null) {
            $up = $stchange > 0;
            $result = $apiHandler->formatIncrementStatusHtml($wid, $up);

            if ($ajax === '1') {
                header('Content-Type: text/html; charset=utf-8');
                // Safe: $result['increment'] is pre-escaped HTML from TermStatusApiHandler
                echo $result['increment'] ?? '';
                return;
            }

            PageLayoutHelper::renderPageStartNobody('Status Changed');
            // Safe: $result['increment'] is pre-escaped HTML from TermStatusApiHandler
            echo $result['increment'] ?? '<p>Status updated</p>';
            PageLayoutHelper::renderPageEnd();
            return;
        }

        // Handle direct status set
        if ($status !== null) {
            $apiHandler->formatSetStatus($wid, $status);

            if ($ajax === '1') {
                header('Content-Type: text/html; charset=utf-8');
                echo 'OK';
                return;
            }

            PageLayoutHelper::renderPageStartNobody('Status Set');
            echo '<p>Status set to ' . $status . '</p>';
            PageLayoutHelper::renderPageEnd();
            return;
        }

        PageLayoutHelper::renderPageStartNobody('Error');
        echo '<p>No status operation specified</p>';
        PageLayoutHelper::renderPageEnd();
    }
}
