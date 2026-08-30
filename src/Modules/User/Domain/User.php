<?php

/**
 * User Entity
 *
 * PHP version 8.2
 *
 * @category Lwt
 * @package  Lwt\Entity
 * @author   HugoFara <git@hugofara.net>
 * @license  Unlicense <http://unlicense.org/>
 * @link     https://hugofara.github.io/lwt/developer/api
 * @since    3.0.0
 */

declare(strict_types=1);

namespace Lwt\Modules\User\Domain;

use DateTimeImmutable;
use InvalidArgumentException;
use Lwt\Shared\Domain\ValueObjects\UserId;

/**
 * A user represented as a rich domain object.
 *
 * Users own all learning data (languages, texts, words, etc.) and can
 * authenticate via built-in auth or WordPress integration.
 *
 * This class enforces domain invariants and encapsulates business logic.
 *
 * @since 3.0.0
 */
class User
{
    public const ROLE_USER = 'user';
    public const ROLE_ADMIN = 'admin';

    private UserId $id;
    private string $username;
    private ?string $email;
    private ?string $passwordHash;
    private ?string $apiToken;
    private ?DateTimeImmutable $apiTokenExpires;
    private ?string $rememberToken;
    private ?DateTimeImmutable $rememberTokenExpires;
    private ?string $passwordResetToken;
    private ?DateTimeImmutable $passwordResetTokenExpires;
    private ?DateTimeImmutable $emailVerifiedAt;
    private ?string $emailVerificationToken;
    private ?DateTimeImmutable $emailVerificationTokenExpires;
    private ?int $wordPressId;
    private ?string $googleId;
    private ?string $microsoftId;
    private DateTimeImmutable $created;
    private ?DateTimeImmutable $lastLogin;
    private bool $isActive;
    private string $role;

    /**
     * Hashed one-time recovery code, for accounts registered without an email
     * (their only password-recovery channel). Hydrated by the repository after
     * reconstitution, so it is not a constructor parameter.
     */
    private ?string $recoveryCodeHash = null;

    /**
     * Private constructor - use factory methods instead.
     */
    private function __construct(
        UserId $id,
        string $username,
        ?string $email,
        ?string $passwordHash,
        ?string $apiToken,
        ?DateTimeImmutable $apiTokenExpires,
        ?string $rememberToken,
        ?DateTimeImmutable $rememberTokenExpires,
        ?string $passwordResetToken,
        ?DateTimeImmutable $passwordResetTokenExpires,
        ?DateTimeImmutable $emailVerifiedAt,
        ?string $emailVerificationToken,
        ?DateTimeImmutable $emailVerificationTokenExpires,
        ?int $wordPressId,
        ?string $googleId,
        ?string $microsoftId,
        DateTimeImmutable $created,
        ?DateTimeImmutable $lastLogin,
        bool $isActive,
        string $role
    ) {
        $this->id = $id;
        $this->username = $username;
        $this->email = $email;
        $this->passwordHash = $passwordHash;
        $this->apiToken = $apiToken;
        $this->apiTokenExpires = $apiTokenExpires;
        $this->rememberToken = $rememberToken;
        $this->rememberTokenExpires = $rememberTokenExpires;
        $this->passwordResetToken = $passwordResetToken;
        $this->passwordResetTokenExpires = $passwordResetTokenExpires;
        $this->emailVerifiedAt = $emailVerifiedAt;
        $this->emailVerificationToken = $emailVerificationToken;
        $this->emailVerificationTokenExpires = $emailVerificationTokenExpires;
        $this->wordPressId = $wordPressId;
        $this->googleId = $googleId;
        $this->microsoftId = $microsoftId;
        $this->created = $created;
        $this->lastLogin = $lastLogin;
        $this->isActive = $isActive;
        $this->role = $role;
    }

