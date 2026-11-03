<?php

/**
 * An LWT term, as the note sync sees it.
 *
 * PHP version 8.2
 *
 * @category Lwt
 * @package  Lwt\Modules\Vocabulary\Application\Services\Anki\Sync
 * @license  Unlicense <http://unlicense.org/>
 * @link     https://github.com/HugoFara/lwt
 */

declare(strict_types=1);

namespace Lwt\Modules\Vocabulary\Application\Services\Anki\Sync;

use DateTimeImmutable;

/**
 * The parts of a term that travel to Anki, in Anki's terms.
 *
 * Built through ApkgTermMapper, so these are exactly the values the .apkg
 * export writes: tags already have spaces turned into underscores, and
 * `suspended` is the status mapping (98 and 99 suspend the card).
 *
 * @phpstan-immutable
 */
final class SyncedTerm
{
    /**
     * @param list<string> $tags Sorted
     */
    public function __construct(
        public readonly int $termId,
        public readonly int $languageId,
        public readonly string $text,
        public readonly string $translation,
        public readonly string $romanization,
        public readonly string $notes,
        public readonly array $tags,
        public readonly int $status,
        public readonly bool $suspended,
        public readonly ?DateTimeImmutable $lastReview,
    ) {
    }

    /**
     * The value of one synced field.
     *
     * @param 'translation'|'romanization'|'notes' $field
     */
    public function field(string $field): string
    {
        return match ($field) {
            NoteField::TRANSLATION => $this->translation,
            NoteField::ROMANIZATION => $this->romanization,
            NoteField::NOTES => $this->notes,
        };
    }
}
