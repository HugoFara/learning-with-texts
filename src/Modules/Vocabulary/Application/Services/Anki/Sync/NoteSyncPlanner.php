<?php

/**
 * Three-way merge of LWT terms and Anki notes against the last sync.
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
use Lwt\Modules\Vocabulary\Domain\ValueObject\TermStatus;
use Lwt\Modules\Vocabulary\Infrastructure\Anki\AnkiSchema;

/**
 * Decides what a note sync does (#264, step 3; design in
 * docs-src/developer/anki-sync-conflicts.md). Pure: no database, no Anki.
 *
 * Every LWT term and Anki note is paired, first through the stored base (by
 * note id, so a note whose LwtId field was never written still pairs), then
 * by the note's LwtId. For each pair, and each of translation, romanization,
 * notes, tags and the suspended flag:
 *
 *  - unchanged on both sides since the base: nothing;
 *  - changed on one side: copied to the other;
 *  - changed on both to the same value: converged;
 *  - changed on both to different values, or never agreed (no base): a
 *    conflict, reported and left alone -- no clock decides, nothing is lost,
 *    and the next sync settles it once the learner makes the two match.
 *
 * Tags merge as sets, so a tag added on one side and another on the other
 * both survive. Suspending in Anki makes a learning term "ignored" (98) and
 * remembers its status; unsuspending gives it back.
 *
 * A pair missing one half was deleted on that side. Deleting the other half
 * waits for the learner's confirmation, and does not happen at all if that
 * half changed since the base (a field, a tag, the flag, or a review): it is
 * re-created on the deleting side instead. A term in a language that has an
 * "LWT::" deck and no note is added to Anki; a note in such a deck with no
 * LwtId becomes a new term.
 */
final class NoteSyncPlanner
{
    /**
     * @param list<SyncedTerm>   $terms         LWT terms of the synced languages
     * @param list<SyncedNote>   $notes         Anki's "LWT Term" notes
     * @param list<SyncBase>     $bases         The stored base rows
     * @param array<string, int> $deckLanguages Anki deck name => LWT language id
     * @param DateTimeImmutable  $now           Time stamped on new base rows
     */
    public function plan(
        array $terms,
        array $notes,
        array $bases,
        array $deckLanguages,
        DateTimeImmutable $now
    ): NoteSyncPlan {
        $plan = new NoteSyncPlan();

        $termsById = [];
        foreach ($terms as $term) {
            $termsById[$term->termId] = $term;
        }
        $notesById = [];
        foreach ($notes as $note) {
            $notesById[$note->noteId] = $note;
        }
        $deckByLanguage = array_flip($deckLanguages);

        $pairedTerms = [];
        $pairedNotes = [];

        foreach ($bases as $base) {
            $term = $termsById[$base->termId] ?? null;
            $note = $notesById[$base->noteId] ?? null;
            $pairedTerms[$base->termId] = true;
            $pairedNotes[$base->noteId] = true;

            if ($term !== null && $note !== null) {
                $this->merge($plan, $term, $note, $base, $now);
            } elseif ($term !== null) {
                $this->deletedInAnki($plan, $term, $base, $deckByLanguage);
            } elseif ($note !== null) {
                $this->deletedInLwt($plan, $note, $base, $deckLanguages);
            } else {
                $plan->dropBases[] = $base->termId;
            }
        }

        foreach ($notes as $note) {
            if (isset($pairedNotes[$note->noteId])) {
                continue;
            }
            $lwtId = $note->lwtId;
            if ($lwtId !== null && $lwtId > 0) {
                $term = $termsById[$lwtId] ?? null;
                if ($term === null || isset($pairedTerms[$lwtId])) {
                    // A term this LWT does not have, or a second note for one
                    // already paired: leave it, as the .apkg import does.
                    $plan->orphans++;
                    continue;
                }
                $pairedTerms[$lwtId] = true;
                $this->merge($plan, $term, $note, null, $now);
            } elseif (isset($deckLanguages[$note->deck])) {
                $this->createInLwt($plan, $note, $deckLanguages[$note->deck]);
            }
        }

        foreach ($terms as $term) {
            if (isset($pairedTerms[$term->termId])) {
                continue;
            }
            $deck = $deckByLanguage[$term->languageId] ?? null;
            if ($deck !== null) {
                $this->addToAnki($plan, $term, $deck);
            }
        }

        return $plan;
    }

