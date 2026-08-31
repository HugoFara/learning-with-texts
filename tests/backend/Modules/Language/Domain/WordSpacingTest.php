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
    /** @var string|false Temporary file collecting error_log() output */
    private string|false $logFile = false;

    /** @var string|false The error_log setting to put back */
    private string|false $previousLog = false;

    /**
     * Send deprecation notices to a file this test can read.
     *
     * error_log() writes to stderr by default under the CLI SAPI, which would
     * both dirty the PHPUnit output and leave nothing to assert on.
     *
     * @return void
     */
    protected function setUp(): void
    {
        WordSpacing::forgetDeprecationNotices();
        $this->logFile = tempnam(sys_get_temp_dir(), 'lwt_deprecation');
        $this->previousLog = ini_set(
            'error_log',
            $this->logFile === false ? '' : $this->logFile
        );
    }

    /**
     * @return void
     */
    protected function tearDown(): void
    {
        ini_set('error_log', $this->previousLog === false ? '' : $this->previousLog);
        if ($this->logFile !== false && file_exists($this->logFile)) {
            unlink($this->logFile);
        }
        WordSpacing::forgetDeprecationNotices();
    }

    /**
     * The deprecation notices written so far.
     *
     * @return array<string> One entry per logged line
     */
    private function loggedNotices(): array
    {
        if ($this->logFile === false || !file_exists($this->logFile)) {
            return [];
        }
        $contents = (string) file_get_contents($this->logFile);
        return preg_split('/\R(?=\[)/u', trim($contents), -1, PREG_SPLIT_NO_EMPTY) ?: [];
    }

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

    // =========================================================================
    // Deprecation notices (#288 step 4)
    // =========================================================================

    public function testFallingBackToTheMarkerIsLogged(): void
    {
        WordSpacing::usesMecabMagicWord('MECAB');

        $notices = $this->loggedNotices();
        $this->assertCount(1, $notices);
        $this->assertStringContainsString('LgRegexpWordCharacters', $notices[0]);
        $this->assertStringContainsString('LgParserType', $notices[0]);
        $this->assertStringContainsString('#288', $notices[0]);
    }

    public function testOneFieldValueIsReportedOnce(): void
    {
        // The spacing readers run per sentence, so an undeduplicated notice
        // would write one line per sentence of every Japanese page and bury
        // itself in its own output.
        for ($i = 0; $i < 5; $i++) {
            WordSpacing::usesMecabMagicWord('MECAB');
        }

        $this->assertCount(1, $this->loggedNotices());
    }

    public function testEachSpellingIsReportedSeparately(): void
    {
        // Different spellings are different rows in the languages table, and
        // the point of the notice is to find them all before the marker goes.
        WordSpacing::usesMecabMagicWord('MECAB');
        WordSpacing::usesMecabMagicWord('mecab');

        $this->assertCount(2, $this->loggedNotices());
    }

    public function testARealRegexIsNotReported(): void
    {
        WordSpacing::usesMecabMagicWord('a-zA-Z');
        WordSpacing::usesMecabMagicWord('');
        WordSpacing::separatesWordsWithSpaces(false, false, 'a-zA-Z');

        $this->assertSame([], $this->loggedNotices());
    }

    public function testAMarkerThatDecidesNothingIsNotReported(): void
    {
        // A notice has to mean the marker was load-bearing, or step 5 cannot
        // read anything off it. LgRemoveSpaces already answers the spacing
        // question here, so the marker is never consulted and never reported —
        // exactly the language that loses nothing when the fallback goes.
        WordSpacing::separatesWordsWithSpaces(true, false, 'MECAB');
        WordSpacing::separatesWordsWithSpaces(false, true, 'MECAB');

        $this->assertSame([], $this->loggedNotices());

        // With both flags off, the marker is the only thing left saying the
        // language has no spaces, so now it is reported.
        $this->assertFalse(WordSpacing::separatesWordsWithSpaces(false, false, 'MECAB'));
        $this->assertCount(1, $this->loggedNotices());
    }
}
