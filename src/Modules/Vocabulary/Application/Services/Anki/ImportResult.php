<?php

declare(strict_types=1);

namespace Lwt\Modules\Vocabulary\Application\Services\Anki;

/**
 * Outcome of an .apkg import.
 *
 * `termsRescheduled` and `reviewsApplied` count the scheduling half (#264):
 * how many terms took new scheduling state from the file, and how many
 * individual reviews done in Anki were replayed to produce it. Both stay 0 on
 * a file with no review history, and on an install whose schema predates
 * `term_schedule`.
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
    ) {
    }
}
