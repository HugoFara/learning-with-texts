<?php

/**
 * Unit tests for AudioUriValidator.
 *
 * PHP version 8.2
 *
 * @category Testing
 * @package  Lwt\Tests\Modules\Text\Domain
 * @license  Unlicense <http://unlicense.org/>
 * @since    3.0.0
 */

declare(strict_types=1);

namespace Tests\Modules\Text\Domain;

use Lwt\Modules\Text\Domain\AudioUriValidator;
use Lwt\Shared\Infrastructure\Globals;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for AudioUriValidator.
 */
#[CoversClass(AudioUriValidator::class)]
class AudioUriValidatorTest extends TestCase
{
    private bool $originalMultiUser;
    private ?int $originalUserId;

    protected function setUp(): void
    {
        parent::setUp();
        // Snapshot Globals so we can restore them after each test.
        $this->originalMultiUser = Globals::isMultiUserEnabled();
        $this->originalUserId = Globals::getCurrentUserId();
    }

    protected function tearDown(): void
    {
        Globals::setMultiUserEnabled($this->originalMultiUser);
        Globals::setCurrentUserId($this->originalUserId);
        parent::tearDown();
    }

    // =========================================================================
    // Always-allowed values
    // =========================================================================

    #[Test]
    public function emptyStringIsAllowed(): void
    {
        $this->assertSame('', AudioUriValidator::validate(''));
    }

    #[Test]
    public function httpUrlIsAllowed(): void
    {
        $url = 'http://example.com/audio.mp3';
        $this->assertSame($url, AudioUriValidator::validate($url));
    }

    #[Test]
    public function httpsUrlIsAllowed(): void
    {
        $url = 'https://example.com/audio.mp3?token=abc';
        $this->assertSame($url, AudioUriValidator::validate($url));
    }

    // =========================================================================
    // Always-rejected values
    // =========================================================================

    #[Test]
    public function javascriptSchemeIsRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        AudioUriValidator::validate('javascript:alert(1)');
    }

    #[Test]
    public function dataSchemeIsRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        AudioUriValidator::validate('data:audio/mp3;base64,AAAA');
    }

    #[Test]
    public function fileSchemeIsRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        AudioUriValidator::validate('file:///etc/passwd');
    }

    #[Test]
    public function nullByteIsRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        AudioUriValidator::validate("media/audio.mp3\0evil");
    }

    #[Test]
    public function pathTraversalIsRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        AudioUriValidator::validate('media/../etc/passwd');
    }

    #[Test]
    public function absolutePathIsRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        AudioUriValidator::validate('/etc/passwd');
    }

    #[Test]
    public function relativePathOutsideMediaIsRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        AudioUriValidator::validate('audio/sample.mp3');
    }

    // =========================================================================
    // Single-user mode
    // =========================================================================

    #[Test]
    public function singleUserAllowsBareMediaPath(): void
    {
        Globals::setMultiUserEnabled(false);
        $value = 'media/sample.mp3';
        $this->assertSame($value, AudioUriValidator::validate($value));
    }

    #[Test]
    public function singleUserAllowsSubdirMediaPath(): void
    {
        Globals::setMultiUserEnabled(false);
        $value = 'media/podcasts/episode-12.mp3';
        $this->assertSame($value, AudioUriValidator::validate($value));
    }

    // =========================================================================
    // Multi-user mode: per-user subdir requirement + grandfathering
    // =========================================================================

    #[Test]
    public function multiUserRequiresUserSubdirOnNewValue(): void
    {
        Globals::setMultiUserEnabled(true);
        Globals::setCurrentUserId(42);

        $this->expectException(\InvalidArgumentException::class);
        AudioUriValidator::validate('media/someone-elses.mp3');
    }

    #[Test]
    public function multiUserAllowsOwnUserSubdir(): void
    {
        Globals::setMultiUserEnabled(true);
        Globals::setCurrentUserId(42);

        $value = 'media/u42/audio.mp3';
        $this->assertSame($value, AudioUriValidator::validate($value));
    }

    #[Test]
    public function multiUserGrandfathersUnchangedValue(): void
    {
        Globals::setMultiUserEnabled(true);
        Globals::setCurrentUserId(42);

        // Pre-existing value not under the per-user subdir survives if
        // the new value is identical to the previous one — keeps legacy
        // data from breaking when the owner re-saves the row.
        $legacy = 'media/old-uploads/audio.mp3';
        $this->assertSame(
            $legacy,
            AudioUriValidator::validate($legacy, $legacy)
        );
    }

    #[Test]
    public function multiUserRejectsForeignUserSubdir(): void
    {
        Globals::setMultiUserEnabled(true);
        Globals::setCurrentUserId(42);

        // The point of the audit: user 42 cannot save user 7's path.
        $this->expectException(\InvalidArgumentException::class);
        AudioUriValidator::validate('media/u7/private.mp3');
    }

    #[Test]
    public function multiUserStillRejectsTraversalEvenWhenGrandfathering(): void
    {
        Globals::setMultiUserEnabled(true);
        Globals::setCurrentUserId(42);

        // Traversal must be blocked regardless of grandfathering. The
        // legacy value would never have been saved in the first place,
        // so this protects against a hand-crafted DB row too.
        $this->expectException(\InvalidArgumentException::class);
        AudioUriValidator::validate('media/../etc/passwd', 'media/../etc/passwd');
    }
}
