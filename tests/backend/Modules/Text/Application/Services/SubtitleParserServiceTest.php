<?php

/**
 * Unit tests for SubtitleParserService.
 *
 * Tests SRT and VTT parsing, format detection, validation,
 * and edge cases for subtitle file processing.
 *
 * PHP version 8.2
 *
 * @category Testing
 * @package  Lwt\Tests\Modules\Text\Application\Services
 * @license  Unlicense <http://unlicense.org/>
 * @since    3.0.0
 */

declare(strict_types=1);

namespace Tests\Modules\Text\Application\Services;

use Lwt\Modules\Text\Application\Services\SubtitleParserService;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * Unit tests for SubtitleParserService.
 *
 * @since  3.0.0
 */
#[CoversClass(SubtitleParserService::class)]
class SubtitleParserServiceTest extends TestCase
{
    private SubtitleParserService $parser;

    protected function setUp(): void
    {
        $this->parser = new SubtitleParserService();
    }

    // =========================================================================
    // parse() - SRT format
    // =========================================================================

    #[Test]
    public function parseSrtExtractsTextFromSingleCue(): void
    {
        $srt = "1\n00:00:00,000 --> 00:00:05,000\nHello world\n";

        $result = $this->parser->parse($srt, 'srt');

        $this->assertTrue($result['success']);
        $this->assertSame('Hello world', $result['text']);
        $this->assertSame(1, $result['cueCount']);
        $this->assertNull($result['error']);
    }

    #[Test]
    public function parseSrtExtractsMultipleCues(): void
    {
        $srt = "1\n00:00:00,000 --> 00:00:05,000\nFirst line\n\n" .
               "2\n00:00:05,100 --> 00:00:10,000\nSecond line\n";

        $result = $this->parser->parse($srt, 'srt');

        $this->assertTrue($result['success']);
        $this->assertStringContainsString('First line', $result['text']);
        $this->assertStringContainsString('Second line', $result['text']);
        $this->assertSame(2, $result['cueCount']);
    }

    #[Test]
    public function parseSrtHandlesMultilineSubtitles(): void
    {
        $srt = "1\n00:00:00,000 --> 00:00:05,000\nLine one\nLine two\n";

        $result = $this->parser->parse($srt, 'srt');

        $this->assertTrue($result['success']);
        $this->assertStringContainsString('Line one', $result['text']);
        $this->assertStringContainsString('Line two', $result['text']);
    }

    #[Test]
    public function parseSrtStripsHtmlTags(): void
    {
        $srt = "1\n00:00:00,000 --> 00:00:05,000\n<b>Bold text</b>\n";

        $result = $this->parser->parse($srt, 'srt');

        $this->assertTrue($result['success']);
        $this->assertStringContainsString('Bold text', $result['text']);
        $this->assertStringNotContainsString('<b>', $result['text']);
        $this->assertStringNotContainsString('</b>', $result['text']);
    }

    #[Test]
    public function parseSrtSkipsSequenceNumbers(): void
    {
        $srt = "1\n00:00:00,000 --> 00:00:05,000\nHello\n\n" .
               "2\n00:00:05,100 --> 00:00:10,000\nWorld\n";

        $result = $this->parser->parse($srt, 'srt');

        $this->assertTrue($result['success']);
        // The text should not contain bare "1" or "2" as lines
        $lines = explode("\n", $result['text']);
        $this->assertNotContains('1', $lines);
        $this->assertNotContains('2', $lines);
    }

    #[Test]
    public function parseSrtSkipsTimecodeLines(): void
    {
        $srt = "1\n00:00:00,000 --> 00:00:05,000\nHello\n";

        $result = $this->parser->parse($srt, 'srt');

        $this->assertTrue($result['success']);
        $this->assertStringNotContainsString('-->', $result['text']);
        $this->assertStringNotContainsString('00:00', $result['text']);
    }

    #[Test]
    public function parseSrtHandlesWindowsLineEndings(): void
    {
        $srt = "1\r\n00:00:00,000 --> 00:00:05,000\r\nHello world\r\n";

        $result = $this->parser->parse($srt, 'srt');

        $this->assertTrue($result['success']);
        $this->assertStringContainsString('Hello world', $result['text']);
    }

