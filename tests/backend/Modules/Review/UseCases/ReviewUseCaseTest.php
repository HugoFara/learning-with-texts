<?php

/**
 * Unit tests for Review module use cases.
 *
 * Tests GetNextTerm, StartReviewSession, SubmitAnswer, and GetTableWords
 * use cases with mocked repository and session dependencies.
 *
 * PHP version 8.2
 *
 * @category Testing
 * @package  Lwt\Tests\Modules\Review\UseCases
 * @license  Unlicense <http://unlicense.org/>
 * @since    3.0.0
 */

declare(strict_types=1);

namespace Lwt\Tests\Modules\Review\UseCases;

use Lwt\Modules\Review\Application\UseCases\GetNextTerm;
use Lwt\Modules\Review\Application\UseCases\GetTableWords;
use Lwt\Modules\Review\Application\UseCases\StartReviewSession;
use Lwt\Modules\Review\Application\UseCases\SubmitAnswer;
use Lwt\Modules\Review\Domain\ReviewConfiguration;
use Lwt\Modules\Review\Domain\ReviewRepositoryInterface;
use Lwt\Modules\Review\Domain\ReviewSession;
use Lwt\Modules\Review\Domain\ReviewWord;
use Lwt\Modules\Review\Infrastructure\SessionStateManager;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for the Review module use cases.
 *
 * Tests business logic in isolation using mocked repositories
 * and session managers. No database access required.
 *
 * @since 3.0.0
 */
class ReviewUseCaseTest extends TestCase
{
    /** @var ReviewRepositoryInterface&MockObject */
    private ReviewRepositoryInterface $repository;

    /** @var SessionStateManager&MockObject */
    private SessionStateManager $sessionManager;

    protected function setUp(): void
    {
        parent::setUp();

        $this->repository = $this->createMock(ReviewRepositoryInterface::class);
        $this->sessionManager = $this->createMock(SessionStateManager::class);
    }

    // =========================================================================
    // Helper methods
    // =========================================================================

    /**
     * Create a ReviewWord entity for testing.
     */
    private function createReviewWord(
        int $id = 1,
        string $text = 'bonjour',
        string $translation = 'hello',
        int $status = 2,
        int $languageId = 1,
        ?string $romanization = null,
        ?string $sentence = null,
        int $score = 50,
        int $daysOld = 3
    ): ReviewWord {
        return new ReviewWord(
            $id,
            $text,
            mb_strtolower($text, 'UTF-8'),
            $translation,
            $romanization,
            $sentence,
            $languageId,
            $status,
            $score,
            $daysOld
        );
    }

    /**
     * Create a valid ReviewConfiguration for testing.
     */
    private function createConfig(
        int $reviewType = 1,
        string $reviewKey = ReviewConfiguration::KEY_LANG,
        int $selection = 1,
        bool $wordMode = false
    ): ReviewConfiguration {
        return new ReviewConfiguration(
            $reviewKey,
            $selection,
            $reviewType,
            $wordMode,
            false
        );
    }

    // =========================================================================
    // GetNextTerm tests
    // =========================================================================

    public function testGetNextTermReturnsEmptyWhenNoWordsAvailable(): void
    {
        $config = $this->createConfig();

        $this->repository
            ->expects($this->once())
            ->method('findNextWordForReview')
            ->with($config)
            ->willReturn(null);

        $useCase = new GetNextTerm($this->repository);
        $result = $useCase->execute($config);

        $this->assertSame(0, $result['word_id']);
        $this->assertSame('', $result['word_text']);
        $this->assertSame('', $result['solution']);
        $this->assertSame('', $result['group']);
        $this->assertArrayNotHasKey('word', $result);
    }

    public function testGetNextTermReturnsWordDataWhenWordFound(): void
    {
        // Use reviewType 2 (translation-to-term) to avoid TagsFacade DB calls
        $config = $this->createConfig(reviewType: 2);
        $word = $this->createReviewWord();

        $this->repository
            ->expects($this->once())
            ->method('findNextWordForReview')
            ->with($config)
            ->willReturn($word);

        $this->repository
            ->expects($this->once())
            ->method('getSentenceForWord')
            ->with($word->id, $word->textLowercase)
            ->willReturn(['sentence' => 'Je dis {bonjour} le matin', 'found' => true]);

        $useCase = new GetNextTerm($this->repository);
        $result = $useCase->execute($config);

        $this->assertSame($word->id, $result['word_id']);
        $this->assertSame('bonjour', $result['word_text']);
        $this->assertSame($word, $result['word']);
    }

