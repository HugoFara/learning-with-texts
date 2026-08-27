<?php

declare(strict_types=1);

namespace Lwt\Modules\Vocabulary\Infrastructure\Anki;

use DateTimeImmutable;

/**
 * One past review, on its way to becoming an Anki `revlog` row.
 *
 * `ease` is LWT's grade unchanged: both number Again..Easy as 1..4, which is
 * why the review log records the grade in that range to begin with.
 *
 * @phpstan-immutable
 */
final class ApkgReview
{
    public function __construct(
        public readonly DateTimeImmutable $reviewedAt,
        public readonly int $ease,
        public readonly int $intervalDays,
        public readonly int $lastIntervalDays,
    ) {
    }
}
