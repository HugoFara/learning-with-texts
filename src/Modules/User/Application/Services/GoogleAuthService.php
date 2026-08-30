<?php

/**
 * Google OAuth Authentication Service
 *
 * Business logic for Google OAuth integration and authentication.
 *
 * PHP version 8.2
 *
 * @category Lwt
 * @package  Lwt\Modules\User\Application\Services
 * @author   HugoFara <git@hugofara.net>
 * @license  Unlicense <http://unlicense.org/>
 * @link     https://hugofara.github.io/lwt/developer/api
 * @since    3.0.0
 */

declare(strict_types=1);

namespace Lwt\Modules\User\Application\Services;

use League\OAuth2\Client\Provider\Google;
use League\OAuth2\Client\Provider\GoogleUser;
use Lwt\Modules\User\Domain\User;
use Lwt\Shared\Infrastructure\Globals;
use Lwt\Shared\Infrastructure\Http\UrlUtilities;
use Lwt\Modules\User\Application\UserFacade;

/**
 * Service class for Google OAuth authentication integration.
 *
 * Handles Google OAuth flow, user authentication, and account linking.
 *
 * @since 3.0.0
 */
class GoogleAuthService
{
    /**
     * Session key for OAuth state parameter.
     */
    private const SESSION_STATE_KEY = 'LWT-Google-State';

    /**
     * Session key for link mode flag.
     */
    private const SESSION_LINK_KEY = 'LWT-Google-Link';

    /**
     * Session key for pending link data.
     */
    private const SESSION_PENDING_LINK_KEY = 'google_link_pending';

    /**
     * @var UserFacade User facade for LWT user management
     */
    private UserFacade $userFacade;

    /**
     * @var Google|null Cached Google OAuth provider
     */
    private ?Google $provider = null;

    /**
     * Create a new GoogleAuthService.
     *
     * @param UserFacade $userFacade User facade for user management
     */
    public function __construct(UserFacade $userFacade)
    {
        $this->userFacade = $userFacade;
    }

    /**
     * Get the user facade.
     *
     * @return UserFacade
     */
    public function getUserFacade(): UserFacade
    {
        return $this->userFacade;
    }

    /**
     * Check if Google OAuth is configured.
     *
     * @return bool True if Google OAuth credentials are set
     */
    public function isConfigured(): bool
    {
        $clientId = $_ENV['GOOGLE_CLIENT_ID'] ?? '';
        $clientSecret = $_ENV['GOOGLE_CLIENT_SECRET'] ?? '';

        return $clientId !== '' && $clientSecret !== '';
    }

    /**
     * Get the Google OAuth provider.
     *
     * @return Google
     */
    private function getProvider(): Google
    {
        if ($this->provider === null) {
            $this->provider = new Google([
                'clientId'     => $_ENV['GOOGLE_CLIENT_ID'] ?? '',
                'clientSecret' => $_ENV['GOOGLE_CLIENT_SECRET'] ?? '',
                'redirectUri'  => $_ENV['GOOGLE_REDIRECT_URI']
                    ?? $this->getDefaultRedirectUri(),
            ]);
        }
        return $this->provider;
    }

    /**
     * Get the default redirect URI based on current request.
     *
     * @return string
     */
    private function getDefaultRedirectUri(): string
    {
        $origin = UrlUtilities::getAppOrigin();
        return "{$origin}/google/callback";
    }

    /**
     * Get the authorization URL for Google OAuth.
     *
     * @param bool $linkMode If true, link to existing account instead of login
     *
     * @return string The authorization URL
     */
    public function getAuthorizationUrl(bool $linkMode = false): string
    {
        $provider = $this->getProvider();

        $options = [
            'scope' => ['email', 'profile'],
        ];

        $authUrl = $provider->getAuthorizationUrl($options);

        // Store state for CSRF protection
        $this->startSession();
        $_SESSION[self::SESSION_STATE_KEY] = $provider->getState();
        $_SESSION[self::SESSION_LINK_KEY] = $linkMode;

        return $authUrl;
    }

    /**
     * Handle the OAuth callback.
     *
     * @param string $code  Authorization code from Google
     * @param string $state State parameter for CSRF validation
     *
     * @return array{success: bool, redirect: string, error: string|null, user: User|null}
     */
    public function handleCallback(string $code, string $state): array
    {
        $this->startSession();

        // Validate state
        /** @var string $storedState */
        $storedState = $_SESSION[self::SESSION_STATE_KEY] ?? '';
        /** @var bool $linkMode */
        $linkMode = (bool) ($_SESSION[self::SESSION_LINK_KEY] ?? false);

        unset($_SESSION[self::SESSION_STATE_KEY], $_SESSION[self::SESSION_LINK_KEY]);

        if (empty($state) || $storedState === '' || !hash_equals($storedState, $state)) {
            return [
                'success' => false,
                'redirect' => '/login',
                'error' => 'Invalid state parameter. Please try again.',
                'user' => null,
            ];
        }

        try {
            $provider = $this->getProvider();
            $token = $provider->getAccessToken('authorization_code', [
                'code' => $code,
            ]);

            /** @var \League\OAuth2\Client\Token\AccessToken $accessToken */
            $accessToken = $token;
            /** @var GoogleUser $googleUser */
            $googleUser = $provider->getResourceOwner($accessToken);

            /** @var string $googleId */
            $googleId = (string) $googleUser->getId();
            /** @var string $email */
            $email = (string) $googleUser->getEmail();
            $name = $googleUser->getName();

            if ($googleId === '' || $email === '') {
                return [
                    'success' => false,
                    'redirect' => '/login',
                    'error' => 'Could not retrieve Google account information.',
                    'user' => null,
                ];
            }

            // Handle link mode (existing user linking their Google account)
            if ($linkMode && Globals::isAuthenticated()) {
                return $this->handleLinkAccount($googleId);
            }

            // Handle login/registration
            return $this->handleLoginOrRegister($googleId, $email, $name);
        } catch (\Exception $e) {
            return [
                'success' => false,
                'redirect' => '/login',
                'error' => 'Google authentication failed: ' . $e->getMessage(),
                'user' => null,
            ];
        }
    }

