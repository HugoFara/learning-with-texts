<?php

declare(strict_types=1);

namespace Lwt\Modules\Vocabulary\Application\Services\Anki;

use Lwt\Modules\Tags\Application\Services\TermTagService;
use Lwt\Modules\Vocabulary\Domain\TermRepositoryInterface;
use Lwt\Modules\Vocabulary\Infrastructure\Anki\ApkgReader;
use Lwt\Modules\Vocabulary\Infrastructure\MySqlTermRepository;

/**
 * Reads an .apkg and merges field/tag/suspension changes back into LWT terms.
 *
 * Merge policy (v1, deliberately conservative):
 *  - Notes whose guid matches an existing LWT term update that term's
 *    translation/romanization/notes; tags become whatever the user had in
 *    Anki; suspended cards demote learning-status terms to status 98.
 *  - Notes referencing an LWT term that no longer exists are counted as
 *    "skipped_missing" — never recreated, since we'd lose the language link.
 *  - Notes from outside LWT (no `lwt-` guid prefix and no LwtId field) are
 *    counted as "skipped_unknown" — silently ignored. A future iteration
 *    will surface these for create-as-new with a language picker.
 *  - Reviews done in Anki are replayed into LWT's own schedule by
 *    {@see ScheduleReplay}.
 */
final class ApkgImportService
{
    public function __construct(
        private readonly TermRepositoryInterface $terms,
        private readonly ApkgReader $reader,
        private readonly ScheduleReplay $replay,
    ) {
    }

    public static function default(): self
    {
        return new self(
            new MySqlTermRepository(),
            new ApkgReader(),
            ScheduleReplay::default(),
        );
    }

    public function importApkg(string $apkgPath): ImportResult
    {
        $notes = $this->reader->read($apkgPath);

        $updated = 0;
        $unchanged = 0;
        $skippedUnknown = 0;
        $skippedMissing = 0;
        $statusSetToIgnored = 0;
        $tagsChanged = 0;

        foreach ($notes as $note) {
            if ($note->lwtTermId <= 0) {
                $skippedUnknown++;
                continue;
            }

            $term = $this->terms->find($note->lwtTermId);
            if ($term === null) {
                $skippedMissing++;
                continue;
            }

            $change = ApkgTermMapper::applyNoteToTerm($term, $note);

            // Tag diff — TermTagService::saveWordTags is the canonical writer
            // for word_tag_map, so go through it rather than touching the
            // table directly.
            $existingTags = array_values(TermTagService::getWordTagsArray($term->id()->toInt()));
            $tagsDiffer = !$this->sameTagSet($existingTags, $note->tags);
            if ($tagsDiffer) {
                TermTagService::saveWordTags($term->id()->toInt(), $note->tags);
                $tagsChanged++;
            }

            if ($change->anyFieldChanged() || $tagsDiffer) {
                $this->terms->save($term);
                $updated++;
            } else {
                $unchanged++;
            }

            if ($change->statusChangedToIgnored) {
                $statusSetToIgnored++;
            }
        }

        $replayed = $this->replay->apply($notes);

        return new ImportResult(
            totalNotes: count($notes),
            updated: $updated,
            unchanged: $unchanged,
            skippedUnknown: $skippedUnknown,
            skippedMissing: $skippedMissing,
            statusSetToIgnored: $statusSetToIgnored,
            tagsChanged: $tagsChanged,
            termsRescheduled: $replayed['terms'],
            reviewsApplied: $replayed['reviews'],
        );
    }

    /**
     * @param list<string> $a
     * @param list<string> $b
     */
    private function sameTagSet(array $a, array $b): bool
    {
        if (count($a) !== count($b)) {
            return false;
        }
        $sortedA = $a;
        $sortedB = $b;
        sort($sortedA);
        sort($sortedB);
        return $sortedA === $sortedB;
    }
}
