<?php

declare(strict_types=1);

namespace Lwt\Tests\Modules\Vocabulary\Application\Services\Anki\Sync;

use DateTimeImmutable;
use Lwt\Modules\Vocabulary\Application\Services\Anki\Sync\NoteSyncPlan;
use Lwt\Modules\Vocabulary\Application\Services\Anki\Sync\NoteSyncPlanner;
use Lwt\Modules\Vocabulary\Application\Services\Anki\Sync\SyncBase;
use Lwt\Modules\Vocabulary\Application\Services\Anki\Sync\SyncedNote;
use Lwt\Modules\Vocabulary\Application\Services\Anki\Sync\SyncedTerm;
use Lwt\Modules\Vocabulary\Domain\ValueObject\TermStatus;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * The note sync's three-way merge (#264, step 3). The scenarios are the
 * acceptance list in docs-src/developer/anki-sync-conflicts.md.
 */
#[CoversClass(NoteSyncPlanner::class)]
final class NoteSyncPlannerTest extends TestCase
{
    private const DECK = 'LWT::Spanish';
    private const LANG = 3;
    private const SYNCED = '2026-02-01 12:00:00';

    public function testTranslationEditedInLwtGoesToAnki(): void
    {
        $plan = $this->plan(
            [$this->term(translation: 'hi')],
            [$this->note()],
            [$this->base()]
        );

        self::assertSame([['note_id' => 100, 'fields' => ['Translation' => 'hi']]], $plan->ankiFields);
        self::assertSame([], $plan->lwtFields);
        self::assertSame('hi', $plan->bases[0]->translation);
    }

    public function testNotesEditedInAnkiGoToLwt(): void
    {
        $plan = $this->plan([$this->term()], [$this->note(notes: 'from Anki')], [$this->base()]);

        self::assertSame([['term_id' => 7, 'fields' => ['notes' => 'from Anki']]], $plan->lwtFields);
        self::assertSame([], $plan->ankiFields);
    }

    public function testEditsToDifferentFieldsOfOneTermBothSurvive(): void
    {
        $plan = $this->plan(
            [$this->term(translation: 'hi')],
            [$this->note(notes: 'from Anki')],
            [$this->base()]
        );

        self::assertSame([['note_id' => 100, 'fields' => ['Translation' => 'hi']]], $plan->ankiFields);
        self::assertSame([['term_id' => 7, 'fields' => ['notes' => 'from Anki']]], $plan->lwtFields);
        self::assertSame([], $plan->conflicts);
    }

    public function testTheSameEditOnBothSidesIsNoConflict(): void
    {
        $plan = $this->plan(
            [$this->term(translation: 'hi')],
            [$this->note(translation: 'hi')],
            [$this->base()]
        );

        self::assertTrue($plan->isEmpty());
        self::assertSame([], $plan->conflicts);
        self::assertSame('hi', $plan->bases[0]->translation);
    }

    public function testDifferentEditsToOneFieldAreReportedAndLeftAlone(): void
    {
        $plan = $this->plan(
            [$this->term(translation: 'hi')],
            [$this->note(translation: 'hey')],
            [$this->base()]
        );

        self::assertSame(
            [['term_id' => 7, 'text' => 'hola', 'field' => 'translation', 'lwt' => 'hi', 'anki' => 'hey']],
            $plan->conflicts
        );
        self::assertSame([], $plan->ankiFields);
        self::assertSame([], $plan->lwtFields);
        // The base keeps the old agreement, so it stays a conflict until settled.
        self::assertSame('hello', $plan->bases[0]->translation);
    }

    public function testAFirstSyncOfPairsThatDifferReportsWithoutChoosing(): void
    {
        $plan = $this->plan([$this->term(translation: 'hi')], [$this->note()], []);

        self::assertCount(1, $plan->conflicts);
        self::assertSame([], $plan->ankiFields);
        self::assertNull($plan->bases[0]->translation, 'never agreed');
        self::assertSame('', $plan->bases[0]->notes, 'equal fields are agreed at once');
    }

