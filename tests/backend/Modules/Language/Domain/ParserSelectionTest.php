<?php

/**
 * Unit tests for ParserSelection.
 *
 * PHP version 8.2
 *
 * @category Tests
 * @package  Lwt\Tests\Modules\Language\Domain
 * @license  Unlicense <http://unlicense.org/>
 */

declare(strict_types=1);

namespace Lwt\Tests\Modules\Language\Domain;

use Lwt\Modules\Language\Domain\ParserSelection;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Choosing MeCab by the setting that means it, not by the marker (#288).
 */
#[CoversClass(ParserSelection::class)]
class ParserSelectionTest extends TestCase
{
    /**
     * The regex a migrated language holds, from the Japanese preset.
     */
    private const JA = '\x{4E00}-\x{9FFF}\x{F900}-\x{FAFF}\x{3040}-\x{30FF}\x{31F0}-\x{31FF}';

    public function testAnExplicitParserTypeSettlesIt(): void
    {
        $this->assertTrue(ParserSelection::tokenizesWithMecab('mecab', self::JA));
        $this->assertFalse(ParserSelection::tokenizesWithMecab('jieba', self::JA));
        $this->assertFalse(ParserSelection::tokenizesWithMecab('regex', self::JA));
    }

    public function testAMigratedLanguageIsStillRecognised(): void
    {
        // This is what the migration leaves behind: the marker is gone from the
        // field, so only the parser type can answer. A reader still asking the
        // field would quietly parse Japanese with the regex tokenizer.
        $this->assertTrue(
            ParserSelection::rowTokenizesWithMecab([
                'LgParserType' => 'mecab',
                'LgRegexpWordCharacters' => self::JA,
            ])
        );
    }

    public function testAnUnmigratedLanguageFallsBackToTheMarker(): void
    {
        $this->assertTrue(
            ParserSelection::rowTokenizesWithMecab([
                'LgParserType' => null,
                'LgRegexpWordCharacters' => 'MECAB',
            ])
        );
        $this->assertTrue(
            ParserSelection::rowTokenizesWithMecab([
                'LgRegexpWordCharacters' => 'mecab',
            ])
        );
    }

    public function testAChosenParserBeatsTheMarker(): void
    {
        // The marker is what the field used to hold; LgParserType is what the
        // user chose. A language that says regex means regex.
        $this->assertFalse(
            ParserSelection::rowTokenizesWithMecab([
                'LgParserType' => 'regex',
                'LgRegexpWordCharacters' => 'MECAB',
            ])
        );
    }

    public function testAnOrdinaryLanguageDoesNotTokenizeWithMecab(): void
    {
        $this->assertFalse(
            ParserSelection::rowTokenizesWithMecab([
                'LgParserType' => null,
                'LgRegexpWordCharacters' => 'a-zA-ZÀ-ÖØ-öø-ȳ',
            ])
        );
        $this->assertFalse(ParserSelection::rowTokenizesWithMecab([]));
    }

    public function testCasingAndPaddingDoNotMatter(): void
    {
        $this->assertTrue(ParserSelection::tokenizesWithMecab('  MeCab  ', ''));
        $this->assertTrue(ParserSelection::tokenizesWithMecab(null, ' MeCab '));
    }
}
