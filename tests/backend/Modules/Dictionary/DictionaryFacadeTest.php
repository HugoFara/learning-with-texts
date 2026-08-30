<?php

/**
 * Unit tests for the DictionaryFacade class.
 *
 * PHP version 8.2
 *
 * @category Tests
 * @package  Lwt\Tests\Modules\Dictionary
 * @author   LWT Development Team
 * @license  Unlicense <http://unlicense.org/>
 * @since    3.0.0
 */

declare(strict_types=1);

namespace Lwt\Tests\Modules\Dictionary;

use Lwt\Shared\Infrastructure\Bootstrap\EnvLoader;
use Lwt\Shared\Infrastructure\Globals;
use Lwt\Shared\Infrastructure\Database\Configuration;
use Lwt\Shared\Infrastructure\Database\Connection;
use Lwt\Shared\Infrastructure\Database\QueryBuilder;
use Lwt\Modules\Dictionary\Domain\LocalDictionary;
use Lwt\Modules\Dictionary\Application\Services\LocalDictionaryService;
use Lwt\Modules\Dictionary\Infrastructure\Import\CsvImporter;
use Lwt\Modules\Dictionary\Infrastructure\Import\JsonImporter;
use Lwt\Modules\Dictionary\Infrastructure\Import\StarDictImporter;
use Lwt\Modules\Dictionary\Application\DictionaryFacade;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * Unit tests for the DictionaryFacade class.
 *
 * Tests the business logic for local dictionary management.
 */
class DictionaryFacadeTest extends TestCase
{
    private static bool $dbConnected = false;
    private static bool $tablesExist = false;
    private DictionaryFacade $facade;
    private LocalDictionaryService $service;
    private static int $testLanguageId = 0;

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

