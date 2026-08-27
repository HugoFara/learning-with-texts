<?php

declare(strict_types=1);

namespace Lwt\Tests\Modules\Review\Infrastructure;

use Lwt\Modules\Review\Infrastructure\MySqlReviewRepository;
use Lwt\Modules\Review\Domain\ReviewConfiguration;
use Lwt\Modules\Review\Domain\ReviewWord;
use Lwt\Modules\Text\Application\Services\SentenceService;
use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\MockObject\MockObject;
use ReflectionMethod;

/**
 * Unit tests for MySqlReviewRepository.
 *
 * Tests review persistence operations and word retrieval.
 */
class MySqlReviewRepositoryTest extends TestCase
{
    /** @var SentenceService&MockObject */
    private SentenceService $sentenceService;

    private MySqlReviewRepository $repository;

    protected function setUp(): void
    {
        parent::setUp();
        if (!defined('LWT_TEST_DB_AVAILABLE') || !LWT_TEST_DB_AVAILABLE) {
            $this->markTestSkipped('Database connection required');
        }
        $this->sentenceService = $this->createMock(SentenceService::class);
        $this->repository = new MySqlReviewRepository($this->sentenceService);
    }

    // =========================================================================
    // Constructor Tests
    // =========================================================================

    public function testConstructorCreatesValidRepository(): void
    {
        $this->assertInstanceOf(MySqlReviewRepository::class, $this->repository);
    }

    public function testConstructorAcceptsNullSentenceService(): void
    {
        $repository = new MySqlReviewRepository(null);
        $this->assertInstanceOf(MySqlReviewRepository::class, $repository);
    }

    public function testConstructorAcceptsCustomSentenceService(): void
    {
        $sentenceService = $this->createMock(SentenceService::class);
        $repository = new MySqlReviewRepository($sentenceService);

        $this->assertInstanceOf(MySqlReviewRepository::class, $repository);
    }

    // =========================================================================
    // ReviewConfiguration Factory Tests
    // =========================================================================

    public function testReviewConfigurationFromLanguage(): void
    {
        $config = ReviewConfiguration::fromLanguage(1);

        $this->assertSame('lang', $config->reviewKey);
        $this->assertSame(1, $config->selection);
        $this->assertSame(1, $config->reviewType);
        $this->assertFalse($config->wordMode);
    }

    public function testReviewConfigurationFromLanguageWithWordMode(): void
    {
        $config = ReviewConfiguration::fromLanguage(1, 4, true);

        $this->assertTrue($config->wordMode);
        $this->assertSame(4, $config->reviewType);
    }

    public function testReviewConfigurationFromText(): void
    {
        $config = ReviewConfiguration::fromText(42);

        $this->assertSame('text', $config->reviewKey);
        $this->assertSame(42, $config->selection);
    }

    public function testReviewConfigurationFromWords(): void
    {
        $config = ReviewConfiguration::fromWords([1, 2, 3]);

        $this->assertSame('words', $config->reviewKey);
        $this->assertIsArray($config->selection);
        $this->assertCount(3, $config->selection);
    }

    public function testReviewConfigurationFromTexts(): void
    {
        $config = ReviewConfiguration::fromTexts([10, 20]);

        $this->assertSame('texts', $config->reviewKey);
        $this->assertIsArray($config->selection);
    }

    public function testReviewConfigurationForTableMode(): void
    {
        $config = ReviewConfiguration::forTableMode('lang', 1);

        $this->assertTrue($config->isTableMode);
        $this->assertSame('lang', $config->reviewKey);
    }

    // =========================================================================
    // ReviewConfiguration SQL Projection Tests
    // =========================================================================

    public function testToSqlProjectionPreparedForLanguage(): void
    {
        $config = ReviewConfiguration::fromLanguage(5);
        $params = [];
        $sql = $config->toSqlProjectionPrepared($params);

        $this->assertStringContainsString('words', $sql);
        $this->assertStringContainsString('WoLgID = ?', $sql);
        $this->assertEquals([5], $params);
    }

    public function testToSqlProjectionPreparedForText(): void
    {
        $config = ReviewConfiguration::fromText(42);
        $params = [];
        $sql = $config->toSqlProjectionPrepared($params);

        $this->assertStringContainsString('words', $sql);
        $this->assertStringContainsString('word_occurrences', $sql);
        $this->assertStringContainsString('Ti2TxID = ?', $sql);
        $this->assertEquals([42], $params);
    }

