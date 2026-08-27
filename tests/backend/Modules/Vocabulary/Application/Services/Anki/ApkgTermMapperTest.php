<?php

declare(strict_types=1);

namespace Tests\Backend\Modules\Vocabulary\Application\Services\Anki;

use DateTimeImmutable;
use Lwt\Modules\Review\Domain\Scheduling\MemoryState;
use Lwt\Modules\Review\Domain\Scheduling\Rating;
use Lwt\Modules\Review\Domain\Scheduling\ReviewLogEntry;
use Lwt\Modules\Review\Domain\Scheduling\SchedulingState;
use Lwt\Modules\Vocabulary\Application\Services\Anki\ApkgTermMapper;
use Lwt\Modules\Vocabulary\Domain\Term;
use Lwt\Modules\Vocabulary\Domain\ValueObject\TermStatus;
use Lwt\Modules\Vocabulary\Infrastructure\Anki\ApkgNote;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for ApkgTermMapper. No DB; uses Term::reconstitute fixtures.
 */
final class ApkgTermMapperTest extends TestCase
{
    public function testTermToNoteCarriesAllFieldsAndTags(): void
    {
        $term = $this->makeTerm(
            id: 42,
            text: 'hello',
            translation: 'a greeting',
            romanization: 'həˈloʊ',
            notes: 'informal',
            status: TermStatus::LEARNING_3,
        );

        $note = ApkgTermMapper::termToNote($term, ['greeting', 'common']);

        self::assertSame(42, $note->lwtTermId);
        self::assertSame('hello', $note->term);
        self::assertSame('a greeting', $note->translation);
        self::assertSame('həˈloʊ', $note->romanization);
        self::assertSame('informal', $note->notes);
        self::assertSame(['greeting', 'common'], $note->tags);
        self::assertFalse($note->suspended, 'learning-status terms must not be suspended');
        self::assertSame('lwt-42', $note->guid());
    }

    public function testIgnoredAndWellKnownTermsAreSuspendedOnExport(): void
    {
        $ignored = $this->makeTerm(id: 1, text: 't', status: TermStatus::IGNORED);
        $known = $this->makeTerm(id: 2, text: 't', status: TermStatus::WELL_KNOWN);

        self::assertTrue(ApkgTermMapper::termToNote($ignored, [])->suspended);
        self::assertTrue(ApkgTermMapper::termToNote($known, [])->suspended);
    }

    public function testApplyNoteUpdatesChangedFieldsAndReportsThem(): void
    {
        $term = $this->makeTerm(
            id: 7,
            text: 'word',
            translation: 'old translation',
            romanization: 'old roman',
            notes: 'old notes',
        );

        $note = $this->makeNote(
            lwtTermId: 7,
            translation: 'new translation',
            romanization: 'new roman',
            notes: 'new notes',
        );

        $change = ApkgTermMapper::applyNoteToTerm($term, $note);

        self::assertTrue($change->translationChanged);
        self::assertTrue($change->romanizationChanged);
        self::assertTrue($change->notesChanged);
        self::assertFalse($change->statusChangedToIgnored);
        self::assertSame('new translation', $term->translation());
        self::assertSame('new roman', $term->romanization());
        self::assertSame('new notes', $term->notes());
    }

    public function testApplyNoteWithIdenticalDataReportsNoChange(): void
    {
        $term = $this->makeTerm(
            id: 7,
            text: 'word',
            translation: 'same',
            romanization: 'same',
            notes: 'same',
        );

        $note = $this->makeNote(
            lwtTermId: 7,
            translation: 'same',
            romanization: 'same',
            notes: 'same',
        );

        $change = ApkgTermMapper::applyNoteToTerm($term, $note);

        self::assertFalse($change->anyFieldChanged());
    }

    public function testSuspendedNoteDemotesLearningTermToIgnored(): void
    {
        $term = $this->makeTerm(id: 1, text: 't', status: TermStatus::LEARNING_3);

        $note = $this->makeNote(lwtTermId: 1, suspended: true);
        $change = ApkgTermMapper::applyNoteToTerm($term, $note);

        self::assertTrue($change->statusChangedToIgnored);
        self::assertSame(TermStatus::IGNORED, $term->status()->toInt());
    }

    public function testSuspendedNoteDoesNotTouchAlreadyIgnoredOrKnown(): void
    {
        $ignored = $this->makeTerm(id: 1, text: 't', status: TermStatus::IGNORED);
        $known = $this->makeTerm(id: 2, text: 't', status: TermStatus::WELL_KNOWN);

        $note = $this->makeNote(lwtTermId: 1, suspended: true);
        ApkgTermMapper::applyNoteToTerm($ignored, $note);
        ApkgTermMapper::applyNoteToTerm($known, $note);

        self::assertSame(TermStatus::IGNORED, $ignored->status()->toInt());
        self::assertSame(TermStatus::WELL_KNOWN, $known->status()->toInt());
    }

