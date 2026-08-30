<?php

/**
 * Security Headers
 *
 * Sends HTTP security headers to protect against common web vulnerabilities.
 *
 * PHP version 8.2
 *
 * @category Lwt
 * @package  Lwt\Core\Http
 * @author   HugoFara <git@hugofara.net>
 * @license  Unlicense <http://unlicense.org/>
 * @link     https://hugofara.github.io/lwt/developer/api
 * @since    3.0.0
 */

declare(strict_types=1);

namespace Lwt\Shared\Infrastructure\Http;

use Lwt\Shared\Infrastructure\Bootstrap\EnvLoader;

/**
 * Handles HTTP security headers for the application.
 *
 * Security headers protect against:
 * - XSS attacks (Content-Security-Policy)
 * - Clickjacking (X-Frame-Options)
 * - MIME type sniffing (X-Content-Type-Options)
 * - Protocol downgrade attacks (Strict-Transport-Security)
 *
 * @category Lwt
 * @package  Lwt\Core\Http
 * @author   HugoFara <git@hugofara.net>
 * @license  Unlicense <http://unlicense.org/>
 * @link     https://hugofara.github.io/lwt/developer/api
 * @since    3.0.0
 */
class SecurityHeaders
{
    /**
     * Whether headers have already been sent by this class.
     *
     * @var bool
     */
    private static bool $headersSent = false;

    /**
     * Send all security headers.
     *
     * Safe to call multiple times - headers are only sent once.
     *
     * @return void
     */
    public static function send(): void
    {
        // Prevent sending headers multiple times
        if (self::$headersSent || headers_sent()) {
            return;
        }

        self::sendXFrameOptions();
        self::sendXContentTypeOptions();
        self::sendContentSecurityPolicy();
        self::sendStrictTransportSecurity();
        self::sendReferrerPolicy();
        self::sendPermissionsPolicy();

        self::$headersSent = true;
    }

    /**
     * Send X-Frame-Options header.
     *
     * Prevents the page from being embedded in iframes on other sites,
     * protecting against clickjacking attacks.
     *
     * @return void
     */
    public static function sendXFrameOptions(): void
    {
        header('X-Frame-Options: SAMEORIGIN');
    }

    /**
     * Send X-Content-Type-Options header.
     *
     * Prevents browsers from MIME-type sniffing, which could allow
     * attackers to execute code by uploading files with misleading extensions.
     *
     * @return void
     */
    public static function sendXContentTypeOptions(): void
    {
        header('X-Content-Type-Options: nosniff');
    }

    /**
     * Send Content-Security-Policy header.
     *
     * Restricts which resources can be loaded, providing strong XSS protection.
     *
     * Current policy:
     * - Scripts: self only (no inline scripts - all JS in external files)
     * - Styles: self + unsafe-inline (needed for inline styles and dynamic styling)
     * - Images: self + data: (for inline images) + blob: (for generated content)
     * - Fonts: self
     * - Connect: self + api.github.com (for release checks)
     * - Media: configurable via CSP_MEDIA_SOURCES env var (default: self + blob)
     * - Frame ancestors: self (alternative to X-Frame-Options)
     * - Upgrade insecure requests: only over HTTPS, to auto-upgrade any
     *   http:// subresource (e.g. behind a TLS-terminating reverse proxy)
     *
     * @return void
     */
    public static function sendContentSecurityPolicy(): void
    {
        header('Content-Security-Policy: ' . self::buildContentSecurityPolicy());
    }

    /**
     * Build the Content-Security-Policy header value.
     *
     * @return string The complete policy string (directives joined by '; ')
     */
    private static function buildContentSecurityPolicy(): string
    {
        $directives = [
            "default-src 'self'",
            // Scripts: self only (Alpine.js uses CSP-compatible build, no inline JS)
            "script-src 'self'",
            // Styles: self + inline (needed for dynamic styling in views)
            "style-src 'self' 'unsafe-inline'",
            // Images: self + data URIs + blob for generated content;
            // content.digitallibrary.io serves Global Digital Library book
            // cover thumbnails shown in the "Kids' Library" text source.
            "img-src 'self' data: blob: https://content.digitallibrary.io",
            // Fonts: self only
            "font-src 'self'",
            // AJAX/fetch requests: self + GitHub API for release checks
            "connect-src 'self' https://api.github.com",
            // External scripts: Glosbe JSONP translation API
            "script-src-elem 'self' https://glosbe.com",
            // Audio/video: configurable, defaults to self + blob for TTS
            self::buildMediaSrcDirective(),
            // Frames: block all embedding (clickjacking protection)
            "frame-ancestors 'self'",
            // Form submissions: self only
            "form-action 'self'",
            // Base URI: self only (prevents base tag injection)
            "base-uri 'self'",
        ];

        // When the page is served over HTTPS, transparently upgrade any
        // http:// subresource to https:// instead of letting the browser
        // block it as mixed content. This shields installs behind a
        // TLS-terminating reverse proxy that doesn't propagate the scheme
        // (see issue #240). Only emitted on secure connections so plain-HTTP
        // dev (e.g. http://localhost:8000) isn't forced to upgrade and break.
        if (self::isSecureConnection()) {
            $directives[] = 'upgrade-insecure-requests';
        }

        return implode('; ', $directives);
    }

