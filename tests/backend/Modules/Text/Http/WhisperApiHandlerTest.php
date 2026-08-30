<?php

/**
 * Unit tests for WhisperApiHandler upload-validation helpers.
 *
 * Targets the private filename sanitization and MIME re-verification
 * helpers added to defend against masquerading uploads. Exercises
 * them through reflection so we don't need a live NLP service.
 *
 * PHP version 8.2
 *
 * @category Testing
 * @package  Lwt\Tests\Modules\Text\Http
 * @license  Unlicense <http://unlicense.org/>
 * @since    3.0.0
 */

declare(strict_types=1);

namespace Lwt\Tests\Modules\Text\Http;

use Lwt\Modules\Text\Http\WhisperApiHandler;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(WhisperApiHandler::class)]
class WhisperApiHandlerTest extends TestCase
{
    /**
     * @return array<string, array{string, string}>
     */
    public static function filenameProvider(): array
    {
        return [
            'plain'            => ['recording.mp3', 'recording.mp3'],
            'path traversal'   => ['../../../etc/passwd.mp3', 'passwd.mp3'],
            'nul byte'         => ["evil\x00.mp3", 'evil.mp3'],
            'control chars'    => ["bad\x01\x02name.mp3", 'badname.mp3'],
            // U+202E (right-to-left override) — the "trojan source" payload
            // that makes "exe.mp3" render as "mp3.exe".
            'rtl override'     => ["safe\xE2\x80\xAEexe.mp3", 'safeexe.mp3'],
            'lone basename'    => ['/var/lib/recording.wav', 'recording.wav'],
            'empty'            => ['', 'unknown'],
        ];
    }

    #[Test]
    #[DataProvider('filenameProvider')]
    public function sanitizeFilenameStripsPathAndControlChars(string $input, string $expected): void
    {
        // setAccessible() is a no-op since 8.1 and deprecated in 8.5;
        // ReflectionMethod can invoke private static methods directly.
        $method = new \ReflectionMethod(WhisperApiHandler::class, 'sanitizeFilename');

        $this->assertSame($expected, $method->invoke(null, $input));
    }

    #[Test]
    public function assertAudioVideoMimeAcceptsRealAudio(): void
    {
        if (!function_exists('finfo_open')) {
            $this->markTestSkipped('finfo extension required');
        }
        // Minimal WAV header so finfo recognizes audio/x-wav.
        $tmp = tempnam(sys_get_temp_dir(), 'whisper_wav_');
        $this->assertIsString($tmp);
        try {
            file_put_contents(
                $tmp,
                "RIFF\x24\x00\x00\x00WAVEfmt \x10\x00\x00\x00"
                . "\x01\x00\x01\x00\x44\xAC\x00\x00\x88\x58\x01\x00"
                . "\x02\x00\x10\x00data\x00\x00\x00\x00"
            );
            $method = new \ReflectionMethod(WhisperApiHandler::class, 'assertAudioVideoMime');
            $method->invoke(null, $tmp);
            $this->assertTrue(true, 'WAV header should pass MIME check');
        } finally {
            @unlink($tmp);
        }
    }

    #[Test]
    public function assertAudioVideoMimeRejectsHtmlMasqueradingAsAudio(): void
    {
        if (!function_exists('finfo_open')) {
            $this->markTestSkipped('finfo extension required');
        }
        $tmp = tempnam(sys_get_temp_dir(), 'whisper_html_');
        $this->assertIsString($tmp);
        try {
            file_put_contents($tmp, "<!DOCTYPE html><html><body>not audio</body></html>");

            $method = new \ReflectionMethod(WhisperApiHandler::class, 'assertAudioVideoMime');

            $this->expectException(\InvalidArgumentException::class);
            $this->expectExceptionMessageMatches('/does not match an audio or video type/');
            $method->invoke(null, $tmp);
        } finally {
            @unlink($tmp);
        }
    }
}
