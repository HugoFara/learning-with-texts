<?php

declare(strict_types=1);

namespace Tests\Modules\Text\Services;

use Lwt\Modules\Text\Application\Services\SentenceService;
use Lwt\Modules\Language\Application\Services\TextParsingService;
use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\DataProvider;
use ReflectionMethod;
use ReflectionClass;

/**
 * Unit tests for SentenceService.
 *
 * Tests private helper methods directly via reflection (pure functions),
 * and uses source/signature analysis for database-dependent public methods.
 */
class SentenceServiceUnitTest extends TestCase
{
    private SentenceService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $mockTps = $this->createMock(TextParsingService::class);
        $this->service = new SentenceService($mockTps);
    }

    // =========================================================================
    // Reflection helpers
    // =========================================================================

    private function invokePrivate(string $method, mixed ...$args): mixed
    {
        $ref = new ReflectionMethod(SentenceService::class, $method);
        return $ref->invoke($this->service, ...$args);
    }

    /**
     * Extract source code of a method from SentenceService.
     */
    private static function methodSource(string $methodName): string
    {
        $ref = new ReflectionMethod(SentenceService::class, $methodName);
        $file = file_get_contents($ref->getFileName());
        $lines = explode("\n", $file);
        $start = $ref->getStartLine() - 1;
        $length = $ref->getEndLine() - $start;
        return implode("\n", array_slice($lines, $start, $length));
    }

    // =========================================================================
    // Constructor
    // =========================================================================

    #[Test]
    public function constructorWithNullCreatesInstance(): void
    {
        $service = new SentenceService(null);
        $this->assertInstanceOf(SentenceService::class, $service);
    }

    #[Test]
    public function constructorWithMockCreatesInstance(): void
    {
        $mock = $this->createMock(TextParsingService::class);
        $service = new SentenceService($mock);
        $this->assertInstanceOf(SentenceService::class, $service);
    }

    #[Test]
    public function constructorStoresTextParsingService(): void
    {
        $mock = $this->createMock(TextParsingService::class);
        $service = new SentenceService($mock);

        $ref = new ReflectionClass(SentenceService::class);
        $prop = $ref->getProperty('textParsingService');

        $this->assertSame($mock, $prop->getValue($service));
    }

    // =========================================================================
    // findSentencesFromWord — signature & source analysis
    // =========================================================================

    #[Test]
    public function findSentencesFromWordSignature(): void
    {
        $ref = new ReflectionMethod(SentenceService::class, 'findSentencesFromWord');
        $this->assertTrue($ref->isPublic());
        $this->assertSame('array', $ref->getReturnType()?->getName());
        $params = $ref->getParameters();
        $this->assertCount(4, $params);
        $this->assertSame('wid', $params[0]->getName());
        $this->assertTrue($params[0]->getType()?->allowsNull());
        $this->assertSame('wordlc', $params[1]->getName());
        $this->assertSame('lid', $params[2]->getName());
        $this->assertSame('limit', $params[3]->getName());
        $this->assertTrue($params[3]->isDefaultValueAvailable());
        $this->assertSame(-1, $params[3]->getDefaultValue());
    }

    #[Test]
    public function findSentencesFromWordNullWidUsesIsNullQuery(): void
    {
        $src = self::methodSource('findSentencesFromWord');
        $this->assertStringContainsString('Ti2WoID IS NULL', $src);
    }

    #[Test]
    public function findSentencesFromWordPositiveWidUsesEqualQuery(): void
    {
        $src = self::methodSource('findSentencesFromWord');
        $this->assertStringContainsString('Ti2WoID = ?', $src);
    }

    #[Test]
    public function findSentencesFromWordNegativeOneDelegates(): void
    {
        $src = self::methodSource('findSentencesFromWord');
        $this->assertStringContainsString('executeSentencesContainingWordQuery', $src);
    }

    #[Test]
    public function findSentencesFromWordAddsLimitClause(): void
    {
        $src = self::methodSource('findSentencesFromWord');
        $this->assertStringContainsString('LIMIT ?', $src);
    }

    #[Test]
    public function findSentencesFromWordUsesConnectionPrepare(): void
    {
        $src = self::methodSource('findSentencesFromWord');
        $this->assertStringContainsString('Connection::prepare', $src);
    }

    #[Test]
    public function findSentencesFromWordUsesBindValues(): void
    {
        $src = self::methodSource('findSentencesFromWord');
        $this->assertStringContainsString('bindValues', $src);
    }

    // =========================================================================
    // executeSentencesContainingWordQuery — source analysis
    // =========================================================================

    #[Test]
    public function executeSentencesContainingWordQueryIsPrivate(): void
    {
        $ref = new ReflectionMethod(SentenceService::class, 'executeSentencesContainingWordQuery');
        $this->assertTrue($ref->isPrivate());
    }

    #[Test]
    public function executeSentencesContainingWordQueryHandlesMecab(): void
    {
        $src = self::methodSource('executeSentencesContainingWordQuery');
        // The magic word is recognised through WordSpacing now, so that the
        // normalisation happens in one place rather than at each site (#288)
        $this->assertStringContainsString('usesMecabMagicWord', $src);
        $this->assertStringContainsString('getMecabPath', $src);
    }

    #[Test]
    public function executeSentencesContainingWordQueryHandlesRemoveSpaces(): void
    {
        $src = self::methodSource('executeSentencesContainingWordQuery');
        $this->assertStringContainsString('LgRemoveSpaces', $src);
        $this->assertStringContainsString('removeSpaces', $src);
    }

    #[Test]
    public function executeSentencesContainingWordQueryUsesRlike(): void
    {
        $src = self::methodSource('executeSentencesContainingWordQuery');
        $this->assertStringContainsString('RLIKE ?', $src);
    }

    #[Test]
    public function executeSentencesContainingWordQueryReturnsEmptyForNoLanguage(): void
    {
        $src = self::methodSource('executeSentencesContainingWordQuery');
        $this->assertStringContainsString('return []', $src);
    }

    #[Test]
    public function executeSentencesContainingWordQueryUsesQueryBuilder(): void
    {
        $src = self::methodSource('executeSentencesContainingWordQuery');
        $this->assertStringContainsString('QueryBuilder::table', $src);
        $this->assertStringContainsString('firstPrepared', $src);
    }

    // =========================================================================
    // formatSentence — signature & source analysis
    // =========================================================================

    #[Test]
    public function formatSentenceSignature(): void
    {
        $ref = new ReflectionMethod(SentenceService::class, 'formatSentence');
        $this->assertTrue($ref->isPublic());
        $this->assertSame('array', $ref->getReturnType()?->getName());
        $params = $ref->getParameters();
        $this->assertCount(3, $params);
        $this->assertSame('int', $params[0]->getType()?->getName());
        $this->assertSame('string', $params[1]->getType()?->getName());
        $this->assertSame('int', $params[2]->getType()?->getName());
    }

    #[Test]
    public function formatSentenceReturnsDefaultWhenRecordNull(): void
    {
        $src = self::methodSource('formatSentence');
        // When record is null, returns [$mode > 1 ? '' : $wordlc, $wordlc]
        $this->assertStringContainsString('return [$mode > 1', $src);
    }

    #[Test]
    public function formatSentenceHandlesRemoveSpacesLanguage(): void
    {
        $src = self::methodSource('formatSentence');
        $this->assertStringContainsString('LgRemoveSpaces', $src);
        $this->assertStringContainsString('removeSpaces', $src);
    }

    #[Test]
    public function formatSentenceHandlesMecabLanguage(): void
    {
        $src = self::methodSource('formatSentence');
        $this->assertStringContainsString('usesMecabMagicWord', $src);
    }

    #[Test]
    public function formatSentenceMode2FetchesPreviousSentence(): void
    {
        $src = self::methodSource('formatSentence');
        $this->assertStringContainsString('$mode > 1', $src);
        $this->assertStringContainsString('SeID < ?', $src);
        $this->assertStringContainsString('order by SeID desc', $src);
    }

    #[Test]
    public function formatSentenceMode3FetchesNextSentence(): void
    {
        $src = self::methodSource('formatSentence');
        $this->assertStringContainsString('$mode > 2', $src);
        $this->assertStringContainsString('SeID > ?', $src);
        $this->assertStringContainsString('order by SeID asc', $src);
    }

    #[Test]
    public function formatSentenceUsesBoldHighlight(): void
    {
        $src = self::methodSource('formatSentence');
        $this->assertStringContainsString('<b>$0</b>', $src);
    }

    #[Test]
    public function formatSentenceUsesCurlyHighlight(): void
    {
        $src = self::methodSource('formatSentence');
        $this->assertStringContainsString('{$0}', $src);
    }

    #[Test]
    public function formatSentenceUsesConnectionPreparedFetchOne(): void
    {
        $src = self::methodSource('formatSentence');
        $this->assertStringContainsString('Connection::preparedFetchOne', $src);
    }

    #[Test]
    public function formatSentenceUsesConnectionPreparedFetchValue(): void
    {
        $src = self::methodSource('formatSentence');
        $this->assertStringContainsString('Connection::preparedFetchValue', $src);
    }

    #[Test]
    public function formatSentenceHandlesSplitEachChar(): void
    {
        $src = self::methodSource('formatSentence');
        $this->assertStringContainsString('LgSplitEachChar', $src);
        $this->assertStringContainsString('splitEachChar', $src);
    }

    // =========================================================================
    // getSentenceText — signature & source analysis
    // =========================================================================

    #[Test]
    public function getSentenceTextSignature(): void
    {
        $ref = new ReflectionMethod(SentenceService::class, 'getSentenceText');
        $this->assertTrue($ref->isPublic());
        $params = $ref->getParameters();
        $this->assertCount(1, $params);
        $this->assertSame('seid', $params[0]->getName());
        $this->assertSame('int', $params[0]->getType()?->getName());
        $this->assertTrue($ref->getReturnType()?->allowsNull());
    }

    #[Test]
    public function getSentenceTextReturnsNullWhenRecordNull(): void
    {
        $src = self::methodSource('getSentenceText');
        $this->assertStringContainsString('return null', $src);
    }

    #[Test]
    public function getSentenceTextReturnsNullWhenSeTextNull(): void
    {
        $src = self::methodSource('getSentenceText');
        // Checks both null record and null SeText
        $this->assertStringContainsString("record['SeText'] === null", $src);
    }

    #[Test]
    public function getSentenceTextCallsConvertZwsForStandardLanguage(): void
    {
        $src = self::methodSource('getSentenceText');
        $this->assertStringContainsString('convertZwsToSpacing', $src);
    }

    #[Test]
    public function getSentenceTextStripsZwsForAsianLanguages(): void
    {
        $src = self::methodSource('getSentenceText');
        // For removeSpaces/MECAB: just strips ZWS
        $this->assertStringContainsString("str_replace", $src);
    }

    #[Test]
    public function getSentenceTextChecksMecab(): void
    {
        $src = self::methodSource('getSentenceText');
        // Spacing is asked as a question about the language, not about what its
        // word-characters field happens to contain (#288)
        $this->assertStringContainsString('separatesWordsWithSpaces', $src);
    }

    // =========================================================================
    // getSentenceAtPosition — signature & source analysis
    // =========================================================================

    #[Test]
    public function getSentenceAtPositionSignature(): void
    {
        $ref = new ReflectionMethod(SentenceService::class, 'getSentenceAtPosition');
        $this->assertTrue($ref->isPublic());
        $params = $ref->getParameters();
        $this->assertCount(2, $params);
        $this->assertSame('textId', $params[0]->getName());
        $this->assertSame('position', $params[1]->getName());
        $this->assertTrue($ref->getReturnType()?->allowsNull());
    }

    #[Test]
    public function getSentenceAtPositionReturnsNullWhenNoSeid(): void
    {
        $src = self::methodSource('getSentenceAtPosition');
        // First null check: no Ti2SeID found
        $this->assertStringContainsString('$seidRaw === null', $src);
    }

    #[Test]
    public function getSentenceAtPositionReturnsNullWhenNoLangRecord(): void
    {
        $src = self::methodSource('getSentenceAtPosition');
        $this->assertStringContainsString('$langRecord === null', $src);
    }

    #[Test]
    public function getSentenceAtPositionReturnsNullWhenNoTokens(): void
    {
        $src = self::methodSource('getSentenceAtPosition');
        $this->assertStringContainsString('empty($tokens)', $src);
    }

    #[Test]
    public function getSentenceAtPositionUsesContextRange100(): void
    {
        $src = self::methodSource('getSentenceAtPosition');
        $this->assertStringContainsString('$contextRange = 100', $src);
    }

    #[Test]
    public function getSentenceAtPositionTrimsLongSentences(): void
    {
        $src = self::methodSource('getSentenceAtPosition');
        // Trim at 800 chars with extractPortionAroundWord
        $this->assertStringContainsString('800', $src);
        $this->assertStringContainsString('extractPortionAroundWord', $src);
    }

    #[Test]
    public function getSentenceAtPositionFallsBackToExtractCenteredPortion(): void
    {
        $src = self::methodSource('getSentenceAtPosition');
        $this->assertStringContainsString('extractCenteredPortion', $src);
    }

    #[Test]
    public function getSentenceAtPositionUsesPreparedStatements(): void
    {
        $src = self::methodSource('getSentenceAtPosition');
        $this->assertStringContainsString('preparedFetchValue', $src);
        $this->assertStringContainsString('preparedFetchOne', $src);
        $this->assertStringContainsString('preparedFetchAll', $src);
    }

    #[Test]
    public function getSentenceAtPositionReadsSplitSentencesConfig(): void
    {
        $src = self::methodSource('getSentenceAtPosition');
        $this->assertStringContainsString('LgRegexpSplitSentences', $src);
    }

    // =========================================================================
    // getSentencesWithWord — signature & source analysis
    // =========================================================================

    #[Test]
    public function getSentencesWithWordSignature(): void
    {
        $ref = new ReflectionMethod(SentenceService::class, 'getSentencesWithWord');
        $this->assertTrue($ref->isPublic());
        $this->assertSame('array', $ref->getReturnType()?->getName());
        $params = $ref->getParameters();
        $this->assertCount(5, $params);
        $this->assertSame('lang', $params[0]->getName());
        $this->assertSame('wordlc', $params[1]->getName());
        $this->assertSame('wid', $params[2]->getName());
        $this->assertTrue($params[2]->getType()?->allowsNull());
        $this->assertSame('mode', $params[3]->getName());
        $this->assertTrue($params[3]->getType()?->allowsNull());
        $this->assertSame(0, $params[3]->getDefaultValue());
        $this->assertSame('limit', $params[4]->getName());
        $this->assertSame(20, $params[4]->getDefaultValue());
    }

    #[Test]
    public function getSentencesWithWordFiltersDuplicates(): void
    {
        $src = self::methodSource('getSentencesWithWord');
        $this->assertStringContainsString('$last != $seText', $src);
    }

    #[Test]
    public function getSentencesWithWordUsesSettingsForNullMode(): void
    {
        $src = self::methodSource('getSentencesWithWord');
        $this->assertStringContainsString('Settings::getWithDefault', $src);
        $this->assertStringContainsString('set-term-sentence-count', $src);
    }

    #[Test]
    public function getSentencesWithWordFiltersWithoutClosingBrace(): void
    {
        $src = self::methodSource('getSentencesWithWord');
        // Filters sentences that don't have a closing brace (word wasn't highlighted)
        $this->assertStringContainsString("mb_strstr(\$sent[1], '}'", $src);
    }

    #[Test]
    public function getSentencesWithWordCallsFormatSentence(): void
    {
        $src = self::methodSource('getSentencesWithWord');
        $this->assertStringContainsString('formatSentence', $src);
    }

    // =========================================================================
    // get20Sentences — signature & source analysis
    // =========================================================================

    #[Test]
    public function get20SentencesSignature(): void
    {
        $ref = new ReflectionMethod(SentenceService::class, 'get20Sentences');
        $this->assertTrue($ref->isPublic());
        $this->assertSame('string', $ref->getReturnType()?->getName());
        $params = $ref->getParameters();
        $this->assertCount(5, $params);
        $this->assertSame('lang', $params[0]->getName());
        $this->assertSame('wordlc', $params[1]->getName());
        $this->assertSame('wid', $params[2]->getName());
        $this->assertTrue($params[2]->getType()?->allowsNull());
        $this->assertSame('targetCtlId', $params[3]->getName());
        $this->assertSame('mode', $params[4]->getName());
    }

    #[Test]
    public function get20SentencesEscapesWordlcInHeader(): void
    {
        $src = self::methodSource('get20Sentences');
        $this->assertStringContainsString('htmlspecialchars($wordlc', $src);
    }

    #[Test]
    public function get20SentencesEscapesTargetCtlId(): void
    {
        $src = self::methodSource('get20Sentences');
        $this->assertStringContainsString('htmlspecialchars($targetCtlId', $src);
    }

    #[Test]
    public function get20SentencesEscapesSentenceData(): void
    {
        $src = self::methodSource('get20Sentences');
        $this->assertStringContainsString("htmlspecialchars(\$sentence[1]", $src);
    }

    #[Test]
    public function get20SentencesUsesIconHelper(): void
    {
        $src = self::methodSource('get20Sentences');
        $this->assertStringContainsString('IconHelper::render', $src);
        $this->assertStringContainsString('circle-check', $src);
    }

    #[Test]
    public function get20SentencesCallsGetSentencesWithWord(): void
    {
        $src = self::methodSource('get20Sentences');
        $this->assertStringContainsString('getSentencesWithWord', $src);
    }

    #[Test]
    public function get20SentencesOutputsCopySentenceAction(): void
    {
        $src = self::methodSource('get20Sentences');
        $this->assertStringContainsString('data-action="copy-sentence"', $src);
    }

    // =========================================================================
    // renderExampleSentencesArea — direct invocation tests
    // =========================================================================

    #[Test]
    public function renderExampleSentencesAreaReturnsHtml(): void
    {
        $result = $this->service->renderExampleSentencesArea(1, 'test', 'ctl1', 5);
        $this->assertIsString($result);
        $this->assertNotEmpty($result);
    }

    #[Test]
    public function renderExampleSentencesAreaContainsExsentDiv(): void
    {
        $result = $this->service->renderExampleSentencesArea(1, 'word', 'ctl1', 1);
        $this->assertStringContainsString('<div id="exsent">', $result);
        $this->assertStringContainsString('<div id="exsent-interactable">', $result);
        $this->assertStringContainsString('<div id="exsent-sentences">', $result);
    }

    #[Test]
    public function renderExampleSentencesAreaEscapesTermlc(): void
    {
        $result = $this->service->renderExampleSentencesArea(1, '<script>alert(1)</script>', 'ctl1', 1);
        $this->assertStringNotContainsString('<script>alert(1)</script>', $result);
        $this->assertStringContainsString('&lt;script&gt;', $result);
    }

    #[Test]
    public function renderExampleSentencesAreaEscapesTargetCtlId(): void
    {
        $result = $this->service->renderExampleSentencesArea(1, 'word', '"><script>', 1);
        $this->assertStringNotContainsString('"><script>', $result);
    }

    #[Test]
    public function renderExampleSentencesAreaContainsDataAttributes(): void
    {
        $result = $this->service->renderExampleSentencesArea(42, 'hola', 'myCtl', 99);
        $this->assertStringContainsString('data-lang="42"', $result);
        $this->assertStringContainsString('data-termlc="hola"', $result);
        $this->assertStringContainsString('data-target="myCtl"', $result);
        $this->assertStringContainsString('data-wid="99"', $result);
    }

    #[Test]
    public function renderExampleSentencesAreaContainsShowSentencesAction(): void
    {
        $result = $this->service->renderExampleSentencesArea(1, 'word', 'ctl1', 1);
        $this->assertStringContainsString('data-action="show-sentences"', $result);
        $this->assertStringContainsString('Show Sentences', $result);
    }

    #[Test]
    public function renderExampleSentencesAreaContainsLoadingIcon(): void
    {
        $result = $this->service->renderExampleSentencesArea(1, 'word', 'ctl1', 1);
        $this->assertStringContainsString('exsent-waiting', $result);
    }

    #[Test]
    public function renderExampleSentencesAreaContainsChooseInstruction(): void
    {
        $result = $this->service->renderExampleSentencesArea(1, 'word', 'ctl1', 1);
        $this->assertStringContainsString('to copy sentence into above term', $result);
    }

    // =========================================================================
    // convertZwsToSpacing — direct invocation via reflection
    // =========================================================================

    #[Test]
    public function convertZwsToSpacingAddSpaceBetweenWords(): void
    {
        $result = $this->invokePrivate('convertZwsToSpacing', "Hello\u{200B}world", 'a-zA-Z');
        $this->assertSame('Hello world', $result);
    }

    #[Test]
    public function convertZwsToSpacingHandlesEmptyString(): void
    {
        $result = $this->invokePrivate('convertZwsToSpacing', '', 'a-zA-Z');
        $this->assertSame('', $result);
    }

    #[Test]
    public function convertZwsToSpacingHandlesNoZws(): void
    {
        $result = $this->invokePrivate('convertZwsToSpacing', 'Hello world', 'a-zA-Z');
        $this->assertSame('Hello world', $result);
    }

    #[Test]
    public function convertZwsToSpacingAddSpaceAfterPunctuation(): void
    {
        $result = $this->invokePrivate('convertZwsToSpacing', "Hello.\u{200B}World", 'a-zA-Z');
        $this->assertSame('Hello. World', $result);
    }

    #[Test]
    public function convertZwsToSpacingRemovesZwsBetweenPunctAndNonWord(): void
    {
        // ZWS between punctuation marks should just be removed
        $result = $this->invokePrivate('convertZwsToSpacing', ".\u{200B}!", 'a-zA-Z');
        $this->assertSame('.!', $result);
    }

    #[Test]
    public function convertZwsToSpacingAddSpaceAfterClosingBracket(): void
    {
        $result = $this->invokePrivate('convertZwsToSpacing', ")\u{200B}word", 'a-zA-Z');
        $this->assertSame(') word', $result);
    }

    #[Test]
    public function convertZwsToSpacingTrimsResult(): void
    {
        $result = $this->invokePrivate('convertZwsToSpacing', "\u{200B}Hello\u{200B}world\u{200B}", 'a-zA-Z');
        $this->assertSame('Hello world', $result);
    }

    #[Test]
    public function convertZwsToSpacingHandlesUnicode(): void
    {
        $result = $this->invokePrivate(
            'convertZwsToSpacing',
            "Bonjour\u{200B}le\u{200B}monde",
            'a-zA-ZA-z\x{00C0}-\x{00FF}'
        );
        $this->assertStringContainsString('Bonjour', $result);
        $this->assertStringContainsString('monde', $result);
    }

    #[Test]
    public function convertZwsToSpacingHandlesExclamation(): void
    {
        $result = $this->invokePrivate('convertZwsToSpacing', "Hello!\u{200B}World", 'a-zA-Z');
        $this->assertSame('Hello! World', $result);
    }

    #[Test]
    public function convertZwsToSpacingHandlesComma(): void
    {
        $result = $this->invokePrivate('convertZwsToSpacing', "Hello,\u{200B}world", 'a-zA-Z');
        $this->assertSame('Hello, world', $result);
    }

    // =========================================================================
    // extractCenteredPortion — direct invocation via reflection
    // =========================================================================

    #[Test]
    public function extractCenteredPortionReturnsFullTextWhenUnderLimit(): void
    {
        $result = $this->invokePrivate('extractCenteredPortion', 'Short text', 500);
        $this->assertSame('Short text', $result);
    }

    #[Test]
    public function extractCenteredPortionReturnsFullTextAtExactLimit(): void
    {
        $text = str_repeat('a', 50);
        $result = $this->invokePrivate('extractCenteredPortion', $text, 50);
        $this->assertSame($text, $result);
    }

    #[Test]
    public function extractCenteredPortionTruncatesAndAddsEllipsis(): void
    {
        $text = 'The quick brown fox jumps over the lazy dog and many more words follow afterwards in this sentence';
        $result = $this->invokePrivate('extractCenteredPortion', $text, 20);
        $this->assertStringStartsWith('...', $result);
        $this->assertStringEndsWith('...', $result);
        $this->assertLessThan(mb_strlen($text), mb_strlen($result));
    }

    #[Test]
    public function extractCenteredPortionHandlesEmptyString(): void
    {
        $result = $this->invokePrivate('extractCenteredPortion', '', 50);
        $this->assertSame('', $result);
    }

    // =========================================================================
    // extractPortionAroundWord — direct invocation via reflection
    // =========================================================================

    #[Test]
    public function extractPortionAroundWordCentersOnWord(): void
    {
        $text = 'The quick brown fox jumps over the lazy dog';
        $result = $this->invokePrivate('extractPortionAroundWord', $text, 'fox', 10);
        $this->assertStringContainsString('fox', $result);
    }

    #[Test]
    public function extractPortionAroundWordFallsBackWhenWordNotFound(): void
    {
        $text = 'The quick brown fox jumps over the lazy dog';
        $result = $this->invokePrivate('extractPortionAroundWord', $text, 'NONEXISTENT', 10);
        // Falls back to extractCenteredPortion
        $this->assertIsString($result);
    }

    #[Test]
    public function extractPortionAroundWordNoEllipsisAtStartWhenWordAtStart(): void
    {
        $text = 'Start of the long text that continues with many words and goes on';
        $result = $this->invokePrivate('extractPortionAroundWord', $text, 'Start', 15);
        $this->assertStringStartsWith('Start', $result);
    }

    #[Test]
    public function extractPortionAroundWordEllipsisWhenMiddle(): void
    {
        $text = 'The quick brown fox jumps over the lazy dog in the big garden near the old house';
        $result = $this->invokePrivate('extractPortionAroundWord', $text, 'lazy', 10);
        $this->assertStringContainsString('...', $result);
        $this->assertStringContainsString('lazy', $result);
    }

    #[Test]
    public function extractPortionAroundWordIsCaseInsensitive(): void
    {
        $text = 'The quick brown FOX jumps over the lazy dog';
        $result = $this->invokePrivate('extractPortionAroundWord', $text, 'fox', 100);
        $this->assertStringContainsString('FOX', $result);
    }

    // =========================================================================
    // SQL safety checks across all database methods
    // =========================================================================

    /**
     * @return array<string, array{string}>
     */
    public static function databaseMethodProvider(): array
    {
        return [
            'findSentencesFromWord' => ['findSentencesFromWord'],
            'formatSentence' => ['formatSentence'],
            'getSentenceText' => ['getSentenceText'],
            'getSentenceAtPosition' => ['getSentenceAtPosition'],
        ];
    }

    #[Test]
    #[DataProvider('databaseMethodProvider')]
    public function databaseMethodUsesParameterizedQueries(string $methodName): void
    {
        $src = self::methodSource($methodName);
        // Should not use string interpolation in SQL
        $this->assertDoesNotMatchRegularExpression(
            '/\"\s*SELECT.*\$\w+/',
            $src,
            "$methodName should not interpolate variables in SQL SELECT"
        );
    }

    #[Test]
    #[DataProvider('databaseMethodProvider')]
    public function databaseMethodDoesNotUseRawQuery(string $methodName): void
    {
        $src = self::methodSource($methodName);
        $this->assertStringNotContainsString(
            'Connection::query(',
            $src,
            "$methodName should not use raw Connection::query()"
        );
    }

    // =========================================================================
    // renderExampleSentencesArea — signature
    // =========================================================================

    #[Test]
    public function renderExampleSentencesAreaSignature(): void
    {
        $ref = new ReflectionMethod(SentenceService::class, 'renderExampleSentencesArea');
        $this->assertTrue($ref->isPublic());
        $this->assertSame('string', $ref->getReturnType()?->getName());
        $params = $ref->getParameters();
        $this->assertCount(4, $params);
        $this->assertSame('lang', $params[0]->getName());
        $this->assertSame('termlc', $params[1]->getName());
        $this->assertSame('targetCtlId', $params[2]->getName());
        $this->assertSame('wid', $params[3]->getName());
    }
}
