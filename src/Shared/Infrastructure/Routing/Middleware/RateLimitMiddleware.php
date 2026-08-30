<?php

/**
 * Rate Limiting Middleware
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

/**
 * Middleware that enforces rate limiting for API requests.
 *
 * Uses a sliding window algorithm to limit requests per IP address.
 * Configurable limits for general API requests and auth endpoints.
 *
 * @category Lwt
 * @package  Lwt\Shared\Infrastructure\Routing\Middleware
 * @author   HugoFara <git@hugofara.net>
 * @license  Unlicense <http://unlicense.org/>
 * @link     https://hugofara.github.io/lwt/developer/api
 * @since    3.0.0
 */
class RateLimitMiddleware implements MiddlewareInterface
{
    /**
     * Default rate limit for general API requests (requests per window).
     */
    private const DEFAULT_LIMIT = 100;

    /**
     * Default window size in seconds (1 minute).
     */
    private const DEFAULT_WINDOW = 60;

    /**
     * Stricter rate limit for authentication endpoints (requests per window).
     */
    private const AUTH_LIMIT = 10;

    /**
     * Window size for auth endpoints in seconds (5 minutes).
     */
    private const AUTH_WINDOW = 300;

    /**
     * Rate limit for Whisper transcription kickoffs.
     *
     * Each kickoff queues a heavy NLP job that can hold a worker for
     * minutes. Even legitimate users rarely need more than a handful
     * an hour, so cap aggressively — five per 15 minutes is enough
     * for an actual reading workflow but blocks a runaway script
     * from saturating the model server.
     */
    private const WHISPER_LIMIT = 5;

    /**
     * Window size for Whisper endpoints in seconds (15 minutes).
     */
    private const WHISPER_WINDOW = 900;

    /**
     * Storage backend for rate limit data.
     */
    private RateLimitStorage $storage;

    /**
     * Maximum requests allowed per window.
     */
    private int $limit;

    /**
     * Window size in seconds.
     */
    private int $window;

    /**
     * Create a new RateLimitMiddleware.
     *
     * @param RateLimitStorage|null $storage Optional storage backend
     * @param int|null              $limit   Optional custom request limit
     * @param int|null              $window  Optional custom window size in seconds
     */
    public function __construct(
        ?RateLimitStorage $storage = null,
        ?int $limit = null,
        ?int $window = null
    ) {
        $this->storage = $storage ?? new RateLimitStorage();
        $this->limit = $limit ?? self::DEFAULT_LIMIT;
        $this->window = $window ?? self::DEFAULT_WINDOW;
    }

    /**
     * Handle the incoming request.
     *
     * Checks if the client has exceeded the rate limit.
     * On failure, returns 429 Too Many Requests.
     *
     * @return bool True if request is allowed, false if rate limited
     */
    public function handle(): bool
    {
        $clientId = $this->getClientIdentifier();

        // Skip rate limiting for localhost (development/testing)
        if ($clientId === '127.0.0.1' || $clientId === '::1') {
            return true;
        }
        $endpoint = $this->getEndpointType();

        // Endpoint-specific knobs: auth gets brute-force protection,
        // whisper gets NLP-saturation protection, everything else
        // uses the broad default.
        if ($endpoint === 'auth') {
            $limit = self::AUTH_LIMIT;
            $window = self::AUTH_WINDOW;
        } elseif ($endpoint === 'whisper') {
            $limit = self::WHISPER_LIMIT;
            $window = self::WHISPER_WINDOW;
        } else {
            $limit = $this->limit;
            $window = $this->window;
        }

        $key = $this->buildKey($clientId, $endpoint);
        $now = time();

        // Get current request count and window start
        $data = $this->storage->get($key);

        if ($data === null || $data['window_start'] < ($now - $window)) {
            // Start new window
            $data = [
                'count' => 1,
                'window_start' => $now,
            ];
            $this->storage->set($key, $data, $window);
            $this->addRateLimitHeaders($limit, $limit - 1, $now + $window);
            return true;
        }

        // Increment request count
        $data['count']++;

        if ($data['count'] > $limit) {
            // Rate limit exceeded
            $retryAfter = $data['window_start'] + $window - $now;
            $this->sendRateLimitedResponse($retryAfter, $limit);
            // Note: sendRateLimitedResponse calls exit, so this line is never reached
            // but Psalm requires explicit return for control flow analysis
        }

        // Update count
        $remaining = $window - ($now - $data['window_start']);
        $this->storage->set($key, $data, $remaining > 0 ? $remaining : 1);
        $this->addRateLimitHeaders($limit, $limit - $data['count'], $data['window_start'] + $window);

        return true;
    }