    #[Test]
    public function parseSrtHandlesCarriageReturnOnlyLineEndings(): void
    {
        $srt = "1\r00:00:00,000 --> 00:00:05,000\rHello world\r";

        $result = $this->parser->parse($srt, 'srt');

        $this->assertTrue($result['success']);
        $this->assertStringContainsString('Hello world', $result['text']);
    }

    #[Test]
    public function parseSrtHandlesEmptyBlocks(): void
    {
        $srt = "1\n00:00:00,000 --> 00:00:05,000\nHello\n\n\n\n" .
               "2\n00:00:05,100 --> 00:00:10,000\nWorld\n";

        $result = $this->parser->parse($srt, 'srt');

        $this->assertTrue($result['success']);
        $this->assertStringContainsString('Hello', $result['text']);
        $this->assertStringContainsString('World', $result['text']);
    }

    #[Test]
    public function parseSrtHandlesItalicTags(): void
    {
        $srt = "1\n00:00:00,000 --> 00:00:05,000\n<i>Italic text</i>\n";

        $result = $this->parser->parse($srt, 'srt');

        $this->assertTrue($result['success']);
        $this->assertStringContainsString('Italic text', $result['text']);
        $this->assertStringNotContainsString('<i>', $result['text']);
    }

    #[Test]
    public function parseSrtHandlesNestedTags(): void
    {
        $srt = "1\n00:00:00,000 --> 00:00:05,000\n<b><i>Bold italic</i></b>\n";

        $result = $this->parser->parse($srt, 'srt');

        $this->assertTrue($result['success']);
        $this->assertStringContainsString('Bold italic', $result['text']);
        $this->assertStringNotContainsString('<', $result['text']);
    }

    #[Test]
    public function parseSrtDecodesHtmlEntities(): void
    {
        $srt = "1\n00:00:00,000 --> 00:00:05,000\nIt&apos;s a test &amp; more\n";

        $result = $this->parser->parse($srt, 'srt');

        $this->assertTrue($result['success']);
        $this->assertStringContainsString("It's a test & more", $result['text']);
    }

    #[Test]
    public function parseSrtHandlesLargeSequenceNumbers(): void
    {
        $srt = "999\n00:01:30,000 --> 00:01:35,000\nLate subtitle\n";

        $result = $this->parser->parse($srt, 'srt');

        $this->assertTrue($result['success']);
        $this->assertStringContainsString('Late subtitle', $result['text']);
    }

    // =========================================================================
    // parse() - VTT format
    // =========================================================================

    #[Test]
    public function parseVttExtractsTextFromSingleCue(): void
    {
        $vtt = "WEBVTT\n\n00:00:00.000 --> 00:00:05.000\nHello world\n";

        $result = $this->parser->parse($vtt, 'vtt');

        $this->assertTrue($result['success']);
        $this->assertSame('Hello world', $result['text']);
        $this->assertSame(1, $result['cueCount']);
        $this->assertNull($result['error']);
    }

    #[Test]
    public function parseVttExtractsMultipleCues(): void
    {
        $vtt = "WEBVTT\n\n00:00:00.000 --> 00:00:05.000\nFirst\n\n" .
               "00:00:05.100 --> 00:00:10.000\nSecond\n";

        $result = $this->parser->parse($vtt, 'vtt');

        $this->assertTrue($result['success']);
        $this->assertStringContainsString('First', $result['text']);
        $this->assertStringContainsString('Second', $result['text']);
        $this->assertSame(2, $result['cueCount']);
    }

    #[Test]
    public function parseVttSkipsNoteBlocks(): void
    {
        $vtt = "WEBVTT\n\nNOTE\nThis is a comment\n\n" .
               "00:00:00.000 --> 00:00:05.000\nActual text\n";

        $result = $this->parser->parse($vtt, 'vtt');

        $this->assertTrue($result['success']);
        $this->assertStringContainsString('Actual text', $result['text']);
        $this->assertStringNotContainsString('This is a comment', $result['text']);
    }

    #[Test]
    public function parseVttSkipsStyleBlocks(): void
    {
        $vtt = "WEBVTT\n\nSTYLE\n::cue { color: white; }\n\n" .
               "00:00:00.000 --> 00:00:05.000\nVisible text\n";

        $result = $this->parser->parse($vtt, 'vtt');

        $this->assertTrue($result['success']);
        $this->assertStringContainsString('Visible text', $result['text']);
        $this->assertStringNotContainsString('color', $result['text']);
    }

