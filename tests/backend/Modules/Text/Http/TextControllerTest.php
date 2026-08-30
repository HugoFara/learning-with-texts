<?php

declare(strict_types=1);

namespace Lwt\Tests\Modules\Text\Http;

use Lwt\Modules\Text\Http\TextController;
use Lwt\Modules\Text\Http\TextReadController;
use Lwt\Modules\Text\Http\TextCrudController;
use Lwt\Modules\Text\Http\ArchivedTextController;
use Lwt\Modules\Text\Application\TextFacade;
use Lwt\Modules\Text\Application\Services\TextDisplayService;
use Lwt\Modules\Language\Application\LanguageFacade;
use Lwt\Shared\Infrastructure\Http\RedirectResponse;
use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\MockObject;

/**
 * Unit tests for TextController (facade) and sub-controllers.
 *
 * Tests text management, reading, display, archive/unarchive,
 * mark actions, class structure, and method signatures.
 */
class TextControllerTest extends TestCase
{
    /** @var TextFacade&MockObject */
    private TextFacade $textService;

    /** @var LanguageFacade&MockObject */
    private LanguageFacade $languageService;

    /** @var TextDisplayService&MockObject */
    private TextDisplayService $displayService;

    private TextController $controller;
    private TextCrudController $crudController;
    private ArchivedTextController $archivedController;
    private TextReadController $readController;

    protected function setUp(): void
    {
        if (!defined('LWT_TEST_DB_AVAILABLE') || !LWT_TEST_DB_AVAILABLE) {
            $this->markTestSkipped('Database connection required');
        }
        $this->textService = $this->createMock(TextFacade::class);
        $this->languageService = $this->createMock(LanguageFacade::class);
        $this->displayService = $this->createMock(TextDisplayService::class);

        $this->controller = new TextController(
            $this->textService,
            $this->languageService,
            $this->displayService
        );

        $this->crudController = new TextCrudController(
            $this->textService,
            $this->languageService
        );

        $this->archivedController = new ArchivedTextController(
            $this->textService,
            $this->languageService
        );

        $this->readController = new TextReadController(
            $this->textService,
            $this->displayService
        );
    }

    // =========================================================================
    // Constructor tests
    // =========================================================================

    #[Test]
    public function constructorCreatesValidController(): void
    {
        $this->assertInstanceOf(TextController::class, $this->controller);
    }

    #[Test]
    public function constructorAcceptsNullParameters(): void
    {
        $controller = new TextController(null, null, null);
        $this->assertInstanceOf(TextController::class, $controller);
    }

    #[Test]
    public function constructorCreatesSubControllers(): void
    {
        $reflection = new \ReflectionProperty(TextController::class, 'readController');
        $this->assertInstanceOf(TextReadController::class, $reflection->getValue($this->controller));

        $reflection = new \ReflectionProperty(TextController::class, 'crudController');
        $this->assertInstanceOf(TextCrudController::class, $reflection->getValue($this->controller));

        $reflection = new \ReflectionProperty(TextController::class, 'archivedController');
        $this->assertInstanceOf(ArchivedTextController::class, $reflection->getValue($this->controller));
    }

    #[Test]
    public function constructorWithNullCreatesDefaultSubControllers(): void
    {
        $controller = new TextController(null, null, null);
        $reflection = new \ReflectionProperty(TextController::class, 'readController');

        $this->assertInstanceOf(TextReadController::class, $reflection->getValue($controller));
    }

    // =========================================================================
    // Class structure tests
    // =========================================================================

    #[Test]
    public function classExtendsBaseController(): void
    {
        $reflection = new \ReflectionClass(TextController::class);
        $this->assertSame(
            'Lwt\Shared\Http\BaseController',
            $reflection->getParentClass()->getName()
        );
    }

    #[Test]
    public function classHasRequiredPublicMethods(): void
    {
        $reflection = new \ReflectionClass(TextController::class);

        $expectedMethods = [
            'read', 'new', 'editSingle', 'delete', 'archive',
            'unarchive', 'edit', 'display', 'check',
            'archived', 'archivedEdit', 'deleteArchived',
        ];

        foreach ($expectedMethods as $methodName) {
            $this->assertTrue(
                $reflection->hasMethod($methodName),
                "TextController should have method: $methodName"
            );
            $method = $reflection->getMethod($methodName);
            $this->assertTrue(
                $method->isPublic(),
                "Method $methodName should be public"
            );
        }
    }

