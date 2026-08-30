<?php

/**
 * API V1 Endpoints registry.
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
 * Registry of API V1 endpoints.
 *
 * Extracted from api_v1.php lines 999-1070.
 */
class Endpoints
{
    /**
     * @var array<string, string[]> Map of endpoint patterns to allowed HTTP methods
     */
    private const ROUTES = [
        // Auth endpoints
        'auth' => ['GET', 'POST'],
        'auth/login' => ['POST'],
        'auth/register' => ['POST'],
        'auth/altcha-challenge' => ['GET'],
        'auth/refresh' => ['POST'],
        'auth/logout' => ['POST'],
        'auth/me' => ['GET'],

        'languages' => ['GET', 'POST', 'PUT', 'DELETE'],
        'languages/definitions' => ['GET'],
        'languages/with-texts' => ['GET'],
        'languages/with-archived-texts' => ['GET'],
        'i18n' => ['GET'],
        'media-files' => ['GET'],
        'navbar' => ['GET'],
        'phonetic-reading' => ['GET'],
        'review/next-word' => ['GET'],
        'review/tomorrow-count' => ['GET'],
        'review/status' => ['PUT'],
        'review/config' => ['GET'],
        'review/table-words' => ['GET'],
        'review/intervals' => ['GET'],
        'sentences-with-term' => ['GET'],
        'similar-terms' => ['GET'],
        'settings' => ['POST'],
        'settings/theme-path' => ['GET'],
        'statuses' => ['GET'],
        // A URL carrying an ID never matches these sub-keys exactly, so
        // getMethodsForEndpoint() falls back to the bare 'tags' entry; that
        // entry has to permit every method any tags/... route uses.
        'tags' => ['GET', 'POST', 'PUT', 'DELETE'],
        'tags/term' => ['GET', 'POST', 'DELETE'],
        'tags/text' => ['GET', 'POST', 'DELETE'],
        'terms' => ['GET', 'POST', 'PUT', 'DELETE'],
        'terms/imported' => ['GET'],
        'terms/new' => ['POST'],
        'terms/quick' => ['POST'],
        'terms/full' => ['POST'],
        'terms/for-language' => ['POST'],
        'terms/bulk' => ['POST'],
        'terms/for-edit' => ['GET'],
        'terms/unknown-for-translate' => ['GET'],
        'terms/bulk-status' => ['PUT'],
        'terms/list' => ['GET'],
        'terms/filter-options' => ['GET'],
        'terms/bulk-action' => ['PUT'],
        'terms/all-action' => ['PUT'],
        'terms/family' => ['GET'],
        'terms/family/status' => ['PUT'],
        'terms/family/suggestion' => ['GET'],
        'terms/family/apply' => ['PUT'],
        'word-families' => ['GET'],
        'word-families/stats' => ['GET'],
        'texts' => ['GET', 'POST', 'PUT'],
        'texts/check' => ['POST'],
        'texts/extract-url' => ['POST'],
        'texts/extract-epub-url' => ['POST'],
        'texts/gutenberg-suggestions' => ['GET'],
        'texts/library-search' => ['GET'],
        'texts/library-preview' => ['GET'],
        'texts/gdl-search' => ['GET'],
        'texts/reader-level' => ['GET'],
        'texts/statistics' => ['GET'],
        'texts/scoring' => ['GET'],
        'texts/scoring/recommended' => ['GET'],
        'texts/by-language' => ['GET'],
        'texts/archived-by-language' => ['GET'],
        'feeds' => ['GET', 'POST', 'PUT', 'DELETE'],
        'feeds/list' => ['GET'],
        'feeds/articles' => ['GET'],
        'feeds/articles/import' => ['POST'],
        'feeds/articles/extract' => ['POST'],
        'feeds/articles/create-texts' => ['POST'],
        'feeds/wizard/preview' => ['POST'],
        'feeds/wizard/article' => ['POST'],
        // A URL carrying an ID (books/12/progress) never matches a key here
        // exactly, so getMethodsForEndpoint() falls back to the first segment.
        // The bare 'books' entry therefore has to permit every method any
        // books/{id}/... route uses, or that route 405s before reaching the
        // handler. The two sub-keys below are documentation, not enforcement.
        'books' => ['GET', 'POST', 'PUT', 'DELETE'],
        'books/chapters' => ['GET'],
        'books/progress' => ['PUT'],
        'local-dictionaries' => ['GET', 'POST', 'PUT', 'DELETE'],
        'local-dictionaries/lookup' => ['GET'],
        'local-dictionaries/preview' => ['POST'],
        'local-dictionaries/import-curated' => ['POST'],
        'local-dictionaries/entries' => ['GET', 'POST', 'PUT', 'DELETE'],
        // Admin-only user management. The bare 'admin' entry is what a URL
        // carrying an ID actually matches, so it has to permit every method;
        // UserManagementApiHandler enforces the admin role itself.
        'admin' => ['GET', 'POST', 'PUT', 'DELETE'],
        'admin/users' => ['GET', 'POST'],
        // The signed-in user's own profile; no ID in any path.
        'profile' => ['GET', 'PUT'],
        'profile/preferences' => ['GET', 'PUT'],
        'activity' => ['GET'],
        'activity/streak' => ['GET'],
        'activity/calendar' => ['GET'],
        'activity/today' => ['GET'],
        'activity/dashboard' => ['GET'],
        'version' => ['GET'],

        // TTS endpoints (Piper TTS via NLP microservice)
        'tts/voices' => ['GET', 'DELETE'],  // GET for list, DELETE for tts/voices/{id}
        'tts/voices/installed' => ['GET'],
        'tts/voices/download' => ['POST'],
        'tts/speak' => ['POST'],

        // Whisper transcription endpoints (Whisper via NLP microservice).
        // First-segment fallback in getMethodsForEndpoint resolves dynamic
        // subpaths like whisper/status/{job_id}, whisper/result/{job_id},
        // and whisper/job/{job_id} against this entry.
        'whisper' => ['GET', 'POST', 'DELETE'],
        'whisper/available' => ['GET'],
        'whisper/languages' => ['GET'],
        'whisper/models' => ['GET'],
        'whisper/transcribe' => ['POST'],

        // YouTube transcript endpoints (Google API key required at runtime).
        // routeGet branches on the first segment ('configured' or 'video');
        // the segment-fallback below covers both.
        'youtube' => ['GET'],
        'youtube/configured' => ['GET'],
        'youtube/video' => ['GET'],
    ];

