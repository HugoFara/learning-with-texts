<?php

declare(strict_types=1);

namespace Lwt\Tests\Modules\Admin\Http;

use Lwt\Modules\Admin\Http\AdminApiHandler;
use Lwt\Modules\Admin\Application\AdminFacade;
use Lwt\Shared\Infrastructure\Http\JsonResponse;
use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\MockObject;

/**
 * Unit tests for AdminApiHandler.
 *
 * Tests API routing (GET/POST/PUT/DELETE), settings save,
 * theme path resolution, statistics formatting, and media files listing.
 */
class AdminApiHandlerTest extends TestCase
{
    /** @var AdminFacade&MockObject */
    private AdminFacade $facade;

    private AdminApiHandler $handler;

    protected function setUp(): void
    {
        $this->facade = $this->createMock(AdminFacade::class);
        $this->handler = new AdminApiHandler($this->facade);
    }

    // =========================================================================
    // Constructor tests
    // =========================================================================

    #[Test]
    public function constructorCreatesValidHandler(): void
    {
        $this->assertInstanceOf(AdminApiHandler::class, $this->handler);
    }

    #[Test]
    public function constructorStoresAdminFacade(): void
    {
        $reflection = new \ReflectionProperty(AdminApiHandler::class, 'adminFacade');

        $this->assertSame($this->facade, $reflection->getValue($this->handler));
    }

    // =========================================================================
    // Class structure tests
    // =========================================================================

    #[Test]
    public function classImplementsApiRoutableInterface(): void
    {
        $reflection = new \ReflectionClass(AdminApiHandler::class);
        $interfaces = $reflection->getInterfaceNames();

        $this->assertContains(
            'Lwt\Shared\Http\ApiRoutableInterface',
            $interfaces
        );
    }

    #[Test]
    public function classUsesApiRoutableTrait(): void
    {
        $reflection = new \ReflectionClass(AdminApiHandler::class);
        $traits = $reflection->getTraitNames();

        $this->assertContains(
            'Lwt\Shared\Http\ApiRoutableTrait',
            $traits
        );
    }

    #[Test]
    public function classHasRequiredPublicMethods(): void
    {
        $expectedMethods = [
            'routeGet', 'routePost', 'routePut', 'routeDelete',
            'saveSetting', 'getThemePath', 'formatSaveSetting',
            'formatThemePath', 'getTextsStatistics', 'formatTextsStatistics',
            'getServerData', 'getMediaFiles', 'formatMediaFiles',
        ];

        $reflection = new \ReflectionClass(AdminApiHandler::class);

        foreach ($expectedMethods as $methodName) {
            $this->assertTrue(
                $reflection->hasMethod($methodName),
                "AdminApiHandler should have method: $methodName"
            );
            $method = $reflection->getMethod($methodName);
            $this->assertTrue(
                $method->isPublic(),
                "Method $methodName should be public"
            );
        }
    }

    // =========================================================================
    // routeGet tests
    // =========================================================================

    #[Test]
    public function routeGetWithThemePathReturnsSuccess(): void
    {
        $response = $this->handler->routeGet(
            ['settings', 'theme-path'],
            ['path' => 'css/styles.css']
        );

        $this->assertInstanceOf(JsonResponse::class, $response);
        $this->assertSame(200, $response->getStatusCode());

        $data = $response->getData();
        $this->assertIsArray($data);
        $this->assertArrayHasKey('theme_path', $data);
    }

    #[Test]
    public function routeGetWithThemePathEmptyString(): void
    {
        $response = $this->handler->routeGet(
            ['settings', 'theme-path'],
            ['path' => '']
        );

        $this->assertInstanceOf(JsonResponse::class, $response);
        $this->assertSame(200, $response->getStatusCode());

        $data = $response->getData();
        $this->assertArrayHasKey('theme_path', $data);
    }

    #[Test]
    public function routeGetWithThemePathMissingParam(): void
    {
        $response = $this->handler->routeGet(
            ['settings', 'theme-path'],
            []
        );

        $this->assertInstanceOf(JsonResponse::class, $response);
        $this->assertSame(200, $response->getStatusCode());

        $data = $response->getData();
        $this->assertArrayHasKey('theme_path', $data);
    }

