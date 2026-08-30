<?php

/**
 * Logout Use Case
 *
 * PHP version 8.2
 *
 * @category Lwt
 * @package  Lwt\Modules\User\Application\UseCases
 * @author   HugoFara <git@hugofara.net>
 * @license  Unlicense <http://unlicense.org/>
 * @link     https://hugofara.github.io/lwt/developer/api
 * @since    3.0.0
 */

declare(strict_types=1);

namespace Lwt\Modules\User\Application\UseCases;

use Lwt\Shared\Infrastructure\Globals;

/**
 * Use case for user logout.
 *
 * Handles session destruction and cleanup.
 *
 * @since 3.0.0
 */
class Logout
{
    /**
     * Execute the logout.
     *
     * @return void
     */
    public function execute(): void
    {
        Globals::setCurrentUserId(null);
        $this->destroySession();
    }

    /**
     * Destroy the current session.
     *
     * @return void
     */
    private function destroySession(): void
    {
        if (session_status() === PHP_SESSION_NONE) {
            @session_start();
        }

        $_SESSION = [];

        if (ini_get('session.use_cookies') !== false) {
            $params = session_get_cookie_params();
            $sessionName = session_name();
            setcookie(
                $sessionName !== false ? $sessionName : 'PHPSESSID',
                '',
                time() - 42000,
                $params['path'] ?? '/',
                $params['domain'] ?? '',
                $params['secure'] ?? false,
                $params['httponly'] ?? false
            );
        }

        session_destroy();
    }
}