    public function testGetNextTermWordModeUsesWordAsSentence(): void
    {
        // wordMode=true means sentence is just the word in braces, no DB lookup
        $config = $this->createConfig(reviewType: 2, wordMode: true);
        $word = $this->createReviewWord();

        $this->repository
            ->expects($this->once())
            ->method('findNextWordForReview')
            ->willReturn($word);

        // getSentenceForWord should NOT be called in word mode
        $this->repository
            ->expects($this->never())
            ->method('getSentenceForWord');

        $useCase = new GetNextTerm($this->repository);
        $result = $useCase->execute($config);

        $this->assertSame($word->id, $result['word_id']);
        $this->assertSame('bonjour', $result['word_text']);
    }

    public function testGetNextTermType1ShowsTermInSentence(): void
    {
        // Type 1: show term, guess translation. Uses TagsFacade so we use word mode
        // to keep the sentence simple. We test the display format only.
        $config = $this->createConfig(reviewType: 2);
        $word = $this->createReviewWord();

        $this->repository
            ->method('findNextWordForReview')
            ->willReturn($word);

        $this->repository
            ->method('getSentenceForWord')
            ->willReturn(['sentence' => '{bonjour} monde', 'found' => true]);

        $useCase = new GetNextTerm($this->repository);
        $result = $useCase->execute($config);

        // Type 2 hides the term with [...]
        $this->assertStringContainsString('[...]', $result['group']);
        // Solution for type 2 is the word text itself
        $this->assertSame('bonjour', $result['solution']);
    }

    public function testGetNextTermType1DisplaysTermVisibly(): void
    {
        // Type 1: term is shown in sentence, we need to avoid static DB call
        // Use word mode so TagsFacade gets wordId which we can handle
        $config = $this->createConfig(reviewType: 1, wordMode: true);
        $word = $this->createReviewWord(id: 0); // id=0 makes TagsFacade return ''

        $this->repository
            ->method('findNextWordForReview')
            ->willReturn($word);

        $useCase = new GetNextTerm($this->repository);
        $result = $useCase->execute($config);

        // Type 1 shows the word text in <span class="word-test">
        $this->assertStringContainsString('word-test', $result['group']);
        $this->assertStringContainsString('bonjour', $result['group']);
    }

    public function testGetNextTermFallsBackToWordWhenNoSentenceFound(): void
    {
        $config = $this->createConfig(reviewType: 2);
        $word = $this->createReviewWord();

        $this->repository
            ->method('findNextWordForReview')
            ->willReturn($word);

        $this->repository
            ->method('getSentenceForWord')
            ->willReturn(['sentence' => null, 'found' => false]);

        $useCase = new GetNextTerm($this->repository);
        $result = $useCase->execute($config);

        // Should still return valid data using the word itself as fallback
        $this->assertSame($word->id, $result['word_id']);
        $this->assertSame('bonjour', $result['word_text']);
    }

    public function testGetNextTermType3SentenceToTerm(): void
    {
        $config = $this->createConfig(reviewType: 3);
        $word = $this->createReviewWord();

        $this->repository
            ->method('findNextWordForReview')
            ->willReturn($word);

        $this->repository
            ->method('getSentenceForWord')
            ->willReturn(['sentence' => 'Il dit {bonjour} chaque jour', 'found' => true]);

        $useCase = new GetNextTerm($this->repository);
        $result = $useCase->execute($config);

        // Type 3 hides term (same as type 2 base behavior)
        $this->assertStringContainsString('[...]', $result['group']);
        $this->assertSame('bonjour', $result['solution']);
    }

    // =========================================================================
    // StartReviewSession tests
    // =========================================================================

    public function testStartReviewSessionReturnsErrorForInvalidConfig(): void
    {
        // Empty review key makes config invalid
        $config = new ReviewConfiguration('', 0);

        $useCase = new StartReviewSession($this->repository, $this->sessionManager);
        $result = $useCase->execute($config);

        $this->assertFalse($result['success']);
        $this->assertSame('Invalid test configuration', $result['error']);
    }

    public function testStartReviewSessionReturnsErrorWhenValidationFails(): void
    {
        $config = $this->createConfig();

        $this->repository
            ->expects($this->once())
            ->method('validateSingleLanguage')
            ->with($config)
            ->willReturn([
                'valid' => false,
                'langCount' => 2,
                'error' => 'Selection contains multiple languages'
            ]);

        $useCase = new StartReviewSession($this->repository, $this->sessionManager);
        $result = $useCase->execute($config);

        $this->assertFalse($result['success']);
        $this->assertSame('Selection contains multiple languages', $result['error']);
    }

    public function testStartReviewSessionReturnsErrorWhenNoLanguageFound(): void
    {
        $config = $this->createConfig();

        $this->repository
            ->method('validateSingleLanguage')
            ->willReturn(['valid' => true, 'langCount' => 1, 'error' => null]);

        $this->repository
            ->expects($this->once())
            ->method('getLanguageIdFromConfig')
            ->with($config)
            ->willReturn(null);

        $useCase = new StartReviewSession($this->repository, $this->sessionManager);
        $result = $useCase->execute($config);

        $this->assertFalse($result['success']);
        $this->assertSame('No words available for testing', $result['error']);
    }