    /**
     * Create a new user with username, email, and password.
     *
     * @param string $username     The username
     * @param string $email        The email address
     * @param string $passwordHash The hashed password
     *
     * @return self
     *
     * @throws InvalidArgumentException If username or email is invalid
     */
    public static function create(
        string $username,
        ?string $email,
        string $passwordHash
    ): self {
        $trimmedUsername = trim($username);
        $trimmedEmail = $email !== null ? trim($email) : '';

        self::validateUsername($trimmedUsername);
        // Email is optional (username is the unique identity). Validate only
        // when one is supplied; an empty email is stored as NULL so multiple
        // email-less accounts don't collide on the UsEmail unique key.
        if ($trimmedEmail !== '') {
            self::validateEmail($trimmedEmail);
        }

        return new self(
            UserId::new(),
            $trimmedUsername,
            $trimmedEmail !== '' ? strtolower($trimmedEmail) : null,
            $passwordHash,
            null,
            null,
            null,
            null,
            null,
            null,
            null, // emailVerifiedAt
            null, // emailVerificationToken
            null, // emailVerificationTokenExpires
            null,
            null,
            null,
            new DateTimeImmutable(),
            null,
            true,
            self::ROLE_USER
        );
    }

    /**
     * Create a user from WordPress integration.
     *
     * @param int    $wordPressId The WordPress user ID
     * @param string $username    The username
     * @param string $email       The email address
     *
     * @return self
     *
     * @throws InvalidArgumentException If WordPress ID is invalid
     */
    public static function createFromWordPress(
        int $wordPressId,
        string $username,
        string $email
    ): self {
        if ($wordPressId <= 0) {
            throw new InvalidArgumentException('WordPress user ID must be positive');
        }

        $trimmedUsername = trim($username);
        $trimmedEmail = trim($email);

        self::validateUsername($trimmedUsername);
        self::validateEmail($trimmedEmail);

        return new self(
            UserId::new(),
            $trimmedUsername,
            strtolower($trimmedEmail),
            null, // No password for WordPress users
            null,
            null,
            null,
            null,
            null,
            null,
            new DateTimeImmutable(), // OAuth users auto-verified
            null,
            null,
            $wordPressId,
            null,
            null,
            new DateTimeImmutable(),
            null,
            true,
            self::ROLE_USER
        );
    }

    /**
     * Create a user from Google OAuth.
     *
     * @param string $googleId The Google user ID
     * @param string $username The username
     * @param string $email    The email address
     *
     * @return self
     *
     * @throws InvalidArgumentException If Google ID is empty
     */
    public static function createFromGoogle(
        string $googleId,
        string $username,
        string $email
    ): self {
        if ($googleId === '') {
            throw new InvalidArgumentException('Google user ID cannot be empty');
        }

        $trimmedUsername = trim($username);
        $trimmedEmail = trim($email);

        self::validateUsername($trimmedUsername);
        self::validateEmail($trimmedEmail);

        return new self(
            UserId::new(),
            $trimmedUsername,
            strtolower($trimmedEmail),
            null, // No password for Google users
            null,
            null,
            null,
            null,
            null,
            null,
            new DateTimeImmutable(), // OAuth users auto-verified
            null,
            null,
            null,
            $googleId,
            null,
            new DateTimeImmutable(),
            null,
            true,
            self::ROLE_USER
        );
    }

    /**
     * Create a user from Microsoft OAuth.
     *
     * @param string $microsoftId The Microsoft user ID
     * @param string $username    The username
     * @param string $email       The email address
     *
     * @return self
     *
     * @throws InvalidArgumentException If Microsoft ID is empty
     */
    public static function createFromMicrosoft(
        string $microsoftId,
        string $username,
        string $email
    ): self {
        if ($microsoftId === '') {
            throw new InvalidArgumentException('Microsoft user ID cannot be empty');
        }

        $trimmedUsername = trim($username);
        $trimmedEmail = trim($email);

        self::validateUsername($trimmedUsername);
        self::validateEmail($trimmedEmail);

        return new self(
            UserId::new(),
            $trimmedUsername,
            strtolower($trimmedEmail),
            null, // No password for Microsoft users
            null,
            null,
            null,
            null,
            null,
            null,
            new DateTimeImmutable(), // OAuth users auto-verified
            null,
            null,
            null,
            null,
            $microsoftId,
            new DateTimeImmutable(),
            null,
            true,
            self::ROLE_USER
        );
    }

