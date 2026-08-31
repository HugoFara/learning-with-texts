<?php

/**
 * Unit tests for ParseCoverage.
 *
 * PHP version 8.2
 *
 * @category Tests
 * @package  Lwt\Tests\Modules\Text\Domain
 * @license  Unlicense <http://unlicense.org/>
 */

declare(strict_types=1);

namespace Lwt\Tests\Modules\Text\Domain;

use Lwt\Modules\Text\Domain\ParseCoverage;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * When a parse counts as having produced nothing learnable (#278, #289).
 */
#[CoversClass(ParseCoverage::class)]
class ParseCoverageTest extends TestCase
{
    /**
     * Word characters of a language left on the Latin defaults.
     */
    private const LATIN = 'a-zA-ZÀ-ÖØ-öø-ÿ';

    /**
     * Judge a text as a language with these word characters would parse it.
     *
     * A miniature of the standard parser: runs of word characters become
     * words, everything between them becomes a non-word run. Both are fed to
     * the coverage, because what the parser could *not* use is half the
     * evidence.
     *
     * @param string $text           The text being parsed
     * @param string $wordCharacters Character class the language calls a word
     *
     * @return string The verdict
     */
    private static function parsedWith(string $text, string $wordCharacters = self::LATIN): string
    {
        $coverage = new ParseCoverage();
        $parts = preg_split(
            "/([$wordCharacters]+)/u",
            $text,
            -1,
            PREG_SPLIT_DELIM_CAPTURE | PREG_SPLIT_NO_EMPTY
        );
        foreach ($parts ?: [] as $part) {
            $coverage->add($part, (bool) preg_match("/^[$wordCharacters]+$/u", $part));
        }
        return $coverage->verdict();
    }

    /**
     * Judge a text as a language that splits every character would parse it.
     *
     * @param string $text The text being parsed
     *
     * @return string The verdict
     */
    private static function parsedByCharacter(string $text): string
    {
        $coverage = new ParseCoverage();
        foreach (preg_split('//u', $text, -1, PREG_SPLIT_NO_EMPTY) ?: [] as $character) {
            $coverage->add($character, trim($character) !== '');
        }
        return $coverage->verdict();
    }

    public function testNoWordsAtAllIsTheReportedCase(): void
    {
        // The reported bug: a Chinese text on a Latin language parses into
        // a readable page where nothing can be clicked
        $this->assertSame(
            ParseCoverage::NO_WORDS,
            self::parsedWith(str_repeat('这是一个中文句子没有空格。', 20))
        );
    }

    public function testAFewStrayTokensInALongTextStillCounts(): void
    {
        // A digit or a Latin fragment inside a non-Latin text matches, the
        // rest does not; a plain zero test would miss this
        $this->assertSame(
            ParseCoverage::ALMOST_NO_WORDS,
            self::parsedWith(str_repeat('这是一个中文句子。', 20) . ' OK Wifi USB')
        );
    }

    public function testAShortNonLatinTextIsReportedToo(): void
    {
        // #289 (1): a 122-character Chinese text with five stray Latin tokens
        // sat above the density floor and under the 200-character exemption,
        // so the reader met a wall of unclickable text in silence. Coverage is
        // judged in letters, so the same text reads the same at any length.
        $text = str_repeat('这是一个中文句子没有空格所以无法处理。', 5) . ' OK Wifi USB GPS PDF';

        $this->assertLessThan(200, mb_strlen($text, 'UTF-8'), 'guard: still a short text');
        $this->assertSame(ParseCoverage::ALMOST_NO_WORDS, self::parsedWith($text));
    }

    /**
     * @param string $text A text that legitimately holds no words at all
     */
    #[DataProvider('textsWithoutLetters')]
    public function testAWordFreeTextIsNotADiagnosis(string $text): void
    {
        // #289 (2): a price list, a numbers drill or a punctuation-only line
        // genuinely contains no words. Telling the reader their word-characters
        // setting does not match would be a confident falsehood about a
        // correctly configured language.
        $this->assertSame(ParseCoverage::OK, self::parsedWith($text));
    }

    /**
     * Texts that contain nothing a word could be made of.
     *
     * @return array<string, array{string}>
     */
    public static function textsWithoutLetters(): array
    {
        return [
            'a number'          => ['123'],
            'a year'            => ['2024'],
            'punctuation only'  => ['?!...'],
            'Roman numerals'    => ['Ⅷ Ⅸ Ⅹ'],
            'a decimal'         => ['3.14'],
            'a price list'      => ["12,50 €\n8,00 €\n31,90 €"],
        ];
    }

    /**
     * @param string $text           A text in a correctly configured language
     * @param string $wordCharacters Word characters of that language
     */
    #[DataProvider('healthyParses')]
    public function testARealLanguageIsNeverWarnedAbout(string $text, string $wordCharacters): void
    {
        $this->assertSame(ParseCoverage::OK, self::parsedWith($text, $wordCharacters));
    }

    /**
     * Texts parsed by a language that actually fits them.
     *
     * @return array<string, array{string, string}>
     */
    public static function healthyParses(): array
    {
        return [
            'French prose'   => ['Le chat dort sur le tapis du salon.', self::LATIN],
            'a short title'  => ['Le chat', self::LATIN],
            'German compound' => ['Die Donaudampfschifffahrtsgesellschaft fuhr.', self::LATIN],
            'Russian'        => ['Он читает книгу каждый день.', 'а-яА-ЯёЁ'],
            'Greek'          => ['Διαβάζει ένα βιβλίο κάθε μέρα.', '\p{Greek}'],
            'Hebrew'         => ['הוא קורא ספר כל יום', '\p{Hebrew}'],
            'Thai'           => ['เขาอ่านหนังสือทุกวัน', '\p{Thai}'],
            'Korean'         => ['그는 매일 책을 읽는다', '\p{Hangul}'],
            'a heading with numerals' => ['Ⅷ Le chapitre huit', self::LATIN],
        ];
    }

    public function testCharacterSplitChineseIsHealthy(): void
    {
        $this->assertSame(
            ParseCoverage::OK,
            self::parsedByCharacter('这是一个中文句子没有空格。')
        );
    }

    public function testAQuotationInAnotherScriptIsNotABrokenParse(): void
    {
        // The room below the floor exists for this: legitimately mixed-script
        // writing, where most letters are still words
        $this->assertSame(
            ParseCoverage::OK,
            self::parsedWith(
                'Le proverbe chinois 塞翁失马 signifie que tout malheur peut se '
                . 'transformer en bonheur, et il est cite tres souvent.'
            )
        );
    }

    public function testTheVerdictDoesNotDependOnSpacingOrLength(): void
    {
        // #289 (3): the three call sites measured three different denominators
        // — the raw text at one, the sum of token lengths at two others. The
        // difference between them is whitespace, so no verdict may turn on it.
        $spaced = 'Le chat dort sur le tapis.';
        $crowded = "Le  chat\n\ndort   sur\tle tapis.";

        $this->assertSame(self::parsedWith($spaced), self::parsedWith($crowded));
        $this->assertSame(ParseCoverage::OK, self::parsedWith($crowded));
    }

    public function testAnEmptyTextIsNotAParsingProblem(): void
    {
        $this->assertSame(ParseCoverage::OK, (new ParseCoverage())->verdict());
        $this->assertSame(ParseCoverage::OK, self::parsedWith(''));
        $this->assertSame(ParseCoverage::OK, self::parsedWith('   '));
    }

    public function testOnlyTheOkVerdictIsSilent(): void
    {
        $this->assertFalse(ParseCoverage::isWarning(ParseCoverage::OK));
        $this->assertTrue(ParseCoverage::isWarning(ParseCoverage::NO_WORDS));
        $this->assertTrue(ParseCoverage::isWarning(ParseCoverage::ALMOST_NO_WORDS));
    }
}
