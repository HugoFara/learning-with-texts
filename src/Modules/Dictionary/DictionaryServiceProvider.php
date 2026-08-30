<?php

/**
 * Dictionary Module Service Provider
 *
 * Registers all services for the Dictionary module.
 *
 * PHP version 8.2
 *
 * @category Lwt
 * @package  Lwt\Modules\Dictionary
 * @author   HugoFara <git@hugofara.net>
 * @license  Unlicense <http://unlicense.org/>
 * @link     https://hugofara.github.io/lwt/developer/api
 * @since    3.0.0
 */

declare(strict_types=1);

namespace Lwt\Modules\Dictionary;

use Lwt\Shared\Infrastructure\Container\Container;
use Lwt\Shared\Infrastructure\Container\ServiceProviderInterface;
// Application
use Lwt\Modules\Dictionary\Application\DictionaryFacade;
use Lwt\Modules\Dictionary\Application\TranslationService;
// Http
use Lwt\Modules\Dictionary\Http\DictionaryApiHandler;
use Lwt\Modules\Dictionary\Http\DictionaryController;
use Lwt\Modules\Dictionary\Http\TranslationController;
// Application Services
use Lwt\Modules\Dictionary\Application\Services\CuratedDictImportService;
use Lwt\Modules\Dictionary\Application\Services\LocalDictionaryService;
// Infrastructure - Dictionary Importers
use Lwt\Modules\Dictionary\Infrastructure\Import\ImporterInterface;
use Lwt\Modules\Dictionary\Infrastructure\Import\CsvImporter;
use Lwt\Modules\Dictionary\Infrastructure\Import\JsonImporter;
use Lwt\Modules\Dictionary\Infrastructure\Import\StarDictImporter;
// Language Module
use Lwt\Modules\Language\Application\LanguageFacade;

/**
 * Service provider for the Dictionary module.
 *
 * Registers the facade, controller, and related services
 * for the Dictionary module.
 *
 * @since 3.0.0
 */
class DictionaryServiceProvider implements ServiceProviderInterface
{
    /**
     * {@inheritdoc}
     */
    public function register(Container $container): void
    {
        // Register LocalDictionaryService (used by facade)
        $container->singleton(LocalDictionaryService::class, function (Container $_c) {
            return new LocalDictionaryService();
        });

        // Register Facade
        $container->singleton(DictionaryFacade::class, function (Container $c) {
            return new DictionaryFacade(
                $c->getTyped(LocalDictionaryService::class)
            );
        });

        // Register Curated Import Service
        $container->singleton(CuratedDictImportService::class, function (Container $c) {
            return new CuratedDictImportService(
                $c->getTyped(DictionaryFacade::class)
            );
        });

        // Register API Handler
        $container->singleton(DictionaryApiHandler::class, function (Container $c) {
            return new DictionaryApiHandler(
                $c->getTyped(DictionaryFacade::class),
                $c->getTyped(CuratedDictImportService::class)
            );
        });

        // Register Controller
        $container->singleton(DictionaryController::class, function (Container $c) {
            return new DictionaryController(
                $c->getTyped(DictionaryFacade::class),
                $c->getTyped(LanguageFacade::class)
            );
        });

        // Register TranslationService
        $container->singleton(TranslationService::class, function (Container $_c) {
            return new TranslationService();
        });

        // Register TranslationController
        $container->singleton(TranslationController::class, function (Container $c) {
            return new TranslationController(
                $c->getTyped(TranslationService::class)
            );
        });

        // Register Dictionary Importers
        $container->singleton(CsvImporter::class, function (Container $_c) {
            return new CsvImporter();
        });

        $container->singleton(JsonImporter::class, function (Container $_c) {
            return new JsonImporter();
        });

        $container->singleton(StarDictImporter::class, function (Container $_c) {
            return new StarDictImporter();
        });
    }

    /**
     * {@inheritdoc}
     */
    public function boot(Container $container): void
    {
        // No bootstrap logic needed for the Dictionary module
    }
}
