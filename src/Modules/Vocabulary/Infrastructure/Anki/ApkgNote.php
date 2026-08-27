<?php

declare(strict_types=1);

namespace Lwt\Modules\Vocabulary\Infrastructure\Anki;

/**
 * Round-trippable representation of a single LWT term as an Anki note.
 *
 * `guid` is the durable identity Anki uses to merge on import — we encode the
 * LWT term id into it so a re-imported .apkg can find the original term.
 *
 * @phpstan-immutable
 */
final class ApkgNote
{
    public const GUID_PREFIX = 'lwt-';

    /**
     * @param list<string> $tags
     */
    public function __construct(
        public readonly int $lwtTermId,
        public readonly string $term,
        public readonly string $translation,
        public readonly string $romanization,
        public readonly string $notes,
        public readonly array $tags,
        public readonly bool $suspended,
        public readonly ?ApkgSchedule $schedule = null,
    ) {
    }

    /**
     * The same note carrying scheduling state.
     */
    public function withSchedule(?ApkgSchedule $schedule): self
    {
        return new self(
            $this->lwtTermId,
            $this->term,
            $this->translation,
            $this->romanization,
            $this->notes,
            $this->tags,
            $this->suspended,
            $schedule,
        );
    }

    public function guid(): string
    {
        return self::GUID_PREFIX . $this->lwtTermId;
    }

    public function lwtIdField(): string
    {
        return (string) $this->lwtTermId;
    }

    public static function lwtIdFromGuid(string $guid): ?int
    {
        if (!str_starts_with($guid, self::GUID_PREFIX)) {
            return null;
        }
        $tail = substr($guid, strlen(self::GUID_PREFIX));
        return ctype_digit($tail) ? (int) $tail : null;
    }
}
