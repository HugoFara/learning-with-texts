<?php

declare(strict_types=1);

namespace Lwt\Tests\Modules\Review\Http;

use Lwt\Modules\Review\Http\ReviewApiHandler;
use Lwt\Modules\Review\Application\ReviewFacade;
use Lwt\Modules\Review\Domain\Scheduling\Rating;
use Lwt\Modules\Review\Infrastructure\SessionStateManager;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\MockObject\MockObject;

/**
 * Unit tests for ReviewApiHandler.
 *
 * Tests review/test API operations including word retrieval, status updates,
 * and test configuration.
 */
class ReviewApiHandlerTest extends TestCase
{
    /** @var ReviewFacade&MockObject */
    private ReviewFacade $reviewFacade;

    /** @var SessionStateManager&MockObject */
    private SessionStateManager $sessionManager;

    private ReviewApiHandler $handler;

    protected function setUp(): void
    {
        $this->reviewFacade = $this->createMock(ReviewFacade::class);
        $this->sessionManager = $this->createMock(SessionStateManager::class);
        $this->handler = new ReviewApiHandler($this->reviewFacade, $this->sessionManager);
    }

    // =========================================================================
    // Constructor tests
    // =========================================================================

    public function testConstructorCreatesValidHandler(): void
    {
        $this->assertInstanceOf(ReviewApiHandler::class, $this->handler);
    }

    public function testConstructorAcceptsNullParameters(): void
    {
        $handler = new ReviewApiHandler(null, null);
        $this->assertInstanceOf(ReviewApiHandler::class, $handler);
    }

    // =========================================================================
    // getWordReviewData tests
    // =========================================================================

    public function testGetWordReviewDataReturnsEmptyWhenNoWord(): void
    {
        $this->reviewFacade->method('getNextWord')
            ->willReturn(null);

        $result = $this->handler->getWordReviewData('SELECT 1', [], false, 1);

        $this->assertSame(0, $result['term_id']);
        $this->assertSame('', $result['term_text']);
        $this->assertSame('', $result['group']);
    }

    public function testGetWordReviewDataReturnsEmptyWhenWordArrayEmpty(): void
    {
        $this->reviewFacade->method('getNextWord')
            ->willReturn([]);

        $result = $this->handler->getWordReviewData('SELECT 1', [], false, 1);

        $this->assertSame(0, $result['term_id']);
    }

    public function testGetWordReviewDataReturnsWordDataInWordMode(): void
    {
        $wordRecord = [
            'WoID' => 123,
            'WoText' => 'test',
            'WoTextLC' => 'test',
            'WoTranslation' => 'prueba',
            'WoRomanization' => ''
        ];

        $this->reviewFacade->method('getNextWord')
            ->willReturn($wordRecord);
        $this->reviewFacade->method('getTableReviewSettings')
            ->willReturn(['contextRom' => 0, 'contextTrans' => 0]);
        $this->reviewFacade->method('getBaseReviewType')
            ->willReturn(1);
        $this->reviewFacade->method('isWordMode')
            ->willReturn(true);
        $this->reviewFacade->method('getTestSolution')
            ->willReturn('prueba');

        $result = $this->handler->getWordReviewData('SELECT 1', [], true, 1);

        $this->assertSame(123, $result['term_id']);
        $this->assertSame('test', $result['term_text']);
        $this->assertSame('prueba', $result['solution']);
    }

    public function testGetWordReviewDataCallsGetSentenceForWordInSentenceMode(): void
    {
        $wordRecord = [
            'WoID' => 123,
            'WoText' => 'test',
            'WoTextLC' => 'test',
            'WoTranslation' => 'prueba'
        ];

        $this->reviewFacade->method('getNextWord')
            ->willReturn($wordRecord);
        $this->reviewFacade->method('getTableReviewSettings')
            ->willReturn(['contextRom' => 0, 'contextTrans' => 0]);
        $this->reviewFacade->expects($this->once())
            ->method('getSentenceForWord')
            ->with(123, 'test')
            ->willReturn(['sentence' => 'This is a {test} sentence']);
        $this->reviewFacade->method('getBaseReviewType')
            ->willReturn(1);
        $this->reviewFacade->method('isWordMode')
            ->willReturn(false);
        $this->reviewFacade->method('getTestSolution')
            ->willReturn('prueba');

        $result = $this->handler->getWordReviewData('SELECT 1', [], false, 1);

        $this->assertSame(123, $result['term_id']);
    }

