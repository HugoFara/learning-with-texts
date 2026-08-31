<?php

/**
 * Verify Email Use Case
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

use Lwt\Modules\User\Application\Services\TokenHasher;
use Lwt\Modules\User\Domain\User;
use Lwt\Modules\User\Domain\UserRepositoryInterface;

/**
 * Use case for verifying a user's email address via token.
 *
 * @since 3.0.0
 */
class VerifyEmail
{
    private UserRepositoryInterface $repository;
    private TokenHasher $tokenHasher;

    public function __construct(
        UserRepositoryInterface $repository,
        TokenHasher $tokenHasher
    ) {
        $this->repository = $repository;
        $this->tokenHasher = $tokenHasher;
    }

    /**
     * Verify email using the provided plaintext token.
     *
     * @param string $plaintextToken The token from the verification URL
     *
     * @return User|null The verified user, or null if token is invalid/expired
     */
    public function execute(string $plaintextToken): ?User
    {
        if ($plaintextToken === '') {
            return null;
        }

        $hashedToken = $this->tokenHasher->hash($plaintextToken);
        $user = $this->repository->findByEmailVerificationToken($hashedToken);

        if ($user === null) {
            return null;
        }

        if (!$user->hasValidEmailVerificationToken()) {
            return null;
        }

        $user->markEmailVerified();
        $this->repository->save($user);

        return $user;
    }
}
