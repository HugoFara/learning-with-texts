<?php

/**
 * Admin Authorization Middleware
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

use Lwt\Modules\User\Domain\User;
use Lwt\Shared\Infrastructure\Globals;
use Lwt\Modules\User\Application\UserFacade;
use Lwt\Shared\Infrastructure\Container\Container;

/**
 * Middleware that requires admin role authorization.
 *
 * This middleware first checks authentication (like AuthMiddleware),
 * then verifies the user has the admin role.
 *
 * Use this for admin-only routes like database wizard, settings, etc.
 *
 * @category Lwt
 * @package  Lwt\Shared\Infrastructure\Routing\Middleware
 * @author   HugoFara <git@hugofara.net>
 * @license  Unlicense <http://unlicense.org/>
 * @link     https://hugofara.github.io/lwt/developer/api
 * @since    3.0.0
 */
class AdminMiddleware implements MiddlewareInterface
{
    /**
     * User facade instance.
     *
     * @var UserFacade
     */
    private UserFacade $userFacade;

    /**
     * Create a new AdminMiddleware.
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
     * Checks for valid authentication and admin role.
     * On failure:
     * - For API requests: returns 403 JSON response
     * - For web requests: redirects to home with error
     *
     * @return bool True if authorized, false if halted
     */
    public function handle(): bool
    {
        // Skip authorization if multi-user mode is disabled
        if (!Globals::isMultiUserEnabled()) {
            return true;
        }

        // First, check authentication
        $user = $this->getCurrentUser();
        if ($user === null) {
            $this->handleUnauthenticated();
            return false;
        }

        // Then, check admin role
        if (!$user->isAdmin()) {
            $this->handleUnauthorized();
            return false;
        }

        return true;
    }

    /**
     * Get the current authenticated user.
     *
     * @return User|null The current user or null if not authenticated
     */
    private function getCurrentUser(): ?User
    {
        // Try session first
        if ($this->userFacade->validateSession()) {
            return $this->userFacade->getCurrentUser();
        }

        // Try API token
        $token = $this->extractBearerToken();
        if ($token !== null) {
            $user = $this->userFacade->validateApiToken($token);
            if ($user !== null) {
                $this->userFacade->setCurrentUser($user);
                return $user;
            }
        }

        return null;
    }

    /**
     * Extract Bearer token from Authorization header.
     *
     * @return string|null The token or null if not present
     */
    private function extractBearerToken(): ?string
    {
        $authHeader = $_SERVER['HTTP_AUTHORIZATION'] ?? '';

        if (empty($authHeader) && function_exists('apache_request_headers')) {
            $headers = apache_request_headers();
            if (is_array($headers)) {
                $rawHeader = (string) ($headers['Authorization'] ?? $headers['authorization'] ?? '');
                $authHeader = $rawHeader;
            }
        }

        if (preg_match('/^Bearer\s+(.+)$/i', $authHeader, $matches)) {
            return $matches[1];
        }

        return null;
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

        if (str_starts_with($requestPath, '/api/')) {
            return true;
        }

        $accept = $_SERVER['HTTP_ACCEPT'] ?? '';
        if (str_contains($accept, 'application/json')) {
            return true;
        }

        $xRequestedWith = $_SERVER['HTTP_X_REQUESTED_WITH'] ?? '';
        if (strtolower($xRequestedWith) === 'xmlhttprequest') {
            return true;
        }

        return false;
    }

    /**
     * Handle unauthenticated request.
     *
     * @return void
     */
    private function handleUnauthenticated(): void
    {
        if ($this->isApiRequest()) {
            http_response_code(401);
            header('Content-Type: application/json; charset=utf-8');
            header('WWW-Authenticate: Bearer realm="LWT API"');
            echo json_encode([
                'error' => 'Unauthorized',
                'message' => 'Authentication required.',
            ]);
        } else {
            if (session_status() === PHP_SESSION_NONE) {
                @session_start();
            }
            $_SESSION['auth_redirect'] = $_SERVER['REQUEST_URI'] ?? '/';
            header('Location: /login', true, 302);
        }
        exit;
    }

    /**
     * Handle unauthorized request (authenticated but not admin).
     *
     * @return void
     */
    private function handleUnauthorized(): void
    {
        if ($this->isApiRequest()) {
            http_response_code(403);
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode([
                'error' => 'Forbidden',
                'message' => 'Admin privileges required.',
            ]);
        } else {
            if (session_status() === PHP_SESSION_NONE) {
                @session_start();
            }
            $_SESSION['flash_error'] = 'Admin privileges required to access this page.';
            header('Location: /', true, 302);
        }
        exit;
    }
}