    /**
     * Reconstitute a user from persistence.
     *
     * @param int                    $id                          The user ID
     * @param string                 $username                    The username
     * @param string                 $email                       The email
     * @param string|null            $passwordHash                The password hash
     * @param string|null            $apiToken                    The API token
     * @param DateTimeImmutable|null $apiTokenExpires             When the API token expires
     * @param string|null            $rememberToken               The remember-me token
     * @param DateTimeImmutable|null $rememberTokenExpires        When the remember token expires
     * @param string|null            $passwordResetToken          The password reset token
     * @param DateTimeImmutable|null $passwordResetTokenExpires   When the reset token expires
     * @param int|null               $wordPressId                 The WordPress user ID
     * @param string|null            $googleId                    The Google user ID
     * @param string|null            $microsoftId                 The Microsoft user ID
     * @param DateTimeImmutable      $created              When the user was created
     * @param DateTimeImmutable|null $lastLogin            Last login time
     * @param bool                   $isActive             Whether the user is active
     * @param string                 $role                 The user role
     *
     * @return self
     *
     * @internal This method is for repository use only
     */
    public static function reconstitute(
        int $id,
        string $username,
        ?string $email,
        ?string $passwordHash,
        ?string $apiToken,
        ?DateTimeImmutable $apiTokenExpires,
        ?string $rememberToken,
        ?DateTimeImmutable $rememberTokenExpires,
        ?string $passwordResetToken,
        ?DateTimeImmutable $passwordResetTokenExpires,
        ?DateTimeImmutable $emailVerifiedAt,
        ?string $emailVerificationToken,
        ?DateTimeImmutable $emailVerificationTokenExpires,
        ?int $wordPressId,
        ?string $googleId,
        ?string $microsoftId,
        DateTimeImmutable $created,
        ?DateTimeImmutable $lastLogin,
        bool $isActive,
        string $role
    ): self {
        return new self(
            UserId::fromInt($id),
            $username,
            $email,
            $passwordHash,
            $apiToken,
            $apiTokenExpires,
            $rememberToken,
            $rememberTokenExpires,
            $passwordResetToken,
            $passwordResetTokenExpires,
            $emailVerifiedAt,
            $emailVerificationToken,
            $emailVerificationTokenExpires,
            $wordPressId,
            $googleId,
            $microsoftId,
            $created,
            $lastLogin,
            $isActive,
            $role
        );
    }

    // Domain behavior methods

    /**
     * Update the username.
     *
     * @param string $username The new username
     *
     * @return void
     *
     * @throws InvalidArgumentException If username is invalid
     */
    public function changeUsername(string $username): void
    {
        $trimmedUsername = trim($username);
        self::validateUsername($trimmedUsername);
        $this->username = $trimmedUsername;
    }

    /**
     * Update the email address.
     *
     * @param string $email The new email address
     *
     * @return void
     *
     * @throws InvalidArgumentException If email is invalid
     */
    public function changeEmail(string $email): void
    {
        $trimmedEmail = trim($email);
        self::validateEmail($trimmedEmail);
        $this->email = strtolower($trimmedEmail);
    }

    /**
     * Update the password hash.
     *
     * @param string $passwordHash The new password hash
     *
     * @return void
     */
    public function changePassword(string $passwordHash): void
    {
        $this->passwordHash = $passwordHash;
    }

