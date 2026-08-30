<?php

/**
 * Unit tests for StartReviewSession use case.
 *
 * PHP version 8.2
 *
 * @category Testing
 * @package  Lwt\Tests\Modules\Review\Application\UseCases
 * @license  Unlicense <http://unlicense.org/>
 * @since    3.0.0
 */

declare(strict_types=1);

namespace Lwt\Tests\Modules\Review\Application\UseCases;

use Lwt\Modules\Review\Application\UseCases\StartReviewSession;
use Lwt\Modules\Review\Domain\ReviewConfiguration;
use Lwt\Modules\Review\Domain\ReviewRepositoryInterface;
use Lwt\Modules\Review\Domain\ReviewSession;
use Lwt\Modules\Review\Infrastructure\SessionStateManager;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for StartReviewSession use case.
 *
 * Tests session initialization, validation, and the
 * getOrStartSession convenience method.
 *
 * @since 3.0.0
 */
class StartReviewSessionTest extends TestCase
{
    private ReviewRepositoryInterface&MockObject $repository;
    private SessionStateManager&MockObject $sessionManager;

    protected function setUp(): void
    {
        $this->repository = $this->createMock(ReviewRepositoryInterface::class);
        $this->sessionManager = $this->createMock(SessionStateManager::class);
    }

    // =========================================================================
    // Instantiation
    // =========================================================================

    #[Test]
    public function canBeInstantiatedWithBothDependencies(): void
    {
        $useCase = new StartReviewSession($this->repository, $this->sessionManager);
        $this->assertInstanceOf(StartReviewSession::class, $useCase);
    }

    #[Test]
    public function canBeInstantiatedWithoutSessionManager(): void
    {
        $useCase = new StartReviewSession($this->repository);
        $this->assertInstanceOf(StartReviewSession::class, $useCase);
    }

    // =========================================================================
    // Invalid configuration
    // =========================================================================

    #[Test]
    public function executeWithInvalidConfigReturnsFalseSuccess(): void
    {
        $config = new ReviewConfiguration('', 0);

        $useCase = new StartReviewSession($this->repository, $this->sessionManager);
        $result = $useCase->execute($config);

        $this->assertFalse($result['success']);
        $this->assertSame('Invalid test configuration', $result['error']);
    }

    #[Test]
    public function executeWithInvalidConfigDoesNotCallRepository(): void
    {
        $config = new ReviewConfiguration('', 0);

        $this->repository->expects($this->never())
            ->method('validateSingleLanguage');

        $useCase = new StartReviewSession($this->repository, $this->sessionManager);
        $useCase->execute($config);
    }

    // =========================================================================
    // Language validation failure
    // =========================================================================

    #[Test]
    public function executeWithLanguageValidationFailureReturnsError(): void
    {
        $config = ReviewConfiguration::fromLanguage(1, 1);

        $this->repository->expects($this->once())
            ->method('validateSingleLanguage')
            ->with($config)
            ->willReturn([
                'valid' => false,
                'langCount' => 2,
                'error' => 'Multiple languages in selection'
            ]);

        $useCase = new StartReviewSession($this->repository, $this->sessionManager);
        $result = $useCase->execute($config);

        $this->assertFalse($result['success']);
        $this->assertSame('Multiple languages in selection', $result['error']);
    }

    #[Test]
    public function executeWithValidationFailureNoErrorKeyUsesDefault(): void
    {
        $config = ReviewConfiguration::fromLanguage(1, 1);

        $this->repository->expects($this->once())
            ->method('validateSingleLanguage')
            ->willReturn([
                'valid' => false,
                'langCount' => 0,
                // 'error' key absent
            ]);

        $useCase = new StartReviewSession($this->repository, $this->sessionManager);
        $result = $useCase->execute($config);

        $this->assertFalse($result['success']);
        $this->assertSame('Validation failed', $result['error']);
    }

    // =========================================================================
    // No language ID
    // =========================================================================

