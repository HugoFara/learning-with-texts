<?php

/**
 * API V1 Entry Point.
 *
 * Dispatches API requests to module-specific handlers via a route map.
 * Each handler implements ApiRoutableInterface with routeGet/Post/Put/Delete.
 *
 * @category Lwt
 * @package  Lwt\Api\V1
 * @author   HugoFara <git@hugofara.net>
 * @license  Unlicense <http://unlicense.org/>
 * @link     https://hugofara.github.io/lwt/developer/api
 * @since    3.0.0
 */

declare(strict_types=1);

namespace Lwt\Api\V1;

use Lwt\Shared\Infrastructure\ApplicationInfo;
use Lwt\Shared\Infrastructure\Globals;
use Lwt\Shared\I18n\Translator;
use Lwt\Shared\Infrastructure\Container\Container;
use Lwt\Shared\Infrastructure\Http\JsonResponse;
use Lwt\Shared\UI\Helpers\PageLayoutHelper;
use Lwt\Shared\Infrastructure\Http\Cors;
use Lwt\Shared\Http\ApiRoutableInterface;
use Lwt\Modules\User\Http\ProfileApiHandler;
use Lwt\Modules\User\Http\UserApiHandler;
use Lwt\Modules\Language\Http\LanguageApiHandler;
use Lwt\Modules\Review\Http\ReviewApiHandler;
use Lwt\Modules\Tags\Http\TagApiHandler;
use Lwt\Modules\Admin\Http\AdminApiHandler;
use Lwt\Modules\Admin\Http\UserManagementApiHandler;
use Lwt\Modules\Vocabulary\Http\AnkiApiHandler;
use Lwt\Modules\Vocabulary\Http\VocabularyApiRouter;
use Lwt\Modules\Vocabulary\Http\WordFamilyApiHandler;
use Lwt\Modules\Text\Http\TextApiHandler;
use Lwt\Modules\Feed\Http\FeedApiHandler;
use Lwt\Modules\Book\Http\BookApiHandler;
use Lwt\Modules\Dictionary\Http\DictionaryApiHandler;
use Lwt\Modules\Text\Http\YouTubeApiHandler;
use Lwt\Modules\Activity\Http\ActivityApiHandler;
use Lwt\Modules\Language\Infrastructure\NlpServiceHandler;
use Lwt\Modules\Text\Http\WhisperApiHandler;

/**
 * Main API V1 handler class.
 *
 * Uses a route map to dispatch requests to module-specific handlers
 * resolved from the DI container.
 */
class ApiV1
{
    /**
     * Endpoints that do not require authentication.
     *
     * @var array<string, bool>
     */
    private const PUBLIC_ENDPOINTS = [
        'auth/login' => true,
        'auth/register' => true,
        'auth/altcha-challenge' => true,
        'version' => true,
    ];

    /**
     * Top-level route names that are public regardless of trailing segments.
     *
     * Unlike PUBLIC_ENDPOINTS (exact match), these match the first path
     * fragment, so "i18n/es" or "i18n?locale=es" are all public. UI strings
     * are not user data and the client needs them before authentication
     * (e.g. on the /connect screen).
     *
     * @var array<string, bool>
     */
    private const PUBLIC_RESOURCES = [
        'i18n' => true,
    ];

    /**
     * Map of top-level route names to handler classes.
     *
     * Each handler implements ApiRoutableInterface. The route method
     * (routeGet, routePost, routePut, routeDelete) receives the full
     * fragments array and request params.
     *
     * @var array<string, class-string<ApiRoutableInterface>>
     */
    private const HANDLER_MAP = [
        'anki'               => AnkiApiHandler::class,
        'auth'               => UserApiHandler::class,
        'languages'          => LanguageApiHandler::class,
        'review'             => ReviewApiHandler::class,
        'settings'           => AdminApiHandler::class,
        'tags'               => TagApiHandler::class,
        'terms'              => VocabularyApiRouter::class,
        'word-families'      => WordFamilyApiHandler::class,
        'texts'              => TextApiHandler::class,
        'feeds'              => FeedApiHandler::class,
        'books'              => BookApiHandler::class,
        'local-dictionaries' => DictionaryApiHandler::class,
        'youtube'            => YouTubeApiHandler::class,
        'tts'                => NlpServiceHandler::class,
        'whisper'            => WhisperApiHandler::class,
        'activity'           => ActivityApiHandler::class,
        'admin'              => UserManagementApiHandler::class,
        'profile'            => ProfileApiHandler::class,
    ];