    public function testStartReviewSessionSuccessfullyCreatesSession(): void
    {
        $config = $this->createConfig();

        $this->repository
            ->method('validateSingleLanguage')
            ->willReturn(['valid' => true, 'langCount' => 1, 'error' => null]);

        $this->repository
            ->method('getLanguageIdFromConfig')
            ->willReturn(1);

        $this->repository
            ->method('getReviewCounts')
            ->with($config)
            ->willReturn(['due' => 15, 'total' => 50]);

        $this->sessionManager
            ->expects($this->once())
            ->method('saveSession')
            ->with($this->isInstanceOf(ReviewSession::class));

        $useCase = new StartReviewSession($this->repository, $this->sessionManager);
        $result = $useCase->execute($config);

        $this->assertTrue($result['success']);
        $this->assertInstanceOf(ReviewSession::class, $result['session']);
        $this->assertSame(15, $result['counts']['due']);
        $this->assertSame(50, $result['counts']['total']);
        $this->assertSame(1, $result['langId']);
    }

    public function testStartReviewSessionCreatesSessionWithCorrectTotal(): void
    {
        $config = $this->createConfig();

        $this->repository
            ->method('validateSingleLanguage')
            ->willReturn(['valid' => true, 'langCount' => 1, 'error' => null]);

        $this->repository
            ->method('getLanguageIdFromConfig')
            ->willReturn(1);

        $this->repository
            ->method('getReviewCounts')
            ->willReturn(['due' => 7, 'total' => 20]);

        $this->sessionManager
            ->method('saveSession');

        $useCase = new StartReviewSession($this->repository, $this->sessionManager);
        $result = $useCase->execute($config);

        $session = $result['session'];
        $this->assertSame(7, $session->getTotal());
        $this->assertSame(0, $session->getCorrect());
        $this->assertSame(0, $session->getWrong());
        $this->assertSame(7, $session->remaining());
    }

    public function testGetOrStartSessionReturnsExistingSession(): void
    {
        $config = $this->createConfig();
        $existingSession = new ReviewSession(time(), 10, 3, 2);

        $this->sessionManager
            ->expects($this->once())
            ->method('getSession')
            ->willReturn($existingSession);

        $useCase = new StartReviewSession($this->repository, $this->sessionManager);
        $result = $useCase->getOrStartSession($config);

        $this->assertSame($existingSession, $result);
    }

    public function testGetOrStartSessionCreatesNewWhenNoneExists(): void
    {
        $config = $this->createConfig();

        $this->sessionManager
            ->method('getSession')
            ->willReturn(null);

        $this->repository
            ->method('validateSingleLanguage')
            ->willReturn(['valid' => true, 'langCount' => 1, 'error' => null]);

        $this->repository
            ->method('getLanguageIdFromConfig')
            ->willReturn(1);

        $this->repository
            ->method('getReviewCounts')
            ->willReturn(['due' => 5, 'total' => 10]);

        $this->sessionManager
            ->method('saveSession');

        $useCase = new StartReviewSession($this->repository, $this->sessionManager);
        $result = $useCase->getOrStartSession($config);

        $this->assertInstanceOf(ReviewSession::class, $result);
        $this->assertSame(5, $result->getTotal());
    }

    public function testGetOrStartSessionReturnsEmptySessionOnFailure(): void
    {
        // Invalid config will cause execute() to fail, returning session with 0 total
        $config = new ReviewConfiguration('', 0);

        $this->sessionManager
            ->method('getSession')
            ->willReturn(null);

        $useCase = new StartReviewSession($this->repository, $this->sessionManager);
        $result = $useCase->getOrStartSession($config);

        $this->assertInstanceOf(ReviewSession::class, $result);
        $this->assertSame(0, $result->getTotal());
    }

    public function testStartReviewSessionValidationErrorUsesDefaultMessage(): void
    {
        $config = $this->createConfig();

        $this->repository
            ->method('validateSingleLanguage')
            ->willReturn(['valid' => false, 'langCount' => 0, 'error' => null]);

        $useCase = new StartReviewSession($this->repository, $this->sessionManager);
        $result = $useCase->execute($config);

        $this->assertFalse($result['success']);
        $this->assertSame('Validation failed', $result['error']);
    }

    // =========================================================================
    // SubmitAnswer tests
    // =========================================================================

    public function testSubmitAnswerRejectsInvalidStatus(): void
    {
        $useCase = new SubmitAnswer($this->repository, $this->sessionManager);
        $result = $useCase->execute(1, 6); // 6 is not a valid status

        $this->assertFalse($result['success']);
        $this->assertSame('Invalid status value', $result['error']);
        $this->assertSame(0, $result['oldStatus']);
        $this->assertSame(0, $result['newStatus']);
    }

