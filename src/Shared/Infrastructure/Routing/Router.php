<?php

/**
 * Simple Router for LWT Front Controller
 *
 * PHP version 8.2
 *
 * @category Lwt
 * @package  Lwt\Shared\Infrastructure\Routing
 * @author   HugoFara <git@hugofara.net>
 * @license  Unlicense <http://unlicense.org/>
 * @link     https://hugofara.github.io/lwt/developer/api
 * @since    3.0.0
 */

declare(strict_types=1);

namespace Lwt\Shared\Infrastructure\Routing;

use Lwt\Shared\Infrastructure\Container\Container;
use Lwt\Shared\Infrastructure\Http\SecurityHeaders;
use Lwt\Shared\Infrastructure\Http\UrlUtilities;
use Lwt\Shared\Infrastructure\Http\ResponseInterface;
use Lwt\Shared\Infrastructure\Routing\Middleware\MiddlewareInterface;

/**
 * Simple Router for LWT Front Controller
 *
 * Handles routing URLs to controller-based handlers
 *
 * @category Lwt
 * @package  Lwt\Shared\Infrastructure\Routing
 * @author   HugoFara <git@hugofara.net>
 * @license  Unlicense <http://unlicense.org/>
 * @link     https://hugofara.github.io/lwt/developer/api
 * @since    3.0.0
 */
class Router
{
    /**
     * Registered routes.
     *
     * Structure: ['path' => ['method' => 'ControllerClass::method']]
     *
     * @var array<string, array<string, string>>
     */
    private array $routes = [];

    /**
     * Prefix-based routes.
     *
     * Structure: ['prefix' => ['method' => 'ControllerClass::method']]
     *
     * @var array<string, array<string, string>>
     */
    private array $prefixRoutes = [];

    /**
     * Middleware stack for routes.
     *
     * Structure: ['path' => ['method' => [middleware1, middleware2, ...]]]
     *
     * @var array<string, array<string, array<MiddlewareInterface|string>>>
     */
    private array $middleware = [];

    /**
     * Middleware stack for prefix routes.
     *
     * @var array<string, array<string, array<MiddlewareInterface|string>>>
     */
    private array $prefixMiddleware = [];

    /**
     * Deprecated routes with their successor URL patterns.
     *
     * Structure: ['path' => 'successor URL pattern']
     * e.g., ['/text/read' => '/text/{text}/read']
     *
     * @var array<string, string>
     */
    private array $deprecatedRoutes = [];

    /**
     * Deprecated prefix routes with their successor URL patterns.
     *
     * @var array<string, string>
     */
    private array $deprecatedPrefixes = [];

    /**
     * The dependency injection container.
     *
     * @var Container|null
     */
    private ?Container $container;

    /**
     * Base path for resolving file paths.
     *
     * @var string
     */
    private string $basePath;

    /**
     * Create a new Router instance.
     *
     * @param string         $basePath  Base path for resolving file paths
     * @param Container|null $container Optional DI container for resolving controllers
     */
    public function __construct(string $basePath, ?Container $container = null)
    {
        $this->basePath = $basePath;
        $this->container = $container;
    }

    /**
     * Register a route
     *
     * @param string $path    The URL path
     * @param string $handler The handler (file path or controller@method)
     * @param string $method  HTTP method (GET, POST, or *)
     *
     * @return void
     */
    public function register(string $path, string $handler, string $method = '*'): void
    {
        $this->routes[$path][$method] = $handler;
    }

    /**
     * Register a route with middleware.
     *
     * @param string                              $path       The URL path
     * @param string                              $handler    The handler (file path or controller@method)
     * @param array<MiddlewareInterface|string>   $middleware Array of middleware class names or instances
     * @param string                              $method     HTTP method (GET, POST, or *)
     *
     * @return void
     */
    public function registerWithMiddleware(
        string $path,
        string $handler,
        array $middleware,
        string $method = '*'
    ): void {
        $this->routes[$path][$method] = $handler;
        $this->middleware[$path][$method] = $middleware;
    }

    /**
     * Register a prefix route (matches all paths starting with prefix)
     *
     * @param string $prefix  The URL prefix (e.g., '/api/v1')
     * @param string $handler The handler (file path or method)
     * @param string $method  HTTP method (GET, POST, or *)
     *
     * @return void
     */
    public function registerPrefix(
        string $prefix,
        string $handler,
        string $method = '*'
    ): void {
        $this->prefixRoutes[$prefix][$method] = $handler;
    }

