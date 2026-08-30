<?php

/**
 * Response Interface
 *
 * PHP version 8.2
 *
 * @category Lwt
 * @package  Lwt\Shared\Infrastructure\Http
 * @author   HugoFara <git@hugofara.net>
 * @license  Unlicense <http://unlicense.org/>
 * @link     https://hugofara.github.io/lwt/developer/api
 * @since    3.0.0
 */

declare(strict_types=1);

namespace Lwt\Shared\Infrastructure\Http;

/**
 * Interface for HTTP response objects.
 *
 * Response objects encapsulate HTTP response data (status, headers, body)
 * and can be sent by the router after controller execution.
 *
 * @since 3.0.0
 */
interface ResponseInterface
{
    /**
     * Send the response to the client.
     *
     * This method outputs headers and body content.
     *
     * @return void
     */
    public function send(): void;

    /**
     * Get the HTTP status code.
     *
     * @return int HTTP status code (e.g., 200, 302, 404)
     */
    public function getStatusCode(): int;
}
