<?php

/**
 * Unit tests for SubmitAnswer use case.
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

use Lwt\Modules\Review\Application\UseCases\SubmitAnswer;
use Lwt\Modules\Review\Domain\ReviewRepositoryInterface;
use Lwt\Modules\Review\Domain\ReviewSession;
use Lwt\Modules\Review\Infrastructure\SessionStateManager;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for SubmitAnswer use case.
 *
 * Tests status validation, status updates, session progress tracking,
 * and the relative status change method.
 *
 * @since 3.0.0
 */
class SubmitAnswerTest extends TestCase
{
    private ReviewRepositoryInterface&MockObject $repository;
    private SessionStateManager&MockObject $sessionManager;

    protected function setUp(): void
    {
        $this->repository = $this->createMock(ReviewRepositoryInterface::class);
        $this->sessionManager = $this->createMock(SessionStateManager::class);
    }

    /**
     * Pass the ownership pre-check in SubmitAnswer::execute() by mocking
     * the user-scoped getWordStatus() lookup. Pass null to test the
     * "foreign WoID" rejection path.
     */
    private function mockOwnershipCheck(?int $currentStatus): void
    {
        $this->repository
            ->method('getWordStatus')
            ->willReturn($currentStatus);
    }

    // =========================================================================
    // Instantiation
    // =========================================================================

    #[Test]
    public function canBeInstantiatedWithBothDependencies(): void
    {
        $useCase = new SubmitAnswer($this->repository, $this->sessionManager);
        $this->assertInstanceOf(SubmitAnswer::class, $useCase);
    }

    #[Test]
    public function canBeInstantiatedWithoutSessionManager(): void
    {
        $useCase = new SubmitAnswer($this->repository);
        $this->assertInstanceOf(SubmitAnswer::class, $useCase);
    }

    // =========================================================================
    // Invalid status values
    // =========================================================================

    #[Test]
    public function executeWithInvalidStatusReturnsFailure(): void
    {
        $useCase = new SubmitAnswer($this->repository, $this->sessionManager);
        $result = $useCase->execute(1, 0);

        $this->assertFalse($result['success']);
        $this->assertSame('Invalid status value', $result['error']);
    }

    #[Test]
    public function executeWithStatus6ReturnsFailure(): void
    {
        $useCase = new SubmitAnswer($this->repository, $this->sessionManager);
        $result = $useCase->execute(1, 6);

        $this->assertFalse($result['success']);
    }

    #[Test]
    public function executeWithNegativeStatusReturnsFailure(): void
    {
        $useCase = new SubmitAnswer($this->repository, $this->sessionManager);
        $result = $useCase->execute(1, -1);

        $this->assertFalse($result['success']);
    }

    #[Test]
    public function executeWithStatus97ReturnsFailure(): void
    {
        $useCase = new SubmitAnswer($this->repository, $this->sessionManager);
        $result = $useCase->execute(1, 97);

        $this->assertFalse($result['success']);
    }

    #[Test]
    public function executeInvalidStatusFailureResponseStructure(): void
    {
        $useCase = new SubmitAnswer($this->repository, $this->sessionManager);
        $result = $useCase->execute(1, 0);

        $this->assertSame(0, $result['oldStatus']);
        $this->assertSame(0, $result['newStatus']);
        $this->assertSame(0, $result['statusChange']);
        $this->assertSame(
            ['total' => 0, 'wrong' => 0, 'correct' => 0, 'remaining' => 0],
            $result['progress']
        );
    }

    #[Test]
    public function executeInvalidStatusDoesNotCallRepository(): void
    {
        $this->repository->expects($this->never())
            ->method('updateWordStatus');

        $useCase = new SubmitAnswer($this->repository, $this->sessionManager);
        $useCase->execute(1, 10);
    }

    // =========================================================================
    // Multi-user ownership gate
    // =========================================================================

    #[Test]
    public function executeForeignWordIdReturnsWordNotFound(): void
    {
        $this->mockOwnershipCheck(null);
        $this->repository->expects($this->never())
            ->method('updateWordStatus');

        $useCase = new SubmitAnswer($this->repository, $this->sessionManager);
        $result = $useCase->execute(99, 3);

        $this->assertFalse($result['success']);
        $this->assertSame('Word not found', $result['error']);
    }

