<?php

/**
 * Request Password Reset Use Case
 *
 * Handles the password reset request flow.
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
use Lwt\Modules\User\Application\Services\EmailService;
use Lwt\Modules\User\Application\Services\TokenHasher;
use Lwt\Modules\User\Domain\UserRepositoryInterface;

/**
 * Use case for requesting a password reset.
 *
 * Generates a secure token, stores it hashed in the database,
 * and sends the reset link via email.
 *
 * Security considerations:
 * - Tokens are generated using cryptographically secure random bytes
 * - Only the SHA-256 hash is stored in the database
 * - Tokens expire after 1 hour
 * - Silent fail on invalid email (prevents user enumeration attacks)
 *
 * @since 3.0.0
 */
class RequestPasswordReset
{
    /**
     * Token expiry time in hours.
     */
    private const TOKEN_EXPIRY_HOURS = 1;

    private UserRepositoryInterface $repository;
    private TokenHasher $tokenHasher;
    private EmailService $emailService;

    /**
     * Create a new RequestPasswordReset use case.
     *
     * @param UserRepositoryInterface $repository   User repository
     * @param TokenHasher             $tokenHasher  Token hasher service
     * @param EmailService            $emailService Email service
     */
    public function __construct(
        UserRepositoryInterface $repository,
        TokenHasher $tokenHasher,
        EmailService $emailService
    ) {
        $this->repository = $repository;
        $this->tokenHasher = $tokenHasher;
        $this->emailService = $emailService;
    }

    /**
     * Execute the password reset request.
     *
     * Always returns true to prevent email enumeration attacks.
     * If the email doesn't exist or the account is inactive, we silently succeed.
     *
     * @param string $email User's email address
     *
     * @return bool Always true (silent fail for security)
     */
    public function execute(string $email): bool
    {
        $user = $this->repository->findByEmail(strtolower(trim($email)));

        if ($user === null) {
            // Silent fail - don't reveal whether email exists
            return true;
        }

        if (!$user->isActive()) {
            // Don't allow reset for inactive accounts
            return true;
        }

        $recipient = $user->email();
        if ($recipient === null) {
            // Account registered without an email — no reset channel here.
            // (Email-less accounts recover via their one-time recovery code.)
            return true;
        }

        // Generate plaintext token and hash for storage
        $plaintextToken = $this->tokenHasher->generate(32);
        $hashedToken = $this->tokenHasher->hash($plaintextToken);
        $expires = new DateTimeImmutable('+' . self::TOKEN_EXPIRY_HOURS . ' hours');

        // Store the hashed token
        $user->setPasswordResetToken($hashedToken, $expires);
        $this->repository->save($user);

        // Send email with plaintext token
        try {
            $this->emailService->sendPasswordResetEmail(
                $recipient,
                $user->username(),
                $plaintextToken,
                $expires
            );
        } catch (\RuntimeException $e) {
            // Log the error but don't reveal it to the user
            error_log("Failed to send password reset email: " . $e->getMessage());
        }

        return true;
    }
}
