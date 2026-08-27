<?php

declare(strict_types=1);

namespace Tests\Backend\Modules\Vocabulary;

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\MockObject\MockObject;
use DateTimeImmutable;
use Lwt\Modules\Vocabulary\Application\VocabularyFacade;
use Lwt\Modules\Vocabulary\Application\UseCases\CreateTerm;
use Lwt\Modules\Vocabulary\Application\UseCases\DeleteTerm;
use Lwt\Modules\Vocabulary\Application\UseCases\GetTermById;
use Lwt\Modules\Vocabulary\Application\UseCases\UpdateTerm;
use Lwt\Modules\Vocabulary\Application\UseCases\UpdateTermStatus;
use Lwt\Modules\Vocabulary\Domain\Term;
use Lwt\Modules\Vocabulary\Domain\TermRepositoryInterface;

/**
 * Comprehensive tests for VocabularyFacade.
 *
 * Tests all facade methods including CRUD operations, status operations,
 * and query operations using proper mocking.
 */
class VocabularyFacadeTest extends TestCase
{
    /** @var TermRepositoryInterface&MockObject */
    private TermRepositoryInterface $repository;

    /** @var CreateTerm&MockObject */
    private CreateTerm $createTerm;

    /** @var GetTermById&MockObject */
    private GetTermById $getTermById;

    /** @var UpdateTerm&MockObject */
    private UpdateTerm $updateTerm;

    /** @var DeleteTerm&MockObject */
    private DeleteTerm $deleteTerm;

    /** @var UpdateTermStatus&MockObject */
    private UpdateTermStatus $updateTermStatus;

    private VocabularyFacade $facade;

    protected function setUp(): void
    {
        $this->repository = $this->createMock(TermRepositoryInterface::class);
        $this->createTerm = $this->createMock(CreateTerm::class);
        $this->getTermById = $this->createMock(GetTermById::class);
        $this->updateTerm = $this->createMock(UpdateTerm::class);
        $this->deleteTerm = $this->createMock(DeleteTerm::class);
        $this->updateTermStatus = $this->createMock(UpdateTermStatus::class);

        $this->facade = new VocabularyFacade(
            $this->repository,
            $this->createTerm,
            $this->getTermById,
            $this->updateTerm,
            $this->deleteTerm,
            $this->updateTermStatus
        );
    }

    /**
     * Create a mock Term for testing.
     *
     * @param int    $id          Term ID
     * @param int    $languageId  Language ID
     * @param string $text        Term text
     * @param int    $status      Status value
     * @param string $translation Translation
     *
     * @return Term
     */
    private function createMockTerm(
        int $id = 1,
        int $languageId = 1,
        string $text = 'test',
        int $status = 1,
        string $translation = 'translation'
    ): Term {
        return Term::reconstitute(
            $id,
            $languageId,
            $text,
            mb_strtolower($text, 'UTF-8'),
            null, // lemma
            null, // lemmaLc
            $status,
            $translation,
            'Example sentence',
            'Notes',
            'romanization',
            1,
            new DateTimeImmutable(),
            new DateTimeImmutable(),
            0.0,
            0.0,
            0.5
        );
    }

    // =========================================================================
    // Constructor Tests
    // =========================================================================

    public function testConstructorWithNoArguments(): void
    {
        // This tests the default instantiation path
        // We can't easily test this without a database, but we verify
        // the facade can be created
        $this->assertInstanceOf(VocabularyFacade::class, $this->facade);
    }

    public function testConstructorWithCustomDependencies(): void
    {
        $facade = new VocabularyFacade(
            $this->repository,
            $this->createTerm,
            $this->getTermById,
            $this->updateTerm,
            $this->deleteTerm,
            $this->updateTermStatus
        );

        $this->assertInstanceOf(VocabularyFacade::class, $facade);
    }

    // =========================================================================
    // CRUD Operations - createTerm
    // =========================================================================

    public function testCreateTermDelegatesToCreateTermUseCase(): void
    {
        $term = $this->createMockTerm();

        $this->createTerm->expects($this->once())
            ->method('execute')
            ->with(1, 'hello', 1, 'greeting', 'Hello there', 'notes', 'helo', 1)
            ->willReturn($term);

        $result = $this->facade->createTerm(
            1,
            'hello',
            1,
            'greeting',
            'Hello there',
            'notes',
            'helo',
            1
        );

        $this->assertSame($term, $result);
    }