    public function testToSqlProjectionPreparedForWords(): void
    {
        $config = ReviewConfiguration::fromWords([1, 2, 3]);
        $params = [];
        $sql = $config->toSqlProjectionPrepared($params);

        $this->assertStringContainsString('WoID IN (?,?,?)', $sql);
        $this->assertEquals([1, 2, 3], $params);
    }

    public function testToSqlProjectionPreparedForTexts(): void
    {
        $config = ReviewConfiguration::fromTexts([10, 20, 30]);
        $params = [];
        $sql = $config->toSqlProjectionPrepared($params);

        $this->assertStringContainsString('Ti2TxID IN (?,?,?)', $sql);
        $this->assertEquals([10, 20, 30], $params);
    }

    public function testToSqlProjectionPreparedForRawSql(): void
    {
        $config = new ReviewConfiguration(
            ReviewConfiguration::KEY_RAW_SQL,
            'words WHERE WoStatus = 1'
        );
        $params = [];
        $sql = $config->toSqlProjectionPrepared($params);

        $this->assertSame('words WHERE WoStatus = 1', $sql);
        $this->assertEmpty($params);
    }

    public function testToSqlProjectionPreparedThrowsForInvalidKey(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid review key');

        $config = new ReviewConfiguration('invalid', 1);
        $params = [];
        $config->toSqlProjectionPrepared($params);
    }

    // =========================================================================
    // ReviewConfiguration URL Property Tests
    // =========================================================================

    public function testToUrlPropertyForLanguage(): void
    {
        $config = ReviewConfiguration::fromLanguage(5);

        $this->assertSame('lang=5', $config->toUrlProperty());
    }

    public function testToUrlPropertyForText(): void
    {
        $config = ReviewConfiguration::fromText(42);

        $this->assertSame('text=42', $config->toUrlProperty());
    }

    public function testToUrlPropertyForWords(): void
    {
        $config = ReviewConfiguration::fromWords([1, 2, 3]);

        $this->assertSame('selection=2', $config->toUrlProperty());
    }

    public function testToUrlPropertyForTexts(): void
    {
        $config = ReviewConfiguration::fromTexts([10, 20]);

        $this->assertSame('selection=3', $config->toUrlProperty());
    }

    // =========================================================================
    // ReviewConfiguration Validation Tests
    // =========================================================================

    public function testIsValidReturnsTrueForValidConfig(): void
    {
        $config = ReviewConfiguration::fromLanguage(1);

        $this->assertTrue($config->isValid());
    }

    public function testIsValidReturnsFalseForEmptyKey(): void
    {
        $config = new ReviewConfiguration('', 1);

        $this->assertFalse($config->isValid());
    }

    public function testGetBaseTypeReturnsTypeForSmallTypes(): void
    {
        $config1 = ReviewConfiguration::fromLanguage(1, 1);
        $config2 = ReviewConfiguration::fromLanguage(1, 2);
        $config3 = ReviewConfiguration::fromLanguage(1, 3);

        $this->assertSame(1, $config1->getBaseType());
        $this->assertSame(2, $config2->getBaseType());
        $this->assertSame(3, $config3->getBaseType());
    }

    public function testGetBaseTypeStripsWordModeOffset(): void
    {
        $config4 = ReviewConfiguration::fromLanguage(1, 4);
        $config5 = ReviewConfiguration::fromLanguage(1, 5);

        $this->assertSame(1, $config4->getBaseType());
        $this->assertSame(2, $config5->getBaseType());
    }

    public function testGetSelectionStringForSingleValue(): void
    {
        $config = ReviewConfiguration::fromLanguage(5);

        $this->assertSame('5', $config->getSelectionString());
    }

    public function testGetSelectionStringForArray(): void
    {
        $config = ReviewConfiguration::fromWords([1, 2, 3]);

        $this->assertSame('1,2,3', $config->getSelectionString());
    }

    // =========================================================================
    // ReviewWord Tests
    // =========================================================================

    public function testReviewWordFromRecord(): void
    {
        $record = [
            'WoID' => 1,
            'WoText' => 'test',
            'WoTextLC' => 'test',
            'WoTranslation' => 'testing',
            'WoRomanization' => null,
            'WoSentence' => 'This is a {test}.',
            'WoLgID' => 1,
            'WoStatus' => 3,
            'Score' => 50,
            'Days' => 5
        ];

        $word = ReviewWord::fromRecord($record);

        $this->assertSame(1, $word->id);
        $this->assertSame('test', $word->text);
        $this->assertSame('testing', $word->translation);
        $this->assertNull($word->romanization);
        $this->assertSame(3, $word->status);
        $this->assertSame(50, $word->score);
    }