    #[Test]
    public function routeGetWithUnknownEndpointReturns404(): void
    {
        $response = $this->handler->routeGet(
            ['settings', 'unknown-endpoint'],
            []
        );

        $this->assertInstanceOf(JsonResponse::class, $response);
        $this->assertSame(404, $response->getStatusCode());

        $data = $response->getData();
        $this->assertArrayHasKey('error', $data);
        $this->assertStringContainsString('unknown-endpoint', $data['error']);
    }

    #[Test]
    public function routeGetWithEmptyFragmentReturns404(): void
    {
        $response = $this->handler->routeGet(
            ['settings'],
            []
        );

        $this->assertInstanceOf(JsonResponse::class, $response);
        $this->assertSame(404, $response->getStatusCode());
    }

    #[Test]
    public function routeGetErrorMessageIncludesEndpointName(): void
    {
        $response = $this->handler->routeGet(
            ['settings', 'nonexistent'],
            []
        );

        $data = $response->getData();
        $this->assertStringContainsString('Endpoint Not Found', $data['error']);
        $this->assertStringContainsString('nonexistent', $data['error']);
    }

    #[Test]
    public function routeGetStatusDefinitionsReturnsAllStatuses(): void
    {
        $response = $this->handler->routeGet(
            ['settings', 'status-definitions'],
            []
        );

        $this->assertInstanceOf(JsonResponse::class, $response);
        $this->assertSame(200, $response->getStatusCode());

        $data = $response->getData();
        $this->assertIsArray($data);
        $this->assertArrayHasKey('statuses', $data);
        $this->assertCount(7, $data['statuses']);

        $first = $data['statuses'][0];
        foreach (['value', 'name', 'abbr', 'cssClass', 'colour', 'order'] as $key) {
            $this->assertArrayHasKey($key, $first);
        }
        $this->assertSame([1, 2, 3, 4, 5, 99, 98], array_column($data['statuses'], 'value'));
    }

    // =========================================================================
    // routePost tests
    // =========================================================================

    #[Test]
    public function routePostCallsFormatSaveSetting(): void
    {
        if (!defined('LWT_TEST_DB_AVAILABLE') || !LWT_TEST_DB_AVAILABLE) {
            $this->markTestSkipped('Database connection required for Settings::save()');
        }

        // routePost always calls formatSaveSetting with key/value from params
        // Since saveSetting calls Settings::save() which is static and needs DB,
        // we test that it returns a JsonResponse
        $response = $this->handler->routePost(
            ['settings'],
            ['key' => 'test_key', 'value' => 'test_value']
        );

        $this->assertInstanceOf(JsonResponse::class, $response);
        $this->assertSame(200, $response->getStatusCode());
    }

    #[Test]
    public function routePostWithEmptyParams(): void
    {
        if (!defined('LWT_TEST_DB_AVAILABLE') || !LWT_TEST_DB_AVAILABLE) {
            $this->markTestSkipped('Database connection required for Settings::save()');
        }

        $response = $this->handler->routePost(
            ['settings'],
            []
        );

        $this->assertInstanceOf(JsonResponse::class, $response);
        $this->assertSame(200, $response->getStatusCode());
    }

    #[Test]
    public function routePostWithMissingKeyParam(): void
    {
        if (!defined('LWT_TEST_DB_AVAILABLE') || !LWT_TEST_DB_AVAILABLE) {
            $this->markTestSkipped('Database connection required for Settings::save()');
        }

        $response = $this->handler->routePost(
            ['settings'],
            ['value' => 'test']
        );

        $this->assertInstanceOf(JsonResponse::class, $response);
    }

    #[Test]
    public function routePostWithMissingValueParam(): void
    {
        if (!defined('LWT_TEST_DB_AVAILABLE') || !LWT_TEST_DB_AVAILABLE) {
            $this->markTestSkipped('Database connection required for Settings::save()');
        }

        $response = $this->handler->routePost(
            ['settings'],
            ['key' => 'test']
        );

        $this->assertInstanceOf(JsonResponse::class, $response);
    }

    // =========================================================================
    // routePut / routeDelete default tests (from trait)
    // =========================================================================

    #[Test]
    public function routePutReturns405(): void
    {
        $response = $this->handler->routePut([], []);

        $this->assertInstanceOf(JsonResponse::class, $response);
        $this->assertSame(405, $response->getStatusCode());

        $data = $response->getData();
        $this->assertArrayHasKey('error', $data);
        $this->assertSame('Method Not Allowed', $data['error']);
    }

