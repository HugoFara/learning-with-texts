<?php

/**
 * Admin Module Service Provider
 *
 * Registers all services for the Admin module.
 *
 * PHP version 8.2
 *
 * @category Lwt
 * @package  Lwt\Modules\Admin
 * @author   HugoFara <git@hugofara.net>
 * @license  Unlicense <http://unlicense.org/>
 * @link     https://hugofara.github.io/lwt/developer/api
 * @since    3.0.0
 */

declare(strict_types=1);

namespace Lwt\Modules\Admin;

use Lwt\Shared\Infrastructure\Container\Container;
use Lwt\Shared\Infrastructure\Container\ServiceProviderInterface;
// Domain
use Lwt\Modules\Admin\Domain\SettingsRepositoryInterface;
use Lwt\Modules\Admin\Domain\BackupRepositoryInterface;
// Infrastructure
use Lwt\Modules\Admin\Infrastructure\MySqlSettingsRepository;
use Lwt\Modules\Admin\Infrastructure\MySqlBackupRepository;
use Lwt\Modules\Admin\Infrastructure\MySqlStatisticsRepository;
use Lwt\Modules\Admin\Infrastructure\FileSystemEnvRepository;
// Application
use Lwt\Modules\Admin\Application\AdminFacade;
use Lwt\Modules\Admin\Application\Services\SessionCleaner;
// Http
use Lwt\Modules\Admin\Http\AdminController;
use Lwt\Modules\Admin\Http\AdminApiHandler;
use Lwt\Modules\Admin\Http\UserManagementApiHandler;
use Lwt\Modules\Admin\Http\UserManagementController;
// Application Services
use Lwt\Modules\Admin\Application\Services\TtsService;
// User Management Use Cases
use Lwt\Modules\Admin\Application\UseCases\UserManagement\ListUsers;
use Lwt\Modules\Admin\Application\UseCases\UserManagement\CreateUser;
use Lwt\Modules\Admin\Application\UseCases\UserManagement\UpdateUser;
use Lwt\Modules\Admin\Application\UseCases\UserManagement\DeleteUser;
use Lwt\Modules\Admin\Application\UseCases\UserManagement\ToggleUserStatus;
use Lwt\Modules\Admin\Application\UseCases\UserManagement\ToggleUserRole;
// Cross-module dependencies
use Lwt\Modules\User\Domain\UserRepositoryInterface;
use Lwt\Modules\User\Infrastructure\MySqlUserRepository;
use Lwt\Modules\User\Application\Services\PasswordHasher;

/**
 * Service provider for the Admin module.
 *
 * Registers repositories, services, facade, controller,
 * and API handler for the Admin module.
 *
 * @since 3.0.0
 */
class AdminServiceProvider implements ServiceProviderInterface
{
    /**
     * {@inheritdoc}
     */
    public function register(Container $container): void
    {
        // Register Repository Interface bindings
        $this->registerRepositories($container);

        // Register Services
        $this->registerServices($container);

        // Register Facade
        $container->singleton(AdminFacade::class, function (Container $c) {
            return new AdminFacade(
                $c->getTyped(SettingsRepositoryInterface::class),
                $c->getTyped(BackupRepositoryInterface::class)
            );
        });

        // Register Controller (optional dependencies for BC)
        $container->bind(AdminController::class, function (Container $c) {
            return new AdminController(
                $c->getTyped(AdminFacade::class),
                $c->getTyped(TtsService::class)
            );
        });

        // Register API Handler
        $container->singleton(AdminApiHandler::class, function (Container $c) {
            return new AdminApiHandler(
                $c->getTyped(AdminFacade::class)
            );
        });

        // Register User Management Use Cases and Controller
        $this->registerUserManagement($container);
    }

