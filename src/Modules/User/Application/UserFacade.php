<?php

/**
 * User Facade
 *
 * PHP version 8.2
 *
 * @category Lwt
 * @package  Lwt\Modules\User\Application
 * @author   HugoFara <git@hugofara.net>
 * @license  Unlicense <http://unlicense.org/>
 * @link     https://hugofara.github.io/lwt/developer/api
 * @since    3.0.0
 */

declare(strict_types=1);

namespace Lwt\Modules\User\Application;

use Lwt\Modules\User\Domain\User;
use Lwt\Shared\Infrastructure\Exception\AuthException;
use Lwt\Shared\Infrastructure\Globals;
use Lwt\Modules\User\Application\Services\PasswordHasher;
use Lwt\Modules\User\Application\Services\TokenHasher;
use Lwt\Modules\User\Application\Services\EmailService;
use Lwt\Modules\User\Application\UseCases\CompletePasswordReset;
use Lwt\Modules\User\Application\UseCases\GenerateApiToken;
use Lwt\Modules\User\Application\UseCases\GetCurrentUser;
use Lwt\Modules\User\Application\UseCases\GetUserPreferences;
use Lwt\Modules\User\Application\UseCases\Login;
use Lwt\Modules\User\Application\UseCases\Logout;
use Lwt\Modules\User\Application\UseCases\Register;
use Lwt\Modules\User\Application\UseCases\ChangePassword;
use Lwt\Modules\User\Application\UseCases\RequestPasswordReset;
use Lwt\Modules\User\Application\UseCases\GenerateRecoveryCode;
use Lwt\Modules\User\Application\UseCases\ResetPasswordWithRecoveryCode;
use Lwt\Modules\User\Application\UseCases\SaveUserPreferences;
use Lwt\Modules\User\Application\UseCases\SendVerificationEmail;
use Lwt\Modules\User\Application\UseCases\UpdateProfile;
use Lwt\Modules\User\Application\UseCases\ValidateApiToken;
use Lwt\Modules\User\Application\UseCases\ValidateSession;
use Lwt\Modules\User\Application\UseCases\VerifyEmail;
use Lwt\Modules\User\Domain\UserRepositoryInterface;

/**
 * Facade providing unified interface to User module.
 *
 * This facade wraps the use cases to provide a similar interface
 * to the original AuthService for gradual migration.
 *
 * @since 3.0.0
 */
