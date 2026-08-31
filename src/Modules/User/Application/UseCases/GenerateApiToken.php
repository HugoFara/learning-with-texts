<?php

/**
 * Generate API Token Use Case
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

use DateTimeImmutable;
use Lwt\Modules\User\Application\Services\TokenHasher;
use Lwt\Modules\User\Domain\UserRepositoryInterface;

/**
 * Use case for generating API tokens for users.
 *
 * Tokens are hashed before storage for security. Only the hash is stored
 * in the database; the plaintext token is returned to the user and never
 * stored. This protects tokens in case of database breach.
 *
 * @since 3.0.0
 */
class GenerateApiToken
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
     * API token expiration time in seconds (default: 30 days).
     */
    private const API_TOKEN_EXPIRATION = 30 * 24 * 60 * 60;

    /**
     * Create a new GenerateApiToken use case.
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
     * Execute to generate an API token.
     *
     * Returns the plaintext token to the user but stores only the hash.
     *
     * @param int $userId The user ID
     *
     * @return string The generated API token (plaintext)
     *
     * @throws \InvalidArgumentException If user not found
     */
    public function execute(int $userId): string
    {
        $user = $this->repository->find($userId);
        if ($user === null) {
            throw new \InvalidArgumentException('User not found');
        }

        // Generate plaintext token and hash it for storage
        $plaintextToken = $this->tokenHasher->generate(32);
        $hashedToken = $this->tokenHasher->hash($plaintextToken);
        $expires = new DateTimeImmutable('+' . self::API_TOKEN_EXPIRATION . ' seconds');

        // Store the hash, not the plaintext
        $user->setApiToken($hashedToken, $expires);
        $this->repository->save($user);

        // Return plaintext to user (only time they'll see it)
        return $plaintextToken;
    }
}
