<?php

/**
 * Everything one note sync will do, on both sides.
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

/**
 * The output of {@see NoteSyncPlanner}: plain lists, so the same plan can be
 * returned to the browser (Anki's half) and applied here (LWT's half).
 *
 * Deletions are listed separately and only happen when the learner confirms
 * them; until then their pairs are left as they are.
 */
final class NoteSyncPlan
{
    /** @var list<array{note_id: int, fields: array<string, string>}> Field values to write in Anki */
    public array $ankiFields = [];

    /** @var list<array{note_id: int, tags: list<string>}> Tag sets to write in Anki */
    public array $ankiTags = [];

    /** @var list<int> Cards to suspend in Anki */
    public array $ankiSuspend = [];

    /** @var list<int> Cards to unsuspend in Anki */
    public array $ankiUnsuspend = [];

    /**
     * Terms to add to Anki as new notes.
     *
     * @var list<array{term_id: int, deck: string, fields: array<string, string>, tags: list<string>, suspended: bool}>
     */
    public array $ankiAdd = [];

    /** @var list<array{term_id: int, fields: array<string, string>}> Field values to write in LWT */
    public array $lwtFields = [];

    /** @var list<array{term_id: int, tags: list<string>}> Tag sets to write in LWT */
    public array $lwtTags = [];

    /** @var list<array{term_id: int, status: int, restore_status: ?int}> Statuses to set in LWT */
    public array $lwtStatus = [];

    /**
     * Notes added in Anki that become new LWT terms.
     *
     * @var list<array{note_id: int, card_id: int, language_id: int, text: string, translation: string,
     *                 romanization: string, notes: string, tags: list<string>, suspended: bool}>
     */
    public array $lwtCreate = [];

    /** @var list<array{term_id: int, note_id: int, text: string}> Deleted in Anki; delete the term? */
    public array $deleteInLwt = [];

    /** @var list<array{term_id: int, note_id: int, text: string}> Deleted in LWT; delete the note? */
    public array $deleteInAnki = [];

    /** @var list<array{term_id: int, text: string, field: string, lwt: string, anki: string}> */
    public array $conflicts = [];

    /** @var list<SyncBase> Rows to store once the sync is committed */
    public array $bases = [];

    /** @var list<int> Terms whose row is dropped: gone on both sides */
    public array $dropBases = [];

    /** @var int Notes pointing at a term this LWT does not have, never synced */
    public int $orphans = 0;

    /**
     * Whether nothing at all would change.
     */
    public function isEmpty(): bool
    {
        return $this->ankiFields === [] && $this->ankiTags === [] && $this->ankiSuspend === []
            && $this->ankiUnsuspend === [] && $this->ankiAdd === [] && $this->lwtFields === []
            && $this->lwtTags === [] && $this->lwtStatus === [] && $this->lwtCreate === []
            && $this->deleteInLwt === [] && $this->deleteInAnki === [];
    }

    /**
     * Anki's half, for the browser to apply.
     *
     * @return array<string, mixed>
     */
    public function forAnki(): array
    {
        return [
            'fields' => $this->ankiFields,
            'tags' => $this->ankiTags,
            'suspend' => $this->ankiSuspend,
            'unsuspend' => $this->ankiUnsuspend,
            'add' => $this->ankiAdd,
            'delete_in_anki' => $this->deleteInAnki,
            'delete_in_lwt' => $this->deleteInLwt,
            'conflicts' => $this->conflicts,
            'summary' => [
                'to_anki' => count($this->ankiFields) + count($this->ankiTags) + count($this->ankiSuspend)
                    + count($this->ankiUnsuspend),
                'to_lwt' => count($this->lwtFields) + count($this->lwtTags) + count($this->lwtStatus),
                'added_to_anki' => count($this->ankiAdd),
                'added_to_lwt' => count($this->lwtCreate),
                'orphans' => $this->orphans,
            ],
        ];
    }
}
