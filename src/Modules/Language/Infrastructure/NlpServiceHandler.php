<?php

/**
 * NlpServiceHandler for NLP microservice integration.
 *
 * PHP version 8.2
 *
 * @category Infrastructure
 * @package  Lwt\Modules\Language\Infrastructure
 * @author   HugoFara <git@hugofara.net>
 * @license  Unlicense <http://unlicense.org/>
 * @link     https://hugofara.github.io/lwt/developer/api
 * @since    3.0.0
 */

declare(strict_types=1);

namespace Lwt\Modules\Language\Infrastructure;

use Lwt\Api\V1\Response;
use Lwt\Shared\Http\ApiRoutableInterface;
use Lwt\Shared\Http\ApiRoutableTrait;
use Lwt\Shared\Infrastructure\Bootstrap\EnvLoader;
use Lwt\Shared\Infrastructure\Globals;
use Lwt\Shared\Infrastructure\Http\JsonResponse;

/**
 * Handler for communicating with the Python NLP microservice.
 *
 * Provides text-to-speech (Piper TTS), text parsing (MeCab/Jieba),
 * and voice management functionality.
 */
class NlpServiceHandler implements ApiRoutableInterface
{
    use ApiRoutableTrait;

    private string $baseUrl;
    private int $timeout;
    private NlpHttpClient $httpClient;

    public function __construct(?NlpHttpClient $httpClient = null)
    {
        $this->baseUrl = EnvLoader::get('NLP_SERVICE_URL', 'http://nlp:8000') ?? 'http://nlp:8000';
        $this->timeout = 30;
        $this->httpClient = $httpClient ?? new StreamNlpHttpClient();
    }

    /**
     * Check if the NLP service is available.
     */
    public function isAvailable(): bool
    {
        $response = $this->httpClient->request($this->baseUrl . '/health', 'GET', null, 5);
        return $response !== null;
    }

    /**
     * Synthesize speech using Piper TTS.
     *
     * @param string $text The text to speak
     * @param string $voiceId The Piper voice ID
     * @return string|null Base64 data URL of WAV audio, or null on failure
     */
    public function speak(string $text, string $voiceId): ?string
    {
        $payload = json_encode(['text' => $text, 'voice_id' => $voiceId]);
        if ($payload === false) {
            return null;
        }

        $audio = $this->httpClient->request(
            $this->baseUrl . '/tts/speak',
            'POST',
            $payload,
            $this->timeout,
            true
        );

        if ($audio === null) {
            return null;
        }

        // Return as base64 data URL
        return 'data:audio/wav;base64,' . base64_encode($audio);
    }

    /**
     * Get list of all voices (installed and available for download).
     *
     * @return array List of voice objects
     */
    public function getVoices(): array
    {
        $response = $this->httpClient->request($this->baseUrl . '/tts/voices', 'GET', null, 10);
        if ($response === null) {
            return [];
        }

        /** @var array{voices?: array}|null $data */
        $data = json_decode($response, true);
        return is_array($data) && isset($data['voices']) ? $data['voices'] : [];
    }

    /**
     * Get list of installed voices only.
     *
     * @return array List of installed voice objects
     */
    public function getInstalledVoices(): array
    {
        $response = $this->httpClient->request($this->baseUrl . '/tts/voices/installed', 'GET', null, 10);
        if ($response === null) {
            return [];
        }

        /** @var array{voices?: array}|null $data */
        $data = json_decode($response, true);
        return is_array($data) && isset($data['voices']) ? $data['voices'] : [];
    }

    /**
     * Download a voice from the catalog.
     *
     * @param string $voiceId The voice ID to download
     * @return bool True on success
     */
    public function downloadVoice(string $voiceId): bool
    {
        $payload = json_encode(['voice_id' => $voiceId]);
        if ($payload === false) {
            return false;
        }

        // Downloads can take time
        $response = $this->httpClient->request(
            $this->baseUrl . '/tts/voices/download',
            'POST',
            $payload,
            300
        );
        return $response !== null;
    }

