<?php

declare(strict_types=1);

namespace Lwt\Modules\Admin\Http;

use Lwt\Shared\Http\BaseController;
use Lwt\Modules\Admin\Application\UseCases\UserManagement\ListUsers;
use Lwt\Modules\Admin\Application\UseCases\UserManagement\CreateUser;
use Lwt\Modules\Admin\Application\UseCases\UserManagement\UpdateUser;
use Lwt\Modules\Admin\Application\UseCases\UserManagement\DeleteUser;
use Lwt\Modules\Admin\Application\UseCases\UserManagement\ToggleUserStatus;
use Lwt\Modules\Admin\Application\UseCases\UserManagement\ToggleUserRole;
use Lwt\Modules\User\Domain\UserRepositoryInterface;

class UserManagementController extends BaseController
{
    private ListUsers $listUsers;
    private CreateUser $createUser;
    private UpdateUser $updateUser;
    private DeleteUser $deleteUser;
    private ToggleUserStatus $toggleUserStatus;
    private ToggleUserRole $toggleUserRole;
    private UserRepositoryInterface $userRepository;
    private string $viewPath;

    public function __construct(
        ListUsers $listUsers,
        CreateUser $createUser,
        UpdateUser $updateUser,
        DeleteUser $deleteUser,
        ToggleUserStatus $toggleUserStatus,
        ToggleUserRole $toggleUserRole,
        UserRepositoryInterface $userRepository
    ) {
        parent::__construct();
        $this->listUsers = $listUsers;
        $this->createUser = $createUser;
        $this->updateUser = $updateUser;
        $this->deleteUser = $deleteUser;
        $this->toggleUserStatus = $toggleUserStatus;
        $this->toggleUserRole = $toggleUserRole;
        $this->userRepository = $userRepository;
        $this->viewPath = __DIR__ . '/../Views/users/';
    }

    /**
     * Render the user list scaffold.
     *
     * Rows, statistics and paging come from GET /api/v1/admin/users; only the
     * initial search and sort travel with the page so a bookmarked URL still
     * opens on them.
     *
     * @psalm-suppress UnusedVariable, UnresolvableInclude
     */
    public function index(array $params): void
    {
        $data = [
            'search' => $this->param('search'),
            'sort' => $this->param('sort', 'username'),
            'dir' => $this->param('dir', 'ASC'),
        ];

        $this->render('User Management', true);
        $this->message($this->param('message'), true);
        include $this->viewPath . 'list.php';
        $this->endRender();
    }

    /**
     * Render the create-user scaffold.
     *
     * Creation itself goes through POST /api/v1/admin/users.
     *
     * @psalm-suppress UnusedVariable, UnresolvableInclude
     */
    public function create(array $params): void
    {
        $isEdit = false;
        $user = null;

        $this->render('Create User', true);
        include $this->viewPath . 'form.php';
        $this->endRender();
    }

    /**
     * Render the edit-user scaffold.
     *
     * Saving goes through PUT /api/v1/admin/users/{id}. The user is looked up
     * here only to 404 early on a bad ID; the form's values come from the API.
     *
     * @psalm-suppress UnusedVariable, UnresolvableInclude
     */
    public function edit(array $params): void
    {
        $userId = (int) ($params['id'] ?? 0);
        $user = $this->userRepository->find($userId);

        if ($user === null) {
            $this->redirect('/admin/users?message=' . urlencode(__('admin.users.flash.not_found')))->send();
            return;
        }

        $isEdit = true;

        $this->render('Edit User', true);
        include $this->viewPath . 'form.php';
        $this->endRender();
    }
}
