<?php

/**
 * \file
 * \brief URL handling utilities.
 *
 * Functions for parsing and manipulating URLs, including dictionary URL parsing.
 *
 * PHP version 8.2
 *
 * @category Lwt
 * @package  Lwt
 * @author   HugoFara <git@hugofara.net>
 * @license  Unlicense <http://unlicense.org/>
 * @link     https://hugofara.github.io/lwt/developer/api
 * @since    3.0.0 Split from kernel_utility.php
 */

declare(strict_types=1);

namespace Lwt\Shared\Infrastructure\Http;

/**
 * URL handling utilities.
 *
 * Provides methods for parsing and manipulating URLs, including dictionary URL parsing.
 *
 * @category Lwt
 * @package  Lwt
 * @author   HugoFara <git@hugofara.net>
 * @license  Unlicense <http://unlicense.org/>
 * @link     https://hugofara.github.io/lwt/developer/api
 * @since    3.0.0
 */
class UrlUtilities
{
    /**
     * Cached base path value.
     *
     * @var string|null
     */
    private static ?string $basePath = null;

    /**
     * Cached app URL value.
     *
     * @var string|null|false null = not yet resolved, false = no env var set
     */
    private static string|null|false $appUrl = null;

    /**
     * Cached `TRUST_PROXY` resolution. null = not yet resolved.
     *
     * @var bool|null
     */
    private static ?bool $trustProxy = null;

    /**
     * Get the configured application base path.
     *
     * Returns the APP_BASE_PATH environment variable value, normalized
     * to ensure it starts with / and has no trailing slash.
     *
     * @return string The base path (e.g., '/lwt') or empty string for root
     */
    public static function getBasePath(): string
    {
        if (self::$basePath === null) {
            $envPath = $_ENV['APP_BASE_PATH'] ?? null;
            if ($envPath === null) {
                $envPath = getenv('APP_BASE_PATH');
            }
            $path = is_string($envPath) && $envPath !== '' ? $envPath : '';

            // Normalize: ensure starts with / (if not empty) and no trailing slash
            if ($path !== '') {
                $path = '/' . trim($path, '/');
            }

            self::$basePath = $path;
        }

        return self::$basePath;
    }

    /**
     * Get the configured application URL (scheme + host).
     *
     * Returns the APP_URL environment variable if set (e.g., 'https://example.com').
     * This should be used instead of $_SERVER['HTTP_HOST'] to prevent
     * Host Header Injection attacks.
     *
     * @return string|null The app URL, or null if not configured
     */
    public static function getAppUrl(): ?string
    {
        if (self::$appUrl === null) {
            $envUrl = $_ENV['APP_URL'] ?? null;
            if ($envUrl === null) {
                $envUrl = getenv('APP_URL');
            }
            if (is_string($envUrl) && $envUrl !== '') {
                // Normalize: remove trailing slash
                self::$appUrl = rtrim($envUrl, '/');
            } else {
                self::$appUrl = false;
            }
        }

        return self::$appUrl === false ? null : self::$appUrl;
    }

    /**
     * Get the application origin (scheme + host), preferring APP_URL env var.
     *
     * Falls back to detecting from $_SERVER if APP_URL is not configured.
     * The fallback honors `X-Forwarded-Proto` and `X-Forwarded-Host` so
     * the app generates correct https URLs when deployed behind a TLS-
     * terminating reverse proxy (Traefik, Caddy, nginx, Cloudflare, ...).
     * Set `TRUST_PROXY=false` to suppress this for direct-internet-facing
     * deployments that aren't shielded by a trusted proxy.
     *
     * @return string The application origin (e.g., 'https://example.com')
     */
    public static function getAppOrigin(): string
    {
        $appUrl = self::getAppUrl();
        if ($appUrl !== null) {
            return $appUrl;
        }

        $protocol = self::isSecureRequest() ? 'https' : 'http';
        return "{$protocol}://" . self::getRequestHost();
    }

