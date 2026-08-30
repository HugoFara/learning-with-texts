<?php

/**
 * Whisper API Handler
 *
 * Provides endpoints for audio/video transcription using Whisper.
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
use Lwt\Modules\Text\Infrastructure\WhisperClient;
use Lwt\Modules\Text\Infrastructure\WhisperJobRepository;
use Lwt\Shared\Http\ApiRoutableInterface;
use Lwt\Shared\Http\ApiRoutableTrait;
use Lwt\Shared\Infrastructure\Http\JsonResponse;

/**
 * Handler for Whisper transcription API endpoints.
 *
 * Proxies requests to the NLP microservice for Whisper transcription.
 *
 * @since 3.0.0
 */
class WhisperApiHandler implements ApiRoutableInterface
{
    use ApiRoutableTrait;

    /**
     * Allowed audio/video file extensions.
     */
    private const ALLOWED_EXTENSIONS = [
        'mp3', 'mp4', 'wav', 'webm', 'ogg', 'm4a', 'mkv', 'flac', 'avi', 'mov', 'wma', 'aac'
    ];

    /**
     * Allowed MIME-type prefixes.
     *
     * We don't pin the full type because finfo varies across distros
     * (e.g. m4a as audio/mp4 vs audio/x-m4a). Prefix matching is
     * narrow enough: a `text/html` masquerading as `.mp3` is rejected,
     * but legitimate variants of audio/* and video/* go through.
     * `application/ogg` covers Ogg containers that finfo sometimes
     * tags under application instead of audio.
     */
    private const ALLOWED_MIME_PREFIXES = ['audio/', 'video/', 'application/ogg'];

    /**
     * Maximum file size in bytes (500MB).
     */
    private const MAX_FILE_SIZE = 500 * 1024 * 1024;

    private WhisperClient $client;
    private WhisperJobRepository $jobs;

    public function __construct(?WhisperClient $client = null, ?WhisperJobRepository $jobs = null)
    {
        $this->client = $client ?? new WhisperClient();
        $this->jobs = $jobs ?? new WhisperJobRepository();
    }

    /**
     * Check if Whisper transcription is available.
     *
     * @return array{available: bool}
     */
    public function formatIsAvailable(): array
    {
        return ['available' => $this->client->isAvailable()];
    }

    /**
     * Get list of supported languages.
     *
     * @return array{languages: array}
     */
    public function formatGetLanguages(): array
    {
        return ['languages' => $this->client->getLanguages()];
    }

    /**
     * Get list of available Whisper models.
     *
     * @return array{models: array}
     */
    public function formatGetModels(): array
    {
        return ['models' => $this->client->getModels()];
    }