    #[Test]
    public function parseVttSkipsRegionBlocks(): void
    {
        $vtt = "WEBVTT\n\nREGION\nid:region1\nwidth:50%\n\n" .
               "00:00:00.000 --> 00:00:05.000\nVisible text\n";

        $result = $this->parser->parse($vtt, 'vtt');

        $this->assertTrue($result['success']);
        $this->assertStringContainsString('Visible text', $result['text']);
        $this->assertStringNotContainsString('region1', $result['text']);
    }

    #[Test]
    public function parseVttStripsVttStylingTags(): void
    {
        $vtt = "WEBVTT\n\n00:00:00.000 --> 00:00:05.000\n<b>Bold</b> and <i>italic</i>\n";

        $result = $this->parser->parse($vtt, 'vtt');

        $this->assertTrue($result['success']);
        $this->assertStringContainsString('Bold', $result['text']);
        $this->assertStringContainsString('italic', $result['text']);
        $this->assertStringNotContainsString('<b>', $result['text']);
        $this->assertStringNotContainsString('<i>', $result['text']);
    }

    #[Test]
    public function parseVttStripsVoiceTags(): void
    {
        $vtt = "WEBVTT\n\n00:00:00.000 --> 00:00:05.000\n<v Speaker>Hello there</v>\n";

        $result = $this->parser->parse($vtt, 'vtt');

        $this->assertTrue($result['success']);
        $this->assertStringContainsString('Hello there', $result['text']);
        $this->assertStringNotContainsString('<v', $result['text']);
        $this->assertStringNotContainsString('Speaker', $result['text']);
    }

    #[Test]
    public function parseVttStripsClassTags(): void
    {
        $vtt = "WEBVTT\n\n00:00:00.000 --> 00:00:05.000\n<c.highlight>Highlighted</c>\n";

        $result = $this->parser->parse($vtt, 'vtt');

        $this->assertTrue($result['success']);
        $this->assertStringContainsString('Highlighted', $result['text']);
        $this->assertStringNotContainsString('<c', $result['text']);
    }

    #[Test]
    public function parseVttStripsLanguageTags(): void
    {
        $vtt = "WEBVTT\n\n00:00:00.000 --> 00:00:05.000\n<lang en>English text</lang>\n";

        $result = $this->parser->parse($vtt, 'vtt');

        $this->assertTrue($result['success']);
        $this->assertStringContainsString('English text', $result['text']);
        $this->assertStringNotContainsString('<lang', $result['text']);
    }

    #[Test]
    public function parseVttStripsRubyTags(): void
    {
        $vtt = "WEBVTT\n\n00:00:00.000 --> 00:00:05.000\n<ruby>Base<rt>annotation</rt></ruby>\n";

        $result = $this->parser->parse($vtt, 'vtt');

        $this->assertTrue($result['success']);
        $this->assertStringContainsString('Base', $result['text']);
        $this->assertStringContainsString('annotation', $result['text']);
        $this->assertStringNotContainsString('<ruby>', $result['text']);
    }

    #[Test]
    public function parseVttHandlesCueIdentifiers(): void
    {
        $vtt = "WEBVTT\n\ncue-1\n00:00:00.000 --> 00:00:05.000\nHello\n";

        $result = $this->parser->parse($vtt, 'vtt');

        $this->assertTrue($result['success']);
        $this->assertStringContainsString('Hello', $result['text']);
        // Cue identifier should not appear in text
        $this->assertStringNotContainsString('cue-1', $result['text']);
    }

    #[Test]
    public function parseVttHandlesHeaderWithMetadata(): void
    {
        $vtt = "WEBVTT Kind: captions; Language: en\n\n" .
               "00:00:00.000 --> 00:00:05.000\nCaption text\n";

        $result = $this->parser->parse($vtt, 'vtt');

        $this->assertTrue($result['success']);
        $this->assertStringContainsString('Caption text', $result['text']);
    }

