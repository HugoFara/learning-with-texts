<?php

/**
 * User Management API Handler
 *
 * Backs the admin user list and user form. Every route here is admin-only:
 * unlike the rest of the API, where the worst case is a user reaching their
 * own data, these routes create accounts and grant the admin role.
 *
 * PHP version 8.2
 *
 * @category Lwt
 * @package  Lwt\Modules\Admin\Http
 * @author   HugoFara <git@hugofara.net>
 * @license  Unlicense <http://unlicense.org/>
 * @link     https://hugofara.github.io/lwt/developer/api
 * @since    3.4.0
 */

declare(strict_types=1);

namespace Lwt\Modules\Admin\Http;

use Lwt\Api\V1\Response;
use Lwt\Modules\Admin\Application\UseCases\UserManagement\CreateUser;
use Lwt\Modules\Admin\Application\UseCases\UserManagement\DeleteUser;
use Lwt\Modules\Admin\Application\UseCases\UserManagement\ListUsers;
use Lwt\Modules\Admin\Application\UseCases\UserManagement\ToggleUserRole;
use Lwt\Modules\Admin\Application\UseCases\UserManagement\ToggleUserStatus;
use Lwt\Modules\Admin\Application\UseCases\UserManagement\UpdateUser;
use Lwt\Modules\User\Domain\User;
use Lwt\Modules\User\Domain\UserRepositoryInterface;
use Lwt\Shared\Http\ApiRoutableInterface;
use Lwt\Shared\Http\ApiRoutableTrait;
use Lwt\Shared\Infrastructure\Globals;
use Lwt\Shared\Infrastructure\Http\JsonResponse;

/**
 * Admin-only CRUD over user accounts.
 *
 * Routes:
 * - GET    /api/v1/admin/users           - Paginated list plus statistics
 * - GET    /api/v1/admin/users/{id}      - One user
 * - POST   /api/v1/admin/users           - Create
 * - PUT    /api/v1/admin/users/{id}      - Update
 * - PUT    /api/v1/admin/users/{id}/role - Promote or demote
 * - PUT    /api/v1/admin/users/{id}/status - Activate or deactivate
 * - DELETE /api/v1/admin/users/{id}      - Delete
 *
 * @since 3.4.0
 */
class UserManagementApiHandler implements ApiRoutableInterface
{
    use ApiRoutableTrait;

    private ListUsers $listUsers;
    private CreateUser $createUser;
    private UpdateUser $updateUser;
    private DeleteUser $deleteUser;
    private ToggleUserStatus $toggleUserStatus;
    private ToggleUserRole $toggleUserRole;
    private UserRepositoryInterface $userRepository;

    public function __construct(
        ListUsers $listUsers,
        CreateUser $createUser,
        UpdateUser $updateUser,
        DeleteUser $deleteUser,
        ToggleUserStatus $toggleUserStatus,
        ToggleUserRole $toggleUserRole,
        UserRepositoryInterface $userRepository
    ) {
        $this->listUsers = $listUsers;
        $this->createUser = $createUser;
        $this->updateUser = $updateUser;
        $this->deleteUser = $deleteUser;
        $this->toggleUserStatus = $toggleUserStatus;
        $this->toggleUserRole = $toggleUserRole;
        $this->userRepository = $userRepository;
    }

    /**
     * Reject the request unless the caller is an admin.
     *
     * Mirrors AdminMiddleware, which guards the pages these routes replaced:
     * the check is skipped when multi-user mode is off, because there are no
     * roles to enforce and the single user is implicitly the administrator.
     *
     * The API's own gate only checks *authentication*, so without this any
     * logged-in user could create an account and grant it the admin role.
     *
     * @return JsonResponse|null Error response, or null when allowed
     */
    private function denyIfNotAdmin(): ?JsonResponse
    {
        // Handled here rather than in ApiV1 so the check cannot be lost if the
        // routing table is rearranged.

        if (!Globals::isMultiUserEnabled()) {
            return null;
        }
        if (!Globals::isCurrentUserAdmin()) {
            return Response::error('Permission denied: admin only', 403);
        }
        return null;
    }

    /**
     * Reject a path under /admin that this handler does not serve.
     *
     * The handler is registered on the `admin` resource, so it also receives
     * anything else that might be added under it later.
     *
     * @param list<string> $fragments URL path fragments
     *
     * @return JsonResponse|null Error response, or null when allowed
     */
    private function denyIfNotUsersPath(array $fragments): ?JsonResponse
    {
        if ($this->frag($fragments, 1) !== 'users') {
            return Response::error('Expected "users"', 404);
        }
        return null;
    }