    /**
     * Register a prefix route with middleware.
     *
     * @param string                            $prefix     The URL prefix (e.g., '/api/v1')
     * @param string                            $handler    The handler (file path or method)
     * @param array<MiddlewareInterface|string> $middleware Array of middleware class names or instances
     * @param string                            $method     HTTP method (GET, POST, or *)
     *
     * @return void
     *
     * @psalm-suppress PossiblyUnusedMethod - Public API for route registration
     */
    public function registerPrefixWithMiddleware(
        string $prefix,
        string $handler,
        array $middleware,
        string $method = '*'
    ): void {
        $this->prefixRoutes[$prefix][$method] = $handler;
        $this->prefixMiddleware[$prefix][$method] = $middleware;
    }

    /**
     * Register a GET route.
     *
     * Convenience method for registering GET-only routes.
     *
     * @param string                            $path       The URL path (supports {param} placeholders)
     * @param string                            $handler    The handler (controller@method)
     * @param array<MiddlewareInterface|string> $middleware Optional middleware
     *
     * @return void
     */
    public function get(string $path, string $handler, array $middleware = []): void
    {
        $this->routes[$path]['GET'] = $handler;
        if (!empty($middleware)) {
            $this->middleware[$path]['GET'] = $middleware;
        }
    }

    /**
     * Register a POST route.
     *
     * @param string                            $path       The URL path (supports {param} placeholders)
     * @param string                            $handler    The handler (controller@method)
     * @param array<MiddlewareInterface|string> $middleware Optional middleware
     *
     * @return void
     *
     * @psalm-suppress PossiblyUnusedMethod - Public API for route registration
     */
    public function post(string $path, string $handler, array $middleware = []): void
    {
        $this->routes[$path]['POST'] = $handler;
        if (!empty($middleware)) {
            $this->middleware[$path]['POST'] = $middleware;
        }
    }

    /**
     * Register a PUT route.
     *
     * @param string                            $path       The URL path (supports {param} placeholders)
     * @param string                            $handler    The handler (controller@method)
     * @param array<MiddlewareInterface|string> $middleware Optional middleware
     *
     * @return void
     */
    public function put(string $path, string $handler, array $middleware = []): void
    {
        $this->routes[$path]['PUT'] = $handler;
        if (!empty($middleware)) {
            $this->middleware[$path]['PUT'] = $middleware;
        }
    }

    /**
     * Register a DELETE route.
     *
     * @param string                            $path       The URL path (supports {param} placeholders)
     * @param string                            $handler    The handler (controller@method)
     * @param array<MiddlewareInterface|string> $middleware Optional middleware
     *
     * @return void
     *
     * @psalm-suppress PossiblyUnusedMethod - Public API for route registration
     */
    public function delete(string $path, string $handler, array $middleware = []): void
    {
        $this->routes[$path]['DELETE'] = $handler;
        if (!empty($middleware)) {
            $this->middleware[$path]['DELETE'] = $middleware;
        }
    }

    /**
     * Register a PATCH route.
     *
     * @param string                            $path       The URL path (supports {param} placeholders)
     * @param string                            $handler    The handler (controller@method)
     * @param array<MiddlewareInterface|string> $middleware Optional middleware
     *
     * @return void
     *
     * @psalm-suppress PossiblyUnusedMethod - Public API for route registration
     */
    public function patch(string $path, string $handler, array $middleware = []): void
    {
        $this->routes[$path]['PATCH'] = $handler;
        if (!empty($middleware)) {
            $this->middleware[$path]['PATCH'] = $middleware;
        }
    }

    /**
     * Register routes for multiple HTTP methods.
     *
     * @param array<string>                     $methods    HTTP methods (GET, POST, etc.)
     * @param string                            $path       The URL path
     * @param string                            $handler    The handler
     * @param array<MiddlewareInterface|string> $middleware Optional middleware
     *
     * @return void
     *
     * @psalm-suppress PossiblyUnusedMethod - Public API for route registration
     */
    public function match(
        array $methods,
        string $path,
        string $handler,
        array $middleware = []
    ): void {
        foreach ($methods as $method) {
            $this->routes[$path][strtoupper($method)] = $handler;
            if (!empty($middleware)) {
                $this->middleware[$path][strtoupper($method)] = $middleware;
            }
        }
    }