    // =========================================================================
    // Valid status values
    // =========================================================================

    /**
     * @return array<string, array{int}>
     */
    public static function validStatusProvider(): array
    {
        return [
            'status 1' => [1],
            'status 2' => [2],
            'status 3' => [3],
            'status 4' => [4],
            'status 5' => [5],
            'status 98 (ignored)' => [98],
            'status 99 (well-known)' => [99],
        ];
    }

    #[Test]
    #[DataProvider('validStatusProvider')]
    public function executeWithValidStatusCallsRepository(int $status): void
    {
        $this->mockOwnershipCheck(1);
        $this->repository->expects($this->once())
            ->method('updateWordStatus')
            ->with(42, $status)
            ->willReturn([
                'oldStatus' => 1,
                'newStatus' => $status,
                'newScore' => 60
            ]);

        $this->sessionManager->method('getSession')->willReturn(null);

        $useCase = new SubmitAnswer($this->repository, $this->sessionManager);
        $result = $useCase->execute(42, $status);

        $this->assertTrue($result['success']);
    }

    // =========================================================================
    // Successful submission
    // =========================================================================

    #[Test]
    public function executeSuccessfulSubmissionReturnsCorrectData(): void
    {
        $this->mockOwnershipCheck(2);
        $this->repository->expects($this->once())
            ->method('updateWordStatus')
            ->with(10, 3)
            ->willReturn([
                'oldStatus' => 2,
                'newStatus' => 3,
                'newScore' => 55
            ]);

        $session = new ReviewSession(time(), 10, 2, 1);
        $this->sessionManager->method('getSession')->willReturn($session);
        $this->sessionManager->method('saveSession');

        $useCase = new SubmitAnswer($this->repository, $this->sessionManager);
        $result = $useCase->execute(10, 3);

        $this->assertTrue($result['success']);
        $this->assertSame(2, $result['oldStatus']);
        $this->assertSame(3, $result['newStatus']);
        $this->assertArrayNotHasKey('error', $result);
    }

    // =========================================================================
    // Status change direction
    // =========================================================================

    #[Test]
    public function executeWithStatusIncreaseReturnsPositiveChange(): void
    {
        $this->mockOwnershipCheck(2);
        $this->repository->method('updateWordStatus')
            ->willReturn([
                'oldStatus' => 2, 'newStatus' => 3, 'newScore' => 60
            ]);
        $this->sessionManager->method('getSession')->willReturn(null);

        $useCase = new SubmitAnswer($this->repository, $this->sessionManager);
        $result = $useCase->execute(1, 3);

        $this->assertSame(1, $result['statusChange']);
    }

    #[Test]
    public function executeWithStatusDecreaseReturnsNegativeChange(): void
    {
        $this->mockOwnershipCheck(3);
        $this->repository->method('updateWordStatus')
            ->willReturn([
                'oldStatus' => 3, 'newStatus' => 1, 'newScore' => 30
            ]);
        $this->sessionManager->method('getSession')->willReturn(null);

        $useCase = new SubmitAnswer($this->repository, $this->sessionManager);
        $result = $useCase->execute(1, 1);

        $this->assertSame(-1, $result['statusChange']);
    }

    #[Test]
    public function executeWithSameStatusReturnsZeroChange(): void
    {
        $this->mockOwnershipCheck(2);
        $this->repository->method('updateWordStatus')
            ->willReturn([
                'oldStatus' => 2, 'newStatus' => 2, 'newScore' => 50
            ]);
        $this->sessionManager->method('getSession')->willReturn(null);

        $useCase = new SubmitAnswer($this->repository, $this->sessionManager);
        $result = $useCase->execute(1, 2);

        $this->assertSame(0, $result['statusChange']);
    }

    // =========================================================================
    // Session progress tracking
    // =========================================================================

    #[Test]
    public function executeUpdatesSessionProgressOnCorrectAnswer(): void
    {
        $this->mockOwnershipCheck(1);
        $this->repository->method('updateWordStatus')
            ->willReturn([
                'oldStatus' => 1, 'newStatus' => 2, 'newScore' => 45
            ]);

        $session = new ReviewSession(time(), 10, 0, 0);
        $this->sessionManager->expects($this->once())
            ->method('getSession')
            ->willReturn($session);

        $this->sessionManager->expects($this->once())
            ->method('saveSession')
            ->with($this->callback(function (ReviewSession $s) {
                return $s->getCorrect() === 1 && $s->getWrong() === 0;
            }));

        $useCase = new SubmitAnswer($this->repository, $this->sessionManager);
        $result = $useCase->execute(1, 2);

        $this->assertSame(10, $result['progress']['total']);
        $this->assertSame(1, $result['progress']['correct']);
        $this->assertSame(0, $result['progress']['wrong']);
        $this->assertSame(9, $result['progress']['remaining']);
    }

