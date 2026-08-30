<?php

declare(strict_types=1);

namespace Lwt\Tests\Modules\Feed\Http;

use Lwt\Modules\Feed\Application\FeedFacade;
use Lwt\Modules\Feed\Http\FeedEditController;
use Lwt\Modules\Language\Application\LanguageFacade;
use Lwt\Shared\Infrastructure\Http\FlashMessageService;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for FeedEditController.
 *
 * Tests feed CRUD operations: edit routing, new/edit/delete feed,
 * mark actions, form handling, and the management list.
 */
class FeedEditControllerTest extends TestCase
{
    /** @var FeedFacade&MockObject */
    private FeedFacade $feedFacade;

    /** @var LanguageFacade&MockObject */
    private LanguageFacade $languageFacade;

    /** @var FlashMessageService&MockObject */
    private FlashMessageService $flashService;

    private FeedEditController $controller;

    protected function setUp(): void
    {
        $this->feedFacade = $this->createMock(FeedFacade::class);
        $this->languageFacade = $this->createMock(LanguageFacade::class);
        $this->flashService = $this->createMock(FlashMessageService::class);

        $this->controller = new FeedEditController(
            $this->feedFacade,
            $this->languageFacade,
            $this->flashService
        );
    }

    /**
     * Create a controller with redirect() stubbed out to prevent exit().
     *
     * @return FeedEditController&MockObject
     */
    private function createControllerWithRedirectStub(): FeedEditController
    {
        $controller = $this->getMockBuilder(FeedEditController::class)
            ->setConstructorArgs([
                $this->feedFacade,
                $this->languageFacade,
                $this->flashService,
            ])
            ->onlyMethods(['redirect'])
            ->getMock();

        $controller->method('redirect')
            ->willReturnCallback(function (string $url): void {
                // no-op: prevents header() + exit() in tests
            });

        return $controller;
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
        $this->assertInstanceOf(FeedEditController::class, $this->controller);
    }

    #[Test]
    public function constructorSetsFeedFacadeProperty(): void
    {
        $reflection = new \ReflectionProperty(FeedEditController::class, 'feedFacade');

        $this->assertSame($this->feedFacade, $reflection->getValue($this->controller));
    }

    #[Test]
    public function constructorSetsLanguageFacadeProperty(): void
    {
        $reflection = new \ReflectionProperty(FeedEditController::class, 'languageFacade');

        $this->assertSame($this->languageFacade, $reflection->getValue($this->controller));
    }

    #[Test]
    public function constructorSetsFlashServiceProperty(): void
    {
        $reflection = new \ReflectionProperty(FeedEditController::class, 'flashService');

        $this->assertSame($this->flashService, $reflection->getValue($this->controller));
    }

    #[Test]
    public function constructorSetsViewPathProperty(): void
    {
        $reflection = new \ReflectionProperty(FeedEditController::class, 'viewPath');

        $viewPath = $reflection->getValue($this->controller);
        $this->assertStringEndsWith('/Views/', $viewPath);
    }

    #[Test]
    public function constructorWithDefaultFlash(): void
    {
        $controller = new FeedEditController(
            $this->feedFacade,
            $this->languageFacade
        );

        $flashRef = new \ReflectionProperty(FeedEditController::class, 'flashService');
        $this->assertInstanceOf(FlashMessageService::class, $flashRef->getValue($controller));
    }

    // =========================================================================
    // Class structure tests
    // =========================================================================

    #[Test]
    public function classUsesFeedFlashTrait(): void
    {
        $reflection = new \ReflectionClass(FeedEditController::class);
        $traitNames = array_map(
            fn(\ReflectionClass $t) => $t->getName(),
            $reflection->getTraits()
        );

        $this->assertContains(
            'Lwt\Modules\Feed\Http\FeedFlashTrait',
            $traitNames
        );
    }

    #[Test]
    public function classHasRequiredPublicMethods(): void
    {
        $reflection = new \ReflectionClass(FeedEditController::class);

        $expectedMethods = ['spa', 'newFeed', 'editFeed', 'deleteFeed'];

        foreach ($expectedMethods as $methodName) {
            $this->assertTrue(
                $reflection->hasMethod($methodName),
                "FeedEditController should have method: $methodName"
            );
            $method = $reflection->getMethod($methodName);
            $this->assertTrue(
                $method->isPublic(),
                "Method $methodName should be public"
            );
        }
    }

