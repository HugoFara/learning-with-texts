<?php

declare(strict_types=1);

namespace Lwt\Tests\Modules\Vocabulary\Services;

use Lwt\Shared\Infrastructure\Bootstrap\EnvLoader;
use Lwt\Shared\Infrastructure\Globals;
use Lwt\Modules\Vocabulary\Application\Services\WordCrudService;
use Lwt\Shared\Infrastructure\Database\Configuration;
use Lwt\Shared\Infrastructure\Database\Connection;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for the WordCrudService class.
 *
 * Tests word/term CRUD operations through the service layer.
 */
class WordCrudServiceTest extends TestCase
{
    private static bool $dbConnected = false;
    private static int $testLangId = 0;
    private WordCrudService $service;

    public static function setUpBeforeClass(): void
    {
        $config = EnvLoader::getDatabaseConfig();
        $testDbname = "test_" . $config['dbname'];

        if (!Globals::getDbConnection()) {
            try {
                $connection = Configuration::connect(
                    $config['server'],
                    $config['userid'],
                    $config['passwd'],
                    $testDbname,
                    $config['socket'] ?? ''
                );
                Globals::setDbConnection($connection);
                self::$dbConnected = true;
            } catch (\Exception $e) {
                self::$dbConnected = false;
            }
        } else {
            self::$dbConnected = true;
        }

        if (self::$dbConnected) {
            // Create a test language if it doesn't exist
            $existingLang = Connection::fetchValue(
                "SELECT LgID AS value FROM languages WHERE LgName = 'TestLanguage' LIMIT 1"
            );

            if ($existingLang) {
                self::$testLangId = (int)$existingLang;
            } else {
                Connection::query(
                    "INSERT INTO languages" .
                    " (LgName, LgDict1URI, LgDict2URI, LgGoogleTranslateURI, " .
                    "LgTextSize, LgCharacterSubstitutions, LgRegexpSplitSentences, LgExceptionsSplitSentences, " .
                    "LgRegexpWordCharacters, LgRemoveSpaces, LgSplitEachChar, LgRightToLeft, LgShowRomanization) " .
                    "VALUES ('TestLanguage', 'http://test.com/###', '', 'http://translate.test/###', " .
                    "100, '', '.!?', '', 'a-zA-Z', 0, 0, 0, 1)"
                );
                self::$testLangId = (int)Connection::fetchValue(
                    "SELECT LAST_INSERT_ID() AS value"
                );
            }
        }
    }

    public static function tearDownAfterClass(): void
    {
        if (!self::$dbConnected) {
            return;
        }

        // Clean up test words
        Connection::query("DELETE FROM words WHERE WoLgID = " . self::$testLangId);
        // Clean up test language
        Connection::query("DELETE FROM languages WHERE LgID = " . self::$testLangId);
    }

    protected function setUp(): void
    {
        $this->service = new WordCrudService();
    }

    protected function tearDown(): void
    {
        if (!self::$dbConnected) {
            return;
        }

        // Clean up test words after each test
        Connection::query("DELETE FROM words WHERE WoText LIKE 'test%'");
    }

    // ===== create() tests =====

    public function testCreateNewWord(): void
    {
        if (!self::$dbConnected) {
            $this->markTestSkipped('Database connection required');
        }

        $data = [
            'WoLgID' => self::$testLangId,
            'WoText' => 'testword',
            'WoStatus' => 1,
            'WoTranslation' => 'test translation',
            'WoSentence' => 'This is a {testword} sentence.',
            'WoRomanization' => 'testwɜːd',
        ];

        $result = $this->service->create($data);

        $this->assertTrue($result['success']);
        $this->assertGreaterThan(0, $result['id']);
        $this->assertEquals('testword', $result['textlc']);
        $this->assertStringContainsString('Term saved', $result['message']);
    }

    public function testCreateWordWithEmptyTranslation(): void
    {
        if (!self::$dbConnected) {
            $this->markTestSkipped('Database connection required');
        }

        $data = [
            'WoLgID' => self::$testLangId,
            'WoText' => 'testempty',
            'WoStatus' => 1,
            'WoTranslation' => '',
        ];

        $result = $this->service->create($data);

        $this->assertTrue($result['success']);

        // Verify the translation was saved as '*'
        $word = $this->service->findById($result['id']);
        $this->assertEquals('*', $word['WoTranslation']);
    }

    public function testCreateWordConvertsToLowercase(): void
    {
        if (!self::$dbConnected) {
            $this->markTestSkipped('Database connection required');
        }

        $data = [
            'WoLgID' => self::$testLangId,
            'WoText' => 'TestMixedCase',
            'WoStatus' => 1,
            'WoTranslation' => 'translation',
        ];

        $result = $this->service->create($data);

        $this->assertTrue($result['success']);
        $this->assertEquals('testmixedcase', $result['textlc']);
    }

