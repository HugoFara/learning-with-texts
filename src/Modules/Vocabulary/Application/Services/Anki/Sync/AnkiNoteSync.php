<?php

/**
 * Sync fields, tags, suspension and existence between LWT and Anki.
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
use Lwt\Modules\Language\Domain\LanguageRepositoryInterface;
use Lwt\Modules\Language\Infrastructure\MySqlLanguageRepository;
use Lwt\Modules\Review\Domain\TermScheduleRepositoryInterface;
use Lwt\Modules\Review\Infrastructure\MySqlTermScheduleRepository;
use Lwt\Modules\Tags\Application\Services\TermTagService;
use Lwt\Modules\Vocabulary\Application\Services\Anki\ApkgTermMapper;
use Lwt\Modules\Vocabulary\Domain\Term;
use Lwt\Modules\Vocabulary\Domain\TermRepositoryInterface;
use Lwt\Modules\Language\Domain\ValueObject\LanguageId;
use Lwt\Modules\Vocabulary\Domain\ValueObject\TermStatus;
use Lwt\Modules\Vocabulary\Infrastructure\Anki\AnkiSchema;
use Lwt\Modules\Vocabulary\Infrastructure\Anki\MySqlAnkiSyncBaseRepository;
use Lwt\Modules\Vocabulary\Infrastructure\MySqlTermRepository;
use Lwt\Shared\Infrastructure\Globals;
use Throwable;

/**
 * The server half of the note sync (#264, step 3).
 *
 * A sync runs in three moves so that two stores with no shared transaction
 * cannot end up half-written:
 *
 *  1. {@see plan()}: the browser sends Anki's notes; this answers with what to
 *     write in Anki, plus the conflicts and the deletions awaiting a yes.
 *  2. The browser writes Anki's half.
 *  3. {@see commit()}: the browser sends the same notes again, plus the ids
 *     of the notes it added; this recomputes the same plan, applies LWT's half
 *     and stores the new base, in one transaction.
 *
 * If step 2 fails part-way the base is untouched, so the next sync sees the
 * Anki writes that did land as already converged and redoes nothing twice.
 */
class AnkiNoteSync
{
    public const DECK_PREFIX = 'LWT::';

    public function __construct(
        private readonly TermRepositoryInterface $terms,
        private readonly LanguageRepositoryInterface $languages,
        private readonly TermScheduleRepositoryInterface $schedules,
        private readonly MySqlAnkiSyncBaseRepository $bases,
        private readonly NoteSyncPlanner $planner = new NoteSyncPlanner(),
    ) {
    }

    public static function default(): self
    {
        return new self(
            new MySqlTermRepository(),
            new MySqlLanguageRepository(),
            new MySqlTermScheduleRepository(),
            new MySqlAnkiSyncBaseRepository(),
        );
    }

    /**
     * What a sync would do.
     *
     * @param list<mixed>  $notes Anki's "LWT Term" notes (see {@see parseNote()})
     * @param list<string> $decks Anki's deck names
     *
     * @return array<string, mixed> Anki's half of the plan
     */
    public function plan(array $notes, array $decks): array
    {
        return $this->buildPlan($notes, $decks)->forAnki();
    }

