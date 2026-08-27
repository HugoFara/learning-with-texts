<?php

declare(strict_types=1);

namespace Lwt\Modules\Vocabulary\Application\Services\Anki;

use Lwt\Modules\Review\Domain\Scheduling\FsrsParameters;
use Lwt\Modules\Review\Domain\Scheduling\MemoryState;
use Lwt\Modules\Review\Domain\Scheduling\ReviewLogEntry;
use Lwt\Modules\Vocabulary\Domain\Term;
use Lwt\Modules\Vocabulary\Domain\ValueObject\TermStatus;
use Lwt\Modules\Vocabulary\Infrastructure\Anki\ApkgNote;
use Lwt\Modules\Vocabulary\Infrastructure\Anki\ApkgReview;
use Lwt\Modules\Vocabulary\Infrastructure\Anki\ApkgSchedule;

/**
 * Pure mapping between LWT Term entities and ApkgNote DTOs.
 *
 * Kept free of database access so it can be exercised by unit tests without
 * an integration fixture. The orchestrating services
 * (ApkgExportService / ApkgImportService) handle persistence.
 *
 * Status-to-suspended convention:
 *  - LWT 1..5  -> not suspended in Anki (active card)
 *  - LWT 98    -> suspended in Anki (ignored)
 *  - LWT 99    -> suspended in Anki (well-known; user already knows it)
 *
 * On import, suspending a card in Anki is read as "user no longer wants this
 * in their active rotation" -> status 98 (ignored). We deliberately do *not*
 * promote to status 99 (well-known) on import, because suspension and
 * "well-known" carry different semantics and we don't want to silently lose
 * the distinction.
 */
final class ApkgTermMapper
{
    /**
     * Translate FSRS memory state and its history into the writer's shape.
     *
     * The interval Anki wants is the span the term was last scheduled for. The
     * newest log entry has it exactly; a term seeded from its legacy status has
     * no history, so fall back to the distance from its last review to its due
     * date, which is what the seed set.
     *
     * @param list<ReviewLogEntry> $history Oldest first
     */
    public static function stateToSchedule(MemoryState $state, array $history): ApkgSchedule
    {
        $latest = $history === [] ? null : $history[count($history) - 1];

        $intervalDays = $latest !== null
            ? $latest->scheduledDays
            : self::seededIntervalDays($state);

        $reviews = [];
        $previousInterval = 0;
        foreach ($history as $entry) {
            $reviews[] = new ApkgReview(
                reviewedAt: $entry->reviewedAt,
                ease: $entry->grade->value,
                intervalDays: $entry->scheduledDays,
                lastIntervalDays: $previousInterval,
            );
            $previousInterval = $entry->scheduledDays;
        }

        return new ApkgSchedule(
            stability: $state->stability,
            difficulty: $state->difficulty,
            desiredRetention: (new FsrsParameters())->desiredRetention,
            due: $state->due,
            intervalDays: $intervalDays,
            reps: $state->reps,
            lapses: $state->lapses,
            reviews: $reviews,
        );
    }

    /**
     * The interval implied by a seeded state, which has no review to read.
     */
    private static function seededIntervalDays(MemoryState $state): int
    {
        if ($state->lastReview === null) {
            return max(1, (int) round($state->stability));
        }

        $days = (int) $state->lastReview->diff($state->due)->days;

        return max(1, $days);
    }

    /**
     * Build an ApkgNote from a Term + its current tag list.
     *
     * @param list<string> $tagNames
     */
    public static function termToNote(Term $term, array $tagNames): ApkgNote
    {
        return new ApkgNote(
            lwtTermId: $term->id()->toInt(),
            term: $term->text(),
            translation: $term->translation(),
            romanization: $term->romanization(),
            notes: $term->notes(),
            tags: $tagNames,
            suspended: self::shouldSuspend($term->status()),
        );
    }

    /**
     * Apply round-tripped note data back onto a Term entity in place.
     *
     * Returns a record of which fields changed so the caller can produce
     * useful import summary stats.
     */
    public static function applyNoteToTerm(Term $term, ApkgNote $note): TermChange
    {
        $changedTranslation = $term->translation() !== $note->translation;
        $changedRomanization = $term->romanization() !== $note->romanization;
        $changedNotes = $term->notes() !== $note->notes;

        if ($changedTranslation) {
            $term->updateTranslation($note->translation);
        }
        if ($changedRomanization) {
            $term->updateRomanization($note->romanization);
        }
        if ($changedNotes) {
            $term->updateNotes($note->notes);
        }

        $statusChangedToIgnored = false;
        if ($note->suspended && self::isLearningStatus($term->status())) {
            $term->setStatus(TermStatus::ignored());
            $statusChangedToIgnored = true;
        }

        return new TermChange(
            translationChanged: $changedTranslation,
            romanizationChanged: $changedRomanization,
            notesChanged: $changedNotes,
            statusChangedToIgnored: $statusChangedToIgnored,
        );
    }

    /**
     * Whether a status maps to a suspended Anki card.
     */
    public static function shouldSuspend(TermStatus $status): bool
    {
        return $status->isIgnored() || $status->isKnown();
    }

    private static function isLearningStatus(TermStatus $status): bool
    {
        return !$status->isIgnored() && !$status->isKnown();
    }
}