    public function testCreateDuplicateWordFails(): void
    {
        if (!self::$dbConnected) {
            $this->markTestSkipped('Database connection required');
        }

        $data = [
            'WoLgID' => self::$testLangId,
            'WoText' => 'testduplicate',
            'WoStatus' => 1,
            'WoTranslation' => 'first',
        ];

        // Create first word
        $result1 = $this->service->create($data);
        $this->assertTrue($result1['success']);

        // Try to create duplicate
        $data['WoTranslation'] = 'second';
        $result2 = $this->service->create($data);

        $this->assertFalse($result2['success']);
        $this->assertStringContainsString('Duplicate entry', $result2['message']);
    }

    // ===== update() tests =====

    public function testUpdateWord(): void
    {
        if (!self::$dbConnected) {
            $this->markTestSkipped('Database connection required');
        }

        // Create a word first
        $createData = [
            'WoLgID' => self::$testLangId,
            'WoText' => 'testupdate',
            'WoStatus' => 1,
            'WoTranslation' => 'original',
        ];
        $createResult = $this->service->create($createData);
        $wordId = $createResult['id'];

        // Update the word
        $updateData = [
            'WoText' => 'testupdate',
            'WoStatus' => 3,
            'WoOldStatus' => 1,
            'WoTranslation' => 'updated translation',
            'WoSentence' => 'New sentence.',
            'WoRomanization' => 'new roman',
        ];

        $result = $this->service->update($wordId, $updateData);

        $this->assertTrue($result['success']);
        $this->assertEquals($wordId, $result['id']);
        $this->assertStringContainsString('Updated', $result['message']);

        // Verify the update
        $word = $this->service->findById($wordId);
        $this->assertEquals('updated translation', $word['WoTranslation']);
        $this->assertEquals('3', $word['WoStatus']);
    }

    public function testUpdateWordStatusChange(): void
    {
        if (!self::$dbConnected) {
            $this->markTestSkipped('Database connection required');
        }

        // Create a word
        $createData = [
            'WoLgID' => self::$testLangId,
            'WoText' => 'teststatus',
            'WoStatus' => 1,
            'WoTranslation' => 'translation',
        ];
        $createResult = $this->service->create($createData);
        $wordId = $createResult['id'];

        // Wait at least 1 second to ensure time difference (MySQL NOW() has second precision)
        sleep(1);

        // Update with status change
        $updateData = [
            'WoText' => 'teststatus',
            'WoStatus' => 5,
            'WoOldStatus' => 1,
            'WoTranslation' => 'translation',
        ];
        $this->service->update($wordId, $updateData);

        // Verify status was changed
        $updatedWord = $this->service->findById($wordId);
        $this->assertEquals('5', $updatedWord['WoStatus']);
    }

    // ===== findById() tests =====

    public function testFindByIdReturnsWord(): void
    {
        if (!self::$dbConnected) {
            $this->markTestSkipped('Database connection required');
        }

        // Create a word
        $data = [
            'WoLgID' => self::$testLangId,
            'WoText' => 'testfind',
            'WoStatus' => 2,
            'WoTranslation' => 'find me',
        ];
        $createResult = $this->service->create($data);

        // Find it
        $word = $this->service->findById($createResult['id']);

        $this->assertIsArray($word);
        $this->assertEquals('testfind', $word['WoText']);
        $this->assertEquals('find me', $word['WoTranslation']);
        $this->assertEquals('2', $word['WoStatus']);
    }

    public function testFindByIdReturnsNullForNonExistent(): void
    {
        if (!self::$dbConnected) {
            $this->markTestSkipped('Database connection required');
        }

        $result = $this->service->findById(999999);
        $this->assertNull($result);
    }

    // ===== findByText() tests =====

    public function testFindByTextReturnsWordId(): void
    {
        if (!self::$dbConnected) {
            $this->markTestSkipped('Database connection required');
        }

        // Create a word
        $data = [
            'WoLgID' => self::$testLangId,
            'WoText' => 'testfindtext',
            'WoStatus' => 1,
            'WoTranslation' => 'translation',
        ];
        $createResult = $this->service->create($data);

        // Find by text
        $foundId = $this->service->findByText('testfindtext', self::$testLangId);

        $this->assertEquals($createResult['id'], $foundId);
    }

    public function testFindByTextReturnsNullForNonExistent(): void
    {
        if (!self::$dbConnected) {
            $this->markTestSkipped('Database connection required');
        }

        $result = $this->service->findByText('nonexistentword12345', self::$testLangId);
        $this->assertNull($result);
    }

    public function testFindByTextIsCaseSensitive(): void
    {
        if (!self::$dbConnected) {
            $this->markTestSkipped('Database connection required');
        }

        // Create a lowercase word
        $data = [
            'WoLgID' => self::$testLangId,
            'WoText' => 'testcase',
            'WoStatus' => 1,
            'WoTranslation' => 'translation',
        ];
        $this->service->create($data);

        // Search with lowercase should find it
        $result = $this->service->findByText('testcase', self::$testLangId);
        $this->assertNotNull($result);

        // Search with uppercase should not find it (WoTextLC stores lowercase)
        $result = $this->service->findByText('TESTCASE', self::$testLangId);
        $this->assertNull($result);
    }

