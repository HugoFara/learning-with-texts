<?php

/**
 * Submit Answer Use Case
 *
 * PHP version 8.1
 *
 * @category Lwt
 * @package  Lwt\Modules\Review\Application\UseCases
 * @author   HugoFara <git@hugofara.net>
 * @license  Unlicense <http://unlicense.org/>
 * @link     https://hugofara.github.io/lwt/developer/api
 * @since    3.0.0
 */

declare(strict_types=1);

namespace Lwt\Modules\Review\Application\UseCases;

use Lwt\Modules\Review\Domain\ReviewRepositoryInterface;
use Lwt\Modules\Review\Domain\ReviewSession;
use Lwt\Modules\Review\Domain\Scheduling\Rating;
use Lwt\Modules\Review\Infrastructure\SessionStateManager;
use Lwt\Modules\Vocabulary\Domain\ValueObject\TermStatus;

/**
 * Use case for submitting an answer during review.
 *
 * Updates word status and session progress.
 *
 * @since 3.0.0
 */
class SubmitAnswer
{
    private ReviewRepositoryInterface $repository;
    private SessionStateManager $sessionManager;
    private RecordScheduledReview $scheduledReview;

    /**
     * Constructor.
     *
     * @param ReviewRepositoryInterface $repository     Review repository
     * @param SessionStateManager|null  $sessionManager Session manager (optional)
     */
    public function __construct(
        ReviewRepositoryInterface $repository,
        ?SessionStateManager $sessionManager = null,
        ?RecordScheduledReview $scheduledReview = null
    ) {
        $this->repository = $repository;
        $this->sessionManager = $sessionManager ?? new SessionStateManager();
        $this->scheduledReview = $scheduledReview ?? new RecordScheduledReview();
    }

    /**
     * Submit a graded answer (issue #238, phase 2a).
     *
     * This is the FSRS-native entry point: the grade drives the scheduler, and
     * is *also* mapped onto the legacy ±1 status nudge so the reading view
     * behaves exactly as it does for a binary answer. Again lowers the status,
     * every other grade raises it.
     *
     * @param int    $wordId Word ID
     * @param Rating $grade  Again / Hard / Good / Easy
     *
     * @return array Same shape as execute(), plus a 'scheduled' flag
     */
    public function executeWithGrade(int $wordId, Rating $grade): array
    {
        $result = $this->executeWithChange($wordId, $grade->legacyStatusChange(), $grade);

        return $result;
    }

    /**
     * Submit answer with explicit new status.
     *
     * @param int $wordId    Word ID
     * @param int $newStatus New status (1-5, 98, 99)
     *
     * @return array{
     *     success: bool,
     *     oldStatus: int,
     *     newStatus: int,
     *     statusChange: int,
     *     progress: array{total: int, wrong: int, correct: int, remaining: int},
     *     error?: string
     * }
     */
    public function execute(int $wordId, int $newStatus): array
    {
        // Validate status
        if (!$this->isValidStatus($newStatus)) {
            return [
                'success' => false,
                'oldStatus' => 0,
                'newStatus' => 0,
                'statusChange' => 0,
                'progress' => ['total' => 0, 'wrong' => 0, 'correct' => 0, 'remaining' => 0],
                'error' => 'Invalid status value'
            ];
        }

        // Confirm the caller owns this word before updating. getWordStatus()
        // applies user scope, so a foreign WoID returns null and we bail
        // out before incrementing the activity counter or signalling success.
        if ($this->repository->getWordStatus($wordId) === null) {
            return [
                'success' => false,
                'oldStatus' => 0,
                'newStatus' => 0,
                'statusChange' => 0,
                'progress' => ['total' => 0, 'wrong' => 0, 'correct' => 0, 'remaining' => 0],
                'error' => 'Word not found'
            ];
        }

        // Update word status
        $result = $this->repository->updateWordStatus($wordId, $newStatus);

        // Calculate status change direction
        $statusChange = $this->calculateStatusChange($result['oldStatus'], $result['newStatus']);

        // Update session progress
        $progress = $this->updateSessionProgress($statusChange);

        return [
            'success' => true,
            'oldStatus' => $result['oldStatus'],
            'newStatus' => $result['newStatus'],
            'statusChange' => $statusChange,
            'progress' => $progress
        ];
    }

