<?php

/**
 * The note fields the sync merges.
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

use Lwt\Modules\Vocabulary\Infrastructure\Anki\AnkiSchema;

/**
 * Translation, romanization and notes: the fields both apps edit.
 *
 * The term text is identity, not content -- LWT only lets it change case --
 * and the sentence has no Anki field, so neither is merged.
 */
final class NoteField
{
    public const TRANSLATION = 'translation';
    public const ROMANIZATION = 'romanization';
    public const NOTES = 'notes';

    public const ALL = [self::TRANSLATION, self::ROMANIZATION, self::NOTES];

    /**
     * The Anki field name for one of ours.
     *
     * @param 'translation'|'romanization'|'notes' $field
     */
    public static function ankiName(string $field): string
    {
        return match ($field) {
            self::TRANSLATION => AnkiSchema::FIELD_TRANSLATION,
            self::ROMANIZATION => AnkiSchema::FIELD_ROMANIZATION,
            self::NOTES => AnkiSchema::FIELD_NOTES,
        };
    }
}
