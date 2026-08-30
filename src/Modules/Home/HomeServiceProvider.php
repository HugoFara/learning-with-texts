<?php

/**
 * Home Module Service Provider
 *
 * Registers all services for the Home module.
 *
 * PHP version 8.2
 *
 * @category Lwt
 * @package  Lwt\Modules\Home
 * @author   HugoFara <git@hugofara.net>
 * @license  Unlicense <http://unlicense.org/>
 * @link     https://hugofara.github.io/lwt/developer/api
 * @since    3.0.0
 */

declare(strict_types=1);

namespace Lwt\Modules\Home;

use Lwt\Shared\Infrastructure\Container\Container;
use Lwt\Shared\Infrastructure\Container\ServiceProviderInterface;
// Application
use Lwt\Modules\Home\Application\HomeFacade;
use Lwt\Modules\Home\Application\UseCases\GetDashboardData;
use Lwt\Modules\Home\Application\UseCases\GetTextStatistics;
// Http
use Lwt\Modules\Home\Http\HomeController;
// Dependencies
use Lwt\Modules\Language\Application\LanguageFacade;

/**
 * Service provider for the Home module.
 *
 * Registers facade, use cases, and controller for the Home module.
 *
 * @since 3.0.0
 */
class HomeServiceProvider implements ServiceProviderInterface
{
    /**
     * {@inheritdoc}
     */
    public function register(Container $container): void
    {
        // Register Use Cases
        $this->registerUseCases($container);

        // Register Facade
        $container->singleton(HomeFacade::class, function (Container $_c) {
            return new HomeFacade();
        });

        // Register Controller
        $container->bind(HomeController::class, function (Container $c) {
            return new HomeController(
                $c->getTyped(HomeFacade::class),
                $c->getTyped(LanguageFacade::class)
            );
        });
    }

    /**
     * Register use cases.
     *
     * @param Container $container The DI container
     *
     * @return void
     */
    private function registerUseCases(Container $container): void
    {
        $container->singleton(GetDashboardData::class, function (Container $_c) {
            return new GetDashboardData();
        });

        $container->singleton(GetTextStatistics::class, function (Container $_c) {
            return new GetTextStatistics();
        });
    }

    /**
     * {@inheritdoc}
     */
    public function boot(Container $container): void
    {
        // No bootstrap logic needed for the Home module
    }
}
