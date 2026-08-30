<?php

/**
 * Unit tests for the parser type carried by the language presets.
 *
 * PHP version 8.2
 *
 * @category Tests
 * @package  Lwt\Tests\Shared\Infrastructure\Language
 * @license  Unlicense <http://unlicense.org/>
 */

declare(strict_types=1);

namespace Lwt\Tests\Shared\Infrastructure\Language;

use Lwt\Shared\Infrastructure\Language\LanguagePresets;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * The tokenizer a preset asks for reaches the caller (#278).
 *
 * langdefs.json has carried a parserType for the CJK languages since the
 * parser modules were added, but loadFromJson() built an eight-slot tuple
 * that dropped it, so nothing could ever read it.
 */
#[CoversClass(LanguagePresets::class)]
class LanguagePresetsParserTypeTest extends TestCase
{
    public function testChinesePresetsAskForJieba(): void
    {
        $all = LanguagePresets::getAll();

        $this->assertSame('jieba', $all['Chinese (Simplified)'][8]);
        $this->assertSame('jieba', $all['Chinese (Traditional)'][8]);
    }

    public function testJapanesePresetAsksForMecab(): void
    {
        $this->assertSame('mecab', LanguagePresets::getAll()['Japanese'][8]);
    }

    public function testChinesePresetsStillSplitEachCharacter(): void
    {
        // The fallback when jieba is not installed: per-character parsing,
        // which is what these languages did before jieba was reachable
        $all = LanguagePresets::getAll();

        $this->assertTrue($all['Chinese (Simplified)'][5]);
        $this->assertTrue($all['Chinese (Traditional)'][5]);
    }

    public function testALanguageWithoutAParserTypeGetsAnEmptyString(): void
    {
        // Empty means "infer", which is what every non-CJK preset wants
        $this->assertSame('', LanguagePresets::getAll()['English'][8]);
    }

    public function testEveryPresetCarriesTheSlot(): void
    {
        foreach (LanguagePresets::getAll() as $name => $def) {
            $this->assertArrayHasKey(8, $def, "preset $name lost its parser type");
            $this->assertIsString($def[8]);
        }
    }
}
