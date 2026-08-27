<?php

/**
 * API V1 Response helper.
 *
 * PHP version 8.1
 *
 * @category Api
 * @package  Lwt
 * @author   HugoFara <git@hugofara.net>
 * @license  Unlicense <http://unlicense.org/>
 * @link     https://hugofara.github.io/lwt/developer/api
 * @since    3.0.0
 */

declare(strict_types=1);

namespace Lwt\Api\V1;

use Lwt\Shared\Infrastructure\Http\JsonResponse;

/**
 * Standardized JSON response helper for API V1.
 *
 * Returns JsonResponse objects that can be sent by the caller.
 */
class Response
{
    /**
     * Status given to a handler payload that reports a failure.
     *
     * 400 rather than a shape-specific code: the payloads carry a message but
     * no indication of *why* they failed, and guessing 404 from wording would
     * break as soon as a message is translated. A handler that knows better
     * should call {@see self::error()} or {@see self::notFound()} directly.
     */
    private const FAILURE_STATUS = 400;

    /**
     * Create JSON response.
     *
     * @param int   $status HTTP status code
     * @param mixed $data   Response data
     *
     * @return JsonResponse
     */
    public static function send(int $status, mixed $data): JsonResponse
    {
        return new JsonResponse($data, $status);
    }

    /**
     * Create success response.
     *
     * Many handlers report a failure by *returning* `['error' => ...]` or
     * `['success' => false, ...]` rather than by throwing, and every router
     * hands that return value straight to this method. Sent as-is it becomes
     * HTTP 200, so a failed write is indistinguishable from a successful one:
     * `fetch` reports `ok`, the client takes the payload as data, and the UI
     * silently does nothing (issue #284 — observed on POST /terms/quick, where
     * the underlying duplicate-key failure of #283 was invisible).
     *
     * Rather than rewrite 300-odd return sites, the shape is recognised here
     * and given a 4xx status. The body is left exactly as the handler built
     * it, so anything already reading `error` out of the payload keeps working
     * — only the status becomes honest.
     *
     * @param mixed $data   Response data
     * @param int   $status HTTP status code (default 200)
     *
     * @return JsonResponse
     */
    public static function success(mixed $data, int $status = 200): JsonResponse
    {
        // Only the default is promoted: a caller that named a status meant it.
        if ($status === 200 && self::signalsFailure($data)) {
            $status = self::FAILURE_STATUS;
        }

        return self::send($status, $data);
    }

    /**
     * Does this payload report a failure rather than carry a result?
     *
     * `'error' => null` is common on the *success* branch of handlers that
     * always include the key, so presence alone means nothing — the value has
     * to be a non-empty message (or a bare `true` flag) to count.
     *
     * @param mixed $data Payload a handler returned
     *
     * @return bool True when the payload describes a failure
     */
    private static function signalsFailure(mixed $data): bool
    {
        if (!is_array($data)) {
            return false;
        }

        if (array_key_exists('success', $data) && $data['success'] === false) {
            return true;
        }

        /** @var mixed $error */
        $error = $data['error'] ?? null;
        if (is_string($error)) {
            return trim($error) !== '';
        }

        return $error === true;
    }

    /**
     * Create error response.
     *
     * @param string $message Error message
     * @param int    $status  HTTP status code (default 400)
     *
     * @return JsonResponse
     */
    public static function error(string $message, int $status = 400): JsonResponse
    {
        return self::send($status, ['error' => $message]);
    }

    /**
     * Create not found response.
     *
     * @param string $message Error message (default: "Not found")
     *
     * @return JsonResponse
     */
    public static function notFound(string $message = 'Not found'): JsonResponse
    {
        return self::error($message, 404);
    }

    /**
     * Create created response (201).
     *
     * @param mixed $data Response data
     *
     * @return JsonResponse
     */
    public static function created(mixed $data): JsonResponse
    {
        return self::send(201, $data);
    }
}
