<?php

/**
 * Unit tests for ResetAllSettings use case.
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

use Lwt\Modules\Admin\Application\UseCases\Settings\ResetAllSettings;
use Lwt\Modules\Admin\Domain\SettingsRepositoryInterface;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Tests for the ResetAllSettings use case.
 *
 * @since 3.0.0
 */
class ResetAllSettingsTest extends TestCase
{
    private SettingsRepositoryInterface&MockObject $repository;
    private ResetAllSettings $useCase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->repository = $this->createMock(SettingsRepositoryInterface::class);
        $this->useCase = new ResetAllSettings($this->repository);
    }

    #[Test]
    public function executeDeletesSettingsWithSetPrefix(): void
    {
        $this->repository
            ->expects($this->once())
            ->method('deleteByPattern')
            ->with('set-%')
            ->willReturn(24);

        $result = $this->useCase->execute();
        $this->assertSame(['success' => true], $result);
    }

    #[Test]
    public function executeReturnsSuccessEvenWhenNoSettingsDeleted(): void
    {
        $this->repository
            ->expects($this->once())
            ->method('deleteByPattern')
            ->with('set-%')
            ->willReturn(0);

        $result = $this->useCase->execute();
        $this->assertSame(['success' => true], $result);
    }

    #[Test]
    public function executeUsesCorrectPattern(): void
    {
        $this->repository
            ->expects($this->once())
            ->method('deleteByPattern')
            ->with($this->callback(function (string $pattern): bool {
                return str_starts_with($pattern, 'set-');
            }))
            ->willReturn(5);

        $this->useCase->execute();
    }
}