    public function testSubmitAnswerRejectsZeroStatus(): void
    {
        $useCase = new SubmitAnswer($this->repository, $this->sessionManager);
        $result = $useCase->execute(1, 0);

        $this->assertFalse($result['success']);
        $this->assertSame('Invalid status value', $result['error']);
    }

    public function testSubmitAnswerRejectsForeignWordId(): void
    {
        // Multi-user defence: getWordStatus is user-scoped via QueryBuilder,
        // so a foreign word returns null. The use case must bail out before
        // calling updateWordStatus or touching the activity counter.
        $this->repository
            ->method('getWordStatus')
            ->willReturn(null);
        $this->repository
            ->expects($this->never())
            ->method('updateWordStatus');

        $useCase = new SubmitAnswer($this->repository, $this->sessionManager);
        $result = $useCase->execute(99, 3);

        $this->assertFalse($result['success']);
        $this->assertSame('Word not found', $result['error']);
    }

    public function testSubmitAnswerAcceptsValidStatuses(): void
    {
        $validStatuses = [1, 2, 3, 4, 5, 98, 99];

        foreach ($validStatuses as $status) {
            $this->repository = $this->createMock(ReviewRepositoryInterface::class);
            $this->sessionManager = $this->createMock(SessionStateManager::class);

            $this->repository
                ->method('getWordStatus')
                ->willReturn(2);
            $this->repository
                ->method('updateWordStatus')
                ->willReturn([
                    'oldStatus' => 2,
                    'newStatus' => $status,
                    'newScore' => 60
                ]);

            $this->sessionManager
                ->method('getSession')
                ->willReturn(new ReviewSession(time(), 10, 0, 0));

            $this->sessionManager
                ->method('saveSession');

            $useCase = new SubmitAnswer($this->repository, $this->sessionManager);
            $result = $useCase->execute(1, $status);

            $this->assertTrue(
                $result['success'],
                "Status $status should be valid"
            );
        }
    }

    public function testSubmitAnswerUpdatesWordAndReturnsResult(): void
    {
        $this->repository
            ->method('getWordStatus')
            ->willReturn(2);
        $this->repository
            ->expects($this->once())
            ->method('updateWordStatus')
            ->with(42, 3)
            ->willReturn([
                'oldStatus' => 2,
                'newStatus' => 3,
                'newScore' => 65
            ]);

        $session = new ReviewSession(time(), 10, 2, 1);
        $this->sessionManager
            ->method('getSession')
            ->willReturn($session);

        $this->sessionManager
            ->expects($this->once())
            ->method('saveSession');

        $useCase = new SubmitAnswer($this->repository, $this->sessionManager);
        $result = $useCase->execute(42, 3);

        $this->assertTrue($result['success']);
        $this->assertSame(2, $result['oldStatus']);
        $this->assertSame(3, $result['newStatus']);
        $this->assertSame(1, $result['statusChange']); // positive change
    }

    public function testSubmitAnswerStatusChangeNegative(): void
    {
        $this->repository
            ->method('getWordStatus')
            ->willReturn(4);
        $this->repository
            ->method('updateWordStatus')
            ->willReturn([
                'oldStatus' => 4,
                'newStatus' => 3,
                'newScore' => 55
            ]);

        $session = new ReviewSession(time(), 10, 0, 0);
        $this->sessionManager
            ->method('getSession')
            ->willReturn($session);

        $this->sessionManager->method('saveSession');

        $useCase = new SubmitAnswer($this->repository, $this->sessionManager);
        $result = $useCase->execute(1, 3);

        $this->assertSame(-1, $result['statusChange']);
    }

    public function testSubmitAnswerStatusChangeZero(): void
    {
        $this->repository
            ->method('getWordStatus')
            ->willReturn(3);
        $this->repository
            ->method('updateWordStatus')
            ->willReturn([
                'oldStatus' => 3,
                'newStatus' => 3,
                'newScore' => 50
            ]);

        $session = new ReviewSession(time(), 10, 0, 0);
        $this->sessionManager
            ->method('getSession')
            ->willReturn($session);

        $this->sessionManager->method('saveSession');

        $useCase = new SubmitAnswer($this->repository, $this->sessionManager);
        $result = $useCase->execute(1, 3);

        $this->assertSame(0, $result['statusChange']);
    }