    /**
     * Check if an API endpoint exists and return it.
     *
     * @param string $method     HTTP method (e.g. 'GET' or 'POST')
     * @param string $requestUri The URI being requested
     *
     * @return string|JsonResponse The matching endpoint path or error response
     */
    public static function resolve(string $method, string $requestUri): string|JsonResponse
    {
        $uriQuery = parse_url($requestUri, PHP_URL_PATH);
        if ($uriQuery === null || $uriQuery === false) {
            return Response::error('Invalid URL format', 400);
        }

        // Support both legacy /api.php/v1/ and new /api/v1/ URL formats
        $matching = preg_match('/(.*?\/api(?:\.php)?\/v\d\/).+/', $uriQuery, $matches);
        if (!$matching) {
            return Response::error('Unrecognized URL format ' . $uriQuery, 400);
        }
        if (count($matches) == 0) {
            return Response::error('Wrong API Location: ' . $uriQuery, 404);
        }

        // endpoint without prepending URL, like 'version'
        $reqEndpoint = rtrim(str_replace($matches[1], '', $uriQuery), '/');

        $methodsAllowed = self::getMethodsForEndpoint($reqEndpoint);
        if ($methodsAllowed === null) {
            return Response::error('Endpoint Not Found: ' . $reqEndpoint, 404);
        }

        // Validate request method for the endpoint
        if (!in_array($method, $methodsAllowed)) {
            return Response::error('Method Not Allowed', 405);
        }

        return $reqEndpoint;
    }

    /**
     * Get allowed methods for an endpoint.
     *
     * @param string $endpoint Endpoint path
     *
     * @return string[]|null Allowed methods or null if not found
     */
    private static function getMethodsForEndpoint(string $endpoint): ?array
    {
        if (array_key_exists($endpoint, self::ROUTES)) {
            return self::ROUTES[$endpoint];
        }

        // Check first segment for dynamic endpoints (e.g., terms/123/status)
        $segments = preg_split('/\//', $endpoint);
        $firstElem = $segments !== false && isset($segments[0]) ? $segments[0] : '';
        if ($firstElem !== '' && array_key_exists($firstElem, self::ROUTES)) {
            return self::ROUTES[$firstElem];
        }

        return null;
    }

    /**
     * Parse endpoint into fragments.
     *
     * @param string $endpoint Endpoint path
     *
     * @return list<string> Endpoint path segments
     */
    public static function parseFragments(string $endpoint): array
    {
        $result = preg_split("/\//", $endpoint);
        if ($result === false) {
            return [];
        }
        /** @var list<string> */
        return $result;
    }
}
