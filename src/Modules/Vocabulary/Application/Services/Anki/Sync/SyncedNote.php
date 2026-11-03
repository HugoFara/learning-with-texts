<?php

/**
 * An Anki note of an LWT term, as the note sync sees it.
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
 * One "LWT Term" note read from Anki over AnkiConnect.
 *
 * Field values are taken as Anki stores them, exactly as ApkgReader takes
 * them from a file.
 *
 * @phpstan-immutable
 */
final class SyncedNote
{
    /**
     * @param list<string> $tags Sorted
     */
    public function __construct(
        public readonly int $noteId,
        public readonly int $cardId,
        public readonly string $deck,
        public readonly ?int $lwtId,
        public readonly string $text,
        public readonly string $translation,
        public readonly string $romanization,
        public readonly string $notes,
        public readonly array $tags,
        public readonly bool $suspended,
        public readonly ?DateTimeImmutable $lastReview,
    ) {
    }

    /**
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