    /**
     * Merge one pair present on both sides.
     */
    private function merge(
        NoteSyncPlan $plan,
        SyncedTerm $term,
        SyncedNote $note,
        ?SyncBase $base,
        DateTimeImmutable $now
    ): void {
        $agreed = [];
        $toAnki = [];
        $toLwt = [];
        foreach (NoteField::ALL as $field) {
            $l = $term->field($field);
            $a = $note->field($field);
            $b = $base?->field($field);
            if ($l === $a) {
                $agreed[$field] = $l;
            } elseif ($b !== null && $a === $b) {
                $toAnki[NoteField::ankiName($field)] = $l;
                $agreed[$field] = $l;
            } elseif ($b !== null && $l === $b) {
                $toLwt[$field] = $a;
                $agreed[$field] = $a;
            } else {
                $plan->conflicts[] = [
                    'term_id' => $term->termId,
                    'text' => $term->text,
                    'field' => $field,
                    'lwt' => $l,
                    'anki' => $a,
                ];
                $agreed[$field] = $b;
            }
        }
        if ($toAnki !== []) {
            $plan->ankiFields[] = ['note_id' => $note->noteId, 'fields' => $toAnki];
        }
        if ($toLwt !== []) {
            $plan->lwtFields[] = ['term_id' => $term->termId, 'fields' => $toLwt];
        }

        $tags = $this->mergeTags($plan, $term, $note, $base);

        [$suspended, $restore] = $this->mergeFlag($plan, $term, $note, $base);

        $plan->bases[] = new SyncBase(
            termId: $term->termId,
            noteId: $note->noteId,
            cardId: $note->cardId,
            translation: $agreed[NoteField::TRANSLATION],
            romanization: $agreed[NoteField::ROMANIZATION],
            notes: $agreed[NoteField::NOTES],
            tags: $tags,
            suspended: $suspended,
            restoreStatus: $restore,
            syncedAt: $now,
        );
    }

    /**
     * Merge tag sets; returns the agreed set, or null if never agreed.
     *
     * @return list<string>|null
     */
    private function mergeTags(NoteSyncPlan $plan, SyncedTerm $term, SyncedNote $note, ?SyncBase $base): ?array
    {
        $l = $term->tags;
        $a = $note->tags;
        if ($l === $a) {
            return $l;
        }
        $b = $base?->tags;
        if ($b === null) {
            $plan->conflicts[] = [
                'term_id' => $term->termId,
                'text' => $term->text,
                'field' => 'tags',
                'lwt' => implode(' ', $l),
                'anki' => implode(' ', $a),
            ];
            return null;
        }

        $added = array_unique(array_merge(array_diff($l, $b), array_diff($a, $b)));
        $removed = array_unique(array_merge(array_diff($b, $l), array_diff($b, $a)));
        $merged = array_values(array_diff(array_unique(array_merge($b, $added)), $removed));
        sort($merged);

        if ($merged !== $a) {
            $plan->ankiTags[] = ['note_id' => $note->noteId, 'tags' => $merged];
        }
        if ($merged !== $l) {
            $plan->lwtTags[] = ['term_id' => $term->termId, 'tags' => $merged];
        }

        return $merged;
    }

