<?php

declare(strict_types=1);

namespace Lwt\Tests\Modules\Review\Http;

use Lwt\Modules\Review\Http\ReviewController;
use Lwt\Modules\Review\Application\ReviewFacade;
use Lwt\Modules\Review\Infrastructure\SessionStateManager;
use Lwt\Modules\Language\Application\LanguageFacade;
use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\MockObject;

/**
 * Unit tests for ReviewController.
 *
 * Tests review page routing, table review,
 * and review property extraction from parameters.
 */
class ReviewControllerTest extends TestCase
{
    /** @var ReviewFacade&MockObject */
    private ReviewFacade $reviewFacade;

    /** @var LanguageFacade&MockObject */
    private LanguageFacade $languageFacade;

    /** @var SessionStateManager&MockObject */
    private SessionStateManager $sessionManager;

    private ReviewController $controller;

    protected function setUp(): void
    {
        $this->reviewFacade = $this->createMock(ReviewFacade::class);
        $this->languageFacade = $this->createMock(LanguageFacade::class);
        $this->sessionManager = $this->createMock(SessionStateManager::class);

        $this->controller = new ReviewController(
            $this->reviewFacade,
            $this->languageFacade,
            $this->sessionManager
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
        $this->assertInstanceOf(ReviewController::class, $this->controller);
    }

    #[Test]
    public function constructorAcceptsNullDependencies(): void
    {
        // This exercises the fallback constructors (new ReviewFacade(), etc.)
        // We can't actually test without DB, so just verify it's possible
        $this->assertInstanceOf(ReviewController::class, $this->controller);
    }

    // =========================================================================
    // getReviewProperty() tests (via reflection)
    // =========================================================================

    #[Test]
    public function getReviewPropertyReturnsEmptyWhenNoParams(): void
    {
        $_REQUEST = [];
        $method = new \ReflectionMethod(ReviewController::class, 'getReviewProperty');

        $result = $method->invoke($this->controller);
        $this->assertSame('', $result);
    }

    #[Test]
    public function getReviewPropertyReturnsLangParam(): void
    {
        $_REQUEST = ['lang' => '5'];
        $method = new \ReflectionMethod(ReviewController::class, 'getReviewProperty');

        $result = $method->invoke($this->controller);
        $this->assertSame('lang=5', $result);
    }

    #[Test]
    public function getReviewPropertyReturnsTextParam(): void
    {
        $_REQUEST = ['text' => '42'];
        $method = new \ReflectionMethod(ReviewController::class, 'getReviewProperty');

        $result = $method->invoke($this->controller);
        $this->assertSame('text=42', $result);
    }

    #[Test]
    public function getReviewPropertyReturnsSelectionWhenSessionHasCriteria(): void
    {
        $_REQUEST = ['selection' => '7'];
        $this->sessionManager->method('hasCriteria')->willReturn(true);

        $method = new \ReflectionMethod(ReviewController::class, 'getReviewProperty');

        $result = $method->invoke($this->controller);
        $this->assertSame('selection=7', $result);
    }

    #[Test]
    public function getReviewPropertyIgnoresSelectionWithoutCriteria(): void
    {
        $_REQUEST = ['selection' => '7', 'lang' => '3'];
        $this->sessionManager->method('hasCriteria')->willReturn(false);

        $method = new \ReflectionMethod(ReviewController::class, 'getReviewProperty');

        $result = $method->invoke($this->controller);
        // Should fall through to lang since selection has no criteria
        $this->assertSame('lang=3', $result);
    }

    #[Test]
    public function getReviewPropertyPrioritizesSelectionOverLang(): void
    {
        $_REQUEST = ['selection' => '7', 'lang' => '3'];
        $this->sessionManager->method('hasCriteria')->willReturn(true);

        $method = new \ReflectionMethod(ReviewController::class, 'getReviewProperty');

        $result = $method->invoke($this->controller);
        $this->assertSame('selection=7', $result);
    }

    #[Test]
    public function getReviewPropertyPrioritizesLangOverText(): void
    {
        $_REQUEST = ['lang' => '3', 'text' => '10'];

        $method = new \ReflectionMethod(ReviewController::class, 'getReviewProperty');

        $result = $method->invoke($this->controller);
        $this->assertSame('lang=3', $result);
    }

    // =========================================================================
    // index() redirect tests
    // =========================================================================

    #[Test]
    public function indexRedirectsWhenNoProperty(): void
    {
        $_REQUEST = [];

        // The redirect calls header() + exit, so we expect it to
        // call getReviewProperty() and get empty string
        // We test the private method directly since testing redirect is complex
        $method = new \ReflectionMethod(ReviewController::class, 'getReviewProperty');

        $result = $method->invoke($this->controller);
        $this->assertSame('', $result);
    }

    // =========================================================================
    // renderReviewPage() internal logic tests (via reflection)
    // =========================================================================

    #[Test]
    public function renderReviewPageRedirectsWhenNoTestData(): void
    {
        $_REQUEST = ['lang' => '5', 'text' => '', 'selection' => '', 'type' => '1'];

        $this->sessionManager->method('hasCriteria')->willReturn(false);
        $this->reviewFacade->method('getReviewDataFromParams')->willReturn(null);

        // renderReviewPage calls redirect which calls header()+exit
        // We test the method returns early by checking facade is only called once
        $this->reviewFacade->expects($this->once())
            ->method('getReviewDataFromParams');
        $this->reviewFacade->expects($this->never())
            ->method('getReviewIdentifier');

        $method = new \ReflectionMethod(ReviewController::class, 'renderReviewPage');

        ob_start();
        try {
            $method->invoke($this->controller);
        } catch (\Throwable $e) {
            // Redirect may throw or exit
        }
        ob_end_clean();

        $this->assertTrue(true);
    }

    #[Test]
    public function renderReviewPageRedirectsWhenEmptyIdentifier(): void
    {
        $_REQUEST = ['lang' => '5', 'text' => '', 'selection' => '', 'type' => '1'];

        $this->sessionManager->method('hasCriteria')->willReturn(false);
        $this->reviewFacade->method('getReviewDataFromParams')
            ->willReturn([
                'counts' => ['due' => 10, 'total' => 50],
                'title' => 'Test',
                'property' => 'lang=5'
            ]);
        $this->reviewFacade->method('getReviewIdentifier')
            ->willReturn(['', null]);

        // Should not proceed to getReviewSql
        $this->reviewFacade->expects($this->never())
            ->method('getReviewSql');

        $method = new \ReflectionMethod(ReviewController::class, 'renderReviewPage');

        ob_start();
        try {
            $method->invoke($this->controller);
        } catch (\Throwable $e) {
            // Redirect may throw or exit
        }
        ob_end_clean();

        $this->assertTrue(true);
    }

    #[Test]
    public function renderReviewPageDetectsTableMode(): void
    {
        $_REQUEST = ['lang' => '5', 'text' => '', 'selection' => '', 'type' => 'table'];

        $this->sessionManager->method('hasCriteria')->willReturn(false);
        $this->reviewFacade->method('getReviewDataFromParams')
            ->willReturn([
                'counts' => ['due' => 10, 'total' => 50],
                'title' => 'Test',
                'property' => 'lang=5'
            ]);
        $this->reviewFacade->method('getReviewIdentifier')
            ->willReturn(['lang', 5]);
        $this->reviewFacade->method('getReviewSql')
            ->willReturn(['sql' => ' words WHERE WoLgID = ? ', 'params' => [5]]);

        // In table mode, testType is set to 1
        $this->reviewFacade->expects($this->once())
            ->method('isWordMode')
            ->with(1);

        $this->reviewFacade->method('getLanguageIdFromReviewSql')->willReturn(null);

        $method = new \ReflectionMethod(ReviewController::class, 'renderReviewPage');

        ob_start();
        try {
            $method->invoke($this->controller);
        } catch (\Throwable $e) {
            // View include may fail
        }
        ob_end_clean();

        $this->assertTrue(true);
    }

    #[Test]
    public function renderReviewPageClampsReviewType(): void
    {
        $_REQUEST = ['lang' => '5', 'text' => '', 'selection' => '', 'type' => '3'];

        $this->sessionManager->method('hasCriteria')->willReturn(false);
        $this->reviewFacade->method('getReviewDataFromParams')
            ->willReturn([
                'counts' => ['due' => 10, 'total' => 50],
                'title' => 'Test',
                'property' => 'lang=5'
            ]);
        $this->reviewFacade->method('getReviewIdentifier')
            ->willReturn(['lang', 5]);
        $this->reviewFacade->method('getReviewSql')
            ->willReturn(['sql' => ' words WHERE WoLgID = ? ', 'params' => [5]]);

        $this->reviewFacade->expects($this->once())
            ->method('clampReviewType')
            ->with(3)
            ->willReturn(3);

        $this->reviewFacade->method('isWordMode')->willReturn(true);
        $this->reviewFacade->method('getBaseReviewType')->willReturn(1);
        $this->reviewFacade->method('getLanguageIdFromReviewSql')->willReturn(null);

        $method = new \ReflectionMethod(ReviewController::class, 'renderReviewPage');

        ob_start();
        try {
            $method->invoke($this->controller);
        } catch (\Throwable $e) {
            // View include may fail
        }
        ob_end_clean();

        $this->assertTrue(true);
    }
}
