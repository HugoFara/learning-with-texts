<?php

/**
 * Login Use Case
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
use Lwt\Shared\Infrastructure\Exception\AuthException;
use Lwt\Shared\Infrastructure\Globals;
use Lwt\Modules\User\Application\Services\PasswordHasher;
use Lwt\Modules\User\Domain\UserRepositoryInterface;

/**
 * Use case for user login.
 *
 * Handles authentication with username/email and password.
 *
 * @since 3.0.0
 */
class Login
{
    /**
     * User repository.
     *
     * @var UserRepositoryInterface
     */
    private UserRepositoryInterface $repository;

    /**
     * Password hasher.
     *
     * @var PasswordHasher
     */
    private PasswordHasher $passwordHasher;

    /**
     * Session key for storing the user ID.
     */
    private const SESSION_USER_ID = 'LWT_USER_ID';

    /**
     * Session key for storing the session token (for CSRF protection).
     */
    private const SESSION_TOKEN = 'LWT_SESSION_TOKEN';

    /**
     * Create a new Login use case.
     *
     * @param UserRepositoryInterface $repository     User repository
     * @param PasswordHasher|null     $passwordHasher Password hasher
     */
    public function __construct(
        UserRepositoryInterface $repository,
        ?PasswordHasher $passwordHasher = null
    ) {
        $this->repository = $repository;
        $this->passwordHasher = $passwordHasher ?? new PasswordHasher();
    }

    /**
     * Execute the login.
     *
     * @param string $usernameOrEmail Username or email
     * @param string $password        Plain-text password
     *
     * @return User The authenticated user
     *
     * @throws AuthException If authentication fails
     */
    public function execute(string $usernameOrEmail, string $password): User
    {
        // Find user by username or email
        $user = $this->repository->findByUsername($usernameOrEmail)
            ?? $this->repository->findByEmail($usernameOrEmail);

        if ($user === null) {
            throw AuthException::invalidCredentials();
        }

        // Check if account is active
        if (!$user->canLogin()) {
            throw AuthException::accountDisabled();
        }

        // Verify password
        $passwordHash = $user->passwordHash();
        if ($passwordHash === null || !$this->passwordHasher->verify($password, $passwordHash)) {
            throw AuthException::invalidCredentials();
        }

        // Check if password needs rehashing
        if ($this->passwordHasher->needsRehash($passwordHash)) {
            $newHash = $this->passwordHasher->hash($password);
            $user->changePassword($newHash);
            $this->repository->save($user);
        }

        // Record login
        $user->recordLogin();
        $this->repository->save($user);

        // Set up session
        $this->createSession($user);

        return $user;
    }

    /**
     * Create a session for the authenticated user.
     *
     * @param User $user The authenticated user
     *
     * @return void
     */
    private function createSession(User $user): void
    {
        if (session_status() === PHP_SESSION_NONE) {
            @session_start();
        }

        // Regenerate session ID to prevent fixation
        session_regenerate_id(true);

        $_SESSION[self::SESSION_USER_ID] = $user->id()->toInt();
        $_SESSION[self::SESSION_TOKEN] = $this->passwordHasher->generateToken(16);

        // Set user context in Globals
        Globals::setCurrentUserId($user->id()->toInt());
    }
}