    #[Test]
    public function subControllersHaveExpectedMethods(): void
    {
        // TextReadController
        $readMethods = ['read', 'display', 'check', 'getTextIdFromRequest', 'renderReadPage'];
        $readReflection = new \ReflectionClass(TextReadController::class);
        foreach ($readMethods as $method) {
            $this->assertTrue($readReflection->hasMethod($method), "TextReadController should have: $method");
        }

        // TextCrudController
        $crudMethods = ['new', 'editSingle', 'delete', 'archive', 'unarchive', 'edit',
            'handleMarkAction', 'showNewTextForm', 'showEditTextForm', 'showTextsList'];
        $crudReflection = new \ReflectionClass(TextCrudController::class);
        foreach ($crudMethods as $method) {
            $this->assertTrue($crudReflection->hasMethod($method), "TextCrudController should have: $method");
        }

        // ArchivedTextController
        $archMethods = ['archived', 'archivedEdit', 'deleteArchived', 'handleArchivedMarkAction'];
        $archReflection = new \ReflectionClass(ArchivedTextController::class);
        foreach ($archMethods as $method) {
            $this->assertTrue($archReflection->hasMethod($method), "ArchivedTextController should have: $method");
        }
    }

    #[Test]
    public function subControllersHaveViewsConstants(): void
    {
        foreach ([TextReadController::class, TextCrudController::class, ArchivedTextController::class] as $class) {
            $reflection = new \ReflectionClassConstant($class, 'MODULE_VIEWS');
            $this->assertStringEndsWith('/Views', $reflection->getValue());
        }
    }

    // =========================================================================
    // read() method tests
    // =========================================================================

    #[Test]
    public function readRedirectsWhenNoTextId(): void
    {
        $result = $this->controller->read(null);

        $this->assertInstanceOf(RedirectResponse::class, $result);
    }

    #[Test]
    public function readRedirectsWhenTextNotFound(): void
    {
        $this->textService->expects($this->once())
            ->method('getTextForReading')
            ->with(999)
            ->willReturn(null);

        $result = $this->controller->read(999);

        $this->assertInstanceOf(RedirectResponse::class, $result);
    }

    // =========================================================================
    // delete() method tests
    // =========================================================================

    #[Test]
    public function deleteCallsServiceAndRedirects(): void
    {
        $this->textService->expects($this->once())
            ->method('deleteText')
            ->with(42);

        $result = $this->controller->delete(42);

        $this->assertInstanceOf(RedirectResponse::class, $result);
    }

    #[Test]
    public function deleteRedirectsToTextsList(): void
    {
        $this->textService->method('deleteText')->willReturn(['sentences' => 0, 'textItems' => 0]);

        $result = $this->controller->delete(1);

        $this->assertInstanceOf(RedirectResponse::class, $result);
        $this->assertSame('/texts', $result->getUrl());
    }

    // =========================================================================
    // archive() method tests
    // =========================================================================

    #[Test]
    public function archiveCallsServiceAndRedirects(): void
    {
        $this->textService->expects($this->once())
            ->method('archiveText')
            ->with(42);

        $result = $this->controller->archive(42);

        $this->assertInstanceOf(RedirectResponse::class, $result);
    }

    #[Test]
    public function archiveRedirectsToTextsList(): void
    {
        $this->textService->method('archiveText')->willReturn(['sentences' => 0, 'textItems' => 0]);

        $result = $this->controller->archive(1);

        $this->assertSame('/texts', $result->getUrl());
    }

    // =========================================================================
    // unarchive() method tests
    // =========================================================================

    #[Test]
    public function unarchiveCallsServiceAndRedirects(): void
    {
        $this->textService->expects($this->once())
            ->method('unarchiveText')
            ->with(42);

        $result = $this->controller->unarchive(42);

        $this->assertInstanceOf(RedirectResponse::class, $result);
    }

    #[Test]
    public function unarchiveRedirectsToArchivedTextsList(): void
    {
        $this->textService->method('unarchiveText')
            ->willReturn(['success' => true, 'sentences' => 0, 'textItems' => 0]);

        $result = $this->controller->unarchive(1);

        $this->assertSame('/text/archived', $result->getUrl());
    }

