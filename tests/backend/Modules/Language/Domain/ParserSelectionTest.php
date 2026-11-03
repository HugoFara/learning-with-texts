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
 * Choosing MeCab by the setting that means it (#288).
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
        $this->assertTrue(ParserSelection::tokenizesWithMecab('mecab'));
        $this->assertFalse(ParserSelection::tokenizesWithMecab('jieba'));
        $this->assertFalse(ParserSelection::tokenizesWithMecab('regex'));
    }

    public function testAMigratedLanguageIsRecognised(): void
    {
        // What the 20260831 migration leaves behind: a real regex in the
        // word-characters field, and the parser type carrying the choice.
        $this->assertTrue(
            ParserSelection::rowTokenizesWithMecab([
                'LgParserType' => 'mecab',
                'LgRegexpWordCharacters' => self::JA,
            ])
        );
    }

    public function testTheRetiredMarkerNoLongerSelectsMecab(): void
    {
        // 3.8.0 removed the fallback: the field is a regex and nothing else.
        // The migration converted every row holding the marker, and a restore
        // re-runs it, so a row like this can only come from writing the
        // literal after the upgrade.
        $this->assertFalse(
            ParserSelection::rowTokenizesWithMecab([
                'LgParserType' => null,
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
        $this->assertTrue(ParserSelection::tokenizesWithMecab('  MeCab  '));
        $this->assertTrue(ParserSelection::rowTokenizesWithMecab(['LgParserType' => "\tMECAB\n"]));
    }
}
