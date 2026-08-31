<?php

/**
 * Reset Password With Recovery Code Use Case.
 *
 * The recovery counterpart to email-based reset, for accounts created without
 * an email. The user supplies their username, their one-time recovery code, and
 * a new password. On success the password is changed, all other long-lived
 * credentials are revoked, and a fresh recovery code is issued (one-time use).
 *
 * PHP version 8.2
 *
 * @category Lwt
 * @package  Lwt\Modules\User\Application\UseCases
 * @author   HugoFara <git@hugofara.net>
 * @license  Unlicense <http://unlicense.org/>
 * @since    3.1.2
 */

declare(strict_types=1);

namespace Lwt\Modules\User\Application\UseCases;

use Lwt\Modules\User\Application\Services\PasswordHasher;
use Lwt\Modules\User\Application\Services\RecoveryCodeService;
use Lwt\Modules\User\Domain\UserRepositoryInterface;

/**
 * Reset a password using a one-time recovery code.
 *
 * @since 3.1.2
 */
class ResetPasswordWithRecoveryCode
{
    /** Generic message used for every failure, to avoid leaking which part was wrong. */
    public const INVALID_MESSAGE = 'Invalid username or recovery code.';

    private UserRepositoryInterface $repository;
    private RecoveryCodeService $recoveryCodes;
    private PasswordHasher $passwordHasher;

    public function __construct(
        UserRepositoryInterface $repository,
        ?RecoveryCodeService $recoveryCodes = null,
        ?PasswordHasher $passwordHasher = null
    ) {
        $this->repository = $repository;
        $this->recoveryCodes = $recoveryCodes ?? new RecoveryCodeService();
        $this->passwordHasher = $passwordHasher ?? new PasswordHasher();
    }

    /**
     * Reset the password and rotate the recovery code.
     *
     * @param string $username    The account username.
     * @param string $code        The recovery code as typed by the user.
     * @param string $newPassword The new password.
     *
     * @return string The new recovery code (plaintext, shown once).
     *
     * @throws \InvalidArgumentException On any failure (uniform message) or a
     *   weak password.
     */
    public function execute(string $username, string $code, string $newPassword): string
    {
        $user = $this->repository->findByUsername(trim($username));
        $storedHash = $user?->recoveryCodeHash();

        // Verify the code first, with a uniform error, so an attacker can't
        // distinguish "no such user" / "no code set" / "wrong code".
        if (
            $user === null || $storedHash === null
            || !$this->recoveryCodes->verify($code, $storedHash)
        ) {
            throw new \InvalidArgumentException(self::INVALID_MESSAGE);
        }

        $validation = $this->passwordHasher->validateStrength($newPassword);
        if (!$validation['valid']) {
            throw new \InvalidArgumentException(implode('. ', $validation['errors']));
        }

        // Change the password and revoke other long-lived credentials, then
        // rotate the recovery code (single use).
        $user->changePassword($this->passwordHasher->hash($newPassword));
        $user->invalidateRememberToken();
        $user->invalidateApiToken();

        $next = $this->recoveryCodes->generate();
        $user->setRecoveryCodeHash($next['hash']);
        $this->repository->save($user);

        return $next['code'];
    }
}
