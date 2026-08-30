<?php

declare(strict_types=1);

namespace Lwt\Tests\Modules\Vocabulary\Http;

use Lwt\Modules\Vocabulary\Http\TermCrudApiHandler;
use Lwt\Modules\Vocabulary\Application\VocabularyFacade;
use Lwt\Modules\Vocabulary\Application\UseCases\FindSimilarTerms;
use Lwt\Modules\Vocabulary\Application\Services\WordContextService;
use Lwt\Modules\Vocabulary\Application\Services\WordDiscoveryService;
use Lwt\Modules\Vocabulary\Application\Services\WordLinkingService;
use Lwt\Modules\Vocabulary\Domain\Term;
use Lwt\Modules\Vocabulary\Domain\ValueObject\TermId;
use Lwt\Modules\Vocabulary\Domain\ValueObject\TermStatus;
use Lwt\Modules\Language\Domain\ValueObject\LanguageId;
use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\MockObject\MockObject;
use ReflectionMethod;

/**
 * Unit tests for TermCrudApiHandler.
 *
 * Tests term CRUD API operations including get, create, update, delete,
 * quick create, term details, term editing, and full CRUD.
 */
class TermCrudApiHandlerTest extends TestCase
{
    /** @var VocabularyFacade&MockObject */
    private VocabularyFacade $facade;

    /** @var FindSimilarTerms&MockObject */
    private FindSimilarTerms $findSimilarTerms;

    /** @var WordContextService&MockObject */
    private WordContextService $contextService;

    /** @var WordDiscoveryService&MockObject */
    private WordDiscoveryService $discoveryService;

    /** @var WordLinkingService&MockObject */
    private WordLinkingService $linkingService;

    private TermCrudApiHandler $handler;

    protected function setUp(): void
    {
        $this->facade = $this->createMock(VocabularyFacade::class);
        $this->findSimilarTerms = $this->createMock(FindSimilarTerms::class);
        $this->contextService = $this->createMock(WordContextService::class);
        $this->discoveryService = $this->createMock(WordDiscoveryService::class);
        $this->linkingService = $this->createMock(WordLinkingService::class);

        $this->handler = new TermCrudApiHandler(
            $this->facade,
            $this->findSimilarTerms,
            $this->contextService,
            $this->discoveryService,
            $this->linkingService
        );
    }

    // =========================================================================
    // Constructor tests
    // =========================================================================

    public function testConstructorCreatesValidHandler(): void
    {
        $this->assertInstanceOf(TermCrudApiHandler::class, $this->handler);
    }

    public function testConstructorAcceptsNullParameters(): void
    {
        $handler = new TermCrudApiHandler(null, null, null, null, null);
        $this->assertInstanceOf(TermCrudApiHandler::class, $handler);
    }

    // =========================================================================
    // getTerm tests
    // =========================================================================

    public function testGetTermReturnsErrorWhenNotFound(): void
    {
        $this->facade->method('getTerm')
            ->willReturn(null);

        $result = $this->handler->getTerm(999);

        $this->assertArrayHasKey('error', $result);
        $this->assertSame('Term not found', $result['error']);
    }

    public function testGetTermReturnsTermData(): void
    {
        $term = $this->createMockTerm(123, 'hello', 'hola', 1);
        $this->facade->method('getTerm')
            ->willReturn($term);

        $result = $this->handler->getTerm(123);

        $this->assertArrayNotHasKey('error', $result);
        $this->assertSame(123, $result['id']);
        $this->assertSame('hello', $result['text']);
        $this->assertSame('hola', $result['translation']);
        $this->assertSame(1, $result['status']);
    }

    public function testGetTermIncludesAllFields(): void
    {
        $term = $this->createMockTerm(1, 'test', 'prueba', 3);
        $this->facade->method('getTerm')
            ->willReturn($term);

        $result = $this->handler->getTerm(1);

        $this->assertArrayHasKey('id', $result);
        $this->assertArrayHasKey('text', $result);
        $this->assertArrayHasKey('textLc', $result);
        $this->assertArrayHasKey('lemma', $result);
        $this->assertArrayHasKey('lemmaLc', $result);
        $this->assertArrayHasKey('translation', $result);
        $this->assertArrayHasKey('romanization', $result);
        $this->assertArrayHasKey('sentence', $result);
        $this->assertArrayHasKey('status', $result);
        $this->assertArrayHasKey('statusLabel', $result);
        $this->assertArrayHasKey('langId', $result);
        $this->assertArrayHasKey('wordCount', $result);
    }

    public function testGetTermReturnsCorrectTextLc(): void
    {
        $term = $this->createMockTerm(1, 'Hello', 'hola', 1);
        $this->facade->method('getTerm')->willReturn($term);

        $result = $this->handler->getTerm(1);

        $this->assertSame('hello', $result['textLc']);
    }

    public function testGetTermReturnsWordCount(): void
    {
        $term = $this->createMockTerm(1, 'hello world', 'hola mundo', 1, 2);
        $this->facade->method('getTerm')->willReturn($term);

        $result = $this->handler->getTerm(1);

        $this->assertSame(2, $result['wordCount']);
    }

    public function testGetTermCallsFacadeWithCorrectId(): void
    {
        $this->facade->expects($this->once())
            ->method('getTerm')
            ->with(42)
            ->willReturn(null);

        $this->handler->getTerm(42);
    }

    // =========================================================================
    // createTerm tests
    // =========================================================================

    public function testCreateTermReturnsErrorWhenLanguageIdMissing(): void
    {
        $result = $this->handler->createTerm([
            'text' => 'hello'
        ]);

        $this->assertFalse($result['success']);
        $this->assertSame('Language ID and text are required', $result['error']);
    }

    public function testCreateTermReturnsErrorWhenLanguageIdZero(): void
    {
        $result = $this->handler->createTerm([
            'langId' => 0,
            'text' => 'hello'
        ]);

        $this->assertFalse($result['success']);
        $this->assertSame('Language ID and text are required', $result['error']);
    }

    public function testCreateTermReturnsErrorWhenTextEmpty(): void
    {
        $result = $this->handler->createTerm([
            'langId' => 1,
            'text' => ''
        ]);

        $this->assertFalse($result['success']);
        $this->assertSame('Language ID and text are required', $result['error']);
    }