    #[Test]
    public function executeWithNoLanguageIdReturnsNoWordsError(): void
    {
        $config = ReviewConfiguration::fromLanguage(1, 1);

        $this->repository->expects($this->once())
            ->method('validateSingleLanguage')
            ->willReturn(['valid' => true, 'langCount' => 1, 'error' => null]);

        $this->repository->expects($this->once())
            ->method('getLanguageIdFromConfig')
            ->with($config)
            ->willReturn(null);

        $useCase = new StartReviewSession($this->repository, $this->sessionManager);
        $result = $useCase->execute($config);

        $this->assertFalse($result['success']);
        $this->assertSame('No words available for testing', $result['error']);
    }

    #[Test]
    public function executeWithNoLanguageIdDoesNotSaveSession(): void
    {
        $config = ReviewConfiguration::fromLanguage(1, 1);

        $this->repository->method('validateSingleLanguage')
            ->willReturn(['valid' => true, 'langCount' => 1, 'error' => null]);

        $this->repository->method('getLanguageIdFromConfig')
            ->willReturn(null);

        $this->sessionManager->expects($this->never())
            ->method('saveSession');

        $useCase = new StartReviewSession($this->repository, $this->sessionManager);
        $useCase->execute($config);
    }

    // =========================================================================
    // Successful session start
    // =========================================================================

    #[Test]
    public function executeSuccessfullyStartsSession(): void
    {
        $config = ReviewConfiguration::fromLanguage(1, 1);

        $this->repository->expects($this->once())
            ->method('validateSingleLanguage')
            ->willReturn(['valid' => true, 'langCount' => 1, 'error' => null]);

        $this->repository->expects($this->once())
            ->method('getLanguageIdFromConfig')
            ->willReturn(5);

        $this->repository->expects($this->once())
            ->method('getReviewCounts')
            ->with($config)
            ->willReturn(['due' => 10, 'total' => 50]);

        $this->sessionManager->expects($this->once())
            ->method('saveSession')
            ->with($this->isInstanceOf(ReviewSession::class));

        $useCase = new StartReviewSession($this->repository, $this->sessionManager);
        $result = $useCase->execute($config);

        $this->assertTrue($result['success']);
        $this->assertInstanceOf(ReviewSession::class, $result['session']);
        $this->assertSame(['due' => 10, 'total' => 50], $result['counts']);
        $this->assertSame(5, $result['langId']);
    }

    #[Test]
    public function executeSuccessSessionHasCorrectTotal(): void
    {
        $config = ReviewConfiguration::fromLanguage(1, 1);

        $this->repository->method('validateSingleLanguage')
            ->willReturn(['valid' => true, 'langCount' => 1, 'error' => null]);
        $this->repository->method('getLanguageIdFromConfig')->willReturn(1);
        $this->repository->method('getReviewCounts')
            ->willReturn(['due' => 25, 'total' => 100]);

        $this->sessionManager->method('saveSession');

        $useCase = new StartReviewSession($this->repository, $this->sessionManager);
        $result = $useCase->execute($config);

        $session = $result['session'];
        $this->assertSame(25, $session->getTotal());
        $this->assertSame(0, $session->getCorrect());
        $this->assertSame(0, $session->getWrong());
        $this->assertSame(25, $session->remaining());
    }

    // =========================================================================
    // Success response structure
    // =========================================================================

    #[Test]
    public function executeSuccessResponseContainsRequiredKeys(): void
    {
        $config = ReviewConfiguration::fromLanguage(1, 1);

        $this->repository->method('validateSingleLanguage')
            ->willReturn(['valid' => true, 'langCount' => 1, 'error' => null]);
        $this->repository->method('getLanguageIdFromConfig')->willReturn(1);
        $this->repository->method('getReviewCounts')
            ->willReturn(['due' => 5, 'total' => 20]);
        $this->sessionManager->method('saveSession');

        $useCase = new StartReviewSession($this->repository, $this->sessionManager);
        $result = $useCase->execute($config);

        $this->assertArrayHasKey('success', $result);
        $this->assertArrayHasKey('session', $result);
        $this->assertArrayHasKey('counts', $result);
        $this->assertArrayHasKey('langId', $result);
        $this->assertArrayNotHasKey('error', $result);
    }