    /**
     * Mark a route as deprecated.
     *
     * Deprecated routes still work but emit a Deprecation HTTP header
     * and a Link header pointing to the successor URL.
     *
     * @param string $path      The deprecated URL path
     * @param string $successor The preferred replacement URL pattern
     *                          (e.g., '/text/{text}/read')
     *
     * @return void
     */
    public function deprecate(string $path, string $successor): void
    {
        $this->deprecatedRoutes[$path] = $successor;
    }

    /**
     * Mark a prefix route as deprecated.
     *
     * @param string $prefix    The deprecated URL prefix
     * @param string $successor The preferred replacement URL prefix
     *
     * @return void
     */
    public function deprecatePrefix(string $prefix, string $successor): void
    {
        $this->deprecatedPrefixes[$prefix] = $successor;
    }

    /**
     * Resolve the current request to a handler.
     *
     * @return array<string, mixed> Resolution array with type and handler info
     *
     * @psalm-return array{
     *     type: 'handler'|'not_found'|'redirect'|'static',
     *     path?: string,
     *     url?: string,
     *     code?: 301,
     *     handler?: string,
     *     params?: array<array-key, mixed>,
     *     routeParams?: array<string, mixed>,
     *     file?: string,
     *     mime?: string,
     *     middleware?: array<MiddlewareInterface|string>,
     *     deprecated?: true,
     *     successor?: string
     * }
     */
    public function resolve(): array
    {
        $requestUri = $_SERVER['REQUEST_URI'] ?? '/';
        $requestMethod = $_SERVER['REQUEST_METHOD'] ?? 'GET';

        // RFC 7231 §4.3.2: a HEAD response MUST be identical to GET except
        // for the absence of a body. Apache strips the body automatically,
        // but we have to route HEAD to the GET handler ourselves — otherwise
        // every GET-only route 404s on HEAD probes (health checks, link
        // checkers, search crawlers).
        if ($requestMethod === 'HEAD') {
            $requestMethod = 'GET';
        }

        // Parse URL to extract path and query string
        $parsedUrl = parse_url($requestUri);
        $path = $parsedUrl['path'] ?? '/';

        // Remove leading/trailing slashes for consistency
        $path = '/' . trim($path, '/');

        // Strip the application base path for route matching
        // This allows the app to work in subdirectories (e.g., /lwt/)
        $path = UrlUtilities::stripBasePath($path);

        // Check for static assets
        $staticResult = $this->resolveStaticAsset($path);
        if ($staticResult !== null) {
            return $staticResult;
        }

        // Handle /index.php/* paths - strip index.php and route the rest
        // e.g., /index.php/admin/install-demo -> /admin/install-demo
        if (preg_match('/^\/index\.php(\/.*)?$/', $path, $matches)) {
            $pathInfo = $matches[1] ?? '';
            if (!empty($pathInfo)) {
                // Has path info after index.php - redirect to that path
                $queryString = $_SERVER['QUERY_STRING'] ?? '';
                $redirectUrl = $pathInfo . ($queryString ? '?' . $queryString : '');

                return [
                    'type' => 'redirect',
                    'url' => $redirectUrl,
                    'code' => 301  // Permanent redirect
                ];
            }
            // No path info - /index.php alone will be handled by exact match below
        }

        // Try exact match first
        if (isset($this->routes[$path])) {
            $methodRoutes = $this->routes[$path];
            $deprecation = isset($this->deprecatedRoutes[$path])
                ? $this->deprecatedRoutes[$path]
                : null;

            // Check specific method first, then wildcard
            if (isset($methodRoutes[$requestMethod])) {
                $middleware = $this->middleware[$path][$requestMethod] ?? [];
                /** @psalm-suppress InvalidReturnStatement - Dynamic route resolution */
                $result = [
                    'type' => 'handler',
                    'handler' => $methodRoutes[$requestMethod],
                    'params' => $_GET,
                    'middleware' => $middleware,
                ];
                if ($deprecation !== null) {
                    $result['deprecated'] = true;
                    $result['successor'] = $deprecation;
                }
                return $result;
            } elseif (isset($methodRoutes['*'])) {
                $middleware = $this->middleware[$path]['*'] ?? [];
                /** @psalm-suppress InvalidReturnStatement - Dynamic route resolution */
                $result = [
                    'type' => 'handler',
                    'handler' => $methodRoutes['*'],
                    'params' => $_GET,
                    'middleware' => $middleware,
                ];
                if ($deprecation !== null) {
                    $result['deprecated'] = true;
                    $result['successor'] = $deprecation;
                }
                return $result;
            }
        }

        // Try pattern matching for dynamic routes (e.g., /text/{id})
        foreach ($this->routes as $pattern => $methods) {
            // Skip exact patterns (no placeholders)
            if (!str_contains($pattern, '{')) {
                continue;
            }

            $regex = $this->convertPatternToRegex($pattern);
            if (preg_match($regex, $path, $matches)) {
                // Extract only named captures (remove numeric keys and full match)
                /** @var array<string, string> $routeParams */
                $routeParams = array_filter(
                    $matches,
                    fn($key) => is_string($key),
                    ARRAY_FILTER_USE_KEY
                );

                // Coerce types based on route definition
                $routeParams = $this->coerceParams($pattern, $routeParams);

                $methodRoutes = $methods;
                $matchedMethod = isset($methodRoutes[$requestMethod])
                    ? $requestMethod
                    : (isset($methodRoutes['*']) ? '*' : null);
                $handler = $matchedMethod !== null
                    ? $methodRoutes[$matchedMethod]
                    : null;

                if ($handler !== null && $matchedMethod !== null) {
                    $middleware = $this->middleware[$pattern][$matchedMethod] ?? [];
                    /** @psalm-suppress InvalidReturnStatement - Dynamic route resolution */
                    return [
                        'type' => 'handler',
                        'handler' => $handler,
                        'params' => array_merge($_GET, $routeParams),
                        'routeParams' => $routeParams,
                        'middleware' => $middleware,
                    ];
                }
            }
        }

        // Try prefix matching (for API routes that handle multiple sub-paths)
        foreach ($this->prefixRoutes as $prefix => $methods) {
            if (str_starts_with($path, $prefix)) {
                $matchedMethod = isset($methods[$requestMethod])
                    ? $requestMethod
                    : (isset($methods['*']) ? '*' : null);
                $handler = $matchedMethod !== null
                    ? $methods[$matchedMethod]
                    : null;

                if ($handler !== null && $matchedMethod !== null) {
                    $middleware = $this->prefixMiddleware[$prefix][$matchedMethod] ?? [];
                    /** @psalm-suppress InvalidReturnStatement - Dynamic route resolution */
                    $result = [
                        'type' => 'handler',
                        'handler' => $handler,
                        'params' => $_GET,
                        'middleware' => $middleware,
                    ];
                    if (isset($this->deprecatedPrefixes[$prefix])) {
                        $result['deprecated'] = true;
                        $result['successor'] = $this->deprecatedPrefixes[$prefix];
                    }
                    return $result;
                }
            }
        }

        // Not found
        return [
            'type' => 'not_found',
            'path' => $path
        ];
    }

