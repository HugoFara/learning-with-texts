<?php

/**
 * Auth Rate Limiting Middleware
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
 * Rate limiting middleware specifically for web authentication routes.
 *
 * Applies 10 requests per 5 minutes limit to login, register,
 * and password reset endpoints.
 *
 * @since 3.0.0
 */
class AuthRateLimitMiddleware implements MiddlewareInterface
{
    private const LIMIT = 10;
    private const WINDOW = 300; // 5 minutes

    private RateLimitStorage $storage;

    public function __construct(?RateLimitStorage $storage = null)
    {
        $this->storage = $storage ?? new RateLimitStorage();
    }

    /**
     * {@inheritdoc}
     */
    public function handle(): bool
    {
        $clientId = $this->getClientIdentifier();

        // Skip for localhost
        if ($clientId === '127.0.0.1' || $clientId === '::1') {
            return true;
        }

        $key = "rate_limit:web_auth:{$clientId}";
        $now = time();

        $data = $this->storage->get($key);

        if ($data === null || $data['window_start'] < ($now - self::WINDOW)) {
            $data = ['count' => 1, 'window_start' => $now];
            $this->storage->set($key, $data, self::WINDOW);
            return true;
        }

        $data['count']++;

        if ($data['count'] > self::LIMIT) {
            $retryAfter = $data['window_start'] + self::WINDOW - $now;
            http_response_code(429);
            header("Retry-After: {$retryAfter}");
            header('Content-Type: text/html; charset=utf-8');
            echo '<h1>Too Many Requests</h1>'
                . '<p>You have made too many authentication attempts. '
                . "Please try again in {$retryAfter} seconds.</p>";
            exit;
        }

        $remaining = self::WINDOW - ($now - $data['window_start']);
        $this->storage->set($key, $data, $remaining > 0 ? $remaining : 1);

        return true;
    }

    private function getClientIdentifier(): string
    {
        $forwarded = $_SERVER['HTTP_X_FORWARDED_FOR'] ?? '';
        if ($forwarded !== '') {
            $ips = explode(',', $forwarded);
            $clientIp = trim($ips[0]);
            if (filter_var($clientIp, FILTER_VALIDATE_IP)) {
                return $clientIp;
            }
        }

        $realIp = $_SERVER['HTTP_X_REAL_IP'] ?? '';
        if ($realIp !== '' && filter_var($realIp, FILTER_VALIDATE_IP)) {
            return $realIp;
        }

        return $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';
    }
}
