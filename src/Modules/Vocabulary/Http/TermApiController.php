<?php

/**
 * Term API Controller
 *
 * PHP version 8.2
 *
 * @category Lwt
 * @package  Lwt\Modules\Vocabulary\Http
 * @author   HugoFara <git@hugofara.net>
 * @license  Unlicense <http://unlicense.org/>
 * @link     https://hugofara.github.io/lwt/developer/api
 * @since    3.0.0
 */

declare(strict_types=1);

namespace Lwt\Modules\Vocabulary\Http;

use Lwt\Shared\Infrastructure\Http\InputValidator;
use Lwt\Shared\Infrastructure\Http\JsonResponse;
use Lwt\Modules\Vocabulary\Application\VocabularyFacade;

/**
 * Controller for JSON REST API endpoints.
 *
 * Handles:
 * - GET /vocabulary/term - Get term as JSON
 * - POST /vocabulary/term - Create term via JSON
 * - PUT /vocabulary/term - Update term via JSON
 * - DELETE /vocabulary/term/{wid} - Delete term
 *
 * @since 3.0.0
 */
class TermApiController
{
    /**
     * Vocabulary facade.
     */
    private VocabularyFacade $facade;

    /**
     * Constructor.
     *
     * @param VocabularyFacade|null $facade Vocabulary facade
     */
    public function __construct(?VocabularyFacade $facade = null)
    {
        $this->facade = $facade ?? new VocabularyFacade();
    }

    /**
     * Get term data as JSON.
     *
     * @param array<string, string> $params Route parameters
     *
     * @return void
     */
    public function getTermJson(array $params): void
    {
        $termId = InputValidator::getInt('wid', 0) ?? 0;

        if ($termId === 0) {
            JsonResponse::error('Term ID required', 400)->send();
            return;
        }

        $term = $this->facade->getTerm($termId);

        if ($term === null) {
            JsonResponse::notFound('Term not found')->send();
            return;
        }

        JsonResponse::success([
            'id' => $term->id()->toInt(),
            'text' => $term->text(),
            'textLc' => $term->textLowercase(),
            'translation' => $term->translation(),
            'romanization' => $term->romanization(),
            'sentence' => $term->sentence(),
            'status' => $term->status()->toInt(),
            'langId' => $term->languageId(),
        ])->send();
    }

    /**
     * Create term via AJAX.
     *
     * @param array<string, string> $params Route parameters
     *
     * @return void
     */
    public function createJson(array $params): void
    {
        $langId = InputValidator::getInt('lgid', 0) ?? 0;
        $text = InputValidator::getString('text');
        $status = InputValidator::getInt('status', 1) ?? 1;
        $translation = InputValidator::getString('translation');
        $romanization = InputValidator::getString('romanization');
        $sentence = InputValidator::getString('sentence');

        if ($langId === 0 || $text === '') {
            JsonResponse::error('Language ID and text required', 400)->send();
            return;
        }

        try {
            $term = $this->facade->createTerm(
                $langId,
                $text,
                $status,
                $translation ?: '*',
                $romanization,
                $sentence
            );

            JsonResponse::success([
                'success' => true,
                'id' => $term->id()->toInt(),
                'text' => $term->text(),
                'textLc' => $term->textLowercase(),
            ])->send();
        } catch (\Exception $e) {
            JsonResponse::error($e->getMessage(), 500)->send();
        }
    }

    /**
     * Update term via AJAX.
     *
     * @param array<string, string> $params Route parameters
     *
     * @return void
     */
    public function updateJson(array $params): void
    {
        $termId = InputValidator::getInt('wid', 0) ?? 0;
        $translation = InputValidator::getString('translation');
        $romanization = InputValidator::getString('romanization');
        $sentence = InputValidator::getString('sentence');
        $status = InputValidator::getInt('status', 0) ?? 0;

        if ($termId === 0) {
            JsonResponse::error('Term ID required', 400)->send();
            return;
        }

        try {
            $statusVal = $status !== 0 ? $status : null;

            $term = $this->facade->updateTerm(
                $termId,
                $statusVal,
                $translation !== '' ? $translation : null,
                $sentence !== '' ? $sentence : null,
                null, // notes
                $romanization !== '' ? $romanization : null
            );

            JsonResponse::success([
                'success' => true,
                'id' => $term->id()->toInt(),
            ])->send();
        } catch (\Exception $e) {
            JsonResponse::error($e->getMessage(), 500)->send();
        }
    }

    /**
     * Delete term.
     *
     * @param array<string, string> $params Route parameters
     *
     * @return void
     */
    public function delete(array $params): void
    {
        $termId = InputValidator::getInt('wid', 0) ?? 0;

        if ($termId === 0) {
            JsonResponse::error('Term ID required', 400)->send();
            return;
        }

        $result = $this->facade->deleteTerm($termId);

        JsonResponse::success(['deleted' => $result])->send();
    }
}
