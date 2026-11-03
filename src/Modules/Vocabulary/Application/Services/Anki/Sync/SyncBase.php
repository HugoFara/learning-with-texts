<?php

/**
 * What LWT and Anki agreed on at the last sync, for one term.
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
 * One row of anki_sync_base. A null value was never agreed on.
 *
 * @phpstan-immutable
 */
final class SyncBase
{
    /**
     * @param list<string>|null $tags Sorted
     */
    public function __construct(
        public readonly int $termId,
        public readonly int $noteId,
        public readonly int $cardId,
        public readonly ?string $translation,
        public readonly ?string $romanization,
        public readonly ?string $notes,
        public readonly ?array $tags,
        public readonly ?bool $suspended,
        public readonly ?int $restoreStatus,
        public readonly DateTimeImmutable $syncedAt,
    ) {
    }

    /**
     * @param 'translation'|'romanization'|'notes' $field
     */
    public function field(string $field): ?string
    {
        return match ($field) {
            NoteField::TRANSLATION => $this->translation,
            NoteField::ROMANIZATION => $this->romanization,
            NoteField::NOTES => $this->notes,
        };
    }
}