    /**
     * Build the media-src CSP directive based on configuration.
     *
     * Reads CSP_MEDIA_SOURCES from environment:
     * - "self" (default): Only allow media from same origin
     * - "https": Allow any HTTPS source
     * - Comma-separated domains: Allow specific domains
     *
     * Always includes 'self' and 'blob:' for local files and TTS.
     *
     * @return string The complete media-src directive
     */
    private static function buildMediaSrcDirective(): string
    {
        $config = EnvLoader::get('CSP_MEDIA_SOURCES', 'self');
        $sources = ["'self'", "blob:"];

        if ($config === null || $config === 'self') {
            // Default: only self and blob
            return "media-src " . implode(' ', $sources);
        }

        if ($config === 'https') {
            // Allow any HTTPS source
            $sources[] = "https:";
            return "media-src " . implode(' ', $sources);
        }

        // Parse comma-separated list of domains
        $domains = array_map('trim', explode(',', $config));
        foreach ($domains as $domain) {
            if ($domain !== '' && self::isValidCspSource($domain)) {
                $sources[] = $domain;
            }
        }

        return "media-src " . implode(' ', $sources);
    }

    /**
     * Validate a CSP source value.
     *
     * Accepts:
     * - https://domain.com or https://domain.com:port
     * - http://domain.com (allowed but not recommended)
     * - *.domain.com wildcards
     *
     * @param string $source The source to validate
     *
     * @return bool True if valid CSP source
     */
    private static function isValidCspSource(string $source): bool
    {
        // Must start with http:// or https:// or be a wildcard pattern
        if (preg_match('#^https?://[a-zA-Z0-9][-a-zA-Z0-9.]*[a-zA-Z0-9](:\d+)?/?$#', $source)) {
            return true;
        }

        // Allow wildcard subdomains like *.example.com
        if (preg_match('#^\*\.[a-zA-Z0-9][-a-zA-Z0-9.]*[a-zA-Z0-9]$#', $source)) {
            return true;
        }

        return false;
    }

    /**
     * Send Strict-Transport-Security header.
     *
     * Tells browsers to always use HTTPS for this domain.
     * Only sent when the current connection is already HTTPS.
     *
     * @return void
     */
    public static function sendStrictTransportSecurity(): void
    {
        // Only send HSTS header over HTTPS connections
        if (!self::isSecureConnection()) {
            return;
        }

        // max-age: 1 year in seconds
        // includeSubDomains: apply to all subdomains
        header('Strict-Transport-Security: max-age=31536000; includeSubDomains');
    }

    /**
     * Send Referrer-Policy header.
     *
     * Controls how much referrer information is sent with requests.
     * 'strict-origin-when-cross-origin' sends:
     * - Full URL for same-origin requests
     * - Origin only for cross-origin HTTPS→HTTPS
     * - Nothing for HTTPS→HTTP (prevents leaking URLs to insecure sites)
     *
     * @return void
     */
    public static function sendReferrerPolicy(): void
    {
        header('Referrer-Policy: strict-origin-when-cross-origin');
    }

    /**
     * Send Permissions-Policy header.
     *
     * Restricts which browser features can be used.
     * Disables features not needed by the application.
     *
     * @return void
     */
    public static function sendPermissionsPolicy(): void
    {
        $policy = implode(', ', [
            'camera=()',           // Disable camera access
            'microphone=()',       // Disable microphone access
            'geolocation=()',      // Disable location access
            'payment=()',          // Disable payment APIs
            'usb=()',              // Disable USB access
        ]);

        header("Permissions-Policy: {$policy}");
    }

    /**
     * Check if the current connection is secure (HTTPS).
     *
     * Delegates to `UrlUtilities::isSecureRequest()`; that helper is the
     * single source of truth for HTTPS detection in LWT and handles the
     * `TRUST_PROXY` opt-out for `X-Forwarded-Proto`.
     *
     * @return bool True if connection is over HTTPS
     */
    public static function isSecureConnection(): bool
    {
        return UrlUtilities::isSecureRequest();
    }

    /**
     * Reset the headers sent flag (mainly for testing).
     *
     * @return void
     */
    public static function reset(): void
    {
        self::$headersSent = false;
    }
}