    /**
     * Get the client identifier (IP address).
     *
     * @return string Client identifier
     */
    private function getClientIdentifier(): string
    {
        // Check for forwarded IP (behind reverse proxy)
        $forwarded = $_SERVER['HTTP_X_FORWARDED_FOR'] ?? '';
        if ($forwarded !== '') {
            // Take the first IP in the chain (original client)
            $ips = explode(',', $forwarded);
            $clientIp = trim($ips[0]);
            // Validate IP format to prevent spoofing
            if (filter_var($clientIp, FILTER_VALIDATE_IP)) {
                return $clientIp;
            }
        }

        // Check X-Real-IP header
        $realIp = $_SERVER['HTTP_X_REAL_IP'] ?? '';
        if ($realIp !== '' && filter_var($realIp, FILTER_VALIDATE_IP)) {
            return $realIp;
        }

        // Fall back to REMOTE_ADDR
        return $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';
    }

    /**
     * Get the endpoint type for rate limiting.
     *
     * @return string Endpoint type: 'auth', 'whisper', or 'api'
     */
    private function getEndpointType(): string
    {
        $path = $_SERVER['REQUEST_URI'] ?? '/';
        $parsedUrl = parse_url($path);
        $requestPath = $parsedUrl['path'] ?? '/';

        // Check if this is an auth endpoint (API or web)
        if (preg_match('#/api(?:\.php)?/v1/auth/(login|register)#', $requestPath)) {
            return 'auth';
        }
        if (preg_match('#^/(login|register|password/forgot|password/reset)$#', $requestPath)) {
            return 'auth';
        }

        // Whisper transcription kickoff — heavy NLP work, stricter cap.
        // /status, /result, /job/{id} are polling/cleanup endpoints
        // and stay on the general API limit.
        if (preg_match('#/api(?:\.php)?/v1/whisper/transcribe$#', $requestPath)) {
            return 'whisper';
        }

        return 'api';
    }

    /**
     * Build the storage key for rate limit tracking.
     *
     * @param string $clientId Client identifier
     * @param string $endpoint Endpoint type
     *
     * @return string Storage key
     */
    private function buildKey(string $clientId, string $endpoint): string
    {
        return "rate_limit:{$endpoint}:{$clientId}";
    }

    /**
     * Add rate limit headers to the response.
     *
     * @param int $limit     Maximum requests allowed
     * @param int $remaining Requests remaining in current window
     * @param int $reset     Unix timestamp when window resets
     *
     * @return void
     */
    private function addRateLimitHeaders(int $limit, int $remaining, int $reset): void
    {
        header("X-RateLimit-Limit: {$limit}");
        header("X-RateLimit-Remaining: " . max(0, $remaining));
        header("X-RateLimit-Reset: {$reset}");
    }

    /**
     * Send a 429 Too Many Requests response.
     *
     * @param int $retryAfter Seconds until client can retry
     * @param int $limit      Maximum requests allowed
     *
     * @return never
     */
    private function sendRateLimitedResponse(int $retryAfter, int $limit): void
    {
        http_response_code(429);
        header('Content-Type: application/json; charset=utf-8');
        header("Retry-After: {$retryAfter}");
        header("X-RateLimit-Limit: {$limit}");
        header("X-RateLimit-Remaining: 0");

        echo json_encode([
            'error' => 'Too Many Requests',
            'message' => "Rate limit exceeded. Please retry after {$retryAfter} seconds.",
            'retry_after' => $retryAfter,
        ]);
        exit;
    }
}