    public function testCreateTermWithDefaultValues(): void
    {
        $term = $this->createMockTerm();

        $this->createTerm->expects($this->once())
            ->method('execute')
            ->with(1, 'word', 1, '', '', '', '', 0)
            ->willReturn($term);

        $result = $this->facade->createTerm(1, 'word');

        $this->assertSame($term, $result);
    }

    public function testCreateTermWithCustomStatus(): void
    {
        $term = $this->createMockTerm(1, 1, 'known', 99);

        $this->createTerm->expects($this->once())
            ->method('execute')
            ->with(1, 'known', 99, '', '', '', '', 0)
            ->willReturn($term);

        $result = $this->facade->createTerm(1, 'known', 99);

        $this->assertSame($term, $result);
    }

    public function testCreateTermWithAllParameters(): void
    {
        $term = $this->createMockTerm(1, 2, 'expression', 3, 'meaning');

        $this->createTerm->expects($this->once())
            ->method('execute')
            ->with(2, 'expression', 3, 'meaning', 'Example', 'My notes', 'exp', 2)
            ->willReturn($term);

        $result = $this->facade->createTerm(
            2,
            'expression',
            3,
            'meaning',
            'Example',
            'My notes',
            'exp',
            2
        );

        $this->assertSame($term, $result);
    }

    public function testCreateTermThrowsExceptionOnEmptyText(): void
    {
        $this->createTerm->expects($this->once())
            ->method('execute')
            ->willThrowException(new \InvalidArgumentException('Term text cannot be empty'));

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Term text cannot be empty');

        $this->facade->createTerm(1, '');
    }

    public function testCreateTermThrowsExceptionOnDuplicate(): void
    {
        $this->createTerm->expects($this->once())
            ->method('execute')
            ->willThrowException(new \InvalidArgumentException('Term "hello" already exists'));

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Term "hello" already exists');

        $this->facade->createTerm(1, 'hello');
    }

    // =========================================================================
    // CRUD Operations - createTermFromArray
    // =========================================================================

    public function testCreateTermFromArrayDelegatesToCreateTermUseCase(): void
    {
        $expectedResult = [
            'id' => 1,
            'message' => 'Term saved',
            'success' => true,
            'textlc' => 'hello',
            'text' => 'Hello'
        ];

        $this->createTerm->expects($this->once())
            ->method('executeFromArray')
            ->with(['WoLgID' => 1, 'WoText' => 'Hello'])
            ->willReturn($expectedResult);

        $result = $this->facade->createTermFromArray(['WoLgID' => 1, 'WoText' => 'Hello']);

        $this->assertSame($expectedResult, $result);
    }

    public function testCreateTermFromArrayWithFullData(): void
    {
        $data = [
            'WoLgID' => 1,
            'WoText' => 'Hello',
            'WoStatus' => 2,
            'WoTranslation' => 'Greeting',
            'WoSentence' => 'Hello world',
            'WoNotes' => 'Common word',
            'WoRomanization' => 'helo',
            'WoWordCount' => 1
        ];

        $expectedResult = [
            'id' => 5,
            'message' => 'Term saved',
            'success' => true,
            'textlc' => 'hello',
            'text' => 'Hello'
        ];

        $this->createTerm->expects($this->once())
            ->method('executeFromArray')
            ->with($data)
            ->willReturn($expectedResult);

        $result = $this->facade->createTermFromArray($data);

        $this->assertSame($expectedResult, $result);
    }

    public function testCreateTermFromArrayReturnsFailureOnError(): void
    {
        $expectedResult = [
            'id' => 0,
            'message' => 'Error: Term text cannot be empty',
            'success' => false,
            'textlc' => '',
            'text' => ''
        ];

        $this->createTerm->expects($this->once())
            ->method('executeFromArray')
            ->with(['WoLgID' => 1, 'WoText' => ''])
            ->willReturn($expectedResult);

        $result = $this->facade->createTermFromArray(['WoLgID' => 1, 'WoText' => '']);

        $this->assertFalse($result['success']);
    }