    public function testCreateTermReturnsErrorWhenTextOnlyWhitespace(): void
    {
        $result = $this->handler->createTerm([
            'langId' => 1,
            'text' => '   '
        ]);

        $this->assertFalse($result['success']);
        $this->assertSame('Language ID and text are required', $result['error']);
    }

    public function testCreateTermReturnsErrorForInvalidStatus(): void
    {
        $result = $this->handler->createTerm([
            'langId' => 1,
            'text' => 'hello',
            'status' => 100
        ]);

        $this->assertFalse($result['success']);
        $this->assertSame('Invalid status', $result['error']);
    }

    public function testCreateTermReturnsErrorForNegativeStatus(): void
    {
        $result = $this->handler->createTerm([
            'langId' => 1,
            'text' => 'hello',
            'status' => -1
        ]);

        $this->assertFalse($result['success']);
        $this->assertSame('Invalid status', $result['error']);
    }

    public function testCreateTermReturnsSuccessWithValidData(): void
    {
        $term = $this->createMockTerm(123, 'hello', 'hola', 1);
        $this->facade->expects($this->once())
            ->method('createTerm')
            ->with(1, 'hello', 1, '*', '', '')
            ->willReturn($term);

        $result = $this->handler->createTerm([
            'langId' => 1,
            'text' => 'hello',
            'status' => 1
        ]);

        $this->assertTrue($result['success']);
        $this->assertSame(123, $result['id']);
        $this->assertArrayHasKey('textLc', $result);
        $this->assertArrayHasKey('hex', $result);
    }

    public function testCreateTermUsesDefaultTranslation(): void
    {
        $term = $this->createMockTerm(1, 'hello', '*', 1);
        $this->facade->expects($this->once())
            ->method('createTerm')
            ->with(1, 'hello', 1, '*', '', '')
            ->willReturn($term);

        $this->handler->createTerm([
            'langId' => 1,
            'text' => 'hello',
            'translation' => ''
        ]);
    }

    public function testCreateTermPassesOptionalFields(): void
    {
        $term = $this->createMockTerm(1, 'hello', 'hola', 2);
        $this->facade->expects($this->once())
            ->method('createTerm')
            ->with(1, 'hello', 2, 'hola', 'elo', 'Hello world')
            ->willReturn($term);

        $this->handler->createTerm([
            'langId' => 1,
            'text' => 'hello',
            'status' => 2,
            'translation' => 'hola',
            'romanization' => 'elo',
            'sentence' => 'Hello world'
        ]);
    }

    public function testCreateTermTrimsWhitespace(): void
    {
        $term = $this->createMockTerm(1, 'hello', 'hola', 1);
        $this->facade->expects($this->once())
            ->method('createTerm')
            ->with(1, 'hello', 1, 'hola', 'elo', 'test')
            ->willReturn($term);

        $this->handler->createTerm([
            'langId' => 1,
            'text' => '  hello  ',
            'translation' => '  hola  ',
            'romanization' => '  elo  ',
            'sentence' => '  test  '
        ]);
    }

    public function testCreateTermHandlesException(): void
    {
        $this->facade->method('createTerm')
            ->willThrowException(new \Exception('Database error'));

        $result = $this->handler->createTerm([
            'langId' => 1,
            'text' => 'hello'
        ]);

        $this->assertFalse($result['success']);
        $this->assertSame('Database error', $result['error']);
    }

    public function testCreateTermReturnsErrorWhenTextMissing(): void
    {
        $result = $this->handler->createTerm([
            'langId' => 1
        ]);

        $this->assertFalse($result['success']);
        $this->assertSame('Language ID and text are required', $result['error']);
    }

    public function testCreateTermReturnsErrorForStatus6(): void
    {
        $result = $this->handler->createTerm([
            'langId' => 1,
            'text' => 'hello',
            'status' => 6
        ]);

        $this->assertFalse($result['success']);
        $this->assertSame('Invalid status', $result['error']);
    }

    public function testCreateTermReturnsErrorForStatus0(): void
    {
        $result = $this->handler->createTerm([
            'langId' => 1,
            'text' => 'hello',
            'status' => 0
        ]);

        $this->assertFalse($result['success']);
        $this->assertSame('Invalid status', $result['error']);
    }

    // =========================================================================
    // createTerm valid status tests
    // =========================================================================

    public function testCreateTermAcceptsStatus98(): void
    {
        $term = $this->createMockTerm(1, 'hello', '*', 98);
        $this->facade->method('createTerm')
            ->willReturn($term);

        $result = $this->handler->createTerm([
            'langId' => 1,
            'text' => 'hello',
            'status' => 98
        ]);

        $this->assertTrue($result['success']);
    }

    public function testCreateTermAcceptsStatus99(): void
    {
        $term = $this->createMockTerm(1, 'hello', '*', 99);
        $this->facade->method('createTerm')
            ->willReturn($term);

        $result = $this->handler->createTerm([
            'langId' => 1,
            'text' => 'hello',
            'status' => 99
        ]);

        $this->assertTrue($result['success']);
    }

    public function testCreateTermDefaultsStatus(): void
    {
        $term = $this->createMockTerm(1, 'hello', '*', 1);
        $this->facade->expects($this->once())
            ->method('createTerm')
            ->with(1, 'hello', 1, '*', '', '')
            ->willReturn($term);

        $this->handler->createTerm([
            'langId' => 1,
            'text' => 'hello'
        ]);
    }

    // =========================================================================
    // updateTerm tests
    // =========================================================================

    public function testUpdateTermReturnsErrorWhenNotFound(): void
    {
        $this->facade->method('getTerm')
            ->willReturn(null);

        $result = $this->handler->updateTerm(999, ['translation' => 'new']);

        $this->assertFalse($result['success']);
        $this->assertSame('Term not found', $result['error']);
    }

    public function testUpdateTermCallsFacadeWithValidData(): void
    {
        $term = $this->createMockTerm(1, 'hello', 'old', 1);
        $this->facade->method('getTerm')
            ->willReturn($term);
        $this->facade->expects($this->once())
            ->method('updateTerm');

        $result = $this->handler->updateTerm(1, ['translation' => 'new']);

        $this->assertTrue($result['success']);
    }