    public function testGetWordReviewDataCallsGetSentenceWithAnnotationsWhenEnabled(): void
    {
        $wordRecord = [
            'WoID' => 123,
            'WoText' => 'test',
            'WoTextLC' => 'test',
            'WoTranslation' => 'prueba'
        ];

        $this->reviewFacade->method('getNextWord')
            ->willReturn($wordRecord);
        $this->reviewFacade->method('getTableReviewSettings')
            ->willReturn(['contextRom' => 1, 'contextTrans' => 0]);
        $this->reviewFacade->expects($this->once())
            ->method('getSentenceWithAnnotations')
            ->with(123, 'test')
            ->willReturn([
                'sentence' => 'This is a {test} sentence',
                'annotations' => []
            ]);
        $this->reviewFacade->method('getBaseReviewType')
            ->willReturn(1);
        $this->reviewFacade->method('isWordMode')
            ->willReturn(false);
        $this->reviewFacade->method('getTestSolution')
            ->willReturn('prueba');

        $this->handler->getWordReviewData('SELECT 1', [], false, 1);
    }

    // =========================================================================
    // wordTestAjax tests
    // =========================================================================

    public function testWordTestAjaxReturnsEmptyWhenReviewKeyMissing(): void
    {
        $result = $this->handler->wordTestAjax(['selection' => '1']);

        $this->assertSame(0, $result['term_id']);
        $this->assertSame('', $result['term_text']);
        $this->assertSame('', $result['group']);
    }

    public function testWordTestAjaxReturnsEmptyWhenSelectionMissing(): void
    {
        $result = $this->handler->wordTestAjax(['review_key' => 'lang']);

        $this->assertSame(0, $result['term_id']);
    }

    public function testWordTestAjaxReturnsEmptyWhenBothMissing(): void
    {
        $result = $this->handler->wordTestAjax([]);

        $this->assertSame(0, $result['term_id']);
    }

    public function testWordTestAjaxAcceptsTestKeyAlias(): void
    {
        $this->reviewFacade->method('getReviewSql')
            ->willReturn(null);

        $result = $this->handler->wordTestAjax([
            'test_key' => 'lang',
            'selection' => '1'
        ]);

        $this->assertSame(0, $result['term_id']);
    }

    public function testWordTestAjaxReturnsEmptyWhenSqlGenerationFails(): void
    {
        $this->reviewFacade->method('getReviewSql')
            ->willReturn(null);

        $result = $this->handler->wordTestAjax([
            'review_key' => 'lang',
            'selection' => '1'
        ]);

        $this->assertSame(0, $result['term_id']);
    }

    // =========================================================================
    // tomorrowTestCount tests
    // =========================================================================

    public function testTomorrowTestCountReturnsZeroWhenReviewKeyMissing(): void
    {
        $result = $this->handler->tomorrowTestCount(['selection' => '1']);

        $this->assertSame(0, $result['count']);
    }

    public function testTomorrowTestCountReturnsZeroWhenSelectionMissing(): void
    {
        $result = $this->handler->tomorrowTestCount(['review_key' => 'lang']);

        $this->assertSame(0, $result['count']);
    }

    public function testTomorrowTestCountReturnsZeroWhenSqlGenerationFails(): void
    {
        $this->reviewFacade->method('getReviewSql')
            ->willReturn(null);

        $result = $this->handler->tomorrowTestCount([
            'review_key' => 'lang',
            'selection' => '1'
        ]);

        $this->assertSame(0, $result['count']);
    }

    public function testTomorrowTestCountReturnsFacadeCount(): void
    {
        $this->reviewFacade->method('getReviewSql')
            ->willReturn(['sql' => ' words WHERE WoLgID = ? ', 'params' => [1]]);
        $this->reviewFacade->method('getTomorrowReviewCount')
            ->willReturn(42);

        $result = $this->handler->tomorrowTestCount([
            'review_key' => 'lang',
            'selection' => '1'
        ]);

        $this->assertSame(42, $result['count']);
    }