    /**
     * Whether the current request reached LWT over a secure transport.
     *
     * Recognises four signals: direct HTTPS (`$_SERVER['HTTPS']` set and
     * not 'off'), the standard HTTPS port, and — when the proxy is
     * trusted — `X-Forwarded-Proto: https` or `X-Forwarded-Ssl: on`.
     * The X-Forwarded-* headers can be spoofed by anyone able to talk
     * directly to LWT, so the install must opt out via `TRUST_PROXY=false`
     * if it isn't shielded by a proxy that strips them.
     *
     * @return bool True if the request is HTTPS (directly or via a
     *              trusted proxy that terminated TLS).
     */
    public static function isSecureRequest(): bool
    {
        if (
            isset($_SERVER['HTTPS'])
            && $_SERVER['HTTPS'] !== ''
            && $_SERVER['HTTPS'] !== 'off'
        ) {
            return true;
        }
        if (isset($_SERVER['SERVER_PORT']) && (int) $_SERVER['SERVER_PORT'] === 443) {
            return true;
        }
        if (self::trustsProxy()) {
            $proto = $_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '';
            if ($proto !== '') {
                $parts = explode(',', $proto);
                if (strtolower(trim($parts[0])) === 'https') {
                    return true;
                }
            }
            $ssl = $_SERVER['HTTP_X_FORWARDED_SSL'] ?? '';
            if (strtolower($ssl) === 'on') {
                return true;
            }
        }
        return false;
    }

    /**
     * Get the host of the current request, honouring `X-Forwarded-Host`
     * when the proxy is trusted.
     *
     * Validates against alphanumeric / dot / hyphen / colon / bracket
     * to mitigate Host-Header-Injection; falls back to `localhost` on
     * unparseable input.
     *
     * @return string The validated host (may include `:port`)
     */
    public static function getRequestHost(): string
    {
        if (self::trustsProxy()) {
            $fwdHost = $_SERVER['HTTP_X_FORWARDED_HOST'] ?? '';
            if ($fwdHost !== '') {
                $parts = explode(',', $fwdHost);
                $host = trim($parts[0]);
                if ($host !== '' && preg_match('/^[a-zA-Z0-9.\-:\[\]]+$/', $host)) {
                    return $host;
                }
            }
        }
        $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
        if (!preg_match('/^[a-zA-Z0-9.\-:\[\]]+$/', $host)) {
            return 'localhost';
        }
        return $host;
    }

    /**
     * Whether to honour `X-Forwarded-*` headers from the front-end proxy.
     *
     * Default: true (matches the historical behaviour of LWT's cookie
     * and HSTS code, which has always trusted `X-Forwarded-Proto`).
     * Operators running LWT directly on the public internet should set
     * `TRUST_PROXY=false` to prevent header-spoofing attacks.
     *
     * @return bool True to trust forwarded headers, false to ignore them
     */
    public static function trustsProxy(): bool
    {
        if (self::$trustProxy === null) {
            $env = $_ENV['TRUST_PROXY'] ?? null;
            if ($env === null) {
                $env = getenv('TRUST_PROXY');
            }
            if (is_string($env) && $env !== '') {
                $parsed = filter_var($env, FILTER_VALIDATE_BOOL, FILTER_NULL_ON_FAILURE);
                self::$trustProxy = $parsed === null ? true : $parsed;
            } else {
                self::$trustProxy = true;
            }
        }
        return self::$trustProxy;
    }

    /**
     * Generate a URL with the application base path prepended.
     *
     * Use this for all internal links to ensure they work correctly
     * when the application is installed in a subdirectory.
     *
     * @param string $path The path to generate URL for (must start with /)
     *
     * @return string The full URL path with base path prepended
     *
     * @example url('/login') returns '/lwt/login' if APP_BASE_PATH=/lwt
     * @example url('/assets/css/main.css') returns '/lwt/assets/css/main.css'
     */
    public static function url(string $path): string
    {
        $basePath = self::getBasePath();

        // Ensure path starts with /
        if ($path !== '' && $path[0] !== '/') {
            $path = '/' . $path;
        }

        // Avoid double slashes
        if ($basePath !== '' && $path === '/') {
            return $basePath;
        }

        return $basePath . $path;
    }

