<?php

declare(strict_types=1);

namespace Tests\Backend\Modules\Vocabulary\Application\Services\Anki;

use DateTimeImmutable;
use Lwt\Modules\Review\Application\UseCases\RecordScheduledReview;
use Lwt\Modules\Review\Domain\Scheduling\Fsrs6Scheduler;
use Lwt\Modules\Review\Domain\Scheduling\Rating;
use Lwt\Modules\Vocabulary\Application\Services\Anki\AnkiPushPlan;
use Lwt\Modules\Vocabulary\Domain\Term;
use Lwt\Modules\Vocabulary\Domain\TermRepositoryInterface;
use Lwt\Modules\Vocabulary\Domain\ValueObject\TermStatus;
use Lwt\Tests\Modules\Vocabulary\Application\Services\Anki\InMemoryTermScheduleRepository;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Planning what to write to Anki so it has LWT's reviews (#264, step 2).
 */
#[CoversClass(AnkiPushPlan::class)]
final class AnkiPushPlanTest extends TestCase
{
    private const CARD = 1700000000123;

    private InMemoryTermScheduleRepository $schedules;

    protected function setUp(): void
    {
        $this->schedules = new InMemoryTermScheduleRepository();
    }

    public function testReviewsAnkiLacksBecomeRevlogRowsShapedLikeTheExport(): void
    {
        $this->reviewedHere(7, ['2026-02-01 10:00:00' => Rating::Good, '2026-02-04 10:00:00' => Rating::Again]);

        $plan = $this->planFor([7 => TermStatus::LEARNING_3])->plan(
            [['term_id' => 7, 'card_id' => self::CARD, 'reviews' => []]],
            new DateTimeImmutable('2026-02-04 12:00:00')
        );

        self::assertCount(2, $plan['reviews']);
        [$first, $second] = $plan['reviews'];
        $history = $this->schedules->history[7];
        // id, cid, usn, ease, ivl, lastIvl, factor, time, type
        self::assertSame(
            [(new DateTimeImmutable('2026-02-01 10:00:00'))->getTimestamp() * 1000, self::CARD, -1, 3,
                $history[0]->scheduledDays, 0, 2500, 0, 1],
            $first
        );
        self::assertSame(1, $second[3]);
        self::assertSame($history[0]->scheduledDays, $second[5], 'lastIvl is the previous interval');
    }

    public function testTheCardGetsLwtsScheduleAfterThePush(): void
    {
        $this->reviewedHere(7, ['2026-02-01 10:00:00' => Rating::Good, '2026-02-04 10:00:00' => Rating::Again]);
        $state = $this->schedules->states[7];

        $plan = $this->planFor([7 => TermStatus::LEARNING_3])->plan(
            [['term_id' => 7, 'card_id' => self::CARD, 'reviews' => []]],
            new DateTimeImmutable('2026-02-04 12:00:00')
        );

        self::assertSame([[
            'card_id' => self::CARD,
            'due_days' => (int) (new DateTimeImmutable('2026-02-04'))->diff($state->due->setTime(0, 0))->format('%a'),
            'ivl' => $this->schedules->history[7][1]->scheduledDays,
            'reps' => 2,
            'lapses' => 1,
        ]], $plan['cards']);
    }

    public function testOnlyTheReviewsAnkiDoesNotHaveAreSent(): void
    {
        $this->reviewedHere(7, ['2026-02-01 10:00:00' => Rating::Good, '2026-02-04 10:00:00' => Rating::Good]);
        $ankiHas = [[(new DateTimeImmutable('2026-02-01 10:00:00'))->getTimestamp() * 1000 + 512, 3]];

        $plan = $this->planFor([7 => TermStatus::LEARNING_3])->plan(
            [['term_id' => 7, 'card_id' => self::CARD, 'reviews' => $ankiHas]]
        );

        self::assertCount(1, $plan['reviews']);
        self::assertSame((new DateTimeImmutable('2026-02-04 10:00:00'))->getTimestamp() * 1000, $plan['reviews'][0][0]);
    }

    public function testACardAnkiIsUpToDateOnPlansNothing(): void
    {
        $this->reviewedHere(7, ['2026-02-01 10:00:00' => Rating::Good]);
        $ankiHas = [[(new DateTimeImmutable('2026-02-01 10:00:00'))->getTimestamp() * 1000, 3]];

        $plan = $this->planFor([7 => TermStatus::LEARNING_3])->plan(
            [['term_id' => 7, 'card_id' => self::CARD, 'reviews' => $ankiHas]]
        );

        self::assertSame(['reviews' => [], 'cards' => [], 'skipped' => 0], $plan);
    }

    public function testARevlogIdAlreadyTakenInAnkiIsSteppedPast(): void
    {
        // A different review of Anki's in the same millisecond as ours would
        // make insertReviews fail the whole batch.
        $this->reviewedHere(7, ['2026-02-01 10:00:00' => Rating::Good]);
        $taken = (new DateTimeImmutable('2026-02-01 10:00:00'))->getTimestamp() * 1000;

        $plan = $this->planFor([7 => TermStatus::LEARNING_3])->plan(
            [['term_id' => 7, 'card_id' => self::CARD, 'reviews' => [[$taken, 1]]]]
        );

        self::assertSame($taken + 1, $plan['reviews'][0][0]);
    }

    public function testUnknownUnschedulableAndMalformedCardsAreSkipped(): void
    {
        $plan = $this->planFor([1 => TermStatus::IGNORED])->plan([
            ['term_id' => 1, 'card_id' => self::CARD, 'reviews' => []],
            ['term_id' => 99, 'card_id' => self::CARD, 'reviews' => []],
            ['term_id' => 'x', 'card_id' => self::CARD],
            'nope',
        ]);

        self::assertSame(4, $plan['skipped']);
        self::assertSame([], $plan['reviews']);
    }

    public function testATermNeverReviewedInLwtHasNothingToSend(): void
    {
        $plan = $this->planFor([7 => TermStatus::LEARNING_3])->plan(
            [['term_id' => 7, 'card_id' => self::CARD, 'reviews' => []]]
        );

        self::assertSame(['reviews' => [], 'cards' => [], 'skipped' => 0], $plan);
    }

    /**
     * @param array<string, Rating> $reviews Grade by review time
     */
    private function reviewedHere(int $termId, array $reviews): void
    {
        $record = new RecordScheduledReview(new Fsrs6Scheduler(), $this->schedules);
        foreach ($reviews as $at => $rating) {
            $record->execute($termId, $rating, new DateTimeImmutable($at));
        }
    }

    /**
     * @param array<int, int> $statusByTermId
     */
    private function planFor(array $statusByTermId): AnkiPushPlan
    {
        $terms = $this->createMock(TermRepositoryInterface::class);
        $terms->method('find')->willReturnCallback(
            static function (int $id) use ($statusByTermId): ?Term {
                $status = $statusByTermId[$id] ?? null;
                if ($status === null) {
                    return null;
                }
                $now = new DateTimeImmutable();

                return Term::reconstitute(
                    id: $id,
                    languageId: 1,
                    text: 'hola',
                    textLowercase: 'hola',
                    lemma: null,
                    lemmaLc: null,
                    status: $status,
                    translation: 'hello',
                    sentence: '',
                    notes: '',
                    romanization: '',
                    wordCount: 1,
                    createdAt: $now,
                    statusChangedAt: $now,
                );
            }
        );

        return new AnkiPushPlan($terms, $this->schedules);
    }
}