    // =========================================================================
    // deleteArchived() method tests
    // =========================================================================

    #[Test]
    public function deleteArchivedCallsServiceAndRedirects(): void
    {
        $this->textService->expects($this->once())
            ->method('deleteArchivedText')
            ->with(42);

        $result = $this->controller->deleteArchived(42);

        $this->assertInstanceOf(RedirectResponse::class, $result);
    }

    #[Test]
    public function deleteArchivedRedirectsToArchivedList(): void
    {
        $this->textService->method('deleteArchivedText')->willReturn([]);

        $result = $this->controller->deleteArchived(1);

        $this->assertSame('/text/archived', $result->getUrl());
    }

    // =========================================================================
    // getTextIdFromRequest() tests via TextReadController
    // =========================================================================

    #[Test]
    public function getTextIdFromRequestReturnsInjectedId(): void
    {
        $result = $this->readController->getTextIdFromRequest(123);

        $this->assertSame(123, $result);
    }

    #[Test]
    public function getTextIdFromRequestReturnsNullWhenEmpty(): void
    {
        $result = $this->readController->getTextIdFromRequest(null);

        $this->assertNull($result);
    }

    #[Test]
    public function getTextIdFromRequestPrioritizesInjectedOverQueryParam(): void
    {
        $result = $this->readController->getTextIdFromRequest(42);

        $this->assertSame(42, $result);
    }

    #[Test]
    public function getTextIdFromRequestReturnsZeroInjectedId(): void
    {
        $result = $this->readController->getTextIdFromRequest(0);

        $this->assertSame(0, $result);
    }

    // =========================================================================
    // handleMarkAction() tests via TextCrudController
    // =========================================================================

    #[Test]
    public function handleMarkActionReturnsDefaultForEmptyMarked(): void
    {
        $result = $this->crudController->handleMarkAction('del', [], '');

        $this->assertSame('Multiple Actions: 0', $result);
    }

    #[Test]
    public function handleMarkActionDeleteCallsService(): void
    {
        $this->textService->expects($this->once())
            ->method('deleteTexts')
            ->with([1, 2, 3])
            ->willReturn(['count' => 3]);

        $result = $this->crudController->handleMarkAction('del', ['1', '2', '3'], '');

        $this->assertSame('Texts deleted: 3', $result);
    }

    #[Test]
    public function handleMarkActionArchiveCallsService(): void
    {
        $this->textService->expects($this->once())
            ->method('archiveTexts')
            ->with([1, 2])
            ->willReturn(['count' => 2]);

        $result = $this->crudController->handleMarkAction('arch', ['1', '2'], '');

        $this->assertSame('Archived Text(s): 2', $result);
    }

    #[Test]
    public function handleMarkActionSetSentencesCallsService(): void
    {
        $this->textService->expects($this->once())
            ->method('setTermSentences')
            ->with([1], false)
            ->willReturn(5);

        $result = $this->crudController->handleMarkAction('setsent', ['1'], '');

        $this->assertSame('Term sentences set: 5', $result);
    }

    #[Test]
    public function handleMarkActionSetActiveSentencesCallsService(): void
    {
        $this->textService->expects($this->once())
            ->method('setTermSentences')
            ->with([1], true)
            ->willReturn(3);

        $result = $this->crudController->handleMarkAction('setactsent', ['1'], '');

        $this->assertSame('Active term sentences set: 3', $result);
    }

    #[Test]
    public function handleMarkActionRebuildCallsService(): void
    {
        $this->textService->expects($this->once())
            ->method('rebuildTexts')
            ->with([1, 2])
            ->willReturn(2);

        $result = $this->crudController->handleMarkAction('rebuild', ['1', '2'], '');

        $this->assertSame('Rebuilt Text(s): 2', $result);
    }

    #[Test]
    public function handleMarkActionReviewReturnsRedirect(): void
    {
        $result = $this->crudController->handleMarkAction('review', ['1', '2'], '');

        $this->assertInstanceOf(RedirectResponse::class, $result);
    }

    #[Test]
    public function handleMarkActionReviewRedirectsToReview(): void
    {
        $result = $this->crudController->handleMarkAction('review', ['1'], '');

        $this->assertInstanceOf(RedirectResponse::class, $result);
        $this->assertSame('/review?selection=3', $result->getUrl());
    }