    /**
     * Delete an installed voice.
     *
     * @param string $voiceId The voice ID to delete
     * @return bool True on success
     */
    public function deleteVoice(string $voiceId): bool
    {
        $response = $this->httpClient->request(
            $this->baseUrl . '/tts/voices/' . urlencode($voiceId),
            'DELETE',
            null,
            10
        );
        return $response !== null;
    }

    /**
     * Parse text using MeCab or Jieba.
     *
     * @param string $text The text to parse
     * @param string $parser Parser type: 'mecab' or 'jieba'
     * @return array<array-key, mixed>|null Parsed result with sentences and tokens, or null on failure
     */
    public function parse(string $text, string $parser): ?array
    {
        $payload = json_encode(['text' => $text, 'parser' => $parser]);
        if ($payload === false) {
            return null;
        }

        $response = $this->httpClient->request(
            $this->baseUrl . '/parse/',
            'POST',
            $payload,
            $this->timeout
        );
        if ($response === null) {
            return null;
        }

        /** @var array<array-key, mixed>|null $result */
        $result = json_decode($response, true);
        return is_array($result) ? $result : null;
    }

    /**
     * Get list of available parsers.
     *
     * @return array List of parser objects
     */
    public function getAvailableParsers(): array
    {
        $response = $this->httpClient->request($this->baseUrl . '/parse/available', 'GET', null, 10);
        if ($response === null) {
            return [];
        }

        /** @var array{parsers?: array}|null $data */
        $data = json_decode($response, true);
        return is_array($data) && isset($data['parsers']) ? $data['parsers'] : [];
    }

    // =========================================================================
    // Lemmatization Methods
    // =========================================================================

    /**
     * Lemmatize a single word using the NLP service.
     *
     * @param string $word         The word to lemmatize
     * @param string $languageCode ISO language code (e.g., 'en', 'de')
     * @param string $lemmatizer   Lemmatizer type: 'spacy' (default)
     *
     * @return string|null The lemma, or null if word is already base form or error
     */
    public function lemmatize(string $word, string $languageCode, string $lemmatizer = 'spacy'): ?string
    {
        $payload = json_encode([
            'word' => $word,
            'language' => $languageCode,
            'lemmatizer' => $lemmatizer,
        ]);
        if ($payload === false) {
            return null;
        }

        $response = $this->httpClient->request(
            $this->baseUrl . '/lemmatize/',
            'POST',
            $payload,
            $this->timeout,
            true
        );
        if ($response === null) {
            return null;
        }

        /** @var array{lemma?: string}|null $data */
        $data = json_decode($response, true);
        return is_array($data) && isset($data['lemma']) ? $data['lemma'] : null;
    }

    /**
     * Lemmatize multiple words using the NLP service.
     *
     * @param list<string> $words        Words to lemmatize
     * @param string       $languageCode ISO language code
     * @param string       $lemmatizer   Lemmatizer type: 'spacy' (default)
     *
     * @return array<string, string|null> Mapping of words to lemmas
     */
    public function lemmatizeBatch(array $words, string $languageCode, string $lemmatizer = 'spacy'): array
    {
        if (empty($words)) {
            return [];
        }

        $payload = json_encode([
            'words' => $words,
            'language' => $languageCode,
            'lemmatizer' => $lemmatizer,
        ]);
        if ($payload === false) {
            /** @var array<string, null> */
            return array_fill_keys($words, null);
        }

        $response = $this->httpClient->request(
            $this->baseUrl . '/lemmatize/batch',
            'POST',
            $payload,
            $this->timeout,
            true
        );
        if ($response === null) {
            /** @var array<string, null> */
            return array_fill_keys($words, null);
        }

        /** @var array{results?: array<string, string|null>}|null $data */
        $data = json_decode($response, true);
        if (is_array($data) && isset($data['results'])) {
            return $data['results'];
        }
        /** @var array<string, null> */
        return array_fill_keys($words, null);
    }

    /**
     * Get list of available lemmatizers and their supported languages.
     *
     * @return array<array-key, mixed> Lemmatizer information
     */
    public function getAvailableLemmatizers(): array
    {
        $response = $this->httpClient->request($this->baseUrl . '/lemmatize/available', 'GET', null, 10);
        if ($response === null) {
            return [];
        }

        /** @var array<array-key, mixed>|null $result */
        $result = json_decode($response, true);
        return is_array($result) ? $result : [];
    }