    #[Test]
    public function parseVttHandlesMultilineSubtitles(): void
    {
        $vtt = "WEBVTT\n\n00:00:00.000 --> 00:00:05.000\nLine one\nLine two\n";

        $result = $this->parser->parse($vtt, 'vtt');

        $this->assertTrue($result['success']);
        $this->assertStringContainsString('Line one', $result['text']);
        $this->assertStringContainsString('Line two', $result['text']);
    }

    // =========================================================================
    // parse() - Error cases
    // =========================================================================

    #[Test]
    public function parseReturnsErrorForEmptyContent(): void
    {
        $result = $this->parser->parse('', 'srt');

        $this->assertFalse($result['success']);
        $this->assertSame('', $result['text']);
        $this->assertSame(0, $result['cueCount']);
        $this->assertSame('Subtitle file is empty', $result['error']);
    }

    #[Test]
    public function parseReturnsErrorForWhitespaceOnlyContent(): void
    {
        $result = $this->parser->parse("   \n\n  ", 'srt');

        $this->assertFalse($result['success']);
        $this->assertSame('Subtitle file is empty', $result['error']);
    }

    #[Test]
    public function parseReturnsErrorForUnsupportedFormat(): void
    {
        $result = $this->parser->parse('Some content', 'ass');

        $this->assertFalse($result['success']);
        $this->assertSame('', $result['text']);
        $this->assertSame(0, $result['cueCount']);
        $this->assertStringContainsString('Unsupported format', $result['error']);
        $this->assertStringContainsString('ass', $result['error']);
    }

    #[Test]
    public function parseReturnsErrorWhenNoTextFound(): void
    {
        // SRT with only timecodes and sequence numbers, no actual text
        $srt = "1\n00:00:00,000 --> 00:00:05,000\n\n";

        $result = $this->parser->parse($srt, 'srt');

        $this->assertFalse($result['success']);
        $this->assertSame('No text content found in subtitle file', $result['error']);
    }

    #[Test]
    public function parseReturnsErrorForVttWithOnlyComments(): void
    {
        $vtt = "WEBVTT\n\nNOTE\nThis is just a comment\nNothing else here\n";

        $result = $this->parser->parse($vtt, 'vtt');

        $this->assertFalse($result['success']);
        $this->assertSame('No text content found in subtitle file', $result['error']);
    }

    // =========================================================================
    // detectFormat()
    // =========================================================================

    #[Test]
    public function detectFormatReturnsSrtForSrtExtension(): void
    {
        $this->assertSame('srt', $this->parser->detectFormat('subtitle.srt', ''));
    }

    #[Test]
    public function detectFormatReturnsVttForVttExtension(): void
    {
        $this->assertSame('vtt', $this->parser->detectFormat('subtitle.vtt', ''));
    }

    #[Test]
    public function detectFormatIsCaseInsensitiveForExtension(): void
    {
        $this->assertSame('srt', $this->parser->detectFormat('subtitle.SRT', ''));
        $this->assertSame('vtt', $this->parser->detectFormat('subtitle.VTT', ''));
    }

    #[Test]
    public function detectFormatDetectsVttFromWebvttHeader(): void
    {
        $content = "WEBVTT\n\n00:00:00.000 --> 00:00:05.000\nHello\n";

        $this->assertSame('vtt', $this->parser->detectFormat('unknown.txt', $content));
    }

    #[Test]
    public function detectFormatDetectsVttFromWebvttHeaderWithMetadata(): void
    {
        $content = "WEBVTT Kind: captions\n\n00:00:00.000 --> 00:00:05.000\nHello\n";

        $this->assertSame('vtt', $this->parser->detectFormat('noext', $content));
    }

    #[Test]
    public function detectFormatDetectsSrtFromTimecodePattern(): void
    {
        $content = "1\n00:00:00,000 --> 00:00:05,000\nHello\n";

        $this->assertSame('srt', $this->parser->detectFormat('unknown.txt', $content));
    }

    #[Test]
    public function detectFormatReturnsNullForUnknown(): void
    {
        $this->assertNull($this->parser->detectFormat('file.txt', 'Just plain text'));
    }

    #[Test]
    public function detectFormatReturnsNullForEmptyContent(): void
    {
        $this->assertNull($this->parser->detectFormat('file.txt', ''));
    }