    public function testUnsuspendedNoteLeavesStatusAlone(): void
    {
        $term = $this->makeTerm(id: 1, text: 't', status: TermStatus::LEARNING_3);

        $note = $this->makeNote(lwtTermId: 1, suspended: false);
        $change = ApkgTermMapper::applyNoteToTerm($term, $note);

        self::assertFalse($change->statusChangedToIgnored);
        self::assertSame(TermStatus::LEARNING_3, $term->status()->toInt());
    }

    private function makeTerm(
        int $id,
        string $text,
        int $status = TermStatus::NEW,
        string $translation = '',
        string $romanization = '',
        string $notes = '',
    ): Term {
        $now = new DateTimeImmutable();
        return Term::reconstitute(
            id: $id,
            languageId: 1,
            text: $text,
            textLowercase: mb_strtolower($text),
            lemma: null,
            lemmaLc: null,
            status: $status,
            translation: $translation,
            sentence: '',
            notes: $notes,
            romanization: $romanization,
            wordCount: 1,
            createdAt: $now,
            statusChangedAt: $now,
        );
    }

    private function makeNote(
        int $lwtTermId,
        string $term = 'word',
        string $translation = '',
        string $romanization = '',
        string $notes = '',
        bool $suspended = false,
    ): ApkgNote {
        return new ApkgNote(
            lwtTermId: $lwtTermId,
            term: $term,
            translation: $translation,
            romanization: $romanization,
            notes: $notes,
            tags: [],
            suspended: $suspended,
        );
    }

    // =========================================================================
    // Scheduling export (#238 phase 2b / #228)
    // =========================================================================

    public function testTheLastReviewSuppliesTheExportedInterval(): void
    {
        $state = new MemoryState(
            stability: 9.0,
            difficulty: 5.0,
            due: new DateTimeImmutable('2026-03-10 00:00:00'),
            lastReview: new DateTimeImmutable('2026-03-01 00:00:00'),
            reps: 2,
            lapses: 0,
            state: SchedulingState::Review,
        );
        $history = [
            $this->logEntry(Rating::Good, 4, new DateTimeImmutable('2026-02-25 00:00:00')),
            $this->logEntry(Rating::Easy, 9, new DateTimeImmutable('2026-03-01 00:00:00')),
        ];

        $schedule = ApkgTermMapper::stateToSchedule($state, $history);

        self::assertSame(9, $schedule->intervalDays);
        self::assertSame(2, $schedule->reps);
        self::assertSame(9.0, $schedule->stability);
    }

    public function testASeededTermTakesItsIntervalFromLastReviewToDue(): void
    {
        // Never graded, so there is no review to read the interval off; the
        // seed put its due date one status-interval after WoStatusChanged.
        $state = new MemoryState(
            stability: 27.0,
            difficulty: 5.0,
            due: new DateTimeImmutable('2026-03-28 00:00:00'),
            lastReview: new DateTimeImmutable('2026-03-01 00:00:00'),
            reps: 0,
            lapses: 0,
            state: SchedulingState::Review,
        );

        $schedule = ApkgTermMapper::stateToSchedule($state, []);

        self::assertSame(27, $schedule->intervalDays);
        self::assertSame([], $schedule->reviews);
    }

    public function testEachReviewCarriesThePreviousReviewsInterval(): void
    {
        $state = new MemoryState(
            stability: 9.0,
            difficulty: 5.0,
            due: new DateTimeImmutable('2026-03-10 00:00:00'),
            lastReview: new DateTimeImmutable('2026-03-01 00:00:00'),
            reps: 3,
            lapses: 1,
            state: SchedulingState::Review,
        );
        $history = [
            $this->logEntry(Rating::Again, 1, new DateTimeImmutable('2026-02-20 00:00:00')),
            $this->logEntry(Rating::Good, 4, new DateTimeImmutable('2026-02-25 00:00:00')),
            $this->logEntry(Rating::Easy, 9, new DateTimeImmutable('2026-03-01 00:00:00')),
        ];

        $reviews = ApkgTermMapper::stateToSchedule($state, $history)->reviews;

        self::assertCount(3, $reviews);
        self::assertSame([0, 1, 4], array_map(static fn($r) => $r->lastIntervalDays, $reviews));
        // Grades pass through untouched: LWT and Anki both number them 1-4
        self::assertSame([1, 3, 4], array_map(static fn($r) => $r->ease, $reviews));
    }

    private function logEntry(Rating $grade, int $scheduledDays, DateTimeImmutable $at): ReviewLogEntry
    {
        return new ReviewLogEntry(
            wordId: 42,
            grade: $grade,
            stateBefore: SchedulingState::Review,
            stability: 9.0,
            difficulty: 5.0,
            elapsedDays: 4,
            scheduledDays: $scheduledDays,
            reviewedAt: $at,
        );
    }
}