    /**
     * Resolve static asset requests
     *
     * Maps legacy paths to new asset locations:
     * - /css/* -> /dist/css/*
     * - /img/* -> /assets/images/*
     * - /js/* -> /dist/js/*
     * - /assets/* and /dist/* -> direct access
     * - /docs/* -> /docs/* (documentation)
     * - /favicon.ico -> /favicon.ico
     *
     * @param string $path Request path
     *
     * @return array{type: 'static', file: string, mime: string}|null Resolution array or null if not a static asset
     */
    private function resolveStaticAsset(string $path): ?array
    {
        // Path mappings from legacy to new structure
        $mappings = [
            '/css/' => '/dist/css/',
            '/img/' => '/assets/images/',
            '/js/' => '/dist/js/',
            '/sounds/' => '/assets/sounds/',
        ];

        // Check if it's a legacy path that needs mapping
        foreach ($mappings as $oldPrefix => $newPrefix) {
            if (str_starts_with($path, $oldPrefix)) {
                $newPath = $newPrefix . substr($path, strlen($oldPrefix));
                $filePath = $this->basePath . $newPath;

                if (file_exists($filePath) && is_file($filePath)) {
                    return [
                        'type' => 'static',
                        'file' => $filePath,
                        'mime' => $this->getMimeType($filePath)
                    ];
                }
                // Return 404 for non-existent mapped paths
                return null;
            }
        }

        // Direct static asset paths
        $directPaths = ['/assets/', '/dist/', '/docs/', '/media/'];
        foreach ($directPaths as $prefix) {
            if (str_starts_with($path, $prefix)) {
                $filePath = $this->basePath . $path;
                if (file_exists($filePath) && is_file($filePath)) {
                    return [
                        'type' => 'static',
                        'file' => $filePath,
                        'mime' => $this->getMimeType($filePath)
                    ];
                }
                // Return 404 for non-existent direct paths
                return null;
            }
        }

        // Special files at root level
        $rootFiles = ['/favicon.ico', '/UNLICENSE.md'];
        if (in_array($path, $rootFiles)) {
            $filePath = $this->basePath . $path;
            if (file_exists($filePath)) {
                return [
                    'type' => 'static',
                    'file' => $filePath,
                    'mime' => $this->getMimeType($filePath)
                ];
            }
        }

        return null;
    }