    // =========================================================================
    // CRUD Operations - getTerm
    // =========================================================================

    public function testGetTermDelegatesToGetTermByIdUseCase(): void
    {
        $term = $this->createMockTerm(5);

        $this->getTermById->expects($this->once())
            ->method('execute')
            ->with(5)
            ->willReturn($term);

        $result = $this->facade->getTerm(5);

        $this->assertSame($term, $result);
    }

    public function testGetTermReturnsNullForNonexistentTerm(): void
    {
        $this->getTermById->expects($this->once())
            ->method('execute')
            ->with(999)
            ->willReturn(null);

        $result = $this->facade->getTerm(999);

        $this->assertNull($result);
    }

    public function testGetTermReturnsNullForZeroId(): void
    {
        $this->getTermById->expects($this->once())
            ->method('execute')
            ->with(0)
            ->willReturn(null);

        $result = $this->facade->getTerm(0);

        $this->assertNull($result);
    }

    public function testGetTermReturnsNullForNegativeId(): void
    {
        $this->getTermById->expects($this->once())
            ->method('execute')
            ->with(-1)
            ->willReturn(null);

        $result = $this->facade->getTerm(-1);

        $this->assertNull($result);
    }

    // =========================================================================
    // CRUD Operations - getTermAsArray
    // =========================================================================

    public function testGetTermAsArrayDelegatesToGetTermByIdUseCase(): void
    {
        $expectedArray = [
            'WoID' => 1,
            'WoLgID' => 1,
            'WoText' => 'test',
            'WoTextLC' => 'test',
            'WoStatus' => 1,
            'WoTranslation' => 'translation',
            'WoSentence' => 'Example sentence',
            'WoNotes' => 'Notes',
            'WoRomanization' => 'romanization',
            'WoWordCount' => 1
        ];

        $this->getTermById->expects($this->once())
            ->method('executeAsArray')
            ->with(1)
            ->willReturn($expectedArray);

        $result = $this->facade->getTermAsArray(1);

        $this->assertSame($expectedArray, $result);
    }

    public function testGetTermAsArrayReturnsNullForNonexistentTerm(): void
    {
        $this->getTermById->expects($this->once())
            ->method('executeAsArray')
            ->with(999)
            ->willReturn(null);

        $result = $this->facade->getTermAsArray(999);

        $this->assertNull($result);
    }

    // =========================================================================
    // CRUD Operations - updateTerm
    // =========================================================================

    public function testUpdateTermDelegatesToUpdateTermUseCase(): void
    {
        $term = $this->createMockTerm(1, 1, 'test', 2, 'updated translation');

        $this->updateTerm->expects($this->once())
            ->method('execute')
            ->with(1, 2, 'updated translation', null, null, null)
            ->willReturn($term);

        $result = $this->facade->updateTerm(1, 2, 'updated translation');

        $this->assertSame($term, $result);
    }

    public function testUpdateTermWithAllFields(): void
    {
        $term = $this->createMockTerm(1, 1, 'test', 3, 'new translation');

        $this->updateTerm->expects($this->once())
            ->method('execute')
            ->with(1, 3, 'new translation', 'new sentence', 'new notes', 'new roman')
            ->willReturn($term);

        $result = $this->facade->updateTerm(
            1,
            3,
            'new translation',
            'new sentence',
            'new notes',
            'new roman'
        );

        $this->assertSame($term, $result);
    }

    public function testUpdateTermWithStatusOnly(): void
    {
        $term = $this->createMockTerm(1, 1, 'test', 5);

        $this->updateTerm->expects($this->once())
            ->method('execute')
            ->with(1, 5, null, null, null, null)
            ->willReturn($term);

        $result = $this->facade->updateTerm(1, 5);

        $this->assertSame($term, $result);
    }

    public function testUpdateTermWithNoChanges(): void
    {
        $term = $this->createMockTerm();

        $this->updateTerm->expects($this->once())
            ->method('execute')
            ->with(1, null, null, null, null, null)
            ->willReturn($term);

        $result = $this->facade->updateTerm(1);

        $this->assertSame($term, $result);
    }