    public function testUpdateTermUsesDefaultTranslationForEmpty(): void
    {
        $term = $this->createMockTerm(1, 'hello', 'old', 1);
        $this->facade->method('getTerm')
            ->willReturn($term);
        $this->facade->expects($this->once())
            ->method('updateTerm')
            ->with(
                1,
                null,
                '*',
                $this->anything(),
                $this->anything()
            );

        $this->handler->updateTerm(1, ['translation' => '']);
    }

    public function testUpdateTermTrimsWhitespace(): void
    {
        $term = $this->createMockTerm(1, 'hello', 'old', 1);
        $this->facade->method('getTerm')
            ->willReturn($term);
        $this->facade->expects($this->once())
            ->method('updateTerm')
            ->with(
                1,
                null,
                'new translation',
                $this->anything(),
                $this->anything()
            );

        $this->handler->updateTerm(1, ['translation' => '  new translation  ']);
    }

    public function testUpdateTermHandlesException(): void
    {
        $term = $this->createMockTerm(1, 'hello', 'old', 1);
        $this->facade->method('getTerm')
            ->willReturn($term);
        $this->facade->method('updateTerm')
            ->willThrowException(new \Exception('Update failed'));

        $result = $this->handler->updateTerm(1, ['translation' => 'new']);

        $this->assertFalse($result['success']);
        $this->assertSame('Update failed', $result['error']);
    }

    public function testUpdateTermIgnoresInvalidStatus(): void
    {
        $term = $this->createMockTerm(1, 'hello', 'old', 1);
        $this->facade->method('getTerm')
            ->willReturn($term);
        $this->facade->expects($this->once())
            ->method('updateTerm')
            ->with(
                1,
                null, // Invalid status should be converted to null
                $this->anything(),
                $this->anything(),
                $this->anything()
            );

        $this->handler->updateTerm(1, ['status' => 999]);
    }

    public function testUpdateTermPassesAllFields(): void
    {
        $term = $this->createMockTerm(1, 'hello', 'old', 1);
        $this->facade->method('getTerm')
            ->willReturn($term);
        $this->facade->expects($this->once())
            ->method('updateTerm');

        $result = $this->handler->updateTerm(1, [
            'translation' => 'nueva',
            'romanization' => 'elo',
            'sentence' => 'Hello world',
            'status' => 3
        ]);

        $this->assertTrue($result['success']);
    }

    public function testUpdateTermPassesValidStatusToFacade(): void
    {
        $term = $this->createMockTerm(1, 'hello', 'old', 1);
        $this->facade->method('getTerm')
            ->willReturn($term);
        $this->facade->expects($this->once())
            ->method('updateTerm')
            ->with(
                1,
                3, // Valid status should be passed through
                $this->anything(),
                $this->anything(),
                $this->anything()
            );

        $this->handler->updateTerm(1, ['status' => 3]);
    }

    public function testUpdateTermPassesSentenceToFacade(): void
    {
        $term = $this->createMockTerm(1, 'hello', 'old', 1);
        $this->facade->method('getTerm')
            ->willReturn($term);
        $this->facade->expects($this->once())
            ->method('updateTerm')
            ->with(
                1,
                null,
                null,
                'Example sentence',
                null
            );

        $this->handler->updateTerm(1, ['sentence' => 'Example sentence']);
    }

    public function testUpdateTermWithNoDataStillSucceeds(): void
    {
        $term = $this->createMockTerm(1, 'hello', 'old', 1);
        $this->facade->method('getTerm')
            ->willReturn($term);
        $this->facade->expects($this->once())
            ->method('updateTerm')
            ->with(1, null, null, null, null);

        $result = $this->handler->updateTerm(1, []);

        $this->assertTrue($result['success']);
    }

    // =========================================================================
    // deleteTerm tests
    // =========================================================================

    public function testDeleteTermReturnsErrorWhenNotFound(): void
    {
        $this->facade->method('getTerm')
            ->willReturn(null);

        $result = $this->handler->deleteTerm(999);

        $this->assertFalse($result['deleted']);
        $this->assertSame('Term not found', $result['error']);
    }

    public function testDeleteTermCallsFacade(): void
    {
        $term = $this->createMockTerm(1, 'hello', 'hola', 1);
        $this->facade->method('getTerm')
            ->willReturn($term);
        $this->facade->expects($this->once())
            ->method('deleteTerm')
            ->with(1)
            ->willReturn(true);

        $result = $this->handler->deleteTerm(1);

        $this->assertTrue($result['deleted']);
    }

    public function testDeleteTermReturnsFalseOnFailure(): void
    {
        $term = $this->createMockTerm(1, 'hello', 'hola', 1);
        $this->facade->method('getTerm')
            ->willReturn($term);
        $this->facade->method('deleteTerm')
            ->willReturn(false);

        $result = $this->handler->deleteTerm(1);

        $this->assertFalse($result['deleted']);
    }

    public function testDeleteTermNoErrorKeyOnSuccess(): void
    {
        $term = $this->createMockTerm(1, 'hello', 'hola', 1);
        $this->facade->method('getTerm')->willReturn($term);
        $this->facade->method('deleteTerm')->willReturn(true);

        $result = $this->handler->deleteTerm(1);

        $this->assertArrayNotHasKey('error', $result);
    }

    // =========================================================================
    // deleteTerms tests
    // =========================================================================

    public function testDeleteTermsReturnsErrorWhenEmpty(): void
    {
        $result = $this->handler->deleteTerms([]);

        $this->assertSame(0, $result['deleted']);
        $this->assertSame('No term IDs provided', $result['error']);
    }

    public function testDeleteTermsCallsFacadeWithIds(): void
    {
        $this->facade->expects($this->once())
            ->method('deleteTerms')
            ->with([1, 2, 3])
            ->willReturn(3);

        $result = $this->handler->deleteTerms([1, 2, 3]);

        $this->assertSame(3, $result['deleted']);
    }

    public function testDeleteTermsReturnsPartialCount(): void
    {
        $this->facade->method('deleteTerms')
            ->willReturn(2);

        $result = $this->handler->deleteTerms([1, 2, 3]);

        $this->assertSame(2, $result['deleted']);
    }

