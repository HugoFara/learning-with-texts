<?php

/**
 * WordPress Authentication Service
 *
 * Business logic for WordPress integration and authentication.
 *
 * PHP version 8.2
 *
 * @category Lwt
 * @package  Lwt\Modules\User\Application\Services
 * @author   HugoFara <git@hugofara.net>
 * @license  Unlicense <http://unlicense.org/>
 * @link     https://hugofara.github.io/lwt/developer/api
 * @since    3.0.0
 */

declare(strict_types=1);

namespace Lwt\Modules\User\Application\Services;

use Lwt\Shared\Infrastructure\Globals;
use Lwt\Modules\User\Application\UserFacade;

/**
 * Service class for WordPress authentication integration.
 *
 * Handles WordPress authentication and session management.
 * Links WordPress users to LWT users for multi-user support.
 *
 * @since 3.0.0
 */
class WordPressAuthService
{
    /**
     * Session key for WordPress user ID.
     */
    private const SESSION_KEY = 'LWT-WP-User';

    /**
     * @var UserFacade User facade for LWT user management
     */
    private UserFacade $userFacade;

    /**
     * Create a new WordPressAuthService.
     *
     * @param UserFacade $userFacade User facade for user management
     */
    public function __construct(UserFacade $userFacade)
    {
        $this->userFacade = $userFacade;
    }

    /**
     * Check if WordPress integration is enabled via env var.
     *
     * @return bool True if WORDPRESS_ENABLED is set
     */
    public static function isConfigured(): bool
    {
        return ($_ENV['WORDPRESS_ENABLED'] ?? '') !== '';
    }

    /**
     * Check if WordPress is available and load it.
     *
     * @return bool True if WordPress was loaded successfully
     */
    public function loadWordPress(): bool
    {
        if (!self::isConfigured()) {
            return false;
        }

        $wpLoadPath = $this->getWordPressLoadPath();

        if (!file_exists($wpLoadPath)) {
            return false;
        }

        require_once $wpLoadPath;
        return true;
    }

    /**
     * Get the path to WordPress wp-load.php.
     *
     * LWT must be installed in a subdirectory "lwt" under the WordPress main directory.
     *
     * @return string Path to wp-load.php
     */
    private function getWordPressLoadPath(): string
    {
        return dirname(__DIR__, 5) . '/wp-load.php';
    }

    /**
     * Check if the current user is logged into WordPress.
     *
     * @return bool True if user is logged in
     */
    public function isUserLoggedIn(): bool
    {
        if (!function_exists('is_user_logged_in')) {
            return false;
        }
        /** @var bool */
        $isLoggedIn = \is_user_logged_in();
        return $isLoggedIn;
    }

    /**
     * Get the current WordPress user ID.
     *
     * @return int|null User ID or null if not logged in
     */
    public function getCurrentUserId(): ?int
    {
        if (!$this->isUserLoggedIn()) {
            return null;
        }

        /** @psalm-suppress InvalidGlobal */
        global $current_user;

        if (function_exists('get_currentuserinfo')) {
            \get_currentuserinfo();
        }

        /** @var object{ID?: int|string}|null $current_user */
        if (!is_object($current_user) || !isset($current_user->ID)) {
            return null;
        }

        return (int) $current_user->ID;
    }

    /**
     * Get the current WordPress user information.
     *
     * @return array{id: int, username: string, email: string}|null User info or null if not logged in
     */
    public function getCurrentUserInfo(): ?array
    {
        if (!$this->isUserLoggedIn()) {
            return null;
        }

        /** @psalm-suppress InvalidGlobal */
        global $current_user;

        if (function_exists('get_currentuserinfo')) {
            \get_currentuserinfo();
        }

        /** @var object{ID?: int|string, user_login?: mixed, user_email?: mixed}|null $current_user */
        if (!is_object($current_user) || !isset($current_user->ID)) {
            return null;
        }

        $wpUserId = (int) $current_user->ID;
        $username = isset($current_user->user_login) && is_string($current_user->user_login)
            ? $current_user->user_login
            : 'wp_user_' . $wpUserId;
        $email = isset($current_user->user_email) && is_string($current_user->user_email)
            ? $current_user->user_email
            : 'wp_user_' . $wpUserId . '@localhost';

        return [
            'id' => $wpUserId,
            'username' => $username,
            'email' => $email
        ];
    }

    /**
     * Start a PHP session for LWT-WordPress integration.
     *
     * @return array{success: bool, error: string|null}
     */
    public function startSession(): array
    {
        $started = @session_start();

        if ($started === false) {
            return [
                'success' => false,
                'error' => 'SESSION error (Impossible to start a PHP session)'
            ];
        }

        if (session_id() === '') {
            return [
                'success' => false,
                'error' => 'SESSION ID empty (Impossible to start a PHP session)'
            ];
        }

        if (!isset($_SESSION)) {
            return [
                'success' => false,
                'error' => 'SESSION array not set (Impossible to start a PHP session)'
            ];
        }

        return ['success' => true, 'error' => null];
    }

    /**
     * Store the WordPress user ID in the session.
     *
     * @param int $userId WordPress user ID
     *
     * @return void
     */
    public function setSessionUser(int $userId): void
    {
        $_SESSION[self::SESSION_KEY] = $userId;
    }

