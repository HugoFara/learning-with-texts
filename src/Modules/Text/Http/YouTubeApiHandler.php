<?php

/**
 * YouTube API Handler
 *
 * Proxies YouTube API calls to keep the API key server-side.
 *
 * PHP version 8.2
 *
 * @category Lwt
 * @package  Lwt\Modules\Text\Http
 * @author   HugoFara <git@hugofara.net>
 * @license  Unlicense <http://unlicense.org/>
 * @link     https://hugofara.github.io/lwt/developer/api
 * @since    3.0.0
 */

declare(strict_types=1);

namespace Lwt\Modules\Text\Http;

use Lwt\Api\V1\Response;
use Lwt\Shared\Http\ApiRoutableInterface;
use Lwt\Shared\Http\ApiRoutableTrait;
use Lwt\Shared\Infrastructure\Bootstrap\EnvLoader;
use Lwt\Shared\Infrastructure\Http\JsonResponse;

/**
 * Handler for YouTube API proxy endpoints.
 *
 * Keeps the YouTube API key server-side to prevent exposure to clients.
 *
 * @since 3.0.0
 */
class YouTubeApiHandler implements ApiRoutableInterface
{
    use ApiRoutableTrait;

    /**
     * YouTube Data API v3 base URL.
     */
    private const YOUTUBE_API_BASE = 'https://www.googleapis.com/youtube/v3';

    /**
     * Get the YouTube API key from environment.
     *
     * @return string|null The API key or null if not configured
     */
    private function getApiKey(): ?string
    {
        return EnvLoader::get('YT_API_KEY');
    }

    /**
     * Check if YouTube API is configured.
     *
     * @return array{configured: bool}
     */
    public function formatIsConfigured(): array
    {
        $key = $this->getApiKey();
        return ['configured' => $key !== null && $key !== ''];
    }

    /**
     * Fetch video information from YouTube.
     *
     * @param string $videoId The YouTube video ID
     *
     * @return array{success: bool, data?: array, error?: string}
     */
    public function formatGetVideoInfo(string $videoId): array
    {
        $apiKey = $this->getApiKey();

        if ($apiKey === null || $apiKey === '') {
            return [
                'success' => false,
                'error' => 'YouTube API key not configured. Set YT_API_KEY in your .env file.',
            ];
        }

        // Validate video ID (alphanumeric, hyphens, underscores, typically 11 chars)
        if (!preg_match('/^[a-zA-Z0-9_-]{10,12}$/', $videoId)) {
            return [
                'success' => false,
                'error' => 'Invalid video ID format.',
            ];
        }

        $url = self::YOUTUBE_API_BASE . '/videos?' . http_build_query([
            'part' => 'snippet',
            'id' => $videoId,
            'key' => $apiKey,
        ]);

        $context = stream_context_create([
            'http' => [
                'method' => 'GET',
                'timeout' => 10,
                'ignore_errors' => true,
            ],
        ]);

        $response = @file_get_contents($url, false, $context);

        if ($response === false) {
            return [
                'success' => false,
                'error' => 'Failed to connect to YouTube API.',
            ];
        }

        // Check HTTP status from response headers
        $httpCode = $this->extractHttpCode($http_response_header ?? []);

        if ($httpCode === 403) {
            return [
                'success' => false,
                'error' => 'Invalid API key or quota exceeded.',
            ];
        }

        if ($httpCode === 400) {
            return [
                'success' => false,
                'error' => 'Invalid video ID.',
            ];
        }

        if ($httpCode !== 200) {
            return [
                'success' => false,
                'error' => 'YouTube API returned error: HTTP ' . $httpCode,
            ];
        }

        $data = json_decode($response, true);

        if (!is_array($data)) {
            return [
                'success' => false,
                'error' => 'Invalid response from YouTube API.',
            ];
        }

        if (!isset($data['items']) || !is_array($data['items']) || empty($data['items'])) {
            return [
                'success' => false,
                'error' => 'Video not found.',
            ];
        }

        /** @var mixed $firstItem */
        $firstItem = $data['items'][0];
        /** @var array{title?: string, description?: string} $snippet */
        $snippet = is_array($firstItem) && isset($firstItem['snippet']) && is_array($firstItem['snippet'])
            ? $firstItem['snippet']
            : [];

        return [
            'success' => true,
            'data' => [
                'title' => $snippet['title'] ?? '',
                'description' => $snippet['description'] ?? '',
                'source_url' => 'https://youtube.com/watch?v=' . $videoId,
            ],
        ];
    }

    /**
     * Extract HTTP status code from response headers.
     *
     * @param array<string> $headers The response headers
     *
     * @return int The HTTP status code
     */
    private function extractHttpCode(array $headers): int
    {
        foreach ($headers as $header) {
            if (preg_match('/^HTTP\/\d+\.\d+\s+(\d+)/', $header, $matches)) {
                return (int) $matches[1];
            }
        }
        return 0;
    }

    /**
     * Route GET requests for the YouTube API handler.
     *
     * @param list<string> $fragments URL path fragments
     * @param array<string, mixed> $params Query parameters
     *
     * @return JsonResponse
     */
    public function routeGet(array $fragments, array $params): JsonResponse
    {
        $frag1 = $this->frag($fragments, 1);

        switch ($frag1) {
            case 'configured':
                return Response::success($this->formatIsConfigured());
            case 'video':
                $videoId = (string) ($params['video_id'] ?? '');
                if ($videoId === '') {
                    return Response::error('video_id parameter is required', 400);
                }
                return Response::success($this->formatGetVideoInfo($videoId));
            default:
                return Response::error('Expected "configured" or "video"', 404);
        }
    }
}
