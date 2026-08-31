<?php

/**
 * Send Verification Email Use Case
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
use Lwt\Modules\User\Domain\User;
use Lwt\Modules\User\Domain\UserRepositoryInterface;

/**
 * Use case for sending an email verification link.
 *
 * Generates a secure token, stores it hashed in the database,
 * and sends the verification link via email. When email is disabled,
 * auto-verifies the user and logs the token.
 *
 * @since 3.0.0
 */
class SendVerificationEmail
{
    private const TOKEN_EXPIRY_HOURS = 24;

    private UserRepositoryInterface $repository;
    private TokenHasher $tokenHasher;
    private EmailService $emailService;

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
     * Send a verification email to the given user.
     *
     * If email is disabled, the user is auto-verified.
     *
     * @param User $user The user to verify
     *
     * @return bool True if sent or auto-verified
     */
    public function execute(User $user): bool
    {
        if ($user->isEmailVerified()) {
            return true;
        }

        // No email on file (email is optional at registration): nothing to
        // send and nothing to verify. Treat as a no-op success.
        $email = $user->email();
        if ($email === null) {
            return true;
        }

        // When email is disabled, auto-verify
        if (!$this->emailService->isEnabled()) {
            $user->markEmailVerified();
            $this->repository->save($user);
            return true;
        }

        // Generate token
        $plaintextToken = $this->tokenHasher->generate(32);
        $hashedToken = $this->tokenHasher->hash($plaintextToken);
        $expires = new DateTimeImmutable('+' . self::TOKEN_EXPIRY_HOURS . ' hours');

        // Store hashed token on user
        $user->setEmailVerificationToken($hashedToken, $expires);
        $this->repository->save($user);

        // Send email with plaintext token (non-blocking — failure doesn't prevent registration)
        try {
            $this->emailService->sendVerificationEmail(
                $email,
                $user->username(),
                $plaintextToken,
                $expires
            );
        } catch (\RuntimeException $e) {
            error_log("Failed to send verification email: " . $e->getMessage());
        }

        return true;
    }
}