        // Check if required tables exist
        if (self::$dbConnected) {
            $tables = Connection::preparedFetchValue(
                "SELECT COUNT(*) as value FROM information_schema.tables " .
                "WHERE table_schema = ? AND table_name = 'local_dictionaries'",
                [$testDbname]
            );
            self::$tablesExist = ((int)($tables ?? 0)) > 0;

            // Create a test language if needed
            if (self::$tablesExist) {
                $langId = Connection::fetchValue(
                    "SELECT LgID AS value FROM languages LIMIT 1"
                );
                if ($langId !== null) {
                    self::$testLanguageId = (int)$langId;
                }
            }
        }
    }

    protected function setUp(): void
    {
        $this->service = new LocalDictionaryService();
        $this->facade = new DictionaryFacade($this->service);
    }

    protected function tearDown(): void
    {
        // Clean up test dictionaries
        if (self::$dbConnected && self::$tablesExist) {
            QueryBuilder::table('local_dictionaries')
                ->where('LdName', 'LIKE', 'Test Dict%')
                ->delete();
        }
    }

    private function skipIfNoDatabase(): void
    {
        if (!self::$dbConnected) {
            $this->markTestSkipped('Database connection required');
        }
    }

    private function skipIfNoTables(): void
    {
        $this->skipIfNoDatabase();
        if (!self::$tablesExist) {
            $this->markTestSkipped('local_dictionaries table required - run migrations');
        }
    }

    private function skipIfNoLanguage(): void
    {
        $this->skipIfNoTables();
        if (self::$testLanguageId === 0) {
            $this->markTestSkipped('No languages in database to test');
        }
    }

    // ===== Constructor tests =====

    public function testCanBeInstantiated(): void
    {
        $this->assertInstanceOf(DictionaryFacade::class, $this->facade);
    }

    // ===== getAllForLanguage() tests =====

    public function testGetAllForLanguageReturnsArray(): void
    {
        $this->skipIfNoLanguage();

        $result = $this->facade->getAllForLanguage(self::$testLanguageId);

        $this->assertIsArray($result);
    }

    public function testGetAllForLanguageReturnsLocalDictionaryInstances(): void
    {
        $this->skipIfNoLanguage();

        // Create a test dictionary first
        $dictId = $this->facade->create(
            self::$testLanguageId,
            'Test Dict ' . uniqid()
        );

        $result = $this->facade->getAllForLanguage(self::$testLanguageId);

        $this->assertNotEmpty($result);
        $this->assertContainsOnlyInstancesOf(LocalDictionary::class, $result);

        // Cleanup
        $this->facade->delete($dictId);
    }

    public function testGetAllForLanguageReturnsEmptyForNonExistentLanguage(): void
    {
        $this->skipIfNoTables();

        $result = $this->facade->getAllForLanguage(999999);

        $this->assertIsArray($result);
        $this->assertEmpty($result);
    }

    // ===== getForLanguage() tests =====

    public function testGetForLanguageReturnsArray(): void
    {
        $this->skipIfNoLanguage();

        $result = $this->facade->getForLanguage(self::$testLanguageId);

        $this->assertIsArray($result);
    }

    public function testGetForLanguageOnlyReturnsEnabledDictionaries(): void
    {
        $this->skipIfNoLanguage();

        // Create enabled and disabled dictionaries
        $enabledId = $this->facade->create(
            self::$testLanguageId,
            'Test Dict Enabled ' . uniqid()
        );

        $disabledId = $this->facade->create(
            self::$testLanguageId,
            'Test Dict Disabled ' . uniqid()
        );

        // Disable one dictionary
        $disabled = $this->facade->getById($disabledId);
        if ($disabled !== null) {
            $disabled->disable();
            $this->facade->update($disabled);
        }

        $result = $this->facade->getForLanguage(self::$testLanguageId);
        $resultIds = array_map(fn($d) => $d->id(), $result);

        $this->assertContains($enabledId, $resultIds);
        $this->assertNotContains($disabledId, $resultIds);

        // Cleanup
        $this->facade->delete($enabledId);
        $this->facade->delete($disabledId);
    }

    // ===== getLocalDictMode() tests =====

    public function testGetLocalDictModeReturnsInt(): void
    {
        $this->skipIfNoLanguage();

        $result = $this->facade->getLocalDictMode(self::$testLanguageId);

        $this->assertIsInt($result);
        $this->assertGreaterThanOrEqual(0, $result);
        $this->assertLessThanOrEqual(3, $result);
    }

    public function testGetLocalDictModeReturnsZeroForNonExistentLanguage(): void
    {
        $this->skipIfNoTables();

        $result = $this->facade->getLocalDictMode(999999);

        $this->assertSame(0, $result);
    }

    // ===== getById() tests =====

    public function testGetByIdReturnsNullForNonExistent(): void
    {
        $this->skipIfNoTables();

        $result = $this->facade->getById(999999);

        $this->assertNull($result);
    }

    public function testGetByIdReturnsLocalDictionary(): void
    {
        $this->skipIfNoLanguage();

        $dictId = $this->facade->create(
            self::$testLanguageId,
            'Test Dict GetById ' . uniqid()
        );

        $result = $this->facade->getById($dictId);

        $this->assertInstanceOf(LocalDictionary::class, $result);
        $this->assertSame($dictId, $result->id());

        // Cleanup
        $this->facade->delete($dictId);
    }

    // ===== create() tests =====

    public function testCreateReturnsDictionaryId(): void
    {
        $this->skipIfNoLanguage();

        $dictId = $this->facade->create(
            self::$testLanguageId,
            'Test Dict Create ' . uniqid()
        );

        $this->assertIsInt($dictId);
        $this->assertGreaterThan(0, $dictId);

        // Cleanup
        $this->facade->delete($dictId);
    }

    public function testCreateWithAllParameters(): void
    {
        $this->skipIfNoLanguage();

        $name = 'Test Dict Full ' . uniqid();
        $description = 'Test description';

        $dictId = $this->facade->create(
            self::$testLanguageId,
            $name,
            'json',
            $description
        );

        $dict = $this->facade->getById($dictId);

        $this->assertNotNull($dict);
        $this->assertSame($name, $dict->name());
        $this->assertSame('json', $dict->sourceFormat());
        $this->assertSame($description, $dict->description());

        // Cleanup
        $this->facade->delete($dictId);
    }

    public function testCreateWithDefaultFormat(): void
    {
        $this->skipIfNoLanguage();

        $dictId = $this->facade->create(
            self::$testLanguageId,
            'Test Dict Default ' . uniqid()
        );

        $dict = $this->facade->getById($dictId);

        $this->assertNotNull($dict);
        $this->assertSame('csv', $dict->sourceFormat());

        // Cleanup
        $this->facade->delete($dictId);
    }

    // ===== update() tests =====

    public function testUpdateReturnsTrue(): void
    {
        $this->skipIfNoLanguage();

        $dictId = $this->facade->create(
            self::$testLanguageId,
            'Test Dict Update ' . uniqid()
        );

        $dict = $this->facade->getById($dictId);
        $this->assertNotNull($dict);

        $dict->rename('Updated Name');
        $result = $this->facade->update($dict);

        $this->assertTrue($result);

        // Verify update
        $updated = $this->facade->getById($dictId);
        $this->assertSame('Updated Name', $updated->name());

        // Cleanup
        $this->facade->delete($dictId);
    }

    public function testUpdateCanDisableDictionary(): void
    {
        $this->skipIfNoLanguage();

        $dictId = $this->facade->create(
            self::$testLanguageId,
            'Test Dict Disable ' . uniqid()
        );

        $dict = $this->facade->getById($dictId);
        $this->assertTrue($dict->isEnabled());

        $dict->disable();
        $this->facade->update($dict);

        $updated = $this->facade->getById($dictId);
        $this->assertFalse($updated->isEnabled());

        // Cleanup
        $this->facade->delete($dictId);
    }

    // ===== delete() tests =====

    public function testDeleteReturnsTrue(): void
    {
        $this->skipIfNoLanguage();

        $dictId = $this->facade->create(
            self::$testLanguageId,
            'Test Dict Delete ' . uniqid()
        );

        $result = $this->facade->delete($dictId);

        $this->assertTrue($result);
        $this->assertNull($this->facade->getById($dictId));
    }

    public function testDeleteReturnsFalseForNonExistent(): void
    {
        $this->skipIfNoTables();

        $result = $this->facade->delete(999999);

        $this->assertFalse($result);
    }

    // ===== lookup() tests =====

    public function testLookupReturnsArray(): void
    {
        $this->skipIfNoLanguage();

        $result = $this->facade->lookup(self::$testLanguageId, 'test');

        $this->assertIsArray($result);
    }

    public function testLookupReturnsEmptyForNonExistentTerm(): void
    {
        $this->skipIfNoLanguage();

        $result = $this->facade->lookup(self::$testLanguageId, 'nonexistent_term_xyz123');

        $this->assertIsArray($result);
        $this->assertEmpty($result);
    }

    public function testLookupFindsAddedEntry(): void
    {
        $this->skipIfNoLanguage();

        // Create dictionary and add entry
        $dictId = $this->facade->create(
            self::$testLanguageId,
            'Test Dict Lookup ' . uniqid()
        );

        $uniqueTerm = 'testterm' . uniqid();
        $this->facade->addEntriesBatch($dictId, [
            ['term' => $uniqueTerm, 'definition' => 'Test definition']
        ]);

        $result = $this->facade->lookup(self::$testLanguageId, $uniqueTerm);

        $this->assertNotEmpty($result);
        $this->assertSame($uniqueTerm, $result[0]['term']);
        $this->assertSame('Test definition', $result[0]['definition']);

        // Cleanup
        $this->facade->delete($dictId);
    }

    public function testLookupIsCaseInsensitive(): void
    {
        $this->skipIfNoLanguage();

        $dictId = $this->facade->create(
            self::$testLanguageId,
            'Test Dict Case ' . uniqid()
        );

        $this->facade->addEntriesBatch($dictId, [
            ['term' => 'TestWord', 'definition' => 'A test word']
        ]);

        // Lookup with different cases
        $result1 = $this->facade->lookup(self::$testLanguageId, 'testword');
        $result2 = $this->facade->lookup(self::$testLanguageId, 'TESTWORD');
        $result3 = $this->facade->lookup(self::$testLanguageId, 'TestWord');

        $this->assertNotEmpty($result1);
        $this->assertNotEmpty($result2);
        $this->assertNotEmpty($result3);

        // Cleanup
        $this->facade->delete($dictId);
    }

    // ===== lookupPrefix() tests =====

    public function testLookupPrefixReturnsArray(): void
    {
        $this->skipIfNoLanguage();

        $result = $this->facade->lookupPrefix(self::$testLanguageId, 'test');

        $this->assertIsArray($result);
    }

    public function testLookupPrefixRespectsLimit(): void
    {
        $this->skipIfNoLanguage();

        $dictId = $this->facade->create(
            self::$testLanguageId,
            'Test Dict Prefix ' . uniqid()
        );

        $prefix = 'prefix' . uniqid();
        $entries = [];
        for ($i = 0; $i < 15; $i++) {
            $entries[] = ['term' => $prefix . '_' . $i, 'definition' => "Definition $i"];
        }
        $this->facade->addEntriesBatch($dictId, $entries);

        $result = $this->facade->lookupPrefix(self::$testLanguageId, $prefix, 5);

        $this->assertCount(5, $result);

        // Cleanup
        $this->facade->delete($dictId);
    }

    public function testLookupPrefixDefaultLimit(): void
    {
        $this->skipIfNoLanguage();

        $dictId = $this->facade->create(
            self::$testLanguageId,
            'Test Dict Prefix2 ' . uniqid()
        );

        $prefix = 'pref' . uniqid();
        $entries = [];
        for ($i = 0; $i < 15; $i++) {
            $entries[] = ['term' => $prefix . '_' . $i, 'definition' => "Definition $i"];
        }
        $this->facade->addEntriesBatch($dictId, $entries);

        $result = $this->facade->lookupPrefix(self::$testLanguageId, $prefix);

        // Default limit is 10
        $this->assertCount(10, $result);

        // Cleanup
        $this->facade->delete($dictId);
    }

    // ===== addEntriesBatch() tests =====

    public function testAddEntriesBatchReturnsCount(): void
    {
        $this->skipIfNoLanguage();

        $dictId = $this->facade->create(
            self::$testLanguageId,
            'Test Dict Batch ' . uniqid()
        );

        $entries = [
            ['term' => 'word1', 'definition' => 'def1'],
            ['term' => 'word2', 'definition' => 'def2'],
            ['term' => 'word3', 'definition' => 'def3'],
        ];

        $result = $this->facade->addEntriesBatch($dictId, $entries);

        $this->assertSame(['added' => 3, 'skipped' => 0], $result);

        // Cleanup
        $this->facade->delete($dictId);
    }

    public function testAddEntriesBatchWithOptionalFields(): void
    {
        $this->skipIfNoLanguage();

        $dictId = $this->facade->create(
            self::$testLanguageId,
            'Test Dict OptFields ' . uniqid()
        );

        $entries = [
            [
                'term' => 'word',
                'definition' => 'def',
                'reading' => 'pronunciation',
                'pos' => 'noun'
            ],
        ];

        $this->facade->addEntriesBatch($dictId, $entries);

        $result = $this->facade->lookup(self::$testLanguageId, 'word');

        $this->assertNotEmpty($result);
        $this->assertSame('pronunciation', $result[0]['reading']);
        $this->assertSame('noun', $result[0]['pos']);

        // Cleanup
        $this->facade->delete($dictId);
    }

    // ===== getEntries() tests =====

    public function testGetEntriesReturnsStructuredArray(): void
    {
        $this->skipIfNoLanguage();

        $dictId = $this->facade->create(
            self::$testLanguageId,
            'Test Dict Entries ' . uniqid()
        );

        $this->facade->addEntriesBatch($dictId, [
            ['term' => 'entry1', 'definition' => 'def1'],
        ]);

        $result = $this->facade->getEntries($dictId);

        $this->assertIsArray($result);
        $this->assertArrayHasKey('entries', $result);
        $this->assertArrayHasKey('total', $result);
        $this->assertArrayHasKey('page', $result);
        $this->assertArrayHasKey('perPage', $result);

        // Cleanup
        $this->facade->delete($dictId);
    }

    public function testGetEntriesPagination(): void
    {
        $this->skipIfNoLanguage();

        $dictId = $this->facade->create(
            self::$testLanguageId,
            'Test Dict Pagination ' . uniqid()
        );

        // Add 5 entries
        $entries = [];
        for ($i = 1; $i <= 5; $i++) {
            $entries[] = ['term' => "pagword$i", 'definition' => "def$i"];
        }
        $this->facade->addEntriesBatch($dictId, $entries);

        // Get page 1 with 2 per page
        $page1 = $this->facade->getEntries($dictId, 1, 2);
        $this->assertCount(2, $page1['entries']);
        $this->assertSame(5, $page1['total']);
        $this->assertSame(1, $page1['page']);
        $this->assertSame(2, $page1['perPage']);

        // Get page 2
        $page2 = $this->facade->getEntries($dictId, 2, 2);
        $this->assertCount(2, $page2['entries']);

        // Get page 3
        $page3 = $this->facade->getEntries($dictId, 3, 2);
        $this->assertCount(1, $page3['entries']);

        // Cleanup
        $this->facade->delete($dictId);
    }

    // ===== hasLocalDictionaries() tests =====

    public function testHasLocalDictionariesReturnsBool(): void
    {
        $this->skipIfNoLanguage();

        $result = $this->facade->hasLocalDictionaries(self::$testLanguageId);

        $this->assertIsBool($result);
    }

    public function testHasLocalDictionariesReturnsFalseForNonExistentLanguage(): void
    {
        $this->skipIfNoTables();

        $result = $this->facade->hasLocalDictionaries(999999);

        $this->assertFalse($result);
    }

    public function testHasLocalDictionariesReturnsTrueWhenExists(): void
    {
        $this->skipIfNoLanguage();

        $dictId = $this->facade->create(
            self::$testLanguageId,
            'Test Dict Exists ' . uniqid()
        );

        $result = $this->facade->hasLocalDictionaries(self::$testLanguageId);

        $this->assertTrue($result);

        // Cleanup
        $this->facade->delete($dictId);
    }

    public function testHasLocalDictionariesIgnoresDisabledDictionaries(): void
    {
        $this->skipIfNoLanguage();

        // First, delete any existing dictionaries for our test language
        $existingDicts = $this->facade->getAllForLanguage(self::$testLanguageId);
        foreach ($existingDicts as $dict) {
            if (str_starts_with($dict->name(), 'Test Dict')) {
                $this->facade->delete($dict->id());
            }
        }

        // Create a disabled dictionary
        $dictId = $this->facade->create(
            self::$testLanguageId,
            'Test Dict DisabledCheck ' . uniqid()
        );

        $dict = $this->facade->getById($dictId);
        $dict->disable();
        $this->facade->update($dict);

        // Check if there are any OTHER enabled dictionaries for this language
        $enabledCount = QueryBuilder::table('local_dictionaries')
            ->where('LdLgID', '=', self::$testLanguageId)
            ->where('LdEnabled', '=', 1)
            ->countPrepared();

        // If there are other enabled dictionaries, we can't test this properly
        if ($enabledCount > 0) {
            $this->facade->delete($dictId);
            $this->markTestSkipped('Other enabled dictionaries exist for test language');
        }

        $result = $this->facade->hasLocalDictionaries(self::$testLanguageId);

        $this->assertFalse($result);

        // Cleanup
        $this->facade->delete($dictId);
    }

    // ===== getImporter() tests =====

    public function testGetImporterReturnsCsvImporter(): void
    {
        $result = $this->facade->getImporter('csv');

        $this->assertInstanceOf(CsvImporter::class, $result);
    }

    public function testGetImporterReturnsCsvImporterForTsv(): void
    {
        $result = $this->facade->getImporter('tsv');

        $this->assertInstanceOf(CsvImporter::class, $result);
    }

    public function testGetImporterReturnsJsonImporter(): void
    {
        $result = $this->facade->getImporter('json');

        $this->assertInstanceOf(JsonImporter::class, $result);
    }

    public function testGetImporterReturnsStarDictImporter(): void
    {
        $result = $this->facade->getImporter('stardict');

        $this->assertInstanceOf(StarDictImporter::class, $result);
    }

    public function testGetImporterReturnsStarDictImporterForIfo(): void
    {
        $result = $this->facade->getImporter('ifo');

        $this->assertInstanceOf(StarDictImporter::class, $result);
    }

    public function testGetImporterThrowsForUnsupportedFormat(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Unsupported format: unsupported');

        $this->facade->getImporter('unsupported');
    }

    public function testGetImporterAutoDetectsFromFilename(): void
    {
        $csvResult = $this->facade->getImporter('auto', 'dictionary.csv');
        $this->assertInstanceOf(CsvImporter::class, $csvResult);

        $tsvResult = $this->facade->getImporter('auto', 'dictionary.tsv');
        $this->assertInstanceOf(CsvImporter::class, $tsvResult);

        $jsonResult = $this->facade->getImporter('auto', 'dictionary.json');
        $this->assertInstanceOf(JsonImporter::class, $jsonResult);

        $starDictResult = $this->facade->getImporter('auto', 'dictionary.ifo');
        $this->assertInstanceOf(StarDictImporter::class, $starDictResult);
    }

    public function testGetImporterDefaultsToCsvForUnknownExtension(): void
    {
        $result = $this->facade->getImporter('auto', 'dictionary.xyz');

        $this->assertInstanceOf(CsvImporter::class, $result);
    }

    // ===== Integration tests =====

    public function testCreateLookupDeleteWorkflow(): void
    {
        $this->skipIfNoLanguage();

        // Create dictionary
        $dictName = 'Test Dict Workflow ' . uniqid();
        $dictId = $this->facade->create(self::$testLanguageId, $dictName);

        // Verify it exists
        $dict = $this->facade->getById($dictId);
        $this->assertNotNull($dict);
        $this->assertSame($dictName, $dict->name());

        // Add entries
        $term = 'workflow_term_' . uniqid();
        $this->facade->addEntriesBatch($dictId, [
            ['term' => $term, 'definition' => 'workflow definition']
        ]);

        // Lookup
        $results = $this->facade->lookup(self::$testLanguageId, $term);
        $this->assertNotEmpty($results);
        $this->assertSame('workflow definition', $results[0]['definition']);

        // Delete
        $this->assertTrue($this->facade->delete($dictId));

        // Verify gone
        $this->assertNull($this->facade->getById($dictId));

        // Lookup should return empty now
        $resultsAfter = $this->facade->lookup(self::$testLanguageId, $term);
        $this->assertEmpty($resultsAfter);
    }

    public function testMultipleDictionariesPriorityOrder(): void
    {
        $this->skipIfNoLanguage();

        // Create two dictionaries with different priorities
        $dict1Id = $this->facade->create(
            self::$testLanguageId,
            'Test Dict Priority1 ' . uniqid()
        );
        $dict2Id = $this->facade->create(
            self::$testLanguageId,
            'Test Dict Priority2 ' . uniqid()
        );

        // Set priorities
        $dict1 = $this->facade->getById($dict1Id);
        $dict2 = $this->facade->getById($dict2Id);

        $dict1->setPriority(2);
        $dict2->setPriority(1);

        $this->facade->update($dict1);
        $this->facade->update($dict2);

        // Get all for language - should be ordered by priority
        $all = $this->facade->getForLanguage(self::$testLanguageId);

        // Find our test dicts in the result
        $foundDict1 = null;
        $foundDict2 = null;
        $dict1Pos = -1;
        $dict2Pos = -1;

        foreach ($all as $pos => $d) {
            if ($d->id() === $dict1Id) {
                $foundDict1 = $d;
                $dict1Pos = $pos;
            }
            if ($d->id() === $dict2Id) {
                $foundDict2 = $d;
                $dict2Pos = $pos;
            }
        }

        $this->assertNotNull($foundDict1);
        $this->assertNotNull($foundDict2);

        // dict2 (priority 1) should come before dict1 (priority 2)
        $this->assertLessThan($dict1Pos, $dict2Pos);

        // Cleanup
        $this->facade->delete($dict1Id);
        $this->facade->delete($dict2Id);
    }

    // ===== Over-long headwords (issue #250) =====

    /**
     * An entry whose headword exceeds LeTerm must not take its batch-mates
     * down with it. Under STRICT_ALL_TABLES the over-long value used to fail
     * the whole multi-row INSERT, which aborted the entire import — one
     * 293-character headword cost all 517,534 FreeDict entries.
     */
    public function testAddEntriesBatchSkipsOverLongTermAndKeepsTheRest(): void
    {
        $this->skipIfNoLanguage();

        $dictId = $this->facade->create(
            self::$testLanguageId,
            'Test Dict Overlong ' . uniqid()
        );

        $good1 = 'shortterm1' . uniqid();
        $good2 = 'shortterm2' . uniqid();

        $result = $this->facade->addEntriesBatch($dictId, [
            ['term' => $good1, 'definition' => 'first'],
            ['term' => str_repeat('x', 251), 'definition' => 'unstorable headword'],
            ['term' => $good2, 'definition' => 'second'],
        ]);

        $this->assertSame(2, $result['added'], 'Both storable entries should be inserted');
        $this->assertSame(1, $result['skipped'], 'The over-long headword should be skipped');

        // The neighbours really are queryable, not merely counted.
        $this->assertNotEmpty($this->facade->lookup(self::$testLanguageId, $good1));
        $this->assertNotEmpty($this->facade->lookup(self::$testLanguageId, $good2));

        // And the over-long headword is genuinely absent from the table.
        $this->assertSame(2, $this->service->getEntryCount($dictId));

        $this->facade->delete($dictId);
    }

    public function testAddEntriesBatchReportsZeroSkippedWhenAllEntriesFit(): void
    {
        $this->skipIfNoLanguage();

        $dictId = $this->facade->create(
            self::$testLanguageId,
            'Test Dict NoSkip ' . uniqid()
        );

        $result = $this->facade->addEntriesBatch($dictId, [
            ['term' => 'fitsfine' . uniqid(), 'definition' => 'ok'],
        ]);

        $this->assertSame(1, $result['added']);
        $this->assertSame(0, $result['skipped']);

        $this->facade->delete($dictId);
    }
}
