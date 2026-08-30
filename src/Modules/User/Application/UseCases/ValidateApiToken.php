<?php

/**
 * Validate API Token Use Case
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
use Lwt\Modules\User\Application\Services\TokenHasher;
use Lwt\Modules\User\Domain\UserRepositoryInterface;

/**
 * Use case for validating API tokens.
 *
 * Tokens are hashed before lookup since only hashes are stored in the database.
 *
 * @since 3.0.0
 */
class ValidateApiToken
{
    /**
     * User repository.
     *
     * @var UserRepositoryInterface
     */
    private UserRepositoryInterface $repository;

    /**
     * Token hasher.
     *
     * @var TokenHasher
     */
    private TokenHasher $tokenHasher;

    /**
     * Create a new ValidateApiToken use case.
     *
     * @param UserRepositoryInterface $repository   User repository
     * @param TokenHasher|null        $tokenHasher  Token hasher
     */
    public function __construct(
        UserRepositoryInterface $repository,
        ?TokenHasher $tokenHasher = null
    ) {
        $this->repository = $repository;
        $this->tokenHasher = $tokenHasher ?? new TokenHasher();
    }

    /**
     * Execute to validate an API token.
     *
     * The provided plaintext token is hashed before lookup since
     * only hashes are stored in the database.
     *
     * @param string $token The API token to validate (plaintext)
     *
     * @return User|null The user if token is valid, null otherwise
     */
    public function execute(string $token): ?User
    {
        try {
            // Hash the provided token to match what's stored
            $hashedToken = $this->tokenHasher->hash($token);
            $user = $this->repository->findByApiToken($hashedToken);

            if ($user === null) {
                return null;
            }

            if (!$user->hasValidApiToken()) {
                return null;
            }

            if (!$user->canLogin()) {
                return null;
            }

            return $user;
        } catch (\RuntimeException $e) {
            error_log("ValidateApiToken::execute failed: " . $e->getMessage());
            return null;
        }
    }
}