    /**
     * Apply LWT's half and store the new base.
     *
     * @param list<mixed>  $notes            The same notes as for plan()
     * @param list<string> $decks            The same decks as for plan()
     * @param list<mixed>  $added            `{term_id, note_id, card_id}` for each
     *                                       note the browser added to Anki
     * @param bool         $confirmDeletions Whether the learner said yes to the
     *                                       deletions the plan listed (the browser
     *                                       has already deleted Anki's)
     *
     * @return array{created: list<array{note_id: int, term_id: int}>, deleted: int, updated: int}
     *         Terms created from Anki notes (the browser writes their LwtId),
     *         terms deleted, and terms changed
     */
    public function commit(array $notes, array $decks, array $added, bool $confirmDeletions): array
    {
        $plan = $this->buildPlan($notes, $decks);
        $now = new DateTimeImmutable();
        $created = [];
        $updated = 0;
        $deleted = 0;

        $db = Globals::getDbConnection();
        $db?->begin_transaction();
        try {
            $updated += $this->applyFields($plan);
            $updated += $this->applyTags($plan);
            $updated += $this->applyStatus($plan);

            foreach ($plan->lwtCreate as $note) {
                $termId = $this->createTerm($note);
                if ($termId === null) {
                    continue;
                }
                $created[] = ['note_id' => $note['note_id'], 'term_id' => $termId];
                $plan->bases[] = new SyncBase(
                    termId: $termId,
                    noteId: $note['note_id'],
                    cardId: $note['card_id'],
                    translation: $note['translation'],
                    romanization: $note['romanization'],
                    notes: $note['notes'],
                    tags: $note['tags'],
                    suspended: $note['suspended'],
                    restoreStatus: null,
                    syncedAt: $now,
                );
            }

            $addedByTerm = self::addedByTerm($added);
            foreach ($plan->ankiAdd as $add) {
                $ids = $addedByTerm[$add['term_id']] ?? null;
                if ($ids === null) {
                    continue;
                }
                $plan->bases[] = new SyncBase(
                    termId: $add['term_id'],
                    noteId: $ids[0],
                    cardId: $ids[1],
                    translation: $add['fields'][AnkiSchema::FIELD_TRANSLATION],
                    romanization: $add['fields'][AnkiSchema::FIELD_ROMANIZATION],
                    notes: $add['fields'][AnkiSchema::FIELD_NOTES],
                    tags: $add['tags'],
                    suspended: $add['suspended'],
                    restoreStatus: null,
                    syncedAt: $now,
                );
            }

            $drop = $plan->dropBases;
            if ($confirmDeletions) {
                foreach ($plan->deleteInLwt as $deletion) {
                    if ($this->terms->delete($deletion['term_id'])) {
                        $deleted++;
                    }
                    $drop[] = $deletion['term_id'];
                }
                foreach ($plan->deleteInAnki as $deletion) {
                    $drop[] = $deletion['term_id'];
                }
            }

            $this->bases->delete($drop);
            $this->bases->save($plan->bases);
            $db?->commit();
        } catch (Throwable $e) {
            $db?->rollback();
            throw $e;
        }

        return ['created' => $created, 'deleted' => $deleted, 'updated' => $updated];
    }

    /**
     * @param list<mixed>  $notes
     * @param list<string> $decks
     */
    private function buildPlan(array $notes, array $decks): NoteSyncPlan
    {
        $deckLanguages = $this->deckLanguages($decks);

        $synced = array_values(array_filter(array_map(self::parseNote(...), $notes)));

        return $this->planner->plan(
            $this->syncedTerms(array_values(array_unique($deckLanguages))),
            $synced,
            $this->bases->all(),
            $deckLanguages,
            new DateTimeImmutable()
        );
    }

    /**
     * "LWT::{Language}" decks whose language exists here, deck => language id.
     *
     * @param list<string> $decks
     *
     * @return array<string, int>
     */
    private function deckLanguages(array $decks): array
    {
        $map = [];
        foreach ($decks as $deck) {
            if (!str_starts_with($deck, self::DECK_PREFIX)) {
                continue;
            }
            $name = substr($deck, strlen(self::DECK_PREFIX));
            // A sub-deck of a language deck is not a language of its own.
            if ($name === '' || str_contains($name, '::')) {
                continue;
            }
            $language = $this->languages->findByName($name);
            if ($language !== null) {
                $map[$deck] = $language->id()->toInt();
            }
        }

        return $map;
    }

    /**
     * Every term of these languages, as the export would write it.
     *
     * @param list<int> $languageIds
     *
     * @return list<SyncedTerm>
     */
    private function syncedTerms(array $languageIds): array
    {
        $terms = [];
        foreach ($languageIds as $languageId) {
            foreach ($this->terms->findByLanguage($languageId) as $term) {
                $terms[] = $term;
            }
        }
        if ($terms === []) {
            return [];
        }

        $states = $this->schedules->findMany(array_map(static fn(Term $t): int => $t->id()->toInt(), $terms));

        $out = [];
        foreach ($terms as $term) {
            $termId = $term->id()->toInt();
            $note = ApkgTermMapper::termToNote($term, array_values(TermTagService::getWordTagsArray($termId)));
            $out[] = new SyncedTerm(
                termId: $termId,
                languageId: $term->languageId()->toInt(),
                text: $note->term,
                translation: $note->translation,
                romanization: $note->romanization,
                notes: $note->notes,
                tags: self::normalizeTags($note->tags),
                status: $term->status()->toInt(),
                suspended: $note->suspended,
                lastReview: $states[$termId]->lastReview ?? null,
            );
        }

        return $out;
    }

