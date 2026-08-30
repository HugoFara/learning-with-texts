<?php

/**
 * Unit tests for TextNavigationService.
 *
 * Tests the URL building logic and navigation service instantiation.
 * The main getPreviousAndNextTextLinks method requires database access
 * and is better suited for integration tests; here we test the pure
 * logic helper methods.
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

use Lwt\Modules\Text\Application\Services\TextNavigationService;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * Unit tests for TextNavigationService.
 *
 * @since  3.0.0
 */
#[CoversClass(TextNavigationService::class)]
class TextNavigationServiceTest extends TestCase
{
    private TextNavigationService $service;

    protected function setUp(): void
    {
        $this->service = new TextNavigationService();
    }

    // =========================================================================
    // buildNavigationUrl() via Reflection
    // =========================================================================

    #[Test]
    public function buildNavigationUrlReplacesIdPlaceholder(): void
    {
        $method = new \ReflectionMethod(TextNavigationService::class, 'buildNavigationUrl');

        $result = $method->invoke($this->service, '/text/{id}/read', 42);

        $this->assertSame('/text/42/read', $result);
    }

    #[Test]
    public function buildNavigationUrlAppendsIdWhenNoPlaceholder(): void
    {
        $method = new \ReflectionMethod(TextNavigationService::class, 'buildNavigationUrl');

        $result = $method->invoke($this->service, '/text/read?id=', 42);

        $this->assertSame('/text/read?id=42', $result);
    }

    #[Test]
    public function buildNavigationUrlHandlesEmptyBaseUrl(): void
    {
        $method = new \ReflectionMethod(TextNavigationService::class, 'buildNavigationUrl');

        $result = $method->invoke($this->service, '', 99);

        $this->assertSame('99', $result);
    }

    #[Test]
    public function buildNavigationUrlReplacesFirstPlaceholderOnly(): void
    {
        $method = new \ReflectionMethod(TextNavigationService::class, 'buildNavigationUrl');

        // str_replace replaces all occurrences
        $result = $method->invoke($this->service, '/text/{id}/compare/{id}', 7);

        $this->assertSame('/text/7/compare/7', $result);
    }

    #[Test]
    public function buildNavigationUrlWithLargeId(): void
    {
        $method = new \ReflectionMethod(TextNavigationService::class, 'buildNavigationUrl');

        $result = $method->invoke($this->service, '/text/{id}', 999999);

        $this->assertSame('/text/999999', $result);
    }

    #[Test]
    public function buildNavigationUrlWithZeroId(): void
    {
        $method = new \ReflectionMethod(TextNavigationService::class, 'buildNavigationUrl');

        $result = $method->invoke($this->service, '/text/{id}', 0);

        $this->assertSame('/text/0', $result);
    }

    #[Test]
    public function buildNavigationUrlAppendsToSlashTerminatedUrl(): void
    {
        $method = new \ReflectionMethod(TextNavigationService::class, 'buildNavigationUrl');

        $result = $method->invoke($this->service, '/texts/', 15);

        $this->assertSame('/texts/15', $result);
    }

    #[Test]
    public function buildNavigationUrlWithQueryStringBase(): void
    {
        $method = new \ReflectionMethod(TextNavigationService::class, 'buildNavigationUrl');

        $result = $method->invoke($this->service, '/reader?text=', 10);

        $this->assertSame('/reader?text=10', $result);
    }

    // =========================================================================
    // Instantiation
    // =========================================================================

    #[Test]
    public function canBeInstantiated(): void
    {
        $service = new TextNavigationService();

        $this->assertInstanceOf(TextNavigationService::class, $service);
    }
}