    // =========================================================================
    // formatNextWord tests
    // =========================================================================

    public function testFormatNextWordDelegatesToWordTestAjax(): void
    {
        $result = $this->handler->formatNextWord([]);

        $this->assertSame(0, $result['term_id']);
    }

    // =========================================================================
    // formatTomorrowCount tests
    // =========================================================================

    public function testFormatTomorrowCountDelegatesToTomorrowTestCount(): void
    {
        $result = $this->handler->formatTomorrowCount([]);

        $this->assertSame(0, $result['count']);
    }

    // =========================================================================
    // updateReviewStatus tests
    // =========================================================================

    public function testUpdateReviewStatusReturnsErrorForInvalidStatus(): void
    {
        $result = $this->handler->updateReviewStatus(1, 7, null);

        $this->assertArrayHasKey('error', $result);
        $this->assertSame('Invalid status value', $result['error']);
    }

    public function testUpdateReviewStatusReturnsErrorForNegativeStatus(): void
    {
        $result = $this->handler->updateReviewStatus(1, -1, null);

        $this->assertArrayHasKey('error', $result);
        $this->assertSame('Invalid status value', $result['error']);
    }

    public function testUpdateReviewStatusReturnsErrorWhenNeitherStatusNorChangeProvided(): void
    {
        $result = $this->handler->updateReviewStatus(1, null, null);

        $this->assertArrayHasKey('error', $result);
        $this->assertSame('Must provide either status, change or grade', $result['error']);
    }

    public function testUpdateReviewStatusAcceptsValidStatusValues(): void
    {
        $validStatuses = [1, 2, 3, 4, 5, 98, 99];

        foreach ($validStatuses as $status) {
            $this->reviewFacade->method('submitAnswer')
                ->willReturn(['success' => true, 'newStatus' => $status]);

            $result = $this->handler->updateReviewStatus(1, $status, null);

            $this->assertArrayNotHasKey('error', $result);
        }
    }

    public function testUpdateReviewStatusCallsSubmitAnswerForExplicitStatus(): void
    {
        $this->reviewFacade->expects($this->once())
            ->method('submitAnswer')
            ->with(123, 3)
            ->willReturn(['success' => true, 'newStatus' => 3]);

        $this->handler->updateReviewStatus(123, 3, null);
    }

    public function testUpdateReviewStatusCallsSubmitAnswerWithChangeForDelta(): void
    {
        $this->reviewFacade->expects($this->once())
            ->method('submitAnswerWithChange')
            ->with(123, 1)
            ->willReturn(['success' => true, 'newStatus' => 2]);

        $this->handler->updateReviewStatus(123, null, 1);
    }

    public function testUpdateReviewStatusReturnsErrorWhenFacadeFails(): void
    {
        $this->reviewFacade->method('submitAnswer')
            ->willReturn(['success' => false, 'error' => 'Database error']);

        $result = $this->handler->updateReviewStatus(1, 3, null);

        $this->assertArrayHasKey('error', $result);
        $this->assertSame('Database error', $result['error']);
    }

    public function testUpdateReviewStatusReturnsDefaultErrorWhenNoErrorMessage(): void
    {
        $this->reviewFacade->method('submitAnswer')
            ->willReturn(['success' => false]);

        $result = $this->handler->updateReviewStatus(1, 3, null);

        $this->assertArrayHasKey('error', $result);
        $this->assertSame('Failed to update status', $result['error']);
    }

    // =========================================================================
    // formatUpdateStatus tests
    // =========================================================================

    public function testFormatUpdateStatusReturnsErrorWhenTermIdMissing(): void
    {
        $result = $this->handler->formatUpdateStatus([]);

        $this->assertArrayHasKey('error', $result);
        $this->assertSame('term_id is required', $result['error']);
    }

    public function testFormatUpdateStatusReturnsErrorWhenTermIdZero(): void
    {
        $result = $this->handler->formatUpdateStatus(['term_id' => 0]);

        $this->assertArrayHasKey('error', $result);
        $this->assertSame('term_id is required', $result['error']);
    }

