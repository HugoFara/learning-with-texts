<?php

/**
 * Google OAuth Controller
 *
 * Controller for Google OAuth integration endpoints.
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
use Lwt\Modules\User\Application\Services\GoogleAuthService;
use Lwt\Shared\UI\Helpers\FormHelper;

/**
 * Controller for Google OAuth integration.
 *
 * Handles:
 * - OAuth start (redirect to Google)
 * - OAuth callback (handle Google response)
 * - Account linking confirmation
 *
 * @since 3.0.0
 */
class GoogleController extends BaseController
{
    /**
     * @var GoogleAuthService Google auth service instance
     */
    protected GoogleAuthService $googleAuthService;

    /**
     * Create a new GoogleController.
     *
     * @param GoogleAuthService $googleAuthService Google auth service
     */
    public function __construct(GoogleAuthService $googleAuthService)
    {
        parent::__construct();
        $this->googleAuthService = $googleAuthService;
    }

    /**
     * Start Google OAuth flow.
     *
     * GET /google/start
     *
     * @param array<string, mixed> $params Route parameters
     *
     * @return void
     */
    public function start(array $params): void
    {
        if (!$this->googleAuthService->isConfigured()) {
            throw new AuthException('Google OAuth is not configured.');
        }

        $linkMode = $this->param('link') === '1';
        $authUrl = $this->googleAuthService->getAuthorizationUrl($linkMode);

        $this->redirect($authUrl);
    }

    /**
     * Handle Google OAuth callback.
     *
     * GET /google/callback
     *
     * @param array<string, mixed> $params Route parameters
     *
     * @return void
     */
    public function callback(array $params): void
    {
        if (!$this->googleAuthService->isConfigured()) {
            throw new AuthException('Google OAuth is not configured.');
        }

        $code = $this->param('code');
        $state = $this->param('state');
        $error = $this->param('error');

        // Handle user cancellation or errors from Google
        if (!empty($error)) {
            $_SESSION['auth_error'] = __('user.flash.google_cancelled');
            $this->redirect('/login');
        }

        if (empty($code)) {
            $_SESSION['auth_error'] = __('user.flash.google_invalid_response');
            $this->redirect('/login');
        }

        $result = $this->googleAuthService->handleCallback($code, $state);

        if (!$result['success'] && $result['error'] !== null) {
            $_SESSION['auth_error'] = $result['error'];
        }

        if ($result['success'] && $result['user'] !== null) {
            // Session already regenerated in service
            $_SESSION['auth_success'] = __('user.flash.google_welcome');
        }

        $this->redirect($result['redirect']);
    }

    /**
     * Show account linking confirmation page.
     *
     * GET /google/link-confirm
     *
     * @param array<string, mixed> $params Route parameters
     *
     * @return void
     */
    public function linkConfirm(array $params): void
    {
        $pendingLink = $this->googleAuthService->getPendingLinkData();

        if ($pendingLink === null) {
            $this->redirect('/login');
            return;
        }

        $email = $pendingLink['email'];

        /** @var mixed $sessionError */
        $sessionError = $_SESSION['auth_error'] ?? null;
        $error = is_string($sessionError) ? $sessionError : null;
        unset($_SESSION['auth_error']);

        $this->render(__('user.google_link.page_title'), false);
        require __DIR__ . '/../Views/google_link_confirm.php';
        $this->endRender();
    }

    /**
     * Process account linking confirmation.
     *
     * POST /google/link-confirm
     *
     * @param array<string, mixed> $params Route parameters
     *
     * @return void
     */
    public function processLinkConfirm(array $params): void
    {
        $pendingLink = $this->googleAuthService->getPendingLinkData();

        if ($pendingLink === null) {
            $this->redirect('/login');
            return;
        }

        $password = $this->post('password');
        $action = $this->post('action');

        if ($action === 'cancel') {
            $this->googleAuthService->clearPendingLinkData();
            $this->redirect('/login');
        }

        // Verify password and link accounts
        try {
            $user = $this->googleAuthService->getUserFacade()
                ->login($pendingLink['email'], $password);

            $this->googleAuthService->linkGoogleToUser($pendingLink['google_id'], $user);
            $this->googleAuthService->clearPendingLinkData();

            // Set up session
            if (session_status() === PHP_SESSION_ACTIVE) {
                session_regenerate_id(true);
            }
            $_SESSION['LWT_USER_ID'] = $user->id()->toInt();

            $_SESSION['auth_success'] = __('user.flash.google_linked');
            $this->redirect('/');
        } catch (AuthException $e) {
            $_SESSION['auth_error'] = __('user.flash.google_invalid_password');
            $this->redirect('/google/link-confirm');
        }
    }
}
