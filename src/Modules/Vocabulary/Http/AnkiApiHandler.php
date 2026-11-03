<?php

/**
 * Anki API Handler
 *
 * PHP version 8.2
 *
 * @category Lwt
 * @package  Lwt\Modules\Vocabulary\Http
 * @license  Unlicense <http://unlicense.org/>
 * @link     https://hugofara.github.io/lwt/developer/api
 */

declare(strict_types=1);

namespace Lwt\Modules\Vocabulary\Http;

use Lwt\Api\V1\Response;
use Lwt\Modules\Vocabulary\Application\Services\Anki\AnkiReviewPull;
use Lwt\Shared\Http\ApiRoutableInterface;
use Lwt\Shared\Infrastructure\Http\JsonResponse;

/**
 * Endpoints the browser uses while syncing with Anki over AnkiConnect (#264).
 *
 * POST /api/v1/anki/reviews: merge reviews read from the learner's Anki
 * collection into LWT's schedules. Body: `{"reviews": [{"term_id": 12,
 * "reviewed_at": 1767225600000, "ease": 3}, ...]}`, at most
 * {@see AnkiReviewPull::MAX_REVIEWS} per request.
 */
class AnkiApiHandler implements ApiRoutableInterface
{
    private AnkiReviewPull $pull;

    public function __construct(?AnkiReviewPull $pull = null)
    {
        $this->pull = $pull ?? AnkiReviewPull::default();
    }

    /**
     * {@inheritdoc}
     */
    public function routeGet(array $fragments, array $params): JsonResponse
    {
        return Response::error('Method Not Allowed', 405);
    }

    /**
     * {@inheritdoc}
     */
    public function routePost(array $fragments, array $params): JsonResponse
    {
        if (($fragments[1] ?? '') !== 'reviews') {
            return Response::error('Expected "reviews"', 404);
        }

        $reviews = $params['reviews'] ?? null;
        if (!is_array($reviews) || !array_is_list($reviews)) {
            return Response::error('"reviews" must be a list', 400);
        }

        return Response::success($this->pull->apply($reviews));
    }

    /**
     * {@inheritdoc}
     */
    public function routePut(array $fragments, array $params): JsonResponse
    {
        return Response::error('Method Not Allowed', 405);
    }

    /**
     * {@inheritdoc}
     */
    public function routeDelete(array $fragments, array $params): JsonResponse
    {
        return Response::error('Method Not Allowed', 405);
    }
}