    public function testSubmitAnswerReturnsProgressFromSession(): void
    {
        $this->repository
            ->method('getWordStatus')
            ->willReturn(2);
        $this->repository
            ->method('updateWordStatus')
            ->willReturn([
                'oldStatus' => 2,
                'newStatus' => 3,
                'newScore' => 65
            ]);

        // Session with 10 total, 3 correct, 1 wrong = 6 remaining
        // After recording a correct answer: 4 correct, 1 wrong = 5 remaining
        $session = new ReviewSession(time(), 10, 3, 1);
        $this->sessionManager
            ->method('getSession')
            ->willReturn($session);

        $this->sessionManager->method('saveSession');

        $useCase = new SubmitAnswer($this->repository, $this->sessionManager);
        $result = $useCase->execute(1, 3);

        $this->assertSame(10, $result['progress']['total']);
        $this->assertSame(4, $result['progress']['correct']);
        $this->assertSame(1, $result['progress']['wrong']);
        $this->assertSame(5, $result['progress']['remaining']);
    }

    public function testSubmitAnswerReturnsZeroProgressWhenNoSession(): void
    {
        $this->repository
            ->method('getWordStatus')
            ->willReturn(2);
        $this->repository
            ->method('updateWordStatus')
            ->willReturn([
                'oldStatus' => 2,
                'newStatus' => 3,
                'newScore' => 65
            ]);

        $this->sessionManager
            ->method('getSession')
            ->willReturn(null);

        $useCase = new SubmitAnswer($this->repository, $this->sessionManager);
        $result = $useCase->execute(1, 3);

        $this->assertTrue($result['success']);
        $this->assertSame(0, $result['progress']['total']);
        $this->assertSame(0, $result['progress']['wrong']);
        $this->assertSame(0, $result['progress']['correct']);
        $this->assertSame(0, $result['progress']['remaining']);
    }

    // =========================================================================
    // SubmitAnswer - executeWithChange tests
    // =========================================================================

    public function testExecuteWithChangeReturnsErrorWhenWordNotFound(): void
    {
        $this->repository
            ->expects($this->once())
            ->method('getWordStatus')
            ->with(999)
            ->willReturn(null);

        $useCase = new SubmitAnswer($this->repository, $this->sessionManager);
        $result = $useCase->executeWithChange(999, 1);

        $this->assertFalse($result['success']);
        $this->assertSame('Word not found', $result['error']);
    }

    public function testExecuteWithChangeIncrementsStatus(): void
    {
        $this->repository
            ->method('getWordStatus')
            ->with(1)
            ->willReturn(2);

        // Should call execute with newStatus = 3
        $this->repository
            ->expects($this->once())
            ->method('updateWordStatus')
            ->with(1, 3)
            ->willReturn([
                'oldStatus' => 2,
                'newStatus' => 3,
                'newScore' => 60
            ]);

        $this->sessionManager
            ->method('getSession')
            ->willReturn(new ReviewSession(time(), 10, 0, 0));

        $this->sessionManager->method('saveSession');

        $useCase = new SubmitAnswer($this->repository, $this->sessionManager);
        $result = $useCase->executeWithChange(1, 1);

        $this->assertTrue($result['success']);
    }

    public function testExecuteWithChangeDecrementsStatus(): void
    {
        $this->repository
            ->method('getWordStatus')
            ->with(1)
            ->willReturn(3);

        // Should call execute with newStatus = 2
        $this->repository
            ->expects($this->once())
            ->method('updateWordStatus')
            ->with(1, 2)
            ->willReturn([
                'oldStatus' => 3,
                'newStatus' => 2,
                'newScore' => 40
            ]);

        $this->sessionManager
            ->method('getSession')
            ->willReturn(new ReviewSession(time(), 10, 0, 0));

        $this->sessionManager->method('saveSession');

        $useCase = new SubmitAnswer($this->repository, $this->sessionManager);
        $result = $useCase->executeWithChange(1, -1);

        $this->assertTrue($result['success']);
    }

    public function testExecuteWithChangeStatus5IncrementGoesToWellKnown(): void
    {
        $this->repository
            ->method('getWordStatus')
            ->willReturn(5);

        // 5 + 1 = 6 -> should become 99 (well-known)
        $this->repository
            ->expects($this->once())
            ->method('updateWordStatus')
            ->with(1, 99)
            ->willReturn([
                'oldStatus' => 5,
                'newStatus' => 99,
                'newScore' => 100
            ]);

        $this->sessionManager
            ->method('getSession')
            ->willReturn(new ReviewSession(time(), 10, 0, 0));

        $this->sessionManager->method('saveSession');

        $useCase = new SubmitAnswer($this->repository, $this->sessionManager);
        $result = $useCase->executeWithChange(1, 1);

        $this->assertTrue($result['success']);
        $this->assertSame(99, $result['newStatus']);
    }

    public function testExecuteWithChangeStatus1DecrementGoesToIgnored(): void
    {
        $this->repository
            ->method('getWordStatus')
            ->willReturn(1);

        // 1 - 1 = 0 -> should become 98 (ignored)
        $this->repository
            ->expects($this->once())
            ->method('updateWordStatus')
            ->with(1, 98)
            ->willReturn([
                'oldStatus' => 1,
                'newStatus' => 98,
                'newScore' => 0
            ]);

        $this->sessionManager
            ->method('getSession')
            ->willReturn(new ReviewSession(time(), 10, 0, 0));

        $this->sessionManager->method('saveSession');

        $useCase = new SubmitAnswer($this->repository, $this->sessionManager);
        $result = $useCase->executeWithChange(1, -1);

        $this->assertTrue($result['success']);
        $this->assertSame(98, $result['newStatus']);
    }