    public function testTagsMergeAsSets(): void
    {
        $plan = $this->plan(
            [$this->term(tags: ['food', 'keep', 'lwt-new'])],
            [$this->note(tags: ['anki-new', 'food'])],
            [$this->base(tags: ['food', 'keep'])]
        );

        // Added on each side survive; removed in Anki ("keep") goes.
        self::assertSame([['note_id' => 100, 'tags' => ['anki-new', 'food', 'lwt-new']]], $plan->ankiTags);
        self::assertSame([['term_id' => 7, 'tags' => ['anki-new', 'food', 'lwt-new']]], $plan->lwtTags);
        self::assertSame(['anki-new', 'food', 'lwt-new'], $plan->bases[0]->tags);
    }

    public function testSuspendingInAnkiIgnoresTheTermAndRemembersItsStatus(): void
    {
        $plan = $this->plan([$this->term(status: 3)], [$this->note(suspended: true)], [$this->base()]);

        self::assertSame(
            [['term_id' => 7, 'status' => TermStatus::IGNORED, 'restore_status' => 3]],
            $plan->lwtStatus
        );
        self::assertSame(3, $plan->bases[0]->restoreStatus);
    }

    public function testUnsuspendingInAnkiGivesTheStatusBack(): void
    {
        $plan = $this->plan(
            [$this->term(status: TermStatus::IGNORED, suspended: true)],
            [$this->note(suspended: false)],
            [$this->base(suspended: true, restoreStatus: 3)]
        );

        self::assertSame([['term_id' => 7, 'status' => 3, 'restore_status' => null]], $plan->lwtStatus);
    }

    public function testIgnoringInLwtSuspendsTheCard(): void
    {
        $plan = $this->plan(
            [$this->term(status: TermStatus::IGNORED, suspended: true)],
            [$this->note()],
            [$this->base()]
        );

        self::assertSame([200], $plan->ankiSuspend);
        self::assertSame([], $plan->lwtStatus);
    }

    public function testANoteDeletedInAnkiAsksToDeleteTheTerm(): void
    {
        $plan = $this->plan([$this->term()], [], [$this->base()]);

        self::assertSame([['term_id' => 7, 'note_id' => 100, 'text' => 'hola']], $plan->deleteInLwt);
        self::assertSame([], $plan->ankiAdd);
    }

    public function testANoteDeletedInAnkiComesBackIfTheTermWasReviewedSince(): void
    {
        $plan = $this->plan(
            [$this->term(lastReview: '2026-02-05 09:00:00')],
            [],
            [$this->base()]
        );

        self::assertSame([], $plan->deleteInLwt);
        self::assertSame(7, $plan->ankiAdd[0]['term_id']);
        self::assertSame([7], $plan->dropBases);
    }

    public function testATermDeletedInLwtAsksToDeleteTheNote(): void
    {
        $plan = $this->plan([], [$this->note()], [$this->base()]);

        self::assertSame([['term_id' => 7, 'note_id' => 100, 'text' => 'hola']], $plan->deleteInAnki);
    }

    public function testATermDeletedInLwtComesBackIfTheNoteChangedSince(): void
    {
        $plan = $this->plan([], [$this->note(notes: 'edited in Anki')], [$this->base()]);

        self::assertSame([], $plan->deleteInAnki);
        self::assertSame(100, $plan->lwtCreate[0]['note_id']);
        self::assertSame(self::LANG, $plan->lwtCreate[0]['language_id']);
    }

    public function testGoneOnBothSidesDropsTheBase(): void
    {
        $plan = $this->plan([], [], [$this->base()]);

        self::assertSame([7], $plan->dropBases);
        self::assertTrue($plan->isEmpty());
    }

