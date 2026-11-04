<?php

declare(strict_types=1);

namespace Lwt\Tests\Modules\Vocabulary\Http;

use Lwt\Modules\Vocabulary\Application\Services\FrequencyImportService;
use Lwt\Modules\Vocabulary\Http\TermCrudApiHandler;
use Lwt\Modules\Vocabulary\Http\TermStatusApiHandler;
use Lwt\Shared\Infrastructure\Bootstrap\EnvLoader;
use Lwt\Shared\Infrastructure\Database\Configuration;
use Lwt\Shared\Infrastructure\Database\Connection;
use Lwt\Shared\Infrastructure\Database\TextParsing;
use Lwt\Shared\Infrastructure\Globals;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Term writes and starter imports as a signed-in user in multi-user mode.
 *
 * Production runs in this mode, and the suite otherwise mostly does not. User
 * scoping adds a placeholder there, which is why term status changes and term
 * edits could return HTTP 500 for every user while passing here.
 */
#[Group('integration')]
class MultiUserTermWritesIntegrationTest extends TestCase
{
    private const USERNAME = 'multi_user_term_writes_test';

    private static bool $dbConnected = false;
    private static int $userId = 0;
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
            } catch (\Throwable) {
                return;
            }
        }
        self::$dbConnected = true;

        Connection::preparedExecute(
            'DELETE FROM users WHERE UsUsername = ?',
            [self::USERNAME]
        );
        self::$userId = (int) Connection::preparedInsert(
            "INSERT INTO users (UsUsername, UsPasswordHash) VALUES (?, 'x')",
            [self::USERNAME]
        );
        self::$languageId = (int) Connection::preparedInsert(
            "INSERT INTO languages (
                LgName, LgDict1URI, LgDict2URI, LgGoogleTranslateURI,
                LgTextSize, LgRegexpSplitSentences, LgRegexpWordCharacters,
                LgRemoveSpaces, LgSplitEachChar, LgRightToLeft, LgShowRomanization,
                LgUsID
            ) VALUES (?, '', '', '', 100, '.!?', 'a-zA-Z', 0, 0, 0, 0, ?)",
            ['MultiUserWrites', self::$userId]
        );
    }

    public static function tearDownAfterClass(): void
    {
        if (self::$userId !== 0) {
            // Everything the tests made hangs off the user by cascading keys.
            Connection::preparedExecute('DELETE FROM users WHERE UsID = ?', [self::$userId]);
        }
    }

    protected function setUp(): void
    {
        if (!self::$dbConnected) {
            $this->markTestSkipped('Database connection required');
        }
        Globals::setMultiUserEnabled(true);
        Globals::setCurrentUserId(self::$userId);
    }

    protected function tearDown(): void
    {
        Globals::setCurrentUserId(null);
        Globals::setMultiUserEnabled(false);
    }

    private function insertTerm(string $text): int
    {
        return (int) Connection::preparedInsert(
            "INSERT INTO words (WoText, WoTextLC, WoLgID, WoStatus, WoTranslation, WoWordCount, WoUsID)
             VALUES (?, ?, ?, 1, 'old', 1, ?)",
            [$text, $text, self::$languageId, self::$userId]
        );
    }

    private function insertText(string $body): int
    {
        return (int) Connection::preparedInsert(
            "INSERT INTO texts (TxLgID, TxTitle, TxText, TxUsID) VALUES (?, 't', ?, ?)",
            [self::$languageId, $body, self::$userId]
        );
    }

    /**
     * @return array<string, int|null> Lowercased occurrence text to its term ID
     */
    private function linksIn(int $textId): array
    {
        $links = [];
        $rows = Connection::preparedFetchAll(
            'SELECT LOWER(Ti2Text) AS word, Ti2WoID FROM word_occurrences
             WHERE Ti2TxID = ? AND Ti2WordCount = 1',
            [$textId]
        );
        foreach ($rows as $row) {
            $id = $row['Ti2WoID'];
            $links[(string) $row['word']] = $id === null ? null : (int) $id;
        }
        return $links;
    }

    /** @param list<string> $words */
    private function importerReturning(array $words): FrequencyImportService
    {
        $importer = $this->getMockBuilder(FrequencyImportService::class)
            ->onlyMethods(['fetchFrequencyList'])
            ->getMock();
        $importer->method('fetchFrequencyList')->willReturn($words);
        return $importer;
    }

    public function testSetWordStatusChangesTheStatus(): void
    {
        $id = $this->insertTerm('statusword');

        (new TermStatusApiHandler())->setWordStatus($id, 3);

        $this->assertSame(
            3,
            (int) Connection::preparedFetchValue('SELECT WoStatus FROM words WHERE WoID = ?', [$id], 'WoStatus')
        );
    }

    public function testUpdateTermFullSavesTheTranslation(): void
    {
        $id = $this->insertTerm('editword');

        $result = (new TermCrudApiHandler())->updateTermFull($id, ['translation' => 'new', 'status' => 2]);

        $this->assertTrue($result['success'] ?? false);
        $this->assertSame(
            'new',
            Connection::preparedFetchValue('SELECT WoTranslation FROM words WHERE WoID = ?', [$id], 'WoTranslation')
        );
    }

    public function testStarterWordsReachTextsParsedBeforeAndAfterTheImport(): void
    {
        $before = $this->insertText('Alpha gamma.');
        TextParsing::parseAndSave('Alpha gamma.', self::$languageId, $before);

        $result = $this->importerReturning(['alpha', 'beta'])
            ->importWords(self::$languageId, 'MultiUserWrites', 2);

        $after = $this->insertText('Beta gamma.');
        TextParsing::parseAndSave('Beta gamma.', self::$languageId, $after);

        $this->assertSame(2, $result['imported']);
        $this->assertNotNull($this->linksIn($before)['alpha']);
        $this->assertNull($this->linksIn($before)['gamma']);
        $this->assertNotNull($this->linksIn($after)['beta']);
    }
}