    public function testDeleteTermsSingleId(): void
    {
        $this->facade->expects($this->once())
            ->method('deleteTerms')
            ->with([42])
            ->willReturn(1);

        $result = $this->handler->deleteTerms([42]);

        $this->assertSame(1, $result['deleted']);
        $this->assertArrayNotHasKey('error', $result);
    }

    // =========================================================================
    // formatGetTerm tests
    // =========================================================================

    public function testFormatGetTermDelegatesToGetTerm(): void
    {
        $this->facade->method('getTerm')
            ->willReturn(null);

        $result = $this->handler->formatGetTerm(999);

        $this->assertArrayHasKey('error', $result);
    }

    public function testFormatGetTermReturnsDataWhenFound(): void
    {
        $term = $this->createMockTerm(1, 'test', 'test', 1);
        $this->facade->method('getTerm')->willReturn($term);

        $result = $this->handler->formatGetTerm(1);

        $this->assertSame(1, $result['id']);
    }

    // =========================================================================
    // formatCreateTerm tests
    // =========================================================================

    public function testFormatCreateTermDelegatesToCreateTerm(): void
    {
        $result = $this->handler->formatCreateTerm([
            'langId' => 0,
            'text' => 'hello'
        ]);

        $this->assertFalse($result['success']);
    }

    // =========================================================================
    // formatUpdateTerm tests
    // =========================================================================

    public function testFormatUpdateTermDelegatesToUpdateTerm(): void
    {
        $this->facade->method('getTerm')
            ->willReturn(null);

        $result = $this->handler->formatUpdateTerm(999, []);

        $this->assertFalse($result['success']);
    }

    // =========================================================================
    // formatDeleteTerm tests
    // =========================================================================

    public function testFormatDeleteTermDelegatesToDeleteTerm(): void
    {
        $this->facade->method('getTerm')
            ->willReturn(null);

        $result = $this->handler->formatDeleteTerm(999);

        $this->assertFalse($result['deleted']);
    }

    // =========================================================================
    // createQuickTerm tests
    // =========================================================================

    public function testCreateQuickTermReturnsErrorForInvalidStatus(): void
    {
        $result = $this->handler->createQuickTerm(1, 5, 1);

        $this->assertArrayHasKey('error', $result);
        $this->assertSame('Status must be 98 (ignored) or 99 (well-known)', $result['error']);
    }

    public function testCreateQuickTermReturnsErrorForStatus5(): void
    {
        $result = $this->handler->createQuickTerm(1, 5, 5);

        $this->assertArrayHasKey('error', $result);
        $this->assertSame('Status must be 98 (ignored) or 99 (well-known)', $result['error']);
    }

    public function testCreateQuickTermReturnsErrorForStatus0(): void
    {
        $result = $this->handler->createQuickTerm(1, 5, 0);

        $this->assertArrayHasKey('error', $result);
    }

    public function testCreateQuickTermReturnsErrorWhenWordNotFound(): void
    {
        $this->linkingService->method('getWordAtPosition')
            ->willReturn(null);

        $result = $this->handler->createQuickTerm(1, 5, 98);

        $this->assertArrayHasKey('error', $result);
        $this->assertSame('Word not found at position', $result['error']);
    }

    public function testCreateQuickTermReturnsSuccessData(): void
    {
        $this->linkingService->method('getWordAtPosition')
            ->willReturn('hello');
        $this->discoveryService->method('insertWordWithStatus')
            ->willReturn([
                'id' => 123,
                'term' => 'hello',
                'termlc' => 'hello',
                'hex' => 'hex123'
            ]);

        $result = $this->handler->createQuickTerm(1, 5, 99);

        $this->assertSame(123, $result['term_id']);
        $this->assertSame('hello', $result['term']);
        $this->assertSame('hello', $result['term_lc']);
        $this->assertSame('hex123', $result['hex']);
    }

    public function testCreateQuickTermWith98Status(): void
    {
        $this->linkingService->method('getWordAtPosition')
            ->willReturn('test');
        $this->discoveryService->expects($this->once())
            ->method('insertWordWithStatus')
            ->with(1, 'test', 98)
            ->willReturn([
                'id' => 10,
                'term' => 'test',
                'termlc' => 'test',
                'hex' => 'abc'
            ]);

        $result = $this->handler->createQuickTerm(1, 3, 98);

        $this->assertSame(10, $result['term_id']);
    }

    public function testCreateQuickTermHandlesException(): void
    {
        $this->linkingService->method('getWordAtPosition')
            ->willReturn('hello');
        $this->discoveryService->method('insertWordWithStatus')
            ->willThrowException(new \RuntimeException('Insert failed'));

        $result = $this->handler->createQuickTerm(1, 5, 98);

        $this->assertArrayHasKey('error', $result);
        $this->assertSame('Insert failed', $result['error']);
    }

    public function testCreateQuickTermCallsLinkingServiceWithCorrectArgs(): void
    {
        $this->linkingService->expects($this->once())
            ->method('getWordAtPosition')
            ->with(42, 7)
            ->willReturn(null);

        $this->handler->createQuickTerm(42, 7, 98);
    }

    // =========================================================================
    // formatQuickCreate tests
    // =========================================================================

    public function testFormatQuickCreateDelegatesToCreateQuickTerm(): void
    {
        $result = $this->handler->formatQuickCreate(1, 5, 1);

        $this->assertArrayHasKey('error', $result);
    }

    public function testFormatQuickCreatePassesAllArgs(): void
    {
        $this->linkingService->expects($this->once())
            ->method('getWordAtPosition')
            ->with(10, 3)
            ->willReturn(null);

        $this->handler->formatQuickCreate(10, 3, 99);
    }

    // =========================================================================
    // getTermDetails — method signature & source analysis
    // =========================================================================