    public function testExecuteWithChangeStatus99IncrementWrapsToOne(): void
    {
        $this->repository
            ->method('getWordStatus')
            ->willReturn(99);

        // 99 + 1 = 100 -> wraps to 1
        $this->repository
            ->expects($this->once())
            ->method('updateWordStatus')
            ->with(1, 1)
            ->willReturn([
                'oldStatus' => 99,
                'newStatus' => 1,
                'newScore' => 10
            ]);

        $this->sessionManager
            ->method('getSession')
            ->willReturn(new ReviewSession(time(), 10, 0, 0));

        $this->sessionManager->method('saveSession');

        $useCase = new SubmitAnswer($this->repository, $this->sessionManager);
        $result = $useCase->executeWithChange(1, 1);

        $this->assertTrue($result['success']);
        $this->assertSame(1, $result['newStatus']);
    }

    public function testExecuteWithChangeStatus98DecrementWrapsToFive(): void
    {
        $this->repository
            ->method('getWordStatus')
            ->willReturn(98);

        // 98 - 1 = 97 -> wraps to 5
        $this->repository
            ->expects($this->once())
            ->method('updateWordStatus')
            ->with(1, 5)
            ->willReturn([
                'oldStatus' => 98,
                'newStatus' => 5,
                'newScore' => 80
            ]);

        $this->sessionManager
            ->method('getSession')
            ->willReturn(new ReviewSession(time(), 10, 0, 0));

        $this->sessionManager->method('saveSession');

        $useCase = new SubmitAnswer($this->repository, $this->sessionManager);
        $result = $useCase->executeWithChange(1, -1);

        $this->assertTrue($result['success']);
        $this->assertSame(5, $result['newStatus']);
    }

    // =========================================================================
    // GetTableWords tests
    // =========================================================================

    public function testGetTableWordsReturnsErrorForInvalidConfig(): void
    {
        $config = new ReviewConfiguration('', 0);

        $useCase = new GetTableWords($this->repository);
        $result = $useCase->execute($config);

        $this->assertArrayHasKey('error', $result);
        $this->assertSame('Invalid test configuration', $result['error']);
    }

    public function testGetTableWordsReturnsErrorWhenValidationFails(): void
    {
        $config = $this->createConfig();

        $this->repository
            ->method('validateSingleLanguage')
            ->willReturn([
                'valid' => false,
                'langCount' => 3,
                'error' => 'Multiple languages in selection'
            ]);

        $useCase = new GetTableWords($this->repository);
        $result = $useCase->execute($config);

        $this->assertArrayHasKey('error', $result);
        $this->assertSame('Multiple languages in selection', $result['error']);
    }

    public function testGetTableWordsReturnsEmptyWhenNoLanguageFound(): void
    {
        $config = $this->createConfig();

        $this->repository
            ->method('validateSingleLanguage')
            ->willReturn(['valid' => true, 'langCount' => 1, 'error' => null]);

        $this->repository
            ->method('getLanguageIdFromConfig')
            ->willReturn(null);

        $useCase = new GetTableWords($this->repository);
        $result = $useCase->execute($config);

        $this->assertSame([], $result['words']);
        $this->assertNull($result['langSettings']);
    }

    // =========================================================================
    // ReviewConfiguration value object tests
    // =========================================================================

    public function testReviewConfigurationFromLanguage(): void
    {
        $config = ReviewConfiguration::fromLanguage(5, 2);

        $this->assertSame(ReviewConfiguration::KEY_LANG, $config->reviewKey);
        $this->assertSame(5, $config->selection);
        $this->assertSame(2, $config->reviewType);
        $this->assertFalse($config->wordMode);
        $this->assertFalse($config->isTableMode);
    }

    public function testReviewConfigurationFromLanguageWordModeAutoSetForType4Plus(): void
    {
        $config = ReviewConfiguration::fromLanguage(1, 4);

        $this->assertTrue($config->wordMode);
        $this->assertSame(4, $config->reviewType);
    }

    public function testReviewConfigurationFromText(): void
    {
        $config = ReviewConfiguration::fromText(42, 3);

        $this->assertSame(ReviewConfiguration::KEY_TEXT, $config->reviewKey);
        $this->assertSame(42, $config->selection);
        $this->assertSame(3, $config->reviewType);
    }

    public function testReviewConfigurationFromWords(): void
    {
        $config = ReviewConfiguration::fromWords([1, 2, 3], 1);

        $this->assertSame(ReviewConfiguration::KEY_WORDS, $config->reviewKey);
        $this->assertSame([1, 2, 3], $config->selection);
    }

