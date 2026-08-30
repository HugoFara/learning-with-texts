<?php

/**
 * Default NlpHttpClient implementation using PHP streams.
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
 * NlpHttpClient implementation backed by file_get_contents + stream contexts.
 */
final class StreamNlpHttpClient implements NlpHttpClient
{
    public function request(
        string $url,
        string $method,
        ?string $jsonBody = null,
        int $timeoutSeconds = 30,
        bool $ignoreErrors = false,
    ): ?string {
        $http = [
            'method' => $method,
            'timeout' => $timeoutSeconds,
        ];
        if ($ignoreErrors) {
            $http['ignore_errors'] = true;
        }
        if ($jsonBody !== null) {
            $http['header'] = 'Content-Type: application/json';
            $http['content'] = $jsonBody;
        }

        $context = stream_context_create(['http' => $http]);
        $response = @file_get_contents($url, false, $context);

        return $response === false ? null : $response;
    }
}
