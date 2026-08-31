<?php

declare(strict_types=1);

namespace Lwt\Tests\Modules\Vocabulary\Services;

use Lwt\Shared\Infrastructure\Bootstrap\EnvLoader;
use Lwt\Shared\Infrastructure\Globals;
use Lwt\Modules\Vocabulary\Application\Services\MultiWordService;
use Lwt\Modules\Vocabulary\Application\Services\WordCrudService;
use Lwt\Shared\Infrastructure\Database\Configuration;
use Lwt\Shared\Infrastructure\Database\Connection;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for the MultiWordService class.
 *
 * Tests multi-word expression operations.
 */
class MultiWordServiceTest extends TestCase
{
    private static bool $dbConnected = false;
    private static int $testLangId = 0;
    private MultiWordService $service;
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
        $this->service = new MultiWordService();
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

    // ===== createMultiWord() tests =====

    public function testCreateMultiWordCreatesExpression(): void
    {
        if (!self::$dbConnected) {
            $this->markTestSkipped('Database connection required');
        }

        $data = [
            'lgid' => self::$testLangId,
            'text' => 'test multi word',
            'textlc' => 'test multi word',
            'status' => 1,
            'translation' => 'multi word translation',
            'sentence' => 'This is a {test multi word} sentence.',
            'roman' => 'test romanization',
            'wordcount' => 3,
        ];

        // Buffer output since insertExpressions outputs JS
        ob_start();
        $result = $this->service->createMultiWord($data);
        ob_end_clean();

        $this->assertArrayHasKey('id', $result);
        $this->assertArrayHasKey('message', $result);
        $this->assertGreaterThan(0, $result['id']);

        // Verify word was created
        $word = $this->crudService->findById($result['id']);
        $this->assertNotNull($word);
        $this->assertEquals('test multi word', $word['WoTextLC']);
        $this->assertEquals('1', $word['WoStatus']);
        $this->assertEquals('3', $word['WoWordCount']);
    }

    // ===== updateMultiWord() tests =====

    public function testUpdateMultiWordUpdatesExpression(): void
    {
        if (!self::$dbConnected) {
            $this->markTestSkipped('Database connection required');
        }

        // First create a multi-word (buffer output)
        $createData = [
            'lgid' => self::$testLangId,
            'text' => 'update multi word',
            'textlc' => 'update multi word',
            'status' => 1,
            'translation' => 'original translation',
            'sentence' => '',
            'roman' => '',
            'wordcount' => 3,
        ];
        ob_start();
        $created = $this->service->createMultiWord($createData);
        ob_end_clean();
        $wid = $created['id'];

        // Now update it
        $updateData = [
            'text' => 'update multi word',
            'textlc' => 'update multi word',
            'translation' => 'updated translation',
            'sentence' => 'Updated sentence.',
            'roman' => 'updated roman',
        ];

        $result = $this->service->updateMultiWord($wid, $updateData, 1, 2);

        $this->assertEquals($wid, $result['id']);
        $this->assertEquals(2, $result['status']);

        // Verify update
        $word = $this->crudService->findById($wid);
        $this->assertEquals('updated translation', $word['WoTranslation']);
        $this->assertEquals('2', $word['WoStatus']);
    }

    // ===== getMultiWordData() tests =====

    public function testGetMultiWordDataReturnsData(): void
    {
        if (!self::$dbConnected) {
            $this->markTestSkipped('Database connection required');
        }

        // Create a multi-word (buffer output)
        $createData = [
            'lgid' => self::$testLangId,
            'text' => 'get multi word',
            'textlc' => 'get multi word',
            'status' => 2,
            'translation' => 'get translation',
            'sentence' => 'Get sentence.',
            'roman' => 'get roman',
            'wordcount' => 3,
        ];
        ob_start();
        $created = $this->service->createMultiWord($createData);
        ob_end_clean();

        $result = $this->service->getMultiWordData($created['id']);

        $this->assertNotNull($result);
        $this->assertEquals('get multi word', $result['text']);
        $this->assertEquals(self::$testLangId, $result['lgid']);
        $this->assertEquals('get translation', $result['translation']);
        $this->assertEquals(2, $result['status']);
    }

    public function testGetMultiWordDataReturnsNullForNonExistent(): void
    {
        if (!self::$dbConnected) {
            $this->markTestSkipped('Database connection required');
        }

        $result = $this->service->getMultiWordData(999999);
        $this->assertNull($result);
    }

    // ===== findMultiWordByText() tests =====

    public function testFindMultiWordByTextFindsWord(): void
    {
        if (!self::$dbConnected) {
            $this->markTestSkipped('Database connection required');
        }

        // Create a multi-word (buffer output)
        $createData = [
            'lgid' => self::$testLangId,
            'text' => 'find multi word',
            'textlc' => 'find multi word',
            'status' => 1,
            'translation' => '*',
            'sentence' => '',
            'roman' => '',
            'wordcount' => 3,
        ];
        ob_start();
        $created = $this->service->createMultiWord($createData);
        ob_end_clean();

        $result = $this->service->findMultiWordByText('find multi word', self::$testLangId);

        $this->assertEquals($created['id'], $result);
    }

    public function testFindMultiWordByTextReturnsNullWhenNotFound(): void
    {
        if (!self::$dbConnected) {
            $this->markTestSkipped('Database connection required');
        }

        $result = $this->service->findMultiWordByText('nonexistent multi word xyz', self::$testLangId);
        $this->assertNull($result);
    }
}
