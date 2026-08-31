<?php

/**
 * Register Use Case
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
use Lwt\Modules\User\Application\Services\PasswordHasher;
use Lwt\Modules\User\Domain\UserRepositoryInterface;

/**
 * Use case for user registration.
 *
 * Handles creating new user accounts.
 *
 * @since 3.0.0
 */
class Register
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
     * Orphan-row claim use case (run during first-admin bootstrap).
     */
    private ClaimOrphanRows $claimOrphanRows;

    /**
     * Create a new Register use case.
     *
     * @param UserRepositoryInterface $repository      User repository
     * @param PasswordHasher|null     $passwordHasher  Password hasher
     * @param ClaimOrphanRows|null    $claimOrphanRows Orphan-claim helper
     */
    public function __construct(
        UserRepositoryInterface $repository,
        ?PasswordHasher $passwordHasher = null,
        ?ClaimOrphanRows $claimOrphanRows = null
    ) {
        $this->repository = $repository;
        $this->passwordHasher = $passwordHasher ?? new PasswordHasher();
        $this->claimOrphanRows = $claimOrphanRows ?? new ClaimOrphanRows();
    }

    /**
     * Execute the registration.
     *
     * @param string      $username Username
     * @param string|null $email    Email address (optional)
     * @param string      $password Plain-text password
     *
     * @return User The created user
     *
     * @throws \InvalidArgumentException If validation fails
     * @throws \RuntimeException If registration fails
     */
    public function execute(string $username, ?string $email, string $password): User
    {
        // Validate password strength
        $validation = $this->passwordHasher->validateStrength($password);
        if (!$validation['valid']) {
            throw new \InvalidArgumentException(implode('. ', $validation['errors']));
        }

        // Email is optional: username is the unique identity. Normalise a blank
        // email to null so it is stored as NULL rather than an empty string.
        $email = $email !== null ? trim($email) : '';
        $email = $email !== '' ? $email : null;

        // Check if username already exists
        if ($this->repository->findByUsername($username) !== null) {
            throw new \InvalidArgumentException('Username is already taken');
        }

        // Check if email already exists (only when one was supplied)
        if ($email !== null && $this->repository->findByEmail($email) !== null) {
            throw new \InvalidArgumentException('Email is already registered');
        }

        // Hash the password
        $passwordHash = $this->passwordHasher->hash($password);

        // Create the user entity
        $user = User::create($username, $email, $passwordHash);

        // First-admin bootstrap: promote if no real admins exist
        $isFirstAdmin = $this->repository->countAdmins() === 0;
        if ($isFirstAdmin) {
            $user->promoteToAdmin();
        }

        // Persist to database
        $this->repository->save($user);

        // Self-heal the false→true MULTI_USER_ENABLED flip: the
        // add_user_id_columns migration's backfill is a no-op when no
        // admin exists at migration time, so legacy single-user data is
        // left at NULL UsIDs and becomes invisible once user-scope kicks
        // in. The first user to register is the new operator — claim
        // every orphan row for them. On already-migrated installs the
        // UPDATEs match zero rows and cost nothing.
        if ($isFirstAdmin) {
            $this->claimOrphanRows->execute($user->id()->toInt());
        }

        return $user;
    }
}