    /**
     * Check if a language is supported for lemmatization.
     *
     * @param string $languageCode ISO language code
     *
     * @return array<array-key, mixed> Language support information
     */
    public function checkLemmatizationSupport(string $languageCode): array
    {
        $response = $this->httpClient->request(
            $this->baseUrl . '/lemmatize/languages/' . urlencode($languageCode),
            'GET',
            null,
            10
        );

        if ($response === null) {
            return [
                'language' => $languageCode,
                'spacy' => ['supported' => false, 'installed' => false, 'model' => null]
            ];
        }

        /** @var array<array-key, mixed>|null $result */
        $result = json_decode($response, true);
        return is_array($result) ? $result : [];
    }

    // =========================================================================
    // API Routing Methods
    // =========================================================================

    /**
     * Reject the request unless the caller may manage shared voice models.
     *
     * Voices live on the NLP service and are shared by every user, so adding
     * or removing one is a server-level operation, not a per-user one. The
     * API's own gate only checks authentication, which would let any logged-in
     * user delete a voice others depend on or fill the disk with downloads.
     * Mirrors the inline admin check ApiV1 already applies to `media-files`,
     * and — like AdminMiddleware — is a no-op when multi-user mode is off.
     *
     * @return JsonResponse|null Error response, or null when allowed
     */
    private function denyIfNotAdmin(): ?JsonResponse
    {
        if (Globals::isMultiUserEnabled() && !Globals::isCurrentUserAdmin()) {
            return Response::error('Permission denied: admin only', 403);
        }
        return null;
    }

    public function routeGet(array $fragments, array $params): JsonResponse
    {
        $frag1 = $this->frag($fragments, 1);
        $frag2 = $this->frag($fragments, 2);

        switch ($frag1) {
            case 'voices':
                if ($frag2 === 'installed') {
                    return Response::success(['voices' => $this->getInstalledVoices()]);
                }
                return Response::success(['voices' => $this->getVoices()]);
            default:
                return Response::error('Expected "voices"', 404);
        }
    }

    public function routePost(array $fragments, array $params): JsonResponse
    {
        $frag1 = $this->frag($fragments, 1);
        $frag2 = $this->frag($fragments, 2);

        switch ($frag1) {
            case 'speak':
                $text = (string) ($params['text'] ?? '');
                $voiceId = (string) ($params['voice_id'] ?? '');
                if ($text === '' || $voiceId === '') {
                    return Response::error('text and voice_id are required', 400);
                }
                $audioData = $this->speak($text, $voiceId);
                if ($audioData === null) {
                    return Response::error('TTS service unavailable or synthesis failed', 503);
                }
                return Response::success(['audio' => $audioData]);

            case 'voices':
                if ($frag2 === 'download') {
                    $denied = $this->denyIfNotAdmin();
                    if ($denied !== null) {
                        return $denied;
                    }
                    $voiceId = (string) ($params['voice_id'] ?? '');
                    if ($voiceId === '') {
                        return Response::error('voice_id is required', 400);
                    }
                    $success = $this->downloadVoice($voiceId);
                    if (!$success) {
                        return Response::error('Voice download failed', 500);
                    }
                    return Response::success(['success' => true, 'voice_id' => $voiceId]);
                }
                return Response::error('Expected "download"', 404);

            default:
                return Response::error('Expected "speak" or "voices/download"', 404);
        }
    }

    public function routeDelete(array $fragments, array $params): JsonResponse
    {
        $frag1 = $this->frag($fragments, 1);
        $frag2 = $this->frag($fragments, 2);

        if ($frag1 === 'voices' && $frag2 !== '') {
            $denied = $this->denyIfNotAdmin();
            if ($denied !== null) {
                return $denied;
            }
            $success = $this->deleteVoice($frag2);
            if (!$success) {
                return Response::error('Voice not found or deletion failed', 404);
            }
            return Response::success(['success' => true]);
        }

        return Response::error('Expected "voices/{id}"', 404);
    }
}