    public function testGetTermDetailsMethodSignature(): void
    {
        $method = new ReflectionMethod(TermCrudApiHandler::class, 'getTermDetails');

        $this->assertTrue($method->isPublic());
        $this->assertSame('array', $method->getReturnType()?->getName());

        $params = $method->getParameters();
        $this->assertCount(2, $params);
        $this->assertSame('termId', $params[0]->getName());
        $this->assertSame('int', $params[0]->getType()?->getName());
        $this->assertSame('ann', $params[1]->getName());
        $this->assertTrue($params[1]->getType()?->allowsNull());
        $this->assertTrue($params[1]->isDefaultValueAvailable());
        $this->assertNull($params[1]->getDefaultValue());
    }

    public function testGetTermDetailsUsesQueryBuilderPrepared(): void
    {
        $source = $this->getMethodSource('getTermDetails');

        $this->assertStringContainsString('firstPrepared()', $source);
        $this->assertStringContainsString('getPrepared()', $source);
    }

    public function testGetTermDetailsReturnsErrorKeyOnNotFound(): void
    {
        $source = $this->getMethodSource('getTermDetails');

        $this->assertStringContainsString("return ['error' => 'Term not found']", $source);
    }

    public function testGetTermDetailsQueriesWordTagMap(): void
    {
        $source = $this->getMethodSource('getTermDetails');

        $this->assertStringContainsString("table('word_tag_map')", $source);
        $this->assertStringContainsString("join('tags'", $source);
    }

    public function testGetTermDetailsHighlightsAnnotationInTranslation(): void
    {
        $source = $this->getMethodSource('getTermDetails');

        $this->assertStringContainsString("str_replace(\$ann, '<b>' . \$ann . '</b>'", $source);
    }

    public function testGetTermDetailsReturnStructureIncludesExpectedKeys(): void
    {
        $source = $this->getMethodSource('getTermDetails');

        $expectedKeys = [
            "'id'", "'text'", "'textLc'", "'lemma'", "'lemmaLc'",
            "'translation'", "'romanization'", "'status'", "'langId'",
            "'sentence'", "'notes'", "'tags'", "'statusLabel'"
        ];

        foreach ($expectedKeys as $key) {
            $this->assertStringContainsString(
                $key,
                $source,
                "getTermDetails should return key $key"
            );
        }
    }

    // =========================================================================
    // formatGetTermDetails tests
    // =========================================================================

    public function testFormatGetTermDetailsMethodSignature(): void
    {
        $method = new ReflectionMethod(TermCrudApiHandler::class, 'formatGetTermDetails');

        $this->assertTrue($method->isPublic());
        $this->assertSame('array', $method->getReturnType()?->getName());

        $params = $method->getParameters();
        $this->assertCount(2, $params);
        $this->assertSame('termId', $params[0]->getName());
        $this->assertSame('ann', $params[1]->getName());
    }

    public function testFormatGetTermDetailsDelegatesToGetTermDetails(): void
    {
        $source = $this->getMethodSource('formatGetTermDetails');

        $this->assertStringContainsString('getTermDetails(', $source);
    }

    // =========================================================================
    // getTermForEdit — method signature & source analysis
    // =========================================================================

    public function testGetTermForEditMethodSignature(): void
    {
        $method = new ReflectionMethod(TermCrudApiHandler::class, 'getTermForEdit');

        $this->assertTrue($method->isPublic());
        $this->assertSame('array', $method->getReturnType()?->getName());

        $params = $method->getParameters();
        $this->assertCount(3, $params);
        $this->assertSame('textId', $params[0]->getName());
        $this->assertSame('position', $params[1]->getName());
        $this->assertSame('wordId', $params[2]->getName());
        $this->assertTrue($params[2]->getType()?->allowsNull());
        $this->assertNull($params[2]->getDefaultValue());
    }

    public function testGetTermForEditReturnsTextNotFoundError(): void
    {
        $source = $this->getMethodSource('getTermForEdit');

        $this->assertStringContainsString("return ['error' => 'Text not found']", $source);
    }

    public function testCreateTermsBulkRejectsAnEmptyList(): void
    {
        $handler = new TermCrudApiHandler();

        $this->assertArrayHasKey('error', $handler->createTermsBulk([]));
        $this->assertArrayHasKey('error', $handler->createTermsBulk(['terms' => []]));
        $this->assertArrayHasKey('error', $handler->createTermsBulk(['terms' => 'nope']));
    }

    public function testCreateTermsBulkRejectsRowsWithoutTextOrLanguage(): void
    {
        $handler = new TermCrudApiHandler();

        // Every row is unusable, so nothing reaches the database.
        $result = $handler->createTermsBulk(['terms' => [
            ['text' => '', 'lg' => 3],
            ['text' => 'ok', 'lg' => 0],
            'not-an-array',
        ]]);

        $this->assertArrayHasKey('error', $result);
    }

    public function testCreateTermsBulkLinksNewTermsToTheirOccurrences(): void
    {
        $source = $this->getMethodSource('createTermsBulk');

        // Without this the reading view keeps rendering them as unknown.
        $this->assertStringContainsString('linkNewWordsToTextItems', $source);
    }

    public function testCreateTermsBulkValidatesStatus(): void
    {
        $source = $this->getMethodSource('createTermsBulk');

        $this->assertStringContainsString('TermStatusService::isValidStatus', $source);
    }

    public function testGetTermForEditResolvesLanguageFromWordWhenWordIdGiven(): void
    {
        $source = $this->getMethodSource('getTermForEdit');

        // An existing term supplies its own language, so no text lookup is
        // needed — that is what lets review and the term list open the editor.
        $this->assertStringContainsString('$hasWordId', $source);
        $this->assertStringContainsString("->select(['WoLgID'])", $source);
        $this->assertStringContainsString("\$langId = (int) \$wordLang['WoLgID'];", $source);
    }

    public function testGetTermForEditOnlyLooksUpTextForNewTerms(): void
    {
        $source = $this->getMethodSource('getTermForEdit');

        $textLookup = strpos($source, "QueryBuilder::table('texts')");
        $guard = strpos($source, 'if ($hasWordId) {');

        $this->assertNotFalse($textLookup);
        $this->assertNotFalse($guard);
        $this->assertGreaterThan($guard, $textLookup);
    }