    /**
     * Strip the base path from a request URI for route matching.
     *
     * This is used by the Router to normalize incoming requests so that
     * routes like '/' work regardless of the configured APP_BASE_PATH.
     *
     * @param string $requestUri The full request URI
     *
     * @return string The path with base path stripped
     *
     * @example stripBasePath('/lwt/login') returns '/login' if APP_BASE_PATH=/lwt
     * @example stripBasePath('/lwt') returns '/' if APP_BASE_PATH=/lwt
     */
    public static function stripBasePath(string $requestUri): string
    {
        $basePath = self::getBasePath();

        if ($basePath === '') {
            return $requestUri;
        }

        // Check if request starts with base path
        if (str_starts_with($requestUri, $basePath)) {
            $stripped = substr($requestUri, strlen($basePath));
            // Ensure we return at least '/'
            return $stripped === '' ? '/' : $stripped;
        }

        // Request doesn't match base path - return as-is
        // This handles cases like /favicon.ico at the actual root
        return $requestUri;
    }

    /**
     * Reset the cached base path.
     *
     * Useful for testing or when environment changes dynamically.
     *
     * @return void
     */
    public static function resetBasePath(): void
    {
        self::$basePath = null;
        self::$appUrl = null;
        self::$trustProxy = null;
    }

    /**
     * Validate that a URL is safe to fetch (not pointing to internal/private IPs).
     *
     * Prevents SSRF attacks by blocking requests to:
     * - Private IP ranges (10.x, 172.16-31.x, 192.168.x)
     * - Loopback addresses (127.x, ::1)
     * - Link-local addresses (169.254.x, fe80::)
     * - Reserved/special addresses
     * - Non-HTTP(S) schemes
     *
     * @param string $url URL to validate
     *
     * @return array{valid: bool, error?: string, resolved_ip?: string}
     */
    public static function validateUrlForFetch(string $url): array
    {
        $url = trim($url);

        // Validate URL structure
        if ($url === '') {
            return ['valid' => false, 'error' => 'Empty URL'];
        }

        $parsed = parse_url($url);
        if ($parsed === false) {
            return ['valid' => false, 'error' => 'Invalid URL format'];
        }

        // Only allow HTTP and HTTPS schemes (check before host requirement)
        $scheme = strtolower($parsed['scheme'] ?? '');
        if ($scheme !== 'http' && $scheme !== 'https') {
            return ['valid' => false, 'error' => 'Only HTTP and HTTPS URLs are allowed'];
        }

        // Host is required for HTTP(S) URLs
        if (!isset($parsed['host']) || $parsed['host'] === '') {
            return ['valid' => false, 'error' => 'Invalid URL format'];
        }

        $host = $parsed['host'];

        // parse_url leaves brackets on IPv6 literals (e.g. "[::1]"). Strip them
        // so downstream IP-literal detection and filter_var IP checks work —
        // without this, "[::1]" falls through to DNS and only "passes" SSRF
        // validation because the lookup eventually times out.
        if (strlen($host) >= 2 && $host[0] === '[' && $host[-1] === ']') {
            $host = substr($host, 1, -1);
        }

        // Block common internal hostnames
        $lowerHost = strtolower($host);
        $blockedHostnames = [
            'localhost',
            'localhost.localdomain',
            'ip6-localhost',
            'ip6-loopback',
        ];
        if (in_array($lowerHost, $blockedHostnames, true)) {
            return ['valid' => false, 'error' => 'Internal hostnames are not allowed'];
        }

        // Block .local and .internal TLDs
        if (
            str_ends_with($lowerHost, '.local') ||
            str_ends_with($lowerHost, '.internal') ||
            str_ends_with($lowerHost, '.localhost')
        ) {
            return ['valid' => false, 'error' => 'Internal domain suffixes are not allowed'];
        }

        // Resolve hostname to IP addresses
        $ips = self::resolveHostToIps($host);
        if ($ips === []) {
            return ['valid' => false, 'error' => 'Could not resolve hostname'];
        }

        // Check each resolved IP
        foreach ($ips as $ip) {
            if (!self::isPublicIp($ip)) {
                return [
                    'valid' => false,
                    'error' => 'URL resolves to private/reserved IP address',
                    'resolved_ip' => $ip
                ];
            }
        }

        return ['valid' => true, 'resolved_ip' => $ips[0]];
    }

