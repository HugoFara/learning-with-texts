<?php

declare(strict_types=1);

namespace Lwt\Tests\Modules\Language\Infrastructure\Parser;

use PHPUnit\Framework\TestCase;
use Lwt\Modules\Language\Infrastructure\Parser\ParserRegistry;
use Lwt\Modules\Language\Domain\Parser\ParserInterface;
use Lwt\Modules\Language\Infrastructure\Parser\RegexParser;
use Lwt\Modules\Language\Infrastructure\Parser\CharacterParser;
use Lwt\Modules\Language\Infrastructure\Parser\MecabParser;

/**
 * Tests for the ParserRegistry class.
 */
class ParserRegistryTest extends TestCase
{
    private ParserRegistry $registry;

    protected function setUp(): void
    {
        $this->registry = new ParserRegistry();
    }

    public function testRegistryRegistersDefaultParsers(): void
    {
        $all = $this->registry->getAll();

        $this->assertArrayHasKey('regex', $all);
        $this->assertArrayHasKey('character', $all);
        $this->assertArrayHasKey('mecab', $all);
    }

    public function testGetReturnsParser(): void
    {
        $parser = $this->registry->get('regex');

        $this->assertInstanceOf(ParserInterface::class, $parser);
        $this->assertInstanceOf(RegexParser::class, $parser);
    }

    public function testGetReturnsNullForUnknownType(): void
    {
        $parser = $this->registry->get('unknown');

        $this->assertNull($parser);
    }

    public function testHasReturnsTrueForRegisteredType(): void
    {
        $this->assertTrue($this->registry->has('regex'));
        $this->assertTrue($this->registry->has('character'));
        $this->assertTrue($this->registry->has('mecab'));
    }

    public function testHasReturnsFalseForUnknownType(): void
    {
        $this->assertFalse($this->registry->has('unknown'));
    }

    public function testGetDefaultType(): void
    {
        $this->assertEquals('regex', $this->registry->getDefaultType());
    }

    public function testResolveParserTypeFromRow(): void
    {
        // Test explicit parser type
        $row = ['LgParserType' => 'character'];
        $this->assertEquals('character', $this->registry->resolveParserTypeFromRow($row));

        // Test MeCab magic word
        $row = ['LgRegexpWordCharacters' => 'MECAB'];
        $this->assertEquals('mecab', $this->registry->resolveParserTypeFromRow($row));

        $row = ['LgRegexpWordCharacters' => 'mecab'];
        $this->assertEquals('mecab', $this->registry->resolveParserTypeFromRow($row));

        // Test splitEachChar flag
        $row = ['LgSplitEachChar' => 1];
        $this->assertEquals('character', $this->registry->resolveParserTypeFromRow($row));

        // Test default fallback
        $row = ['LgRegexpWordCharacters' => 'a-zA-Z'];
        $this->assertEquals('regex', $this->registry->resolveParserTypeFromRow($row));
    }

    public function testGetAvailableReturnsAvailableParsers(): void
    {
        $available = $this->registry->getAvailable();

        // At minimum, regex and character parsers should always be available
        $this->assertArrayHasKey('regex', $available);
        $this->assertArrayHasKey('character', $available);
    }

    public function testGetParserInfo(): void
    {
        $info = $this->registry->getParserInfo();

        $this->assertArrayHasKey('regex', $info);
        $this->assertArrayHasKey('type', $info['regex']);
        $this->assertArrayHasKey('name', $info['regex']);
        $this->assertArrayHasKey('available', $info['regex']);
        $this->assertArrayHasKey('message', $info['regex']);
    }

    public function testRegisterCustomParser(): void
    {
        $customParser = $this->createMock(ParserInterface::class);
        $customParser->method('getType')->willReturn('custom');

        $this->registry->register($customParser);

        $this->assertTrue($this->registry->has('custom'));
        $this->assertSame($customParser, $this->registry->get('custom'));
    }

    // =========================================================================
    // Opted-in parser resolution (#278)
    // =========================================================================

    public function testNoParserTypeLeavesTheLanguageOnTheBuiltInPipeline(): void
    {
        // Every language predating the field stores nothing here, and their
        // parsing must not change
        $this->assertNull($this->registry->getOptedInParserFromRow([]));
        $this->assertNull($this->registry->getOptedInParserFromRow(['LgParserType' => null]));
        $this->assertNull($this->registry->getOptedInParserFromRow(['LgParserType' => '']));
        $this->assertNull($this->registry->getOptedInParserFromRow(['LgParserType' => '  ']));
    }

