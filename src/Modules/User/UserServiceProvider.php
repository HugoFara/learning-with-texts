<?php

/**
 * User Module Service Provider
 *
 * Registers all services for the User module.
 *
 * PHP version 8.2
 *
 * @category Lwt
 * @package  Lwt\Modules\User
 * @author   HugoFara <git@hugofara.net>
 * @license  Unlicense <http://unlicense.org/>
 * @link     https://hugofara.github.io/lwt/developer/api
 * @since    3.0.0
 */

declare(strict_types=1);

namespace Lwt\Modules\User;

use Lwt\Shared\Infrastructure\Container\Container;
use Lwt\Shared\Infrastructure\Container\ServiceProviderInterface;
// Domain
use Lwt\Modules\User\Domain\UserRepositoryInterface;
// Infrastructure
use Lwt\Modules\User\Infrastructure\MySqlUserRepository;
// Application
use Lwt\Modules\User\Application\UserFacade;
use Lwt\Modules\User\Application\Services\PasswordHasher;
// Http
use Lwt\Modules\User\Http\UserController;
use Lwt\Modules\User\Http\ProfileApiHandler;
use Lwt\Modules\User\Http\UserApiHandler;
use Lwt\Modules\User\Http\WordPressController;
use Lwt\Modules\User\Http\GoogleController;
// Infrastructure
use Lwt\Modules\User\Infrastructure\AuthFormDataManager;
// Shared Services
use Lwt\Shared\Infrastructure\Http\FlashMessageService;
// WordPress integration
use Lwt\Modules\User\Application\Services\WordPressAuthService;
// Google OAuth integration
use Lwt\Modules\User\Application\Services\GoogleAuthService;
// Microsoft OAuth integration
use Lwt\Modules\User\Application\Services\MicrosoftAuthService;
use Lwt\Modules\User\Http\MicrosoftController;
// Application Services
use Lwt\Modules\User\Application\Services\EmailService;
use Lwt\Modules\User\Application\Services\PasswordService;
use Lwt\Modules\User\Application\Services\AuthService;

/**
 * Service provider for the User module.
 *
 * Registers repositories, services, facade, controller,
 * and API handler for the User module.
 *
 * @since 3.0.0
 */
class UserServiceProvider implements ServiceProviderInterface
{
    /**
     * {@inheritdoc}
     */
    public function register(Container $container): void
    {
        // Register Repository Interface bindings
        $this->registerRepositories($container);

        // Register Application Services
        $this->registerServices($container);

        // Register Facade
        $container->singleton(UserFacade::class, function (Container $c) {
            return new UserFacade(
                $c->getTyped(UserRepositoryInterface::class),
                $c->getTyped(PasswordHasher::class)
            );
        });

        // Register Auth Form Data Manager
        $container->singleton(AuthFormDataManager::class, function (Container $_c) {
            return new AuthFormDataManager();
        });

        // Register FlashMessageService if not already registered
        if (!$container->has(FlashMessageService::class)) {
            $container->singleton(FlashMessageService::class, function (Container $_c) {
                return new FlashMessageService();
            });
        }

        // Register Controller
        $container->bind(UserController::class, function (Container $c) {
            return new UserController(
                $c->getTyped(UserFacade::class),
                $c->getTyped(FlashMessageService::class),
                $c->getTyped(AuthFormDataManager::class)
            );
        });

        // Register API Handler
        $container->singleton(UserApiHandler::class, function (Container $c) {
            return new UserApiHandler(
                $c->getTyped(UserFacade::class)
            );
        });

        $container->singleton(ProfileApiHandler::class, function (Container $c) {
            return new ProfileApiHandler(
                $c->getTyped(UserFacade::class)
            );
        });

        // Register WordPress integration services
        $this->registerWordPressServices($container);

        // Register Google OAuth services
        $this->registerGoogleServices($container);

        // Register Microsoft OAuth services
        $this->registerMicrosoftServices($container);

        // Register authentication services
        $this->registerAuthServices($container);
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
        // User Repository Interface -> MySQL implementation
        $container->singleton(UserRepositoryInterface::class, function (Container $_c) {
            return new MySqlUserRepository();
        });

        // Also register concrete class for direct injection
        $container->singleton(MySqlUserRepository::class, function (Container $c): MySqlUserRepository {
            /** @var MySqlUserRepository */
            return $c->getTyped(UserRepositoryInterface::class);
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
        // Password Service (shared)
        $container->singleton(PasswordService::class, function (Container $_c) {
            return new PasswordService();
        });

        // Password Hasher (wraps PasswordService for module use)
        $container->singleton(PasswordHasher::class, function (Container $c) {
            return new PasswordHasher($c->getTyped(PasswordService::class));
        });

        // Email Service (for password reset)
        $container->singleton(EmailService::class, function (Container $_c) {
            return new EmailService();
        });
    }

    /**
     * Register WordPress integration services.
     *
     * @param Container $container The DI container
     *
     * @return void
     */
    private function registerWordPressServices(Container $container): void
    {
        // WordPress Auth Service
        $container->singleton(WordPressAuthService::class, function (Container $c) {
            return new WordPressAuthService(
                $c->getTyped(UserFacade::class)
            );
        });

        // WordPress Controller
        $container->bind(WordPressController::class, function (Container $c) {
            return new WordPressController(
                $c->getTyped(WordPressAuthService::class)
            );
        });
    }

    /**
     * Register Google OAuth services.
     *
     * @param Container $container The DI container
     *
     * @return void
     */
    private function registerGoogleServices(Container $container): void
    {
        // Google Auth Service
        $container->singleton(GoogleAuthService::class, function (Container $c) {
            return new GoogleAuthService(
                $c->getTyped(UserFacade::class)
            );
        });

        // Google Controller
        $container->bind(GoogleController::class, function (Container $c) {
            return new GoogleController(
                $c->getTyped(GoogleAuthService::class)
            );
        });
    }

    /**
     * Register Microsoft OAuth services.
     *
     * @param Container $container The DI container
     *
     * @return void
     */
    private function registerMicrosoftServices(Container $container): void
    {
        // Microsoft Auth Service
        $container->singleton(MicrosoftAuthService::class, function (Container $c) {
            return new MicrosoftAuthService(
                $c->getTyped(UserFacade::class)
            );
        });

        // Microsoft Controller
        $container->bind(MicrosoftController::class, function (Container $c) {
            return new MicrosoftController(
                $c->getTyped(MicrosoftAuthService::class)
            );
        });
    }

    /**
     * Register authentication services.
     *
     * @param Container $container The DI container
     *
     * @return void
     */
    private function registerAuthServices(Container $container): void
    {
        // AuthService - uses UserRepository for database access
        $container->singleton(AuthService::class, function (Container $c) {
            return new AuthService(
                $c->getTyped(PasswordService::class),
                $c->getTyped(MySqlUserRepository::class)
            );
        });
    }

    /**
     * {@inheritdoc}
     */
    public function boot(Container $container): void
    {
        // No bootstrap logic needed for the User module
    }
}