    public function testFormatUpdateStatusParsesStatusParameter(): void
    {
        $this->reviewFacade->expects($this->once())
            ->method('submitAnswer')
            ->with(1, 3)
            ->willReturn(['success' => true, 'newStatus' => 3]);

        $this->handler->formatUpdateStatus([
            'term_id' => 1,
            'status' => '3'
        ]);
    }

    public function testFormatUpdateStatusParsesChangeParameter(): void
    {
        $this->reviewFacade->expects($this->once())
            ->method('submitAnswerWithChange')
            ->with(1, -1)
            ->willReturn(['success' => true, 'newStatus' => 1]);

        $this->handler->formatUpdateStatus([
            'term_id' => 1,
            'change' => '-1'
        ]);
    }

    // =========================================================================
    // formatTestConfig tests
    // =========================================================================

    public function testFormatTestConfigReturnsErrorWhenNoTestData(): void
    {
        $this->sessionManager->method('hasCriteria')
            ->willReturn(false);
        $this->reviewFacade->method('getReviewDataFromParams')
            ->willReturn(null);

        $result = $this->handler->formatTestConfig([]);

        $this->assertArrayHasKey('error', $result);
        $this->assertSame('Invalid test parameters', $result['error']);
    }

    public function testFormatTestConfigReturnsErrorWhenIdentifierEmpty(): void
    {
        $this->sessionManager->method('hasCriteria')
            ->willReturn(false);
        $this->reviewFacade->method('getReviewDataFromParams')
            ->willReturn(['counts' => ['due' => 10], 'title' => 'Test', 'property' => '']);
        $this->reviewFacade->method('getReviewIdentifier')
            ->willReturn(['', '']);

        $result = $this->handler->formatTestConfig([]);

        $this->assertArrayHasKey('error', $result);
        $this->assertSame('Invalid test identifier', $result['error']);
    }

    public function testFormatTestConfigReturnsErrorWhenSqlGenerationFails(): void
    {
        $this->sessionManager->method('hasCriteria')
            ->willReturn(false);
        $this->reviewFacade->method('getReviewDataFromParams')
            ->willReturn(['counts' => ['due' => 10], 'title' => 'Test', 'property' => '']);
        $this->reviewFacade->method('getReviewIdentifier')
            ->willReturn(['lang', 1]);
        /** @psalm-suppress InvalidArgument */
        $this->reviewFacade->method('getReviewSql')
            ->willReturn(null);

        $result = $this->handler->formatTestConfig([]);

        $this->assertArrayHasKey('error', $result);
        $this->assertSame('Unable to generate test SQL', $result['error']);
    }

    public function testFormatTestConfigReturnsErrorWhenNoLanguageFound(): void
    {
        $this->sessionManager->method('hasCriteria')
            ->willReturn(false);
        $this->reviewFacade->method('getReviewDataFromParams')
            ->willReturn(['counts' => ['due' => 10], 'title' => 'Test', 'property' => '']);
        $this->reviewFacade->method('getReviewIdentifier')
            ->willReturn(['lang', 1]);
        $this->reviewFacade->method('getReviewSql')
            ->willReturn(['sql' => ' words WHERE WoLgID = ? ', 'params' => [1]]);
        $this->reviewFacade->method('clampReviewType')
            ->willReturn(1);
        $this->reviewFacade->method('isWordMode')
            ->willReturn(false);
        $this->reviewFacade->method('getBaseReviewType')
            ->willReturn(1);
        $this->reviewFacade->method('getLanguageIdFromReviewSql')
            ->willReturn(null);

        $result = $this->handler->formatTestConfig([]);

        $this->assertArrayHasKey('error', $result);
        $this->assertSame('No words available for testing', $result['error']);
    }

    // =========================================================================
    // formatTableWords tests
    // =========================================================================

    public function testFormatTableWordsReturnsErrorWhenReviewKeyMissing(): void
    {
        $result = $this->handler->formatTableWords(['selection' => '1']);

        $this->assertArrayHasKey('error', $result);
        $this->assertSame('review_key and selection are required', $result['error']);
    }

