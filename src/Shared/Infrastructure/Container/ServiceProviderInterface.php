<?php

/**
 * Service Provider Interface
 *
 * PHP version 8.2
 *
 * @category Lwt
 * @package  Lwt\Shared\Infrastructure\Container
 * @author   HugoFara <git@hugofara.net>
 * @license  Unlicense <http://unlicense.org/>
 * @link     https://hugofara.github.io/lwt/developer/api
 * @since    3.0.0
 */

declare(strict_types=1);

namespace Lwt\Shared\Infrastructure\Container;

/**
 * Interface for service providers that register services in the container.
 *
 * Service providers are a way to organize service registration and
 * bootstrapping logic.
 *
 * @since 3.0.0
 */
interface ServiceProviderInterface
{
    /**
     * Register services in the container.
     *
     * This method is called during application bootstrap.
     *
     * @param Container $container The DI container
     *
     * @return void
     */
    public function register(Container $container): void;

    /**
     * Bootstrap any application services.
     *
     * This method is called after all providers have been registered.
     *
     * @param Container $container The DI container
     *
     * @return void
     */
    public function boot(Container $container): void;
}
