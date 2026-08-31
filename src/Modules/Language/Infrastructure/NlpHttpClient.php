<?php

/**
 * Transport abstraction for NlpServiceHandler.
 *
 * PHP version 8.2
 *
 * @category Infrastructure
 * @package  Lwt\Modules\Language\Infrastructure
 * @author   HugoFara <git@hugofara.net>
 * @license  Unlicense <http://unlicense.org/>
 * @link     https://hugofara.github.io/lwt/developer/api
 * @since    3.1.0
 */

declare(strict_types=1);

namespace Lwt\Modules\Language\Infrastructure;

/**
 * Injectable HTTP transport used by NlpServiceHandler.
 *
 * Implementations return the raw response body on success and null when the
 * request could not be completed (network failure, DNS error, refused, or
 * non-success HTTP status when $ignoreErrors is false).
 */
interface NlpHttpClient
{
    public function request(
        string $url,
        string $method,
        ?string $jsonBody = null,
        int $timeoutSeconds = 30,
        bool $ignoreErrors = false,
    ): ?string;
}
