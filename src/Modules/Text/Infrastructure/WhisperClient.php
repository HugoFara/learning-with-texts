<?php

/**
 * WhisperClient for NLP service integration.
 *
 * PHP version 8.2
 *
 * @category Infrastructure
 * @package  Lwt
 * @author   HugoFara <git@hugofara.net>
 * @license  Unlicense <http://unlicense.org/>
 * @link     https://hugofara.github.io/lwt/developer/api
 * @since    3.0.0
 */

declare(strict_types=1);

namespace Lwt\Modules\Text\Infrastructure;

use Lwt\Shared\Infrastructure\Bootstrap\EnvLoader;

/**
 * HTTP client for communicating with the NLP microservice Whisper endpoints.
 *
 * Provides methods for transcribing audio/video files using Whisper.
 */
class WhisperClient
{
    private string $baseUrl;
    private int $timeout = 30;

    public function __construct()
    {
        $this->baseUrl = EnvLoader::get('NLP_SERVICE_URL', 'http://nlp:8000') ?? 'http://nlp:8000';
    }

    /**
     * Check if Whisper transcription is available.
     */
    public function isAvailable(): bool
    {
        $context = stream_context_create(
            [
            'http' => ['method' => 'GET', 'timeout' => 5]
            ]
        );

        $response = @file_get_contents($this->baseUrl . '/whisper/available', false, $context);
        if ($response === false) {
            return false;
        }

        /**
 * @var array{available?: bool}|null $data
*/
        $data = json_decode($response, true);
        return $data['available'] ?? false;
    }

    /**
     * Get list of supported languages.
     *
     * @return array<array{code: string, name: string}>
     */
    public function getLanguages(): array
    {
        $context = stream_context_create(
            [
            'http' => ['method' => 'GET', 'timeout' => 10]
            ]
        );

        $response = @file_get_contents($this->baseUrl . '/whisper/languages', false, $context);
        if ($response === false) {
            return [];
        }

        /**
 * @var array{languages?: array<array{code: string, name: string}>}|null $data
*/
        $data = json_decode($response, true);
        return $data['languages'] ?? [];
    }

    /**
     * Get list of available Whisper models.
     *
     * @return array<array{name: string, description: string}>
     */
    public function getModels(): array
    {
        $context = stream_context_create(
            [
            'http' => ['method' => 'GET', 'timeout' => 10]
            ]
        );

        $response = @file_get_contents($this->baseUrl . '/whisper/models', false, $context);
        if ($response === false) {
            return [];
        }

        /**
 * @var array{models?: array<array{name: string, description: string}>}|null $data
*/
        $data = json_decode($response, true);
        return $data['models'] ?? [];
    }

    /**
     * Start a transcription job.
     *
     * @param string      $filePath Path to the uploaded file
     * @param string      $fileName Original filename
     * @param string|null $language Language code (null for auto-detect)
     * @param string      $model    Whisper model name
     *
     * @return string Job ID for tracking
     *
     * @throws \RuntimeException If transcription fails to start
     */
    public function startTranscription(
        string $filePath,
        string $fileName,
        ?string $language,
        string $model = 'small'
    ): string {
        $ch = curl_init($this->baseUrl . '/whisper/transcribe');
        if ($ch === false) {
            throw new \RuntimeException('Failed to initialize cURL');
        }

        /** @psalm-suppress UndefinedClass - CURLFile is a core PHP class */
        $postFields = [
            'file' => new \CURLFile($filePath, '', $fileName),
            'model' => $model,
        ];

        if ($language !== null && $language !== '') {
            $postFields['language'] = $language;
        }

        curl_setopt_array(
            $ch,
            [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $postFields,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 120, // Upload timeout
            ]
        );

        $response = curl_exec($ch);
        /** @psalm-suppress MixedArgument - CURLINFO_HTTP_CODE is a core PHP constant */
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        if ($response === false || !is_string($response)) {
            throw new \RuntimeException('Failed to connect to NLP service: ' . $error);
        }

        /**
 * @var array{job_id?: string, detail?: string}|null $data
*/
        $data = json_decode($response, true);

        if ($httpCode !== 200 && $httpCode !== 202) {
            $detail = $data['detail'] ?? 'Unknown error';
            throw new \RuntimeException('Failed to start transcription: ' . $detail);
        }

        if (!isset($data['job_id'])) {
            throw new \RuntimeException('No job_id returned from NLP service');
        }

        return $data['job_id'];
    }

    /**
     * Get the status of a transcription job.
     *
     * @param string $jobId Job ID
     *
     * @return array{job_id: string, status: string, progress: int, message: string}
     */
    public function getStatus(string $jobId): array
    {
        $context = stream_context_create(
            [
            'http' => ['method' => 'GET', 'timeout' => $this->timeout]
            ]
        );

        $url = $this->baseUrl . '/whisper/status/' . urlencode($jobId);
        $response = @file_get_contents($url, false, $context);

        if ($response === false) {
            return [
                'job_id' => $jobId,
                'status' => 'error',
                'progress' => 0,
                'message' => 'Failed to get status from NLP service',
            ];
        }

        /**
 * @var array{job_id: string, status: string, progress: int, message: string} $data
*/
        $data = json_decode($response, true);
        return $data;
    }

    /**
     * Get the result of a completed transcription.
     *
     * @param string $jobId Job ID
     *
     * @return array{job_id: string, text: string, language: string, duration_seconds: float}
     *
     * @throws \RuntimeException If result cannot be retrieved
     */
    public function getResult(string $jobId): array
    {
        $context = stream_context_create(
            [
            'http' => [
                'method' => 'GET',
                'timeout' => $this->timeout,
                'ignore_errors' => true,
            ]
            ]
        );

        $url = $this->baseUrl . '/whisper/result/' . urlencode($jobId);
        $response = @file_get_contents($url, false, $context);

        if ($response === false) {
            throw new \RuntimeException('Failed to get result from NLP service');
        }

        // Check HTTP status from response headers
        $httpCode = 200;
        if (isset($http_response_header)) {
            foreach ($http_response_header as $header) {
                if (preg_match('/^HTTP\/\d+\.\d+\s+(\d+)/', $header, $matches)) {
                    $httpCode = (int)$matches[1];
                    break;
                }
            }
        }

        /**
 * @var array{job_id?: string, text?: string, language?: string, duration_seconds?: float, detail?: string}|null $data
*/
        $data = json_decode($response, true);

        if ($httpCode === 202) {
            throw new \RuntimeException('Transcription still in progress');
        }

        if ($httpCode === 410) {
            throw new \RuntimeException('Job was cancelled');
        }

        if ($httpCode >= 400) {
            $detail = $data['detail'] ?? 'Unknown error';
            throw new \RuntimeException('Failed to get result: ' . $detail);
        }

        /**
 * @var array{job_id: string, text: string, language: string, duration_seconds: float}
*/
        return $data ?? [];
    }

    /**
     * Cancel a transcription job.
     *
     * @param string $jobId Job ID
     *
     * @return bool True if cancelled/deleted successfully
     */
    public function cancelJob(string $jobId): bool
    {
        $context = stream_context_create(
            [
            'http' => [
                'method' => 'DELETE',
                'timeout' => $this->timeout,
            ]
            ]
        );

        $url = $this->baseUrl . '/whisper/job/' . urlencode($jobId);
        $response = @file_get_contents($url, false, $context);

        return $response !== false;
    }
}
