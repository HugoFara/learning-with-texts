<?php

/**
 * Unit tests for InputValidator::sanitizeUploadName.
 *
 * PHP version 8.2
 *
 * @category Testing
 * @package  Lwt\Tests\Shared\Infrastructure\Http
 * @license  Unlicense <http://unlicense.org/>
 * @since    3.0.0
 */

declare(strict_types=1);

namespace Lwt\Tests\Shared\Infrastructure\Http;

use Lwt\Shared\Infrastructure\Http\InputValidator;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

class InputValidatorUploadNameTest extends TestCase
{
    /**
     * @return array<string, array{string, string}>
     */
    public static function nameProvider(): array
    {
        return [
            'plain'                => ['recording.mp3', 'recording.mp3'],
            'path traversal'       => ['../../../etc/passwd', 'passwd'],
            'nul byte'             => ["evil\x00.mp3", 'evil.mp3'],
            'control chars'        => ["bad\x01\x02name.mp3", 'badname.mp3'],
            // U+202E (right-to-left override) — "trojan source" payload.
            'rtl override'         => ["safe\xE2\x80\xAEexe.mp3", 'safeexe.mp3'],
            // U+2066 (LRI) bidi-isolate.
            'bidi isolate'         => ["normal\xE2\x81\xA6trick.txt", 'normaltrick.txt'],
            'lone basename'        => ['/var/lib/data.csv', 'data.csv'],
            'empty'                => ['', 'unknown'],
            'just bidi controls'   => ["\xE2\x80\xAE\xE2\x80\xAE", 'unknown'],
        ];
    }

    #[Test]
    #[DataProvider('nameProvider')]
    public function sanitizesAtIngestion(string $input, string $expected): void
    {
        $this->assertSame($expected, InputValidator::sanitizeUploadName($input));
    }

    #[Test]
    public function clampsAtFilesystemLimit(): void
    {
        $name = str_repeat('a', 300) . '.txt';
        $clamped = InputValidator::sanitizeUploadName($name);
        // 255 is the cap on most filesystems (ext4, exfat); names
        // longer than that nearly always signal an attack or a buggy
        // client.
        $this->assertSame(255, strlen($clamped));
    }
}
