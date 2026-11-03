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
use Lwt\Modules\Vocabulary\Application\Services\Anki\AnkiPushPlan;
use Lwt\Modules\Vocabulary\Application\Services\Anki\AnkiReviewPull;
use Lwt\Modules\Vocabulary\Application\Services\Anki\Sync\AnkiNoteSync;
use Lwt\Shared\Http\ApiRoutableInterface;
use Lwt\Shared\Infrastructure\Http\JsonResponse;

/**
 * Endpoints the browser uses while syncing with Anki over AnkiConnect (#264).
 *
 * POST /api/v1/anki/reviews: merge reviews read from the learner's Anki
 * collection into LWT's schedules. Body: `{"reviews": [{"term_id": 12,
 * "reviewed_at": 1767225600000, "ease": 3}, ...]}`, at most
 * {@see AnkiReviewPull::MAX_REVIEWS} per request.
 *
 * POST /api/v1/anki/push-plan: after a pull, what to write to Anki so it has
 * LWT's reviews and schedule. Body: `{"cards": [{"term_id": 12, "card_id":
 * 1767..., "reviews": [[1767225600000, 3], ...]}, ...]}`, each card's revlog as
 * Anki holds it. Answers with AnkiConnect-ready rows; the browser makes the
 * calls ({@see AnkiPushPlan}).
 *
 * POST /api/v1/anki/notes-plan and /anki/notes-commit: the note sync
 * ({@see AnkiNoteSync}). Both take `{"notes": [...], "decks": [...]}`, the
 * learner's "LWT Term" notes and Anki's deck names; commit also takes
 * `added` (notes the browser created) and `confirm_deletions`.
 */
class AnkiApiHandler implements ApiRoutableInterface
{
    private AnkiReviewPull $pull;
    private AnkiPushPlan $push;
    private ?AnkiNoteSync $notes;

    public function __construct(
        ?AnkiReviewPull $pull = null,
        ?AnkiPushPlan $push = null,
        ?AnkiNoteSync $notes = null
    ) {
        $this->pull = $pull ?? AnkiReviewPull::default();
        $this->push = $push ?? AnkiPushPlan::default();
        $this->notes = $notes;
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
        $route = $fragments[1] ?? '';
        if ($route === 'notes-plan' || $route === 'notes-commit') {
            return $this->routeNotes($route, $params);
        }
        if ($route === 'push-plan') {
            $cards = $params['cards'] ?? null;
            if (!is_array($cards) || !array_is_list($cards)) {
                return Response::error('"cards" must be a list', 400);
            }

            return Response::success($this->push->plan($cards));
        }
        if ($route !== 'reviews') {
            return Response::error('Expected "reviews" or "push-plan"', 404);
        }

        $reviews = $params['reviews'] ?? null;
        if (!is_array($reviews) || !array_is_list($reviews)) {
            return Response::error('"reviews" must be a list', 400);
        }

        return Response::success($this->pull->apply($reviews));
    }

    /**
     * @param array<string, mixed> $params
     */
    private function routeNotes(string $route, array $params): JsonResponse
    {
        $notes = $params['notes'] ?? null;
        $decks = $params['decks'] ?? null;
        if (!is_array($notes) || !array_is_list($notes) || !is_array($decks) || !array_is_list($decks)) {
            return Response::error('"notes" and "decks" must be lists', 400);
        }
        $deckNames = array_values(array_filter($decks, 'is_string'));
        $sync = $this->notes ??= AnkiNoteSync::default();

        if ($route === 'notes-plan') {
            return Response::success($sync->plan($notes, $deckNames));
        }

        $added = $params['added'] ?? [];
        if (!is_array($added) || !array_is_list($added)) {
            return Response::error('"added" must be a list', 400);
        }

        $confirm = ($params['confirm_deletions'] ?? false) === true;

        return Response::success($sync->commit($notes, $deckNames, $added, $confirm));
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