class UserFacade
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
     * Token hasher for API and remember tokens.
     *
     * @var TokenHasher
     */
    private TokenHasher $tokenHasher;

    // Use cases (lazily initialized)
    private ?Login $loginUseCase = null;
    private ?Register $registerUseCase = null;
    private ?Logout $logoutUseCase = null;
    private ?ValidateSession $validateSessionUseCase = null;
    private ?GetCurrentUser $getCurrentUserUseCase = null;
    private ?GenerateApiToken $generateApiTokenUseCase = null;
    private ?ValidateApiToken $validateApiTokenUseCase = null;
    private ?RequestPasswordReset $requestPasswordResetUseCase = null;
    private ?CompletePasswordReset $completePasswordResetUseCase = null;
    private ?GenerateRecoveryCode $generateRecoveryCodeUseCase = null;
    private ?ResetPasswordWithRecoveryCode $resetPasswordWithRecoveryCodeUseCase = null;
    private ?SendVerificationEmail $sendVerificationEmailUseCase = null;
    private ?VerifyEmail $verifyEmailUseCase = null;
    private ?UpdateProfile $updateProfileUseCase = null;
    private ?ChangePassword $changePasswordUseCase = null;

    /**
     * Constructor.
     *
     * @param UserRepositoryInterface $repository     User repository
     * @param PasswordHasher|null     $passwordHasher Password hasher
     * @param TokenHasher|null        $tokenHasher    Token hasher
     */
    public function __construct(
        UserRepositoryInterface $repository,
        ?PasswordHasher $passwordHasher = null,
        ?TokenHasher $tokenHasher = null
    ) {
        $this->repository = $repository;
        $this->passwordHasher = $passwordHasher ?? new PasswordHasher();
        $this->tokenHasher = $tokenHasher ?? new TokenHasher();
    }

    // =========================================================================
    // Authentication Operations
    // =========================================================================

    /**
     * Authenticate a user with username/email and password.
     *
     * @param string $usernameOrEmail Username or email
     * @param string $password        Plain-text password
     *
     * @return User The authenticated user
     *
     * @throws AuthException If authentication fails
     */
    public function login(string $usernameOrEmail, string $password): User
    {
        return $this->getLoginUseCase()->execute($usernameOrEmail, $password);
    }

    /**
     * Register a new user.
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
    public function register(string $username, ?string $email, string $password): User
    {
        return $this->getRegisterUseCase()->execute($username, $email, $password);
    }

    /**
     * Send a verification email to the given user.
     *
     * Non-blocking — failure doesn't prevent registration.
     * When MAIL_ENABLED=false, auto-verifies the user.
     *
     * @param User $user The user to verify
     *
     * @return bool True if sent or auto-verified
     */
    public function sendVerificationEmail(User $user): bool
    {
        return $this->getSendVerificationEmailUseCase()->execute($user);
    }

    /**
     * Verify a user's email using a plaintext token.
     *
     * @param string $token The token from the verification URL
     *
     * @return User|null The verified user, or null if invalid/expired
     */
    public function verifyEmail(string $token): ?User
    {
        return $this->getVerifyEmailUseCase()->execute($token);
    }

    /**
     * Update a user's profile (username, email).
     *
     * @param User   $user     The user to update
     * @param string $username New username
     * @param string $email    New email
     *
     * @return bool Whether the email changed (triggers re-verification)
     *
     * @throws \InvalidArgumentException If validation fails
     */
    public function updateProfile(User $user, string $username, string $email): bool
    {
        return $this->getUpdateProfileUseCase()->execute($user, $username, $email);
    }

    /**
     * Change a user's password.
     *
     * @param User   $user            The user
     * @param string $currentPassword Current password for verification
     * @param string $newPassword     New password
     *
     * @return void
     *
     * @throws \InvalidArgumentException If validation fails
     */
    public function changePassword(User $user, string $currentPassword, string $newPassword): void
    {
        $this->getChangePasswordUseCase()->execute($user, $currentPassword, $newPassword);
    }

    /**
     * Log out the current user.
     *
     * @return void
     */
    public function logout(): void
    {
        $this->getLogoutUseCase()->execute();
        $this->getCurrentUserUseCase?->clearCache();
    }

    /**
     * Validate the current session.
     *
     * @return bool True if the session is valid
     */
    public function validateSession(): bool
    {
        return $this->getValidateSessionUseCase()->execute() !== null;
    }

    /**
     * Get the currently authenticated user.
     *
     * @return User|null The current user or null if not authenticated
     */
    public function getCurrentUser(): ?User
    {
        return $this->getGetCurrentUserUseCase()->execute();
    }

    /**
     * Set the current user (for session restoration).
     *
     * @param User $user The user to set as current
     *
     * @return void
     */
    public function setCurrentUser(User $user): void
    {
        Globals::setCurrentUserId($user->id()->toInt());
        Globals::setCurrentUserIsAdmin($user->isAdmin());
        $this->getCurrentUserUseCase?->clearCache();
    }

    // =========================================================================
    // API Token Operations
    // =========================================================================

    /**
     * Generate a new API token for a user.
     *
     * @param int $userId The user ID
     *
     * @return string The generated API token
     *
     * @throws \InvalidArgumentException If user not found
     */
    public function generateApiToken(int $userId): string
    {
        return $this->getGenerateApiTokenUseCase()->execute($userId);
    }

    /**
     * Validate an API token and return the associated user.
     *
     * @param string $token The API token to validate
     *
     * @return User|null The user if token is valid, null otherwise
     */
    public function validateApiToken(string $token): ?User
    {
        return $this->getValidateApiTokenUseCase()->execute($token);
    }

    /**
     * Invalidate a user's API token.
     *
     * @param int $userId The user ID
     *
     * @return void
     */
    public function invalidateApiToken(int $userId): void
    {
        $user = $this->repository->find($userId);
        if ($user !== null) {
            $user->invalidateApiToken();
            $this->repository->save($user);
        }
    }

    // =========================================================================
    // Remember Token Operations
    // =========================================================================

    /**
     * Set a remember-me token for a user.
     *
     * Returns the plaintext token but stores only the hash for security.
     *
     * @param int $userId The user ID
     * @param int $days   Number of days until expiration (default: 30)
     *
     * @return string The generated remember token (plaintext)
     *
     * @throws \InvalidArgumentException If user not found
     */
    public function setRememberToken(int $userId, int $days = 30): string
    {
        $user = $this->repository->find($userId);
        if ($user === null) {
            throw new \InvalidArgumentException("User not found: {$userId}");
        }

        // Generate plaintext token and hash for storage
        $plaintextToken = $this->tokenHasher->generate(32);
        $hashedToken = $this->tokenHasher->hash($plaintextToken);
        $expires = new \DateTimeImmutable("+{$days} days");

        // Store the hash, not the plaintext
        $user->setRememberToken($hashedToken, $expires);
        $this->repository->save($user);

        // Return plaintext to user (for cookie storage)
        return $plaintextToken;
    }

    /**
     * Validate a remember-me token and return the associated user.
     *
     * The provided plaintext token is hashed before lookup.
     *
     * @param string $token The remember token to validate (plaintext)
     *
     * @return User|null The user if token is valid, null otherwise
     */
    public function validateRememberToken(string $token): ?User
    {
        if (empty($token)) {
            return null;
        }

        // Hash the provided token to match what's stored
        $hashedToken = $this->tokenHasher->hash($token);
        $user = $this->repository->findByRememberToken($hashedToken);
        if ($user === null) {
            return null;
        }

        if (!$user->hasValidRememberToken()) {
            return null;
        }

        if (!$user->isActive()) {
            return null;
        }

        return $user;
    }

    /**
     * Invalidate a user's remember-me token.
     *
     * @param int $userId The user ID
     *
     * @return void
     */
    public function invalidateRememberToken(int $userId): void
    {
        $user = $this->repository->find($userId);
        if ($user !== null) {
            $user->invalidateRememberToken();
            $this->repository->save($user);
        }
    }

    // =========================================================================
    // Password Reset Operations
    // =========================================================================

    /**
     * Request a password reset for an email address.
     *
     * Always returns true to prevent email enumeration attacks.
     * If the email doesn't exist, we silently succeed.
     *
     * @param string $email The email address
     *
     * @return bool Always true (silent fail for security)
     */
    public function requestPasswordReset(string $email): bool
    {
        return $this->getRequestPasswordResetUseCase()->execute($email);
    }

    /**
     * Complete a password reset with token and new password.
     *
     * @param string $token       The reset token from email
     * @param string $newPassword The new password
     *
     * @return bool True if password was reset
     *
     * @throws \InvalidArgumentException If password validation fails
     */
    public function completePasswordReset(string $token, string $newPassword): bool
    {
        return $this->getCompletePasswordResetUseCase()->execute($token, $newPassword);
    }

    /**
     * Validate a password reset token without using it.
     *
     * @param string $token The reset token
     *
     * @return bool True if token is valid
     */
    public function validatePasswordResetToken(string $token): bool
    {
        return $this->getCompletePasswordResetUseCase()->validateToken($token);
    }

    /**
     * Issue a new one-time recovery code for a user and return the plaintext
     * (to be shown to the user exactly once).
     *
     * @param User $user The user to issue a code for.
     *
     * @return string The plaintext recovery code.
     */
    public function generateRecoveryCode(User $user): string
    {
        return $this->getGenerateRecoveryCodeUseCase()->execute($user);
    }

    /**
     * Reset a password using a username + one-time recovery code, returning a
     * freshly rotated recovery code (shown once).
     *
     * @param string $username    The account username.
     * @param string $code        The recovery code as typed by the user.
     * @param string $newPassword The new password.
     *
     * @return string The new recovery code (plaintext).
     *
     * @throws \InvalidArgumentException On invalid username/code or weak password.
     */
    public function resetPasswordWithRecoveryCode(
        string $username,
        string $code,
        string $newPassword
    ): string {
        return $this->getResetPasswordWithRecoveryCodeUseCase()
            ->execute($username, $code, $newPassword);
    }

    // =========================================================================
    // User Lookup Operations
    // =========================================================================

    /**
     * Find a user by ID.
     *
     * @param int $id User ID
     *
     * @return User|null
     */
    public function findById(int $id): ?User
    {
        try {
            return $this->repository->find($id);
        } catch (\RuntimeException $e) {
            error_log("UserFacade::findById failed: " . $e->getMessage());
            return null;
        }
    }

    /**
     * Find a user by username.
     *
     * @param string $username Username
     *
     * @return User|null
     */
    public function findByUsername(string $username): ?User
    {
        try {
            return $this->repository->findByUsername($username);
        } catch (\RuntimeException $e) {
            error_log("UserFacade::findByUsername failed: " . $e->getMessage());
            return null;
        }
    }

    /**
     * Find a user by email.
     *
     * @param string $email Email address
     *
     * @return User|null
     */
    public function findByEmail(string $email): ?User
    {
        try {
            return $this->repository->findByEmail($email);
        } catch (\RuntimeException $e) {
            error_log("UserFacade::findByEmail failed: " . $e->getMessage());
            return null;
        }
    }

    /**
     * Find or create a user from WordPress integration.
     *
     * @param int    $wpUserId WordPress user ID
     * @param string $username WordPress username
     * @param string $email    WordPress email
     *
     * @return User The found or created user
     */
    public function findOrCreateWordPressUser(
        int $wpUserId,
        string $username,
        string $email
    ): User {
        // First, try to find by WordPress ID
        try {
            $user = $this->repository->findByWordPressId($wpUserId);
            if ($user !== null) {
                return $user;
            }
        } catch (\RuntimeException $e) {
            error_log("UserFacade::findOrCreateWordPressUser findByWordPressId failed: " . $e->getMessage());
        }

        // Try to find by email and link
        try {
            $user = $this->repository->findByEmail($email);
            if ($user !== null) {
                $user->linkWordPress($wpUserId);
                $this->repository->save($user);
                return $user;
            }
        } catch (\RuntimeException $e) {
            error_log("UserFacade::findOrCreateWordPressUser findByEmail failed: " . $e->getMessage());
        }

        // Create a new user from WordPress
        $user = User::createFromWordPress($wpUserId, $username, $email);
        $this->repository->save($user);

        return $user;
    }

    /**
     * Find a user by Google ID.
     *
     * @param string $googleId Google user ID
     *
     * @return User|null
     */
    public function findByGoogleId(string $googleId): ?User
    {
        try {
            return $this->repository->findByGoogleId($googleId);
        } catch (\RuntimeException $e) {
            error_log("UserFacade::findByGoogleId failed: " . $e->getMessage());
            return null;
        }
    }

    /**
     * Find or create a user from Google OAuth.
     *
     * @param string $googleId Google user ID
     * @param string $username Generated username
     * @param string $email    Google email
     *
     * @return User The found or created user
     */
    public function findOrCreateGoogleUser(
        string $googleId,
        string $username,
        string $email
    ): User {
        // First, try to find by Google ID
        try {
            $user = $this->repository->findByGoogleId($googleId);
            if ($user !== null) {
                return $user;
            }
        } catch (\RuntimeException $e) {
            error_log("UserFacade::findOrCreateGoogleUser findByGoogleId failed: " . $e->getMessage());
        }

        // Try to find by email and link
        try {
            $user = $this->repository->findByEmail($email);
            if ($user !== null) {
                $user->linkGoogle($googleId);
                $this->repository->save($user);
                return $user;
            }
        } catch (\RuntimeException $e) {
            error_log("UserFacade::findOrCreateGoogleUser findByEmail failed: " . $e->getMessage());
        }

        // Create a new user from Google
        $user = User::createFromGoogle($googleId, $username, $email);
        $this->repository->save($user);

        return $user;
    }

    /**
     * Save a user entity.
     *
     * @param User $user The user to save
     *
     * @return void
     */
    public function save(User $user): void
    {
        $this->repository->save($user);
    }

    /**
     * Find a user by Microsoft ID.
     *
     * @param string $microsoftId Microsoft user ID
     *
     * @return User|null
     */
    public function findByMicrosoftId(string $microsoftId): ?User
    {
        try {
            return $this->repository->findByMicrosoftId($microsoftId);
        } catch (\RuntimeException $e) {
            error_log("UserFacade::findByMicrosoftId failed: " . $e->getMessage());
            return null;
        }
    }

    /**
     * Find or create a user from Microsoft OAuth.
     *
     * @param string $microsoftId Microsoft user ID
     * @param string $username    Generated username
     * @param string $email       Microsoft email
     *
     * @return User The found or created user
     */
    public function findOrCreateMicrosoftUser(
        string $microsoftId,
        string $username,
        string $email
    ): User {
        // First, try to find by Microsoft ID
        try {
            $user = $this->repository->findByMicrosoftId($microsoftId);
            if ($user !== null) {
                return $user;
            }
        } catch (\RuntimeException $e) {
            error_log("UserFacade::findOrCreateMicrosoftUser findByMicrosoftId failed: " . $e->getMessage());
        }

        // Try to find by email and link
        try {
            $user = $this->repository->findByEmail($email);
            if ($user !== null) {
                $user->linkMicrosoft($microsoftId);
                $this->repository->save($user);
                return $user;
            }
        } catch (\RuntimeException $e) {
            error_log("UserFacade::findOrCreateMicrosoftUser findByEmail failed: " . $e->getMessage());
        }

        // Create a new user from Microsoft
        $user = User::createFromMicrosoft($microsoftId, $username, $email);
        $this->repository->save($user);

        return $user;
    }

    // =========================================================================
    // Preferences Operations
    // =========================================================================

    /**
     * Get all user-scoped preferences.
     *
     * @return array<string, string> Preferences with current values
     */
    public function getUserPreferences(): array
    {
        return (new GetUserPreferences())->execute();
    }

    /**
     * Save user preferences from form submission.
     *
     * @return array{success: bool}
     */
    public function saveUserPreferences(): array
    {
        return (new SaveUserPreferences())->execute();
    }

    /**
     * Save user preferences from an array (API-friendly version).
     *
     * @param array<string, mixed> $data Settings keyed by name
     *
     * @return array{success: bool}
     */
    public function saveUserPreferencesFromData(array $data): array
    {
        return (new SaveUserPreferences())->executeFromData($data);
    }

    // =========================================================================
    // Password Operations
    // =========================================================================

    /**
     * Validate password strength.
     *
     * @param string $password Password to validate
     *
     * @return array{valid: bool, errors: string[]} Validation result
     */
    public function validatePasswordStrength(string $password): array
    {
        return $this->passwordHasher->validateStrength($password);
    }

    /**
     * Generate a secure random token.
     *
     * @param int<1, max> $length Token length in bytes
     *
     * @return string The generated token
     */
    public function generateToken(int $length = 32): string
    {
        return $this->passwordHasher->generateToken($length);
    }

    // =========================================================================
    // Use Case Getters (Lazy Initialization)
    // =========================================================================

    /**
     * @return Login
     */
    private function getLoginUseCase(): Login
    {
        if ($this->loginUseCase === null) {
            $this->loginUseCase = new Login($this->repository, $this->passwordHasher);
        }
        return $this->loginUseCase;
    }

    /**
     * @return Register
     */
    private function getRegisterUseCase(): Register
    {
        if ($this->registerUseCase === null) {
            $this->registerUseCase = new Register($this->repository, $this->passwordHasher);
        }
        return $this->registerUseCase;
    }

    /**
     * @return Logout
     */
    private function getLogoutUseCase(): Logout
    {
        if ($this->logoutUseCase === null) {
            $this->logoutUseCase = new Logout();
        }
        return $this->logoutUseCase;
    }

    /**
     * @return ValidateSession
     */
    private function getValidateSessionUseCase(): ValidateSession
    {
        if ($this->validateSessionUseCase === null) {
            $this->validateSessionUseCase = new ValidateSession($this->repository);
        }
        return $this->validateSessionUseCase;
    }

    /**
     * @return GetCurrentUser
     */
    private function getGetCurrentUserUseCase(): GetCurrentUser
    {
        if ($this->getCurrentUserUseCase === null) {
            $this->getCurrentUserUseCase = new GetCurrentUser($this->repository);
        }
        return $this->getCurrentUserUseCase;
    }

    /**
     * @return GenerateApiToken
     */
    private function getGenerateApiTokenUseCase(): GenerateApiToken
    {
        if ($this->generateApiTokenUseCase === null) {
            $this->generateApiTokenUseCase = new GenerateApiToken($this->repository, $this->tokenHasher);
        }
        return $this->generateApiTokenUseCase;
    }

    /**
     * @return ValidateApiToken
     */
    private function getValidateApiTokenUseCase(): ValidateApiToken
    {
        if ($this->validateApiTokenUseCase === null) {
            $this->validateApiTokenUseCase = new ValidateApiToken($this->repository, $this->tokenHasher);
        }
        return $this->validateApiTokenUseCase;
    }

    /**
     * @return RequestPasswordReset
     */
    private function getRequestPasswordResetUseCase(): RequestPasswordReset
    {
        if ($this->requestPasswordResetUseCase === null) {
            $this->requestPasswordResetUseCase = new RequestPasswordReset(
                $this->repository,
                $this->tokenHasher,
                new EmailService()
            );
        }
        return $this->requestPasswordResetUseCase;
    }

    /**
     * @return CompletePasswordReset
     */
    private function getCompletePasswordResetUseCase(): CompletePasswordReset
    {
        if ($this->completePasswordResetUseCase === null) {
            $this->completePasswordResetUseCase = new CompletePasswordReset(
                $this->repository,
                $this->tokenHasher,
                $this->passwordHasher
            );
        }
        return $this->completePasswordResetUseCase;
    }

    /**
     * @return GenerateRecoveryCode
     */
    private function getGenerateRecoveryCodeUseCase(): GenerateRecoveryCode
    {
        if ($this->generateRecoveryCodeUseCase === null) {
            $this->generateRecoveryCodeUseCase = new GenerateRecoveryCode($this->repository);
        }
        return $this->generateRecoveryCodeUseCase;
    }

    /**
     * @return ResetPasswordWithRecoveryCode
     */
    private function getResetPasswordWithRecoveryCodeUseCase(): ResetPasswordWithRecoveryCode
    {
        if ($this->resetPasswordWithRecoveryCodeUseCase === null) {
            $this->resetPasswordWithRecoveryCodeUseCase = new ResetPasswordWithRecoveryCode(
                $this->repository,
                null,
                $this->passwordHasher
            );
        }
        return $this->resetPasswordWithRecoveryCodeUseCase;
    }

    /**
     * @return SendVerificationEmail
     */
    private function getSendVerificationEmailUseCase(): SendVerificationEmail
    {
        if ($this->sendVerificationEmailUseCase === null) {
            $this->sendVerificationEmailUseCase = new SendVerificationEmail(
                $this->repository,
                $this->tokenHasher,
                new EmailService()
            );
        }
        return $this->sendVerificationEmailUseCase;
    }

    /**
     * @return VerifyEmail
     */
    private function getVerifyEmailUseCase(): VerifyEmail
    {
        if ($this->verifyEmailUseCase === null) {
            $this->verifyEmailUseCase = new VerifyEmail(
                $this->repository,
                $this->tokenHasher
            );
        }
        return $this->verifyEmailUseCase;
    }

    /**
     * @return UpdateProfile
     */
    private function getUpdateProfileUseCase(): UpdateProfile
    {
        if ($this->updateProfileUseCase === null) {
            $this->updateProfileUseCase = new UpdateProfile($this->repository);
        }
        return $this->updateProfileUseCase;
    }

    /**
     * @return ChangePassword
     */
    private function getChangePasswordUseCase(): ChangePassword
    {
        if ($this->changePasswordUseCase === null) {
            $this->changePasswordUseCase = new ChangePassword(
                $this->repository,
                $this->passwordHasher
            );
        }
        return $this->changePasswordUseCase;
    }
}