    /**
     * Get MIME type for a file
     *
     * @param string $filePath Path to file
     *
     * @return string MIME type
     */
    private function getMimeType(string $filePath): string
    {
        $extension = strtolower(pathinfo($filePath, PATHINFO_EXTENSION));

        $mimeTypes = [
            'css' => 'text/css',
            'js' => 'application/javascript',
            'json' => 'application/json',
            'png' => 'image/png',
            'jpg' => 'image/jpeg',
            'jpeg' => 'image/jpeg',
            'gif' => 'image/gif',
            'svg' => 'image/svg+xml',
            'ico' => 'image/x-icon',
            'woff' => 'font/woff',
            'woff2' => 'font/woff2',
            'ttf' => 'font/ttf',
            'eot' => 'application/vnd.ms-fontobject',
            'mp3' => 'audio/mpeg',
            'wav' => 'audio/wav',
            'ogg' => 'audio/ogg',
            'mp4' => 'video/mp4',
            'webm' => 'video/webm',
            'html' => 'text/html',
            'htm' => 'text/html',
            'txt' => 'text/plain',
            'md' => 'text/markdown',
            'xml' => 'application/xml',
        ];

        return $mimeTypes[$extension] ?? 'application/octet-stream';
    }

    /**
     * Route parameter type constraints.
     *
     * Maps type names to regex patterns.
     *
     * @var array<string, string>
     */
    private const PARAM_TYPES = [
        'int' => '[0-9]+',
        'alpha' => '[a-zA-Z]+',
        'alphanum' => '[a-zA-Z0-9]+',
        'slug' => '[a-zA-Z0-9_-]+',
        'uuid' => '[a-fA-F0-9]{8}-[a-fA-F0-9]{4}-[a-fA-F0-9]{4}-[a-fA-F0-9]{4}-[a-fA-F0-9]{12}',
    ];

    /**
     * Store parameter types for routes.
     *
     * Structure: ['pattern' => ['param_name' => 'type']]
     *
     * @var array<string, array<string, string>>
     */
    private array $routeParamTypes = [];

    /**
     * Convert route pattern to regex.
     *
     * Supports:
     * - Basic params: {id} - matches any non-slash characters
     * - Typed params: {id:int} - matches only digits
     * - Optional params: {id?} or {id:int?} - makes the param optional
     *
     * Available types: int, alpha, alphanum, slug, uuid
     *
     * @param string $pattern Route pattern (e.g., '/text/{id}', '/user/{id:int}')
     *
     * @return non-empty-string Regex pattern
     */
    private function convertPatternToRegex(string $pattern): string
    {
        $originalPattern = $pattern;

        // Escape slashes
        $pattern = str_replace('/', '\/', $pattern);

        // Convert typed optional params: {param:type?}
        $pattern = preg_replace_callback(
            '/\{(\w+):(\w+)\?\}/',
            function (array $matches) use ($originalPattern): string {
                $paramName = $matches[1];
                $type = $matches[2];
                $typePattern = self::PARAM_TYPES[$type] ?? '[^\/]+';

                // Store param type for later coercion
                $this->routeParamTypes[$originalPattern][$paramName] = $type;

                return '(?:(?P<' . $paramName . '>' . $typePattern . '))?';
            },
            $pattern
        ) ?? $pattern;

        // Convert typed params: {param:type}
        $pattern = preg_replace_callback(
            '/\{(\w+):(\w+)\}/',
            function (array $matches) use ($originalPattern): string {
                $paramName = $matches[1];
                $type = $matches[2];
                $typePattern = self::PARAM_TYPES[$type] ?? '[^\/]+';

                // Store param type for later coercion
                $this->routeParamTypes[$originalPattern][$paramName] = $type;

                return '(?P<' . $paramName . '>' . $typePattern . ')';
            },
            $pattern
        ) ?? $pattern;

        // Convert optional params: {param?}
        // Must start with a letter (not a digit) to avoid matching regex quantifiers like {8}
        $pattern = preg_replace(
            '/\{([a-zA-Z]\w*)\?\}/',
            '(?:(?P<$1>[^\/]+))?',
            $pattern
        ) ?? $pattern;

        // Convert basic params: {param}
        // Must start with a letter (not a digit) to avoid matching regex quantifiers like {8}
        $pattern = preg_replace('/\{([a-zA-Z]\w*)\}/', '(?P<$1>[^\/]+)', $pattern) ?? $pattern;

        return '/^' . $pattern . '$/';
    }