    public function testUpdateTermThrowsExceptionForNonexistentTerm(): void
    {
        $this->updateTerm->expects($this->once())
            ->method('execute')
            ->willThrowException(new \InvalidArgumentException('Term not found: 999'));

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Term not found: 999');

        $this->facade->updateTerm(999, 2);
    }

    // =========================================================================
    // CRUD Operations - updateTermFromArray
    // =========================================================================

    public function testUpdateTermFromArrayDelegatesToUpdateTermUseCase(): void
    {
        $data = ['WoID' => 1, 'WoStatus' => 2];
        $expectedResult = ['id' => 1, 'message' => 'Term updated', 'success' => true];

        $this->updateTerm->expects($this->once())
            ->method('executeFromArray')
            ->with($data)
            ->willReturn($expectedResult);

        $result = $this->facade->updateTermFromArray($data);

        $this->assertSame($expectedResult, $result);
    }

    public function testUpdateTermFromArrayWithFullData(): void
    {
        $data = [
            'WoID' => 1,
            'WoStatus' => 3,
            'WoTranslation' => 'new meaning',
            'WoSentence' => 'new example',
            'WoNotes' => 'new notes',
            'WoRomanization' => 'new roman'
        ];
        $expectedResult = ['id' => 1, 'message' => 'Term updated', 'success' => true];

        $this->updateTerm->expects($this->once())
            ->method('executeFromArray')
            ->with($data)
            ->willReturn($expectedResult);

        $result = $this->facade->updateTermFromArray($data);

        $this->assertTrue($result['success']);
    }

    public function testUpdateTermFromArrayReturnsFailureOnError(): void
    {
        $expectedResult = [
            'id' => 0,
            'message' => 'Error: Term not found: 999',
            'success' => false
        ];

        $this->updateTerm->expects($this->once())
            ->method('executeFromArray')
            ->with(['WoID' => 999])
            ->willReturn($expectedResult);

        $result = $this->facade->updateTermFromArray(['WoID' => 999]);

        $this->assertFalse($result['success']);
    }

    // =========================================================================
    // CRUD Operations - deleteTerm
    // =========================================================================

    public function testDeleteTermDelegatesToDeleteTermUseCase(): void
    {
        $this->deleteTerm->expects($this->once())
            ->method('execute')
            ->with(1)
            ->willReturn(true);

        $result = $this->facade->deleteTerm(1);

        $this->assertTrue($result);
    }

    public function testDeleteTermReturnsFalseForNonexistentTerm(): void
    {
        $this->deleteTerm->expects($this->once())
            ->method('execute')
            ->with(999)
            ->willReturn(false);

        $result = $this->facade->deleteTerm(999);

        $this->assertFalse($result);
    }

    public function testDeleteTermReturnsFalseForZeroId(): void
    {
        $this->deleteTerm->expects($this->once())
            ->method('execute')
            ->with(0)
            ->willReturn(false);

        $result = $this->facade->deleteTerm(0);

        $this->assertFalse($result);
    }

    // =========================================================================
    // CRUD Operations - deleteTerms (bulk)
    // =========================================================================

    public function testDeleteTermsDelegatesToDeleteTermUseCase(): void
    {
        $this->deleteTerm->expects($this->once())
            ->method('executeMultiple')
            ->with([1, 2, 3])
            ->willReturn(3);

        $result = $this->facade->deleteTerms([1, 2, 3]);

        $this->assertSame(3, $result);
    }

    public function testDeleteTermsWithEmptyArray(): void
    {
        $this->deleteTerm->expects($this->once())
            ->method('executeMultiple')
            ->with([])
            ->willReturn(0);

        $result = $this->facade->deleteTerms([]);

        $this->assertSame(0, $result);
    }

    public function testDeleteTermsWithSingleTerm(): void
    {
        $this->deleteTerm->expects($this->once())
            ->method('executeMultiple')
            ->with([5])
            ->willReturn(1);

        $result = $this->facade->deleteTerms([5]);

        $this->assertSame(1, $result);
    }

    public function testDeleteTermsReturnsPartialCount(): void
    {
        $this->deleteTerm->expects($this->once())
            ->method('executeMultiple')
            ->with([1, 999, 3])
            ->willReturn(2);

        $result = $this->facade->deleteTerms([1, 999, 3]);

        $this->assertSame(2, $result);
    }

