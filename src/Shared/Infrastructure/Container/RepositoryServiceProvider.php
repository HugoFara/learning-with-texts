<?php

/**
 * Repository Service Provider
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

use Lwt\Shared\Infrastructure\Repository\RepositoryInterface;
use Lwt\Modules\Language\Infrastructure\MySqlLanguageRepository;
use Lwt\Modules\Text\Infrastructure\MySqlTextRepository;
use Lwt\Modules\User\Infrastructure\MySqlUserRepository;

// Note: TermRepository is now registered by VocabularyServiceProvider

/**
 * Service provider that registers all repository classes.
 *
 * @since 3.0.0
 *
 * @psalm-suppress UnusedClass Class will be used when container is fully integrated
 */
class RepositoryServiceProvider implements ServiceProviderInterface
{
    /**
     * {@inheritdoc}
     */
    public function register(Container $container): void
    {
        // Register MySqlLanguageRepository as a singleton
        $container->singleton(MySqlLanguageRepository::class, function (Container $_c) {
            return new MySqlLanguageRepository();
        });

        // Register MySqlTextRepository as a singleton
        $container->singleton(MySqlTextRepository::class, function (Container $_c) {
            return new MySqlTextRepository();
        });

        // Note: TermRepository is now registered by VocabularyServiceProvider

        // Register MySqlUserRepository as a singleton
        $container->singleton(MySqlUserRepository::class, function (Container $_c) {
            return new MySqlUserRepository();
        });
    }

    /**
     * {@inheritdoc}
     */
    public function boot(Container $container): void
    {
        // No bootstrap logic needed for repositories
    }
}