    public function testGetTermForEditAdoptsATermThatAlreadyExists(): void
    {
        $source = $this->getMethodSource('getTermForEdit');

        // Issue #283: the caller passes no wordId when the reader thinks the
        // word is unknown, but the reader reads that from the occurrence table
        // and a dictionary-imported term is unlinked there. Resolving it by
        // (WoTextLC, WoLgID) is what routes the form to the update path
        // instead of a create that collides with the unique index.
        $this->assertStringContainsString('findTermIdByText', $source);
        $this->assertStringContainsString('$hasWordId = true;', $source);
    }

    public function testCreateTermFullDefersToUpdateWhenTheTermExists(): void
    {
        $source = $this->getMethodSource('createTermFull');

        // The same resolution guards the endpoint itself, for API clients and
        // for a form loaded before the term appeared.
        $this->assertStringContainsString('findTermIdByText', $source);
        $this->assertStringContainsString('return $this->updateTermFull($existingId, $data);', $source);
    }

    public function testUpdateTermFullLinksOccurrences(): void
    {
        $source = $this->getMethodSource('updateTermFull');

        // A term whose occurrences were never linked stays invisible to the
        // reader however often it is edited (#283).
        $this->assertStringContainsString('linkToTextItems', $source);
    }

    public function testGetTermForEditReturnsLanguageNotFoundError(): void
    {
        $source = $this->getMethodSource('getTermForEdit');

        $this->assertStringContainsString("return ['error' => 'Language not found']", $source);
    }

    public function testGetTermForEditReturnsTermNotFoundErrorForExisting(): void
    {
        $source = $this->getMethodSource('getTermForEdit');

        $this->assertStringContainsString("return ['error' => 'Term not found']", $source);
    }

    public function testGetTermForEditReturnsWordNotFoundAtPositionError(): void
    {
        $source = $this->getMethodSource('getTermForEdit');

        $this->assertStringContainsString("return ['error' => 'Word not found at position']", $source);
    }

    public function testGetTermForEditReturnsIsNewFalseForExistingTerm(): void
    {
        $source = $this->getMethodSource('getTermForEdit');

        $this->assertStringContainsString("'isNew' => false", $source);
    }

    public function testGetTermForEditReturnsIsNewTrueForNewTerm(): void
    {
        $source = $this->getMethodSource('getTermForEdit');

        $this->assertStringContainsString("'isNew' => true", $source);
    }

    public function testGetTermForEditQueriesTextsTable(): void
    {
        $source = $this->getMethodSource('getTermForEdit');

        $this->assertStringContainsString("table('texts')", $source);
        $this->assertStringContainsString("'TxLgID'", $source);
    }

    public function testGetTermForEditQueriesLanguagesTable(): void
    {
        $source = $this->getMethodSource('getTermForEdit');

        $this->assertStringContainsString("table('languages')", $source);
        $this->assertStringContainsString("'LgName'", $source);
    }

    public function testGetTermForEditCallsGetAllTermTags(): void
    {
        $source = $this->getMethodSource('getTermForEdit');

        $this->assertStringContainsString('TagsFacade::getAllTermTags()', $source);
    }

    public function testGetTermForEditCallsFindSimilarTerms(): void
    {
        $source = $this->getMethodSource('getTermForEdit');

        $this->assertStringContainsString('getSimilarTermsForEdit(', $source);
    }

    public function testGetTermForEditReturnsLanguageStructure(): void
    {
        $source = $this->getMethodSource('getTermForEdit');

        $this->assertStringContainsString("'id' => \$langId", $source);
        $this->assertStringContainsString("'name'", $source);
        $this->assertStringContainsString("'showRomanization'", $source);
        $this->assertStringContainsString("'translateUri'", $source);
    }

    public function testGetTermForEditNewTermHasDefaultStatus1(): void
    {
        $source = $this->getMethodSource('getTermForEdit');

        $this->assertStringContainsString("'status' => 1", $source);
    }

    public function testGetTermForEditUsesContextServiceForSentence(): void
    {
        $source = $this->getMethodSource('getTermForEdit');

        $this->assertStringContainsString('getSentenceTextAtPosition(', $source);
    }

    public function testGetTermForEditUsesLinkingServiceForWord(): void
    {
        $source = $this->getMethodSource('getTermForEdit');

        $this->assertStringContainsString('getWordAtPosition(', $source);
    }

    // =========================================================================
    // formatGetTermForEdit tests
    // =========================================================================

    public function testFormatGetTermForEditDelegates(): void
    {
        $source = $this->getMethodSource('formatGetTermForEdit');

        $this->assertStringContainsString('getTermForEdit(', $source);
    }

    // =========================================================================
    // createTermFull — validation branches (mockable, no static DB calls)
    // =========================================================================

    public function testCreateTermFullReturnsErrorWhenTextIdMissing(): void
    {
        $result = $this->handler->createTermFull([]);

        $this->assertArrayHasKey('error', $result);
        $this->assertSame('Text ID is required', $result['error']);
    }

    public function testCreateTermFullReturnsErrorWhenTextIdZero(): void
    {
        $result = $this->handler->createTermFull(['textId' => 0]);

        $this->assertArrayHasKey('error', $result);
        $this->assertSame('Text ID is required', $result['error']);
    }

    public function testCreateTermFullReturnsErrorWhenTextNotFound(): void
    {
        $this->contextService->method('getLanguageIdFromText')
            ->willReturn(null);

        $result = $this->handler->createTermFull(['textId' => 1]);

        $this->assertArrayHasKey('error', $result);
        $this->assertSame('Text not found', $result['error']);
    }

    public function testCreateTermFullReturnsErrorWhenWordNotFound(): void
    {
        $this->contextService->method('getLanguageIdFromText')
            ->willReturn(5);
        $this->linkingService->method('getWordAtPosition')
            ->willReturn(null);

        $result = $this->handler->createTermFull(['textId' => 1, 'position' => 3]);

        $this->assertArrayHasKey('error', $result);
        $this->assertSame('Word not found at position', $result['error']);
    }

    public function testCreateTermFullReturnsErrorForInvalidStatus(): void
    {
        $this->contextService->method('getLanguageIdFromText')
            ->willReturn(5);
        $this->linkingService->method('getWordAtPosition')
            ->willReturn('hello');

        $result = $this->handler->createTermFull([
            'textId' => 1,
            'position' => 3,
            'status' => 100
        ]);

        $this->assertArrayHasKey('error', $result);
        $this->assertSame('Status must be 1-5, 98, or 99', $result['error']);
    }

