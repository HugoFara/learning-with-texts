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
use Lwt\Modules\Language\Domain\WordSpacing;
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

    /** @var string|false Temporary file collecting error_log() output */
    private string|false $logFile = false;

    /** @var string|false The error_log setting to put back */
    private string|false $previousLog = false;

    /**
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
     * Whether any deprecation notice was written.
     *
     * @return bool
     */
    private function anythingLogged(): bool
    {
        return $this->logFile !== false
            && file_exists($this->logFile)
            && trim((string) file_get_contents($this->logFile)) !== '';
    }

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

    public function testOnlyAnActualFallbackIsReportedAsDeprecated(): void
    {
        // A language carrying both the marker and a parser type never consults
        // the marker, so removing the fallback in step 5 cannot change what it
        // does. Reporting it would make the notice mean "a row holds a marker"
        // instead of "a reader needed one", and that is the only thing the
        // removal can be decided on. The Server Data panel covers the rest.
        ParserSelection::rowTokenizesWithMecab([
            'LgParserType' => 'mecab',
            'LgRegexpWordCharacters' => 'MECAB',
        ]);
        $this->assertFalse($this->anythingLogged());

        ParserSelection::rowTokenizesWithMecab([
            'LgParserType' => null,
            'LgRegexpWordCharacters' => 'MECAB',
        ]);
        $this->assertTrue($this->anythingLogged());
    }
}
