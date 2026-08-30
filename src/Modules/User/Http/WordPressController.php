<?php

/**
 * WordPress Controller
 *
 * Controller for WordPress integration endpoints.
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
use Lwt\Modules\User\Application\Services\WordPressAuthService;

/**
 * Controller for WordPress integration.
 *
 * Handles:
 * - WordPress start (login flow)
 * - WordPress stop (logout flow)
 *
 * @since 3.0.0
 */
class WordPressController extends BaseController
{
    /**
     * @var WordPressAuthService WordPress auth service instance
     */
    protected WordPressAuthService $wordPressAuthService;

    /**
     * Create a new WordPressController.
     *
     * @param WordPressAuthService $wordPressAuthService WordPress auth service
     */
    public function __construct(WordPressAuthService $wordPressAuthService)
    {
        parent::__construct();
        $this->wordPressAuthService = $wordPressAuthService;
    }

    /**
     * Get the WordPress auth service instance.
     *
     * @return WordPressAuthService
     */
    public function getWordPressAuthService(): WordPressAuthService
    {
        return $this->wordPressAuthService;
    }

    /**
     * WordPress start - handle login flow (replaces wordpress_start.php)
     *
     * To start LWT with WordPress login, use this URL:
     * http://...path-to-wp-blog.../lwt/wp_lwt_start.php
     *
     * Cookies must be enabled. A session cookie will be set.
     * The LWT installation must be in sub directory "lwt" under
     * the WordPress main directory.
     *
     * @param array<string, mixed> $params Route parameters
     *
     * @return void
     */
    public function start(array $params): void
    {
        $redirectUrl = $this->param('rd');

        $result = $this->wordPressAuthService->handleStart($redirectUrl);

        if (!$result['success'] && $result['error'] !== null) {
            throw new AuthException($result['error']);
        }

        $this->redirect($result['redirect']);
    }

    /**
     * WordPress stop - handle logout flow (replaces wordpress_stop.php)
     *
     * To properly log out from both WordPress and LWT, use:
     * http://...path-to-wp-blog.../lwt/wp_lwt_stop.php
     *
     * @param array<string, mixed> $params Route parameters
     *
     * @return void
     */
    public function stop(array $params): void
    {
        $result = $this->wordPressAuthService->handleStop();

        $this->redirect($result['redirect']);
    }
}