    /**
     * Submit answer with relative status change.
     *
     * @param int $wordId Word ID
     * @param int $change Change amount (+1 or -1)
     *
     * @return array Same as execute()
     */
    public function executeWithChange(int $wordId, int $change, ?Rating $grade = null): array
    {
        // Get current status
        $currentStatus = $this->repository->getWordStatus($wordId);
        if ($currentStatus === null) {
            return [
                'success' => false,
                'oldStatus' => 0,
                'newStatus' => 0,
                'statusChange' => 0,
                'progress' => ['total' => 0, 'wrong' => 0, 'correct' => 0, 'remaining' => 0],
                'error' => 'Word not found'
            ];
        }

        // Calculate new status
        $newStatus = $this->calculateNewStatus($currentStatus, $change);

        $result = $this->execute($wordId, $newStatus);

        // Shadow-write FSRS state (issue #238, phase 2a). A binary answer has
        // no Hard/Easy signal, so it maps to Again/Good; the graded endpoint
        // passes the real grade through. Only recorded once the legacy update
        // actually succeeded, so the two models cannot diverge.
        if ($result['success'] === true) {
            $result['scheduled'] = $this->scheduledReview->execute(
                $wordId,
                $grade ?? Rating::fromBinary($change >= 0)
            );
        }

        return $result;
    }

    /**
     * Calculate new status based on change direction.
     *
     * @param int $currentStatus Current status
     * @param int $change        Change amount
     *
     * @return int New status
     */
    private function calculateNewStatus(int $currentStatus, int $change): int
    {
        if ($change > 0) {
            // Increment
            $newStatus = $currentStatus + 1;
            if ($newStatus === 6) {
                return 99; // 5 -> 99 (well-known)
            }
            if ($newStatus === 100) {
                return 1; // 99 -> 1 (wrap around)
            }
            return $newStatus;
        }

        // Decrement
        $newStatus = $currentStatus - 1;
        if ($newStatus === 0) {
            return 98; // 1 -> 98 (ignored)
        }
        if ($newStatus === 97) {
            return 5; // 98 -> 5 (wrap around)
        }
        return $newStatus;
    }

    /**
     * Calculate status change direction.
     *
     * @param int $oldStatus Old status
     * @param int $newStatus New status
     *
     * @return int -1, 0, or 1
     */
    private function calculateStatusChange(int $oldStatus, int $newStatus): int
    {
        $diff = $newStatus - $oldStatus;
        if ($diff < 0) {
            return -1;
        }
        if ($diff > 0) {
            return 1;
        }
        return 0;
    }

    /**
     * Check if status is valid.
     *
     * @param int $status Status value
     *
     * @return bool
     */
    private function isValidStatus(int $status): bool
    {
        return TermStatus::isValid($status);
    }

    /**
     * Update session progress after answer.
     *
     * @param int $statusChange Status change direction
     *
     * @return array{total: int, wrong: int, correct: int, remaining: int}
     */
    private function updateSessionProgress(int $statusChange): array
    {
        $session = $this->sessionManager->getSession();
        if ($session === null) {
            return ['total' => 0, 'wrong' => 0, 'correct' => 0, 'remaining' => 0];
        }

        $session->recordAnswer($statusChange);
        $this->sessionManager->saveSession($session);

        return [
            'total' => $session->getTotal(),
            'wrong' => $session->getWrong(),
            'correct' => $session->getCorrect(),
            'remaining' => $session->remaining()
        ];
    }
}