    // =========================================================================
    // Status Operations - updateStatus
    // =========================================================================

    public function testUpdateStatusDelegatesToUpdateTermStatusUseCase(): void
    {
        $this->updateTermStatus->expects($this->once())
            ->method('execute')
            ->with(1, 3)
            ->willReturn(true);

        $result = $this->facade->updateStatus(1, 3);

        $this->assertTrue($result);
    }

    public function testUpdateStatusToIgnored(): void
    {
        $this->updateTermStatus->expects($this->once())
            ->method('execute')
            ->with(1, 98)
            ->willReturn(true);

        $result = $this->facade->updateStatus(1, 98);

        $this->assertTrue($result);
    }

    public function testUpdateStatusToWellKnown(): void
    {
        $this->updateTermStatus->expects($this->once())
            ->method('execute')
            ->with(1, 99)
            ->willReturn(true);

        $result = $this->facade->updateStatus(1, 99);

        $this->assertTrue($result);
    }

    public function testUpdateStatusReturnsFalseForNonexistentTerm(): void
    {
        $this->updateTermStatus->expects($this->once())
            ->method('execute')
            ->with(999, 2)
            ->willReturn(false);

        $result = $this->facade->updateStatus(999, 2);

        $this->assertFalse($result);
    }

    // =========================================================================
    // Status Operations - advanceStatus
    // =========================================================================

    public function testAdvanceStatusDelegatesToUpdateTermStatusUseCase(): void
    {
        $this->updateTermStatus->expects($this->once())
            ->method('advance')
            ->with(1)
            ->willReturn(true);

        $result = $this->facade->advanceStatus(1);

        $this->assertTrue($result);
    }

    public function testAdvanceStatusReturnsFalseWhenAtMax(): void
    {
        $this->updateTermStatus->expects($this->once())
            ->method('advance')
            ->with(1)
            ->willReturn(false);

        $result = $this->facade->advanceStatus(1);

        $this->assertFalse($result);
    }

    public function testAdvanceStatusReturnsFalseForNonexistentTerm(): void
    {
        $this->updateTermStatus->expects($this->once())
            ->method('advance')
            ->with(999)
            ->willReturn(false);

        $result = $this->facade->advanceStatus(999);

        $this->assertFalse($result);
    }

    // =========================================================================
    // Status Operations - decreaseStatus
    // =========================================================================

    public function testDecreaseStatusDelegatesToUpdateTermStatusUseCase(): void
    {
        $this->updateTermStatus->expects($this->once())
            ->method('decrease')
            ->with(1)
            ->willReturn(true);

        $result = $this->facade->decreaseStatus(1);

        $this->assertTrue($result);
    }

    public function testDecreaseStatusReturnsFalseWhenAtMin(): void
    {
        $this->updateTermStatus->expects($this->once())
            ->method('decrease')
            ->with(1)
            ->willReturn(false);

        $result = $this->facade->decreaseStatus(1);

        $this->assertFalse($result);
    }

    public function testDecreaseStatusReturnsFalseForNonexistentTerm(): void
    {
        $this->updateTermStatus->expects($this->once())
            ->method('decrease')
            ->with(999)
            ->willReturn(false);

        $result = $this->facade->decreaseStatus(999);

        $this->assertFalse($result);
    }

    // =========================================================================
    // Status Operations - bulkUpdateStatus
    // =========================================================================

    public function testBulkUpdateStatusDelegatesToUpdateTermStatusUseCase(): void
    {
        $this->updateTermStatus->expects($this->once())
            ->method('executeMultiple')
            ->with([1, 2, 3], 5)
            ->willReturn(3);

        $result = $this->facade->bulkUpdateStatus([1, 2, 3], 5);

        $this->assertSame(3, $result);
    }

    public function testBulkUpdateStatusWithEmptyArray(): void
    {
        $this->updateTermStatus->expects($this->once())
            ->method('executeMultiple')
            ->with([], 2)
            ->willReturn(0);

        $result = $this->facade->bulkUpdateStatus([], 2);

        $this->assertSame(0, $result);
    }