    #[Test]
    public function executeFailureResponseContainsSuccessAndError(): void
    {
        $config = new ReviewConfiguration('', 0);

        $useCase = new StartReviewSession($this->repository, $this->sessionManager);
        $result = $useCase->execute($config);

        $this->assertArrayHasKey('success', $result);
        $this->assertArrayHasKey('error', $result);
        $this->assertArrayNotHasKey('session', $result);
        $this->assertArrayNotHasKey('counts', $result);
        $this->assertArrayNotHasKey('langId', $result);
    }

    // =========================================================================
    // getOrStartSession: existing session
    // =========================================================================

    #[Test]
    public function getOrStartSessionReturnsExistingSession(): void
    {
        $existingSession = new ReviewSession(time(), 10, 3, 2);
        $config = ReviewConfiguration::fromLanguage(1, 1);

        $this->sessionManager->expects($this->once())
            ->method('getSession')
            ->willReturn($existingSession);

        // Should not call execute (no validation needed)
        $this->repository->expects($this->never())
            ->method('validateSingleLanguage');

        $useCase = new StartReviewSession($this->repository, $this->sessionManager);
        $result = $useCase->getOrStartSession($config);

        $this->assertSame($existingSession, $result);
    }

    // =========================================================================
    // getOrStartSession: no existing session, successful start
    // =========================================================================

    #[Test]
    public function getOrStartSessionCreatesNewSessionWhenNoneExists(): void
    {
        $config = ReviewConfiguration::fromLanguage(1, 1);

        $this->sessionManager->method('getSession')->willReturn(null);
        $this->sessionManager->method('saveSession');

        $this->repository->method('validateSingleLanguage')
            ->willReturn(['valid' => true, 'langCount' => 1, 'error' => null]);
        $this->repository->method('getLanguageIdFromConfig')->willReturn(1);
        $this->repository->method('getReviewCounts')
            ->willReturn(['due' => 15, 'total' => 30]);

        $useCase = new StartReviewSession($this->repository, $this->sessionManager);
        $result = $useCase->getOrStartSession($config);

        $this->assertInstanceOf(ReviewSession::class, $result);
        $this->assertSame(15, $result->getTotal());
    }

    // =========================================================================
    // getOrStartSession: no session, execute fails
    // =========================================================================

    #[Test]
    public function getOrStartSessionReturnsFallbackSessionOnFailure(): void
    {
        $config = new ReviewConfiguration('', 0);

        $this->sessionManager->method('getSession')->willReturn(null);

        $useCase = new StartReviewSession($this->repository, $this->sessionManager);
        $result = $useCase->getOrStartSession($config);

        // Should get a fallback session with 0 total
        $this->assertInstanceOf(ReviewSession::class, $result);
        $this->assertSame(0, $result->getTotal());
    }

    // =========================================================================
    // Configuration types
    // =========================================================================

    #[Test]
    public function executeWithTextConfigWorks(): void
    {
        $config = ReviewConfiguration::fromText(42, 2);

        $this->repository->method('validateSingleLanguage')
            ->willReturn(['valid' => true, 'langCount' => 1, 'error' => null]);
        $this->repository->method('getLanguageIdFromConfig')->willReturn(3);
        $this->repository->method('getReviewCounts')
            ->willReturn(['due' => 8, 'total' => 8]);
        $this->sessionManager->method('saveSession');

        $useCase = new StartReviewSession($this->repository, $this->sessionManager);
        $result = $useCase->execute($config);

        $this->assertTrue($result['success']);
        $this->assertSame(3, $result['langId']);
    }

    #[Test]
    public function executeWithWordsConfigWorks(): void
    {
        $config = ReviewConfiguration::fromWords([10, 20, 30], 1);

        $this->repository->method('validateSingleLanguage')
            ->willReturn(['valid' => true, 'langCount' => 1, 'error' => null]);
        $this->repository->method('getLanguageIdFromConfig')->willReturn(2);
        $this->repository->method('getReviewCounts')
            ->willReturn(['due' => 3, 'total' => 3]);
        $this->sessionManager->method('saveSession');

        $useCase = new StartReviewSession($this->repository, $this->sessionManager);
        $result = $useCase->execute($config);

        $this->assertTrue($result['success']);
        $this->assertSame(3, $result['counts']['due']);
    }
}