    public function testReviewWordFromRecordWithRomanization(): void
    {
        $record = [
            'WoID' => 1,
            'WoText' => 'テスト',
            'WoTextLC' => 'テスト',
            'WoTranslation' => 'test',
            'WoRomanization' => 'tesuto',
            'WoSentence' => null,
            'WoLgID' => 2,
            'WoStatus' => 1,
            'Score' => 0,
            'Days' => 0
        ];

        $word = ReviewWord::fromRecord($record);

        $this->assertSame('tesuto', $word->romanization);
    }

    public function testReviewWordHasSentence(): void
    {
        $wordWith = new ReviewWord(1, 'test', 'test', 'trans', null, 'A {test} sentence.', 1, 1, 0, 0);
        $wordWithout = new ReviewWord(2, 'test', 'test', 'trans', null, null, 1, 1, 0, 0);
        $wordEmpty = new ReviewWord(3, 'test', 'test', 'trans', null, '', 1, 1, 0, 0);

        $this->assertTrue($wordWith->hasSentence());
        $this->assertFalse($wordWithout->hasSentence());
        $this->assertFalse($wordEmpty->hasSentence());
    }

    public function testReviewWordNeedsNewSentence(): void
    {
        $wordValid = new ReviewWord(1, 'test', 'test', 'trans', null, 'A {test} sentence.', 1, 1, 0, 0);
        $wordInvalid = new ReviewWord(2, 'test', 'test', 'trans', null, 'A sentence without mark.', 1, 1, 0, 0);
        $wordNull = new ReviewWord(3, 'test', 'test', 'trans', null, null, 1, 1, 0, 0);

        $this->assertFalse($wordValid->needsNewSentence());
        $this->assertTrue($wordInvalid->needsNewSentence());
        $this->assertTrue($wordNull->needsNewSentence());
    }

    public function testReviewWordGetSentenceForDisplay(): void
    {
        $wordWith = new ReviewWord(1, 'test', 'test', 'trans', null, 'A {test} sentence.', 1, 1, 0, 0);
        $wordWithout = new ReviewWord(2, 'hello', 'hello', 'trans', null, null, 1, 1, 0, 0);

        $this->assertSame('A {test} sentence.', $wordWith->getSentenceForDisplay());
        $this->assertSame('{hello}', $wordWithout->getSentenceForDisplay());
    }

    public function testReviewWordIsLearning(): void
    {
        $wordStatus1 = new ReviewWord(1, 't', 't', 'tr', null, null, 1, 1, 0, 0);
        $wordStatus5 = new ReviewWord(2, 't', 't', 'tr', null, null, 1, 5, 0, 0);
        $wordStatus98 = new ReviewWord(3, 't', 't', 'tr', null, null, 1, 98, 0, 0);
        $wordStatus99 = new ReviewWord(4, 't', 't', 'tr', null, null, 1, 99, 0, 0);

        $this->assertTrue($wordStatus1->isLearning());
        $this->assertTrue($wordStatus5->isLearning());
        $this->assertFalse($wordStatus98->isLearning());
        $this->assertFalse($wordStatus99->isLearning());
    }

    public function testReviewWordIsWellKnown(): void
    {
        $wordWellKnown = new ReviewWord(1, 't', 't', 'tr', null, null, 1, 99, 0, 0);
        $wordLearning = new ReviewWord(2, 't', 't', 'tr', null, null, 1, 3, 0, 0);

        $this->assertTrue($wordWellKnown->isWellKnown());
        $this->assertFalse($wordLearning->isWellKnown());
    }

    public function testReviewWordIsIgnored(): void
    {
        $wordIgnored = new ReviewWord(1, 't', 't', 'tr', null, null, 1, 98, 0, 0);
        $wordLearning = new ReviewWord(2, 't', 't', 'tr', null, null, 1, 3, 0, 0);

        $this->assertTrue($wordIgnored->isIgnored());
        $this->assertFalse($wordLearning->isIgnored());
    }

    public function testReviewWordToArray(): void
    {
        $word = new ReviewWord(1, 'test', 'test', 'trans', 'rom', 'sent', 2, 3, 50, 5);
        $array = $word->toArray();

        $this->assertIsArray($array);
        $this->assertSame(1, $array['id']);
        $this->assertSame('test', $array['text']);
        $this->assertSame('trans', $array['translation']);
        $this->assertSame('rom', $array['romanization']);
        $this->assertSame('sent', $array['sentence']);
        $this->assertSame(2, $array['languageId']);
        $this->assertSame(3, $array['status']);
        $this->assertSame(50, $array['score']);
        $this->assertSame(5, $array['daysOld']);
    }

