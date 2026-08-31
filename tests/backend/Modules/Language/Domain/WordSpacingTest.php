<?php

/**
 * Unit tests for WordSpacing.
 *
 * PHP version 8.2
 *
 * @category Tests
 * @package  Lwt\Tests\Modules\Language\Domain
 * @license  Unlicense <http://unlicense.org/>
 */

declare(strict_types=1);

namespace Lwt\Tests\Modules\Language\Domain;

use Lwt\Modules\Language\Domain\WordSpacing;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * The one place that recognises the MeCab magic word (#288).
 */
#[CoversClass(WordSpacing::class)]
class WordSpacingTest extends TestCase
{
    /**
     * @param string $field A word-characters field holding the magic word
     */
    #[DataProvider('magicWordSpellings')]
    public function testTheMagicWordIsRecognisedWhateverItsCasing(string $field): void
    {
        // Every reader normalised before comparing except GetPhoneticReading,
        // which compared against lowercase "mecab" and so silently gave no
        // phonetic reading for any other casing. ExternalParser had the mirror
        // bug, comparing against uppercase only.
        $this->assertTrue(WordSpacing::usesMecabMagicWord($field));
    }

    /**
     * Spellings an install can legitimately hold.
     *
     * @return array<string, array{string}>
     */
    public static function magicWordSpellings(): array
    {
        return [
            'the canonical uppercase' => ['MECAB'],
            'what the form writes'    => ['mecab'],
            'mixed case'              => ['MeCab'],
            'padded'                  => ['  MECAB  '],
            'padded lowercase'        => ["\tmecab\n"],
        ];
    }

    /**
     * @param string $field A field holding a real word-characters regex
     */
    #[DataProvider('realWordCharacters')]
    public function testARealRegexIsNotTheMagicWord(string $field): void
    {
        $this->assertFalse(WordSpacing::usesMecabMagicWord($field));
    }

    /**
     * @return array<string, array{string}>
     */
    public static function realWordCharacters(): array
    {
        return [
            'empty'    => [''],
            'Latin'    => ['a-zA-ZÀ-ÖØ-öø-ȳ'],
            'Japanese' => ['\x{4E00}-\x{9FFF}\x{3040}-\x{30FF}'],
            'a word that merely contains it' => ['mecabra'],
        ];
    }

    public function testASpaceSeparatedLanguageSeparatesWords(): void
    {
        $this->assertTrue(
            WordSpacing::separatesWordsWithSpaces(false, false, 'a-zA-ZÀ-ÖØ-öø-ȳ')
        );
    }

    /**
     * @param bool   $removeSpaces  LgRemoveSpaces
     * @param bool   $splitEachChar LgSplitEachChar
     * @param string $wordChars     LgRegexpWordCharacters
     */
    #[DataProvider('spacelessLanguages')]
    public function testASpacelessLanguageDoesNot(
        bool $removeSpaces,
        bool $splitEachChar,
        string $wordChars
    ): void {
        $this->assertFalse(
            WordSpacing::separatesWordsWithSpaces($removeSpaces, $splitEachChar, $wordChars)
        );
    }

    /**
     * The three ways a language says it is written without spaces.
     *
     * @return array<string, array{bool, bool, string}>
     */
    public static function spacelessLanguages(): array
    {
        return [
            'LgRemoveSpaces'          => [true, false, 'a-zA-Z'],
            'LgSplitEachChar'         => [false, true, '一-龥'],
            'the MeCab magic word'    => [false, false, 'MECAB'],
            'the magic word, as the form writes it' => [false, false, 'mecab'],
            'all three at once'       => [true, true, 'MECAB'],
        ];
    }
}