    #[Test]
    public function handleMarkActionDeltagReturnsRedirect(): void
    {
        $result = $this->crudController->handleMarkAction('deltag', ['1'], 'tag');

        $this->assertInstanceOf(RedirectResponse::class, $result);
    }

    #[Test]
    public function handleMarkActionDeltagRedirectsToTexts(): void
    {
        $result = $this->crudController->handleMarkAction('deltag', ['1'], 'tag');

        $this->assertSame('/texts', $result->getUrl());
    }

    #[Test]
    public function handleMarkActionUnknownActionReturnsDefault(): void
    {
        $result = $this->crudController->handleMarkAction('unknownaction', ['1'], '');

        $this->assertSame('Multiple Actions: 0', $result);
    }

    // =========================================================================
    // handleArchivedMarkAction() tests via ArchivedTextController
    // =========================================================================

    #[Test]
    public function handleArchivedMarkActionReturnsDefaultForEmptyMarked(): void
    {
        $result = $this->archivedController->handleArchivedMarkAction('del', [], '');

        $this->assertSame('Multiple Actions: 0', $result);
    }

    #[Test]
    public function handleArchivedMarkActionDeleteCallsService(): void
    {
        $this->textService->expects($this->once())
            ->method('deleteArchivedTexts')
            ->with([1, 2])
            ->willReturn(['count' => 2]);

        $result = $this->archivedController->handleArchivedMarkAction('del', ['1', '2'], '');

        $this->assertSame('Archived Texts deleted: 2', $result);
    }

    #[Test]
    public function handleArchivedMarkActionUnarchiveCallsService(): void
    {
        $this->textService->expects($this->once())
            ->method('unarchiveTexts')
            ->with([1])
            ->willReturn(['count' => 1]);

        $result = $this->archivedController->handleArchivedMarkAction('unarch', ['1'], '');

        $this->assertSame('Unarchived Text(s): 1', $result);
    }

    #[Test]
    public function handleArchivedMarkActionDeltagReturnsRedirect(): void
    {
        $result = $this->archivedController->handleArchivedMarkAction('deltag', ['1'], 'tag');

        $this->assertInstanceOf(RedirectResponse::class, $result);
    }

    #[Test]
    public function handleArchivedMarkActionDeltagRedirectsToArchived(): void
    {
        $result = $this->archivedController->handleArchivedMarkAction('deltag', ['1'], 'tag');

        $this->assertSame('/text/archived', $result->getUrl());
    }

    #[Test]
    public function handleArchivedMarkActionUnknownReturnsDefault(): void
    {
        $result = $this->archivedController->handleArchivedMarkAction('unknownaction', ['1'], '');

        $this->assertSame('Multiple Actions: 0', $result);
    }

    // =========================================================================
    // display() method tests
    // =========================================================================

    #[Test]
    public function displayRedirectsWhenTextIdIsZero(): void
    {
        $result = $this->controller->display(0);

        $this->assertInstanceOf(RedirectResponse::class, $result);
    }

    #[Test]
    public function displayRedirectsWhenAnnotatedTextEmpty(): void
    {
        $this->displayService->expects($this->once())
            ->method('getAnnotatedText')
            ->with(42)
            ->willReturn('');

        $result = $this->controller->display(42);

        $this->assertInstanceOf(RedirectResponse::class, $result);
    }

    #[Test]
    public function displayRedirectsWhenSettingsNull(): void
    {
        $this->displayService->method('getAnnotatedText')
            ->willReturn('some annotation text');
        $this->displayService->method('getTextDisplaySettings')
            ->with(42)
            ->willReturn(null);

        $result = $this->controller->display(42);

        $this->assertInstanceOf(RedirectResponse::class, $result);
    }

    #[Test]
    public function displayRedirectsWhenHeaderDataNull(): void
    {
        $this->displayService->method('getAnnotatedText')
            ->willReturn('some annotation text');
        $this->displayService->method('getTextDisplaySettings')
            ->willReturn(['textSize' => 100, 'rtlScript' => false]);
        $this->displayService->method('getHeaderData')
            ->with(42)
            ->willReturn(null);

        $result = $this->controller->display(42);

        $this->assertInstanceOf(RedirectResponse::class, $result);
    }

    #[Test]
    public function displayRedirectsWhenNullPassedAndNoQueryParam(): void
    {
        $result = $this->controller->display(null);

        $this->assertInstanceOf(RedirectResponse::class, $result);
    }

