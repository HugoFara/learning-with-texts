<?php

declare(strict_types=1);

namespace Lwt\Modules\Vocabulary\Infrastructure\Anki;

use DateTimeImmutable;

/**
 * A term's scheduling state, in the shape the .apkg writer needs.
 *
 * Deliberately flat and Anki-shaped rather than a MemoryState: the export
 * service does the translating, so the writer stays the only piece that knows
 * the Anki layout and the Review module's domain objects stay out of it.
 *
 * @phpstan-immutable
 */
final class ApkgSchedule
{
    /**
     * @param list<ApkgReview> $reviews Review history, oldest first
     */
    public function __construct(
        public readonly float $stability,
        public readonly float $difficulty,
        public readonly float $desiredRetention,
        public readonly DateTimeImmutable $due,
        public readonly int $intervalDays,
        public readonly int $reps,
        public readonly int $lapses,
        public readonly array $reviews = [],
    ) {
    }
}