    public function testFormatTableWordsReturnsErrorWhenSelectionMissing(): void
    {
        $result = $this->handler->formatTableWords(['review_key' => 'lang']);

        $this->assertArrayHasKey('error', $result);
        $this->assertSame('review_key and selection are required', $result['error']);
    }

    public function testFormatTableWordsReturnsErrorWhenSqlGenerationFails(): void
    {
        $this->reviewFacade->method('getReviewSql')
            ->willReturn(null);

        $result = $this->handler->formatTableWords([
            'review_key' => 'lang',
            'selection' => '1'
        ]);

        $this->assertArrayHasKey('error', $result);
        $this->assertSame('Unable to generate test SQL', $result['error']);
    }

    public function testFormatTableWordsReturnsErrorWhenValidationFails(): void
    {
        $this->reviewFacade->method('getReviewSql')
            ->willReturn(['sql' => ' words WHERE WoLgID = ? ', 'params' => [1]]);
        $this->reviewFacade->method('validateReviewSelection')
            ->willReturn(['valid' => false, 'error' => 'Multiple languages selected']);

        $result = $this->handler->formatTableWords([
            'review_key' => 'lang',
            'selection' => '1'
        ]);

        $this->assertArrayHasKey('error', $result);
        $this->assertSame('Multiple languages selected', $result['error']);
    }

    public function testFormatTableWordsReturnsEmptyWhenNoLanguage(): void
    {
        $this->reviewFacade->method('getReviewSql')
            ->willReturn(['sql' => ' words WHERE WoLgID = ? ', 'params' => [1]]);
        $this->reviewFacade->method('validateReviewSelection')
            ->willReturn(['valid' => true]);
        $this->reviewFacade->method('getLanguageIdFromReviewSql')
            ->willReturn(null);

        $result = $this->handler->formatTableWords([
            'review_key' => 'lang',
            'selection' => '1'
        ]);

        $this->assertSame([], $result['words']);
        $this->assertNull($result['langSettings']);
    }

    // =========================================================================
    // Graded answers (#238 phase 2b)
    // =========================================================================

    public function testAGradeIsSubmittedThroughTheGradedPath(): void
    {
        $this->reviewFacade->expects($this->once())
            ->method('submitAnswerWithGrade')
            ->with(42, Rating::Hard)
            ->willReturn(['success' => true, 'newStatus' => 3]);
        $this->reviewFacade->expects($this->never())->method('submitAnswer');

        $result = $this->handler->updateReviewStatus(42, null, null, 2);

        $this->assertSame(3, $result['status']);
    }

    public function testAGradeWinsOverAStatusSentAlongsideIt(): void
    {
        // Only the graded path feeds the scheduler, so a client that sends both
        // must not be able to lose the scheduling signal
        $this->reviewFacade->expects($this->once())
            ->method('submitAnswerWithGrade')
            ->willReturn(['success' => true, 'newStatus' => 4]);
        $this->reviewFacade->expects($this->never())->method('submitAnswer');

        $this->handler->updateReviewStatus(42, 5, null, 4);
    }

    #[DataProvider('invalidGradeProvider')]
    public function testAnOutOfRangeGradeIsRejected(int $grade): void
    {
        $this->reviewFacade->expects($this->never())->method('submitAnswerWithGrade');

        $result = $this->handler->updateReviewStatus(42, null, null, $grade);

        $this->assertSame('Invalid grade value', $result['error']);
    }

    /**
     * @return array<string, array{int}>
     */
    public static function invalidGradeProvider(): array
    {
        return [
            'below Again' => [0],
            'above Easy' => [5],
            'negative' => [-1],
        ];
    }

    public function testIntervalsAreReportedPerGrade(): void
    {
        $this->reviewFacade->method('previewIntervals')
            ->with(42)
            ->willReturn([1 => 0, 2 => 2, 3 => 5, 4 => 12]);

        $result = $this->handler->formatIntervals(['term_id' => 42]);

        $this->assertSame([1 => 0, 2 => 2, 3 => 5, 4 => 12], $result['intervals']);
    }

    public function testIntervalsNeedATerm(): void
    {
        $result = $this->handler->formatIntervals([]);

        $this->assertSame('term_id is required', $result['error']);
    }
}
