<?php

/**
 * Bring reviews done in Anki into LWT's schedules.
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
use DateTimeZone;
use Lwt\Modules\Review\Application\UseCases\MergeReviewHistory;
use Lwt\Modules\Review\Domain\Scheduling\HistoricReview;
use Lwt\Modules\Review\Domain\Scheduling\Rating;
use Lwt\Modules\Vocabulary\Domain\TermRepositoryInterface;
use Lwt\Modules\Vocabulary\Infrastructure\MySqlTermRepository;

/**
 * The server half of pulling reviews from Anki (#264, step 1).
 *
 * The browser reads the learner's Anki collection through AnkiConnect, maps
 * each card back to its term through the note's LwtId field, and posts the
 * reviews here. Each term's reviews are merged into its history by
 * {@see MergeReviewHistory}: reviews LWT already holds are recognised and
 * dropped, so pulling the same collection twice changes nothing, and reviews
 * that interleave with LWT's own are put in their place.
 *
 * Only terms the current user owns are touched, and only schedulable ones:
 * ignored and well-known terms are not in the review queue, and a review in
 * Anki is not a reason to put them back.
 */
final class AnkiReviewPull
{
    /**
     * Most reviews accepted in one request, to bound the work per call. The
     * browser sends a large collection in several batches.
     */
    public const MAX_REVIEWS = 5000;

    public function __construct(
        private readonly TermRepositoryInterface $terms,
        private readonly MergeReviewHistory $merge,
    ) {
    }

    public static function default(): self
    {
        return new self(new MySqlTermRepository(), new MergeReviewHistory());
    }

    /**
     * Merge a batch of Anki reviews.
     *
     * @param list<mixed> $rows Each `{term_id, reviewed_at, ease}`: the LWT
     *                          term id, Anki's revlog id (epoch milliseconds,
     *                          which is when the review happened) and the
     *                          button pressed, 1 Again to 4 Easy
     *
     * @return array{terms: int, applied: int, duplicates: int, rebuilt: int, skipped: int}
     *         Terms that changed, reviews new to LWT, reviews it already had,
     *         terms whose later history was recomputed, and reviews ignored
     *         (malformed, unknown or unschedulable term, or a grade-less row
     *         such as Anki's "set due date")
     */
    public function apply(array $rows): array
    {
        $byTerm = [];
        $skipped = 0;
        $parsed = array_map(self::parse(...), array_slice($rows, 0, self::MAX_REVIEWS));
        foreach ($parsed as $review) {
            if ($review === null) {
                $skipped++;
                continue;
            }
            $byTerm[$review[0]][] = $review[1];
        }
        $skipped += max(0, count($rows) - self::MAX_REVIEWS);

        $summary = ['terms' => 0, 'applied' => 0, 'duplicates' => 0, 'rebuilt' => 0, 'skipped' => $skipped];
        foreach ($byTerm as $termId => $reviews) {
            $term = $this->terms->find($termId);
            if ($term === null || $term->status()->isSpecial()) {
                $summary['skipped'] += count($reviews);
                continue;
            }

            $result = $this->merge->execute($termId, $reviews);
            $summary['applied'] += $result['applied'];
            $summary['duplicates'] += $result['duplicates'];
            if ($result['applied'] > 0) {
                $summary['terms']++;
            }
            if ($result['rebuilt']) {
                $summary['rebuilt']++;
            }
        }

        return $summary;
    }

    /**
     * Read one posted review, or null if it is not one we can use.
     *
     * @return array{0: int, 1: HistoricReview}|null Term id and the review
     */
    private static function parse(mixed $row): ?array
    {
        if (!is_array($row)) {
            return null;
        }

        $termId = filter_var($row['term_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        $reviewedAt = filter_var($row['reviewed_at'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        $ease = filter_var($row['ease'] ?? null, FILTER_VALIDATE_INT);
        if ($termId === false || $reviewedAt === false || $ease === false) {
            return null;
        }

        // Anki writes ease 0 for rows that are not answers ("set due date",
        // "forget"); those carry no grade to fold.
        $rating = Rating::tryFrom($ease);
        if ($rating === null) {
            return null;
        }

        // A revlog id is the review time in milliseconds. review_log keeps
        // whole seconds in the server's zone, exactly as ApkgReader stores a
        // review read from a file, so the two paths agree on every review.
        $when = (new DateTimeImmutable('@' . intdiv($reviewedAt, 1000)))
            ->setTimezone(new DateTimeZone(date_default_timezone_get()));

        return [$termId, new HistoricReview($when, $rating)];
    }
}