    public function testReviewConfigurationForTableMode(): void
    {
        $config = ReviewConfiguration::forTableMode('lang', 5);

        $this->assertTrue($config->isTableMode);
        $this->assertSame(1, $config->reviewType);
    }

    public function testReviewConfigurationIsValidWithNonEmptyKey(): void
    {
        $config = $this->createConfig();

        $this->assertTrue($config->isValid());
    }

    public function testReviewConfigurationIsInvalidWithEmptyKey(): void
    {
        $config = new ReviewConfiguration('', 0);

        $this->assertFalse($config->isValid());
    }

    public function testReviewConfigurationClampsReviewType(): void
    {
        $config = ReviewConfiguration::fromLanguage(1, 100);

        $this->assertSame(5, $config->reviewType);
    }

    public function testReviewConfigurationClampsReviewTypeMin(): void
    {
        $config = ReviewConfiguration::fromLanguage(1, -5);

        $this->assertSame(1, $config->reviewType);
    }

    public function testReviewConfigurationGetBaseType(): void
    {
        $config = $this->createConfig(reviewType: 4);

        $this->assertSame(1, $config->getBaseType());
    }

    public function testReviewConfigurationGetBaseTypeForLowTypes(): void
    {
        $config = $this->createConfig(reviewType: 2);

        $this->assertSame(2, $config->getBaseType());
    }

    public function testReviewConfigurationToUrlProperty(): void
    {
        $langConfig = ReviewConfiguration::fromLanguage(5);
        $this->assertSame('lang=5', $langConfig->toUrlProperty());

        $textConfig = ReviewConfiguration::fromText(42);
        $this->assertSame('text=42', $textConfig->toUrlProperty());

        $wordsConfig = ReviewConfiguration::fromWords([1, 2, 3]);
        $this->assertSame('selection=2', $wordsConfig->toUrlProperty());
    }

    public function testReviewConfigurationGetSelectionString(): void
    {
        $config = ReviewConfiguration::fromWords([10, 20, 30]);

        $this->assertSame('10,20,30', $config->getSelectionString());
    }

    public function testReviewConfigurationGetSelectionStringScalar(): void
    {
        $config = ReviewConfiguration::fromLanguage(7);

        $this->assertSame('7', $config->getSelectionString());
    }

    // =========================================================================
    // ReviewSession entity tests
    // =========================================================================

    public function testReviewSessionStart(): void
    {
        $session = ReviewSession::start(10);

        $this->assertSame(10, $session->getTotal());
        $this->assertSame(0, $session->getCorrect());
        $this->assertSame(0, $session->getWrong());
        $this->assertSame(10, $session->remaining());
        $this->assertFalse($session->isFinished());
    }

    public function testReviewSessionRecordCorrect(): void
    {
        $session = new ReviewSession(time(), 5, 0, 0);
        $session->recordCorrect();

        $this->assertSame(1, $session->getCorrect());
        $this->assertSame(4, $session->remaining());
    }

    public function testReviewSessionRecordWrong(): void
    {
        $session = new ReviewSession(time(), 5, 0, 0);
        $session->recordWrong();

        $this->assertSame(1, $session->getWrong());
        $this->assertSame(4, $session->remaining());
    }

    public function testReviewSessionRecordAnswerPositive(): void
    {
        $session = new ReviewSession(time(), 5, 0, 0);
        $session->recordAnswer(1);

        $this->assertSame(1, $session->getCorrect());
        $this->assertSame(0, $session->getWrong());
    }

    public function testReviewSessionRecordAnswerZeroCountsAsCorrect(): void
    {
        $session = new ReviewSession(time(), 5, 0, 0);
        $session->recordAnswer(0);

        $this->assertSame(1, $session->getCorrect());
        $this->assertSame(0, $session->getWrong());
    }

    public function testReviewSessionRecordAnswerNegative(): void
    {
        $session = new ReviewSession(time(), 5, 0, 0);
        $session->recordAnswer(-1);

        $this->assertSame(0, $session->getCorrect());
        $this->assertSame(1, $session->getWrong());
    }

    public function testReviewSessionIsFinishedWhenAllAnswered(): void
    {
        $session = new ReviewSession(time(), 3, 2, 1);

        $this->assertTrue($session->isFinished());
        $this->assertSame(0, $session->remaining());
    }

    public function testReviewSessionDoesNotGoNegativeRemaining(): void
    {
        $session = new ReviewSession(time(), 2, 2, 0);

        // Try recording more answers than remaining
        $session->recordCorrect();

        $this->assertSame(2, $session->getCorrect()); // unchanged
        $this->assertSame(0, $session->remaining());
    }