    public function testCreateTermFullReturnsErrorForStatus0(): void
    {
        $this->contextService->method('getLanguageIdFromText')
            ->willReturn(5);
        $this->linkingService->method('getWordAtPosition')
            ->willReturn('hello');

        $result = $this->handler->createTermFull([
            'textId' => 1,
            'position' => 3,
            'status' => 0
        ]);

        $this->assertArrayHasKey('error', $result);
        $this->assertSame('Status must be 1-5, 98, or 99', $result['error']);
    }

    public function testCreateTermFullReturnsErrorForStatus6(): void
    {
        $this->contextService->method('getLanguageIdFromText')
            ->willReturn(5);
        $this->linkingService->method('getWordAtPosition')
            ->willReturn('hello');

        $result = $this->handler->createTermFull([
            'textId' => 1,
            'position' => 3,
            'status' => 6
        ]);

        $this->assertArrayHasKey('error', $result);
        $this->assertSame('Status must be 1-5, 98, or 99', $result['error']);
    }

    public function testCreateTermFullCallsContextServiceWithTextId(): void
    {
        $this->contextService->expects($this->once())
            ->method('getLanguageIdFromText')
            ->with(42)
            ->willReturn(null);

        $this->handler->createTermFull(['textId' => 42]);
    }

    public function testCreateTermFullCallsLinkingServiceWithCorrectArgs(): void
    {
        $this->contextService->method('getLanguageIdFromText')
            ->willReturn(5);
        $this->linkingService->expects($this->once())
            ->method('getWordAtPosition')
            ->with(10, 7)
            ->willReturn(null);

        $this->handler->createTermFull(['textId' => 10, 'position' => 7]);
    }

    // =========================================================================
    // createTermFull — source analysis for DB operations
    // =========================================================================

    public function testCreateTermFullUsesInsertPreparedStatement(): void
    {
        $source = $this->getMethodSource('createTermFull');

        $this->assertStringContainsString('Connection::prepare(', $source);
        $this->assertStringContainsString('->bindValues(', $source);
        $this->assertStringContainsString('->execute()', $source);
    }

    public function testCreateTermFullUsesUserScopedQuery(): void
    {
        $source = $this->getMethodSource('createTermFull');

        $this->assertStringContainsString('UserScopedQuery::getUserIdForInsert(', $source);
    }

    public function testCreateTermFullUpdatesWordOccurrences(): void
    {
        $source = $this->getMethodSource('createTermFull');

        $this->assertStringContainsString('UPDATE word_occurrences', $source);
        $this->assertStringContainsString('Ti2WoID = ?', $source);
    }

    public function testCreateTermFullSavesTagsWhenProvided(): void
    {
        $source = $this->getMethodSource('createTermFull');

        $this->assertStringContainsString('TagsFacade::saveWordTagsFromArray(', $source);
    }

    public function testCreateTermFullReturnsSuccessStructure(): void
    {
        $source = $this->getMethodSource('createTermFull');

        $this->assertStringContainsString("'success' => true", $source);
        $this->assertStringContainsString("'term' =>", $source);
    }

    public function testCreateTermFullReturnsFailedToCreateOnZeroAffected(): void
    {
        $source = $this->getMethodSource('createTermFull');

        $this->assertStringContainsString("'error' => 'Failed to create term'", $source);
    }

    public function testCreateTermFullDefaultsTranslationToStar(): void
    {
        $source = $this->getMethodSource('createTermFull');

        $this->assertStringContainsString("\$translation = '*'", $source);
    }

    public function testCreateTermFullHandlesLemma(): void
    {
        $source = $this->getMethodSource('createTermFull');

        $this->assertStringContainsString('WoLemma', $source);
        $this->assertStringContainsString('WoLemmaLC', $source);
        $this->assertStringContainsString('mb_strtolower($lemma', $source);
    }

    // =========================================================================
    // updateTermFull — method signature & source analysis
    // =========================================================================

    public function testUpdateTermFullAcceptsOnlyARecasingOfTheTerm(): void
    {
        $source = $this->getMethodSource('updateTermFull');

        // WoTextLC is what textitems2 links on, so a real rename would orphan
        // every occurrence. Only the display casing may move.
        $this->assertStringContainsString(
            "mb_strtolower(\$candidate, 'UTF-8') !== \$textLc",
            $source
        );
        $this->assertStringContainsString('Term in lowercase must be exactly', $source);
    }

    public function testUpdateTermFullWritesTheTermText(): void
    {
        $source = $this->getMethodSource('updateTermFull');

        $this->assertStringContainsString('WoText = ?', $source);
    }

    public function testUpdateTermFullMethodSignature(): void
    {
        $method = new ReflectionMethod(TermCrudApiHandler::class, 'updateTermFull');

        $this->assertTrue($method->isPublic());
        $this->assertSame('array', $method->getReturnType()?->getName());

        $params = $method->getParameters();
        $this->assertCount(2, $params);
        $this->assertSame('termId', $params[0]->getName());
        $this->assertSame('int', $params[0]->getType()?->getName());
        $this->assertSame('data', $params[1]->getName());
        $this->assertSame('array', $params[1]->getType()?->getName());
    }

    public function testUpdateTermFullReturnsTermNotFoundError(): void
    {
        $source = $this->getMethodSource('updateTermFull');

        $this->assertStringContainsString("return ['error' => 'Term not found']", $source);
    }

    public function testUpdateTermFullReturnsInvalidStatusError(): void
    {
        $source = $this->getMethodSource('updateTermFull');

        $this->assertStringContainsString("return ['error' => 'Status must be 1-5, 98, or 99']", $source);
    }

    public function testUpdateTermFullUsesPreparedExecute(): void
    {
        $source = $this->getMethodSource('updateTermFull');

        $this->assertStringContainsString('Connection::preparedExecute(', $source);
    }

    public function testUpdateTermFullUsesUserScopedQuery(): void
    {
        $source = $this->getMethodSource('updateTermFull');

        $this->assertStringContainsString('UserScopedQuery::forTablePrepared(', $source);
    }

