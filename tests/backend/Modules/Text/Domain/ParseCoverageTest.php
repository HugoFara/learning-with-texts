<?php

/**
 * Unit tests for ParseCoverage.
 *
 * PHP version 8.1
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
 * When a parse counts as having produced nothing learnable (#278).
 */
#[CoversClass(ParseCoverage::class)]
class ParseCoverageTest extends TestCase
{
    public function testNoWordsAtAllIsTheReportedCase(): void
    {
        // The reported bug: a Chinese text on a Latin language parses into
        // a readable page where nothing can be clicked
        $this->assertSame(ParseCoverage::NO_WORDS, ParseCoverage::assess(0, 420));
    }

    public function testAFewStrayTokensInALongTextStillCounts(): void
    {
        // A digit or a Latin fragment inside a non-Latin text matches, the
        // rest does not; a plain zero test would miss this
        $this->assertSame(ParseCoverage::ALMOST_NO_WORDS, ParseCoverage::assess(3, 400));
    }

    /**
     * @param int $words      Words the parse produced
     * @param int $characters Characters in the text
     */
    #[DataProvider('healthyParses')]
    public function testARealLanguageIsNeverWarnedAbout(int $words, int $characters): void
    {
        $this->assertSame(ParseCoverage::OK, ParseCoverage::assess($words, $characters));
    }

    /**
     * Word-to-character ratios that real languages actually produce.
     *
     * @return array<string, array{int, int}>
     */
    public static function healthyParses(): array
    {
        return [
            'English prose, ~1 word per 6 characters' => [70, 420],
            'German compounds, ~1 per 12'             => [35, 420],
            'character-split Chinese, ~1 per 1'       => [400, 420],
            'jieba-segmented Chinese, ~1 per 2'       => [200, 420],
            'a sparse but plausible text, 1 per 20'   => [21, 420],
        ];
    }

    public function testAShortTextIsNotJudgedOnDensity(): void
    {
        // One word in a title or a caption is not a broken parse
        $this->assertSame(ParseCoverage::OK, ParseCoverage::assess(1, 199));
    }

    public function testAShortTextWithNoWordsIsStillReported(): void
    {
        $this->assertSame(ParseCoverage::NO_WORDS, ParseCoverage::assess(0, 20));
    }

    public function testAnEmptyTextIsNotAParsingProblem(): void
    {
        $this->assertSame(ParseCoverage::OK, ParseCoverage::assess(0, 0));
        $this->assertSame(ParseCoverage::OK, ParseCoverage::assess(0, -1));
    }

    public function testOnlyTheOkVerdictIsSilent(): void
    {
        $this->assertFalse(ParseCoverage::isWarning(ParseCoverage::OK));
        $this->assertTrue(ParseCoverage::isWarning(ParseCoverage::NO_WORDS));
        $this->assertTrue(ParseCoverage::isWarning(ParseCoverage::ALMOST_NO_WORDS));
    }
}