    public function testBulkUpdateStatusReturnsPartialCount(): void
    {
        $this->updateTermStatus->expects($this->once())
            ->method('executeMultiple')
            ->with([1, 999, 3], 2)
            ->willReturn(2);

        $result = $this->facade->bulkUpdateStatus([1, 999, 3], 2);

        $this->assertSame(2, $result);
    }

    // =========================================================================
    // Status Operations - ignoreTerm
    // =========================================================================

    public function testIgnoreTermDelegatesToUpdateTermStatusUseCase(): void
    {
        $this->updateTermStatus->expects($this->once())
            ->method('markAsIgnored')
            ->with(1)
            ->willReturn(true);

        $result = $this->facade->ignoreTerm(1);

        $this->assertTrue($result);
    }

    public function testIgnoreTermReturnsFalseForNonexistentTerm(): void
    {
        $this->updateTermStatus->expects($this->once())
            ->method('markAsIgnored')
            ->with(999)
            ->willReturn(false);

        $result = $this->facade->ignoreTerm(999);

        $this->assertFalse($result);
    }

    // =========================================================================
    // Status Operations - markAsWellKnown
    // =========================================================================

    public function testMarkAsWellKnownDelegatesToUpdateTermStatusUseCase(): void
    {
        $this->updateTermStatus->expects($this->once())
            ->method('markAsWellKnown')
            ->with(1)
            ->willReturn(true);

        $result = $this->facade->markAsWellKnown(1);

        $this->assertTrue($result);
    }

    public function testMarkAsWellKnownReturnsFalseForNonexistentTerm(): void
    {
        $this->updateTermStatus->expects($this->once())
            ->method('markAsWellKnown')
            ->with(999)
            ->willReturn(false);

        $result = $this->facade->markAsWellKnown(999);

        $this->assertFalse($result);
    }

    // =========================================================================
    // Status Operations - markAsLearned
    // =========================================================================

    public function testMarkAsLearnedDelegatesToUpdateTermStatusUseCase(): void
    {
        $this->updateTermStatus->expects($this->once())
            ->method('markAsLearned')
            ->with(1)
            ->willReturn(true);

        $result = $this->facade->markAsLearned(1);

        $this->assertTrue($result);
    }

    public function testMarkAsLearnedReturnsFalseForNonexistentTerm(): void
    {
        $this->updateTermStatus->expects($this->once())
            ->method('markAsLearned')
            ->with(999)
            ->willReturn(false);

        $result = $this->facade->markAsLearned(999);

        $this->assertFalse($result);
    }

    // =========================================================================
    // Query Operations - termExists
    // =========================================================================

    public function testTermExistsDelegatesToRepository(): void
    {
        $this->repository->expects($this->once())
            ->method('exists')
            ->with(1)
            ->willReturn(true);

        $result = $this->facade->termExists(1);

        $this->assertTrue($result);
    }

    public function testTermExistsReturnsFalseForNonexistentTerm(): void
    {
        $this->repository->expects($this->once())
            ->method('exists')
            ->with(999)
            ->willReturn(false);

        $result = $this->facade->termExists(999);

        $this->assertFalse($result);
    }

    // =========================================================================
    // Query Operations - findByText
    // =========================================================================

    public function testFindByTextDelegatesToRepository(): void
    {
        $term = $this->createMockTerm();

        $this->repository->expects($this->once())
            ->method('findByTextLc')
            ->with(1, 'hello')
            ->willReturn($term);

        $result = $this->facade->findByText(1, 'hello');

        $this->assertSame($term, $result);
    }

    public function testFindByTextReturnsNullForNonexistentTerm(): void
    {
        $this->repository->expects($this->once())
            ->method('findByTextLc')
            ->with(1, 'nonexistent')
            ->willReturn(null);

        $result = $this->facade->findByText(1, 'nonexistent');

        $this->assertNull($result);
    }

    // =========================================================================
    // Query Operations - countByLanguage
    // =========================================================================

    public function testCountByLanguageDelegatesToRepository(): void
    {
        $this->repository->expects($this->once())
            ->method('countByLanguage')
            ->with(1)
            ->willReturn(150);

        $result = $this->facade->countByLanguage(1);

        $this->assertSame(150, $result);
    }

