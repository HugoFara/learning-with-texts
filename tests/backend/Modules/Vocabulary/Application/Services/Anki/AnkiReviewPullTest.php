<?php

declare(strict_types=1);

namespace Tests\Backend\Modules\Vocabulary\Application\Services\Anki;

use DateTimeImmutable;
use Lwt\Modules\Review\Application\UseCases\MergeReviewHistory;
use Lwt\Modules\Review\Domain\Scheduling\Fsrs6Scheduler;
use Lwt\Modules\Review\Domain\Scheduling\Rating;
use Lwt\Modules\Vocabulary\Application\Services\Anki\AnkiReviewPull;
use Lwt\Modules\Vocabulary\Domain\Term;
use Lwt\Modules\Vocabulary\Domain\TermRepositoryInterface;
use Lwt\Modules\Vocabulary\Domain\ValueObject\TermStatus;
use Lwt\Tests\Modules\Vocabulary\Application\Services\Anki\InMemoryTermScheduleRepository;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Pulling reviews from Anki over AnkiConnect (#264, step 1).
 */
#[CoversClass(AnkiReviewPull::class)]
final class AnkiReviewPullTest extends TestCase
{
    private InMemoryTermScheduleRepository $schedules;

    protected function setUp(): void
    {
        $this->schedules = new InMemoryTermScheduleRepository();
    }

    public function testReviewsAreMergedPerTerm(): void
    {
        $pull = $this->pullFor([7 => TermStatus::LEARNING_3, 8 => TermStatus::LEARNING_2]);

        $result = $pull->apply([
            $this->row(7, '2026-02-01 10:00:00', 3),
            $this->row(8, '2026-02-02 10:00:00', 1),
            $this->row(7, '2026-02-05 10:00:00', 4),
        ]);

        self::assertSame(['terms' => 2, 'applied' => 3, 'duplicates' => 0, 'rebuilt' => 0, 'skipped' => 0], $result);
        self::assertCount(2, $this->schedules->history[7]);
        self::assertSame(Rating::Again, $this->schedules->history[8][0]->grade);
    }

    public function testPullingTheSameCollectionTwiceChangesNothing(): void
    {
        $pull = $this->pullFor([7 => TermStatus::LEARNING_3]);
        $rows = [$this->row(7, '2026-02-01 10:00:00', 3), $this->row(7, '2026-02-05 10:00:00', 3)];

        $pull->apply($rows);
        $again = $pull->apply($rows);

        self::assertSame(['terms' => 0, 'applied' => 0, 'duplicates' => 2, 'rebuilt' => 0, 'skipped' => 0], $again);
        self::assertCount(2, $this->schedules->history[7]);
    }

    public function testTheRevlogIdIsTheReviewTime(): void
    {
        $pull = $this->pullFor([7 => TermStatus::LEARNING_3]);

        $pull->apply([['term_id' => 7, 'reviewed_at' => 1770000000123, 'ease' => 3]]);

        self::assertSame(
            1770000000,
            (int) $this->schedules->history[7][0]->reviewedAt->format('U')
        );
    }

    public function testUnknownIgnoredAndWellKnownTermsAreSkipped(): void
    {
        $pull = $this->pullFor([1 => TermStatus::IGNORED, 2 => TermStatus::WELL_KNOWN]);

        $result = $pull->apply([
            $this->row(1, '2026-02-01 10:00:00', 3),
            $this->row(2, '2026-02-01 10:00:00', 3),
            $this->row(99, '2026-02-01 10:00:00', 3),
        ]);

        self::assertSame(3, $result['skipped']);
        self::assertSame(0, $result['applied']);
        self::assertSame([], $this->schedules->history);
    }

    public function testRowsThatAreNotAnswersAreSkipped(): void
    {
        // Anki's "set due date" and "forget" rows carry ease 0.
        $pull = $this->pullFor([7 => TermStatus::LEARNING_3]);

        $result = $pull->apply([
            $this->row(7, '2026-02-01 10:00:00', 0),
            ['term_id' => 'seven', 'reviewed_at' => 1770000000000, 'ease' => 3],
            ['term_id' => 7, 'ease' => 3],
            'not a row',
        ]);

        self::assertSame(4, $result['skipped']);
        self::assertSame(0, $result['applied']);
    }

    public function testABatchLargerThanTheLimitIsCutAndCounted(): void
    {
        $pull = $this->pullFor([7 => TermStatus::LEARNING_3]);
        $rows = [];
        $start = (new DateTimeImmutable('2026-01-01 00:00:00'))->getTimestamp();
        for ($i = 0; $i < AnkiReviewPull::MAX_REVIEWS + 3; $i++) {
            $rows[] = ['term_id' => 7, 'reviewed_at' => ($start + $i * 86400) * 1000, 'ease' => 3];
        }

        $result = $pull->apply($rows);

        self::assertSame(AnkiReviewPull::MAX_REVIEWS, $result['applied']);
        self::assertSame(3, $result['skipped']);
    }

    /**
     * @param array<int, int> $statusByTermId
     */
    private function pullFor(array $statusByTermId): AnkiReviewPull
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

        return new AnkiReviewPull($terms, new MergeReviewHistory(new Fsrs6Scheduler(), $this->schedules));
    }

    /**
     * @return array{term_id: int, reviewed_at: int, ease: int}
     */
    private function row(int $termId, string $at, int $ease): array
    {
        return [
            'term_id' => $termId,
            'reviewed_at' => (new DateTimeImmutable($at))->getTimestamp() * 1000,
            'ease' => $ease,
        ];
    }
}
