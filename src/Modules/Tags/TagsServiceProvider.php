<?php

/**
 * Tags Module Service Provider
 *
 * Registers all services for the Tags module.
 *
 * PHP version 8.2
 *
 * @category Lwt
 * @package  Lwt\Modules\Tags
 * @author   HugoFara <git@hugofara.net>
 * @license  Unlicense <http://unlicense.org/>
 * @link     https://hugofara.github.io/lwt/developer/api
 * @since    3.0.0
 */

declare(strict_types=1);

namespace Lwt\Modules\Tags;

use Lwt\Shared\Infrastructure\Container\Container;
use Lwt\Shared\Infrastructure\Container\ServiceProviderInterface;
// Domain
use Lwt\Modules\Tags\Domain\TagRepositoryInterface;
use Lwt\Modules\Tags\Domain\TagAssociationInterface;
use Lwt\Modules\Tags\Domain\TagType;
// Infrastructure
use Lwt\Modules\Tags\Infrastructure\MySqlTermTagRepository;
use Lwt\Modules\Tags\Infrastructure\MySqlTextTagRepository;
use Lwt\Modules\Tags\Infrastructure\MySqlWordTagAssociation;
use Lwt\Modules\Tags\Infrastructure\MySqlTextTagAssociation;
use Lwt\Modules\Tags\Infrastructure\MySqlArchivedTextTagAssociation;
// Use Cases
use Lwt\Modules\Tags\Application\UseCases\CreateTag;
use Lwt\Modules\Tags\Application\UseCases\DeleteTag;
use Lwt\Modules\Tags\Application\UseCases\GetAllTagNames;
use Lwt\Modules\Tags\Application\UseCases\GetTagById;
use Lwt\Modules\Tags\Application\UseCases\ListTags;
use Lwt\Modules\Tags\Application\UseCases\UpdateTag;
// Application
use Lwt\Modules\Tags\Application\TagsFacade;
// Http
use Lwt\Modules\Tags\Http\TermTagController;
use Lwt\Modules\Tags\Http\TextTagController;
use Lwt\Modules\Tags\Http\TagApiHandler;

/**
 * Service provider for the Tags module.
 *
 * Registers repositories, associations, use cases, facades, controllers,
 * and API handlers for both term tags and text tags.
 *
 * @since 3.0.0
 */
class TagsServiceProvider implements ServiceProviderInterface
{
    /**
     * {@inheritdoc}
     */
    public function register(Container $container): void
    {
        // Register Repositories
        $this->registerRepositories($container);

        // Register Associations
        $this->registerAssociations($container);

        // Register Use Cases
        $this->registerUseCases($container);

        // Register Facades
        $this->registerFacades($container);

        // Register Controllers
        $this->registerControllers($container);

        // Register API Handler
        $this->registerApiHandler($container);
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
        // Term Tag Repository
        $container->singleton(MySqlTermTagRepository::class, function (Container $_c) {
            return new MySqlTermTagRepository();
        });

        // Text Tag Repository
        $container->singleton(MySqlTextTagRepository::class, function (Container $_c) {
            return new MySqlTextTagRepository();
        });

        // Interface bindings with aliases for term/text discrimination
        $container->singleton('tags.repository.term', function (Container $c): MySqlTermTagRepository {
            return $c->getTyped(MySqlTermTagRepository::class);
        });

        $container->singleton('tags.repository.text', function (Container $c): MySqlTextTagRepository {
            return $c->getTyped(MySqlTextTagRepository::class);
        });
    }

    /**
     * Register association bindings.
     *
     * @param Container $container The DI container
     *
     * @return void
     */
    private function registerAssociations(Container $container): void
    {
        // Word Tag Association (for term tags)
        $container->singleton(MySqlWordTagAssociation::class, function (Container $c) {
            return new MySqlWordTagAssociation(
                $c->getTyped(MySqlTermTagRepository::class)
            );
        });

        // Text Tag Association
        $container->singleton(MySqlTextTagAssociation::class, function (Container $c) {
            return new MySqlTextTagAssociation(
                $c->getTyped(MySqlTextTagRepository::class)
            );
        });

        // Archived Text Tag Association
        $container->singleton(MySqlArchivedTextTagAssociation::class, function (Container $c) {
            return new MySqlArchivedTextTagAssociation(
                $c->getTyped(MySqlTextTagRepository::class)
            );
        });

        // Interface bindings with aliases
        $container->singleton('tags.association.word', function (Container $c): MySqlWordTagAssociation {
            return $c->getTyped(MySqlWordTagAssociation::class);
        });

        $container->singleton('tags.association.text', function (Container $c): MySqlTextTagAssociation {
            return $c->getTyped(MySqlTextTagAssociation::class);
        });

        $container->singleton('tags.association.archived', function (Container $c): MySqlArchivedTextTagAssociation {
            return $c->getTyped(MySqlArchivedTextTagAssociation::class);
        });
    }

