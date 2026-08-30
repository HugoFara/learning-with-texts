<?php

/**
 * Microsoft OAuth Controller
 *
 * Controller for Microsoft OAuth integration endpoints.
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

use Lwt\Shared\Http\BaseController;
use Lwt\Shared\Infrastructure\Exception\AuthException;
use Lwt\Modules\User\Application\Services\MicrosoftAuthService;
use Lwt\Shared\UI\Helpers\FormHelper;

/**
 * Controller for Microsoft OAuth integration.
 *
 * Handles:
 * - OAuth start (redirect to Microsoft)
 * - OAuth callback (handle Microsoft response)
 * - Account linking confirmation
 *
 * @since 3.0.0
 */
class MicrosoftController extends BaseController
{
    /**
     * @var MicrosoftAuthService Microsoft auth service instance
     */
    protected MicrosoftAuthService $microsoftAuthService;

    /**
     * Create a new MicrosoftController.
     *
     * @param MicrosoftAuthService $microsoftAuthService Microsoft auth service
     */
    public function __construct(MicrosoftAuthService $microsoftAuthService)
    {
        parent::__construct();
        $this->microsoftAuthService = $microsoftAuthService;
    }

    /**
     * Start Microsoft OAuth flow.
     *
     * GET /microsoft/start
     *
     * @param array<string, mixed> $params Route parameters
     *
     * @return void
     */
    public function start(array $params): void
    {
        if (!$this->microsoftAuthService->isConfigured()) {
            throw new AuthException('Microsoft OAuth is not configured.');
        }

        $linkMode = $this->param('link') === '1';
        $authUrl = $this->microsoftAuthService->getAuthorizationUrl($linkMode);

        $this->redirect($authUrl);
    }

    /**
     * Handle Microsoft OAuth callback.
     *
     * GET /microsoft/callback
     *
     * @param array<string, mixed> $params Route parameters
     *
     * @return void
     */
    public function callback(array $params): void
    {
        if (!$this->microsoftAuthService->isConfigured()) {
            throw new AuthException('Microsoft OAuth is not configured.');
        }

        $code = $this->param('code');
        $state = $this->param('state');
        $error = $this->param('error');

        // Handle user cancellation or errors from Microsoft
        if (!empty($error)) {
            $_SESSION['auth_error'] = __('user.flash.microsoft_cancelled');
            $this->redirect('/login');
        }

        if (empty($code)) {
            $_SESSION['auth_error'] = __('user.flash.microsoft_invalid_response');
            $this->redirect('/login');
        }

        $result = $this->microsoftAuthService->handleCallback($code, $state);

        if (!$result['success'] && $result['error'] !== null) {
            $_SESSION['auth_error'] = $result['error'];
        }

        if ($result['success'] && $result['user'] !== null) {
            // Session already regenerated in service
            $_SESSION['auth_success'] = __('user.flash.microsoft_welcome');
        }

        $this->redirect($result['redirect']);
    }

    /**
     * Show account linking confirmation page.
     *
     * GET /microsoft/link-confirm
     *
     * @param array<string, mixed> $params Route parameters
     *
     * @return void
     */
    public function linkConfirm(array $params): void
    {
        $pendingLink = $this->microsoftAuthService->getPendingLinkData();

        if ($pendingLink === null) {
            $this->redirect('/login');
            return;
        }

        $email = $pendingLink['email'];

        /** @var mixed $sessionError */
        $sessionError = $_SESSION['auth_error'] ?? null;
        $error = is_string($sessionError) ? $sessionError : null;
        unset($_SESSION['auth_error']);

        $this->render(__('user.microsoft_link.page_title'), false);
        require __DIR__ . '/../Views/microsoft_link_confirm.php';
        $this->endRender();
    }

    /**
     * Process account linking confirmation.
     *
     * POST /microsoft/link-confirm
     *
     * @param array<string, mixed> $params Route parameters
     *
     * @return void
     */
    public function processLinkConfirm(array $params): void
    {
        $pendingLink = $this->microsoftAuthService->getPendingLinkData();

        if ($pendingLink === null) {
            $this->redirect('/login');
            return;
        }

        $password = $this->post('password');
        $action = $this->post('action');

        if ($action === 'cancel') {
            $this->microsoftAuthService->clearPendingLinkData();
            $this->redirect('/login');
        }

        // Verify password and link accounts
        try {
            $user = $this->microsoftAuthService->getUserFacade()
                ->login($pendingLink['email'], $password);

            $this->microsoftAuthService->linkMicrosoftToUser($pendingLink['microsoft_id'], $user);
            $this->microsoftAuthService->clearPendingLinkData();

            // Set up session
            if (session_status() === PHP_SESSION_ACTIVE) {
                session_regenerate_id(true);
            }
            $_SESSION['LWT_USER_ID'] = $user->id()->toInt();

            $_SESSION['auth_success'] = __('user.flash.microsoft_linked');
            $this->redirect('/');
        } catch (AuthException $e) {
            $_SESSION['auth_error'] = __('user.flash.microsoft_invalid_password');
            $this->redirect('/microsoft/link-confirm');
        }
    }
}
