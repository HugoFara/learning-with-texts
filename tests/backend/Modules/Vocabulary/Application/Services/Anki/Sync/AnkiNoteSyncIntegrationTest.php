<?php

declare(strict_types=1);

namespace Lwt\Tests\Modules\Vocabulary\Application\Services\Anki\Sync;

use Lwt\Modules\Tags\Application\Services\TermTagService;
use Lwt\Modules\Vocabulary\Application\Services\Anki\Sync\AnkiNoteSync;
use Lwt\Shared\Infrastructure\Bootstrap\EnvLoader;
use Lwt\Shared\Infrastructure\Database\Configuration;
use Lwt\Shared\Infrastructure\Database\Connection;
use Lwt\Shared\Infrastructure\Globals;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * The note sync against a real database (#264, step 3): plan, commit, and
 * the base that makes the next sync a no-op.
 *
 * Skips when LWT_TEST_DB_AVAILABLE is false (e.g. CI without MySQL).
 */
#[Group('integration')]
#[CoversClass(AnkiNoteSync::class)]
final class AnkiNoteSyncIntegrationTest extends TestCase
{
    private const LANGUAGE = 'NoteSyncTest_Lang';
    private const DECK = 'LWT::' . self::LANGUAGE;

    private static bool $dbConnected = false;
    private static int $languageId = 0;

    public static function setUpBeforeClass(): void
    {
        $config = EnvLoader::getDatabaseConfig();
        if (!Globals::getDbConnection()) {
            try {
                Globals::setDbConnection(Configuration::connect(
                    $config['server'],
                    $config['userid'],
                    $config['passwd'],
                    'test_' . $config['dbname'],
                    $config['socket'] ?? ''
                ));
                self::$dbConnected = true;
            } catch (\Throwable) {
                self::$dbConnected = false;
            }
        } else {
            self::$dbConnected = true;
        }
        if (!self::$dbConnected || !Connection::tableExists('anki_sync_base')) {
            self::$dbConnected = false;
            return;
        }

        Connection::query(
            "INSERT INTO languages (
                LgName, LgDict1URI, LgDict2URI, LgGoogleTranslateURI,
                LgTextSize, LgRegexpSplitSentences, LgRegexpWordCharacters,
                LgRemoveSpaces, LgSplitEachChar, LgRightToLeft, LgShowRomanization
            ) VALUES (
                '" . self::LANGUAGE . "', 'https://dict.test/sync', '', '',
                100, '.!?', 'a-zA-Z',
                0, 0, 0, 1
            )"
        );
        self::$languageId = (int) mysqli_insert_id(Globals::getDbConnection());
    }

    public static function tearDownAfterClass(): void
    {
        if (!self::$dbConnected || self::$languageId === 0) {
            return;
        }
        Connection::query(
            'DELETE FROM anki_sync_base WHERE AsWoID IN (SELECT WoID FROM words WHERE WoLgID = '
            . self::$languageId . ')'
        );
        Connection::query('DELETE FROM words WHERE WoLgID = ' . self::$languageId);
        Connection::query('DELETE FROM languages WHERE LgID = ' . self::$languageId);
    }

    protected function setUp(): void
    {
        if (!defined('LWT_TEST_DB_AVAILABLE') || !LWT_TEST_DB_AVAILABLE) {
            $this->markTestSkipped('Database connection required');
        }
        if (!self::$dbConnected) {
            $this->markTestSkipped('Test database (with anki_sync_base) not available');
        }
        Connection::query('DELETE FROM anki_sync_base');
        Connection::query('DELETE FROM words WHERE WoLgID = ' . self::$languageId);
    }

    public function testAnEditInAnkiReachesLwtAndTheNextSyncIsQuiet(): void
    {
        $termId = $this->createTerm('hola', 'hello');
        $sync = AnkiNoteSync::default();

        // First sync after an export: both sides agree, the base is written.
        $first = $sync->plan([$this->note($termId, 'hello')], [self::DECK]);
        self::assertSame([], $first['conflicts']);
        $sync->commit([$this->note($termId, 'hello')], [self::DECK], [], false);

        // Edited in Anki.
        $notes = [$this->note($termId, 'hello', notes: 'informal', tags: ['greeting'])];
        $plan = $sync->plan($notes, [self::DECK]);
        self::assertSame(
            ['to_anki' => 0, 'to_lwt' => 2, 'added_to_anki' => 0, 'added_to_lwt' => 0, 'orphans' => 0],
            $plan['summary']
        );
        $sync->commit($notes, [self::DECK], [], false);

        $row = Connection::preparedFetchOne('SELECT WoNotes FROM words WHERE WoID = ?', [$termId]);
        self::assertSame('informal', $row['WoNotes'] ?? null);
        self::assertSame(['greeting'], array_values(TermTagService::getWordTagsArray($termId)));

        $again = $sync->plan($notes, [self::DECK]);
        self::assertSame(0, $again['summary']['to_lwt']);
        self::assertSame(0, $again['summary']['to_anki']);
    }

    public function testANewTermIsAddedToAnkiAndRecordedOnceTheBrowserHasDoneIt(): void
    {
        $termId = $this->createTerm('adios', 'bye');
        $sync = AnkiNoteSync::default();

        $plan = $sync->plan([], [self::DECK]);
        self::assertSame($termId, $plan['add'][0]['term_id']);
        self::assertSame(self::DECK, $plan['add'][0]['deck']);

        $sync->commit([], [self::DECK], [['term_id' => $termId, 'note_id' => 9001, 'card_id' => 9002]], false);

        // Now Anki has it, and the pair is in the base.
        $after = $sync->plan([$this->note($termId, 'bye', noteId: 9001, cardId: 9002, text: 'adios')], [self::DECK]);
        self::assertSame([], $after['add']);
        self::assertSame(0, $after['summary']['to_anki'] + $after['summary']['to_lwt']);
    }

    public function testANoteAddedInAnkiBecomesATerm(): void
    {
        $sync = AnkiNoteSync::default();
        $note = $this->note(null, 'thanks', noteId: 9100, cardId: 9101, text: 'gracias');

        $result = $sync->commit([$note], [self::DECK], [], false);

        self::assertCount(1, $result['created']);
        $row = Connection::preparedFetchOne(
            'SELECT WoText, WoTranslation, WoLgID FROM words WHERE WoID = ?',
            [$result['created'][0]['term_id']]
        );
        self::assertSame('gracias', $row['WoText'] ?? null);
        self::assertSame(self::$languageId, (int) ($row['WoLgID'] ?? 0));

        // The base pairs it by note id even before Anki has its LwtId.
        $again = $sync->plan([$note], [self::DECK]);
        self::assertSame(0, $again['summary']['added_to_lwt']);
    }

    public function testADeletionWaitsForConfirmation(): void
    {
        $termId = $this->createTerm('perro', 'dog');
        $sync = AnkiNoteSync::default();
        $sync->commit([$this->note($termId, 'dog', text: 'perro')], [self::DECK], [], false);

        // The note is gone from Anki.
        $plan = $sync->plan([], [self::DECK]);
        self::assertSame($termId, $plan['delete_in_lwt'][0]['term_id']);

        $sync->commit([], [self::DECK], [], false);
        self::assertNotNull(Connection::preparedFetchOne('SELECT WoID FROM words WHERE WoID = ?', [$termId]));

        $result = $sync->commit([], [self::DECK], [], true);
        self::assertSame(1, $result['deleted']);
        self::assertNull(Connection::preparedFetchOne('SELECT WoID FROM words WHERE WoID = ?', [$termId]));
    }

    private function createTerm(string $text, string $translation): int
    {
        Connection::preparedExecute(
            'INSERT INTO words (WoLgID, WoText, WoTextLC, WoStatus, WoTranslation, WoStatusChanged)
             VALUES (?, ?, ?, 1, ?, NOW())',
            [self::$languageId, $text, strtolower($text), $translation]
        );

        return (int) mysqli_insert_id(Globals::getDbConnection());
    }

    /**
     * @param list<string> $tags
     *
     * @return array<string, mixed>
     */
    private function note(
        ?int $termId,
        string $translation,
        string $notes = '',
        array $tags = [],
        int $noteId = 9000,
        int $cardId = 9001,
        string $text = 'hola'
    ): array {
        return [
            'note_id' => $noteId,
            'card_id' => $cardId,
            'deck' => self::DECK,
            'fields' => [
                'Term' => $text,
                'Translation' => $translation,
                'Romanization' => '',
                'Notes' => $notes,
                'LwtId' => $termId === null ? '' : (string) $termId,
            ],
            'tags' => $tags,
            'suspended' => false,
            'last_review' => null,
        ];
    }
}