    /**
     * Register use case bindings.
     *
     * @param Container $container The DI container
     *
     * @return void
     */
    private function registerUseCases(Container $container): void
    {
        // Term tag use cases
        $container->singleton('tags.usecase.term.create', function (Container $c) {
            return new CreateTag($c->getTyped(MySqlTermTagRepository::class));
        });

        $container->singleton('tags.usecase.term.update', function (Container $c) {
            return new UpdateTag($c->getTyped(MySqlTermTagRepository::class));
        });

        $container->singleton('tags.usecase.term.delete', function (Container $c) {
            return new DeleteTag(
                $c->getTyped(MySqlTermTagRepository::class),
                $c->getTyped(MySqlWordTagAssociation::class)
            );
        });

        $container->singleton('tags.usecase.term.getById', function (Container $c) {
            return new GetTagById($c->getTyped(MySqlTermTagRepository::class));
        });

        $container->singleton('tags.usecase.term.list', function (Container $c) {
            return new ListTags($c->getTyped(MySqlTermTagRepository::class));
        });

        // Text tag use cases
        $container->singleton('tags.usecase.text.create', function (Container $c) {
            return new CreateTag($c->getTyped(MySqlTextTagRepository::class));
        });

        $container->singleton('tags.usecase.text.update', function (Container $c) {
            return new UpdateTag($c->getTyped(MySqlTextTagRepository::class));
        });

        $container->singleton('tags.usecase.text.delete', function (Container $c) {
            return new DeleteTag(
                $c->getTyped(MySqlTextTagRepository::class),
                $c->getTyped(MySqlTextTagAssociation::class)
            );
        });

        $container->singleton('tags.usecase.text.getById', function (Container $c) {
            return new GetTagById($c->getTyped(MySqlTextTagRepository::class));
        });

        $container->singleton('tags.usecase.text.list', function (Container $c) {
            return new ListTags($c->getTyped(MySqlTextTagRepository::class));
        });

        // Shared use case
        $container->singleton(GetAllTagNames::class, function (Container $c) {
            return new GetAllTagNames(
                $c->getTyped(MySqlTermTagRepository::class),
                $c->getTyped(MySqlTextTagRepository::class),
                $_SERVER['REQUEST_URI'] ?? null
            );
        });
    }

    /**
     * Register facade bindings.
     *
     * @param Container $container The DI container
     *
     * @return void
     */
    private function registerFacades(Container $container): void
    {
        // Term tags facade
        $container->singleton('tags.facade.term', function (Container $c) {
            return new TagsFacade(
                TagType::TERM,
                $c->getTyped(MySqlTermTagRepository::class),
                $c->getTyped(MySqlWordTagAssociation::class)
            );
        });

        // Text tags facade
        $container->singleton('tags.facade.text', function (Container $c) {
            return new TagsFacade(
                TagType::TEXT,
                $c->getTyped(MySqlTextTagRepository::class),
                $c->getTyped(MySqlTextTagAssociation::class)
            );
        });

        // Default TagsFacade binding (term tags)
        $container->singleton(TagsFacade::class, function (Container $c): TagsFacade {
            /** @var TagsFacade */
            return $c->get('tags.facade.term');
        });
    }

    /**
     * Register controller bindings.
     *
     * @param Container $container The DI container
     *
     * @return void
     */
    private function registerControllers(Container $container): void
    {
        // Term Tag Controller
        $container->singleton(TermTagController::class, function (Container $c) {
            /** @var TagsFacade $termFacade */
            $termFacade = $c->get('tags.facade.term');
            return new TermTagController($termFacade);
        });

        // Text Tag Controller
        $container->singleton(TextTagController::class, function (Container $c) {
            /** @var TagsFacade $textFacade */
            $textFacade = $c->get('tags.facade.text');
            return new TextTagController($textFacade);
        });
    }

    /**
     * Register API handler bindings.
     *
     * @param Container $container The DI container
     *
     * @return void
     */
    private function registerApiHandler(Container $container): void
    {
        $container->singleton(TagApiHandler::class, function (Container $_c) {
            return new TagApiHandler();
        });
    }

    /**
     * {@inheritdoc}
     */
    public function boot(Container $container): void
    {
        // No bootstrap logic needed for the Tags module
    }
}