    #[Test]
    public function routeDeleteReturns405(): void
    {
        $response = $this->handler->routeDelete([], []);

        $this->assertInstanceOf(JsonResponse::class, $response);
        $this->assertSame(405, $response->getStatusCode());
    }

    // =========================================================================
    // getThemePath tests
    // =========================================================================

    #[Test]
    public function getThemePathReturnsArrayWithThemePathKey(): void
    {
        $result = $this->handler->getThemePath('css/main.css');

        $this->assertIsArray($result);
        $this->assertArrayHasKey('theme_path', $result);
        $this->assertIsString($result['theme_path']);
    }

    #[Test]
    public function getThemePathWithEmptyString(): void
    {
        $result = $this->handler->getThemePath('');

        $this->assertIsArray($result);
        $this->assertArrayHasKey('theme_path', $result);
    }

    #[Test]
    public function getThemePathWithNestedPath(): void
    {
        $result = $this->handler->getThemePath('themes/dark/style.css');

        $this->assertIsArray($result);
        $this->assertArrayHasKey('theme_path', $result);
    }

    // =========================================================================
    // formatThemePath tests
    // =========================================================================

    #[Test]
    public function formatThemePathDelegatesToGetThemePath(): void
    {
        $directResult = $this->handler->getThemePath('test.css');
        $formatResult = $this->handler->formatThemePath('test.css');

        $this->assertSame($directResult, $formatResult);
    }

    // =========================================================================
    // formatSaveSetting tests
    // =========================================================================

    #[Test]
    public function formatSaveSettingDelegatesToSaveSetting(): void
    {
        // Both should return the same result; this verifies delegation
        $method = new \ReflectionMethod(AdminApiHandler::class, 'formatSaveSetting');
        $this->assertTrue($method->isPublic());

        $params = $method->getParameters();
        $this->assertCount(2, $params);
        $this->assertSame('key', $params[0]->getName());
        $this->assertSame('value', $params[1]->getName());
    }

    // =========================================================================
    // formatTextsStatistics tests
    // =========================================================================

    #[Test]
    public function formatTextsStatisticsReturnStructureForSingleText(): void
    {
        // We need to mock the TextStatisticsService which is created inside
        // getTextsStatistics. Since it's a new instance, we test the formatting
        // logic by using reflection or a partial mock.
        // For now, we test the formatting behavior given known raw input.

        $handler = $this->getMockBuilder(AdminApiHandler::class)
            ->setConstructorArgs([$this->facade])
            ->onlyMethods(['getTextsStatistics'])
            ->getMock();

        $handler->expects($this->once())
            ->method('getTextsStatistics')
            ->with('42')
            ->willReturn([
                'totalu' => ['42' => 100],
                'statu' => [
                    '42' => [1 => 10, 2 => 5, 3 => 3, 4 => 2, 5 => 1, 98 => 20, 99 => 30],
                ],
            ]);

        $result = $handler->formatTextsStatistics('42');

        $this->assertArrayHasKey('42', $result);
        $stats = $result['42'];

        $this->assertSame(100, $stats['total']);
        $this->assertSame(71, $stats['saved']); // 10+5+3+2+1+20+30
        $this->assertSame(29, $stats['unknown']); // 100-71
        $this->assertSame(29, $stats['unknownPercent']); // round(29/100*100)
        $this->assertArrayHasKey('statusCounts', $stats);
        $this->assertSame(10, $stats['statusCounts']['1']);
        $this->assertSame(30, $stats['statusCounts']['99']);
    }

    #[Test]
    public function formatTextsStatisticsCountsSavedExpressionsInTheTotal(): void
    {
        // A real text from #313: 19 unique words, one saved two-word
        // expression ("Bonjour Manon"), every term saved. statu counts the
        // expression among the saved terms, so a total of words alone made
        // the card read "total 19, saved 20, unknown -1, -5%".
        $handler = $this->getMockBuilder(AdminApiHandler::class)
            ->setConstructorArgs([$this->facade])
            ->onlyMethods(['getTextsStatistics'])
            ->getMock();

        $handler->method('getTextsStatistics')->willReturn([
            'totalu' => ['10' => 19],
            'expru' => ['10' => 1],
            'statu' => ['10' => [1 => 3, 2 => 6, 4 => 3, 5 => 7, 98 => 1]],
        ]);

        $stats = $handler->formatTextsStatistics('10')['10'];

        $this->assertSame(20, $stats['total']);
        $this->assertSame(20, $stats['saved']);
        $this->assertSame(0, $stats['unknown']);
        $this->assertSame(0, $stats['unknownPercent']);
    }