    /**
     * Get the WordPress user ID from the session.
     *
     * @return int|null User ID or null if not set
     */
    public function getSessionUser(): ?int
    {
        return isset($_SESSION[self::SESSION_KEY]) ? (int) $_SESSION[self::SESSION_KEY] : null;
    }

    /**
     * Clear the WordPress user from the session.
     *
     * @return void
     */
    public function clearSessionUser(): void
    {
        if (isset($_SESSION[self::SESSION_KEY])) {
            unset($_SESSION[self::SESSION_KEY]);
        }
    }

    /**
     * Validate and sanitize a redirect URL.
     *
     * Ensures the redirect target exists as a local file.
     *
     * @param string|null $redirectUrl The requested redirect URL
     *
     * @return string Safe redirect URL (defaults to 'index.php')
     */
    public function validateRedirectUrl(?string $redirectUrl): string
    {
        if ($redirectUrl === null || $redirectUrl === '') {
            return 'index.php';
        }

        // Extract path before query string and check if file exists
        $path = preg_replace('/^([^?]+).*/', './$1', $redirectUrl);

        if ($path !== null && file_exists($path)) {
            return $redirectUrl;
        }

        return 'index.php';
    }

    /**
     * Get the WordPress login URL with redirect.
     *
     * @param string $redirectTo URL to redirect to after login
     *
     * @return string WordPress login URL
     */
    public function getLoginUrl(string $redirectTo = './lwt/wp_lwt_start.php'): string
    {
        return '../wp-login.php?redirect_to=' . urlencode($redirectTo);
    }

    /**
     * Logout from WordPress and destroy the session.
     *
     * @return void
     */
    public function logout(): void
    {
        // Logout from WordPress
        if (function_exists('wp_logout')) {
            \wp_logout();
        }

        // Destroy the PHP session
        $this->destroySession();
    }

    /**
     * Destroy the current PHP session completely.
     *
     * @return void
     */
    public function destroySession(): void
    {
        if (session_status() === PHP_SESSION_NONE) {
            @session_start();
        }

        session_unset();
        session_destroy();
        session_write_close();

        // Delete session cookie
        $sessionName = session_name();
        if ($sessionName !== false) {
            setcookie($sessionName, '', 0, '/');
        }

        // Regenerate session ID only if a session can be started
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_regenerate_id(true);
        }
    }

    /**
     * Handle the WordPress start flow.
     *
     * Authenticates via WordPress and links the WP user to an LWT user.
     * If multi-user mode is enabled, creates or finds the corresponding
     * LWT user and sets up the user context.
     *
     * @param string|null $redirectUrl Requested redirect URL
     *
     * @return array{success: bool, redirect: string, error: string|null}
     */
    public function handleStart(?string $redirectUrl = null): array
    {
        // Load WordPress
        if (!$this->loadWordPress()) {
            return [
                'success' => false,
                'redirect' => '',
                'error' => 'WordPress not found'
            ];
        }

        // Check if user is logged in
        if (!$this->isUserLoggedIn()) {
            return [
                'success' => false,
                'redirect' => $this->getLoginUrl('./lwt/wp_lwt_start.php'),
                'error' => null
            ];
        }

        // Get WordPress user info
        $wpUserInfo = $this->getCurrentUserInfo();
        if ($wpUserInfo === null) {
            return [
                'success' => false,
                'redirect' => $this->getLoginUrl('./lwt/wp_lwt_start.php'),
                'error' => null
            ];
        }

        // Start session
        $sessionResult = $this->startSession();
        if (!$sessionResult['success']) {
            return [
                'success' => false,
                'redirect' => '',
                'error' => $sessionResult['error']
            ];
        }

        // Store WordPress user ID in session (for backward compatibility)
        $this->setSessionUser($wpUserInfo['id']);

        // Link WordPress user to LWT user (for multi-user support)
        if (Globals::isMultiUserEnabled()) {
            try {
                $lwtUser = $this->userFacade->findOrCreateWordPressUser(
                    $wpUserInfo['id'],
                    $wpUserInfo['username'],
                    $wpUserInfo['email']
                );
                $this->userFacade->setCurrentUser($lwtUser);
            } catch (\Exception $e) {
                return [
                    'success' => false,
                    'redirect' => '',
                    'error' => 'Failed to create LWT user: ' . $e->getMessage()
                ];
            }
        }

        // Validate redirect URL
        $redirectTo = $this->validateRedirectUrl($redirectUrl);

        return [
            'success' => true,
            'redirect' => './' . $redirectTo,
            'error' => null
        ];
    }

    /**
     * Handle the WordPress stop flow.
     *
     * Logs out from WordPress and clears the LWT user context.
     *
     * @return array{success: bool, redirect: string}
     */
    public function handleStop(): array
    {
        // Load WordPress (if available)
        $this->loadWordPress();

        // Clear LWT user context if multi-user mode is enabled
        if (Globals::isMultiUserEnabled()) {
            $this->userFacade->logout();
        }

        // Logout from WordPress and destroy session
        $this->logout();

        return [
            'success' => true,
            'redirect' => $this->getLoginUrl('./lwt/wp_lwt_start.php')
        ];
    }
}