    /**
     * Coerce route parameters to their declared types.
     *
     * @param string               $routePattern The original route pattern
     * @param array<string, mixed> $params       The extracted parameters
     *
     * @return array<string, mixed> Parameters with types coerced
     *
     * @psalm-suppress MixedAssignment - Dynamic type coercion by design
     */
    private function coerceParams(string $routePattern, array $params): array
    {
        $types = $this->routeParamTypes[$routePattern] ?? [];

        foreach ($types as $name => $type) {
            if (!isset($params[$name])) {
                continue;
            }

            $params[$name] = match ($type) {
                'int' => (int) $params[$name],
                default => $params[$name],
            };
        }

        return $params;
    }

    /**
     * Execute the resolved handler
     *
     * @param array<string, mixed> $resolution Result from resolve()
     *
     * @return void
     *
     * @psalm-suppress MixedAssignment,MixedArgument,MixedArrayAccess - Dynamic route resolution
     * @psalm-suppress PossiblyUnusedMethod - Called from Application::run()
     */
    public function execute(array $resolution): void
    {
        // Send security headers on all responses
        SecurityHeaders::send();

        switch ($resolution['type']) {
            case 'redirect':
                $code = $resolution['code'];
                header("Location: {$resolution['url']}", true, $code);
                exit;

            case 'static':
                $this->serveStaticFile(
                    $resolution['file'],
                    $resolution['mime']
                );
                break;

            case 'handler':
                // Send deprecation headers for legacy routes
                if (!empty($resolution['deprecated'])) {
                    $this->sendDeprecationHeaders($resolution['successor'] ?? '');
                }

                // Execute middleware chain first
                $middleware = $resolution['middleware'] ?? [];
                if (!$this->executeMiddleware($middleware)) {
                    // Middleware halted the request
                    return;
                }

                $this->executeHandler(
                    $resolution['handler'],
                    $resolution['params'],
                    $resolution['routeParams'] ?? []
                );
                break;

            case 'not_found':
                $this->handle404($resolution['path']);
                // handle404() calls exit() so break is unreachable
                // Fall through to default is intentional as safety net

            default:
                $this->handle500(
                    "Unknown resolution type: {$resolution['type']}"
                );
        }
    }

    /**
     * Execute the middleware chain.
     *
     * @param array<MiddlewareInterface|string> $middlewareList List of middleware class names or instances
     *
     * @return bool True if all middleware passed, false if halted
     */
    private function executeMiddleware(array $middlewareList): bool
    {
        foreach ($middlewareList as $middleware) {
            $instance = $this->resolveMiddleware($middleware);
            if (!$instance->handle()) {
                // Middleware halted the request
                return false;
            }
        }
        return true;
    }

    /**
     * Send HTTP headers indicating that this route is deprecated.
     *
     * Emits a standard Deprecation header (RFC 8594) and a Link header
     * pointing to the successor URL pattern.
     *
     * @param string $successor The preferred replacement URL pattern
     *
     * @return void
     */
    private function sendDeprecationHeaders(string $successor): void
    {
        header('Deprecation: true');
        header('Sunset: 2027-01-01T00:00:00Z');
        if ($successor !== '') {
            header('Link: <' . $successor . '>; rel="successor-version"');
        }
    }

    /**
     * Resolve a middleware to an instance.
     *
     * @param MiddlewareInterface|string $middleware Middleware instance or class name
     *
     * @return MiddlewareInterface The resolved middleware instance
     *
     * @throws \RuntimeException If middleware class not found or invalid
     */
    private function resolveMiddleware(
        MiddlewareInterface|string $middleware
    ): MiddlewareInterface {
        // Already an instance
        if ($middleware instanceof MiddlewareInterface) {
            return $middleware;
        }

        // It's a class name - try to resolve from container or instantiate directly
        if (!class_exists($middleware)) {
            throw new \RuntimeException("Middleware class not found: {$middleware}");
        }

        // Use DI container if available
        if ($this->container !== null && $this->container->has($middleware)) {
            /** @psalm-suppress MixedAssignment - Container returns mixed */
            $instance = $this->container->get($middleware);
        } else {
            /** @psalm-suppress MixedMethodCall - Dynamic instantiation */
            $instance = new $middleware();
        }

        if (!$instance instanceof MiddlewareInterface) {
            throw new \RuntimeException(
                "Middleware must implement MiddlewareInterface: {$middleware}"
            );
        }

        return $instance;
    }

