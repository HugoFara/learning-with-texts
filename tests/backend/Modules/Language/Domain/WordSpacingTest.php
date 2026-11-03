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
 * Whether a language separates its words with spaces (#288).
 */
#[CoversClass(WordSpacing::class)]
class WordSpacingTest extends TestCase
{
    public function testASpaceSeparatedLanguageSeparatesWords(): void
    {
        $this->assertTrue(WordSpacing::separatesWordsWithSpaces(false, false));
    }

    /**
     * @param bool $removeSpaces  LgRemoveSpaces
     * @param bool $splitEachChar LgSplitEachChar
     */
    #[DataProvider('spacelessLanguages')]
    public function testASpacelessLanguageDoesNot(bool $removeSpaces, bool $splitEachChar): void
    {
        $this->assertFalse(
            WordSpacing::separatesWordsWithSpaces($removeSpaces, $splitEachChar)
        );
    }

    /**
     * The ways a language says it is written without spaces.
     *
     * The MECAB marker in LgRegexpWordCharacters used to be a third; the
     * 20260831 migration moved it onto LgRemoveSpaces and 3.8.0 stopped
     * reading it.
     *
     * @return array<string, array{bool, bool}>
     */
    public static function spacelessLanguages(): array
    {
        return [
            'LgRemoveSpaces'  => [true, false],
            'LgSplitEachChar' => [false, true],
            'both'            => [true, true],
        ];
    }
}
