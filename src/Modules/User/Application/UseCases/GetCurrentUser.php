<?php

/**
 * Get Current User Use Case
 *
 * PHP version 8.2
 *
 * @category Lwt
 * @package  Lwt\Modules\User\Application\UseCases
 * @author   HugoFara <git@hugofara.net>
 * @license  Unlicense <http://unlicense.org/>
 * @link     https://hugofara.github.io/lwt/developer/api
 * @since    3.0.0
 */

declare(strict_types=1);

namespace Lwt\Modules\User\Application\UseCases;

use Lwt\Modules\User\Domain\User;
use Lwt\Shared\Infrastructure\Globals;
use Lwt\Modules\User\Domain\UserRepositoryInterface;

/**
 * Use case for getting the current authenticated user.
 *
 * @since 3.0.0
 */
class GetCurrentUser
{
    /**
     * User repository.
     *
     * @var UserRepositoryInterface
     */
    private UserRepositoryInterface $repository;

    /**
     * Cached current user.
     *
     * @var User|null
     */
    private ?User $cachedUser = null;

    /**
     * Create a new GetCurrentUser use case.
     *
     * @param UserRepositoryInterface $repository User repository
     */
    public function __construct(UserRepositoryInterface $repository)
    {
        $this->repository = $repository;
    }

    /**
     * Execute to get the current user.
     *
     * @return User|null The current user or null if not authenticated
     */
    public function execute(): ?User
    {
        if ($this->cachedUser !== null) {
            return $this->cachedUser;
        }

        $userId = Globals::getCurrentUserId();
        if ($userId === null) {
            return null;
        }

        try {
            $this->cachedUser = $this->repository->find($userId);
            return $this->cachedUser;
        } catch (\RuntimeException $e) {
            error_log("GetCurrentUser::execute failed: " . $e->getMessage());
            return null;
        }
    }

    /**
     * Clear the cached user.
     *
     * @return void
     */
    public function clearCache(): void
    {
        $this->cachedUser = null;
    }
}
