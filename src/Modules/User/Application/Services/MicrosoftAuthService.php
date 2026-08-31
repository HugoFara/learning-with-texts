<?php

/**
 * Microsoft OAuth Authentication Service
 *
 * Business logic for Microsoft OAuth integration and authentication.
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

use TheNetworg\OAuth2\Client\Provider\Azure;
use Lwt\Modules\User\Domain\User;
use Lwt\Shared\Infrastructure\Globals;
use Lwt\Shared\Infrastructure\Http\UrlUtilities;
use Lwt\Modules\User\Application\UserFacade;

/**
 * Service class for Microsoft OAuth authentication integration.
 *
 * Handles Microsoft OAuth flow, user authentication, and account linking.
 *
 * @since 3.0.0
 */
class MicrosoftAuthService
{
    /**
     * Session key for OAuth state parameter.
     */
    private const SESSION_STATE_KEY = 'LWT-Microsoft-State';

    /**
     * Session key for link mode flag.
     */
    private const SESSION_LINK_KEY = 'LWT-Microsoft-Link';

    /**
     * Session key for pending link data.
     */
    private const SESSION_PENDING_LINK_KEY = 'microsoft_link_pending';

    /**
     * @var UserFacade User facade for LWT user management
     */
    private UserFacade $userFacade;

    /**
     * @var Azure|null Cached Microsoft OAuth provider
     */
    private ?Azure $provider = null;

    /**
     * Create a new MicrosoftAuthService.
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
     * Check if Microsoft OAuth is configured.
     *
     * @return bool True if Microsoft OAuth credentials are set
     */
    public function isConfigured(): bool
    {
        $clientId = $_ENV['MICROSOFT_CLIENT_ID'] ?? '';
        $clientSecret = $_ENV['MICROSOFT_CLIENT_SECRET'] ?? '';

        return $clientId !== '' && $clientSecret !== '';
    }

    /**
     * Get the Microsoft OAuth provider.
     *
     * @return Azure
     */
    private function getProvider(): Azure
    {
        if ($this->provider === null) {
            $this->provider = new Azure([
                'clientId'     => $_ENV['MICROSOFT_CLIENT_ID'] ?? '',
                'clientSecret' => $_ENV['MICROSOFT_CLIENT_SECRET'] ?? '',
                'redirectUri'  => $_ENV['MICROSOFT_REDIRECT_URI']
                    ?? $this->getDefaultRedirectUri(),
                'tenant'       => $_ENV['MICROSOFT_TENANT'] ?? 'common',
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
        return "{$origin}/microsoft/callback";
    }

    /**
     * Get the authorization URL for Microsoft OAuth.
     *
     * @param bool $linkMode If true, link to existing account instead of login
     *
     * @return string The authorization URL
     */
    public function getAuthorizationUrl(bool $linkMode = false): string
    {
        $provider = $this->getProvider();

        $options = [
            'scope' => ['openid', 'profile', 'email'],
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
     * @param string $code  Authorization code from Microsoft
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

            // Get user info from Microsoft
            /** @var array<string, mixed> $me */
            $me = $provider->get('me', $token);

            /** @var string $microsoftId */
            $microsoftId = (string) ($me['id'] ?? '');
            /** @var string $email */
            $email = (string) ($me['mail'] ?? $me['userPrincipalName'] ?? '');
            $name = (string) ($me['displayName'] ?? '');

            if ($microsoftId === '' || $email === '') {
                return [
                    'success' => false,
                    'redirect' => '/login',
                    'error' => 'Could not retrieve Microsoft account information.',
                    'user' => null,
                ];
            }

            // Handle link mode (existing user linking their Microsoft account)
            if ($linkMode && Globals::isAuthenticated()) {
                return $this->handleLinkAccount($microsoftId);
            }

            // Handle login/registration
            return $this->handleLoginOrRegister($microsoftId, $email, $name);
        } catch (\Exception $e) {
            return [
                'success' => false,
                'redirect' => '/login',
                'error' => 'Microsoft authentication failed: ' . $e->getMessage(),
                'user' => null,
            ];
        }
    }

    /**
     * Handle linking Microsoft account to existing LWT user.
     *
     * @param string $microsoftId Microsoft user ID
     *
     * @return array{success: bool, redirect: string, error: string|null, user: User|null}
     */
    private function handleLinkAccount(string $microsoftId): array
    {
        // Check if Microsoft account is already linked to another user
        $existingUser = $this->userFacade->findByMicrosoftId($microsoftId);
        if ($existingUser !== null) {
            return [
                'success' => false,
                'redirect' => '/',
                'error' => 'This Microsoft account is already linked to another user.',
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

        $currentUser->linkMicrosoft($microsoftId);
        $this->userFacade->save($currentUser);

        return [
            'success' => true,
            'redirect' => '/',
            'error' => null,
            'user' => $currentUser,
        ];
    }

    /**
     * Handle Microsoft login or new user registration.
     *
     * @param string $microsoftId Microsoft user ID
     * @param string $email       Email from Microsoft
     * @param string $name        Name from Microsoft
     *
     * @return array{success: bool, redirect: string, error: string|null, user: User|null}
     */
    private function handleLoginOrRegister(
        string $microsoftId,
        string $email,
        string $name
    ): array {
        // Try to find existing user by Microsoft ID
        $user = $this->userFacade->findByMicrosoftId($microsoftId);

        if ($user !== null) {
            // Existing Microsoft user - log them in
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
            // Store Microsoft info in session for account linking
            $_SESSION[self::SESSION_PENDING_LINK_KEY] = [
                'microsoft_id' => $microsoftId,
                'email' => $email,
            ];

            return [
                'success' => false,
                'redirect' => '/microsoft/link-confirm',
                'error' => null,
                'user' => null,
            ];
        }

        // Create new user from Microsoft
        $username = $this->generateUsername($email, $name);
        $user = $this->userFacade->findOrCreateMicrosoftUser(
            $microsoftId,
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
     * @return array{microsoft_id: string, email: string}|null
     */
    public function getPendingLinkData(): ?array
    {
        $this->startSession();
        $data = $_SESSION[self::SESSION_PENDING_LINK_KEY] ?? null;

        if (!is_array($data) || empty($data['microsoft_id']) || empty($data['email'])) {
            return null;
        }

        return [
            'microsoft_id' => (string) $data['microsoft_id'],
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
     * Link Microsoft account after password verification.
     *
     * @param string $microsoftId Microsoft user ID
     * @param User   $user        User to link
     *
     * @return void
     */
    public function linkMicrosoftToUser(string $microsoftId, User $user): void
    {
        $user->linkMicrosoft($microsoftId);
        $this->userFacade->save($user);
    }

    /**
     * Generate a username from email or name.
     *
     * @param string $email Email address
     * @param string $name  Name from Microsoft
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
            $base = 'microsoft_user';
        }

        // Ensure minimum length
        if (strlen($base) < 3) {
            $base = 'microsoft_user';
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