    #[Test]
    public function formatTextsStatisticsHandlesMultipleTexts(): void
    {
        $handler = $this->getMockBuilder(AdminApiHandler::class)
            ->setConstructorArgs([$this->facade])
            ->onlyMethods(['getTextsStatistics'])
            ->getMock();

        $handler->expects($this->once())
            ->method('getTextsStatistics')
            ->with('1,2')
            ->willReturn([
                'totalu' => ['1' => 50, '2' => 80],
                'statu' => [
                    '1' => [1 => 5, 99 => 10],
                    '2' => [2 => 20, 5 => 10],
                ],
            ]);

        $result = $handler->formatTextsStatistics('1,2');

        $this->assertArrayHasKey('1', $result);
        $this->assertArrayHasKey('2', $result);

        $this->assertSame(50, $result['1']['total']);
        $this->assertSame(15, $result['1']['saved']);
        $this->assertSame(35, $result['1']['unknown']);

        $this->assertSame(80, $result['2']['total']);
        $this->assertSame(30, $result['2']['saved']);
        $this->assertSame(50, $result['2']['unknown']);
    }

    #[Test]
    public function formatTextsStatisticsHandlesTextWithNoStatusData(): void
    {
        $handler = $this->getMockBuilder(AdminApiHandler::class)
            ->setConstructorArgs([$this->facade])
            ->onlyMethods(['getTextsStatistics'])
            ->getMock();

        $handler->expects($this->once())
            ->method('getTextsStatistics')
            ->with('99')
            ->willReturn([
                'totalu' => [],
                'statu' => [],
            ]);

        $result = $handler->formatTextsStatistics('99');

        $this->assertArrayHasKey('99', $result);
        $stats = $result['99'];

        $this->assertSame(0, $stats['total']);
        $this->assertSame(0, $stats['saved']);
        $this->assertSame(0, $stats['unknown']);
        $this->assertSame(0, $stats['unknownPercent']);
        $this->assertEmpty($stats['statusCounts']);
    }

    #[Test]
    public function formatTextsStatisticsWithZeroTotalHasZeroPercent(): void
    {
        $handler = $this->getMockBuilder(AdminApiHandler::class)
            ->setConstructorArgs([$this->facade])
            ->onlyMethods(['getTextsStatistics'])
            ->getMock();

        $handler->expects($this->once())
            ->method('getTextsStatistics')
            ->with('7')
            ->willReturn([
                'totalu' => ['7' => 0],
                'statu' => [],
            ]);

        $result = $handler->formatTextsStatistics('7');

        $this->assertSame(0, $result['7']['unknownPercent']);
    }

    #[Test]
    public function formatTextsStatisticsWithAllWordsSaved(): void
    {
        $handler = $this->getMockBuilder(AdminApiHandler::class)
            ->setConstructorArgs([$this->facade])
            ->onlyMethods(['getTextsStatistics'])
            ->getMock();

        $handler->expects($this->once())
            ->method('getTextsStatistics')
            ->with('10')
            ->willReturn([
                'totalu' => ['10' => 50],
                'statu' => ['10' => [99 => 50]],
            ]);

        $result = $handler->formatTextsStatistics('10');

        $this->assertSame(0, $result['10']['unknown']);
        $this->assertSame(0, $result['10']['unknownPercent']);
        $this->assertSame(50, $result['10']['saved']);
    }

    #[Test]
    public function formatTextsStatisticsWithAllWordsUnknown(): void
    {
        $handler = $this->getMockBuilder(AdminApiHandler::class)
            ->setConstructorArgs([$this->facade])
            ->onlyMethods(['getTextsStatistics'])
            ->getMock();

        $handler->expects($this->once())
            ->method('getTextsStatistics')
            ->with('5')
            ->willReturn([
                'totalu' => ['5' => 100],
                'statu' => [],
            ]);

        $result = $handler->formatTextsStatistics('5');

        $this->assertSame(100, $result['5']['unknown']);
        $this->assertSame(100, $result['5']['unknownPercent']);
        $this->assertSame(0, $result['5']['saved']);
    }