    /**
     * Reject the request unless the caller is an admin and the path is ours.
     *
     * @param list<string> $fragments URL path fragments
     *
     * @return JsonResponse|null Error response, or null when allowed
     */
    private function guard(array $fragments): ?JsonResponse
    {
        return $this->denyIfNotAdmin() ?? $this->denyIfNotUsersPath($fragments);
    }

    /**
     * The admin performing the request.
     *
     * The use cases take this to stop an admin removing their own account or
     * demoting themselves out of the last admin seat.
     */
    private function currentAdminId(): int
    {
        return Globals::getCurrentUserId() ?? 0;
    }

    /**
     * Shape a user for the client.
     *
     * Deliberately omits the password hash and every verification token the
     * entity carries.
     *
     * @param User $user User entity
     *
     * @return array<string, mixed> Public user fields
     */
    private function formatUser(User $user): array
    {
        $lastLogin = $user->lastLogin();

        // The edit form shows which OAuth providers an account is linked to
        // and whether it has a password at all, so an admin can tell an
        // OAuth-only account from one whose password they could reset.
        $providers = [];
        if ($user->isLinkedToGoogle()) {
            $providers[] = 'Google';
        }
        if ($user->isLinkedToMicrosoft()) {
            $providers[] = 'Microsoft';
        }
        if ($user->isLinkedToWordPress()) {
            $providers[] = 'WordPress';
        }

        return [
            'id' => $user->id()->toInt(),
            'username' => $user->username(),
            'email' => $user->email(),
            'role' => $user->isAdmin() ? 'admin' : 'user',
            'isAdmin' => $user->isAdmin(),
            'isActive' => $user->isActive(),
            'created' => $user->created()->format('Y-m-d H:i'),
            'lastLogin' => $lastLogin !== null ? $lastLogin->format('Y-m-d H:i') : null,
            'linkedProviders' => $providers,
            'hasPassword' => $user->hasPassword(),
        ];
    }

    /**
     * List users.
     *
     * @param array<string, mixed> $params Query parameters
     *
     * @return array<string, mixed> Users, paging and statistics
     */
    public function list(array $params): array
    {
        $page = max(1, (int) ($params['page'] ?? 1));
        $perPage = min(100, max(1, (int) ($params['per_page'] ?? 20)));
        $sortBy = (string) ($params['sort'] ?? 'username');
        $direction = (string) ($params['dir'] ?? 'ASC');
        $search = (string) ($params['search'] ?? '');

        $data = $this->listUsers->execute($page, $perPage, $sortBy, $direction, $search);

        $users = [];
        foreach ($data['items'] as $user) {
            $users[] = $this->formatUser($user);
        }

        return [
            'users' => $users,
            'pagination' => [
                'page' => $data['page'],
                'per_page' => $data['per_page'],
                'total' => $data['total'],
                'total_pages' => $data['total_pages'],
            ],
            'statistics' => $data['statistics'],
            'currentAdminId' => $this->currentAdminId(),
            'sort' => $sortBy,
            'dir' => $direction,
            'search' => $search,
        ];
    }

    /**
     * Read one user.
     *
     * @param int $id User ID
     *
     * @return array<string, mixed> The user, or an error
     */
    public function get(int $id): array
    {
        $user = $this->userRepository->find($id);
        if ($user === null) {
            return ['error' => 'User not found'];
        }

        return ['user' => $this->formatUser($user), 'currentAdminId' => $this->currentAdminId()];
    }

    /**
     * Create a user.
     *
     * @param array<string, mixed> $data Payload
     *
     * @return array{success: bool, id?: int, errors?: array<array-key, string>}
     */
    public function create(array $data): array
    {
        $result = $this->createUser->execute(
            (string) ($data['username'] ?? ''),
            (string) ($data['email'] ?? ''),
            (string) ($data['password'] ?? ''),
            (string) ($data['role'] ?? 'user'),
            !empty($data['is_active'])
        );

        if (!$result['success']) {
            return ['success' => false, 'errors' => $result['errors'] ?? ['Failed to create user']];
        }

        return ['success' => true, 'id' => $result['user_id'] ?? 0];
    }

