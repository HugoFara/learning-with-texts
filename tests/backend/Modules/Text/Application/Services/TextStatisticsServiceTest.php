<?php

/**
 * Unit tests for TextStatisticsService.
 *
 * The service methods rely heavily on database queries and static methods
 * (Connection::preparedFetchAll, Connection::preparedFetchValue).
 * This test file validates instantiation, method signatures, return
 * type contracts, and empty-input behavior. Integration tests with a
 * real database are needed for full query coverage.
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

use Lwt\Modules\Text\Application\Services\TextStatisticsService;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * Unit tests for TextStatisticsService.
 *
 * @since  3.0.0
 */
#[CoversClass(TextStatisticsService::class)]
class TextStatisticsServiceTest extends TestCase
{
    // =========================================================================
    // Instantiation and method existence
    // =========================================================================

    #[Test]
    public function canBeInstantiated(): void
    {
        $service = new TextStatisticsService();

        $this->assertInstanceOf(TextStatisticsService::class, $service);
    }

    #[Test]
    public function getTextWordCountMethodExists(): void
    {
        $service = new TextStatisticsService();

        $this->assertTrue(
            method_exists($service, 'getTextWordCount'),
            'getTextWordCount method should exist'
        );
    }

    #[Test]
    public function getTodoWordsCountMethodExists(): void
    {
        $service = new TextStatisticsService();

        $this->assertTrue(
            method_exists($service, 'getTodoWordsCount'),
            'getTodoWordsCount method should exist'
        );
    }

    #[Test]
    public function getTodoWordsContentMethodExists(): void
    {
        $service = new TextStatisticsService();

        $this->assertTrue(
            method_exists($service, 'getTodoWordsContent'),
            'getTodoWordsContent method should exist'
        );
    }

    // =========================================================================
    // Method signatures
    // =========================================================================

    #[Test]
    public function getTextWordCountAcceptsArrayParameter(): void
    {
        $method = new \ReflectionMethod(TextStatisticsService::class, 'getTextWordCount');
        $params = $method->getParameters();

        $this->assertCount(1, $params);
        $this->assertSame('textIds', $params[0]->getName());
        $this->assertSame('array', $params[0]->getType()->getName());
    }

    #[Test]
    public function getTodoWordsCountAcceptsIntParameter(): void
    {
        $method = new \ReflectionMethod(TextStatisticsService::class, 'getTodoWordsCount');
        $params = $method->getParameters();

        $this->assertCount(1, $params);
        $this->assertSame('textId', $params[0]->getName());
        $this->assertSame('int', $params[0]->getType()->getName());
    }

    #[Test]
    public function getTodoWordsContentAcceptsIntParameter(): void
    {
        $method = new \ReflectionMethod(TextStatisticsService::class, 'getTodoWordsContent');
        $params = $method->getParameters();

        $this->assertCount(1, $params);
        $this->assertSame('textId', $params[0]->getName());
        $this->assertSame('int', $params[0]->getType()->getName());
    }

    // =========================================================================
    // Return types
    // =========================================================================

    #[Test]
    public function getTextWordCountReturnsArray(): void
    {
        $method = new \ReflectionMethod(TextStatisticsService::class, 'getTextWordCount');

        $this->assertSame('array', $method->getReturnType()->getName());
    }

    #[Test]
    public function getTodoWordsCountReturnsInt(): void
    {
        $method = new \ReflectionMethod(TextStatisticsService::class, 'getTodoWordsCount');

        $this->assertSame('int', $method->getReturnType()->getName());
    }

    #[Test]
    public function getTodoWordsContentReturnsString(): void
    {
        $method = new \ReflectionMethod(TextStatisticsService::class, 'getTodoWordsContent');

        $this->assertSame('string', $method->getReturnType()->getName());
    }

    // =========================================================================
    // Empty input behavior
    // =========================================================================

