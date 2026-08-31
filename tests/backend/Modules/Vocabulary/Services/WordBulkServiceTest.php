<?php

declare(strict_types=1);

namespace Lwt\Tests\Modules\Vocabulary\Services;

use Lwt\Shared\Infrastructure\Bootstrap\EnvLoader;
use Lwt\Shared\Infrastructure\Globals;
use Lwt\Modules\Vocabulary\Application\Services\WordBulkService;
use Lwt\Modules\Vocabulary\Application\Services\WordCrudService;
use Lwt\Shared\Infrastructure\Database\Configuration;
use Lwt\Shared\Infrastructure\Database\Connection;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for the WordBulkService class.
 *
 * Tests bulk operations on words/terms.
 */
class WordBulkServiceTest extends TestCase
{
    private static bool $dbConnected = false;
    private static int $testLangId = 0;
    private WordBulkService $service;
    private WordCrudService $crudService;

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
        $this->service = new WordBulkService();
        $this->crudService = new WordCrudService();
    }

    protected function tearDown(): void
    {
        if (!self::$dbConnected) {
            return;
        }

        // Clean up test words after each test
        Connection::query("DELETE FROM words WHERE WoText LIKE 'test%'");
    }

    // ===== deleteMultiple() tests =====

    public function testDeleteMultipleWords(): void
    {
        if (!self::$dbConnected) {
            $this->markTestSkipped('Database connection required');
        }

        // Create multiple words
        $ids = [];
        for ($i = 1; $i <= 3; $i++) {
            $data = [
                'WoLgID' => self::$testLangId,
                'WoText' => "testdelmulti$i",
                'WoStatus' => 1,
                'WoTranslation' => "translation $i",
            ];
            $result = $this->crudService->create($data);
            $ids[] = $result['id'];
        }

        // Delete them all
        $count = $this->service->deleteMultiple($ids);
        $this->assertEquals(3, $count);

        // Verify they're all gone
        foreach ($ids as $id) {
            $this->assertNull($this->crudService->findById($id));
        }
    }

    public function testDeleteMultipleWithEmptyArray(): void
    {
        if (!self::$dbConnected) {
            $this->markTestSkipped('Database connection required');
        }

        $result = $this->service->deleteMultiple([]);
        $this->assertEquals(0, $result);
    }

    // ===== updateStatusMultiple() tests =====

    public function testUpdateStatusMultipleAbsolute(): void
    {
        if (!self::$dbConnected) {
            $this->markTestSkipped('Database connection required');
        }

        // Create multiple words
        $ids = [];
        for ($i = 1; $i <= 3; $i++) {
            $data = [
                'WoLgID' => self::$testLangId,
                'WoText' => "teststatmulti$i",
                'WoStatus' => 1,
                'WoTranslation' => "translation $i",
            ];
            $result = $this->crudService->create($data);
            $ids[] = $result['id'];
        }

        // Update all to status 5
        $count = $this->service->updateStatusMultiple($ids, 5);
        $this->assertEquals(3, $count);

        // Verify
        foreach ($ids as $id) {
            $word = $this->crudService->findById($id);
            $this->assertEquals('5', $word['WoStatus']);
        }
    }

    public function testUpdateStatusMultipleIncrement(): void
    {
        if (!self::$dbConnected) {
            $this->markTestSkipped('Database connection required');
        }

        // Create a word with status 2
        $data = [
            'WoLgID' => self::$testLangId,
            'WoText' => 'testincrement',
            'WoStatus' => 2,
            'WoTranslation' => 'translation',
        ];
        $createResult = $this->crudService->create($data);
        $wordId = $createResult['id'];

        // Increment status
        $this->service->updateStatusMultiple([$wordId], 1, true);

        // Verify status is now 3
        $word = $this->crudService->findById($wordId);
        $this->assertEquals('3', $word['WoStatus']);
    }

    public function testUpdateStatusMultipleDecrement(): void
    {
        if (!self::$dbConnected) {
            $this->markTestSkipped('Database connection required');
        }

        // Create a word with status 4
        $data = [
            'WoLgID' => self::$testLangId,
            'WoText' => 'testdecrement',
            'WoStatus' => 4,
            'WoTranslation' => 'translation',
        ];
        $createResult = $this->crudService->create($data);
        $wordId = $createResult['id'];

        // Decrement status
        $this->service->updateStatusMultiple([$wordId], -1, true);

        // Verify status is now 3
        $word = $this->crudService->findById($wordId);
        $this->assertEquals('3', $word['WoStatus']);
    }

    public function testUpdateStatusMultipleWithEmptyArray(): void
    {
        if (!self::$dbConnected) {
            $this->markTestSkipped('Database connection required');
        }

        $result = $this->service->updateStatusMultiple([], 5);
        $this->assertEquals(0, $result);
    }

    // ===== deleteSentencesMultiple() tests =====

    public function testDeleteSentencesMultiple(): void
    {
        if (!self::$dbConnected) {
            $this->markTestSkipped('Database connection required');
        }

        // Create a word with a sentence
        $data = [
            'WoLgID' => self::$testLangId,
            'WoText' => 'testdelsent',
            'WoStatus' => 1,
            'WoTranslation' => 'translation',
            'WoSentence' => 'This is a {testdelsent} sentence.',
        ];
        $createResult = $this->crudService->create($data);
        $wordId = $createResult['id'];

        // Delete sentences
        $count = $this->service->deleteSentencesMultiple([$wordId]);
        $this->assertEquals(1, $count);

        // Verify sentence is null
        $word = $this->crudService->findById($wordId);
        $this->assertNull($word['WoSentence']);
    }

    // ===== toLowercaseMultiple() tests =====

    public function testToLowercaseMultiple(): void
    {
        if (!self::$dbConnected) {
            $this->markTestSkipped('Database connection required');
        }

        // Create a word with mixed case
        $data = [
            'WoLgID' => self::$testLangId,
            'WoText' => 'TestLower',
            'WoStatus' => 1,
            'WoTranslation' => 'translation',
        ];
        $createResult = $this->crudService->create($data);
        $wordId = $createResult['id'];

        // Convert to lowercase
        $count = $this->service->toLowercaseMultiple([$wordId]);
        $this->assertEquals(1, $count);

        // Verify text is lowercase
        $word = $this->crudService->findById($wordId);
        $this->assertEquals('testlower', $word['WoText']);
    }

    // ===== capitalizeMultiple() tests =====

    public function testCapitalizeMultiple(): void
    {
        if (!self::$dbConnected) {
            $this->markTestSkipped('Database connection required');
        }

        // Create a lowercase word
        $data = [
            'WoLgID' => self::$testLangId,
            'WoText' => 'testcapital',
            'WoStatus' => 1,
            'WoTranslation' => 'translation',
        ];
        $createResult = $this->crudService->create($data);
        $wordId = $createResult['id'];

        // Capitalize
        $count = $this->service->capitalizeMultiple([$wordId]);
        $this->assertEquals(1, $count);

        // Verify text is capitalized
        $word = $this->crudService->findById($wordId);
        $this->assertEquals('Testcapital', $word['WoText']);
    }

    // ===== bulkSaveTerms() tests =====

    public function testBulkSaveTermsCreatesMultipleWords(): void
    {
        if (!self::$dbConnected) {
            $this->markTestSkipped('Database connection required');
        }

        $terms = [
            ['lg' => self::$testLangId, 'text' => 'testbulk1', 'status' => 1, 'trans' => 'bulk trans 1'],
            ['lg' => self::$testLangId, 'text' => 'testbulk2', 'status' => 2, 'trans' => 'bulk trans 2'],
            ['lg' => self::$testLangId, 'text' => 'testbulk3', 'status' => 3, 'trans' => ''],
        ];

        $maxWoId = $this->service->bulkSaveTerms($terms);

        // Verify all words were created
        $word1 = $this->crudService->findByText('testbulk1', self::$testLangId);
        $word2 = $this->crudService->findByText('testbulk2', self::$testLangId);
        $word3 = $this->crudService->findByText('testbulk3', self::$testLangId);

        $this->assertNotNull($word1);
        $this->assertNotNull($word2);
        $this->assertNotNull($word3);

        // Verify all IDs are greater than maxWoId
        $this->assertGreaterThan($maxWoId, $word1);
        $this->assertGreaterThan($maxWoId, $word2);
        $this->assertGreaterThan($maxWoId, $word3);

        // Verify statuses
        $wordData1 = $this->crudService->findById($word1);
        $wordData2 = $this->crudService->findById($word2);
        $wordData3 = $this->crudService->findById($word3);

        $this->assertEquals('1', $wordData1['WoStatus']);
        $this->assertEquals('2', $wordData2['WoStatus']);
        $this->assertEquals('3', $wordData3['WoStatus']);

        // Verify empty translation becomes '*'
        $this->assertEquals('*', $wordData3['WoTranslation']);
    }

    public function testBulkSaveTermsWithEmptyArrayReturnsMaxId(): void
    {
        if (!self::$dbConnected) {
            $this->markTestSkipped('Database connection required');
        }

        $maxWoId = $this->service->bulkSaveTerms([]);

        // Should return a non-negative value (the current max ID)
        $this->assertGreaterThanOrEqual(0, $maxWoId);
    }

    // ===== getNewWordsAfter() tests =====

    public function testGetNewWordsAfterReturnsNewWords(): void
    {
        if (!self::$dbConnected) {
            $this->markTestSkipped('Database connection required');
        }

        // Get current max ID
        $maxBefore = $this->service->bulkSaveTerms([]);

        // Create a word
        $data = [
            'WoLgID' => self::$testLangId,
            'WoText' => 'testnewafter',
            'WoStatus' => 1,
            'WoTranslation' => 'translation',
        ];
        $this->crudService->create($data);

        // Get new words
        $res = $this->service->getNewWordsAfter($maxBefore);

        $found = false;
        foreach ($res as $record) {
            if ($record['WoTextLC'] === 'testnewafter') {
                $found = true;
                break;
            }
        }

        $this->assertTrue($found);
    }
}