    private Container $container;

    public function __construct(?Container $container = null)
    {
        $this->container = $container ?? Container::getInstance();
    }

    /**
     * Handle the incoming API request.
     *
     * @param string                     $method   HTTP method
     * @param string                     $uri      Request URI
     * @param array<string, mixed>|null  $postData POST data (also used for PUT/DELETE with JSON body)
     */
    public function handle(string $method, string $uri, ?array $postData): void
    {
        $endpointResult = Endpoints::resolve($method, $uri);

        if ($endpointResult instanceof JsonResponse) {
            $endpointResult->send();
            return;
        }

        $endpoint = $endpointResult;
        $fragments = Endpoints::parseFragments($endpoint);

        // Validate authentication for protected endpoints
        if (!$this->isPublicEndpoint($endpoint)) {
            $authError = $this->validateAuth();
            if ($authError !== null) {
                $authError->send();
                return;
            }
        }

        // Release the session lock early so concurrent browser requests
        // (e.g. form submissions) are not blocked while API calls run.
        // API handlers do not write to the session after this point.
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_write_close();
        }

        $params = $method === 'GET'
            ? $this->parseQueryParams($uri)
            : ($postData ?? []);

        $response = $this->dispatch($method, $fragments, $params);
        $response->send();
    }

    /**
     * Dispatch a request to the appropriate handler.
     *
     * @param string               $method    HTTP method
     * @param list<string>         $fragments Endpoint path segments
     * @param array<string, mixed> $params    Request parameters
     */
    private function dispatch(string $method, array $fragments, array $params): JsonResponse
    {
        $resource = $fragments[0] ?? '';

        // Handle inline endpoints that don't need a handler
        $inline = $this->handleInlineEndpoints($method, $resource, $fragments, $params);
        if ($inline !== null) {
            return $inline;
        }

        // Look up handler in route map
        $handlerClass = self::HANDLER_MAP[$resource] ?? null;
        if ($handlerClass === null) {
            return Response::error('Endpoint Not Found: ' . $resource, 404);
        }

        /** @var ApiRoutableInterface $handler */
        $handler = $this->container->get($handlerClass);

        return match ($method) {
            'GET'    => $handler->routeGet($fragments, $params),
            'POST'   => $handler->routePost($fragments, $params),
            'PUT'    => $handler->routePut($fragments, $params),
            'DELETE' => $handler->routeDelete($fragments, $params),
            default  => Response::error('Method Not Allowed', 405),
        };
    }

    /**
     * Handle simple inline endpoints that don't warrant a full handler.
     *
     * Also handles cross-cutting endpoints that map to a handler under
     * a different route name than the handler's primary resource.
     *
     * @param string               $method    HTTP method
     * @param string               $resource  Top-level route name
     * @param list<string>         $fragments Endpoint path segments
     * @param array<string, mixed> $params    Request parameters
     *
     * @return JsonResponse|null Response if handled, null to continue to HANDLER_MAP
     */
    private function handleInlineEndpoints(
        string $method,
        string $resource,
        array $fragments,
        array $params
    ): ?JsonResponse {
        if ($method !== 'GET') {
            return null;
        }

        switch ($resource) {
            case 'version':
                return Response::success([
                    // Source from ApplicationInfo (single source of truth) so this
                    // never drifts behind the app version again. Strip the "-fork"
                    // suffix to keep the value bare semver (X.Y.Z) for API clients.
                    "version" => \explode('-', ApplicationInfo::getRawVersion())[0],
                    "release_date" => ApplicationInfo::getReleaseDate()
                ]);

            case 'statuses':
                return Response::success(
                    \Lwt\Modules\Vocabulary\Application\Services\TermStatusService::getStatuses()
                );

            case 'i18n':
                return $this->handleI18nGet($fragments, $params);

            case 'navbar':
                // Per-user navbar chrome data (language list, current language,
                // theme, admin flags). The labels are not included — the client
                // resolves them from the i18n bundle. Protected: the language
                // list is user-scoped.
                return Response::success(PageLayoutHelper::getNavbarData());

            case 'media-files':
                // MediaService::searchMediaPaths recursively scans `media/`
                // and returns every file. In multi-user mode `media/` is not
                // segregated, so any non-admin could enumerate other users'
                // uploaded filenames. Restrict to admins; in single-user
                // mode there is one user (install owner) so the gate is a
                // no-op there.
                if (Globals::isMultiUserEnabled() && !Globals::isCurrentUserAdmin()) {
                    return Response::error('Permission denied: admin only', 403);
                }
                /** @var AdminApiHandler $admin */
                $admin = $this->container->get(AdminApiHandler::class);
                return Response::success($admin->formatMediaFiles());

            case 'phonetic-reading':
                /** @var LanguageApiHandler $lang */
                $lang = $this->container->get(LanguageApiHandler::class);
                /** @var array{text?: string, language_id?: int|string, lang?: string} $params */
                return Response::success($lang->formatPhoneticReading($params));

            case 'sentences-with-term':
                /** @var LanguageApiHandler $lang */
                $lang = $this->container->get(LanguageApiHandler::class);
                return $this->handleSentencesGet($lang, $fragments, $params);

            case 'similar-terms':
                /** @var LanguageApiHandler $lang */
                $lang = $this->container->get(LanguageApiHandler::class);
                return Response::success($lang->formatSimilarTerms(
                    (int) ($params["language_id"] ?? 0),
                    (string) ($params["term"] ?? '')
                ));

            case 'texts':
                // texts/statistics is cross-cutting: routes to AdminApiHandler
                if (($fragments[1] ?? '') === 'statistics') {
                    /** @var AdminApiHandler $admin */
                    $admin = $this->container->get(AdminApiHandler::class);
                    $textIds = isset($params["text_ids"]) ? (string) $params["text_ids"] : '';
                    if ($textIds === '') {
                        return Response::error('Missing required parameter: text_ids', 400);
                    }
                    return Response::success($admin->formatTextsStatistics($textIds));
                }
                return null;

            default:
                return null;
        }
    }

    /**
     * Handle GET /sentences-with-term requests.
     *
     * @param LanguageApiHandler   $lang      Language handler
     * @param list<string>         $fragments Endpoint path segments
     * @param array<string, mixed> $params    Query parameters
     */
    private function handleSentencesGet(
        LanguageApiHandler $lang,
        array $fragments,
        array $params
    ): JsonResponse {
        $languageId = (int) ($params["language_id"] ?? 0);
        $termLc = (string) ($params["term_lc"] ?? '');
        $frag1 = $fragments[1] ?? '';

        // Per-user LgIDs are already unique in practice, so the
        // downstream sentence/word query can't actually return another
        // user's data. But accepting an arbitrary LgID still leaks
        // existence — the response shape differs between "no such
        // language" and "language not yours" — and an explicit guard
        // here keeps the policy verifiable instead of incidental.
        if (!Globals::languageBelongsToCurrentUser($languageId)) {
            return Response::error('Language not found or access denied', 403);
        }

        if ($frag1 !== '' && ctype_digit($frag1)) {
            return Response::success($lang->formatSentencesWithRegisteredTerm(
                $languageId,
                $termLc,
                (int) $frag1
            ));
        }

        return Response::success($lang->formatSentencesWithNewTerm(
            $languageId,
            $termLc,
            array_key_exists("advanced_search", $params)
        ));
    }

    /**
     * Handle GET /i18n[/{locale}] — deliver UI translations to the client.
     *
     * Lets a configurable client fetch and cache per-locale string bundles
     * instead of relying on the server-injected page blob. Locale precedence:
     * path segment, then ?locale=, then the server's active locale. An
     * optional ?namespaces=a,b,c filter limits the payload; default is all.
     *
     * Response: { "locale": "es", "messages": { "common.save": "...", ... } }
     *
     * @param list<string>         $fragments Endpoint path segments
     * @param array<string, mixed> $params    Query parameters
     */
    private function handleI18nGet(array $fragments, array $params): JsonResponse
    {
        /** @var Translator $translator */
        $translator = $this->container->get(Translator::class);

        $locale = $fragments[1] ?? '';
        if ($locale === '') {
            $locale = isset($params['locale']) && $params['locale'] !== ''
                ? (string) $params['locale']
                : $translator->getLocale();
        }

        $namespaces = null;
        if (isset($params['namespaces']) && (string) $params['namespaces'] !== '') {
            $namespaces = array_values(array_filter(
                array_map('trim', explode(',', (string) $params['namespaces'])),
                static fn (string $ns): bool => $ns !== ''
            ));
        }

        $messages = $translator->getAllTranslations($locale, $namespaces);

        return Response::success([
            'locale' => $locale,
            'messages' => $messages,
        ]);
    }

    /**
     * Check if an endpoint is public (does not require authentication).
     *
     * @param string $endpoint The endpoint path
     */
    private function isPublicEndpoint(string $endpoint): bool
    {
        if (isset(self::PUBLIC_ENDPOINTS[$endpoint])) {
            return true;
        }

        if ($endpoint === 'auth/login' || $endpoint === 'auth/register') {
            return true;
        }

        // Public by top-level resource (matches any trailing segments).
        $resource = explode('/', $endpoint, 2)[0];
        if (isset(self::PUBLIC_RESOURCES[$resource])) {
            return true;
        }

        // In non-multi-user mode, all endpoints are effectively public
        if (!Globals::isMultiUserEnabled()) {
            return true;
        }

        return false;
    }

    /**
     * Validate authentication for the current request.
     *
     * @return JsonResponse|null Error response or null if valid
     */
    private function validateAuth(): ?JsonResponse
    {
        if (!Globals::isMultiUserEnabled()) {
            return null;
        }

        /** @var UserApiHandler $authHandler */
        $authHandler = $this->container->get(UserApiHandler::class);
        if (!$authHandler->isAuthenticated()) {
            return Response::error('Authentication required', 401);
        }

        return null;
    }

    /**
     * @return array<string, mixed>
     */
    private function parseQueryParams(string $uri): array
    {
        $query = parse_url($uri, PHP_URL_QUERY);
        if ($query === null || $query === false) {
            return [];
        }
        parse_str($query, $params);
        /** @var array<string, mixed> */
        return $params;
    }

    /**
     * Parse JSON body for PUT/DELETE requests.
     *
     * @return array<string, mixed> Parsed body data
     */
    private static function parseJsonBody(): array
    {
        $input = file_get_contents('php://input');
        if ($input === false || $input === '') {
            return [];
        }
        /** @var array<string, mixed>|null $data */
        $data = json_decode($input, true);
        return is_array($data) ? $data : [];
    }

    /**
     * Static entry point for handling requests.
     */
    public static function handleRequest(): void
    {
        $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

        // Answer an allow-listed cross-origin preflight before the method gate
        // (OPTIONS would otherwise 405), and decorate real responses with CORS
        // headers. Both are no-ops unless CORS_ALLOWED_ORIGINS is configured.
        if (Cors::handlePreflight($method)) {
            return;
        }
        Cors::sendHeaders();

        if (!in_array($method, ['GET', 'POST', 'PUT', 'DELETE'])) {
            Response::error('Method Not Allowed', 405)->send();
            return;
        }

        $bodyData = self::getRequestBody($method);

        $api = new self();
        $api->handle($method, $_SERVER['REQUEST_URI'] ?? '/', $bodyData);
    }

    /**
     * Get request body data based on HTTP method.
     *
     * @param string $method HTTP method
     *
     * @return array<string, mixed>
     */
    private static function getRequestBody(string $method): array
    {
        if ($method === 'POST') {
            if (!empty($_POST)) {
                /** @var array<string, mixed> */
                return $_POST;
            }
            return self::parseJsonBody();
        }

        if (in_array($method, ['PUT', 'DELETE'])) {
            return self::parseJsonBody();
        }

        return [];
    }
}
