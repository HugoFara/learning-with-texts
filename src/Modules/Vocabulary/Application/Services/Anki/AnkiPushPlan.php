<?php

/**
 * What to write to Anki so it has LWT's reviews and schedule.
 *
 * PHP version 8.2
 *
 * @category Lwt
 * @package  Lwt\Modules\Vocabulary\Application\Services\Anki
 * @license  Unlicense <http://unlicense.org/>
 * @link     https://github.com/HugoFara/lwt
 */

declare(strict_types=1);

namespace Lwt\Modules\Vocabulary\Application\Services\Anki;

use DateTimeImmutable;
use Lwt\Modules\Review\Domain\Scheduling\HistoricReview;
use Lwt\Modules\Review\Domain\Scheduling\Rating;
use Lwt\Modules\Review\Domain\TermScheduleRepositoryInterface;
use Lwt\Modules\Review\Infrastructure\MySqlTermScheduleRepository;
use Lwt\Modules\Vocabulary\Domain\TermRepositoryInterface;
use Lwt\Modules\Vocabulary\Infrastructure\MySqlTermRepository;

/**
 * The server half of pushing reviews to Anki (#264, step 2).
 *
 * Runs after a pull, so LWT's history already holds every review from both
 * apps. For each Anki card, the browser sends the reviews Anki has; this
 * answers with what Anki is missing, as AnkiConnect calls for the browser to
 * make:
 *
 *  - `insertReviews` rows for LWT reviews absent from the card's revlog,
 *    shaped exactly as the .apkg exporter writes them (ApkgWriter);
 *  - the card's due date (`setDueDate`) and its interval, reps and lapses
 *    (`setSpecificValueOfCard`), so Anki's next session agrees with LWT.
 *
 * Measured against Anki 26.9 with AnkiConnect: `insertReviews` only appends
 * history, leaving the card untouched, so the schedule has to be set
 * separately; and AnkiConnect refuses to write FSRS memory state at all. With
 * FSRS on, Anki's memory state therefore lags until the learner reoptimises,
 * which recomputes it from the history this sends (see the sync doc).
 *
 * Cards whose history Anki already has get no action, so a second push right
 * after the first plans nothing.
 */
final class AnkiPushPlan
{
    /** Anki's default ease factor, in permille, as ApkgWriter writes it. */
    private const DEFAULT_EASE_FACTOR = 2500;

    /** revlog.type for a review-stage answer, as ApkgWriter writes it. */
    private const REVLOG_TYPE_REVIEW = 1;

    /** Most cards accepted in one request. */
    public const MAX_CARDS = 2000;

    public function __construct(
        private readonly TermRepositoryInterface $terms,
        private readonly TermScheduleRepositoryInterface $schedules,
    ) {
    }

    public static function default(): self
    {
        return new self(new MySqlTermRepository(), new MySqlTermScheduleRepository());
    }

    /**
     * Plan the writes that bring Anki up to LWT for these cards.
     *
     * @param list<mixed>            $cards Each `{term_id, card_id, reviews}`, where
     *                                      `reviews` is the card's revlog as
     *                                      `[[id, ease], ...]` (id in epoch ms)
     * @param DateTimeImmutable|null $now   Reference time for due dates
     *
     * @return array{
     *     reviews: list<list<int>>,
     *     cards: list<array{card_id: int, due_days: int, ivl: int, reps: int, lapses: int}>,
     *     skipped: int
     * } revlog rows for insertReviews (Anki's 9-integer shape), per-card
     *   schedule updates, and cards ignored (malformed, unknown, unschedulable)
     */
    public function plan(array $cards, ?DateTimeImmutable $now = null): array
    {
        $now ??= new DateTimeImmutable();
        $plan = ['reviews' => [], 'cards' => [], 'skipped' => max(0, count($cards) - self::MAX_CARDS)];

        foreach (array_map(self::parse(...), array_slice($cards, 0, self::MAX_CARDS)) as $card) {
            if ($card === null) {
                $plan['skipped']++;
                continue;
            }
            [$termId, $cardId, $ankiKeys, $ankiIds] = $card;

            $term = $this->terms->find($termId);
            if ($term === null || $term->status()->isSpecial()) {
                $plan['skipped']++;
                continue;
            }

            $history = $this->schedules->historyFor([$termId])[$termId] ?? [];
            $state = $this->schedules->find($termId);
            if ($history === [] || $state === null) {
                continue;
            }

            $missing = 0;
            $previousInterval = 0;
            $lapses = 0;
            foreach ($history as $entry) {
                if ($entry->grade === Rating::Again) {
                    $lapses++;
                }
                $key = (new HistoricReview($entry->reviewedAt, $entry->grade))->key();
                if (!isset($ankiKeys[$key])) {
                    $id = $entry->reviewedAt->getTimestamp() * 1000;
                    while (isset($ankiIds[$id])) {
                        $id++;
                    }
                    $ankiIds[$id] = true;
                    $plan['reviews'][] = [
                        $id,
                        $cardId,
                        -1,
                        $entry->grade->value,
                        $entry->scheduledDays,
                        $previousInterval,
                        self::DEFAULT_EASE_FACTOR,
                        0,
                        self::REVLOG_TYPE_REVIEW,
                    ];
                    $missing++;
                }
                $previousInterval = $entry->scheduledDays;
            }

            if ($missing === 0) {
                continue;
            }

            $last = $history[count($history) - 1];
            $plan['cards'][] = [
                'card_id' => $cardId,
                'due_days' => self::daysUntil($state->due, $now),
                'ivl' => max(1, $last->scheduledDays),
                'reps' => count($history),
                'lapses' => $lapses,
            ];
        }

        return $plan;
    }

    /**
     * Whole days from today to $due, 0 for today or overdue.
     *
     * Counted in calendar days, as Anki's setDueDate counts them; Anki's own
     * day starts at its rollover hour (4 a.m. by default), so a term due in
     * the small hours may land a day apart.
     */
    private static function daysUntil(DateTimeImmutable $due, DateTimeImmutable $now): int
    {
        $today = $now->setTime(0, 0);
        $dueDay = $due->setTimezone($now->getTimezone())->setTime(0, 0);

        return max(0, (int) $today->diff($dueDay)->format('%r%a'));
    }

    /**
     * Read one posted card, or null if it is not one we can use.
     *
     * @return array{0: int, 1: int, 2: array<string, true>, 3: array<int, true>}|null
     *         Term id, card id, the card's review keys, and its revlog ids
     */
    private static function parse(mixed $card): ?array
    {
        if (!is_array($card)) {
            return null;
        }
        $termId = filter_var($card['term_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        $cardId = filter_var($card['card_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        $reviews = $card['reviews'] ?? [];
        if ($termId === false || $cardId === false || !is_array($reviews)) {
            return null;
        }

        $keys = [];
        $ids = [];
        foreach ($reviews as $review) {
            if (!is_array($review) || !isset($review[0], $review[1])) {
                continue;
            }
            $id = filter_var($review[0], FILTER_VALIDATE_INT);
            $ease = filter_var($review[1], FILTER_VALIDATE_INT);
            if ($id === false || $ease === false) {
                continue;
            }
            $ids[$id] = true;
            $rating = Rating::tryFrom($ease);
            if ($rating !== null) {
                $at = new DateTimeImmutable('@' . intdiv($id, 1000));
                $keys[(new HistoricReview($at, $rating))->key()] = true;
            }
        }

        return [$termId, $cardId, $keys, $ids];
    }
}