    /**
     * Register user management use cases and controller.
     *
     * @param Container $container The DI container
     *
     * @return void
     */
    private function registerUserManagement(Container $container): void
    {
        $container->singleton(ListUsers::class, function (Container $c) {
            return new ListUsers(
                $c->getTyped(MySqlUserRepository::class)
            );
        });

        $container->singleton(CreateUser::class, function (Container $c) {
            return new CreateUser(
                $c->getTyped(UserRepositoryInterface::class),
                $c->getTyped(PasswordHasher::class)
            );
        });

        $container->singleton(UpdateUser::class, function (Container $c) {
            return new UpdateUser(
                $c->getTyped(UserRepositoryInterface::class),
                $c->getTyped(PasswordHasher::class)
            );
        });

        $container->singleton(DeleteUser::class, function (Container $c) {
            return new DeleteUser(
                $c->getTyped(UserRepositoryInterface::class)
            );
        });

        $container->singleton(ToggleUserStatus::class, function (Container $c) {
            return new ToggleUserStatus(
                $c->getTyped(UserRepositoryInterface::class)
            );
        });

        $container->singleton(ToggleUserRole::class, function (Container $c) {
            return new ToggleUserRole(
                $c->getTyped(MySqlUserRepository::class)
            );
        });

        $container->bind(UserManagementController::class, function (Container $c) {
            return new UserManagementController(
                $c->getTyped(ListUsers::class),
                $c->getTyped(CreateUser::class),
                $c->getTyped(UpdateUser::class),
                $c->getTyped(DeleteUser::class),
                $c->getTyped(ToggleUserStatus::class),
                $c->getTyped(ToggleUserRole::class),
                $c->getTyped(UserRepositoryInterface::class)
            );
        });

        $container->bind(UserManagementApiHandler::class, function (Container $c) {
            return new UserManagementApiHandler(
                $c->getTyped(ListUsers::class),
                $c->getTyped(CreateUser::class),
                $c->getTyped(UpdateUser::class),
                $c->getTyped(DeleteUser::class),
                $c->getTyped(ToggleUserStatus::class),
                $c->getTyped(ToggleUserRole::class),
                $c->getTyped(UserRepositoryInterface::class)
            );
        });
    }

    /**
     * Register repository bindings.
     *
     * @param Container $container The DI container
     *
     * @return void
     */
    private function registerRepositories(Container $container): void
    {
        // Settings Repository
        $container->singleton(SettingsRepositoryInterface::class, function (Container $_c) {
            return new MySqlSettingsRepository();
        });

        $container->singleton(MySqlSettingsRepository::class, function (Container $c): SettingsRepositoryInterface {
            return $c->getTyped(SettingsRepositoryInterface::class);
        });

        // Backup Repository
        $container->singleton(BackupRepositoryInterface::class, function (Container $_c) {
            return new MySqlBackupRepository();
        });

        $container->singleton(MySqlBackupRepository::class, function (Container $c): BackupRepositoryInterface {
            return $c->getTyped(BackupRepositoryInterface::class);
        });

        // Statistics Repository (no interface needed, concrete only)
        $container->singleton(MySqlStatisticsRepository::class, function (Container $_c) {
            return new MySqlStatisticsRepository();
        });

        // Env Repository (no database needed, works standalone)
        $container->singleton(FileSystemEnvRepository::class, function (Container $_c) {
            return new FileSystemEnvRepository();
        });
    }

    /**
     * Register application services.
     *
     * @param Container $container The DI container
     *
     * @return void
     */
    private function registerServices(Container $container): void
    {
        // Session Cleaner
        $container->singleton(SessionCleaner::class, function (Container $_c) {
            return new SessionCleaner();
        });

        // TTS Service
        $container->singleton(TtsService::class, function (Container $c) {
            return new TtsService(
                $c->getTyped(\Lwt\Modules\Language\Application\LanguageFacade::class)
            );
        });
    }

    /**
     * {@inheritdoc}
     */
    public function boot(Container $container): void
    {
        // No bootstrap logic needed for the Admin module
    }
}
