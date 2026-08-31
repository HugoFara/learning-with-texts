<?php

/**
 * Unit tests for GetSetting use case.
 *
 * PHP version 8.2
 *
 * @category Testing
 * @package  Lwt\Tests\Modules\Admin\Application\UseCases\Settings
 * @license  Unlicense <http://unlicense.org/>
 * @since    3.0.0
 */

declare(strict_types=1);

namespace Lwt\Tests\Modules\Admin\Application\UseCases\Settings;

use Lwt\Modules\Admin\Application\UseCases\Settings\GetSetting;
use Lwt\Modules\Admin\Domain\SettingsRepositoryInterface;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Tests for the GetSetting use case.
 *
 * @since 3.0.0
 */
class GetSettingTest extends TestCase
{
    private SettingsRepositoryInterface&MockObject $repository;
    private GetSetting $useCase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->repository = $this->createMock(SettingsRepositoryInterface::class);
        $this->useCase = new GetSetting($this->repository);
    }

    #[Test]
    public function executeReturnsSettingValue(): void
    {
        $this->repository
            ->expects($this->once())
            ->method('get')
            ->with('set-theme-dir', '')
            ->willReturn('Dark');

        $result = $this->useCase->execute('set-theme-dir');
        $this->assertSame('Dark', $result);
    }

    #[Test]
    public function executePassesDefaultToRepository(): void
    {
        $this->repository
            ->expects($this->once())
            ->method('get')
            ->with('set-tooltip-mode', 'hover')
            ->willReturn('hover');

        $result = $this->useCase->execute('set-tooltip-mode', 'hover');
        $this->assertSame('hover', $result);
    }

    #[Test]
    public function executeReturnsDefaultWhenNotFound(): void
    {
        $this->repository
            ->expects($this->once())
            ->method('get')
            ->with('nonexistent-key', 'fallback')
            ->willReturn('fallback');

        $result = $this->useCase->execute('nonexistent-key', 'fallback');
        $this->assertSame('fallback', $result);
    }

    #[Test]
    public function executeReturnsEmptyStringByDefault(): void
    {
        $this->repository
            ->expects($this->once())
            ->method('get')
            ->with('missing-key', '')
            ->willReturn('');

        $result = $this->useCase->execute('missing-key');
        $this->assertSame('', $result);
    }
}