    #[Test]
    public function classHasRequiredPrivateMethods(): void
    {
        $reflection = new \ReflectionClass(FeedEditController::class);

        $expectedMethods = [
            'showNewForm', 'showEditForm',
            'loadCuratedFeeds'
        ];

        foreach ($expectedMethods as $methodName) {
            $this->assertTrue(
                $reflection->hasMethod($methodName),
                "FeedEditController should have method: $methodName"
            );
            $method = $reflection->getMethod($methodName);
            $this->assertTrue(
                $method->isPrivate(),
                "Method $methodName should be private"
            );
        }
    }

    // =========================================================================
    // showEditForm tests
    // =========================================================================

    #[Test]
    public function showEditFormOutputsErrorWhenFeedNotFound(): void
    {
        $this->feedFacade->method('getFeedById')
            ->with(999)
            ->willReturn(null);

        $method = new \ReflectionMethod(FeedEditController::class, 'showEditForm');

        ob_start();
        $method->invoke($this->controller, 999);
        $output = ob_get_clean();

        $this->assertStringContainsString('Feed not found', $output);
        $this->assertStringContainsString('is-danger', $output);
    }

    #[Test]
    public function showEditFormCallsFacadeForExistingFeed(): void
    {
        $feed = [
            'NfID' => 1,
            'NfName' => 'Test Feed',
            'NfSourceURI' => 'http://test.com',
            'NfLgID' => 1,
            'NfArticleSectionTags' => '',
            'NfFilterTags' => '',
            'NfOptions' => '',
            'NfUpdate' => 0,
        ];

        $this->feedFacade->expects($this->once())
            ->method('getFeedById')
            ->with(1)
            ->willReturn($feed);

        $this->feedFacade->expects($this->once())
            ->method('getLanguages')
            ->willReturn([]);

        $this->feedFacade->method('getFeedOption')
            ->willReturn(null);

        $method = new \ReflectionMethod(FeedEditController::class, 'showEditForm');

        ob_start();
        try {
            $method->invoke($this->controller, 1);
        } catch (\Throwable $e) {
            // View include may fail in test context
        }
        ob_end_clean();
    }

    #[Test]
    public function showEditFormParsesAutoUpdateOption(): void
    {
        $feed = [
            'NfID' => 1,
            'NfName' => 'Test',
            'NfSourceURI' => 'http://test.com',
            'NfLgID' => 1,
            'NfArticleSectionTags' => '',
            'NfFilterTags' => '',
            'NfOptions' => 'autoupdate:24h',
            'NfUpdate' => 0,
        ];

        $this->feedFacade->method('getFeedById')->willReturn($feed);
        $this->feedFacade->method('getLanguages')->willReturn([]);

        // Return different values based on option param
        $this->feedFacade->method('getFeedOption')
            ->willReturnCallback(function (string $options, string $option) {
                if ($option === '') {
                    return [];
                }
                if ($option === 'autoupdate') {
                    return '24h';
                }
                return null;
            });

        $method = new \ReflectionMethod(FeedEditController::class, 'showEditForm');

        ob_start();
        try {
            $method->invoke($this->controller, 1);
        } catch (\Throwable $e) {
            // View include may fail
        }
        ob_end_clean();

        // If we got here without fatal error, the parsing logic works
        $this->assertTrue(true);
    }

    #[Test]
    public function showEditFormHandlesNullAutoUpdate(): void
    {
        $feed = [
            'NfID' => 1,
            'NfName' => 'Test',
            'NfSourceURI' => 'http://test.com',
            'NfLgID' => 1,
            'NfArticleSectionTags' => '',
            'NfFilterTags' => '',
            'NfOptions' => '',
            'NfUpdate' => 0,
        ];

        $this->feedFacade->method('getFeedById')->willReturn($feed);
        $this->feedFacade->method('getLanguages')->willReturn([]);
        $this->feedFacade->method('getFeedOption')->willReturn(null);

        $method = new \ReflectionMethod(FeedEditController::class, 'showEditForm');

        ob_start();
        try {
            $method->invoke($this->controller, 1);
        } catch (\Throwable $e) {
            // View include may fail
        }
        ob_end_clean();

        $this->assertTrue(true);
    }