    // =========================================================================
    // Method signature tests
    // =========================================================================

    #[Test]
    public function readMethodAcceptsNullableInt(): void
    {
        $method = new \ReflectionMethod(TextController::class, 'read');
        $params = $method->getParameters();

        $this->assertCount(1, $params);
        $this->assertSame('text', $params[0]->getName());
        $this->assertTrue($params[0]->allowsNull());
    }

    #[Test]
    public function deleteMethodAcceptsInt(): void
    {
        $method = new \ReflectionMethod(TextController::class, 'delete');
        $params = $method->getParameters();

        $this->assertCount(1, $params);
        $this->assertSame('id', $params[0]->getName());
        $this->assertFalse($params[0]->allowsNull());
    }

    #[Test]
    public function archiveMethodAcceptsInt(): void
    {
        $method = new \ReflectionMethod(TextController::class, 'archive');
        $params = $method->getParameters();

        $this->assertCount(1, $params);
        $this->assertSame('id', $params[0]->getName());
    }

    #[Test]
    public function editSingleMethodAcceptsInt(): void
    {
        $method = new \ReflectionMethod(TextController::class, 'editSingle');
        $params = $method->getParameters();

        $this->assertCount(1, $params);
        $this->assertSame('id', $params[0]->getName());
    }

    #[Test]
    public function deleteReturnsRedirectResponse(): void
    {
        $method = new \ReflectionMethod(TextController::class, 'delete');
        $returnType = $method->getReturnType();

        $this->assertNotNull($returnType);
        $this->assertSame(RedirectResponse::class, $returnType->getName());
    }

    #[Test]
    public function archiveReturnsRedirectResponse(): void
    {
        $method = new \ReflectionMethod(TextController::class, 'archive');
        $returnType = $method->getReturnType();

        $this->assertNotNull($returnType);
        $this->assertSame(RedirectResponse::class, $returnType->getName());
    }

    #[Test]
    public function unarchiveReturnsRedirectResponse(): void
    {
        $method = new \ReflectionMethod(TextController::class, 'unarchive');
        $returnType = $method->getReturnType();

        $this->assertNotNull($returnType);
        $this->assertSame(RedirectResponse::class, $returnType->getName());
    }

    #[Test]
    public function readReturnsNullableRedirectResponse(): void
    {
        $method = new \ReflectionMethod(TextController::class, 'read');
        $returnType = $method->getReturnType();

        $this->assertNotNull($returnType);
        $this->assertTrue($returnType->allowsNull());
    }

    #[Test]
    public function displayReturnsNullableRedirectResponse(): void
    {
        $method = new \ReflectionMethod(TextController::class, 'display');
        $returnType = $method->getReturnType();

        $this->assertNotNull($returnType);
        $this->assertTrue($returnType->allowsNull());
    }

    #[Test]
    public function editMethodAcceptsArrayParams(): void
    {
        $method = new \ReflectionMethod(TextController::class, 'edit');
        $params = $method->getParameters();

        $this->assertCount(1, $params);
        $this->assertSame('params', $params[0]->getName());
    }

    #[Test]
    public function newMethodAcceptsArrayParams(): void
    {
        $method = new \ReflectionMethod(TextController::class, 'new');
        $params = $method->getParameters();

        $this->assertCount(1, $params);
        $this->assertSame('params', $params[0]->getName());
    }

    #[Test]
    public function checkReturnsVoid(): void
    {
        $method = new \ReflectionMethod(TextController::class, 'check');
        $returnType = $method->getReturnType();

        $this->assertNotNull($returnType);
        $this->assertSame('void', $returnType->getName());
    }

    #[Test]
    public function archivedEditAcceptsIntParam(): void
    {
        $method = new \ReflectionMethod(TextController::class, 'archivedEdit');
        $params = $method->getParameters();

        $this->assertCount(1, $params);
        $this->assertSame('id', $params[0]->getName());
    }

    #[Test]
    public function deleteArchivedReturnsRedirectResponse(): void
    {
        $method = new \ReflectionMethod(TextController::class, 'deleteArchived');
        $returnType = $method->getReturnType();

        $this->assertNotNull($returnType);
        $this->assertSame(RedirectResponse::class, $returnType->getName());
    }
}
