<?php

/**
 * Authentication Middleware
 *
 * PHP version 8.2
 *
 * @category Lwt
 * @package  Lwt\Shared\Infrastructure\Routing\Middleware
 * @author   HugoFara <git@hugofara.net>
 * @license  Unlicense <http://unlicense.org/>
 * @link     https://hugofara.github.io/lwt/developer/api
 * @since    3.0.0
 */

declare(strict_types=1);

namespace Lwt\Shared\Infrastructure\Routing\Middleware;

use Lwt\Shared\Infrastructure\Globals;
use Lwt\Modules\User\Application\UserFacade;
use Lwt\Shared\Infrastructure\Container\Container;

/**
 * Middleware that requires user authentication.
 *
 * Checks for:
 * 1. Session-based authentication (LWT_USER_ID in $_SESSION)
 * 2. API token authentication (Authorization: Bearer header)
 *
 * If neither is valid, redirects to login page (web) or returns 401 (API).
 *
 * @category Lwt
 * @package  Lwt\Shared\Infrastructure\Routing\Middleware
 * @author   HugoFara <git@hugofara.net>
 * @license  Unlicense <http://unlicense.org/>
 * @link     https://hugofara.github.io/lwt/developer/api
 * @since    3.0.0
 */
class AuthMiddleware implements MiddlewareInterface
{
    /**
     * User facade instance.
     *
     * @var UserFacade
     */
    private UserFacade $userFacade;

    /**
     * Create a new AuthMiddleware.
     *
     * @param UserFacade|null $userFacade Optional user facade instance
     *
     * @psalm-suppress PossiblyUnusedMethod - Public API for middleware instantiation
     */
    public function __construct(?UserFacade $userFacade = null)
    {
        /** @var UserFacade */
        $facade = $userFacade ?? Container::getInstance()->get(UserFacade::class);
        $this->userFacade = $facade;
    }

    /**
     * Handle the incoming request.
     *
     * Checks for valid authentication via session or API token.
     * On failure:
     * - For API requests: returns 401 JSON response
     * - For web requests: redirects to /login
     *
     * @return bool True if authenticated, false if halted
     */
    public function handle(): bool
    {
        // Skip authentication if multi-user mode is disabled
        if (!Globals::isMultiUserEnabled()) {
            return true;
        }

        // Already authenticated in this request?
        if (Globals::isAuthenticated()) {
            return true;
        }

        // Try session authentication
        if ($this->validateSession()) {
            return true;
        }

        // Try API token authentication
        if ($this->validateApiToken()) {
            return true;
        }

        // Authentication failed - handle based on request type
        $this->handleUnauthenticated();
        return false;
    }

    /**
     * Check if the request is an API request.
     *
     * @return bool True if this is an API request
     */
    private function isApiRequest(): bool
    {
        $path = $_SERVER['REQUEST_URI'] ?? '/';
        $parsedUrl = parse_url($path);
        $requestPath = $parsedUrl['path'] ?? '/';

        // Check if path starts with /api/
        if (str_starts_with($requestPath, '/api/')) {
            return true;
        }

        // Check for JSON Accept header
        $accept = $_SERVER['HTTP_ACCEPT'] ?? '';
        if (str_contains($accept, 'application/json')) {
            return true;
        }

        // Check for XHR request
        $xRequestedWith = $_SERVER['HTTP_X_REQUESTED_WITH'] ?? '';
        if (strtolower($xRequestedWith) === 'xmlhttprequest') {
            return true;
        }

        return false;
    }

    /**
     * Validate session-based authentication.
     *
     * @return bool True if session is valid
     */
    private function validateSession(): bool
    {
        return $this->userFacade->validateSession();
    }

    /**
     * Validate API token authentication.
     *
     * Looks for Bearer token in Authorization header.
     *
     * @return bool True if token is valid
     */
    private function validateApiToken(): bool
    {
        $token = $this->extractBearerToken();
        if ($token === null) {
            return false;
        }

        $user = $this->userFacade->validateApiToken($token);
        if ($user === null) {
            return false;
        }

        // Set user context
        $this->userFacade->setCurrentUser($user);
        return true;
    }

    /**
     * Extract Bearer token from Authorization header.
     *
     * @return string|null The token or null if not present
     */
    private function extractBearerToken(): ?string
    {
        // Check Authorization header
        $authHeader = $_SERVER['HTTP_AUTHORIZATION'] ?? '';

        // Apache may put it in a different location
        if (empty($authHeader) && function_exists('apache_request_headers')) {
            $headers = apache_request_headers();
            if (is_array($headers)) {
                $rawHeader = (string) ($headers['Authorization'] ?? $headers['authorization'] ?? '');
                $authHeader = $rawHeader;
            }
        }

        // Check for Bearer token
        if (preg_match('/^Bearer\s+(.+)$/i', $authHeader, $matches)) {
            return $matches[1];
        }

        return null;
    }

    /**
     * Handle unauthenticated request.
     *
     * For API requests: return 401 JSON response
     * For web requests: redirect to login page
     *
     * @return void
     */
    private function handleUnauthenticated(): void
    {
        if ($this->isApiRequest()) {
            $this->sendUnauthorizedResponse();
        } else {
            $this->redirectToLogin();
        }
    }

    /**
     * Send 401 Unauthorized JSON response.
     *
     * @return never
     */
    private function sendUnauthorizedResponse(): void
    {
        http_response_code(401);
        header('Content-Type: application/json; charset=utf-8');
        header('WWW-Authenticate: Bearer realm="LWT API"');
        echo json_encode([
            'error' => 'Unauthorized',
            'message' => 'Authentication required. Please provide a valid session or API token.',
        ]);
        exit;
    }

    /**
     * Redirect to login page.
     *
     * Stores the current URL for redirect after login.
     *
     * @return never
     */
    private function redirectToLogin(): void
    {
        // Start session if needed
        if (session_status() === PHP_SESSION_NONE) {
            @session_start();
        }

        // Store intended URL for redirect after login
        $currentUrl = $_SERVER['REQUEST_URI'] ?? '/';
        $_SESSION['auth_redirect'] = $currentUrl;

        header('Location: /login', true, 302);
        exit;
    }
}