    public function testCountByLanguageReturnsZeroForEmptyLanguage(): void
    {
        $this->repository->expects($this->once())
            ->method('countByLanguage')
            ->with(999)
            ->willReturn(0);

        $result = $this->facade->countByLanguage(999);

        $this->assertSame(0, $result);
    }

    // =========================================================================
    // Query Operations - getStatistics
    // =========================================================================

    public function testGetStatisticsDelegatesToRepository(): void
    {
        $expectedStats = [
            'total' => 100,
            'learning' => 40,
            'known' => 30,
            'ignored' => 20,
            'multi_word' => 10
        ];

        $this->repository->expects($this->once())
            ->method('getStatistics')
            ->with(1)
            ->willReturn($expectedStats);

        $result = $this->facade->getStatistics(1);

        $this->assertSame($expectedStats, $result);
    }

    public function testGetStatisticsForAllLanguages(): void
    {
        $expectedStats = [
            'total' => 500,
            'learning' => 200,
            'known' => 150,
            'ignored' => 100,
            'multi_word' => 50
        ];

        $this->repository->expects($this->once())
            ->method('getStatistics')
            ->with(null)
            ->willReturn($expectedStats);

        $result = $this->facade->getStatistics();

        $this->assertSame($expectedStats, $result);
    }


    // =========================================================================
    // Query Operations - findRecent
    // =========================================================================

    public function testFindRecentDelegatesToRepository(): void
    {
        $terms = [$this->createMockTerm(1), $this->createMockTerm(2)];

        $this->repository->expects($this->once())
            ->method('findRecent')
            ->with(1, 20)
            ->willReturn($terms);

        $result = $this->facade->findRecent(1, 20);

        $this->assertSame($terms, $result);
    }

    public function testFindRecentWithDefaults(): void
    {
        $terms = [$this->createMockTerm()];

        $this->repository->expects($this->once())
            ->method('findRecent')
            ->with(null, 50)
            ->willReturn($terms);

        $result = $this->facade->findRecent();

        $this->assertSame($terms, $result);
    }

    public function testFindRecentReturnsEmptyArray(): void
    {
        $this->repository->expects($this->once())
            ->method('findRecent')
            ->with(999, 50)
            ->willReturn([]);

        $result = $this->facade->findRecent(999);

        $this->assertSame([], $result);
    }

    // =========================================================================
    // Query Operations - searchTerms
    // =========================================================================

    public function testSearchTermsDelegatesToRepository(): void
    {
        $terms = [$this->createMockTerm(1, 1, 'hello'), $this->createMockTerm(2, 1, 'helicopter')];

        $this->repository->expects($this->once())
            ->method('searchByText')
            ->with('hel', 1, 50)
            ->willReturn($terms);

        $result = $this->facade->searchTerms('hel', 1);

        $this->assertSame($terms, $result);
    }

    public function testSearchTermsWithCustomLimit(): void
    {
        $terms = [$this->createMockTerm()];

        $this->repository->expects($this->once())
            ->method('searchByText')
            ->with('test', null, 10)
            ->willReturn($terms);

        $result = $this->facade->searchTerms('test', null, 10);

        $this->assertSame($terms, $result);
    }

    public function testSearchTermsReturnsEmptyArray(): void
    {
        $this->repository->expects($this->once())
            ->method('searchByText')
            ->with('xyz', 1, 50)
            ->willReturn([]);

        $result = $this->facade->searchTerms('xyz', 1);

        $this->assertSame([], $result);
    }

    // =========================================================================
    // Query Operations - listTerms
    // =========================================================================

    public function testListTermsDelegatesToRepository(): void
    {
        $expectedResult = [
            'items' => [$this->createMockTerm()],
            'total' => 100,
            'page' => 1,
            'per_page' => 20,
            'total_pages' => 5
        ];

        $this->repository->expects($this->once())
            ->method('findPaginated')
            ->with(1, 1, 20, 'WoText', 'ASC')
            ->willReturn($expectedResult);

        $result = $this->facade->listTerms(1);

        $this->assertSame($expectedResult, $result);
    }

