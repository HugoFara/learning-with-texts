<?php

/**
 * CSRF Protection Middleware
 *
 * Validates CSRF tokens on state-changing requests (POST, PUT, DELETE, PATCH).
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

use Lwt\Shared\Infrastructure\Http\Cors;

/**
 * Middleware that validates CSRF tokens.
 *
 * Requires valid CSRF token for POST, PUT, DELETE, and PATCH requests.
 * Token must be provided via:
 * - Form field: _csrf_token
 * - Header: X-CSRF-TOKEN
 *
 * GET and OPTIONS requests are exempt.
 * API requests with Bearer tokens are exempt (API tokens serve as CSRF protection).
 *
 * @category Lwt
 * @package  Lwt\Shared\Infrastructure\Routing\Middleware
 * @author   HugoFara <git@hugofara.net>
 * @license  Unlicense <http://unlicense.org/>
 * @link     https://hugofara.github.io/lwt/developer/api
 * @since    3.0.0
 */
class CsrfMiddleware implements MiddlewareInterface
{
    /**
     * Session key for CSRF token.
     */
    private const SESSION_TOKEN = 'LWT_SESSION_TOKEN';

    /**
     * Form field name for CSRF token.
     */
    private const FORM_FIELD = '_csrf_token';

    /**
     * Header name for CSRF token.
     */
    private const HEADER_NAME = 'X-CSRF-TOKEN';

    /**
     * HTTP methods that require CSRF validation.
     *
     * @var array<string>
     */
    private const PROTECTED_METHODS = ['POST', 'PUT', 'DELETE', 'PATCH'];

    /**
     * Handle the incoming request.
     *
     * Validates CSRF token for state-changing requests.
     *
     * @return bool True if validation passes, false if halted
     */
    public function handle(): bool
    {
        // Skip for safe methods (GET, HEAD, OPTIONS)
        $method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
        if (!in_array($method, self::PROTECTED_METHODS, true)) {
            return true;
        }

        // Skip for API requests with Bearer token (token acts as CSRF protection)
        if ($this->hasApiToken()) {
            return true;
        }

        // Skip for cross-origin requests from a CORS-allow-listed origin.
        // CSRF protects cookie sessions, but the server never sends
        // `Access-Control-Allow-Credentials` (see Cors), so a browser never
        // attaches cookies to a cross-origin request — there is no session to
        // forge. The admin-controlled allow-list is the trust boundary, and
        // this is what lets a packaged client reach `/auth/login` for its first
        // bearer token (before which it has no token for the exemption above).
        // Same-origin requests carry the app's own origin, which is not in the
        // cross-origin allow-list, so the web app stays protected.
        if (Cors::resolveOrigin() !== null) {
            return true;
        }

        // Validate CSRF token
        if (!$this->validateToken()) {
            $this->handleInvalidToken($this->diagnoseFailure());
            return false;
        }

        return true;
    }

    /**
     * Check if request has a plausible API Bearer token.
     *
     * API tokens serve as CSRF protection since they're not automatically
     * sent by browsers like cookies are. The actual token validity is
     * verified by AuthMiddleware, which runs before CsrfMiddleware.
     * Here we only confirm the token is non-trivial (minimum 20 chars).
     *
     * @return bool True if Bearer token present and non-trivial
     */
    private function hasApiToken(): bool
    {
        $authHeader = $_SERVER['HTTP_AUTHORIZATION'] ?? '';

        if (empty($authHeader) && function_exists('apache_request_headers')) {
            $headers = apache_request_headers();
            if (is_array($headers)) {
                $rawHeader = (string) ($headers['Authorization'] ?? $headers['authorization'] ?? '');
                $authHeader = $rawHeader;
            }
        }

        if (!str_starts_with(strtolower($authHeader), 'bearer ')) {
            return false;
        }

        // Require a minimum token length to prevent trivial bypass
        $token = trim(substr($authHeader, 7));
        return strlen($token) >= 20;
    }

    /**
     * Validate the CSRF token.
     *
     * @return bool True if token is valid
     */
    private function validateToken(): bool
    {
        // Get expected token from session
        if (session_status() === PHP_SESSION_NONE) {
            @session_start();
        }

        $expectedTokenRaw = $_SESSION[self::SESSION_TOKEN] ?? null;
        if (!is_string($expectedTokenRaw) || $expectedTokenRaw === '') {
            return false;
        }

        // Get provided token from request
        $providedToken = $this->extractToken();
        if ($providedToken === null || $providedToken === '') {
            return false;
        }

        // Use timing-safe comparison
        return hash_equals($expectedTokenRaw, $providedToken);
    }

    /**
     * Extract CSRF token from request.
     *
     * Checks form field first, then header.
     *
     * @return string|null The token or null if not found
     */
    private function extractToken(): ?string
    {
        // Check form field (POST data)
        if (isset($_POST[self::FORM_FIELD]) && is_string($_POST[self::FORM_FIELD])) {
            return $_POST[self::FORM_FIELD];
        }

        // Check header
        $headerValue = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? null;
        if (is_string($headerValue) && $headerValue !== '') {
            return $headerValue;
        }

        return null;
    }

