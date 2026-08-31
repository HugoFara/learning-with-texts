<?php

/**
 * Change Password Use Case
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

use Lwt\Modules\User\Application\Services\PasswordHasher;
use Lwt\Modules\User\Domain\User;
use Lwt\Modules\User\Domain\UserRepositoryInterface;

/**
 * Use case for changing a user's password (requires current password).
 *
 * @since 3.0.0
 */
class ChangePassword
{
    private UserRepositoryInterface $repository;
    private PasswordHasher $passwordHasher;

    public function __construct(
        UserRepositoryInterface $repository,
        ?PasswordHasher $passwordHasher = null
    ) {
        $this->repository = $repository;
        $this->passwordHasher = $passwordHasher ?? new PasswordHasher();
    }

    /**
     * Change the user's password.
     *
     * @param User   $user            The user
     * @param string $currentPassword Current password for verification
     * @param string $newPassword     New password
     *
     * @return void
     *
     * @throws \InvalidArgumentException If current password is wrong or new password is weak
     */
    public function execute(User $user, string $currentPassword, string $newPassword): void
    {
        // Verify current password
        if (!$this->passwordHasher->verify($currentPassword, $user->passwordHash() ?? '')) {
            throw new \InvalidArgumentException('Current password is incorrect');
        }

        // Validate new password strength
        $validation = $this->passwordHasher->validateStrength($newPassword);
        if (!$validation['valid']) {
            throw new \InvalidArgumentException(implode('. ', $validation['errors']));
        }

        // Hash the new password, then invalidate every other auth credential
        // tied to this account before persisting. A password change is the
        // user's signal that they want to revoke anything that may have been
        // set up on a shared/compromised browser — long-lived remember-me
        // cookies and the API bearer token must not survive it. The current
        // PHP session is left alone (the actor doing the change keeps their
        // own session) but everything else is wiped in the same save.
        $hash = $this->passwordHasher->hash($newPassword);
        $user->changePassword($hash);
        $user->invalidateRememberToken();
        $user->invalidateApiToken();
        $this->repository->save($user);
    }
}