    #[Test]
    public function detectFormatPrefersExtensionOverContent(): void
    {
        // File has .srt extension but contains WEBVTT header
        $content = "WEBVTT\n\n00:00:00.000 --> 00:00:05.000\nHello\n";

        $this->assertSame('srt', $this->parser->detectFormat('file.srt', $content));
    }

    #[Test]
    public function detectFormatHandlesPathsWithDirectories(): void
    {
        $this->assertSame('srt', $this->parser->detectFormat('/path/to/subtitle.srt', ''));
        $this->assertSame('vtt', $this->parser->detectFormat('C:\\path\\to\\file.vtt', ''));
    }

    #[Test]
    public function detectFormatHandlesFilenameWithMultipleDots(): void
    {
        $this->assertSame('srt', $this->parser->detectFormat('my.subtitle.file.srt', ''));
    }

    // =========================================================================
    // isValidSubtitle()
    // =========================================================================

    #[Test]
    public function isValidSubtitleReturnsTrueForValidSrt(): void
    {
        $srt = "1\n00:00:00,000 --> 00:00:05,000\nHello\n";

        $this->assertTrue($this->parser->isValidSubtitle($srt, 'srt'));
    }

    #[Test]
    public function isValidSubtitleReturnsTrueForValidVtt(): void
    {
        $vtt = "WEBVTT\n\n00:00:00.000 --> 00:00:05.000\nHello\n";

        $this->assertTrue($this->parser->isValidSubtitle($vtt, 'vtt'));
    }

    #[Test]
    public function isValidSubtitleReturnsFalseForEmptyContent(): void
    {
        $this->assertFalse($this->parser->isValidSubtitle('', 'srt'));
        $this->assertFalse($this->parser->isValidSubtitle('', 'vtt'));
    }

    #[Test]
    public function isValidSubtitleReturnsFalseForWhitespaceOnly(): void
    {
        $this->assertFalse($this->parser->isValidSubtitle("  \n\n  ", 'srt'));
        $this->assertFalse($this->parser->isValidSubtitle("  \n\n  ", 'vtt'));
    }

    #[Test]
    public function isValidSubtitleReturnsFalseForUnsupportedFormat(): void
    {
        $this->assertFalse($this->parser->isValidSubtitle('some content', 'ass'));
    }

    #[Test]
    public function isValidSrtReturnsFalseForPlainText(): void
    {
        $this->assertFalse($this->parser->isValidSubtitle('Just plain text', 'srt'));
    }

    #[Test]
    public function isValidSrtRequiresCommaInTimecode(): void
    {
        // VTT-style timecodes (with period) should not validate as SRT
        $vttTimecodes = "1\n00:00:00.000 --> 00:00:05.000\nHello\n";

        $this->assertFalse($this->parser->isValidSubtitle($vttTimecodes, 'srt'));
    }

    #[Test]
    public function isValidVttRequiresWebvttHeader(): void
    {
        // SRT content should not validate as VTT
        $srt = "1\n00:00:00,000 --> 00:00:05,000\nHello\n";

        $this->assertFalse($this->parser->isValidSubtitle($srt, 'vtt'));
    }

    #[Test]
    public function isValidVttAcceptsWebvttWithTrailingContent(): void
    {
        $vtt = "WEBVTT - This file has metadata\n\n00:00:00.000 --> 00:00:05.000\nHello\n";

        $this->assertTrue($this->parser->isValidSubtitle($vtt, 'vtt'));
    }

    // =========================================================================
    // parse() - Text cleaning and normalization
    // =========================================================================

    #[Test]
    public function parseNormalizesExcessiveBlankLines(): void
    {
        $srt = "1\n00:00:00,000 --> 00:00:05,000\nFirst\n\n" .
               "2\n00:00:05,100 --> 00:00:10,000\nSecond\n";

        $result = $this->parser->parse($srt, 'srt');

        $this->assertTrue($result['success']);
        // Should not have 3+ consecutive newlines
        $this->assertDoesNotMatchRegularExpression('/\n{3,}/', $result['text']);
    }

    #[Test]
    public function parseTrimsWhitespaceFromLines(): void
    {
        $srt = "1\n00:00:00,000 --> 00:00:05,000\n  Padded text  \n";

        $result = $this->parser->parse($srt, 'srt');

        $this->assertTrue($result['success']);
        $this->assertSame('Padded text', $result['text']);
    }