    // =========================================================================
    // getFirstTranslation Tests (private method via reflection)
    // =========================================================================

    public function testGetFirstTranslationReturnsNullForEmpty(): void
    {
        $method = new ReflectionMethod(MySqlReviewRepository::class, 'getFirstTranslation');

        $result = $method->invoke($this->repository, '');

        $this->assertNull($result);
    }

    public function testGetFirstTranslationReturnsNullForStar(): void
    {
        $method = new ReflectionMethod(MySqlReviewRepository::class, 'getFirstTranslation');

        $result = $method->invoke($this->repository, '*');

        $this->assertNull($result);
    }

    public function testGetFirstTranslationReturnsSingleTranslation(): void
    {
        $method = new ReflectionMethod(MySqlReviewRepository::class, 'getFirstTranslation');

        $result = $method->invoke($this->repository, 'hello');

        $this->assertSame('hello', $result);
    }

    public function testGetFirstTranslationReturnsFirstOfMultiple(): void
    {
        $method = new ReflectionMethod(MySqlReviewRepository::class, 'getFirstTranslation');

        // Assuming separators include semicolon
        $result = $method->invoke($this->repository, 'first; second; third');

        $this->assertSame('first', $result);
    }

    public function testGetFirstTranslationTrimsWhitespace(): void
    {
        $method = new ReflectionMethod(MySqlReviewRepository::class, 'getFirstTranslation');

        $result = $method->invoke($this->repository, '  hello  ');

        $this->assertSame('hello', $result);
    }

    // =========================================================================
    // Interface Method Signature Tests
    // =========================================================================

    public function testRepositoryImplementsInterface(): void
    {
        $this->assertInstanceOf(
            \Lwt\Modules\Review\Domain\ReviewRepositoryInterface::class,
            $this->repository
        );
    }

    public function testFindNextWordForReviewMethodExists(): void
    {
        $this->assertTrue(
            method_exists($this->repository, 'findNextWordForReview'),
            'Repository should have findNextWordForReview method'
        );
    }

    public function testGetSentenceForWordMethodExists(): void
    {
        $this->assertTrue(
            method_exists($this->repository, 'getSentenceForWord'),
            'Repository should have getSentenceForWord method'
        );
    }

    public function testGetReviewCountsMethodExists(): void
    {
        $this->assertTrue(
            method_exists($this->repository, 'getReviewCounts'),
            'Repository should have getReviewCounts method'
        );
    }

    public function testGetTomorrowCountMethodExists(): void
    {
        $this->assertTrue(
            method_exists($this->repository, 'getTomorrowCount'),
            'Repository should have getTomorrowCount method'
        );
    }

    public function testGetTableWordsMethodExists(): void
    {
        $this->assertTrue(
            method_exists($this->repository, 'getTableWords'),
            'Repository should have getTableWords method'
        );
    }

    public function testUpdateWordStatusMethodExists(): void
    {
        $this->assertTrue(
            method_exists($this->repository, 'updateWordStatus'),
            'Repository should have updateWordStatus method'
        );
    }

    public function testGetWordStatusMethodExists(): void
    {
        $this->assertTrue(
            method_exists($this->repository, 'getWordStatus'),
            'Repository should have getWordStatus method'
        );
    }

    public function testGetLanguageSettingsMethodExists(): void
    {
        $this->assertTrue(
            method_exists($this->repository, 'getLanguageSettings'),
            'Repository should have getLanguageSettings method'
        );
    }

    public function testGetLanguageIdFromConfigMethodExists(): void
    {
        $this->assertTrue(
            method_exists($this->repository, 'getLanguageIdFromConfig'),
            'Repository should have getLanguageIdFromConfig method'
        );
    }

    public function testValidateSingleLanguageMethodExists(): void
    {
        $this->assertTrue(
            method_exists($this->repository, 'validateSingleLanguage'),
            'Repository should have validateSingleLanguage method'
        );
    }

    public function testGetLanguageNameMethodExists(): void
    {
        $this->assertTrue(
            method_exists($this->repository, 'getLanguageName'),
            'Repository should have getLanguageName method'
        );
    }

    public function testGetWordTextMethodExists(): void
    {
        $this->assertTrue(
            method_exists($this->repository, 'getWordText'),
            'Repository should have getWordText method'
        );
    }

    public function testGetTableReviewSettingsMethodExists(): void
    {
        $this->assertTrue(
            method_exists($this->repository, 'getTableReviewSettings'),
            'Repository should have getTableReviewSettings method'
        );
    }