    /**
     * Set a new API token.
     *
     * @param string            $token   The API token
     * @param DateTimeImmutable $expires When the token expires
     *
     * @return void
     */
    public function setApiToken(string $token, DateTimeImmutable $expires): void
    {
        $this->apiToken = $token;
        $this->apiTokenExpires = $expires;
    }

    /**
     * Invalidate the API token.
     *
     * @return void
     */
    public function invalidateApiToken(): void
    {
        $this->apiToken = null;
        $this->apiTokenExpires = null;
    }

    /**
     * Set a new remember-me token.
     *
     * @param string            $token   The remember token
     * @param DateTimeImmutable $expires When the token expires
     *
     * @return void
     */
    public function setRememberToken(string $token, DateTimeImmutable $expires): void
    {
        $this->rememberToken = $token;
        $this->rememberTokenExpires = $expires;
    }

    /**
     * Invalidate the remember-me token.
     *
     * @return void
     */
    public function invalidateRememberToken(): void
    {
        $this->rememberToken = null;
        $this->rememberTokenExpires = null;
    }

    /**
     * Set a new password reset token.
     *
     * @param string            $token   The password reset token (hashed)
     * @param DateTimeImmutable $expires When the token expires
     *
     * @return void
     */
    public function setPasswordResetToken(string $token, DateTimeImmutable $expires): void
    {
        $this->passwordResetToken = $token;
        $this->passwordResetTokenExpires = $expires;
    }

    /**
     * Invalidate the password reset token.
     *
     * @return void
     */
    public function invalidatePasswordResetToken(): void
    {
        $this->passwordResetToken = null;
        $this->passwordResetTokenExpires = null;
    }

    /**
     * Check if the password reset token is valid (not expired).
     *
     * @return bool
     */
    public function hasValidPasswordResetToken(): bool
    {
        if ($this->passwordResetToken === null || $this->passwordResetTokenExpires === null) {
            return false;
        }
        return $this->passwordResetTokenExpires > new DateTimeImmutable();
    }

    /**
     * The stored (hashed) one-time recovery code, or null if none is set.
     */
    public function recoveryCodeHash(): ?string
    {
        return $this->recoveryCodeHash;
    }

    /**
     * Set (or clear) the stored hashed recovery code.
     *
     * @param string|null $hash Hashed recovery code, or null to clear it.
     */
    public function setRecoveryCodeHash(?string $hash): void
    {
        $this->recoveryCodeHash = $hash;
    }

    /**
     * Whether this account has a recovery code on file.
     */
    public function hasRecoveryCode(): bool
    {
        return $this->recoveryCodeHash !== null;
    }

    /**
     * Set a new email verification token.
     *
     * @param string            $token   The verification token (hashed)
     * @param DateTimeImmutable $expires When the token expires
     *
     * @return void
     */
    public function setEmailVerificationToken(string $token, DateTimeImmutable $expires): void
    {
        $this->emailVerificationToken = $token;
        $this->emailVerificationTokenExpires = $expires;
    }

    /**
     * Invalidate the email verification token.
     *
     * @return void
     */
    public function invalidateEmailVerificationToken(): void
    {
        $this->emailVerificationToken = null;
        $this->emailVerificationTokenExpires = null;
    }

    /**
     * Check if the email verification token is valid (not expired).
     *
     * @return bool
     */
    public function hasValidEmailVerificationToken(): bool
    {
        if ($this->emailVerificationToken === null || $this->emailVerificationTokenExpires === null) {
            return false;
        }
        return $this->emailVerificationTokenExpires > new DateTimeImmutable();
    }

    /**
     * Check if the user's email is verified.
     *
     * @return bool
     */
    public function isEmailVerified(): bool
    {
        return $this->emailVerifiedAt !== null;
    }

    /**
     * Mark the email as verified.
     *
     * @return void
     */
    public function markEmailVerified(): void
    {
        $this->emailVerifiedAt = new DateTimeImmutable();
        $this->invalidateEmailVerificationToken();
    }