    /**
     * Handle linking Google account to existing LWT user.
     *
     * @param string $googleId Google user ID
     *
     * @return array{success: bool, redirect: string, error: string|null, user: User|null}
     */
    private function handleLinkAccount(string $googleId): array
    {
        // Check if Google account is already linked to another user
        $existingUser = $this->userFacade->findByGoogleId($googleId);
        if ($existingUser !== null) {
            return [
                'success' => false,
                'redirect' => '/',
                'error' => 'This Google account is already linked to another user.',
                'user' => null,
            ];
        }

        $currentUser = $this->userFacade->getCurrentUser();
        if ($currentUser === null) {
            return [
                'success' => false,
                'redirect' => '/login',
                'error' => 'Session expired. Please log in again.',
                'user' => null,
            ];
        }

        $currentUser->linkGoogle($googleId);
        $this->userFacade->save($currentUser);

        return [
            'success' => true,
            'redirect' => '/',
            'error' => null,
            'user' => $currentUser,
        ];
    }

    /**
     * Handle Google login or new user registration.
     *
     * @param string $googleId Google user ID
     * @param string $email    Email from Google
     * @param string $name     Name from Google
     *
     * @return array{success: bool, redirect: string, error: string|null, user: User|null}
     */
    private function handleLoginOrRegister(
        string $googleId,
        string $email,
        string $name
    ): array {
        // Try to find existing user by Google ID
        $user = $this->userFacade->findByGoogleId($googleId);

        if ($user !== null) {
            // Existing Google user - log them in
            if (!$user->isActive()) {
                return [
                    'success' => false,
                    'redirect' => '/login',
                    'error' => 'Your account has been deactivated.',
                    'user' => null,
                ];
            }

            $this->userFacade->setCurrentUser($user);
            $user->recordLogin();
            $this->userFacade->save($user);

            // Regenerate session for security
            if (session_status() === PHP_SESSION_ACTIVE) {
                session_regenerate_id(true);
            }
            $_SESSION['LWT_USER_ID'] = $user->id()->toInt();

            return [
                'success' => true,
                'redirect' => '/',
                'error' => null,
                'user' => $user,
            ];
        }

        // Check if email exists (offer to link accounts)
        $userByEmail = $this->userFacade->findByEmail($email);
        if ($userByEmail !== null) {
            // Store Google info in session for account linking
            $_SESSION[self::SESSION_PENDING_LINK_KEY] = [
                'google_id' => $googleId,
                'email' => $email,
            ];

            return [
                'success' => false,
                'redirect' => '/google/link-confirm',
                'error' => null,
                'user' => null,
            ];
        }

        // Create new user from Google
        $username = $this->generateUsername($email, $name);
        $user = $this->userFacade->findOrCreateGoogleUser(
            $googleId,
            $username,
            $email
        );

        $this->userFacade->setCurrentUser($user);

        // Regenerate session for security
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_regenerate_id(true);
        }
        $_SESSION['LWT_USER_ID'] = $user->id()->toInt();

        return [
            'success' => true,
            'redirect' => '/',
            'error' => null,
            'user' => $user,
        ];
    }

    /**
     * Get pending link data from session.
     *
     * @return array{google_id: string, email: string}|null
     */
    public function getPendingLinkData(): ?array
    {
        $this->startSession();
        $data = $_SESSION[self::SESSION_PENDING_LINK_KEY] ?? null;

        if (!is_array($data) || empty($data['google_id']) || empty($data['email'])) {
            return null;
        }

        return [
            'google_id' => (string) $data['google_id'],
            'email' => (string) $data['email'],
        ];
    }

    /**
     * Clear pending link data from session.
     *
     * @return void
     */
    public function clearPendingLinkData(): void
    {
        $this->startSession();
        unset($_SESSION[self::SESSION_PENDING_LINK_KEY]);
    }

    /**
     * Link Google account after password verification.
     *
     * @param string $googleId Google user ID
     * @param User   $user     User to link
     *
     * @return void
     */
    public function linkGoogleToUser(string $googleId, User $user): void
    {
        $user->linkGoogle($googleId);
        $this->userFacade->save($user);
    }

    /**
     * Generate a username from email or name.
     *
     * @param string $email Email address
     * @param string $name  Name from Google
     *
     * @return string Generated username
     */
    private function generateUsername(string $email, string $name): string
    {
        // Try to use the part before @ in email
        $base = strstr($email, '@', true);
        if ($base === false || $base === '') {
            $base = 'user';
        }

        // Sanitize: only allow letters, numbers, underscores, hyphens
        $base = preg_replace('/[^a-zA-Z0-9_-]/', '', $base);
        if ($base === null || $base === '') {
            $base = 'google_user';
        }

        // Ensure minimum length
        if (strlen($base) < 3) {
            $base = 'google_user';
        }

        // Truncate if too long
        $base = substr($base, 0, 90);

        // Check if username exists and append number if needed
        $username = $base;
        $counter = 1;

        while ($this->userFacade->findByUsername($username) !== null) {
            $username = $base . '_' . $counter;
            $counter++;
        }

        return $username;
    }

    /**
     * Start a PHP session.
     *
     * @return void
     */
    private function startSession(): void
    {
        if (session_status() === PHP_SESSION_NONE) {
            @session_start();
        }
    }
}