    /**
     * Update a user.
     *
     * An empty password means "leave it alone", matching the retired form.
     *
     * @param int                  $id   User ID
     * @param array<string, mixed> $data Payload
     *
     * @return array{success: bool, errors?: array<array-key, string>}
     */
    public function update(int $id, array $data): array
    {
        $result = $this->updateUser->execute(
            $id,
            $this->currentAdminId(),
            (string) ($data['username'] ?? ''),
            (string) ($data['email'] ?? ''),
            (string) ($data['password'] ?? ''),
            (string) ($data['role'] ?? 'user'),
            !empty($data['is_active'])
        );

        if (!$result['success']) {
            return ['success' => false, 'errors' => $result['errors'] ?? ['Failed to update user']];
        }

        return ['success' => true];
    }

    /**
     * Delete a user.
     *
     * @param int $id User ID
     *
     * @return array{success: bool, error?: string}
     */
    public function delete(int $id): array
    {
        $result = $this->deleteUser->execute($id, $this->currentAdminId());

        if (!$result['success']) {
            return ['success' => false, 'error' => $result['error'] ?? 'Failed to delete user'];
        }

        return ['success' => true];
    }

    /**
     * Activate or deactivate a user.
     *
     * @param int                  $id   User ID
     * @param array<string, mixed> $data Payload carrying `is_active`
     *
     * @return array{success: bool, error?: string}
     */
    public function setStatus(int $id, array $data): array
    {
        $admin = $this->currentAdminId();
        $result = empty($data['is_active'])
            ? $this->toggleUserStatus->deactivate($id, $admin)
            : $this->toggleUserStatus->activate($id, $admin);

        if (!$result['success']) {
            return ['success' => false, 'error' => $result['error'] ?? 'Failed to change status'];
        }

        return ['success' => true];
    }

    /**
     * Promote a user to admin or demote them.
     *
     * @param int                  $id   User ID
     * @param array<string, mixed> $data Payload carrying `role`
     *
     * @return array{success: bool, error?: string}
     */
    public function setRole(int $id, array $data): array
    {
        $admin = $this->currentAdminId();
        $result = ((string) ($data['role'] ?? 'user')) === 'admin'
            ? $this->toggleUserRole->promote($id, $admin)
            : $this->toggleUserRole->demote($id, $admin);

        if (!$result['success']) {
            return ['success' => false, 'error' => $result['error'] ?? 'Failed to change role'];
        }

        return ['success' => true];
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
        $denied = $this->guard($fragments);
        if ($denied !== null) {
            return $denied;
        }

        $id = $this->frag($fragments, 2);
        if ($id !== '' && ctype_digit($id)) {
            return Response::success($this->get((int) $id));
        }

        return Response::success($this->list($params));
    }

    /**
     * @param list<string>         $fragments URL path fragments
     * @param array<string, mixed> $params    Request body
     */
    public function routePost(array $fragments, array $params): JsonResponse
    {
        $denied = $this->guard($fragments);
        if ($denied !== null) {
            return $denied;
        }

        return Response::success($this->create($params));
    }

    /**
     * @param list<string>         $fragments URL path fragments
     * @param array<string, mixed> $params    Request body
     */
    public function routePut(array $fragments, array $params): JsonResponse
    {
        $denied = $this->guard($fragments);
        if ($denied !== null) {
            return $denied;
        }

        $id = $this->frag($fragments, 2);
        if ($id === '' || !ctype_digit($id)) {
            return Response::error('User ID (Integer) Expected', 404);
        }

        return match ($this->frag($fragments, 3)) {
            'role' => Response::success($this->setRole((int) $id, $params)),
            'status' => Response::success($this->setStatus((int) $id, $params)),
            '' => Response::success($this->update((int) $id, $params)),
            default => Response::error('Expected "role", "status", or no sub-path', 404),
        };
    }

    /**
     * @param list<string>         $fragments URL path fragments
     * @param array<string, mixed> $params    Request body
     */
    public function routeDelete(array $fragments, array $params): JsonResponse
    {
        $denied = $this->guard($fragments);
        if ($denied !== null) {
            return $denied;
        }

        $id = $this->frag($fragments, 2);
        if ($id === '' || !ctype_digit($id)) {
            return Response::error('User ID (Integer) Expected', 404);
        }

        return Response::success($this->delete((int) $id));
    }
}