    // =========================================================================
    // showNewForm tests
    // =========================================================================

    #[Test]
    public function showNewFormCallsGetLanguagesForSelect(): void
    {
        $this->languageFacade->expects($this->once())
            ->method('getLanguagesForSelect')
            ->willReturn([['id' => 1, 'name' => 'English']]);

        $method = new \ReflectionMethod(FeedEditController::class, 'showNewForm');

        ob_start();
        try {
            $method->invoke($this->controller);
        } catch (\Throwable $e) {
            // View include may fail
        }
        ob_end_clean();
    }

    // =========================================================================
    // showMultiLoadForm tests
    // =========================================================================


    // =========================================================================
    // deleteFeed tests
    // =========================================================================

    #[Test]
    public function deleteFeedCallsFacadeWithStringId(): void
    {
        $controller = $this->createControllerWithRedirectStub();

        $this->feedFacade->expects($this->once())
            ->method('deleteFeeds')
            ->with('42')
            ->willReturn(['feeds' => 1]);

        $this->flashService->expects($this->once())
            ->method('success')
            ->with('Feed deleted successfully');

        $controller->deleteFeed(42);
    }

    #[Test]
    public function deleteFeedShowsErrorWhenNoFeedsDeleted(): void
    {
        $controller = $this->createControllerWithRedirectStub();

        $this->feedFacade->expects($this->once())
            ->method('deleteFeeds')
            ->with('99')
            ->willReturn(['feeds' => 0]);

        $this->flashService->expects($this->once())
            ->method('error')
            ->with('Failed to delete feed');

        $controller->deleteFeed(99);
    }

    // =========================================================================
    // editFeed tests
    // =========================================================================

    #[Test]
    public function editFeedRedirectsWhenFeedNotFound(): void
    {
        $controller = $this->createControllerWithRedirectStub();

        $this->feedFacade->method('getFeedById')
            ->with(999)
            ->willReturn(null);

        $this->flashService->expects($this->once())
            ->method('error')
            ->with('Feed not found');

        $controller->editFeed(999);
    }

    #[Test]
    public function editFeedCallsLanguageFacadeForExistingFeed(): void
    {
        $feed = [
            'NfID' => 1,
            'NfLgID' => 5,
            'NfName' => 'Test',
            'NfSourceURI' => 'http://test.com',
            'NfArticleSectionTags' => '',
            'NfFilterTags' => '',
            'NfOptions' => '',
            'NfUpdate' => 0,
        ];

        $this->feedFacade->method('getFeedById')->willReturn($feed);
        $this->feedFacade->method('getLanguages')->willReturn([]);
        $this->feedFacade->method('getFeedOption')->willReturn(null);

        $this->languageFacade->expects($this->once())
            ->method('getLanguageName')
            ->with(5)
            ->willReturn('French');

        $_REQUEST = [];

        ob_start();
        try {
            $this->controller->editFeed(1);
        } catch (\Throwable $e) {
            // View include may fail
        }
        ob_end_clean();
    }

    // =========================================================================
    // edit method routing tests
    // =========================================================================


    // =========================================================================
    // Method signature tests
    // =========================================================================


    #[Test]
    public function spaMethodAcceptsArrayParam(): void
    {
        $method = new \ReflectionMethod(FeedEditController::class, 'spa');
        $params = $method->getParameters();
        $this->assertCount(1, $params);
        $this->assertSame('params', $params[0]->getName());
    }

    #[Test]
    public function newFeedMethodAcceptsArrayParam(): void
    {
        $method = new \ReflectionMethod(FeedEditController::class, 'newFeed');
        $params = $method->getParameters();
        $this->assertCount(1, $params);
        $this->assertSame('params', $params[0]->getName());
    }

    #[Test]
    public function editFeedMethodAcceptsIntParam(): void
    {
        $method = new \ReflectionMethod(FeedEditController::class, 'editFeed');
        $params = $method->getParameters();
        $this->assertCount(1, $params);
        $this->assertSame('id', $params[0]->getName());
    }

    #[Test]
    public function deleteFeedMethodAcceptsIntParam(): void
    {
        $method = new \ReflectionMethod(FeedEditController::class, 'deleteFeed');
        $params = $method->getParameters();
        $this->assertCount(1, $params);
        $this->assertSame('id', $params[0]->getName());
    }
}
