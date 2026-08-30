<?php

/**
 * Vocabulary Module Service Provider
 *
 * Registers all services for the Vocabulary module.
 *
 * PHP version 8.2
 *
 * @category Lwt
 * @package  Lwt\Modules\Vocabulary
 * @author   HugoFara <git@hugofara.net>
 * @license  Unlicense <http://unlicense.org/>
 * @link     https://hugofara.github.io/lwt/developer/api
 * @since    3.0.0
 */

declare(strict_types=1);

namespace Lwt\Modules\Vocabulary;

use Lwt\Shared\Infrastructure\Container\Container;
use Lwt\Shared\Infrastructure\Container\ServiceProviderInterface;
// Domain
use Lwt\Modules\Vocabulary\Domain\TermRepositoryInterface;
// Infrastructure
use Lwt\Modules\Vocabulary\Infrastructure\MySqlTermRepository;
// Use Cases
use Lwt\Modules\Vocabulary\Application\UseCases\CreateTerm;
use Lwt\Modules\Vocabulary\Application\UseCases\DeleteTerm;
use Lwt\Modules\Vocabulary\Application\UseCases\FindSimilarTerms;
use Lwt\Modules\Vocabulary\Application\UseCases\GetTermById;
use Lwt\Modules\Vocabulary\Application\UseCases\UpdateTerm;
use Lwt\Modules\Vocabulary\Application\UseCases\UpdateTermStatus;
// Services
use Lwt\Modules\Vocabulary\Application\Services\SimilarityCalculator;
use Lwt\Modules\Vocabulary\Application\Services\LemmaService;
// Lemmatizers
use Lwt\Modules\Vocabulary\Domain\LemmatizerInterface;
use Lwt\Modules\Vocabulary\Infrastructure\Lemmatizers\DictionaryLemmatizer;
// Infrastructure
use Lwt\Shared\Infrastructure\Dictionary\DictionaryAdapter;
// Application
use Lwt\Modules\Vocabulary\Application\VocabularyFacade;
// HTTP
use Lwt\Modules\Vocabulary\Http\VocabularyController;
use Lwt\Modules\Vocabulary\Http\TermCrudApiHandler;
use Lwt\Modules\Vocabulary\Http\WordFamilyApiHandler;
use Lwt\Modules\Vocabulary\Http\MultiWordApiHandler;
use Lwt\Modules\Vocabulary\Http\WordListApiHandler;
use Lwt\Modules\Vocabulary\Http\TermTranslationApiHandler;
use Lwt\Modules\Vocabulary\Http\TermStatusApiHandler;
use Lwt\Modules\Vocabulary\Http\VocabularyApiRouter;
use Lwt\Modules\Text\Application\TextFacade;
use Lwt\Modules\Vocabulary\Application\Services\WordListService;
use Lwt\Modules\Vocabulary\Application\Services\WordUploadService;
use Lwt\Modules\Vocabulary\Application\Services\ExpressionService;
use Lwt\Modules\Vocabulary\Application\Services\ExportService;
use Lwt\Modules\Vocabulary\Application\Services\WordContextService;
use Lwt\Modules\Vocabulary\Application\Services\WordLinkingService;
use Lwt\Modules\Vocabulary\Application\Services\MultiWordService;
use Lwt\Modules\Vocabulary\Application\Services\WordBulkService;
use Lwt\Modules\Vocabulary\Application\Services\WordDiscoveryService;
use Lwt\Modules\Vocabulary\Application\Services\WordCrudService;
use Lwt\Modules\Text\Application\Services\SentenceService;
use Lwt\Modules\Vocabulary\Application\Services\FrequencyImportService;
use Lwt\Modules\Vocabulary\Application\Services\WiktionaryEnrichmentService;
use Lwt\Modules\Vocabulary\Http\StarterVocabController;
// Controllers
use Lwt\Modules\Vocabulary\Http\TermEditController;
use Lwt\Modules\Vocabulary\Http\TermDisplayController;
use Lwt\Modules\Vocabulary\Http\TermStatusController;
use Lwt\Modules\Vocabulary\Http\TermApiController;
use Lwt\Modules\Vocabulary\Http\TermImportController;
use Lwt\Modules\Language\Application\LanguageFacade;

