<?php

/**
 * Auth Form Data Manager
 *
 * Infrastructure adapter for managing authentication form field persistence.
 *
 * PHP version 8.2
 *
 * @category Lwt
 * @package  Lwt\Modules\User\Infrastructure
 * @author   HugoFara <git@hugofara.net>
 * @license  Unlicense <http://unlicense.org/>
 * @link     https://hugofara.github.io/lwt/developer/api
 * @since    3.0.0
 */

declare(strict_types=1);

namespace Lwt\Modules\User\Infrastructure;

/**
 * Adapter for managing authentication form field persistence.
 *
 * Abstracts $_SESSION access for auth form data (username, email, redirect),
 * enabling testability and session backend changes.
 *
 * @since 3.0.0
 */
class AuthFormDataManager
{
    /**
     * Session key prefix for auth data.
     */
    private const KEY_PREFIX = 'auth_';

    /**
     * Session key for password form data.
     */
    private const KEY_PASSWORD_PREFIX = 'password_';

    /**
     * Ensure session is started.
     *
     * @return void
     */
    private function ensureSession(): void
    {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
    }

    // =========================================================================
    // Auth Form Fields
    // =========================================================================

    /**
     * Get the stored username for form repopulation.
     *
     * @return string
     */
    public function getUsername(): string
    {
        $this->ensureSession();
        if (!isset($_SESSION[self::KEY_PREFIX . 'username'])) {
            return '';
        }
        /** @var mixed $value */
        $value = $_SESSION[self::KEY_PREFIX . 'username'];
        return is_string($value) ? $value : '';
    }

    /**
     * Set the username for form repopulation.
     *
     * @param string $username Username
     *
     * @return void
     */
    public function setUsername(string $username): void
    {
        $this->ensureSession();
        $_SESSION[self::KEY_PREFIX . 'username'] = $username;
    }

    /**
     * Clear the stored username.
     *
     * @return void
     */
    public function clearUsername(): void
    {
        $this->ensureSession();
        unset($_SESSION[self::KEY_PREFIX . 'username']);
    }

    /**
     * Get and clear the stored username.
     *
     * @return string
     */
    public function getAndClearUsername(): string
    {
        $username = $this->getUsername();
        $this->clearUsername();
        return $username;
    }

    /**
     * Get the stored email for form repopulation.
     *
     * @return string
     */
    public function getEmail(): string
    {
        $this->ensureSession();
        if (!isset($_SESSION[self::KEY_PREFIX . 'email'])) {
            return '';
        }
        /** @var mixed $value */
        $value = $_SESSION[self::KEY_PREFIX . 'email'];
        return is_string($value) ? $value : '';
    }

    /**
     * Set the email for form repopulation.
     *
     * @param string $email Email
     *
     * @return void
     */
    public function setEmail(string $email): void
    {
        $this->ensureSession();
        $_SESSION[self::KEY_PREFIX . 'email'] = $email;
    }

    /**
     * Clear the stored email.
     *
     * @return void
     */
    public function clearEmail(): void
    {
        $this->ensureSession();
        unset($_SESSION[self::KEY_PREFIX . 'email']);
    }

    /**
     * Get and clear the stored email.
     *
     * @return string
     */
    public function getAndClearEmail(): string
    {
        $email = $this->getEmail();
        $this->clearEmail();
        return $email;
    }

    /**
     * Get the stored redirect URL.
     *
     * @param string $default Default URL if not set
     *
     * @return string
     */
    public function getRedirectUrl(string $default = '/'): string
    {
        $this->ensureSession();
        if (!isset($_SESSION[self::KEY_PREFIX . 'redirect'])) {
            return $default;
        }
        /** @var mixed $value */
        $value = $_SESSION[self::KEY_PREFIX . 'redirect'];
        if (!is_string($value) || !self::isSafeRelativeUrl($value)) {
            return $default;
        }
        return $value;
    }

    /**
     * A stored redirect target is safe iff it's a same-origin path.
     *
     * AuthMiddleware stores raw $_SERVER['REQUEST_URI'] when redirecting
     * unauthenticated users to /login. An attacker who tricks a victim
     * into visiting `https://lwt.example.com//evil.com/phish` gets
     * `REQUEST_URI = //evil.com/phish` stored verbatim; if the post-login
     * redirect followed it, the browser would interpret the leading `//`
     * as protocol-relative and navigate to evil.com. Reject anything that
     * doesn't start with a single `/` followed by something other than
     * `/` or `\` (some browsers treat `\` like `/` in URL paths).
     */
    private static function isSafeRelativeUrl(string $url): bool
    {
        if ($url === '' || $url[0] !== '/') {
            return false;
        }
        if (strlen($url) >= 2 && ($url[1] === '/' || $url[1] === '\\')) {
            return false;
        }
        return true;
    }

    /**
     * Set the redirect URL for post-login navigation.
     *
     * @param string $url Redirect URL
     *
     * @return void
     */
    public function setRedirectUrl(string $url): void
    {
        $this->ensureSession();
        $_SESSION[self::KEY_PREFIX . 'redirect'] = $url;
    }

    /**
     * Clear the stored redirect URL.
     *
     * @return void
     */
    public function clearRedirectUrl(): void
    {
        $this->ensureSession();
        unset($_SESSION[self::KEY_PREFIX . 'redirect']);
    }

    /**
     * Get and clear the stored redirect URL.
     *
     * @param string $default Default URL if not set
     *
     * @return string
     */
    public function getAndClearRedirectUrl(string $default = '/'): string
    {
        $url = $this->getRedirectUrl($default);
        $this->clearRedirectUrl();
        return $url;
    }

    /**
     * Clear all auth form data (username, email, redirect).
     *
     * @return void
     */
    public function clearAll(): void
    {
        $this->clearUsername();
        $this->clearEmail();
        $this->clearRedirectUrl();
        $this->clearPasswordEmail();
    }

    // =========================================================================
    // Password Reset Form Fields
    // =========================================================================

    /**
     * Get the stored password form email.
     *
     * @return string
     */
    public function getPasswordEmail(): string
    {
        $this->ensureSession();
        if (!isset($_SESSION[self::KEY_PASSWORD_PREFIX . 'email'])) {
            return '';
        }
        /** @var mixed $value */
        $value = $_SESSION[self::KEY_PASSWORD_PREFIX . 'email'];
        return is_string($value) ? $value : '';
    }

    /**
     * Set the password form email.
     *
     * @param string $email Email
     *
     * @return void
     */
    public function setPasswordEmail(string $email): void
    {
        $this->ensureSession();
        $_SESSION[self::KEY_PASSWORD_PREFIX . 'email'] = $email;
    }

    /**
     * Clear the password form email.
     *
     * @return void
     */
    public function clearPasswordEmail(): void
    {
        $this->ensureSession();
        unset($_SESSION[self::KEY_PASSWORD_PREFIX . 'email']);
    }

    /**
     * Get and clear the password form email.
     *
     * @return string
     */
    public function getAndClearPasswordEmail(): string
    {
        $email = $this->getPasswordEmail();
        $this->clearPasswordEmail();
        return $email;
    }
}