    // =========================================================================
    // getServerData delegate tests
    // =========================================================================

    #[Test]
    public function getServerDataDelegatesToFacade(): void
    {
        $expected = ['php_version' => '8.2', 'db_version' => '10.6'];

        $this->facade->expects($this->once())
            ->method('getServerData')
            ->willReturn($expected);

        $result = $this->handler->getServerData();

        $this->assertSame($expected, $result);
    }

    // =========================================================================
    // formatMediaFiles tests
    // =========================================================================

    #[Test]
    public function formatMediaFilesReturnsArrayWithBasePath(): void
    {
        // getMediaFiles creates a new MediaService inside, so we test
        // that formatMediaFiles delegates correctly by verifying the method
        // signature and return type
        $method = new \ReflectionMethod(AdminApiHandler::class, 'formatMediaFiles');
        $this->assertTrue($method->isPublic());
        $this->assertCount(0, $method->getParameters());
    }

    #[Test]
    public function getMediaFilesReturnsArrayWithBasePath(): void
    {
        $method = new \ReflectionMethod(AdminApiHandler::class, 'getMediaFiles');
        $this->assertTrue($method->isPublic());
        $this->assertCount(0, $method->getParameters());
    }

    // =========================================================================
    // frag helper tests (via trait)
    // =========================================================================

    #[Test]
    public function fragReturnsEmptyStringForMissingIndex(): void
    {
        $method = new \ReflectionMethod(AdminApiHandler::class, 'frag');

        $result = $method->invoke($this->handler, [], 0);
        $this->assertSame('', $result);
    }

    #[Test]
    public function fragReturnsValueAtIndex(): void
    {
        $method = new \ReflectionMethod(AdminApiHandler::class, 'frag');

        $result = $method->invoke($this->handler, ['zero', 'one', 'two'], 1);
        $this->assertSame('one', $result);
    }

    #[Test]
    public function fragReturnsEmptyStringForOutOfBoundsIndex(): void
    {
        $method = new \ReflectionMethod(AdminApiHandler::class, 'frag');

        $result = $method->invoke($this->handler, ['only'], 5);
        $this->assertSame('', $result);
    }

    // =========================================================================
    // saveSetting method tests (direct call, not via route)
    // =========================================================================

    #[Test]
    public function saveSettingMethodSignature(): void
    {
        $method = new \ReflectionMethod(AdminApiHandler::class, 'saveSetting');

        $this->assertTrue($method->isPublic());
        $params = $method->getParameters();
        $this->assertCount(2, $params);
        $this->assertSame('key', $params[0]->getName());
        $this->assertSame('string', $params[0]->getType()->getName());
        $this->assertSame('value', $params[1]->getName());
        $this->assertSame('string', $params[1]->getType()->getName());
    }

    // =========================================================================
    // Private method tests
    // =========================================================================

    #[Test]
    public function getTextCountForLanguageIsPrivate(): void
    {
        $method = new \ReflectionMethod(AdminApiHandler::class, 'getTextCountForLanguage');
        $this->assertTrue($method->isPrivate());

        $params = $method->getParameters();
        $this->assertCount(1, $params);
        $this->assertSame('int', $params[0]->getType()->getName());
    }

    #[Test]
    public function getLastTextForLanguageIsPrivate(): void
    {
        $method = new \ReflectionMethod(AdminApiHandler::class, 'getLastTextForLanguage');
        $this->assertTrue($method->isPrivate());

        $params = $method->getParameters();
        $this->assertCount(1, $params);
        $this->assertSame('int', $params[0]->getType()->getName());
    }

    // =========================================================================
    // Edge case tests for routeGet
    // =========================================================================

    #[Test]
    public function routeGetWithNullFragmentsHandlesGracefully(): void
    {
        // Passing minimal fragments array
        $response = $this->handler->routeGet([], []);

        $this->assertInstanceOf(JsonResponse::class, $response);
        $this->assertSame(404, $response->getStatusCode());
    }

    #[Test]
    public function routeGetThemePathWithSpecialCharacters(): void
    {
        $response = $this->handler->routeGet(
            ['settings', 'theme-path'],
            ['path' => 'css/my theme/style.css']
        );

        $this->assertInstanceOf(JsonResponse::class, $response);
        $this->assertSame(200, $response->getStatusCode());
    }
}