    public function testANewTermInASyncedLanguageIsAddedToAnki(): void
    {
        $plan = $this->plan([$this->term()], [], []);

        self::assertSame([[
            'term_id' => 7,
            'deck' => self::DECK,
            'fields' => [
                'Term' => 'hola',
                'Translation' => 'hello',
                'Romanization' => '',
                'Notes' => '',
                'LwtId' => '7',
            ],
            'tags' => [],
            'suspended' => false,
        ]], $plan->ankiAdd);
    }

    public function testATermInALanguageWithNoDeckStaysOutOfAnki(): void
    {
        $plan = $this->plan([$this->term(languageId: 99)], [], []);

        self::assertSame([], $plan->ankiAdd);
    }

    public function testANoteAddedInAnkiBecomesATermInItsDecksLanguage(): void
    {
        $plan = $this->plan([], [$this->note(lwtId: null, text: 'adiós')], []);

        self::assertSame('adiós', $plan->lwtCreate[0]['text']);
        self::assertSame(self::LANG, $plan->lwtCreate[0]['language_id']);
    }

    public function testANoteWhoseIdWasNeverWrittenStillPairsThroughTheBase(): void
    {
        // The term was created from this note, but writing its LwtId back to
        // Anki failed. The base knows the note id, so no duplicate is created.
        $plan = $this->plan([$this->term()], [$this->note(lwtId: null)], [$this->base()]);

        self::assertSame([], $plan->lwtCreate);
        self::assertSame([], $plan->ankiAdd);
    }

    public function testANoteForATermWeDoNotHaveIsLeftAlone(): void
    {
        $plan = $this->plan([], [$this->note(lwtId: 555)], []);

        self::assertSame(1, $plan->orphans);
        self::assertTrue($plan->isEmpty());
    }

    public function testAnUnchangedPairPlansNothing(): void
    {
        $plan = $this->plan([$this->term()], [$this->note()], [$this->base()]);

        self::assertTrue($plan->isEmpty());
        self::assertCount(1, $plan->bases);
    }

    /**
     * @param list<SyncedTerm> $terms
     * @param list<SyncedNote> $notes
     * @param list<SyncBase>   $bases
     */
    private function plan(array $terms, array $notes, array $bases): NoteSyncPlan
    {
        return (new NoteSyncPlanner())->plan(
            $terms,
            $notes,
            $bases,
            [self::DECK => self::LANG],
            new DateTimeImmutable('2026-02-10 12:00:00')
        );
    }

    /**
     * @param list<string> $tags
     */
    private function term(
        string $translation = 'hello',
        string $notes = '',
        array $tags = [],
        int $status = 3,
        bool $suspended = false,
        ?string $lastReview = null,
        int $languageId = self::LANG,
    ): SyncedTerm {
        return new SyncedTerm(
            termId: 7,
            languageId: $languageId,
            text: 'hola',
            translation: $translation,
            romanization: '',
            notes: $notes,
            tags: $tags,
            status: $status,
            suspended: $suspended,
            lastReview: $lastReview === null ? null : new DateTimeImmutable($lastReview),
        );
    }

    /**
     * @param list<string> $tags
     */
    private function note(
        string $translation = 'hello',
        string $notes = '',
        array $tags = [],
        bool $suspended = false,
        ?int $lwtId = 7,
        string $text = 'hola',
    ): SyncedNote {
        return new SyncedNote(
            noteId: 100,
            cardId: 200,
            deck: self::DECK,
            lwtId: $lwtId,
            text: $text,
            translation: $translation,
            romanization: '',
            notes: $notes,
            tags: $tags,
            suspended: $suspended,
            lastReview: null,
        );
    }

    /**
     * @param list<string> $tags
     */
    private function base(array $tags = [], bool $suspended = false, ?int $restoreStatus = null): SyncBase
    {
        return new SyncBase(
            termId: 7,
            noteId: 100,
            cardId: 200,
            translation: 'hello',
            romanization: '',
            notes: '',
            tags: $tags,
            suspended: $suspended,
            restoreStatus: $restoreStatus,
            syncedAt: new DateTimeImmutable(self::SYNCED),
        );
    }
}