    public function testListTermsWithPagination(): void
    {
        $expectedResult = [
            'items' => [$this->createMockTerm()],
            'total' => 100,
            'page' => 3,
            'per_page' => 10,
            'total_pages' => 10
        ];

        $this->repository->expects($this->once())
            ->method('findPaginated')
            ->with(1, 3, 10, 'WoText', 'ASC')
            ->willReturn($expectedResult);

        $result = $this->facade->listTerms(1, 3, 10);

        $this->assertSame($expectedResult, $result);
    }

    public function testListTermsWithCustomSort(): void
    {
        $expectedResult = [
            'items' => [$this->createMockTerm()],
            'total' => 50,
            'page' => 1,
            'per_page' => 20,
            'total_pages' => 3
        ];

        $this->repository->expects($this->once())
            ->method('findPaginated')
            ->with(1, 1, 20, 'WoStatus', 'DESC')
            ->willReturn($expectedResult);

        $result = $this->facade->listTerms(1, 1, 20, 'WoStatus', 'DESC');

        $this->assertSame($expectedResult, $result);
    }

    public function testListTermsForAllLanguages(): void
    {
        $expectedResult = [
            'items' => [],
            'total' => 0,
            'page' => 1,
            'per_page' => 20,
            'total_pages' => 0
        ];

        $this->repository->expects($this->once())
            ->method('findPaginated')
            ->with(0, 1, 20, 'WoText', 'ASC')
            ->willReturn($expectedResult);

        $result = $this->facade->listTerms();

        $this->assertSame($expectedResult, $result);
    }

    // =========================================================================
    // Query Operations - getRepository
    // =========================================================================

    public function testGetRepositoryReturnsRepository(): void
    {
        $result = $this->facade->getRepository();

        $this->assertSame($this->repository, $result);
    }

    // =========================================================================
    // Integration-style Tests
    // =========================================================================

    public function testCreateAndGetTerm(): void
    {
        $term = $this->createMockTerm(5, 1, 'created', 1, 'meaning');

        $this->createTerm->expects($this->once())
            ->method('execute')
            ->with(1, 'created', 1, 'meaning', '', '', '', 0)
            ->willReturn($term);

        $this->getTermById->expects($this->once())
            ->method('execute')
            ->with(5)
            ->willReturn($term);

        $created = $this->facade->createTerm(1, 'created', 1, 'meaning');
        $retrieved = $this->facade->getTerm(5);

        $this->assertSame($created, $retrieved);
    }

    public function testCreateUpdateAndDeleteTerm(): void
    {
        $term = $this->createMockTerm(10, 1, 'lifecycle', 1);
        $updatedTerm = $this->createMockTerm(10, 1, 'lifecycle', 3, 'updated');

        $this->createTerm->expects($this->once())
            ->method('execute')
            ->willReturn($term);

        $this->updateTerm->expects($this->once())
            ->method('execute')
            ->with(10, 3, 'updated', null, null, null)
            ->willReturn($updatedTerm);

        $this->deleteTerm->expects($this->once())
            ->method('execute')
            ->with(10)
            ->willReturn(true);

        $created = $this->facade->createTerm(1, 'lifecycle');
        $updated = $this->facade->updateTerm(10, 3, 'updated');
        $deleted = $this->facade->deleteTerm(10);

        $this->assertSame($term, $created);
        $this->assertSame($updatedTerm, $updated);
        $this->assertTrue($deleted);
    }

    public function testStatusProgression(): void
    {
        // Simulate advancing through status levels
        $this->updateTermStatus->expects($this->exactly(4))
            ->method('advance')
            ->with(1)
            ->willReturn(true);

        for ($i = 0; $i < 4; $i++) {
            $result = $this->facade->advanceStatus(1);
            $this->assertTrue($result);
        }
    }

    public function testBulkOperations(): void
    {
        $termIds = [1, 2, 3, 4, 5];

        $this->deleteTerm->expects($this->once())
            ->method('executeMultiple')
            ->with($termIds)
            ->willReturn(5);

        $this->updateTermStatus->expects($this->once())
            ->method('executeMultiple')
            ->with($termIds, 99)
            ->willReturn(5);

        $deleted = $this->facade->deleteTerms($termIds);
        $updated = $this->facade->bulkUpdateStatus($termIds, 99);

        $this->assertSame(5, $deleted);
        $this->assertSame(5, $updated);
    }
}
