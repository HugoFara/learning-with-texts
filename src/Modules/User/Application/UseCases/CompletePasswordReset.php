<?php

/**
 * Complete Password Reset Use Case
 *
 * Handles the password reset completion flow.
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
use Lwt\Modules\User\Application\Services\TokenHasher;
use Lwt\Modules\User\Domain\UserRepositoryInterface;

/**
 * Use case for completing a password reset.
 *
 * Validates the token and updates the user's password.
 *
 * Security considerations:
 * - Tokens are hashed before lookup (prevents timing attacks)
 * - Token expiration is checked
 * - Token is invalidated after use (one-time use)
 * - Password strength is validated before update
 *
 * @since 3.0.0
 */
class CompletePasswordReset
{
    private UserRepositoryInterface $repository;
    private TokenHasher $tokenHasher;
    private PasswordHasher $passwordHasher;

    /**
     * Create a new CompletePasswordReset use case.
     *
     * @param UserRepositoryInterface $repository     User repository
     * @param TokenHasher             $tokenHasher    Token hasher service
     * @param PasswordHasher          $passwordHasher Password hasher service
     */
    public function __construct(
        UserRepositoryInterface $repository,
        TokenHasher $tokenHasher,
        PasswordHasher $passwordHasher
    ) {
        $this->repository = $repository;
        $this->tokenHasher = $tokenHasher;
        $this->passwordHasher = $passwordHasher;
    }

    /**
     * Execute the password reset completion.
     *
     * @param string $token       The plaintext token from the email
     * @param string $newPassword The new password
     *
     * @return bool True if password was reset successfully
     *
     * @throws \InvalidArgumentException If password validation fails
     */
    public function execute(string $token, string $newPassword): bool
    {
        if (empty($token)) {
            return false;
        }

        // Hash the provided token to match database
        $hashedToken = $this->tokenHasher->hash($token);
        $user = $this->repository->findByPasswordResetToken($hashedToken);

        if ($user === null) {
            return false;
        }

        // Check token hasn't expired
        if (!$user->hasValidPasswordResetToken()) {
            // Clear the expired token
            $user->invalidatePasswordResetToken();
            $this->repository->save($user);
            return false;
        }

        // Validate password strength
        $validation = $this->passwordHasher->validateStrength($newPassword);
        if (!$validation['valid']) {
            throw new \InvalidArgumentException(implode('. ', $validation['errors']));
        }

        // Update password, consume the reset token, and revoke every other
        // long-lived auth credential. Password reset is the standard signal
        // for "my account was compromised" — leaving the prior remember-me
        // cookie or API bearer token live would defeat the point of the
        // reset (the attacker who set them up retains access after the
        // victim resets).
        $passwordHash = $this->passwordHasher->hash($newPassword);
        $user->changePassword($passwordHash);
        $user->invalidatePasswordResetToken();
        $user->invalidateRememberToken();
        $user->invalidateApiToken();
        $this->repository->save($user);

        return true;
    }

    /**
     * Validate a password reset token without using it.
     *
     * @param string $token The plaintext token to validate
     *
     * @return bool True if token is valid
     */
    public function validateToken(string $token): bool
    {
        if (empty($token)) {
            return false;
        }

        $hashedToken = $this->tokenHasher->hash($token);
        $user = $this->repository->findByPasswordResetToken($hashedToken);

        if ($user === null) {
            return false;
        }

        return $user->hasValidPasswordResetToken();
    }
}
