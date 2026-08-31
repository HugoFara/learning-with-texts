<?php

/**
 * Profile API Handler
 *
 * Backs the profile and preferences pages (issue #262). Every route acts on
 * *the caller's own* account — there is no ID in any path — so there is no
 * object-reference surface to get wrong.
 *
 * PHP version 8.2
 *
 * @category Lwt
 * @package  Lwt\Modules\User\Http
 * @author   HugoFara <git@hugofara.net>
 * @license  Unlicense <http://unlicense.org/>
 * @link     https://hugofara.github.io/lwt/developer/api
 * @since    3.4.0
 */

declare(strict_types=1);

namespace Lwt\Modules\User\Http;

use Lwt\Api\V1\Response;
use Lwt\Modules\User\Application\UserFacade;
use Lwt\Shared\Http\ApiRoutableInterface;
use Lwt\Shared\Http\ApiRoutableTrait;
use Lwt\Shared\Infrastructure\Http\JsonResponse;

/**
 * The signed-in user's own profile and preferences.
 *
 * Routes:
 * - GET /api/v1/profile             - Username, email, verification state
 * - PUT /api/v1/profile             - Update username and email
 * - PUT /api/v1/profile/password    - Change password (needs the current one)
 * - GET /api/v1/profile/preferences - The user-scoped settings map
 * - PUT /api/v1/profile/preferences - Save that map
 *
 * @since 3.4.0
 */
class ProfileApiHandler implements ApiRoutableInterface
{
    use ApiRoutableTrait;

    private UserFacade $userFacade;

    public function __construct(UserFacade $userFacade)
    {
        $this->userFacade = $userFacade;
    }

    /**
     * Read the caller's profile.
     *
     * @return array<string, mixed> Profile fields, or an error
     */
    public function getProfile(): array
    {
        $user = $this->userFacade->getCurrentUser();
        if ($user === null) {
            // Single-user installs have no account to edit; the page shows a
            // simplified panel instead, so this is "not applicable", not an
            // authentication failure.
            return ['error' => 'No account is signed in'];
        }

        return [
            'profile' => [
                'username' => $user->username(),
                'email' => $user->email(),
                'emailVerified' => $user->emailVerifiedAt() !== null,
            ],
        ];
    }

    /**
     * Update the caller's username and email.
     *
     * @param array<string, mixed> $data Payload
     *
     * @return array{success: bool, emailChanged?: bool, error?: string}
     */
    public function updateProfile(array $data): array
    {
        $user = $this->userFacade->getCurrentUser();
        if ($user === null) {
            return ['success' => false, 'error' => 'No account is signed in'];
        }

        $username = trim((string) ($data['username'] ?? ''));
        $email = trim((string) ($data['email'] ?? ''));

        if ($username === '' || $email === '') {
            return ['success' => false, 'error' => __('user.flash.profile_missing_fields')];
        }

        try {
            $emailChanged = $this->userFacade->updateProfile($user, $username, $email);
        } catch (\InvalidArgumentException $e) {
            return ['success' => false, 'error' => $e->getMessage()];
        }

        if ($emailChanged) {
            $this->userFacade->sendVerificationEmail($user);
        }

        return ['success' => true, 'emailChanged' => $emailChanged];
    }

    /**
     * Change the caller's password.
     *
     * The current password is required and checked by the facade, so knowing
     * a session is open is not enough to replace it.
     *
     * @param array<string, mixed> $data Payload
     *
     * @return array{success: bool, error?: string}
     */
    public function changePassword(array $data): array
    {
        $user = $this->userFacade->getCurrentUser();
        if ($user === null) {
            return ['success' => false, 'error' => 'No account is signed in'];
        }

        $current = (string) ($data['current_password'] ?? '');
        $new = (string) ($data['new_password'] ?? '');
        $confirm = (string) ($data['new_password_confirm'] ?? '');

        if ($current === '' || $new === '' || $confirm === '') {
            return ['success' => false, 'error' => __('user.flash.password_missing_fields')];
        }
        if ($new !== $confirm) {
            return ['success' => false, 'error' => __('user.flash.password_mismatch')];
        }

        try {
            $this->userFacade->changePassword($user, $current, $new);
        } catch (\InvalidArgumentException $e) {
            return ['success' => false, 'error' => $e->getMessage()];
        }

        return ['success' => true];
    }

    /**
     * Read the caller's preferences.
     *
     * @return array<string, mixed> The settings map
     */
    public function getPreferences(): array
    {
        return ['settings' => $this->userFacade->getUserPreferences()];
    }

    /**
     * Save the caller's preferences.
     *
     * @param array<string, mixed> $data Settings map
     *
     * @return array{success: bool}
     */
    public function savePreferences(array $data): array
    {
        /** @var array<string, mixed> $settings */
        $settings = is_array($data['settings'] ?? null) ? $data['settings'] : $data;

        return $this->userFacade->saveUserPreferencesFromData($settings);
    }

    // =========================================================================
    // API Routing
    // =========================================================================

    /**
     * @param list<string>         $fragments URL path fragments
     * @param array<string, mixed> $params    Query parameters
     */
    public function routeGet(array $fragments, array $params): JsonResponse
    {
        return match ($this->frag($fragments, 1)) {
            '' => Response::success($this->getProfile()),
            'preferences' => Response::success($this->getPreferences()),
            default => Response::error('Expected "preferences" or no sub-path', 404),
        };
    }

    /**
     * @param list<string>         $fragments URL path fragments
     * @param array<string, mixed> $params    Request body
     */
    public function routePut(array $fragments, array $params): JsonResponse
    {
        return match ($this->frag($fragments, 1)) {
            '' => Response::success($this->updateProfile($params)),
            'password' => Response::success($this->changePassword($params)),
            'preferences' => Response::success($this->savePreferences($params)),
            default => Response::error('Expected "password", "preferences", or no sub-path', 404),
        };
    }
}