    public function testGetSentenceWithAnnotationsMethodExists(): void
    {
        $this->assertTrue(
            method_exists($this->repository, 'getSentenceWithAnnotations'),
            'Repository should have getSentenceWithAnnotations method'
        );
    }

    // =========================================================================
    // Review Type Clamping Tests
    // =========================================================================

    public function testReviewTypeClampedToMinimum(): void
    {
        $config = ReviewConfiguration::fromLanguage(1, -5);

        $this->assertSame(1, $config->reviewType);
    }

    public function testReviewTypeClampedToMaximum(): void
    {
        $config = ReviewConfiguration::fromLanguage(1, 100);

        $this->assertSame(5, $config->reviewType);
    }

    public function testReviewTypeNotClampedWhenValid(): void
    {
        $config1 = ReviewConfiguration::fromLanguage(1, 1);
        $config3 = ReviewConfiguration::fromLanguage(1, 3);
        $config5 = ReviewConfiguration::fromLanguage(1, 5);

        $this->assertSame(1, $config1->reviewType);
        $this->assertSame(3, $config3->reviewType);
        $this->assertSame(5, $config5->reviewType);
    }

    // =========================================================================
    // Word Mode Detection Tests
    // =========================================================================

    public function testWordModeEnabledForTypeGreaterThan3(): void
    {
        $config4 = ReviewConfiguration::fromLanguage(1, 4);
        $config5 = ReviewConfiguration::fromLanguage(1, 5);

        $this->assertTrue($config4->wordMode);
        $this->assertTrue($config5->wordMode);
    }

    public function testWordModeDisabledForTypeLessThanOrEqual3(): void
    {
        $config1 = ReviewConfiguration::fromLanguage(1, 1);
        $config2 = ReviewConfiguration::fromLanguage(1, 2);
        $config3 = ReviewConfiguration::fromLanguage(1, 3);

        $this->assertFalse($config1->wordMode);
        $this->assertFalse($config2->wordMode);
        $this->assertFalse($config3->wordMode);
    }

    public function testWordModeExplicitlyEnabled(): void
    {
        $config = ReviewConfiguration::fromLanguage(1, 1, true);

        $this->assertTrue($config->wordMode);
    }

    // =========================================================================
    // Edge Cases for Selection
    // =========================================================================

    public function testFromWordsConvertsToIntegers(): void
    {
        $config = ReviewConfiguration::fromWords(['1', '2', '3']);

        $this->assertSame([1, 2, 3], $config->selection);
    }

    public function testFromTextsConvertsToIntegers(): void
    {
        $config = ReviewConfiguration::fromTexts(['10', '20']);

        $this->assertSame([10, 20], $config->selection);
    }

    public function testFromWordsWithEmptyArray(): void
    {
        $config = ReviewConfiguration::fromWords([]);

        $this->assertSame([], $config->selection);
    }


    public function testReviewWordFromRecordWithMissingOptionalFields(): void
    {
        $record = [
            'WoID' => 1,
            'WoText' => 'test',
            'WoTextLC' => 'test',
            'WoTranslation' => 'trans',
            // Missing WoRomanization, WoSentence, Score, Days
            'WoLgID' => 1,
            'WoStatus' => 1,
        ];

        $word = ReviewWord::fromRecord($record);

        $this->assertNull($word->romanization);
        $this->assertNull($word->sentence);
        $this->assertSame(0, $word->score);
        $this->assertSame(0, $word->daysOld);
    }

    // =========================================================================
    // Constants Tests
    // =========================================================================

    public function testReviewConfigurationConstants(): void
    {
        $this->assertSame('lang', ReviewConfiguration::KEY_LANG);
        $this->assertSame('text', ReviewConfiguration::KEY_TEXT);
        $this->assertSame('words', ReviewConfiguration::KEY_WORDS);
        $this->assertSame('texts', ReviewConfiguration::KEY_TEXTS);
        $this->assertSame('raw_sql', ReviewConfiguration::KEY_RAW_SQL);

        $this->assertSame(1, ReviewConfiguration::TYPE_TERM_TO_TRANSLATION);
        $this->assertSame(2, ReviewConfiguration::TYPE_TRANSLATION_TO_TERM);
        $this->assertSame(3, ReviewConfiguration::TYPE_SENTENCE_TO_TERM);
        $this->assertSame(4, ReviewConfiguration::TYPE_TERM_TO_TRANSLATION_WORD);
        $this->assertSame(5, ReviewConfiguration::TYPE_TRANSLATION_TO_TERM_WORD);
    }
}