    /**
     * Diagnose why CSRF validation failed.
     *
     * @return string Human-readable reason for the failure
     */
    private function diagnoseFailure(): string
    {
        // Check if POST body was truncated (post_max_size exceeded)
        $requestMethod = $_SERVER['REQUEST_METHOD'] ?? '';
        if (
            $requestMethod === 'POST'
            && empty($_POST)
            && empty($_FILES)
        ) {
            $contentLength = (int) ($_SERVER['CONTENT_LENGTH'] ?? 0);
            $postMaxSize = self::parseIniSize((string) ini_get('post_max_size'));
            if ($contentLength > 0 && $postMaxSize > 0 && $contentLength > $postMaxSize) {
                return 'The submitted data (' . self::formatBytes($contentLength)
                    . ') exceeded PHP\'s post_max_size ('
                    . ini_get('post_max_size')
                    . '). Try importing a shorter text or increase post_max_size in php.ini.';
            }
        }

        // Check session state
        if (session_status() !== PHP_SESSION_ACTIVE) {
            return 'Your session could not be started. Check that cookies are enabled.';
        }

        $expectedTokenRaw = $_SESSION[self::SESSION_TOKEN] ?? null;
        if (!is_string($expectedTokenRaw) || $expectedTokenRaw === '') {
            return 'Your session does not contain a security token. '
                . 'This usually means the session expired or was reset.';
        }

        $providedToken = $this->extractToken();
        if ($providedToken === null || $providedToken === '') {
            return 'No security token was included in the request. '
                . 'This may happen if another request was still in progress.';
        }

        return 'The security token did not match. '
            . 'This usually means your session has changed since the page loaded.';
    }

    /**
     * Parse a PHP ini size value (e.g. "8M") to bytes.
     *
     * @param string $size The ini size string
     *
     * @return int Size in bytes
     */
    private static function parseIniSize(string $size): int
    {
        $value = (int) $size;
        $unit = strtolower(substr(trim($size), -1));
        return match ($unit) {
            'g' => $value * 1024 * 1024 * 1024,
            'm' => $value * 1024 * 1024,
            'k' => $value * 1024,
            default => $value,
        };
    }

    /**
     * Format bytes into a human-readable string.
     *
     * @param int $bytes Number of bytes
     *
     * @return string Formatted string (e.g. "1.5 MB")
     */
    private static function formatBytes(int $bytes): string
    {
        if ($bytes >= 1024 * 1024) {
            return round($bytes / (1024 * 1024), 1) . ' MB';
        }
        if ($bytes >= 1024) {
            return round($bytes / 1024, 1) . ' KB';
        }
        return $bytes . ' bytes';
    }

    /**
     * Handle invalid or missing CSRF token.
     *
     * @param string $reason Diagnostic reason for the failure
     *
     * @return void
     */
    private function handleInvalidToken(string $reason = ''): void
    {
        if ($this->isApiRequest()) {
            $this->sendForbiddenResponse();
        } else {
            $this->sendForbiddenPage($reason);
        }
    }

    /**
     * Check if this is an API request.
     *
     * @return bool True if API request
     */
    private function isApiRequest(): bool
    {
        $path = $_SERVER['REQUEST_URI'] ?? '/';
        $parsedUrl = parse_url($path);
        $requestPath = $parsedUrl['path'] ?? '/';

        if (str_starts_with($requestPath, '/api/')) {
            return true;
        }

        $accept = $_SERVER['HTTP_ACCEPT'] ?? '';
        if (str_contains($accept, 'application/json')) {
            return true;
        }

        $xRequestedWith = $_SERVER['HTTP_X_REQUESTED_WITH'] ?? '';
        return strtolower($xRequestedWith) === 'xmlhttprequest';
    }

    /**
     * Send 403 Forbidden JSON response.
     *
     * @return never
     */
    private function sendForbiddenResponse(): void
    {
        http_response_code(403);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode([
            'error' => 'Forbidden',
            'message' => 'CSRF token validation failed. Please refresh the page and try again.',
        ]);
        exit;
    }

    /**
     * Send 403 Forbidden HTML page.
     *
     * @param string $reason Diagnostic reason for the failure
     *
     * @return never
     */
    private function sendForbiddenPage(string $reason = ''): void
    {
        http_response_code(403);
        header('Content-Type: text/html; charset=utf-8');
        $reasonHtml = $reason !== ''
            ? '<p style="background:#fef3cd;border:1px solid #ffc107;padding:10px;border-radius:4px;">'
              . '<strong>Details:</strong> '
              . htmlspecialchars($reason, ENT_QUOTES, 'UTF-8')
              . '</p>'
            : '';
        echo <<<HTML
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>403 Forbidden - CSRF Token Invalid</title>
    <style>
        body { font-family: system-ui, sans-serif; max-width: 600px; margin: 100px auto; padding: 20px; }
        h1 { color: #c0392b; }
        a { color: #3498db; }
    </style>
</head>
<body>
    <h1>403 Forbidden</h1>
    <p>Your request could not be processed because the security token was missing or invalid.</p>
    {$reasonHtml}
    <p>This usually happens when:</p>
    <ul>
        <li>Your session has expired</li>
        <li>You submitted a form from a bookmarked page</li>
        <li>You have cookies disabled</li>
    </ul>
    <p>Use your browser's back button to try again, or <a href="/">return to the home page</a>.</p>
</body>
</html>
HTML;
        exit;
    }

    /**
     * Get the current CSRF token for embedding in forms.
     *
     * Creates a new token if one doesn't exist in the session.
     *
     * @return string The CSRF token
     */
    public static function getToken(): string
    {
        if (session_status() === PHP_SESSION_NONE) {
            @session_start();
        }

        /** @var mixed $sessionValue */
        $sessionValue = $_SESSION[self::SESSION_TOKEN] ?? '';
        $token = is_string($sessionValue) ? $sessionValue : '';
        if ($token === '') {
            $token = bin2hex(random_bytes(32));
            $_SESSION[self::SESSION_TOKEN] = $token;
        }

        return $token;
    }

    /**
     * Generate a hidden form field with the CSRF token.
     *
     * @return string HTML hidden input element
     */
    public static function formField(): string
    {
        $token = htmlspecialchars(self::getToken(), ENT_QUOTES, 'UTF-8');
        return '<input type="hidden" name="' . self::FORM_FIELD . '" value="' . $token . '">';
    }
}
