<?php

/**
 * Update Profile Use Case
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
use Lwt\Modules\User\Domain\UserRepositoryInterface;

/**
 * Use case for updating a user's profile (username, email).
 *
 * @since 3.0.0
 */
class UpdateProfile
{
    private UserRepositoryInterface $repository;

    public function __construct(UserRepositoryInterface $repository)
    {
        $this->repository = $repository;
    }

    /**
     * Update the user's profile.
     *
     * @param User   $user     The user to update
     * @param string $username New username
     * @param string $email    New email
     *
     * @return bool Whether the email changed (triggers re-verification)
     *
     * @throws \InvalidArgumentException If validation fails
     */
    public function execute(User $user, string $username, string $email): bool
    {
        $trimmedUsername = trim($username);
        $normalizedEmail = strtolower(trim($email));
        $emailChanged = $normalizedEmail !== $user->email();

        // Check username uniqueness (excluding current user)
        if ($trimmedUsername !== $user->username()) {
            if ($this->repository->usernameExists($trimmedUsername, $user->id()->toInt())) {
                throw new \InvalidArgumentException('Username is already taken');
            }
            $user->changeUsername($trimmedUsername);
        }

        // Check email uniqueness (excluding current user)
        if ($emailChanged) {
            if ($this->repository->emailExists($normalizedEmail, $user->id()->toInt())) {
                throw new \InvalidArgumentException('Email is already registered');
            }
            $user->changeEmail($normalizedEmail);
            $user->markEmailUnverified();
        }

        $this->repository->save($user);

        return $emailChanged;
    }
}
