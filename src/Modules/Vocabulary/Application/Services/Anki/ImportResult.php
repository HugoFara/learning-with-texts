<?php

declare(strict_types=1);

namespace Lwt\Modules\Vocabulary\Application\Services\Anki;

/**
 * Outcome of an .apkg import.
 *
 * `termsRescheduled`, `reviewsApplied` and `dueDatesMoved` count the scheduling
 * half (#264): how many terms took new scheduling state from the file, how many
 * individual reviews done in Anki were replayed to produce it, and how many
 * took a due date the learner set by hand in Anki instead of earning it. All
 * three stay 0 on a file with no review history, and on an install whose schema
 * predates `term_schedule`. A term can appear in `termsRescheduled` for either
 * reason, so the other two do not have to sum to it.
 *
 * @phpstan-immutable
 */
final class ImportResult
{
    public function __construct(
        public readonly int $totalNotes,
        public readonly int $updated,
        public readonly int $unchanged,
        public readonly int $skippedUnknown,
        public readonly int $skippedMissing,
        public readonly int $statusSetToIgnored,
        public readonly int $tagsChanged,
        public readonly int $termsRescheduled = 0,
        public readonly int $reviewsApplied = 0,
        public readonly int $dueDatesMoved = 0,
    ) {
    }
}
