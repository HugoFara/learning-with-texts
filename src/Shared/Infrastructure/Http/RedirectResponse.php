<?php

/**
 * Redirect Response
 *
 * PHP version 8.2
 *
 * @category Lwt
 * @package  Lwt\Shared\Infrastructure\Http
 * @author   HugoFara <git@hugofara.net>
 * @license  Unlicense <http://unlicense.org/>
 * @link     https://hugofara.github.io/lwt/developer/api
 * @since    3.0.0
 */

declare(strict_types=1);

namespace Lwt\Shared\Infrastructure\Http;

/**
 * HTTP redirect response.
 *
 * Encapsulates a redirect response with URL and status code.
 * Can be returned from controllers and sent by the router.
 *
 * @since 3.0.0
 */
class RedirectResponse implements ResponseInterface
{
    /**
     * @var string Target URL for redirect
     */
    private string $url;

    /**
     * @var int HTTP status code (302 for temporary, 301 for permanent)
     */
    private int $statusCode;

    /**
     * Create a new redirect response.
     *
     * @param string $url        Target URL
     * @param int    $statusCode HTTP status code (default: 302 Found)
     */
    public function __construct(string $url, int $statusCode = 302)
    {
        $this->url = $url;
        $this->statusCode = $statusCode;
    }

    /**
     * Send the redirect response.
     *
     * @return void
     */
    public function send(): void
    {
        // Only set headers if they haven't been sent yet
        if (!headers_sent()) {
            http_response_code($this->statusCode);
            header('Location: ' . $this->url);
        }
    }

    /**
     * Get the HTTP status code.
     *
     * @return int HTTP status code
     */
    public function getStatusCode(): int
    {
        return $this->statusCode;
    }

    /**
     * Get the redirect URL.
     *
     * @return string Target URL
     */
    public function getUrl(): string
    {
        return $this->url;
    }

    /**
     * Create a temporary redirect (302 Found).
     *
     * @param string $url Target URL
     *
     * @return self
     */
    public static function temporary(string $url): self
    {
        return new self($url, 302);
    }

    /**
     * Create a permanent redirect (301 Moved Permanently).
     *
     * @param string $url Target URL
     *
     * @return self
     */
    public static function permanent(string $url): self
    {
        return new self($url, 301);
    }

    /**
     * Create a "see other" redirect (303 See Other).
     *
     * Used after POST to redirect to a GET endpoint.
     *
     * @param string $url Target URL
     *
     * @return self
     */
    public static function seeOther(string $url): self
    {
        return new self($url, 303);
    }
}
