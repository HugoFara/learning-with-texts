<?php

/**
 * Review Module Service Provider
 *
 * Registers all services for the Review module.
 *
 * PHP version 8.2
 *
 * @category Lwt
 * @package  Lwt\Modules\Review
 * @author   HugoFara <git@hugofara.net>
 * @license  Unlicense <http://unlicense.org/>
 * @link     https://hugofara.github.io/lwt/developer/api
 * @since    3.0.0
 */

declare(strict_types=1);

namespace Lwt\Modules\Review;

use Lwt\Shared\Infrastructure\Container\Container;
use Lwt\Shared\Infrastructure\Container\ServiceProviderInterface;
// Domain
use Lwt\Modules\Review\Domain\ReviewRepositoryInterface;
// Infrastructure
use Lwt\Modules\Review\Domain\Scheduling\Fsrs6Scheduler;
use Lwt\Modules\Review\Domain\Scheduling\SchedulerInterface;
use Lwt\Modules\Review\Domain\TermScheduleRepositoryInterface;
use Lwt\Modules\Review\Infrastructure\MySqlReviewRepository;
use Lwt\Modules\Review\Infrastructure\MySqlTermScheduleRepository;
use Lwt\Modules\Review\Infrastructure\SessionStateManager;
// Use Cases
use Lwt\Modules\Review\Application\UseCases\GetNextTerm;
use Lwt\Modules\Review\Application\UseCases\GetTableWords;
use Lwt\Modules\Review\Application\UseCases\GetReviewConfiguration;
use Lwt\Modules\Review\Application\UseCases\GetTomorrowCount;
use Lwt\Modules\Review\Application\UseCases\StartReviewSession;
use Lwt\Modules\Review\Application\UseCases\SubmitAnswer;
// Application
use Lwt\Modules\Review\Application\ReviewFacade;
// Http
use Lwt\Modules\Review\Http\ReviewController;
use Lwt\Modules\Review\Http\ReviewApiHandler;

/**
 * Service provider for the Review module.
 *
 * Registers the ReviewRepositoryInterface, all use cases,
 * ReviewFacade, ReviewController, and ReviewApiHandler.
 *
 * @since 3.0.0
 */
class ReviewServiceProvider implements ServiceProviderInterface
{
    /**
     * {@inheritdoc}
     */
    public function register(Container $container): void
    {
        // Register Repository Interface binding
        $container->singleton(ReviewRepositoryInterface::class, function (Container $_c) {
            return new MySqlReviewRepository();
        });

        // Scheduling state. Bound here rather than only constructed by hand,
        // because the .apkg exporter is auto-wired through the container and
        // asks for the interface.
        $container->singleton(TermScheduleRepositoryInterface::class, function (Container $_c) {
            return new MySqlTermScheduleRepository();
        });

        // The scheduler itself, for the same reason: RecordScheduledReview
        // takes it as an optional collaborator, and anything auto-wired that
        // reaches that use case asks the container for the interface first.
        $container->singleton(SchedulerInterface::class, function (Container $_c) {
            return new Fsrs6Scheduler();
        });

        // Register MySqlReviewRepository as concrete implementation
        $container->singleton(
            MySqlReviewRepository::class,
            /** @return MySqlReviewRepository */
            function (Container $c): MySqlReviewRepository {
                /** @var MySqlReviewRepository */
                return $c->getTyped(ReviewRepositoryInterface::class);
            }
        );

        // Register Infrastructure
        $container->singleton(SessionStateManager::class, function (Container $_c) {
            return new SessionStateManager();
        });

        // Register Use Cases
        $this->registerUseCases($container);

        // Register Facade
        $container->singleton(ReviewFacade::class, function (Container $c) {
            return new ReviewFacade(
                $c->getTyped(ReviewRepositoryInterface::class),
                $c->getTyped(SessionStateManager::class),
                $c->getTyped(GetNextTerm::class),
                $c->getTyped(GetTableWords::class),
                $c->getTyped(GetReviewConfiguration::class),
                $c->getTyped(GetTomorrowCount::class),
                $c->getTyped(StartReviewSession::class),
                $c->getTyped(SubmitAnswer::class)
            );
        });

        // Register Controller
        $container->singleton(ReviewController::class, function (Container $c) {
            return new ReviewController(
                $c->getTyped(ReviewFacade::class)
            );
        });

        // Register API Handler
        $container->singleton(ReviewApiHandler::class, function (Container $c) {
            return new ReviewApiHandler(
                $c->getTyped(ReviewFacade::class)
            );
        });
    }

    /**
     * Register all use case classes.
     *
     * @param Container $container The DI container
     *
     * @return void
     */
    private function registerUseCases(Container $container): void
    {
        // GetNextTerm use case
        $container->singleton(GetNextTerm::class, function (Container $c) {
            return new GetNextTerm(
                $c->getTyped(ReviewRepositoryInterface::class)
            );
        });

        // GetTableWords use case
        $container->singleton(GetTableWords::class, function (Container $c) {
            return new GetTableWords(
                $c->getTyped(ReviewRepositoryInterface::class)
            );
        });

        // GetReviewConfiguration use case
        $container->singleton(GetReviewConfiguration::class, function (Container $c) {
            return new GetReviewConfiguration(
                $c->getTyped(ReviewRepositoryInterface::class),
                $c->getTyped(SessionStateManager::class)
            );
        });

        // GetTomorrowCount use case
        $container->singleton(GetTomorrowCount::class, function (Container $c) {
            return new GetTomorrowCount(
                $c->getTyped(ReviewRepositoryInterface::class)
            );
        });

        // StartReviewSession use case
        $container->singleton(StartReviewSession::class, function (Container $c) {
            return new StartReviewSession(
                $c->getTyped(ReviewRepositoryInterface::class),
                $c->getTyped(SessionStateManager::class)
            );
        });

        // SubmitAnswer use case
        $container->singleton(SubmitAnswer::class, function (Container $c) {
            return new SubmitAnswer(
                $c->getTyped(ReviewRepositoryInterface::class),
                $c->getTyped(SessionStateManager::class)
            );
        });
    }

    /**
     * {@inheritdoc}
     */
    public function boot(Container $container): void
    {
        // No bootstrap logic needed for the Review module
    }
}
