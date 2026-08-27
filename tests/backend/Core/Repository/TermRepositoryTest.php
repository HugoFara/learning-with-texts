<?php

declare(strict_types=1);

namespace Lwt\Tests\Core\Repository;

use Lwt\Modules\Vocabulary\Domain\Term;
use Lwt\Modules\Language\Domain\ValueObject\LanguageId;
use Lwt\Modules\Vocabulary\Domain\ValueObject\TermId;
use Lwt\Modules\Vocabulary\Domain\ValueObject\TermStatus;
use Lwt\Modules\Vocabulary\Infrastructure\MySqlTermRepository;
use Lwt\Shared\Infrastructure\Bootstrap\EnvLoader;
use Lwt\Shared\Infrastructure\Globals;
use Lwt\Shared\Infrastructure\Database\Configuration;
use Lwt\Shared\Infrastructure\Database\Connection;
use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * Tests for the MySqlTermRepository class.
 *
 */
#[CoversClass(MySqlTermRepository::class)]
class TermRepositoryTest extends TestCase
{
    private static bool $dbConnected = false;
    private MySqlTermRepository $repository;
    private static int $testLanguageId = 0;
    private static array $testTermIds = [];

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
            // Create a test language
            $prefix = '';
            Connection::query(
                "INSERT INTO {$prefix}languages (
                    LgName, LgDict1URI, LgDict2URI, LgGoogleTranslateURI,
                    LgTextSize, LgRegexpSplitSentences, LgRegexpWordCharacters,
                    LgRemoveSpaces, LgSplitEachChar, LgRightToLeft, LgShowRomanization
                ) VALUES (
                    'TermRepoTest_Language', 'https://dict.test/lwt_term', '', '',
                    100, '.!?', 'a-zA-Z',
                    0, 0, 0, 1
                )"
            );
            self::$testLanguageId = (int) mysqli_insert_id(Globals::getDbConnection());
        }
    }

    public static function tearDownAfterClass(): void
    {
        if (!self::$dbConnected) {
            return;
        }

        $prefix = '';
        // Clean up test terms
        Connection::query("DELETE FROM {$prefix}words WHERE WoText LIKE 'TermRepoTest_%'");
        // Clean up test language
        if (self::$testLanguageId > 0) {
            Connection::query("DELETE FROM {$prefix}languages WHERE LgID = " . self::$testLanguageId);
        }
    }

    protected function setUp(): void
    {
        $this->repository = new MySqlTermRepository();
    }

    protected function tearDown(): void
    {
        if (!self::$dbConnected) {
            return;
        }

        // Clean up terms created during this test
        $prefix = '';
        Connection::query("DELETE FROM {$prefix}words WHERE WoText LIKE 'TermRepoTest_%'");
        self::$testTermIds = [];
    }

    /**
     * Helper to create a test term directly in DB.
     */
    private function createTestTermInDb(
        string $text,
        int $status = 1,
        string $translation = '',
        int $wordCount = 1
    ): int {
        if (!self::$dbConnected) {
            $this->markTestSkipped('Database connection required');
        }

        $prefix = '';
        $textLc = mb_strtolower($text, 'UTF-8');
        $conn = Globals::getDbConnection();
        $escapedText = mysqli_real_escape_string($conn, $text);
        $escapedTextLc = mysqli_real_escape_string($conn, $textLc);
        $escapedTranslation = mysqli_real_escape_string($conn, $translation);

        Connection::query(
            "INSERT INTO {$prefix}words (
                WoLgID, WoText, WoTextLC, WoStatus, WoTranslation, WoSentence,
                WoRomanization, WoWordCount, WoCreated, WoStatusChanged
            ) VALUES (
                " . self::$testLanguageId . ", '$escapedText', '$escapedTextLc', $status,
                '$escapedTranslation', '', '', $wordCount, NOW(), NOW()
            )"
        );
        $id = (int) mysqli_insert_id($conn);
        self::$testTermIds[] = $id;
        return $id;
    }

    // ===== find() tests =====

    public function testFindReturnsTerm(): void
    {
        if (!self::$dbConnected) {
            $this->markTestSkipped('Database connection required');
        }

        $id = $this->createTestTermInDb('TermRepoTest_Find');

        $result = $this->repository->find($id);

        $this->assertInstanceOf(Term::class, $result);
        $this->assertEquals($id, $result->id()->toInt());
        $this->assertEquals('TermRepoTest_Find', $result->text());
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

        $term = Term::create(
            LanguageId::fromInt(self::$testLanguageId),
            'TermRepoTest_Insert',
            'Test translation'
        );

        $id = $this->repository->save($term);

        $this->assertGreaterThan(0, $id);
        $this->assertEquals($id, $term->id()->toInt());

        // Verify in database
        $found = $this->repository->find($id);
        $this->assertNotNull($found);
        $this->assertEquals('TermRepoTest_Insert', $found->text());

        self::$testTermIds[] = $id;
    }

    public function testSaveUpdatesExistingEntity(): void
    {
        if (!self::$dbConnected) {
            $this->markTestSkipped('Database connection required');
        }

        $id = $this->createTestTermInDb('TermRepoTest_Update');
        $term = $this->repository->find($id);

        $term->updateTranslation('Updated translation');
        $this->repository->save($term);

        $updated = $this->repository->find($id);
        $this->assertEquals('Updated translation', $updated->translation());
    }

    // ===== delete() tests =====

    public function testDeleteById(): void
    {
        if (!self::$dbConnected) {
            $this->markTestSkipped('Database connection required');
        }

        $id = $this->createTestTermInDb('TermRepoTest_DeleteById');

        $result = $this->repository->delete($id);

        $this->assertTrue($result);
        $this->assertNull($this->repository->find($id));
    }

    public function testDeleteReturnsFalseForNonExistent(): void
    {
        if (!self::$dbConnected) {
            $this->markTestSkipped('Database connection required');
        }

        $result = $this->repository->delete(999999);

        $this->assertFalse($result);
    }

    // ===== exists() tests =====

    public function testExists(): void
    {
        if (!self::$dbConnected) {
            $this->markTestSkipped('Database connection required');
        }

        $id = $this->createTestTermInDb('TermRepoTest_Exists');

        $this->assertTrue($this->repository->exists($id));
        $this->assertFalse($this->repository->exists(999999));
    }

    // ===== count() tests =====

    public function testCountWithCriteria(): void
    {
        if (!self::$dbConnected) {
            $this->markTestSkipped('Database connection required');
        }

        $this->createTestTermInDb('TermRepoTest_Count1');
        $this->createTestTermInDb('TermRepoTest_Count2');

        $count = $this->repository->count(['WoLgID' => self::$testLanguageId]);

        $this->assertGreaterThanOrEqual(2, $count);
    }

    // ===== findByLanguage() tests =====

    public function testFindByLanguage(): void
    {
        if (!self::$dbConnected) {
            $this->markTestSkipped('Database connection required');
        }

        $this->createTestTermInDb('TermRepoTest_Lang1');
        $this->createTestTermInDb('TermRepoTest_Lang2');

        $terms = $this->repository->findByLanguage(self::$testLanguageId);

        $this->assertIsArray($terms);
        $this->assertGreaterThanOrEqual(2, count($terms));
        $this->assertContainsOnlyInstancesOf(Term::class, $terms);
    }

    // ===== findByTextLc() tests =====

    public function testFindByTextLc(): void
    {
        if (!self::$dbConnected) {
            $this->markTestSkipped('Database connection required');
        }

        $this->createTestTermInDb('TermRepoTest_UniqueText');

        $found = $this->repository->findByTextLc(self::$testLanguageId, 'termrepotest_uniquetext');

        $this->assertNotNull($found);
        $this->assertEquals('TermRepoTest_UniqueText', $found->text());
    }

    public function testFindByTextLcNotFound(): void
    {
        if (!self::$dbConnected) {
            $this->markTestSkipped('Database connection required');
        }

        $found = $this->repository->findByTextLc(self::$testLanguageId, 'nonexistenttext12345');

        $this->assertNull($found);
    }

    // ===== termExists() tests =====

    public function testTermExists(): void
    {
        if (!self::$dbConnected) {
            $this->markTestSkipped('Database connection required');
        }

        $this->createTestTermInDb('TermRepoTest_TermExists');

        $this->assertTrue(
            $this->repository->termExists(self::$testLanguageId, 'termrepotest_termexists')
        );
        $this->assertFalse(
            $this->repository->termExists(self::$testLanguageId, 'nonexistentterm')
        );
    }

    public function testTermExistsWithExclude(): void
    {
        if (!self::$dbConnected) {
            $this->markTestSkipped('Database connection required');
        }

        $id = $this->createTestTermInDb('TermRepoTest_TermExclude');

        // Should not find if excluding the same term
        $this->assertFalse(
            $this->repository->termExists(
                self::$testLanguageId,
                'termrepotest_termexclude',
                $id
            )
        );
    }

    // ===== countByLanguage() tests =====

    public function testCountByLanguage(): void
    {
        if (!self::$dbConnected) {
            $this->markTestSkipped('Database connection required');
        }

        $initialCount = $this->repository->countByLanguage(self::$testLanguageId);

        $this->createTestTermInDb('TermRepoTest_CountLang1');
        $this->createTestTermInDb('TermRepoTest_CountLang2');

        $newCount = $this->repository->countByLanguage(self::$testLanguageId);

        $this->assertEquals($initialCount + 2, $newCount);
    }

    // ===== findByStatus() tests =====

    public function testFindByStatus(): void
    {
        if (!self::$dbConnected) {
            $this->markTestSkipped('Database connection required');
        }

        $this->createTestTermInDb('TermRepoTest_Status1', TermStatus::NEW);
        $this->createTestTermInDb('TermRepoTest_Status2', TermStatus::LEARNED);

        $newTerms = $this->repository->findByStatus(TermStatus::NEW, self::$testLanguageId);
        $learnedTerms = $this->repository->findByStatus(TermStatus::LEARNED, self::$testLanguageId);

        $this->assertContainsOnlyInstancesOf(Term::class, $newTerms);
        $this->assertContainsOnlyInstancesOf(Term::class, $learnedTerms);
    }

    // ===== findLearning() tests =====

    public function testFindLearning(): void
    {
        if (!self::$dbConnected) {
            $this->markTestSkipped('Database connection required');
        }

        $this->createTestTermInDb('TermRepoTest_Learning1', TermStatus::NEW);
        $this->createTestTermInDb('TermRepoTest_Learning2', TermStatus::LEARNING_2);
        $this->createTestTermInDb('TermRepoTest_NotLearning', TermStatus::WELL_KNOWN);

        $learning = $this->repository->findLearning(self::$testLanguageId);

        $texts = array_map(fn(Term $t) => $t->text(), $learning);
        $this->assertContains('TermRepoTest_Learning1', $texts);
        $this->assertContains('TermRepoTest_Learning2', $texts);
        $this->assertNotContains('TermRepoTest_NotLearning', $texts);
    }

    // ===== findKnown() tests =====

    public function testFindKnown(): void
    {
        if (!self::$dbConnected) {
            $this->markTestSkipped('Database connection required');
        }

        $this->createTestTermInDb('TermRepoTest_Known1', TermStatus::LEARNED);
        $this->createTestTermInDb('TermRepoTest_Known2', TermStatus::WELL_KNOWN);
        $this->createTestTermInDb('TermRepoTest_Unknown', TermStatus::NEW);

        $known = $this->repository->findKnown(self::$testLanguageId);

        $texts = array_map(fn(Term $t) => $t->text(), $known);
        $this->assertContains('TermRepoTest_Known1', $texts);
        $this->assertContains('TermRepoTest_Known2', $texts);
        $this->assertNotContains('TermRepoTest_Unknown', $texts);
    }

    // ===== findIgnored() tests =====

    public function testFindIgnored(): void
    {
        if (!self::$dbConnected) {
            $this->markTestSkipped('Database connection required');
        }

        $this->createTestTermInDb('TermRepoTest_Ignored', TermStatus::IGNORED);
        $this->createTestTermInDb('TermRepoTest_NotIgnored', TermStatus::NEW);

        $ignored = $this->repository->findIgnored(self::$testLanguageId);

        $texts = array_map(fn(Term $t) => $t->text(), $ignored);
        $this->assertContains('TermRepoTest_Ignored', $texts);
        $this->assertNotContains('TermRepoTest_NotIgnored', $texts);
    }

    // ===== findMultiWord() tests =====

    public function testFindMultiWord(): void
    {
        if (!self::$dbConnected) {
            $this->markTestSkipped('Database connection required');
        }

        $this->createTestTermInDb('TermRepoTest_SingleWord', 1, '', 1);
        $this->createTestTermInDb('TermRepoTest_Multi Word', 1, '', 2);

        $multiWord = $this->repository->findMultiWord(self::$testLanguageId);

        $texts = array_map(fn(Term $t) => $t->text(), $multiWord);
        $this->assertContains('TermRepoTest_Multi Word', $texts);
        $this->assertNotContains('TermRepoTest_SingleWord', $texts);
    }

    // ===== updateStatus() tests =====

    public function testUpdateStatus(): void
    {
        if (!self::$dbConnected) {
            $this->markTestSkipped('Database connection required');
        }

        $id = $this->createTestTermInDb('TermRepoTest_UpdateStatus', TermStatus::NEW);

        $this->assertTrue($this->repository->updateStatus($id, TermStatus::LEARNED));

        $found = $this->repository->find($id);
        $this->assertEquals(TermStatus::LEARNED, $found->status()->toInt());
    }

    // ===== updateTranslation() tests =====

    public function testUpdateTranslation(): void
    {
        if (!self::$dbConnected) {
            $this->markTestSkipped('Database connection required');
        }

        $id = $this->createTestTermInDb('TermRepoTest_UpdateTrans');

        $this->assertTrue($this->repository->updateTranslation($id, 'New translation'));

        $found = $this->repository->find($id);
        $this->assertEquals('New translation', $found->translation());
    }

    // ===== updateRomanization() tests =====

    public function testUpdateRomanization(): void
    {
        if (!self::$dbConnected) {
            $this->markTestSkipped('Database connection required');
        }

        $id = $this->createTestTermInDb('TermRepoTest_UpdateRoman');

        $this->assertTrue($this->repository->updateRomanization($id, 'romaji'));

        $found = $this->repository->find($id);
        $this->assertEquals('romaji', $found->romanization());
    }

    // ===== updateSentence() tests =====

    public function testUpdateSentence(): void
    {
        if (!self::$dbConnected) {
            $this->markTestSkipped('Database connection required');
        }

        $id = $this->createTestTermInDb('TermRepoTest_UpdateSent');

        $this->assertTrue($this->repository->updateSentence($id, 'Example sentence.'));

        $found = $this->repository->find($id);
        $this->assertEquals('Example sentence.', $found->sentence());
    }

    // ===== getForSelect() tests =====

    public function testGetForSelect(): void
    {
        if (!self::$dbConnected) {
            $this->markTestSkipped('Database connection required');
        }

        $this->createTestTermInDb('TermRepoTest_Select1');

        $options = $this->repository->getForSelect(self::$testLanguageId);

        $this->assertIsArray($options);
        $this->assertNotEmpty($options);

        $firstOption = $options[0];
        $this->assertArrayHasKey('id', $firstOption);
        $this->assertArrayHasKey('text', $firstOption);
        $this->assertArrayHasKey('language_id', $firstOption);
    }

    // ===== getBasicInfo() tests =====

    public function testGetBasicInfo(): void
    {
        if (!self::$dbConnected) {
            $this->markTestSkipped('Database connection required');
        }

        $id = $this->createTestTermInDb('TermRepoTest_BasicInfo', TermStatus::NEW, 'Translation');

        $info = $this->repository->getBasicInfo($id);

        $this->assertNotNull($info);
        $this->assertEquals($id, $info['id']);
        $this->assertEquals('TermRepoTest_BasicInfo', $info['text']);
        $this->assertEquals(self::$testLanguageId, $info['language_id']);
        $this->assertEquals(TermStatus::NEW, $info['status']);
        $this->assertTrue($info['has_translation']);
    }

    public function testGetBasicInfoNotFound(): void
    {
        if (!self::$dbConnected) {
            $this->markTestSkipped('Database connection required');
        }

        $info = $this->repository->getBasicInfo(999999);

        $this->assertNull($info);
    }

    // ===== findPaginated() tests =====

    public function testFindPaginated(): void
    {
        if (!self::$dbConnected) {
            $this->markTestSkipped('Database connection required');
        }

        for ($i = 1; $i <= 5; $i++) {
            $this->createTestTermInDb("TermRepoTest_Paginated$i");
        }

        $result = $this->repository->findPaginated(
            self::$testLanguageId,
            1,
            3,
            'WoText',
            'ASC'
        );

        $this->assertArrayHasKey('items', $result);
        $this->assertArrayHasKey('total', $result);
        $this->assertArrayHasKey('page', $result);
        $this->assertArrayHasKey('per_page', $result);
        $this->assertArrayHasKey('total_pages', $result);

        $this->assertLessThanOrEqual(3, count($result['items']));
        $this->assertEquals(1, $result['page']);
        $this->assertEquals(3, $result['per_page']);
    }

    // ===== searchByText() tests =====

    public function testSearchByText(): void
    {
        if (!self::$dbConnected) {
            $this->markTestSkipped('Database connection required');
        }

        $this->createTestTermInDb('TermRepoTest_SearchAlpha');
        $this->createTestTermInDb('TermRepoTest_SearchBeta');
        $this->createTestTermInDb('TermRepoTest_Different');

        $results = $this->repository->searchByText('TermRepoTest_Search', self::$testLanguageId);

        $this->assertGreaterThanOrEqual(2, count($results));
        foreach ($results as $term) {
            $this->assertStringContainsString('Search', $term->text());
        }
    }

    // ===== searchByTranslation() tests =====

    public function testSearchByTranslation(): void
    {
        if (!self::$dbConnected) {
            $this->markTestSkipped('Database connection required');
        }

        $this->createTestTermInDb('TermRepoTest_TransSearch1', 1, 'apple fruit');
        $this->createTestTermInDb('TermRepoTest_TransSearch2', 1, 'fruit salad');
        $this->createTestTermInDb('TermRepoTest_TransSearch3', 1, 'vegetable');

        $results = $this->repository->searchByTranslation('fruit', self::$testLanguageId);

        $this->assertGreaterThanOrEqual(2, count($results));
    }

    // ===== getStatistics() tests =====

    public function testGetStatistics(): void
    {
        if (!self::$dbConnected) {
            $this->markTestSkipped('Database connection required');
        }

        $this->createTestTermInDb('TermRepoTest_Stats1', TermStatus::NEW);
        $this->createTestTermInDb('TermRepoTest_Stats2', TermStatus::LEARNED);
        $this->createTestTermInDb('TermRepoTest_Stats3', TermStatus::IGNORED);
        $this->createTestTermInDb('TermRepoTest_Stats4', TermStatus::NEW, '', 2);

        $stats = $this->repository->getStatistics(self::$testLanguageId);

        $this->assertArrayHasKey('total', $stats);
        $this->assertArrayHasKey('learning', $stats);
        $this->assertArrayHasKey('known', $stats);
        $this->assertArrayHasKey('ignored', $stats);
        $this->assertArrayHasKey('multi_word', $stats);

        $this->assertGreaterThanOrEqual(4, $stats['total']);
        $this->assertGreaterThanOrEqual(1, $stats['known']);
        $this->assertGreaterThanOrEqual(1, $stats['ignored']);
    }

    // ===== getStatusDistribution() tests =====

    public function testGetStatusDistribution(): void
    {
        if (!self::$dbConnected) {
            $this->markTestSkipped('Database connection required');
        }

        $this->createTestTermInDb('TermRepoTest_Dist1', TermStatus::NEW);
        $this->createTestTermInDb('TermRepoTest_Dist2', TermStatus::LEARNING_2);
        $this->createTestTermInDb('TermRepoTest_Dist3', TermStatus::LEARNED);

        $distribution = $this->repository->getStatusDistribution(self::$testLanguageId);

        $this->assertIsArray($distribution);
        $this->assertArrayHasKey(TermStatus::NEW, $distribution);
        $this->assertArrayHasKey(TermStatus::LEARNED, $distribution);
    }

    // ===== getLanguagesWithTerms() tests =====

    public function testGetLanguagesWithTerms(): void
    {
        if (!self::$dbConnected) {
            $this->markTestSkipped('Database connection required');
        }

        $this->createTestTermInDb('TermRepoTest_LangCheck');

        $languageIds = $this->repository->getLanguagesWithTerms();

        $this->assertContains(self::$testLanguageId, $languageIds);
    }

    // ===== deleteMultiple() tests =====

    public function testDeleteMultiple(): void
    {
        if (!self::$dbConnected) {
            $this->markTestSkipped('Database connection required');
        }

        $id1 = $this->createTestTermInDb('TermRepoTest_DelMulti1');
        $id2 = $this->createTestTermInDb('TermRepoTest_DelMulti2');

        $deleted = $this->repository->deleteMultiple([$id1, $id2]);

        $this->assertEquals(2, $deleted);
        $this->assertNull($this->repository->find($id1));
        $this->assertNull($this->repository->find($id2));
    }

    // ===== updateStatusMultiple() tests =====

    public function testUpdateStatusMultiple(): void
    {
        if (!self::$dbConnected) {
            $this->markTestSkipped('Database connection required');
        }

        $id1 = $this->createTestTermInDb('TermRepoTest_UpdMulti1', TermStatus::NEW);
        $id2 = $this->createTestTermInDb('TermRepoTest_UpdMulti2', TermStatus::NEW);

        $updated = $this->repository->updateStatusMultiple([$id1, $id2], TermStatus::LEARNED);

        $this->assertEquals(2, $updated);

        $term1 = $this->repository->find($id1);
        $term2 = $this->repository->find($id2);

        $this->assertEquals(TermStatus::LEARNED, $term1->status()->toInt());
        $this->assertEquals(TermStatus::LEARNED, $term2->status()->toInt());
    }

    // ===== findRecent() tests =====

    public function testFindRecent(): void
    {
        if (!self::$dbConnected) {
            $this->markTestSkipped('Database connection required');
        }

        $this->createTestTermInDb('TermRepoTest_Recent1');
        $this->createTestTermInDb('TermRepoTest_Recent2');

        $recent = $this->repository->findRecent(self::$testLanguageId, 10);

        $this->assertContainsOnlyInstancesOf(Term::class, $recent);
        $this->assertGreaterThanOrEqual(2, count($recent));
    }

    // ===== getWordCountDistribution() tests =====

    public function testGetWordCountDistribution(): void
    {
        if (!self::$dbConnected) {
            $this->markTestSkipped('Database connection required');
        }

        $this->createTestTermInDb('TermRepoTest_WC1', 1, '', 1);
        $this->createTestTermInDb('TermRepoTest_WC2', 1, '', 2);
        $this->createTestTermInDb('TermRepoTest_WC3', 1, '', 1);

        $distribution = $this->repository->getWordCountDistribution(self::$testLanguageId);

        $this->assertIsArray($distribution);
        $this->assertArrayHasKey(1, $distribution);
        $this->assertGreaterThanOrEqual(2, $distribution[1]);
    }
}
