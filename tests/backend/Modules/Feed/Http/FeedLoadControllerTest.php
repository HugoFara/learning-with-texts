<?php

declare(strict_types=1);

namespace Lwt\Tests\Modules\Feed\Http;

use Lwt\Modules\Feed\Application\FeedFacade;
use Lwt\Modules\Feed\Http\FeedLoadController;
use Lwt\Modules\Language\Application\LanguageFacade;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for FeedLoadController.
 *
 * Tests feed loading operations including single feed loading,
 * multi-load interface, and the renderFeedLoadInterface method.
 */
class FeedLoadControllerTest extends TestCase
{
    /** @var FeedFacade&MockObject */
    private FeedFacade $feedFacade;

    /** @var LanguageFacade&MockObject */
    private LanguageFacade $languageFacade;

    private FeedLoadController $controller;

    protected function setUp(): void
    {
        $this->feedFacade = $this->createMock(FeedFacade::class);
        $this->languageFacade = $this->createMock(LanguageFacade::class);

        $this->controller = new FeedLoadController(
            $this->feedFacade,
            $this->languageFacade
        );
    }

    protected function tearDown(): void
    {
        $_REQUEST = [];
        $_GET = [];
        $_POST = [];
    }

    // =========================================================================
    // Constructor tests
    // =========================================================================

    #[Test]
    public function constructorCreatesValidController(): void
    {
        $this->assertInstanceOf(FeedLoadController::class, $this->controller);
    }

    #[Test]
    public function constructorSetsFeedFacadeProperty(): void
    {
        $reflection = new \ReflectionProperty(FeedLoadController::class, 'feedFacade');

        $this->assertSame($this->feedFacade, $reflection->getValue($this->controller));
    }

    #[Test]
    public function constructorSetsLanguageFacadeProperty(): void
    {
        $reflection = new \ReflectionProperty(FeedLoadController::class, 'languageFacade');

        $this->assertSame($this->languageFacade, $reflection->getValue($this->controller));
    }

    #[Test]
    public function constructorSetsViewPathProperty(): void
    {
        $reflection = new \ReflectionProperty(FeedLoadController::class, 'viewPath');

        $viewPath = $reflection->getValue($this->controller);
        $this->assertStringEndsWith('/Views/', $viewPath);
    }

    // =========================================================================
    // Class structure tests
    // =========================================================================

    #[Test]
    public function classHasRequiredPublicMethods(): void
    {
        $reflection = new \ReflectionClass(FeedLoadController::class);

        $expectedMethods = ['renderFeedLoadInterface', 'loadFeedRoute'];

        foreach ($expectedMethods as $methodName) {
            $this->assertTrue(
                $reflection->hasMethod($methodName),
                "FeedLoadController should have method: $methodName"
            );
            $method = $reflection->getMethod($methodName);
            $this->assertTrue(
                $method->isPublic(),
                "Method $methodName should be public"
            );
        }
    }


    #[Test]
    public function loadFeedRouteMethodAcceptsIntParam(): void
    {
        $method = new \ReflectionMethod(FeedLoadController::class, 'loadFeedRoute');
        $params = $method->getParameters();
        $this->assertCount(1, $params);
        $this->assertSame('id', $params[0]->getName());
    }


    #[Test]
    public function renderFeedLoadInterfaceAcceptsThreeParams(): void
    {
        $method = new \ReflectionMethod(FeedLoadController::class, 'renderFeedLoadInterface');
        $params = $method->getParameters();
        $this->assertCount(3, $params);
        $this->assertSame('currentFeed', $params[0]->getName());
        $this->assertSame('checkAutoupdate', $params[1]->getName());
        $this->assertSame('redirectUrl', $params[2]->getName());
    }

    // =========================================================================
    // renderFeedLoadInterface tests
    // =========================================================================

    #[Test]
    public function renderFeedLoadInterfaceOutputsJsonConfig(): void
    {
        $config = [
            'feeds' => [
                ['id' => 1, 'name' => 'Feed 1', 'sourceUri' => 'http://example.com/1'],
                ['id' => 2, 'name' => 'Feed 2', 'sourceUri' => 'http://example.com/2'],
            ],
            'count' => 2,
        ];

        $this->feedFacade->expects($this->once())
            ->method('getFeedLoadConfig')
            ->with(1, false)
            ->willReturn($config);

        ob_start();
        $this->controller->renderFeedLoadInterface(1, false, '/feeds/edit');
        $output = ob_get_clean();

        $this->assertStringContainsString('feed-loader-config', $output);
        $this->assertStringContainsString('feeds', $output);
        $this->assertStringContainsString('redirectUrl', $output);
        $this->assertStringContainsString('feedLoader()', $output);
    }