    /**
     * Serve a static file with proper headers
     *
     * @param string $filePath Full path to the file
     * @param string $mimeType MIME type of the file
     *
     * @return void
     */
    private function serveStaticFile(string $filePath, string $mimeType): void
    {
        // Set cache headers for static assets (1 week)
        $maxAge = 604800;
        header('Cache-Control: public, max-age=' . $maxAge);
        header('Expires: ' . gmdate('D, d M Y H:i:s', time() + $maxAge) . ' GMT');
        header('Content-Type: ' . $mimeType);
        $size = filesize($filePath);
        if ($size !== false) {
            header('Content-Length: ' . $size);
        }

        // Send file contents
        readfile($filePath);
        exit;
    }

    /**
     * Execute a handler (file include or controller method)
     *
     * @param string               $handler     Handler string
     * @param array<string, mixed> $params      All request parameters (query + route)
     * @param array<string, mixed> $routeParams Route parameters only (for injection)
     *
     * @return void
     */
    private function executeHandler(
        string $handler,
        array $params,
        array $routeParams = []
    ): void {
        // Check if it's a controller@method format
        if (str_contains($handler, '@')) {
            $parts = explode('@', $handler, 2);
            $controller = $parts[0];
            $method = $parts[1] ?? '';
            $this->executeController($controller, $method, $params, $routeParams);
        } else {
            // It's a file path - include it
            $this->executeFile($handler, $params);
        }
    }

    /**
     * Execute a controller method with parameter injection.
     *
     * Route parameters are injected as method arguments by name.
     * The method can also accept a $params array for all parameters.
     *
     * Examples:
     * - Route: /text/{id:int} -> Method: read(int $id)
     * - Route: /user/{id}/post/{slug} -> Method: show(int $id, string $slug)
     * - Legacy: Method: index(array $params) still works
     *
     * @param string               $controllerClass Controller class name
     * @param string               $method          Method name
     * @param array<string, mixed> $params          All parameters (query + route)
     * @param array<string, mixed> $routeParams     Route parameters for injection
     *
     * @return void
     */
    private function executeController(
        string $controllerClass,
        string $method,
        array $params,
        array $routeParams = []
    ): void {
        // Add namespace if not present
        if (!str_contains($controllerClass, '\\')) {
            $controllerClass = "Lwt\\Controllers\\{$controllerClass}";
        }

        if (!class_exists($controllerClass)) {
            $this->handle500("Controller not found: {$controllerClass}");
        }

        // Resolve controller from DI container if available, otherwise instantiate directly
        $controller = $this->resolveController($controllerClass);

        if (!method_exists($controller, $method)) {
            $this->handle500(
                "Method not found: {$controllerClass}::{$method}"
            );
        }

        // Build method arguments using reflection
        $arguments = $this->buildMethodArguments(
            $controllerClass,
            $method,
            $params,
            $routeParams
        );

        // Call the controller method with resolved arguments
        /** @var mixed $result */
        $result = call_user_func_array([$controller, $method], $arguments);

        // Handle response objects
        if ($result instanceof ResponseInterface) {
            $result->send();
        }
    }

    /**
     * Build method arguments using reflection.
     *
     * Matches route parameters to method parameter names. Falls back to
     * passing the full params array for legacy compatibility.
     *
     * @param string               $controllerClass Controller class name
     * @param string               $method          Method name
     * @param array<string, mixed> $params          All parameters
     * @param array<string, mixed> $routeParams     Route parameters
     *
     * @return array<int, mixed> Ordered arguments for the method
     *
     * @psalm-suppress MixedAssignment - Dynamic argument building via reflection
     */
    private function buildMethodArguments(
        string $controllerClass,
        string $method,
        array $params,
        array $routeParams
    ): array {
        try {
            /** @psalm-suppress ArgumentTypeCoercion - Controller class verified above */
            $reflection = new \ReflectionMethod($controllerClass, $method);
        } catch (\ReflectionException) {
            // Fallback: pass params array as single argument
            return [$params];
        }

        $methodParams = $reflection->getParameters();

        // No parameters - call with no arguments
        if (count($methodParams) === 0) {
            return [];
        }

        // Check if first parameter is an array (legacy style)
        $firstParam = $methodParams[0];
        $firstParamType = $firstParam->getType();
        if (
            $firstParamType instanceof \ReflectionNamedType
            && $firstParamType->getName() === 'array'
            && $firstParam->getName() === 'params'
        ) {
            // Legacy style: method expects (array $params)
            return [$params];
        }

        // Modern style: inject named parameters
        $arguments = [];
        foreach ($methodParams as $param) {
            $name = $param->getName();

            // Try route params first, then all params
            if (array_key_exists($name, $routeParams)) {
                $arguments[] = $routeParams[$name];
            } elseif (array_key_exists($name, $params)) {
                $arguments[] = $params[$name];
            } elseif ($param->isDefaultValueAvailable()) {
                $arguments[] = $param->getDefaultValue();
            } elseif ($param->allowsNull()) {
                $arguments[] = null;
            } else {
                // Required parameter not found - pass params array as fallback
                // This handles the case where controller expects different params
                return [$params];
            }
        }

        return $arguments;
    }