    #[Test]
    public function parseNormalizesInternalSpaces(): void
    {
        $srt = "1\n00:00:00,000 --> 00:00:05,000\nMultiple   spaces   here\n";

        $result = $this->parser->parse($srt, 'srt');

        $this->assertTrue($result['success']);
        $this->assertSame('Multiple spaces here', $result['text']);
    }

    #[Test]
    public function parseHandlesUnicodeContent(): void
    {
        $srt = "1\n00:00:00,000 --> 00:00:05,000\n日本語テスト\n\n" .
               "2\n00:00:05,100 --> 00:00:10,000\nFrancais avec des accents\n";

        $result = $this->parser->parse($srt, 'srt');

        $this->assertTrue($result['success']);
        $this->assertStringContainsString('日本語テスト', $result['text']);
        $this->assertStringContainsString('Francais avec des accents', $result['text']);
    }

    #[Test]
    public function parseVttWithUnicodeContent(): void
    {
        $vtt = "WEBVTT\n\n00:00:00.000 --> 00:00:05.000\nBonjour le monde\n\n" .
               "00:00:05.100 --> 00:00:10.000\nHallo Welt\n";

        $result = $this->parser->parse($vtt, 'vtt');

        $this->assertTrue($result['success']);
        $this->assertStringContainsString('Bonjour le monde', $result['text']);
        $this->assertStringContainsString('Hallo Welt', $result['text']);
    }

    // =========================================================================
    // parse() - Cue count accuracy
    // =========================================================================

    #[Test]
    public function parseCueCountMatchesNumberOfCues(): void
    {
        $srt = "1\n00:00:00,000 --> 00:00:02,000\nOne\n\n" .
               "2\n00:00:02,100 --> 00:00:04,000\nTwo\n\n" .
               "3\n00:00:04,100 --> 00:00:06,000\nThree\n";

        $result = $this->parser->parse($srt, 'srt');

        $this->assertTrue($result['success']);
        $this->assertSame(3, $result['cueCount']);
    }

    #[Test]
    public function parseVttCueCountMatchesNumberOfCues(): void
    {
        $vtt = "WEBVTT\n\n" .
               "00:00:00.000 --> 00:00:02.000\nOne\n\n" .
               "00:00:02.100 --> 00:00:04.000\nTwo\n\n" .
               "00:00:04.100 --> 00:00:06.000\nThree\n\n" .
               "00:00:06.100 --> 00:00:08.000\nFour\n";

        $result = $this->parser->parse($vtt, 'vtt');

        $this->assertTrue($result['success']);
        $this->assertSame(4, $result['cueCount']);
    }

    // =========================================================================
    // parse() - Complex real-world scenarios
    // =========================================================================

    #[Test]
    public function parseSrtHandlesTypicalMovieSubtitle(): void
    {
        $srt = <<<SRT
1
00:00:01,000 --> 00:00:04,000
<i>In a galaxy far, far away...</i>

2
00:00:05,000 --> 00:00:08,000
A long time ago,
the world was different.

3
00:00:10,000 --> 00:00:13,000
<b>NARRATOR:</b> It all began here.

SRT;

        $result = $this->parser->parse($srt, 'srt');

        $this->assertTrue($result['success']);
        $this->assertSame(3, $result['cueCount']);
        $this->assertStringNotContainsString('<i>', $result['text']);
        $this->assertStringNotContainsString('<b>', $result['text']);
        $this->assertStringContainsString('In a galaxy far, far away...', $result['text']);
        $this->assertStringContainsString('NARRATOR:', $result['text']);
    }

    #[Test]
    public function parseVttHandlesYouTubeStyleCaptions(): void
    {
        $vtt = <<<VTT
WEBVTT Kind: captions; Language: en

00:00:00.000 --> 00:00:02.000 position:10% align:start
Welcome to the tutorial

00:00:02.500 --> 00:00:05.000
Today we will learn something new

NOTE This is an auto-generated caption

00:00:05.500 --> 00:00:08.000
Let's get started!

VTT;

        $result = $this->parser->parse($vtt, 'vtt');

        $this->assertTrue($result['success']);
        $this->assertStringContainsString('Welcome to the tutorial', $result['text']);
        $this->assertStringContainsString("Today we will learn something new", $result['text']);
        $this->assertStringContainsString("Let's get started!", $result['text']);
        $this->assertStringNotContainsString('auto-generated', $result['text']);
    }