    public function testTheDefaultParserIsNotAnOptIn(): void
    {
        // "regex" is what the built-in pipeline already does
        $this->assertNull($this->registry->getOptedInParserFromRow(['LgParserType' => 'regex']));
    }

    public function testLegacySignalsAreNotOptIns(): void
    {
        // resolveParserTypeFromRow() reads both of these; the pipeline has
        // always handled them itself, so they must not route anywhere new
        $this->assertNull($this->registry->getOptedInParserFromRow([
            'LgRegexpWordCharacters' => 'MECAB',
        ]));
        $this->assertNull($this->registry->getOptedInParserFromRow([
            'LgSplitEachChar' => 1,
        ]));
    }

    public function testABackfilledCharacterTypeIsNotAnOptIn(): void
    {
        // 20251223_120000_add_parser_type.sql set LgParserType='character' on
        // every LgSplitEachChar language, so an upgraded install carries a type
        // nobody chose. Routing it to CharacterParser retokenizes the language:
        // measured on a real database, Chinese went from 103 words to 122.
        $this->assertNull($this->registry->getOptedInParserFromRow([
            'LgParserType' => 'character',
            'LgSplitEachChar' => 1,
        ]));
    }

    public function testABackfilledMecabTypeIsNotAnOptIn(): void
    {
        // Same backfill, from the magic word this time. The built-in pipeline
        // already routes these to JapaneseTextParser.
        $this->assertNull($this->registry->getOptedInParserFromRow([
            'LgParserType' => 'mecab',
            'LgRegexpWordCharacters' => 'MECAB',
        ]));
        $this->assertNull($this->registry->getOptedInParserFromRow([
            'LgParserType' => 'mecab',
            'LgRegexpWordCharacters' => '  mecab  ',
        ]));
    }

    public function testACharacterTypeWithoutTheSplitFlagIsAChoice(): void
    {
        // The backfill only ever wrote 'character' alongside the flag, so this
        // combination could only have come from the language form.
        $this->assertInstanceOf(
            CharacterParser::class,
            $this->registry->getOptedInParserFromRow([
                'LgParserType' => 'character',
                'LgSplitEachChar' => 0,
            ])
        );
    }

    public function testAParserUnrelatedToTheLegacyFlagsIsAlwaysAChoice(): void
    {
        // A language asking for jieba means it, whatever its other flags say
        $this->registry->register(new UnavailableTestParser());

        $this->assertNull($this->registry->getOptedInParserFromRow([
            'LgParserType' => 'unavailable-test',
            'LgSplitEachChar' => 1,
        ]), 'an unavailable parser still falls back, but for its own reason');

        $this->assertInstanceOf(
            CharacterParser::class,
            $this->registry->getOptedInParserFromRow([
                'LgParserType' => 'character',
                'LgRegexpWordCharacters' => 'MECAB',
            ]),
            'the magic word only excuses a mecab type, not any other'
        );
    }

    public function testAnExplicitAvailableParserIsReturned(): void
    {
        $parser = $this->registry->getOptedInParserFromRow(['LgParserType' => 'character']);

        $this->assertInstanceOf(CharacterParser::class, $parser);
    }

    public function testAnUnknownParserFallsBackToTheBuiltInPipeline(): void
    {
        $this->assertNull(
            $this->registry->getOptedInParserFromRow(['LgParserType' => 'no-such-parser'])
        );
    }

    public function testAnUnavailableParserFallsBackToTheBuiltInPipeline(): void
    {
        // Not to the regex parser: for a language that asked for jieba or
        // mecab, regex yields a text with no words at all
        $this->registry->register(new UnavailableTestParser());

        $this->assertNull(
            $this->registry->getOptedInParserFromRow(['LgParserType' => 'unavailable-test'])
        );
    }
}

/**
 * A registered parser that the server cannot run.
 */
class UnavailableTestParser implements ParserInterface
{
    public function getType(): string
    {
        return 'unavailable-test';
    }

    public function getName(): string
    {
        return 'Unavailable Test Parser';
    }

    public function isAvailable(): bool
    {
        return false;
    }

    public function getAvailabilityMessage(): string
    {
        return 'not installed';
    }

    public function parse(
        string $text,
        \Lwt\Modules\Language\Domain\Parser\ParserConfig $config
    ): \Lwt\Modules\Language\Domain\Parser\ParserResult {
        throw new \RuntimeException('never called');
    }
}
