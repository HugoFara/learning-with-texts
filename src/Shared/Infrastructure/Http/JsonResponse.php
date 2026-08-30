<?php

/**
 * JSON Response
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
 * HTTP JSON response.
 *
 * Encapsulates a JSON response with data and status code.
 * Can be returned from controllers and sent by the router.
 *
 * @since 3.0.0
 */
class JsonResponse implements ResponseInterface
{
    /**
     * @var mixed Response data to encode as JSON
     */
    private mixed $data;

    /**
     * @var int HTTP status code
     */
    private int $statusCode;

    /**
     * @var int JSON encoding flags
     */
    private int $encodingFlags;

    /**
     * Create a new JSON response.
     *
     * @param mixed $data          Data to encode as JSON
     * @param int   $statusCode    HTTP status code (default: 200)
     * @param int   $encodingFlags JSON encoding flags (default: 0)
     */
    public function __construct(
        mixed $data,
        int $statusCode = 200,
        int $encodingFlags = 0
    ) {
        $this->data = $data;
        $this->statusCode = $statusCode;
        $this->encodingFlags = $encodingFlags;
    }

    /**
     * Send the JSON response.
     *
     * @return void
     */
    public function send(): void
    {
        // Only set headers if they haven't been sent yet
        if (!headers_sent()) {
            http_response_code($this->statusCode);
            header('Content-Type: application/json; charset=utf-8');
        }
        echo json_encode($this->data, $this->encodingFlags);
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
     * Get the response data.
     *
     * @return mixed Response data
     */
    public function getData(): mixed
    {
        return $this->data;
    }

    /**
     * Create a success response (200 OK).
     *
     * @param mixed $data Response data
     *
     * @return self
     */
    public static function success(mixed $data): self
    {
        return new self($data, 200);
    }

    /**
     * Create a created response (201 Created).
     *
     * @param mixed $data Response data
     *
     * @return self
     */
    public static function created(mixed $data): self
    {
        return new self($data, 201);
    }

    /**
     * Create an error response.
     *
     * @param string $message    Error message
     * @param int    $statusCode HTTP status code (default: 400)
     *
     * @return self
     */
    public static function error(string $message, int $statusCode = 400): self
    {
        return new self(['error' => $message], $statusCode);
    }

    /**
     * Create a not found response (404 Not Found).
     *
     * @param string $message Error message (default: "Not found")
     *
     * @return self
     */
    public static function notFound(string $message = 'Not found'): self
    {
        return new self(['error' => $message], 404);
    }
}