    #[Test]
    public function executeUpdatesSessionProgressOnWrongAnswer(): void
    {
        $this->mockOwnershipCheck(3);
        $this->repository->method('updateWordStatus')
            ->willReturn([
                'oldStatus' => 3, 'newStatus' => 1, 'newScore' => 20
            ]);

        $session = new ReviewSession(time(), 10, 0, 0);
        $this->sessionManager->expects($this->once())
            ->method('getSession')
            ->willReturn($session);

        $this->sessionManager->expects($this->once())
            ->method('saveSession');

        $useCase = new SubmitAnswer($this->repository, $this->sessionManager);
        $result = $useCase->execute(1, 1);

        $this->assertSame(0, $result['progress']['correct']);
        $this->assertSame(1, $result['progress']['wrong']);
    }

    #[Test]
    public function executeWithNoSessionReturnsEmptyProgress(): void
    {
        $this->mockOwnershipCheck(1);
        $this->repository->method('updateWordStatus')
            ->willReturn([
                'oldStatus' => 1, 'newStatus' => 2, 'newScore' => 45
            ]);

        $this->sessionManager->method('getSession')->willReturn(null);

        $useCase = new SubmitAnswer($this->repository, $this->sessionManager);
        $result = $useCase->execute(1, 2);

        $this->assertSame(
            ['total' => 0, 'wrong' => 0, 'correct' => 0, 'remaining' => 0],
            $result['progress']
        );
    }

    // =========================================================================
    // executeWithChange: word not found
    // =========================================================================

    #[Test]
    public function executeWithChangeWordNotFoundReturnsFailure(): void
    {
        $this->repository->expects($this->once())
            ->method('getWordStatus')
            ->with(999)
            ->willReturn(null);

        $useCase = new SubmitAnswer($this->repository, $this->sessionManager);
        $result = $useCase->executeWithChange(999, 1);

        $this->assertFalse($result['success']);
        $this->assertSame('Word not found', $result['error']);
    }

    #[Test]
    public function executeWithChangeWordNotFoundDoesNotUpdateStatus(): void
    {
        $this->repository->method('getWordStatus')->willReturn(null);

        $this->repository->expects($this->never())
            ->method('updateWordStatus');

        $useCase = new SubmitAnswer($this->repository, $this->sessionManager);
        $useCase->executeWithChange(999, 1);
    }

    // =========================================================================
    // executeWithChange: positive change (+1)
    // =========================================================================

    #[Test]
    public function executeWithChangeIncrementsStatus(): void
    {
        $this->repository->method('getWordStatus')->with(10)->willReturn(2);

        $this->repository->expects($this->once())
            ->method('updateWordStatus')
            ->with(10, 3)
            ->willReturn([
                'oldStatus' => 2, 'newStatus' => 3, 'newScore' => 55
            ]);

        $this->sessionManager->method('getSession')->willReturn(null);

        $useCase = new SubmitAnswer($this->repository, $this->sessionManager);
        $result = $useCase->executeWithChange(10, 1);

        $this->assertTrue($result['success']);
        $this->assertSame(3, $result['newStatus']);
    }

    #[Test]
    public function executeWithChangeStatus5JumpsTo99(): void
    {
        $this->repository->method('getWordStatus')->willReturn(5);

        $this->repository->expects($this->once())
            ->method('updateWordStatus')
            ->with($this->anything(), 99)
            ->willReturn([
                'oldStatus' => 5, 'newStatus' => 99, 'newScore' => 100
            ]);

        $this->sessionManager->method('getSession')->willReturn(null);

        $useCase = new SubmitAnswer($this->repository, $this->sessionManager);
        $result = $useCase->executeWithChange(1, 1);

        $this->assertSame(99, $result['newStatus']);
    }