    #[Test]
    public function renderFeedLoadInterfaceShowsCountForMultipleFeeds(): void
    {
        $config = [
            'feeds' => [
                ['id' => 1, 'name' => 'Feed 1'],
                ['id' => 2, 'name' => 'Feed 2'],
                ['id' => 3, 'name' => 'Feed 3'],
            ],
            'count' => 3,
        ];

        $this->feedFacade->method('getFeedLoadConfig')->willReturn($config);

        ob_start();
        $this->controller->renderFeedLoadInterface(0, true, '/feeds');
        $output = ob_get_clean();

        // Asserted on the markup, not the copy: the label goes through the
        // translator now, so it is English only by default (#266).
        $this->assertStringContainsString('notification is-info', $output);
        $this->assertStringContainsString('x-text="loadedCount"', $output);
        $this->assertStringContainsString('3', $output);
    }

    #[Test]
    public function renderFeedLoadInterfaceHidesCountForSingleFeed(): void
    {
        $config = [
            'feeds' => [['id' => 1, 'name' => 'Feed 1']],
            'count' => 1,
        ];

        $this->feedFacade->method('getFeedLoadConfig')->willReturn($config);

        ob_start();
        $this->controller->renderFeedLoadInterface(1, false, '/feeds');
        $output = ob_get_clean();

        $this->assertStringNotContainsString('notification is-info', $output);
    }

    #[Test]
    public function renderFeedLoadInterfaceOutputsAlpineComponents(): void
    {
        $config = [
            'feeds' => [],
            'count' => 0,
        ];

        $this->feedFacade->method('getFeedLoadConfig')->willReturn($config);

        ob_start();
        $this->controller->renderFeedLoadInterface(1, false, '/feeds');
        $output = ob_get_clean();

        $this->assertStringContainsString('x-data="feedLoader()"', $output);
        $this->assertStringContainsString('x-for="feed in feeds"', $output);
        $this->assertStringContainsString('handleContinue()', $output);
    }

    #[Test]
    public function renderFeedLoadInterfaceEncodesJsonSafely(): void
    {
        $config = [
            'feeds' => [['id' => 1, 'name' => "Feed with <script>'quotes'</script>"]],
            'count' => 1,
        ];

        $this->feedFacade->method('getFeedLoadConfig')->willReturn($config);

        ob_start();
        $this->controller->renderFeedLoadInterface(1, false, '/feeds');
        $output = ob_get_clean();

        // JSON_HEX_TAG should escape < and >
        $this->assertStringNotContainsString('<script>', $output);
    }

    #[Test]
    public function renderFeedLoadInterfacePassesCheckAutoupdateToFacade(): void
    {
        $this->feedFacade->expects($this->once())
            ->method('getFeedLoadConfig')
            ->with(5, true)
            ->willReturn(['feeds' => [], 'count' => 0]);

        ob_start();
        $this->controller->renderFeedLoadInterface(5, true, '/feeds/manage');
        ob_end_clean();
    }

    // =========================================================================
    // loadFeedRoute tests
    // =========================================================================

    #[Test]
    public function loadFeedRouteRendersThroughTheController(): void
    {
        if (!defined('LWT_TEST_DB_AVAILABLE') || !LWT_TEST_DB_AVAILABLE) {
            $this->markTestSkipped('Database connection required');
        }

        $_REQUEST = ['filterlang' => '1'];

        $this->languageFacade->method('getLanguageName')
            ->willReturn('English');

        $this->feedFacade->expects($this->once())
            ->method('getFeedLoadConfig')
            ->with(42, false)
            ->willReturn(['feeds' => [], 'count' => 1]);

        ob_start();
        try {
            $this->controller->loadFeedRoute(42);
        } catch (\Throwable $e) {
            // PageLayoutHelper static calls may fail
        }
        ob_end_clean();
    }

    // =========================================================================
    // multiLoad tests
    // =========================================================================


    // =========================================================================
    // showMultiLoadForm tests
    // =========================================================================


    // =========================================================================
    // Return type tests
    // =========================================================================

    #[Test]
    public function loadFeedRouteReturnsVoid(): void
    {
        $method = new \ReflectionMethod(FeedLoadController::class, 'loadFeedRoute');
        $returnType = $method->getReturnType();
        $this->assertNotNull($returnType);
        $this->assertSame('void', $returnType->getName());
    }

    #[Test]
    public function renderFeedLoadInterfaceReturnsVoid(): void
    {
        $method = new \ReflectionMethod(FeedLoadController::class, 'renderFeedLoadInterface');
        $returnType = $method->getReturnType();
        $this->assertNotNull($returnType);
        $this->assertSame('void', $returnType->getName());
    }
}
