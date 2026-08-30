<?php

/**
 * \file
 * \brief Session bootstrap class - handles PHP session initialization.
 *
 * PHP version 8.2
 *
 * @category Lwt
 * @package  Lwt\Shared\Infrastructure\Bootstrap
 * @author   HugoFara <git@hugofara.net>
 * @license  Unlicense <http://unlicense.org/>
 * @link     https://hugofara.github.io/lwt/developer/api
 * @since    3.0.0
 */

declare(strict_types=1);

namespace Lwt\Shared\Infrastructure\Bootstrap;

use Lwt\Shared\Infrastructure\Globals;
use Lwt\Shared\Infrastructure\Http\UrlUtilities;
use Lwt\Shared\Infrastructure\Utilities\ErrorHandler;

/**
 * Session bootstrap utility class.
 *
 * Provides static methods for configuring and starting PHP sessions
 * with proper security settings.
 *
 * @since 3.0.0
 */
class SessionBootstrap
{
    /**
     * Set error reporting level.
     *
     * @param bool $displayErrors True to enable all error reporting
     *
     * @return void
     */
    public static function setErrorReporting(bool $displayErrors): void
    {
        if ($displayErrors) {
            @\error_reporting(E_ALL);
            @\ini_set('display_errors', '1');
            @\ini_set('display_startup_errors', '1');
        } else {
            @\error_reporting(0);
            @\ini_set('display_errors', '0');
            @\ini_set('display_startup_errors', '0');
        }
    }

    /**
     * Set PHP configuration options like time limits and memory.
     *
     * @return void
     */
    public static function setConfigurationOptions(): void
    {
        // Set script time limit
        @\ini_set('max_execution_time', '600');  // 10 min.
        @\set_time_limit(600);  // 10 min.

        @\ini_set('memory_limit', '999M');
    }

    /**
     * Detect if the current request is over HTTPS.
     *
     * Delegates to `UrlUtilities::isSecureRequest()` so direct-HTTPS,
     * port-443, and `X-Forwarded-Proto` (when the proxy is trusted)
     * are handled identically across the codebase.
     *
     * @return bool True if the connection is secure
     */
    public static function isSecureConnection(): bool
    {
        return UrlUtilities::isSecureRequest();
    }

    /**
     * Configure secure session cookie parameters.
     *
     * Sets HttpOnly, Secure, and SameSite flags to protect against:
     * - XSS attacks (HttpOnly prevents JavaScript access)
     * - Man-in-the-middle attacks (Secure ensures HTTPS-only transmission)
     * - CSRF attacks (SameSite restricts cross-site cookie sending)
     *
     * @return void
     */
    public static function configureSessionCookie(): void
    {
        $isSecure = self::isSecureConnection();

        \session_set_cookie_params([
            'lifetime' => 0,           // Session cookie (expires when browser closes)
            'path' => '/',             // Available across entire domain
            'domain' => '',            // Current domain only
            'secure' => $isSecure,     // Only send over HTTPS when available
            'httponly' => true,        // Prevent JavaScript access (XSS protection)
            'samesite' => 'Lax'        // CSRF protection while allowing normal navigation
        ]);
    }

    /**
     * Start the session and validate it.
     *
     * @return void
     */
    public static function startSession(): void
    {
        // Configure secure cookie parameters before starting session
        self::configureSessionCookie();

        // session isn't started
        $err = @\session_start();
        if ($err === false) {
            ErrorHandler::die('SESSION error (Impossible to start a PHP session)');
        }
        if (\session_id() == '') {
            ErrorHandler::die('SESSION ID empty (Impossible to start a PHP session)');
        }
        if (!isset($_SESSION)) {
            ErrorHandler::die('SESSION array not set (Impossible to start a PHP session)');
        }
    }

    /**
     * Initialize session and configuration.
     *
     * Main entry point that sets up error reporting, configuration,
     * and starts the session if needed.
     *
     * @return void
     */
    public static function bootstrap(): void
    {
        // Errors are logged, never displayed: output would corrupt HTML and API responses.
        self::setErrorReporting(false);
        self::setConfigurationOptions();
        // Start a PHP session if not one already exists
        if (\session_id() == '') {
            self::startSession();
        }
    }
}