    #[Test]
    public function executeWithChangeStatus99WrapsTo1(): void
    {
        $this->repository->method('getWordStatus')->willReturn(99);

        $this->repository->expects($this->once())
            ->method('updateWordStatus')
            ->with($this->anything(), 1)
            ->willReturn([
                'oldStatus' => 99, 'newStatus' => 1, 'newScore' => 10
            ]);

        $this->sessionManager->method('getSession')->willReturn(null);

        $useCase = new SubmitAnswer($this->repository, $this->sessionManager);
        $result = $useCase->executeWithChange(1, 1);

        $this->assertSame(1, $result['newStatus']);
    }

    // =========================================================================
    // executeWithChange: negative change (-1)
    // =========================================================================

    #[Test]
    public function executeWithChangeDecrementsStatus(): void
    {
        $this->repository->method('getWordStatus')->with(10)->willReturn(3);

        $this->repository->expects($this->once())
            ->method('updateWordStatus')
            ->with(10, 2)
            ->willReturn([
                'oldStatus' => 3, 'newStatus' => 2, 'newScore' => 40
            ]);

        $this->sessionManager->method('getSession')->willReturn(null);

        $useCase = new SubmitAnswer($this->repository, $this->sessionManager);
        $result = $useCase->executeWithChange(10, -1);

        $this->assertTrue($result['success']);
        $this->assertSame(2, $result['newStatus']);
    }

    #[Test]
    public function executeWithChangeStatus1DropsTo98(): void
    {
        $this->repository->method('getWordStatus')->willReturn(1);

        $this->repository->expects($this->once())
            ->method('updateWordStatus')
            ->with($this->anything(), 98)
            ->willReturn([
                'oldStatus' => 1, 'newStatus' => 98, 'newScore' => 0
            ]);

        $this->sessionManager->method('getSession')->willReturn(null);

        $useCase = new SubmitAnswer($this->repository, $this->sessionManager);
        $result = $useCase->executeWithChange(1, -1);

        $this->assertSame(98, $result['newStatus']);
    }

    #[Test]
    public function executeWithChangeStatus98WrapsTo5(): void
    {
        $this->repository->method('getWordStatus')->willReturn(98);

        $this->repository->expects($this->once())
            ->method('updateWordStatus')
            ->with($this->anything(), 5)
            ->willReturn([
                'oldStatus' => 98, 'newStatus' => 5, 'newScore' => 80
            ]);

        $this->sessionManager->method('getSession')->willReturn(null);

        $useCase = new SubmitAnswer($this->repository, $this->sessionManager);
        $result = $useCase->executeWithChange(1, -1);

        $this->assertSame(5, $result['newStatus']);
    }

    // =========================================================================
    // Response structure
    // =========================================================================

    #[Test]
    public function executeSuccessResponseContainsAllRequiredKeys(): void
    {
        $this->mockOwnershipCheck(1);
        $this->repository->method('updateWordStatus')
            ->willReturn([
                'oldStatus' => 1, 'newStatus' => 2, 'newScore' => 45
            ]);
        $this->sessionManager->method('getSession')->willReturn(null);

        $useCase = new SubmitAnswer($this->repository, $this->sessionManager);
        $result = $useCase->execute(1, 2);

        $this->assertArrayHasKey('success', $result);
        $this->assertArrayHasKey('oldStatus', $result);
        $this->assertArrayHasKey('newStatus', $result);
        $this->assertArrayHasKey('statusChange', $result);
        $this->assertArrayHasKey('progress', $result);
    }

    #[Test]
    public function executeProgressContainsAllRequiredKeys(): void
    {
        $this->mockOwnershipCheck(1);
        $this->repository->method('updateWordStatus')
            ->willReturn([
                'oldStatus' => 1, 'newStatus' => 2, 'newScore' => 45
            ]);

        $session = new ReviewSession(time(), 5, 1, 0);
        $this->sessionManager->method('getSession')->willReturn($session);
        $this->sessionManager->method('saveSession');

        $useCase = new SubmitAnswer($this->repository, $this->sessionManager);
        $result = $useCase->execute(1, 2);

        $this->assertArrayHasKey('total', $result['progress']);
        $this->assertArrayHasKey('wrong', $result['progress']);
        $this->assertArrayHasKey('correct', $result['progress']);
        $this->assertArrayHasKey('remaining', $result['progress']);
    }
}