    /**
     * Mark the email as unverified (e.g. after email change).
     *
     * @return void
     */
    public function markEmailUnverified(): void
    {
        $this->emailVerifiedAt = null;
    }

    /**
     * Record a login.
     *
     * @return void
     */
    public function recordLogin(): void
    {
        $this->lastLogin = new DateTimeImmutable();
    }

    /**
     * Activate the user account.
     *
     * @return void
     */
    public function activate(): void
    {
        $this->isActive = true;
    }

    /**
     * Deactivate the user account.
     *
     * @return void
     */
    public function deactivate(): void
    {
        $this->isActive = false;
    }

    /**
     * Promote the user to admin.
     *
     * @return void
     */
    public function promoteToAdmin(): void
    {
        $this->role = self::ROLE_ADMIN;
    }

    /**
     * Demote the user from admin.
     *
     * @return void
     */
    public function demoteFromAdmin(): void
    {
        $this->role = self::ROLE_USER;
    }

    /**
     * Link to a WordPress account.
     *
     * @param int $wordPressId The WordPress user ID
     *
     * @return void
     *
     * @throws InvalidArgumentException If WordPress ID is invalid
     */
    public function linkWordPress(int $wordPressId): void
    {
        if ($wordPressId <= 0) {
            throw new InvalidArgumentException('WordPress user ID must be positive');
        }
        $this->wordPressId = $wordPressId;
    }

    /**
     * Unlink from WordPress account.
     *
     * @return void
     */
    public function unlinkWordPress(): void
    {
        $this->wordPressId = null;
    }

    /**
     * Link to a Google account.
     *
     * @param string $googleId The Google user ID
     *
     * @return void
     *
     * @throws InvalidArgumentException If Google ID is empty
     */
    public function linkGoogle(string $googleId): void
    {
        if ($googleId === '') {
            throw new InvalidArgumentException('Google user ID cannot be empty');
        }
        $this->googleId = $googleId;
    }

    /**
     * Unlink from Google account.
     *
     * @return void
     */
    public function unlinkGoogle(): void
    {
        $this->googleId = null;
    }

    /**
     * Link to a Microsoft account.
     *
     * @param string $microsoftId The Microsoft user ID
     *
     * @return void
     *
     * @throws InvalidArgumentException If Microsoft ID is empty
     */
    public function linkMicrosoft(string $microsoftId): void
    {
        if ($microsoftId === '') {
            throw new InvalidArgumentException('Microsoft user ID cannot be empty');
        }
        $this->microsoftId = $microsoftId;
    }

    /**
     * Unlink from Microsoft account.
     *
     * @return void
     */
    public function unlinkMicrosoft(): void
    {
        $this->microsoftId = null;
    }

    // Query methods

    /**
     * Check if the user is an admin.
     *
     * @return bool
     */
    public function isAdmin(): bool
    {
        return $this->role === self::ROLE_ADMIN;
    }

    /**
     * Check if the user is linked to WordPress.
     *
     * @return bool
     */
    public function isLinkedToWordPress(): bool
    {
        return $this->wordPressId !== null;
    }

    /**
     * Check if the user is linked to Google.
     *
     * @return bool
     */
    public function isLinkedToGoogle(): bool
    {
        return $this->googleId !== null;
    }

    /**
     * Check if the user is linked to Microsoft.
     *
     * @return bool
     */
    public function isLinkedToMicrosoft(): bool
    {
        return $this->microsoftId !== null;
    }

    /**
     * Check if the user has a password set.
     *
     * @return bool
     */
    public function hasPassword(): bool
    {
        return $this->passwordHash !== null;
    }

    /**
     * Check if the API token is valid (not expired).
     *
     * @return bool
     */
    public function hasValidApiToken(): bool
    {
        if ($this->apiToken === null || $this->apiTokenExpires === null) {
            return false;
        }
        return $this->apiTokenExpires > new DateTimeImmutable();
    }