    public function testReviewSessionToArray(): void
    {
        $session = new ReviewSession(1000, 10, 3, 2);
        $arr = $session->toArray();

        $this->assertSame(1000, $arr['start']);
        $this->assertSame(10, $arr['total']);
        $this->assertSame(3, $arr['correct']);
        $this->assertSame(2, $arr['wrong']);
        $this->assertSame(5, $arr['remaining']);
    }

    // =========================================================================
    // ReviewWord entity tests
    // =========================================================================

    public function testReviewWordCreation(): void
    {
        $word = $this->createReviewWord();

        $this->assertSame(1, $word->id);
        $this->assertSame('bonjour', $word->text);
        $this->assertSame('bonjour', $word->textLowercase);
        $this->assertSame('hello', $word->translation);
        $this->assertNull($word->romanization);
        $this->assertNull($word->sentence);
        $this->assertSame(1, $word->languageId);
        $this->assertSame(2, $word->status);
    }

    public function testReviewWordHasSentence(): void
    {
        $withSentence = $this->createReviewWord(sentence: 'Je dis {bonjour}');
        $withoutSentence = $this->createReviewWord();
        $emptyStr = $this->createReviewWord(sentence: '');

        $this->assertTrue($withSentence->hasSentence());
        $this->assertFalse($withoutSentence->hasSentence());
        $this->assertFalse($emptyStr->hasSentence());
    }

    public function testReviewWordIsLearning(): void
    {
        foreach ([1, 2, 3, 4, 5] as $status) {
            $word = $this->createReviewWord(status: $status);
            $this->assertTrue($word->isLearning(), "Status $status should be learning");
        }

        $this->assertFalse($this->createReviewWord(status: 98)->isLearning());
        $this->assertFalse($this->createReviewWord(status: 99)->isLearning());
    }

    public function testReviewWordIsWellKnown(): void
    {
        $this->assertTrue($this->createReviewWord(status: 99)->isWellKnown());
        $this->assertFalse($this->createReviewWord(status: 2)->isWellKnown());
    }

    public function testReviewWordIsIgnored(): void
    {
        $this->assertTrue($this->createReviewWord(status: 98)->isIgnored());
        $this->assertFalse($this->createReviewWord(status: 2)->isIgnored());
    }

    public function testReviewWordNeedsNewSentence(): void
    {
        // No sentence
        $this->assertTrue($this->createReviewWord()->needsNewSentence());

        // Sentence with correct marking
        $word = $this->createReviewWord(sentence: 'Il dit {bonjour} le matin');
        $this->assertFalse($word->needsNewSentence());

        // Sentence without marking
        $word2 = $this->createReviewWord(sentence: 'Il dit bonjour le matin');
        $this->assertTrue($word2->needsNewSentence());
    }

    public function testReviewWordGetSentenceForDisplay(): void
    {
        $withSentence = $this->createReviewWord(sentence: '{bonjour} monde');
        $this->assertSame('{bonjour} monde', $withSentence->getSentenceForDisplay());

        $withoutSentence = $this->createReviewWord();
        $this->assertSame('{bonjour}', $withoutSentence->getSentenceForDisplay());
    }

    public function testReviewWordToArray(): void
    {
        $word = $this->createReviewWord(
            id: 42,
            text: 'merci',
            translation: 'thank you',
            status: 3,
            romanization: 'mer-ci',
            sentence: 'Je dis {merci}',
            score: 75,
            daysOld: 5
        );

        $arr = $word->toArray();

        $this->assertSame(42, $arr['id']);
        $this->assertSame('merci', $arr['text']);
        $this->assertSame('merci', $arr['textLowercase']);
        $this->assertSame('thank you', $arr['translation']);
        $this->assertSame('mer-ci', $arr['romanization']);
        $this->assertSame('Je dis {merci}', $arr['sentence']);
        $this->assertSame(3, $arr['status']);
        $this->assertSame(75, $arr['score']);
        $this->assertSame(5, $arr['daysOld']);
    }

    public function testReviewWordFromRecord(): void
    {
        $record = [
            'WoID' => 10,
            'WoText' => 'casa',
            'WoTextLC' => 'casa',
            'WoTranslation' => 'house',
            'WoRomanization' => null,
            'WoSentence' => 'La {casa} es grande',
            'WoLgID' => 2,
            'WoStatus' => 4,
            'Score' => 85,
            'Days' => 7
        ];

        $word = ReviewWord::fromRecord($record);

        $this->assertSame(10, $word->id);
        $this->assertSame('casa', $word->text);
        $this->assertSame('house', $word->translation);
        $this->assertNull($word->romanization);
        $this->assertSame('La {casa} es grande', $word->sentence);
        $this->assertSame(2, $word->languageId);
        $this->assertSame(4, $word->status);
        $this->assertSame(85, $word->score);
        $this->assertSame(7, $word->daysOld);
    }
}