    /**
     * Resolve a hostname to its IP addresses.
     *
     * @param string $host Hostname to resolve
     *
     * @return array<string> List of IP addresses
     */
    private static function resolveHostToIps(string $host): array
    {
        // If it's already an IP address, return it directly
        if (filter_var($host, FILTER_VALIDATE_IP) !== false) {
            return [$host];
        }

        // Resolve DNS
        $records = @dns_get_record($host, DNS_A | DNS_AAAA);
        if ($records === false || $records === []) {
            // Fallback to gethostbyname for simple A record lookup
            $ip = @gethostbyname($host);
            if ($ip !== $host && filter_var($ip, FILTER_VALIDATE_IP) !== false) {
                return [$ip];
            }
            return [];
        }

        $ips = [];
        foreach ($records as $record) {
            if (isset($record['ip']) && is_string($record['ip'])) {
                $ips[] = $record['ip'];
            }
            if (isset($record['ipv6']) && is_string($record['ipv6'])) {
                $ips[] = $record['ipv6'];
            }
        }

        return $ips;
    }

    /**
     * Check if an IP address is a public (non-private, non-reserved) address.
     *
     * @param string $ip IP address to check
     *
     * @return bool True if the IP is public and safe to access
     */
    private static function isPublicIp(string $ip): bool
    {
        // IPv4-mapped IPv6 addresses (::ffff:x.x.x.x) are flagged as reserved
        // by PHP's FILTER_FLAG_NO_RES_RANGE, but they're just IPv4 addresses
        // in IPv6 notation. Extract and validate the IPv4 part instead.
        if (str_starts_with($ip, '::ffff:') && substr_count($ip, ':') <= 4) {
            $ipv4Part = substr($ip, 7);
            // The IPv4 part may be in hex notation (e.g. 9813:862f) or dotted decimal
            if (filter_var($ipv4Part, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false) {
                return self::isPublicIp($ipv4Part);
            }
            // Hex-encoded IPv4 (e.g. ::ffff:9813:862f → 152.19.134.47)
            $hexParts = explode(':', $ipv4Part);
            if (count($hexParts) === 2) {
                $hi = (int) hexdec($hexParts[0]);
                $lo = (int) hexdec($hexParts[1]);
                $decoded = (($hi >> 8) & 0xFF) . '.' . ($hi & 0xFF) . '.' . (($lo >> 8) & 0xFF) . '.' . ($lo & 0xFF);
                if (filter_var($decoded, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false) {
                    return self::isPublicIp($decoded);
                }
            }
        }

        // Use PHP's built-in filters to check for private and reserved ranges
        // FILTER_FLAG_NO_PRIV_RANGE blocks: 10.0.0.0/8, 172.16.0.0/12, 192.168.0.0/16, fc00::/7
        // FILTER_FLAG_NO_RES_RANGE blocks: 0.0.0.0/8, 169.254.0.0/16, 127.0.0.0/8, 240.0.0.0/4,
        //                                  ::1, ::/128, ::ffff:0:0/96, fe80::/10
        $result = filter_var(
            $ip,
            FILTER_VALIDATE_IP,
            FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE
        );

        if ($result === false) {
            return false;
        }

        // Additional check for multicast addresses (224.0.0.0/4) which PHP filter doesn't block
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false) {
            $ipLong = ip2long($ip);
            // Multicast range: 224.0.0.0 - 239.255.255.255 (224.0.0.0/4)
            if ($ipLong >= ip2long('224.0.0.0') && $ipLong <= ip2long('239.255.255.255')) {
                return false;
            }
        }

        return true;
    }

    /**
     * Safely fetch an HTTP/HTTPS URL with SSRF protection.
     *
     * Unlike `file_get_contents` with `follow_location => true`, this
     * routes every redirect hop through `validateUrlForFetch()` — so
     * an attacker-controlled public host cannot 302 the request into
     * `127.0.0.1:8080`, `169.254.169.254`, or any private range. The
     * response body is capped at `maxBytes`, the wall-clock at
     * `timeout`, and the redirect chain at `maxRedirects`.
     *
     * Residual: validation does its own DNS resolve, then the fetch
     * does another — a TTL-0 rebinding attacker who wins the race
     * could still hit a private IP. Mitigating that fully needs
     * connecting by IP literal with an explicit Host header, which
     * is more invasive than this helper provides; the per-hop
     * revalidation here narrows the window to a single resolve per
     * hop instead of an open-ended redirect chain.
     *
     * @param string $url The URL to fetch
     * @param array{
     *     timeout?: int,
     *     maxBytes?: int,
     *     maxRedirects?: int,
     *     accept?: string,
     *     userAgent?: string
     * } $opts Override defaults (timeout=15s, maxBytes=2MB,
     *         maxRedirects=5, accept='*‍/*', UA='LWT/3.0').
     *
     * @return string|null Response body, or null on any failure
     *                     (invalid URL, network error, non-2xx,
     *                     size/redirect cap exceeded, or a redirect
     *                     into a blocked range).
     */
    public static function safeHttpGet(string $url, array $opts = []): ?string
    {
        $timeout = $opts['timeout'] ?? 15;
        $maxBytes = $opts['maxBytes'] ?? 2 * 1024 * 1024;
        $maxRedirects = $opts['maxRedirects'] ?? 5;
        $userAgent = $opts['userAgent'] ?? 'LWT/3.0 (Learning with Texts)';
        $accept = $opts['accept'] ?? '*/*';

        $current = trim($url);
        for ($hop = 0; $hop <= $maxRedirects; $hop++) {
            $validation = self::validateUrlForFetch($current);
            if (!$validation['valid']) {
                return null;
            }

            $context = stream_context_create([
                'http' => [
                    'follow_location' => 0,
                    'timeout' => $timeout,
                    'user_agent' => $userAgent,
                    'header' => "Accept: $accept\r\n",
                    'ignore_errors' => true,
                ],
                'ssl' => [
                    'verify_peer' => true,
                    'verify_peer_name' => true,
                ],
            ]);

            $body = @file_get_contents($current, false, $context, 0, $maxBytes);
            if ($body === false) {
                return null;
            }

            /** @var list<string>|null $http_response_header */
            $headers = $http_response_header ?? null;
            if ($headers === null || $headers === []) {
                return null;
            }

            $status = 0;
            if (preg_match('#^HTTP/[\d.]+\s+(\d+)#', $headers[0], $m)) {
                $status = (int) $m[1];
            }

            if ($status >= 200 && $status < 300) {
                return $body;
            }

            if ($status < 300 || $status >= 400) {
                return null;
            }

            $location = null;
            foreach ($headers as $header) {
                if (preg_match('#^Location:\s*(.+?)\s*$#i', $header, $m)) {
                    $location = $m[1];
                }
            }
            if ($location === null || $location === '') {
                return null;
            }

            $current = self::resolveRelativeUrl($current, $location);
        }

        return null;
    }

    /**
     * Resolve a (possibly relative) URL against a base URL.
     *
     * Handles three Location header shapes that real servers emit:
     * absolute (`https://other.com/x`), protocol-relative (`//cdn/x`),
     * and absolute-path (`/x`). Anything else is treated as a path
     * fragment relative to the base URL's directory.
     *
     * @param string $base     Base URL (the URL of the page that
     *                         returned the redirect).
     * @param string $relative The Location header value.
     *
     * @return string Fully qualified URL.
     */
    private static function resolveRelativeUrl(string $base, string $relative): string
    {
        if (preg_match('#^https?://#i', $relative) === 1) {
            return $relative;
        }

        $parts = parse_url($base);
        if ($parts === false || !isset($parts['scheme'], $parts['host'])) {
            return $relative;
        }

        $scheme = $parts['scheme'];
        $host = $parts['host'];
        $port = isset($parts['port']) ? ':' . $parts['port'] : '';
        $origin = "$scheme://$host$port";

        if (str_starts_with($relative, '//')) {
            return "$scheme:$relative";
        }

        if (str_starts_with($relative, '/')) {
            return $origin . $relative;
        }

        $basePath = $parts['path'] ?? '/';
        $lastSlash = strrpos($basePath, '/');
        $baseDir = $lastSlash === false ? '/' : substr($basePath, 0, $lastSlash + 1);
        if ($baseDir === '') {
            $baseDir = '/';
        }
        return $origin . $baseDir . $relative;
    }

    /**
     * Get the base URL of the application
     *
     * Honours `X-Forwarded-Proto` / `X-Forwarded-Host` when the proxy
     * is trusted (see `isSecureRequest()` / `getRequestHost()`).
     *
     * @return string base URL
     */
    public static function urlBase(): string
    {
        $scheme = self::isSecureRequest() ? 'https' : 'http';
        $host = self::getRequestHost();

        $url = parse_url("$scheme://" . $host . ($_SERVER['REQUEST_URI'] ?? '/'));
        $r = ($url["scheme"] ?? $scheme) . "://" . ($url["host"] ?? 'localhost');
        if (isset($url["port"])) {
            $r .= ":" . $url["port"];
        }
        if (isset($url["path"])) {
            $b = basename($url["path"]);
            if (substr($b, -4) == ".php" || substr($b, -4) == ".htm" || substr($b, -5) == ".html") {
                $r .= dirname($url["path"]);
            } else {
                $r .= $url["path"];
            }
        }
        if (substr($r, -1) !== "/") {
            $r .= "/";
        }
        return $r;
    }

    /**
     * Build a URL with query parameters.
     *
     * Constructs a URL by combining a path with query parameters.
     * Empty/null parameter values are filtered out.
     *
     * @param string               $path   The URL path (will have base path prepended)
     * @param array<string, mixed> $params Query parameters to append
     *
     * @return string The complete URL with query string
     *
     * @example buildUrl('/tags', ['page' => 2, 'query' => 'test']) returns '/tags?page=2&query=test'
     * @example buildUrl('/tags', ['page' => 1, 'query' => '']) returns '/tags?page=1'
     */
    public static function buildUrl(string $path, array $params = []): string
    {
        $url = self::url($path);

        if (empty($params)) {
            return $url;
        }

        // Filter out empty/null values but keep '0' and false
        $filtered = array_filter(
            $params,
            fn($v) => $v !== '' && $v !== null
        );

        if (empty($filtered)) {
            return $url;
        }

        return $url . '?' . http_build_query($filtered);
    }

    /**
     * Get a two-letter language code from dictionary source language.
     *
     * @param string $url Input URL, usually Google Translate or LibreTranslate
     *
     * @return string The source language code or empty string
     */
    public static function langFromDict(string $url): string
    {
        if ($url == '') {
            return '';
        }
        $query = parse_url($url, PHP_URL_QUERY);
        if ($query === null || $query === false) {
            return '';
        }
        parse_str($query, $parsed_query);
        /** @var array<string, string|list<string>> $parsed_query */
        if (
            array_key_exists("lwt_translator", $parsed_query)
            && $parsed_query["lwt_translator"] == "libretranslate"
        ) {
            $source = $parsed_query["source"] ?? "";
            return is_string($source) ? $source : "";
        }
        // Fallback to Google Translate
        $sl = $parsed_query["sl"] ?? "";
        return is_string($sl) ? $sl : "";
    }

    /**
     * Get a two-letter language code from dictionary target language
     *
     * @param string $url Input URL, usually Google Translate or LibreTranslate
     *
     * @return string The target language code or empty string
     */
    public static function targetLangFromDict(string $url): string
    {
        if ($url == '') {
            return '';
        }
        $query = parse_url($url, PHP_URL_QUERY);
        if ($query === null || $query === false) {
            return '';
        }
        parse_str($query, $parsed_query);
        /** @var array<string, string|list<string>> $parsed_query */
        if (
            array_key_exists("lwt_translator", $parsed_query)
            && $parsed_query["lwt_translator"] == "libretranslate"
        ) {
            $target = $parsed_query["target"] ?? "";
            return is_string($target) ? $target : "";
        }
        // Fallback to Google Translate
        $tl = $parsed_query["tl"] ?? "";
        return is_string($tl) ? $tl : "";
    }
}