    /**
     * Start a transcription job.
     *
     * @param array{name?: string, tmp_name?: string, size?: int} $file Uploaded file from $_FILES
     * @param string|null $language Language code (null for auto-detect)
     * @param string      $model    Whisper model name
     *
     * @return array{job_id: string}
     *
     * @throws \InvalidArgumentException If file is invalid
     * @throws \RuntimeException If Whisper is not available
     */
    public function formatStartTranscription(
        array $file,
        ?string $language,
        string $model = 'small'
    ): array {
        // Validate file was uploaded
        $tmpName = $file['tmp_name'] ?? '';
        if ($tmpName === '' || !is_uploaded_file($tmpName)) {
            throw new \InvalidArgumentException('No file uploaded');
        }

        // Sanitize filename: basename strips path components a client could
        // sneak in via something like "../../etc/passwd.mp3", and the
        // control-char/RTL-override strip prevents the NLP service or any
        // logger from rendering hostile filenames.
        $rawName = $file['name'] ?? 'unknown';
        $filename = self::sanitizeFilename($rawName);
        $ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));

        if (!in_array($ext, self::ALLOWED_EXTENSIONS, true)) {
            throw new \InvalidArgumentException(
                'Unsupported file type: ' . $ext . '. Allowed: ' . implode(', ', self::ALLOWED_EXTENSIONS)
            );
        }

        // Validate file size from the real on-disk size, not $file['size']
        // — that field comes from the multipart Content-Length and a
        // crafted form can lie about it. filesize($tmpName) is what PHP
        // actually wrote out.
        $fileSize = @filesize($tmpName);
        if ($fileSize === false || $fileSize > self::MAX_FILE_SIZE) {
            throw new \InvalidArgumentException(
                'File too large. Maximum size: ' . intdiv(self::MAX_FILE_SIZE, 1024 * 1024) . 'MB'
            );
        }

        // MIME re-verification: a `.mp3` extension proves nothing — the
        // file could be an executable, an HTML page with smuggled JS,
        // or a polyglot. Whisper itself would reject most, but the NLP
        // service is the most expensive consumer in the stack and we'd
        // rather drop garbage here than after a transcoder spin-up.
        self::assertAudioVideoMime($tmpName);

        // Validate model
        $validModels = ['tiny', 'base', 'small', 'medium', 'large'];
        if (!in_array($model, $validModels, true)) {
            throw new \InvalidArgumentException(
                'Invalid model: ' . $model . '. Allowed: ' . implode(', ', $validModels)
            );
        }

        // Check if Whisper is available
        if (!$this->client->isAvailable()) {
            throw new \RuntimeException('Whisper transcription is not available. Please check NLP service.');
        }

        // Start transcription
        $jobId = $this->client->startTranscription(
            $tmpName,
            $filename,
            $language,
            $model
        );

        // Bind the NLP-issued job_id to the caller so status/result/cancel
        // can reject foreign IDs even if the UUID leaks.
        $this->jobs->recordForCurrentUser($jobId);

        return ['job_id' => $jobId];
    }

    /**
     * Get the status of a transcription job.
     *
     * @param string $jobId Job ID
     *
     * @return array{job_id: string, status: string, progress: int, message: string}
     */
    public function formatGetStatus(string $jobId): array
    {
        if (empty($jobId)) {
            throw new \InvalidArgumentException('Job ID is required');
        }
        if (!$this->jobs->isOwnedByCurrentUser($jobId)) {
            throw new \RuntimeException('Job not found');
        }

        return $this->client->getStatus($jobId);
    }

    /**
     * Get the result of a completed transcription.
     *
     * @param string $jobId Job ID
     *
     * @return array{job_id: string, text: string, language: string, duration_seconds: float}
     */
    public function formatGetResult(string $jobId): array
    {
        if (empty($jobId)) {
            throw new \InvalidArgumentException('Job ID is required');
        }
        if (!$this->jobs->isOwnedByCurrentUser($jobId)) {
            throw new \RuntimeException('Job not found');
        }

        return $this->client->getResult($jobId);
    }

    /**
     * Cancel a transcription job.
     *
     * @param string $jobId Job ID
     *
     * @return array{cancelled: bool}
     */
    public function formatCancelJob(string $jobId): array
    {
        if (empty($jobId)) {
            throw new \InvalidArgumentException('Job ID is required');
        }
        if (!$this->jobs->isOwnedByCurrentUser($jobId)) {
            throw new \RuntimeException('Job not found');
        }

        $cancelled = $this->client->cancelJob($jobId);
        if ($cancelled) {
            $this->jobs->forget($jobId);
        }
        return ['cancelled' => $cancelled];
    }

    public function routeGet(array $fragments, array $params): JsonResponse
    {
        $frag1 = $this->frag($fragments, 1);
        $frag2 = $this->frag($fragments, 2);

        switch ($frag1) {
            case 'available':
                return Response::success($this->formatIsAvailable());
            case 'languages':
                return Response::success($this->formatGetLanguages());
            case 'models':
                return Response::success($this->formatGetModels());
            case 'status':
                if ($frag2 === '') {
                    return Response::error('job_id is required', 400);
                }
                try {
                    return Response::success($this->formatGetStatus($frag2));
                } catch (\RuntimeException $e) {
                    // The ownership check throws "Job not found" — surface
                    // it as 404 (same shape as an unknown job_id at NLP).
                    return Response::error($e->getMessage(), 404);
                }
            case 'result':
                if ($frag2 === '') {
                    return Response::error('job_id is required', 400);
                }
                try {
                    return Response::success($this->formatGetResult($frag2));
                } catch (\RuntimeException $e) {
                    $code = $e->getMessage() === 'Job not found' ? 404 : 500;
                    return Response::error($e->getMessage(), $code);
                }
            default:
                return Response::error(
                    'Expected "available", "languages", "models", "status/{id}", or "result/{id}"',
                    404
                );
        }
    }

    public function routePost(array $fragments, array $params): JsonResponse
    {
        $frag1 = $this->frag($fragments, 1);

        if ($frag1 === 'transcribe') {
            /** @var array{name?: string, tmp_name?: string, size?: int}|null $file */
            $file = $_FILES['file'] ?? null;
            if ($file === null) {
                return Response::error('No file uploaded', 400);
            }

            $language = isset($params['language']) && $params['language'] !== '' ? (string) $params['language'] : null;
            $model = (string) ($params['model'] ?? 'small');

            try {
                return Response::success($this->formatStartTranscription($file, $language, $model));
            } catch (\InvalidArgumentException $e) {
                return Response::error($e->getMessage(), 400);
            } catch (\RuntimeException $e) {
                return Response::error($e->getMessage(), 503);
            }
        }

        return Response::error('Expected "transcribe"', 404);
    }

    /**
     * Strip path components, control characters, and Unicode
     * bidi-override runs from a client-supplied filename. Thin
     * wrapper around the shared {@see InputValidator::sanitizeUploadName}
     * — kept here so the existing test suite continues to exercise
     * the Whisper-specific input path.
     */
    private static function sanitizeFilename(string $name): string
    {
        return \Lwt\Shared\Infrastructure\Http\InputValidator::sanitizeUploadName($name);
    }

    /**
     * Reject uploads whose detected MIME doesn't match an audio/video
     * media type. Throws InvalidArgumentException on mismatch.
     */
    private static function assertAudioVideoMime(string $tmpName): void
    {
        $finfo = @finfo_open(FILEINFO_MIME_TYPE);
        if ($finfo === false) {
            // finfo extension missing: degrade to extension-only check
            // (already done above). Don't fail closed — a misconfigured
            // PHP shouldn't take Whisper offline.
            return;
        }
        // finfo objects free themselves on scope exit since PHP 8.1, and
        // finfo_close() is deprecated as of 8.5 — letting $finfo fall out
        // of scope is both correct and forward-compatible.
        $detected = (string) @finfo_file($finfo, $tmpName);

        if ($detected === '') {
            return;
        }
        foreach (self::ALLOWED_MIME_PREFIXES as $prefix) {
            if (str_starts_with($detected, $prefix)) {
                return;
            }
        }
        throw new \InvalidArgumentException(
            'File content does not match an audio or video type (detected: ' . $detected . ')'
        );
    }

    public function routeDelete(array $fragments, array $params): JsonResponse
    {
        $frag1 = $this->frag($fragments, 1);
        $frag2 = $this->frag($fragments, 2);

        if ($frag1 === 'job' && $frag2 !== '') {
            try {
                return Response::success($this->formatCancelJob($frag2));
            } catch (\RuntimeException $e) {
                return Response::error($e->getMessage(), 404);
            }
        }

        return Response::error('Expected "job/{id}"', 404);
    }
}