    /**
     * Merge the suspended flag; returns the agreed flag and the status to
     * restore on unsuspending.
     *
     * @return array{0: ?bool, 1: ?int}
     */
    private function mergeFlag(NoteSyncPlan $plan, SyncedTerm $term, SyncedNote $note, ?SyncBase $base): array
    {
        $l = $term->suspended;
        $a = $note->suspended;
        $b = $base?->suspended;
        $restore = $base?->restoreStatus;

        if ($l === $a) {
            return [$l, $l ? $restore : null];
        }
        if ($b === null) {
            $plan->conflicts[] = [
                'term_id' => $term->termId,
                'text' => $term->text,
                'field' => 'suspended',
                'lwt' => $l ? '1' : '0',
                'anki' => $a ? '1' : '0',
            ];
            return [null, $restore];
        }
        if ($l === $b) {
            // Changed in Anki.
            if ($a) {
                $plan->lwtStatus[] = [
                    'term_id' => $term->termId,
                    'status' => TermStatus::IGNORED,
                    'restore_status' => $term->status,
                ];
                return [true, $term->status];
            }
            $plan->lwtStatus[] = [
                'term_id' => $term->termId,
                'status' => $restore ?? ($term->status === TermStatus::WELL_KNOWN
                    ? TermStatus::LEARNED
                    : TermStatus::NEW),
                'restore_status' => null,
            ];
            return [false, null];
        }
        // Changed in LWT.
        if ($l) {
            $plan->ankiSuspend[] = $note->cardId;
        } else {
            $plan->ankiUnsuspend[] = $note->cardId;
        }
        return [$l, null];
    }

    /**
     * The note is gone from Anki: delete the term, or put the note back if
     * the term changed since.
     *
     * @param array<int, string> $deckByLanguage
     */
    private function deletedInAnki(NoteSyncPlan $plan, SyncedTerm $term, SyncBase $base, array $deckByLanguage): void
    {
        $changed = $term->tags !== $base->tags || $term->suspended !== $base->suspended
            || ($term->lastReview !== null && $term->lastReview > $base->syncedAt);
        foreach (NoteField::ALL as $field) {
            $changed = $changed || $term->field($field) !== $base->field($field);
        }

        $deck = $deckByLanguage[$term->languageId] ?? null;
        if ($changed && $deck !== null) {
            $plan->dropBases[] = $base->termId;
            $this->addToAnki($plan, $term, $deck);
            return;
        }
        $plan->deleteInLwt[] = ['term_id' => $term->termId, 'note_id' => $base->noteId, 'text' => $term->text];
    }

    /**
     * The term is gone from LWT: delete the note, or bring the term back if
     * the note changed since.
     *
     * @param array<string, int> $deckLanguages
     */
    private function deletedInLwt(NoteSyncPlan $plan, SyncedNote $note, SyncBase $base, array $deckLanguages): void
    {
        $changed = $note->tags !== $base->tags || $note->suspended !== $base->suspended
            || ($note->lastReview !== null && $note->lastReview > $base->syncedAt);
        foreach (NoteField::ALL as $field) {
            $changed = $changed || $note->field($field) !== $base->field($field);
        }

        $languageId = $deckLanguages[$note->deck] ?? null;
        if ($changed && $languageId !== null) {
            $plan->dropBases[] = $base->termId;
            $this->createInLwt($plan, $note, $languageId);
            return;
        }
        $plan->deleteInAnki[] = ['term_id' => $base->termId, 'note_id' => $note->noteId, 'text' => $note->text];
    }

    private function addToAnki(NoteSyncPlan $plan, SyncedTerm $term, string $deck): void
    {
        $plan->ankiAdd[] = [
            'term_id' => $term->termId,
            'deck' => $deck,
            'fields' => [
                AnkiSchema::FIELD_TERM => $term->text,
                AnkiSchema::FIELD_TRANSLATION => $term->translation,
                AnkiSchema::FIELD_ROMANIZATION => $term->romanization,
                AnkiSchema::FIELD_NOTES => $term->notes,
                AnkiSchema::FIELD_LWT_ID => (string) $term->termId,
            ],
            'tags' => $term->tags,
            'suspended' => $term->suspended,
        ];
    }

    private function createInLwt(NoteSyncPlan $plan, SyncedNote $note, int $languageId): void
    {
        if (trim($note->text) === '') {
            return;
        }
        $plan->lwtCreate[] = [
            'note_id' => $note->noteId,
            'card_id' => $note->cardId,
            'language_id' => $languageId,
            'text' => $note->text,
            'translation' => $note->translation,
            'romanization' => $note->romanization,
            'notes' => $note->notes,
            'tags' => $note->tags,
            'suspended' => $note->suspended,
        ];
    }
}
