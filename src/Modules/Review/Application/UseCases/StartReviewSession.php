<?php

/**
 * Start Review Session Use Case
 *
 * PHP version 8.2
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
use Lwt\Modules\Review\Domain\ReviewConfiguration;
use Lwt\Modules\Review\Infrastructure\SessionStateManager;

/**
 * Use case for starting a new review session.
 *
 * Validates test configuration, initializes session state,
 * and returns configuration data for the frontend.
 *
 * @since 3.0.0
 */
class StartReviewSession
{
    private ReviewRepositoryInterface $repository;
    private SessionStateManager $sessionManager;

    /**
     * Constructor.
     *
     * @param ReviewRepositoryInterface $repository     Review repository
     * @param SessionStateManager|null  $sessionManager Session manager (optional)
     */
    public function __construct(
        ReviewRepositoryInterface $repository,
        ?SessionStateManager $sessionManager = null
    ) {
        $this->repository = $repository;
        $this->sessionManager = $sessionManager ?? new SessionStateManager();
    }

    /**
     * Start a new review session.
     *
     * @param ReviewConfiguration $config Test configuration
     *
     * @return array{
     *     success: bool,
     *     session?: ReviewSession,
     *     counts?: array{due: int, total: int},
     *     langId?: int,
     *     error?: string
     * }
     */
    public function execute(ReviewConfiguration $config): array
    {
        // Validate configuration
        if (!$config->isValid()) {
            return [
                'success' => false,
                'error' => 'Invalid test configuration'
            ];
        }

        // Validate single language
        $validation = $this->repository->validateSingleLanguage($config);
        if (!$validation['valid']) {
            return [
                'success' => false,
                'error' => $validation['error'] ?? 'Validation failed'
            ];
        }

        // Get language ID
        $langId = $this->repository->getLanguageIdFromConfig($config);
        if ($langId === null) {
            return [
                'success' => false,
                'error' => 'No words available for testing'
            ];
        }

        // Get test counts
        $counts = $this->repository->getReviewCounts($config);

        // Initialize session
        $session = ReviewSession::start($counts['due']);
        $this->sessionManager->saveSession($session);

        return [
            'success' => true,
            'session' => $session,
            'counts' => $counts,
            'langId' => $langId
        ];
    }

    /**
     * Get current session or start new one if none exists.
     *
     * @param ReviewConfiguration $config Test configuration
     *
     * @return ReviewSession
     */
    public function getOrStartSession(ReviewConfiguration $config): ReviewSession
    {
        $session = $this->sessionManager->getSession();
        if ($session !== null) {
            return $session;
        }

        $result = $this->execute($config);
        return $result['session'] ?? ReviewSession::start(0);
    }
}