    #[Test]
    public function getTextWordCountWithEmptyArrayReturnsEmptyStructure(): void
    {
        $service = new TextStatisticsService();
        $result = $service->getTextWordCount([]);

        $this->assertIsArray($result);
        $this->assertArrayHasKey('total', $result);
        $this->assertArrayHasKey('expr', $result);
        $this->assertArrayHasKey('stat', $result);
        $this->assertArrayHasKey('totalu', $result);
        $this->assertArrayHasKey('expru', $result);
        $this->assertArrayHasKey('statu', $result);

        $this->assertSame([], $result['total']);
        $this->assertSame([], $result['expr']);
        $this->assertSame([], $result['stat']);
        $this->assertSame([], $result['totalu']);
        $this->assertSame([], $result['expru']);
        $this->assertSame([], $result['statu']);
    }

    #[Test]
    public function getTextWordCountReturnsSixKeys(): void
    {
        $service = new TextStatisticsService();
        $result = $service->getTextWordCount([]);

        $this->assertCount(6, $result);
    }

    // =========================================================================
    // Visibility checks
    // =========================================================================

    #[Test]
    public function getTextWordCountIsPublic(): void
    {
        $method = new \ReflectionMethod(TextStatisticsService::class, 'getTextWordCount');
        $this->assertTrue($method->isPublic());
    }

    #[Test]
    public function getTodoWordsCountIsPublic(): void
    {
        $method = new \ReflectionMethod(TextStatisticsService::class, 'getTodoWordsCount');
        $this->assertTrue($method->isPublic());
    }

    #[Test]
    public function getTodoWordsContentIsPublic(): void
    {
        $method = new \ReflectionMethod(TextStatisticsService::class, 'getTodoWordsContent');
        $this->assertTrue($method->isPublic());
    }

    // =========================================================================
    // Service is not abstract/final
    // =========================================================================

    #[Test]
    public function serviceIsNotAbstract(): void
    {
        $class = new \ReflectionClass(TextStatisticsService::class);
        $this->assertFalse($class->isAbstract());
    }

    #[Test]
    public function serviceHasNoConstructorDependencies(): void
    {
        $class = new \ReflectionClass(TextStatisticsService::class);
        $constructor = $class->getConstructor();

        // No constructor or empty constructor
        $this->assertTrue(
            $constructor === null || $constructor->getNumberOfRequiredParameters() === 0,
            'TextStatisticsService should have no required constructor parameters'
        );
    }

    // =========================================================================
    // getTextWordCount structure contract
    // =========================================================================

    #[Test]
    public function getTextWordCountResultKeysAreArrays(): void
    {
        $service = new TextStatisticsService();
        $result = $service->getTextWordCount([]);

        foreach (['total', 'expr', 'stat', 'totalu', 'expru', 'statu'] as $key) {
            $this->assertIsArray(
                $result[$key],
                "Result key '$key' should be an array"
            );
        }
    }

    #[Test]
    public function getTextWordCountEmptyArrayDoesNotHitDatabase(): void
    {
        // This test verifies the early return for empty arrays
        // If it hit the DB without a connection, it would throw an exception
        $service = new TextStatisticsService();
        $result = $service->getTextWordCount([]);

        $this->assertNotNull($result);
    }

    // =========================================================================
    // Parameter type safety
    // =========================================================================

    #[Test]
    public function getTextWordCountParameterIsNotOptional(): void
    {
        $method = new \ReflectionMethod(TextStatisticsService::class, 'getTextWordCount');
        $params = $method->getParameters();

        $this->assertFalse($params[0]->isOptional());
    }

    #[Test]
    public function getTodoWordsCountParameterIsNotOptional(): void
    {
        $method = new \ReflectionMethod(TextStatisticsService::class, 'getTodoWordsCount');
        $params = $method->getParameters();

        $this->assertFalse($params[0]->isOptional());
    }

    #[Test]
    public function getTodoWordsContentParameterIsNotOptional(): void
    {
        $method = new \ReflectionMethod(TextStatisticsService::class, 'getTodoWordsContent');
        $params = $method->getParameters();

        $this->assertFalse($params[0]->isOptional());
    }
}
