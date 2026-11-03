<?php

declare(strict_types=1);

namespace Lwt\Tests\Core\Repository;

use Lwt\Modules\Language\Domain\Language;
use Lwt\Modules\Language\Infrastructure\MySqlLanguageRepository;
use Lwt\Shared\Infrastructure\Bootstrap\EnvLoader;
use Lwt\Shared\Infrastructure\Globals;
use Lwt\Shared\Infrastructure\Database\Configuration;
use Lwt\Shared\Infrastructure\Database\Connection;
use PHPUnit\Framework\TestCase;

// Module classes loaded via autoloader

/**
 * Unit tests for the MySqlLanguageRepository class (formerly LanguageRepository).
 */
class LanguageRepositoryTest extends TestCase
{
    private static bool $dbConnected = false;
    private MySqlLanguageRepository $repository;
    private static array $testLanguageIds = [];

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
    }

    protected function setUp(): void
    {
        $this->repository = new MySqlLanguageRepository();
    }

    protected function tearDown(): void
    {
        if (!self::$dbConnected) {
            return;
        }

        // Clean up test languages after each test
        $prefix = '';
        Connection::query("DELETE FROM {$prefix}languages WHERE LgName LIKE 'RepoTest_%'");
        self::$testLanguageIds = [];
    }

    /**
     * Helper to create a test language entity.
     */
    private function createTestLanguageEntity(string $name): Language
    {
        $language = Language::create(
            $name,
            'https://dict.test/lwt_term',
            '.!?',
            'a-zA-Z'
        );
        return $language;
    }

    /**
     * Helper to create a test language directly in DB.
     */
    private function createTestLanguageInDb(string $name): int
    {
        $prefix = '';
        Connection::query(
            "INSERT INTO {$prefix}languages (
                LgName, LgDict1URI, LgDict2URI, LgGoogleTranslateURI,
                LgTextSize, LgRegexpSplitSentences, LgRegexpWordCharacters,
                LgRemoveSpaces, LgSplitEachChar, LgRightToLeft, LgShowRomanization
            ) VALUES (
                '$name', 'https://dict.test/lwt_term', '', '',
                100, '.!?', 'a-zA-Z',
                0, 0, 0, 1
            )"
        );
        $id = (int) mysqli_insert_id(Globals::getDbConnection());
        self::$testLanguageIds[] = $id;
        return $id;
    }

    // ===== find() tests =====

    public function testFindReturnsLanguage(): void
    {
        if (!self::$dbConnected) {
            $this->markTestSkipped('Database connection required');
        }

        $id = $this->createTestLanguageInDb('RepoTest_Find');

        $result = $this->repository->find($id);

        $this->assertInstanceOf(Language::class, $result);
        $this->assertEquals($id, $result->id()->toInt());
        $this->assertEquals('RepoTest_Find', $result->name());
    }

    public function testFindReturnsNullForNonExistent(): void
    {
        if (!self::$dbConnected) {
            $this->markTestSkipped('Database connection required');
        }

        $result = $this->repository->find(999999);

        $this->assertNull($result);
    }

    // ===== save() tests =====

    public function testSaveInsertsNewEntity(): void
    {
        if (!self::$dbConnected) {
            $this->markTestSkipped('Database connection required');
        }

        $language = $this->createTestLanguageEntity('RepoTest_Insert');

        $this->repository->save($language);

        // After save, the entity should have an ID assigned
        $id = $language->id()->toInt();
        $this->assertGreaterThan(0, $id);

        // Verify in database
        $found = $this->repository->find($id);
        $this->assertNotNull($found);
        $this->assertEquals('RepoTest_Insert', $found->name());

        self::$testLanguageIds[] = $id;
    }

    public function testSaveUpdatesExistingEntity(): void
    {
        if (!self::$dbConnected) {
            $this->markTestSkipped('Database connection required');
        }

        $id = $this->createTestLanguageInDb('RepoTest_Update');
        $language = $this->repository->find($id);

        $language->rename('RepoTest_Updated');
        $language->setTextSize(150);

        $this->repository->save($language);

        $updated = $this->repository->find($id);
        $this->assertEquals('RepoTest_Updated', $updated->name());
        $this->assertEquals(150, $updated->textSize());
    }

    // ===== delete() tests =====

    public function testDeleteById(): void
    {
        if (!self::$dbConnected) {
            $this->markTestSkipped('Database connection required');
        }

        $id = $this->createTestLanguageInDb('RepoTest_DeleteById');

        $this->repository->delete($id);

        $this->assertNull($this->repository->find($id));
    }

    public function testDeleteNonExistentDoesNotThrow(): void
    {
        if (!self::$dbConnected) {
            $this->markTestSkipped('Database connection required');
        }

        // Should not throw for non-existent ID
        $this->repository->delete(999999);

        $this->assertTrue(true); // If we get here, no exception was thrown
    }

    // ===== exists() tests =====

    public function testExistsReturnsTrueForExisting(): void
    {
        if (!self::$dbConnected) {
            $this->markTestSkipped('Database connection required');
        }

        $id = $this->createTestLanguageInDb('RepoTest_Exists');

        $this->assertTrue($this->repository->exists($id));
    }

    public function testExistsReturnsFalseForNonExisting(): void
    {
        if (!self::$dbConnected) {
            $this->markTestSkipped('Database connection required');
        }

        $this->assertFalse($this->repository->exists(999999));
    }

    // ===== Custom repository methods =====

    public function testFindAllActiveExcludesEmptyNames(): void
    {
        if (!self::$dbConnected) {
            $this->markTestSkipped('Database connection required');
        }

        $prefix = '';
        // Try to insert language with empty name (may already exist)
        try {
            Connection::query(
                "INSERT INTO {$prefix}languages " .
                "(LgName, LgDict1URI, LgTextSize, LgRegexpSplitSentences, LgRegexpWordCharacters) " .
                "VALUES ('', 'https://test.com', 100, '.!?', 'a-z')"
            );
        } catch (\RuntimeException $e) {
            // Empty language may already exist, that's fine
        }

        $this->createTestLanguageInDb('RepoTest_Active');

        $result = $this->repository->findAllActive();

        $names = array_map(fn($l) => $l->name(), $result);
        $this->assertNotContains('', $names);
        $this->assertContains('RepoTest_Active', $names);
    }

    public function testFindByName(): void
    {
        if (!self::$dbConnected) {
            $this->markTestSkipped('Database connection required');
        }

        $id = $this->createTestLanguageInDb('RepoTest_ByName');

        $result = $this->repository->findByName('RepoTest_ByName');

        $this->assertInstanceOf(Language::class, $result);
        $this->assertEquals($id, $result->id()->toInt());
    }

    public function testNameExists(): void
    {
        if (!self::$dbConnected) {
            $this->markTestSkipped('Database connection required');
        }

        $id = $this->createTestLanguageInDb('RepoTest_NameExists');

        $this->assertTrue($this->repository->nameExists('RepoTest_NameExists'));
        $this->assertFalse($this->repository->nameExists('NonExistent12345'));
    }

    public function testNameExistsExcludesId(): void
    {
        if (!self::$dbConnected) {
            $this->markTestSkipped('Database connection required');
        }

        $id = $this->createTestLanguageInDb('RepoTest_NameExclude');

        // Should not find duplicate when excluding its own ID
        $this->assertFalse($this->repository->nameExists('RepoTest_NameExclude', $id));
        // Should find duplicate without exclusion
        $this->assertTrue($this->repository->nameExists('RepoTest_NameExclude', null));
    }

    public function testGetAllAsDict(): void
    {
        if (!self::$dbConnected) {
            $this->markTestSkipped('Database connection required');
        }

        $this->createTestLanguageInDb('RepoTest_Dict');

        $result = $this->repository->getAllAsDict();

        $this->assertIsArray($result);
        $this->assertArrayHasKey('RepoTest_Dict', $result);
        $this->assertIsInt($result['RepoTest_Dict']);
    }

    public function testGetForSelect(): void
    {
        if (!self::$dbConnected) {
            $this->markTestSkipped('Database connection required');
        }

        $this->createTestLanguageInDb('RepoTest_Select');

        $result = $this->repository->getForSelect();

        $this->assertIsArray($result);

        // Find our test language
        $found = false;
        foreach ($result as $item) {
            if ($item['name'] === 'RepoTest_Select') {
                $found = true;
                $this->assertArrayHasKey('id', $item);
                $this->assertArrayHasKey('name', $item);
                break;
            }
        }
        $this->assertTrue($found, 'Test language should be in results');
    }

    public function testGetForSelectTruncatesLongNames(): void
    {
        if (!self::$dbConnected) {
            $this->markTestSkipped('Database connection required');
        }

        // LgName is varchar(40), so create exactly 40 chars (max allowed)
        $longName = 'RT_' . str_repeat('A', 37); // 3 + 37 = 40 chars
        $this->createTestLanguageInDb($longName);

        $result = $this->repository->getForSelect(30);

        foreach ($result as $item) {
            if (str_starts_with($item['name'], 'RT_AA')) {
                $this->assertLessThanOrEqual(33, strlen($item['name'])); // 30 + '...'
                $this->assertStringEndsWith('...', $item['name']);
                return;
            }
        }
        $this->fail('Long name language not found in results');
    }

    public function testCreateEmpty(): void
    {
        $result = $this->repository->createEmpty();

        $this->assertInstanceOf(Language::class, $result);
        $this->assertTrue($result->id()->isNew());
        $this->assertEquals('New Language', $result->name());
        $this->assertEquals(100, $result->textSize());
        $this->assertTrue($result->showRomanization());
    }

    public function testIsRightToLeft(): void
    {
        if (!self::$dbConnected) {
            $this->markTestSkipped('Database connection required');
        }

        $prefix = '';
        // Create RTL language
        Connection::query(
            "INSERT INTO {$prefix}languages (
                LgName, LgDict1URI, LgTextSize, LgRegexpSplitSentences,
                LgRegexpWordCharacters, LgRightToLeft
            ) VALUES (
                'RepoTest_RTL', 'https://dict.test', 100, '.!?', 'a-z', 1
            )"
        );
        $rtlId = (int) mysqli_insert_id(Globals::getDbConnection());
        self::$testLanguageIds[] = $rtlId;

        // Create LTR language
        $ltrId = $this->createTestLanguageInDb('RepoTest_LTR');

        $this->assertTrue($this->repository->isRightToLeft($rtlId));
        $this->assertFalse($this->repository->isRightToLeft($ltrId));
    }

    public function testGetWordCharacters(): void
    {
        if (!self::$dbConnected) {
            $this->markTestSkipped('Database connection required');
        }

        $id = $this->createTestLanguageInDb('RepoTest_WordChars');

        $result = $this->repository->getWordCharacters($id);

        $this->assertEquals('a-zA-Z', $result);
    }
}