    /**
     * Resolve a controller instance from the container or create directly.
     *
     * @param class-string $controllerClass The fully qualified controller class name
     *
     * @return object The controller instance
     *
     * @psalm-suppress MixedInferredReturnType,MixedReturnStatement - Dynamic instantiation
     */
    private function resolveController(string $controllerClass): object
    {
        // Use DI container if available
        if ($this->container !== null) {
            return $this->container->get($controllerClass);
        }

        // Fallback to direct instantiation
        /** @psalm-suppress MixedMethodCall - Dynamic class instantiation */
        return new $controllerClass();
    }

    /**
     * Execute a legacy file
     *
     * @param string $filePath Path to PHP file or static file
     * @param array  $params   Parameters (available to file)
     *
     * @return void
     */
    private function executeFile(string $filePath, array $params): void
    {
        if (!file_exists($filePath)) {
            $this->handle500("File not found: {$filePath}");
        }

        // Handle static HTML files
        if (str_ends_with($filePath, '.html')) {
            header('Content-Type: text/html; charset=utf-8');
            readfile($filePath);
            return;
        }

        // EXTR_SKIP prevents overwriting existing variables
        extract($params, EXTR_SKIP);
        include $filePath;
    }

    /**
     * Handle 404 Not Found
     *
     * @param string $path Requested path
     *
     * @return never
     */
    private function handle404(string $path)
    {
        http_response_code(404);
        $cssUrl = UrlUtilities::url('/dist/css/standalone.css');
        $homeUrl = UrlUtilities::url('/');
        $title = __('legacy.error_404_title');
        $bodyText = __('legacy.error_404_body', ['path' => '__LWT_PATH__']);
        [$bodyBefore, $bodyAfter] = array_pad(explode('__LWT_PATH__', $bodyText, 2), 2, '');
        $returnHome = __('legacy.return_home');
        ?>
        <!DOCTYPE html>
        <html>
        <head>
            <meta charset="UTF-8">
            <title><?php echo htmlspecialchars($title); ?></title>
            <link rel="stylesheet" href="<?php echo htmlspecialchars($cssUrl); ?>" type="text/css"/>
        </head>
        <body class="error-page">
            <div class="error">
                <h1><?php echo htmlspecialchars($title); ?></h1>
                <p><?php
                    echo htmlspecialchars($bodyBefore);
                ?><code><?php echo htmlspecialchars($path); ?></code><?php
                    echo htmlspecialchars($bodyAfter);
?></p>
                <p><a href="<?php echo htmlspecialchars($homeUrl); ?>">
                    <?php echo htmlspecialchars($returnHome); ?>
                </a></p>
            </div>
        </body>
        </html>
        <?php
        exit;
    }

    /**
     * Handle 500 Internal Server Error
     *
     * @param string $message Error message
     *
     * @return never
     */
    private function handle500(string $message)
    {
        http_response_code(500);
        $cssUrl = UrlUtilities::url('/dist/css/standalone.css');
        $title = __('legacy.error_500_title');
        ?>
        <!DOCTYPE html>
        <html>
        <head>
            <meta charset="UTF-8">
            <title><?php echo htmlspecialchars($title); ?></title>
            <link rel="stylesheet" href="<?php echo htmlspecialchars($cssUrl); ?>" type="text/css"/>
        </head>
        <body class="error-page">
            <div class="error">
                <h1><?php echo htmlspecialchars($title); ?></h1>
                <div class="message"><?php echo htmlspecialchars($message); ?></div>
            </div>
        </body>
        </html>
        <?php
        exit;
    }
}