/**
 * Service provider for the Vocabulary module.
 *
 * Registers the TermRepositoryInterface, all use cases,
 * and VocabularyFacade.
 *
 * @since 3.0.0
 */
class VocabularyServiceProvider implements ServiceProviderInterface
{
    /**
     * {@inheritdoc}
     */
    public function register(Container $container): void
    {
        // Register Repository Interface binding
        $container->singleton(TermRepositoryInterface::class, function (Container $_c) {
            return new MySqlTermRepository();
        });

        // Register MySqlTermRepository as concrete implementation
        $container->singleton(MySqlTermRepository::class, function (Container $c): MySqlTermRepository {
            /** @var MySqlTermRepository */
            return $c->getTyped(TermRepositoryInterface::class);
        });

        // Register Use Cases
        $container->singleton(CreateTerm::class, function (Container $c) {
            return new CreateTerm($c->getTyped(TermRepositoryInterface::class));
        });

        $container->singleton(GetTermById::class, function (Container $c) {
            return new GetTermById($c->getTyped(TermRepositoryInterface::class));
        });

        $container->singleton(UpdateTerm::class, function (Container $c) {
            return new UpdateTerm($c->getTyped(TermRepositoryInterface::class));
        });

        $container->singleton(DeleteTerm::class, function (Container $c) {
            return new DeleteTerm($c->getTyped(TermRepositoryInterface::class));
        });

        $container->singleton(UpdateTermStatus::class, function (Container $c) {
            return new UpdateTermStatus($c->getTyped(TermRepositoryInterface::class));
        });

        // Register Services
        $container->singleton(SimilarityCalculator::class, function (Container $_c) {
            return new SimilarityCalculator();
        });

        $container->singleton(FindSimilarTerms::class, function (Container $c) {
            return new FindSimilarTerms(
                $c->getTyped(SimilarityCalculator::class)
            );
        });

        $container->singleton(DictionaryAdapter::class, function (Container $_c) {
            return new DictionaryAdapter();
        });

        // Register Module Services
        $container->singleton(WordListService::class, function (Container $_c) {
            return new WordListService();
        });

        $container->singleton(WordUploadService::class, function (Container $_c) {
            return new WordUploadService();
        });

        $container->singleton(ExpressionService::class, function (Container $_c) {
            return new ExpressionService();
        });

        $container->singleton(ExportService::class, function (Container $_c) {
            return new ExportService();
        });

        // Register SentenceService (from Text module)
        $container->singleton(SentenceService::class, function (Container $_c) {
            return new SentenceService();
        });

        // Register WordContextService
        $container->singleton(WordContextService::class, function (Container $c) {
            return new WordContextService(
                $c->getTyped(SentenceService::class)
            );
        });

        // Register WordLinkingService
        $container->singleton(WordLinkingService::class, function (Container $_c) {
            return new WordLinkingService();
        });

        // Register MultiWordService
        $container->singleton(MultiWordService::class, function (Container $c) {
            return new MultiWordService(
                $c->getTyped(ExpressionService::class)
            );
        });

        // Register WordBulkService
        $container->singleton(WordBulkService::class, function (Container $_c) {
            return new WordBulkService();
        });

        // Register WordDiscoveryService
        $container->singleton(WordDiscoveryService::class, function (Container $c) {
            return new WordDiscoveryService(
                $c->getTyped(WordContextService::class),
                $c->getTyped(WordLinkingService::class)
            );
        });

        // Register WordCrudService
        $container->singleton(WordCrudService::class, function (Container $c) {
            return new WordCrudService(
                $c->getTyped(MySqlTermRepository::class)
            );
        });

        // Register Lemmatizer
        $container->singleton(LemmatizerInterface::class, function (Container $_c) {
            return new DictionaryLemmatizer();
        });

        $container->singleton(DictionaryLemmatizer::class, function (Container $c): DictionaryLemmatizer {
            /** @var DictionaryLemmatizer */
            return $c->getTyped(LemmatizerInterface::class);
        });

        $container->singleton(LemmaService::class, function (Container $c) {
            return new LemmaService(
                $c->getTyped(LemmatizerInterface::class),
                $c->getTyped(MySqlTermRepository::class)
            );
        });

        // Register Facade
        $container->singleton(VocabularyFacade::class, function (Container $c) {
            return new VocabularyFacade(
                $c->getTyped(TermRepositoryInterface::class),
                $c->getTyped(CreateTerm::class),
                $c->getTyped(GetTermById::class),
                $c->getTyped(UpdateTerm::class),
                $c->getTyped(DeleteTerm::class),
                $c->getTyped(UpdateTermStatus::class)
            );
        });

        // Register Term CRUD API Handler
        $container->singleton(TermCrudApiHandler::class, function (Container $c) {
            return new TermCrudApiHandler(
                $c->getTyped(VocabularyFacade::class),
                $c->getTyped(FindSimilarTerms::class),
                $c->getTyped(WordContextService::class),
                $c->getTyped(WordDiscoveryService::class),
                $c->getTyped(WordLinkingService::class)
            );
        });

        // Register Word Family API Handler
        $container->singleton(WordFamilyApiHandler::class, function (Container $c) {
            return new WordFamilyApiHandler(
                $c->getTyped(LemmaService::class)
            );
        });

        // Register Multi-word API Handler
        $container->singleton(MultiWordApiHandler::class, function (Container $c) {
            return new MultiWordApiHandler(
                $c->getTyped(MultiWordService::class),
                $c->getTyped(WordContextService::class)
            );
        });

        // Register Word List API Handler
        $container->singleton(WordListApiHandler::class, function (Container $c) {
            return new WordListApiHandler(
                $c->getTyped(WordListService::class)
            );
        });

        // Register Term Translation API Handler
        $container->singleton(TermTranslationApiHandler::class, function (Container $c) {
            return new TermTranslationApiHandler(
                $c->getTyped(FindSimilarTerms::class),
                $c->getTyped(DictionaryAdapter::class)
            );
        });

        // Register Term Status API Handler
        $container->singleton(TermStatusApiHandler::class, function (Container $c) {
            return new TermStatusApiHandler(
                $c->getTyped(VocabularyFacade::class)
            );
        });

        // Register Vocabulary API Router (dispatches /terms/* requests)
        $container->singleton(VocabularyApiRouter::class, function (Container $c) {
            return new VocabularyApiRouter(
                $c->getTyped(TermCrudApiHandler::class),
                $c->getTyped(WordFamilyApiHandler::class),
                $c->getTyped(MultiWordApiHandler::class),
                $c->getTyped(WordListApiHandler::class),
                $c->getTyped(TermTranslationApiHandler::class),
                $c->getTyped(TermStatusApiHandler::class),
                $c->getTyped(TextFacade::class)
            );
        });

        // Register Controllers
        $container->singleton(TermEditController::class, function (Container $c) {
            return new TermEditController(
                $c->getTyped(VocabularyFacade::class),
                $c->getTyped(DictionaryAdapter::class),
                $c->getTyped(LanguageFacade::class)
            );
        });

        $container->singleton(TermDisplayController::class, function (Container $c) {
            return new TermDisplayController(
                $c->getTyped(VocabularyFacade::class),
                $c->getTyped(FindSimilarTerms::class),
                $c->getTyped(DictionaryAdapter::class),
                $c->getTyped(LanguageFacade::class)
            );
        });

        $container->singleton(TermStatusController::class, function (Container $c) {
            return new TermStatusController(
                $c->getTyped(VocabularyFacade::class)
            );
        });

        $container->singleton(TermApiController::class, function (Container $c) {
            return new TermApiController(
                $c->getTyped(VocabularyFacade::class)
            );
        });

        $container->singleton(TermImportController::class, function (Container $c) {
            return new TermImportController(
                $c->getTyped(LanguageFacade::class),
                $c->getTyped(\Lwt\Modules\Dictionary\Application\DictionaryFacade::class)
            );
        });

        // Register Starter Vocabulary services
        $container->singleton(FrequencyImportService::class, function (Container $_c) {
            return new FrequencyImportService();
        });

        $container->singleton(WiktionaryEnrichmentService::class, function (Container $_c) {
            return new WiktionaryEnrichmentService();
        });

        $container->singleton(StarterVocabController::class, function (Container $c) {
            return new StarterVocabController(
                $c->getTyped(LanguageFacade::class),
                $c->getTyped(FrequencyImportService::class),
                $c->getTyped(WiktionaryEnrichmentService::class)
            );
        });
    }

    /**
     * {@inheritdoc}
     */
    public function boot(Container $container): void
    {
        // No bootstrap logic needed for the Vocabulary module yet
    }
}