    /**
     * Check if the remember-me token is valid (not expired).
     *
     * @return bool
     */
    public function hasValidRememberToken(): bool
    {
        if ($this->rememberToken === null || $this->rememberTokenExpires === null) {
            return false;
        }
        return $this->rememberTokenExpires > new DateTimeImmutable();
    }

    /**
     * Check if the user can log in.
     *
     * @return bool
     */
    public function canLogin(): bool
    {
        return $this->isActive;
    }

    // Validation methods

    /**
     * Validate a username.
     *
     * @param string $username The username to validate
     *
     * @return void
     *
     * @throws InvalidArgumentException If username is invalid
     */
    private static function validateUsername(string $username): void
    {
        if ($username === '') {
            throw new InvalidArgumentException('Username cannot be empty');
        }
        if (strlen($username) < 3) {
            throw new InvalidArgumentException('Username must be at least 3 characters');
        }
        if (strlen($username) > 100) {
            throw new InvalidArgumentException('Username cannot exceed 100 characters');
        }
        if (!preg_match('/^[a-zA-Z0-9_-]+$/', $username)) {
            throw new InvalidArgumentException(
                'Username can only contain letters, numbers, underscores, and hyphens'
            );
        }
    }

    /**
     * Validate an email address.
     *
     * @param string $email The email to validate
     *
     * @return void
     *
     * @throws InvalidArgumentException If email is invalid
     */
    private static function validateEmail(string $email): void
    {
        if ($email === '') {
            throw new InvalidArgumentException('Email cannot be empty');
        }
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new InvalidArgumentException('Invalid email address format');
        }
        if (strlen($email) > 255) {
            throw new InvalidArgumentException('Email cannot exceed 255 characters');
        }
    }

    // Getters

    public function id(): UserId
    {
        return $this->id;
    }

    public function username(): string
    {
        return $this->username;
    }

    public function email(): ?string
    {
        return $this->email;
    }

    public function passwordHash(): ?string
    {
        return $this->passwordHash;
    }

    public function apiToken(): ?string
    {
        return $this->apiToken;
    }

    public function apiTokenExpires(): ?DateTimeImmutable
    {
        return $this->apiTokenExpires;
    }

    public function rememberToken(): ?string
    {
        return $this->rememberToken;
    }

    public function rememberTokenExpires(): ?DateTimeImmutable
    {
        return $this->rememberTokenExpires;
    }

    public function passwordResetToken(): ?string
    {
        return $this->passwordResetToken;
    }

    public function passwordResetTokenExpires(): ?DateTimeImmutable
    {
        return $this->passwordResetTokenExpires;
    }

    public function emailVerifiedAt(): ?DateTimeImmutable
    {
        return $this->emailVerifiedAt;
    }

    public function emailVerificationToken(): ?string
    {
        return $this->emailVerificationToken;
    }

    public function emailVerificationTokenExpires(): ?DateTimeImmutable
    {
        return $this->emailVerificationTokenExpires;
    }

    public function wordPressId(): ?int
    {
        return $this->wordPressId;
    }

    public function googleId(): ?string
    {
        return $this->googleId;
    }

    public function microsoftId(): ?string
    {
        return $this->microsoftId;
    }

    public function created(): DateTimeImmutable
    {
        return $this->created;
    }

    public function lastLogin(): ?DateTimeImmutable
    {
        return $this->lastLogin;
    }

    public function isActive(): bool
    {
        return $this->isActive;
    }

    public function role(): string
    {
        return $this->role;
    }

    /**
     * Internal method to set the ID after persistence.
     *
     * @param UserId $id The new ID
     *
     * @return void
     *
     * @internal This method is for repository use only
     */
    public function setId(UserId $id): void
    {
        if (!$this->id->isNew()) {
            throw new \LogicException('Cannot change ID of a persisted user');
        }
        $this->id = $id;
    }
}