    #[Test]
    public function parseVttStripsMultipleVttTagTypes(): void
    {
        $vtt = "WEBVTT\n\n00:00:00.000 --> 00:00:05.000\n" .
               "<b>Bold</b> <i>italic</i> <u>underline</u> <c.cls>class</c> normal\n";

        $result = $this->parser->parse($vtt, 'vtt');

        $this->assertTrue($result['success']);
        $this->assertStringContainsString('Bold', $result['text']);
        $this->assertStringContainsString('italic', $result['text']);
        $this->assertStringContainsString('underline', $result['text']);
        $this->assertStringContainsString('class', $result['text']);
        $this->assertStringContainsString('normal', $result['text']);
        $this->assertStringNotContainsString('<', $result['text']);
    }

    // =========================================================================
    // Private method tests via Reflection - stripVttTags()
    // =========================================================================

    #[Test]
    public function stripVttTagsRemovesBoldTags(): void
    {
        $method = new \ReflectionMethod(SubtitleParserService::class, 'stripVttTags');

        $result = $method->invoke($this->parser, '<b>Bold</b>');

        $this->assertSame('Bold', $result);
    }

    #[Test]
    public function stripVttTagsRemovesVoiceTagWithName(): void
    {
        $method = new \ReflectionMethod(SubtitleParserService::class, 'stripVttTags');

        $result = $method->invoke($this->parser, '<v John>Hello</v>');

        $this->assertSame('Hello', $result);
    }

    #[Test]
    public function stripVttTagsRemovesLangTag(): void
    {
        $method = new \ReflectionMethod(SubtitleParserService::class, 'stripVttTags');

        $result = $method->invoke($this->parser, '<lang fr>Bonjour</lang>');

        $this->assertSame('Bonjour', $result);
    }

    #[Test]
    public function stripVttTagsHandlesPlainText(): void
    {
        $method = new \ReflectionMethod(SubtitleParserService::class, 'stripVttTags');

        $result = $method->invoke($this->parser, 'No tags here');

        $this->assertSame('No tags here', $result);
    }

    #[Test]
    public function stripVttTagsHandlesEmptyString(): void
    {
        $method = new \ReflectionMethod(SubtitleParserService::class, 'stripVttTags');

        $result = $method->invoke($this->parser, '');

        $this->assertSame('', $result);
    }

    // =========================================================================
    // Private method tests via Reflection - cleanText()
    // =========================================================================

    #[Test]
    public function cleanTextDecodesHtmlEntities(): void
    {
        $method = new \ReflectionMethod(SubtitleParserService::class, 'cleanText');

        $result = $method->invoke($this->parser, 'It&apos;s &amp; them');

        $this->assertStringContainsString("It's & them", $result);
    }

    #[Test]
    public function cleanTextNormalizesSpaces(): void
    {
        $method = new \ReflectionMethod(SubtitleParserService::class, 'cleanText');

        $result = $method->invoke($this->parser, "multiple   spaces\there");

        $this->assertSame('multiple spaces here', $result);
    }

    #[Test]
    public function cleanTextCollapsesExcessiveNewlines(): void
    {
        $method = new \ReflectionMethod(SubtitleParserService::class, 'cleanText');

        $result = $method->invoke($this->parser, "para1\n\n\n\n\npara2");

        $this->assertSame("para1\n\npara2", $result);
    }

    #[Test]
    public function cleanTextTrimsResult(): void
    {
        $method = new \ReflectionMethod(SubtitleParserService::class, 'cleanText');

        $result = $method->invoke($this->parser, "  text  ");

        $this->assertSame('text', $result);
    }

    #[Test]
    public function cleanTextTrimsEachLine(): void
    {
        $method = new \ReflectionMethod(SubtitleParserService::class, 'cleanText');

        $result = $method->invoke($this->parser, "  line1  \n  line2  ");

        $this->assertSame("line1\nline2", $result);
    }

    #[Test]
    public function cleanTextHandlesEmptyString(): void
    {
        $method = new \ReflectionMethod(SubtitleParserService::class, 'cleanText');

        $result = $method->invoke($this->parser, '');

        $this->assertSame('', $result);
    }
}