    /**
     * Read one note as the browser sends it, or null if malformed.
     *
     * `{note_id, card_id, deck, fields: {Term, Translation, Romanization,
     * Notes, LwtId}, tags: [...], suspended: bool, last_review: ms|null}`
     */
    public static function parseNote(mixed $raw): ?SyncedNote
    {
        if (!is_array($raw)) {
            return null;
        }
        $noteId = filter_var($raw['note_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        $cardId = filter_var($raw['card_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        $fields = $raw['fields'] ?? null;
        if ($noteId === false || $cardId === false || !is_array($fields)) {
            return null;
        }
        $field = static fn(string $name): string => is_scalar($fields[$name] ?? null) ? (string) $fields[$name] : '';

        $lwtId = $field(AnkiSchema::FIELD_LWT_ID);
        /** @var mixed $rawTags */
        $rawTags = $raw['tags'] ?? null;
        $tags = is_array($rawTags) ? array_filter($rawTags, 'is_string') : [];
        $lastReview = filter_var($raw['last_review'] ?? null, FILTER_VALIDATE_INT);
        /** @var mixed $deck */
        $deck = $raw['deck'] ?? null;

        return new SyncedNote(
            noteId: $noteId,
            cardId: $cardId,
            deck: is_string($deck) ? $deck : '',
            lwtId: ctype_digit($lwtId) ? (int) $lwtId : null,
            text: $field(AnkiSchema::FIELD_TERM),
            translation: $field(AnkiSchema::FIELD_TRANSLATION),
            romanization: $field(AnkiSchema::FIELD_ROMANIZATION),
            notes: $field(AnkiSchema::FIELD_NOTES),
            tags: self::normalizeTags(array_values($tags)),
            suspended: ($raw['suspended'] ?? false) === true,
            lastReview: $lastReview === false
                ? null
                : new DateTimeImmutable('@' . intdiv($lastReview, 1000)),
        );
    }

    /**
     * Tags as Anki holds them: no spaces inside, sorted, unique.
     *
     * @param list<string> $tags
     *
     * @return list<string>
     */
    private static function normalizeTags(array $tags): array
    {
        $out = [];
        foreach ($tags as $tag) {
            $tag = trim(str_replace([' ', "\t", "\n"], '_', $tag));
            if ($tag !== '') {
                $out[$tag] = true;
            }
        }
        $out = array_keys($out);
        sort($out);

        return array_map('strval', $out);
    }

    private function applyFields(NoteSyncPlan $plan): int
    {
        $count = 0;
        foreach ($plan->lwtFields as $change) {
            $term = $this->terms->find($change['term_id']);
            if ($term === null) {
                continue;
            }
            foreach ($change['fields'] as $field => $value) {
                match ($field) {
                    NoteField::TRANSLATION => $term->updateTranslation($value),
                    NoteField::ROMANIZATION => $term->updateRomanization($value),
                    NoteField::NOTES => $term->updateNotes($value),
                    default => null,
                };
            }
            $this->terms->save($term);
            $count++;
        }

        return $count;
    }

    private function applyTags(NoteSyncPlan $plan): int
    {
        foreach ($plan->lwtTags as $change) {
            TermTagService::saveWordTags($change['term_id'], $change['tags']);
        }

        return count($plan->lwtTags);
    }

    private function applyStatus(NoteSyncPlan $plan): int
    {
        $count = 0;
        foreach ($plan->lwtStatus as $change) {
            $term = $this->terms->find($change['term_id']);
            if ($term === null) {
                continue;
            }
            $term->setStatus(TermStatus::fromInt($change['status']));
            $this->terms->save($term);
            $count++;
        }

        return $count;
    }

    /**
     * @param array{note_id: int, card_id: int, language_id: int, text: string, translation: string,
     *              romanization: string, notes: string, tags: list<string>, suspended: bool} $note
     */
    private function createTerm(array $note): ?int
    {
        $textLc = mb_strtolower(trim($note['text']), 'UTF-8');
        if ($this->terms->findByTextLc($note['language_id'], $textLc) !== null) {
            // The language already has this word; pairing it is the learner's
            // call, so do not create a duplicate.
            return null;
        }

        $term = Term::create(LanguageId::fromInt($note['language_id']), $note['text'], $note['translation']);
        $term->updateRomanization($note['romanization']);
        $term->updateNotes($note['notes']);
        if ($note['suspended']) {
            $term->setStatus(TermStatus::ignored());
        }
        $termId = $this->terms->save($term);
        if ($note['tags'] !== []) {
            TermTagService::saveWordTags($termId, $note['tags']);
        }

        return $termId;
    }

    /**
     * @param list<mixed> $added
     *
     * @return array<int, array{0: int, 1: int}> Term id => [note id, card id]
     */
    private static function addedByTerm(array $added): array
    {
        $out = [];
        foreach ($added as $row) {
            if (!is_array($row)) {
                continue;
            }
            $termId = filter_var($row['term_id'] ?? null, FILTER_VALIDATE_INT);
            $noteId = filter_var($row['note_id'] ?? null, FILTER_VALIDATE_INT);
            $cardId = filter_var($row['card_id'] ?? null, FILTER_VALIDATE_INT);
            if ($termId !== false && $noteId !== false && $cardId !== false) {
                $out[$termId] = [$noteId, $cardId];
            }
        }

        return $out;
    }
}
