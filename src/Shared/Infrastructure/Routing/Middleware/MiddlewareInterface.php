<?php

/**
 * Middleware Interface
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

/**
 * Interface for route middleware.
 *
 * Middleware can inspect and modify requests before they reach controllers,
 * and can halt request processing (e.g., for authentication failures).
 *
 * @category Lwt
 * @package  Lwt\Shared\Infrastructure\Routing\Middleware
 * @author   HugoFara <git@hugofara.net>
 * @license  Unlicense <http://unlicense.org/>
 * @link     https://hugofara.github.io/lwt/developer/api
 * @since    3.0.0
 */
interface MiddlewareInterface
{
    /**
     * Handle the incoming request.
     *
     * Return true to continue to the next middleware/controller.
     * Return false to halt request processing (middleware should handle response).
     *
     * @return bool True if the request should continue, false to halt
     */
    public function handle(): bool;
}