    public function testUpdateTermFullSavesTagsWhenProvided(): void
    {
        $source = $this->getMethodSource('updateTermFull');

        $this->assertStringContainsString('TagsFacade::saveWordTagsFromArray(', $source);
    }

    public function testUpdateTermFullReturnsSuccessWithTermData(): void
    {
        $source = $this->getMethodSource('updateTermFull');

        $this->assertStringContainsString("'success' => true", $source);
        $this->assertStringContainsString("'term' =>", $source);
    }

    public function testUpdateTermFullDefaultsTranslationToStar(): void
    {
        $source = $this->getMethodSource('updateTermFull');

        $this->assertStringContainsString("\$translation = '*'", $source);
    }

    public function testUpdateTermFullHandlesLemma(): void
    {
        $source = $this->getMethodSource('updateTermFull');

        $this->assertStringContainsString('WoLemma = ?', $source);
        $this->assertStringContainsString('WoLemmaLC = ?', $source);
    }

    // =========================================================================
    // formatCreateTermFull tests
    // =========================================================================

    public function testFormatCreateTermFullDelegates(): void
    {
        $source = $this->getMethodSource('formatCreateTermFull');

        $this->assertStringContainsString('createTermFull(', $source);
    }

    // =========================================================================
    // formatUpdateTermFull tests
    // =========================================================================

    public function testFormatUpdateTermFullDelegates(): void
    {
        $source = $this->getMethodSource('formatUpdateTermFull');

        $this->assertStringContainsString('updateTermFull(', $source);
    }

    // =========================================================================
    // getSimilarTermsForEdit — source analysis (private method)
    // =========================================================================

    public function testGetSimilarTermsForEditMethodExists(): void
    {
        $method = new ReflectionMethod(TermCrudApiHandler::class, 'getSimilarTermsForEdit');

        $this->assertTrue($method->isPrivate());
        $this->assertSame('array', $method->getReturnType()?->getName());

        $params = $method->getParameters();
        $this->assertCount(3, $params);
        $this->assertSame('langId', $params[0]->getName());
        $this->assertSame('termLc', $params[1]->getName());
        $this->assertSame('excludeId', $params[2]->getName());
    }

    public function testGetSimilarTermsForEditCallsFindSimilarTerms(): void
    {
        $source = $this->getMethodSource('getSimilarTermsForEdit');

        $this->assertStringContainsString('findSimilarTerms->execute(', $source);
    }

    public function testGetSimilarTermsForEditExcludesCurrentTerm(): void
    {
        $source = $this->getMethodSource('getSimilarTermsForEdit');

        $this->assertStringContainsString('$termId === $excludeId', $source);
    }

    // =========================================================================
    // Overall class structure tests
    // =========================================================================

    public function testClassHasAllExpectedPublicMethods(): void
    {
        $expectedMethods = [
            'getTerm', 'createTerm', 'updateTerm', 'deleteTerm', 'deleteTerms',
            'formatGetTerm', 'formatCreateTerm', 'formatUpdateTerm', 'formatDeleteTerm',
            'getTermDetails', 'formatGetTermDetails',
            'createQuickTerm', 'formatQuickCreate',
            'getTermForEdit', 'formatGetTermForEdit',
            'createTermFull', 'formatCreateTermFull',
            'updateTermFull', 'formatUpdateTermFull'
        ];

        foreach ($expectedMethods as $method) {
            $this->assertTrue(
                method_exists(TermCrudApiHandler::class, $method),
                "TermCrudApiHandler should have public method $method"
            );
        }
    }

    public function testAllPublicMethodsReturnArray(): void
    {
        $publicMethods = [
            'getTerm', 'createTerm', 'updateTerm', 'deleteTerm', 'deleteTerms',
            'formatGetTerm', 'formatCreateTerm', 'formatUpdateTerm', 'formatDeleteTerm',
            'getTermDetails', 'formatGetTermDetails',
            'createQuickTerm', 'formatQuickCreate',
            'getTermForEdit', 'formatGetTermForEdit',
            'createTermFull', 'formatCreateTermFull',
            'updateTermFull', 'formatUpdateTermFull'
        ];

        foreach ($publicMethods as $methodName) {
            $method = new ReflectionMethod(TermCrudApiHandler::class, $methodName);
            $this->assertSame(
                'array',
                $method->getReturnType()?->getName(),
                "$methodName should return array"
            );
        }
    }

    // =========================================================================
    // Helper methods
    // =========================================================================

    /**
     * Create a mock Term object.
     *
     * @param int    $id          Term ID
     * @param string $text        Term text
     * @param string $translation Translation
     * @param int    $status      Status
     * @param int    $wordCount   Word count
     *
     * @return Term&MockObject
     */
    private function createMockTerm(
        int $id,
        string $text,
        string $translation,
        int $status,
        int $wordCount = 1
    ): Term {
        // Use real value objects since they are final readonly
        $termId = TermId::fromInt($id);
        $termStatus = TermStatus::fromInt($status);
        $languageId = LanguageId::fromInt(1);

        $term = $this->createMock(Term::class);
        $term->method('id')->willReturn($termId);
        $term->method('text')->willReturn($text);
        $term->method('textLowercase')->willReturn(strtolower($text));
        $term->method('lemma')->willReturn('');
        $term->method('lemmaLc')->willReturn('');
        $term->method('translation')->willReturn($translation);
        $term->method('romanization')->willReturn('');
        $term->method('sentence')->willReturn('');
        $term->method('status')->willReturn($termStatus);
        $term->method('languageId')->willReturn($languageId);
        $term->method('wordCount')->willReturn($wordCount);

        return $term;
    }

    /**
     * Get source code of a method for source-level analysis.
     */
    private function getMethodSource(string $methodName): string
    {
        $method = new ReflectionMethod(TermCrudApiHandler::class, $methodName);
        $filename = $method->getFileName();
        $startLine = $method->getStartLine();
        $endLine = $method->getEndLine();

        $source = file_get_contents($filename);
        $lines = explode("\n", $source);

        return implode("\n", array_slice($lines, $startLine - 1, $endLine - $startLine + 1));
    }
}