    // ===== delete() tests =====

    public function testDeleteWord(): void
    {
        if (!self::$dbConnected) {
            $this->markTestSkipped('Database connection required');
        }

        // Create a word
        $data = [
            'WoLgID' => self::$testLangId,
            'WoText' => 'testdelete',
            'WoStatus' => 1,
            'WoTranslation' => 'to be deleted',
        ];
        $createResult = $this->service->create($data);
        $wordId = $createResult['id'];

        // Verify it exists
        $word = $this->service->findById($wordId);
        $this->assertNotNull($word);

        // Delete it (returns void)
        $this->service->delete($wordId);

        // Verify it's gone
        $deletedWord = $this->service->findById($wordId);
        $this->assertNull($deletedWord);
    }

    // ===== getWordCount() tests =====

    public function testGetWordCountReturnsWordCount(): void
    {
        if (!self::$dbConnected) {
            $this->markTestSkipped('Database connection required');
        }

        // Create a word (note: WoWordCount is typically set during text parsing,
        // not during direct word creation, so it defaults to 0 or NULL)
        $data = [
            'WoLgID' => self::$testLangId,
            'WoText' => 'testsingle',
            'WoStatus' => 1,
            'WoTranslation' => 'translation',
        ];
        $createResult = $this->service->create($data);

        $count = $this->service->getWordCount($createResult['id']);

        // Word count is an integer (0 for newly created words without text parsing)
        $this->assertIsInt($count);
        $this->assertGreaterThanOrEqual(0, $count);
    }

    // ===== getWordData() tests =====

    public function testGetWordData(): void
    {
        if (!self::$dbConnected) {
            $this->markTestSkipped('Database connection required');
        }

        // Create a word
        $data = [
            'WoLgID' => self::$testLangId,
            'WoText' => 'testgetdata',
            'WoStatus' => 1,
            'WoTranslation' => 'my translation',
            'WoRomanization' => 'my romanization',
        ];
        $createResult = $this->service->create($data);
        $wordId = $createResult['id'];

        // Get word data
        $wordData = $this->service->getWordData($wordId);

        $this->assertIsArray($wordData);
        $this->assertEquals('testgetdata', $wordData['text']);
        $this->assertEquals('my translation', $wordData['translation']);
        $this->assertEquals('my romanization', $wordData['romanization']);
    }

    public function testGetWordDataReturnsNullForNonExistent(): void
    {
        if (!self::$dbConnected) {
            $this->markTestSkipped('Database connection required');
        }

        $result = $this->service->getWordData(999999);
        $this->assertNull($result);
    }

    // ===== getWordText() tests =====

    public function testGetWordText(): void
    {
        if (!self::$dbConnected) {
            $this->markTestSkipped('Database connection required');
        }

        $data = [
            'WoLgID' => self::$testLangId,
            'WoText' => 'testgettext',
            'WoStatus' => 1,
            'WoTranslation' => 'translation',
        ];
        $createResult = $this->service->create($data);

        $text = $this->service->getWordText($createResult['id']);
        $this->assertEquals('testgettext', $text);
    }

    public function testGetWordTextReturnsNullForNonExistent(): void
    {
        if (!self::$dbConnected) {
            $this->markTestSkipped('Database connection required');
        }

        $result = $this->service->getWordText(999999);
        $this->assertNull($result);
    }

    // ===== Integration tests =====

    public function testCreateUpdateFindRoundTrip(): void
    {
        if (!self::$dbConnected) {
            $this->markTestSkipped('Database connection required');
        }

        // Create
        $createData = [
            'WoLgID' => self::$testLangId,
            'WoText' => 'testroundtrip',
            'WoStatus' => 1,
            'WoTranslation' => 'original',
            'WoRomanization' => 'roman1',
        ];
        $createResult = $this->service->create($createData);
        $wordId = $createResult['id'];

        // Verify create
        $word = $this->service->findById($wordId);
        $this->assertEquals('original', $word['WoTranslation']);

        // Update
        $updateData = [
            'WoText' => 'testroundtrip',
            'WoStatus' => 4,
            'WoOldStatus' => 1,
            'WoTranslation' => 'modified',
            'WoRomanization' => 'roman2',
        ];
        $this->service->update($wordId, $updateData);

        // Verify update
        $updatedWord = $this->service->findById($wordId);
        $this->assertEquals('modified', $updatedWord['WoTranslation']);
        $this->assertEquals('4', $updatedWord['WoStatus']);
        $this->assertEquals('roman2', $updatedWord['WoRomanization']);

        // Find by text
        $foundId = $this->service->findByText('testroundtrip', self::$testLangId);
        $this->assertEquals($wordId, $foundId);
    }
}
