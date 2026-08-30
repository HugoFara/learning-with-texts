<?php

/**
 * Vocabulary Base Controller
 *
 * PHP version 8.2
 *
 * @category Lwt
 * @package  Lwt\Modules\Vocabulary\Http
 * @author   HugoFara <git@hugofara.net>
 * @license  Unlicense <http://unlicense.org/>
 * @link     https://hugofara.github.io/lwt/developer/api
 * @since    3.0.0
 */

declare(strict_types=1);

namespace Lwt\Modules\Vocabulary\Http;

use Lwt\Modules\Vocabulary\Application\Services\WordCrudService;
use Lwt\Modules\Vocabulary\Application\Services\WordContextService;
use Lwt\Modules\Vocabulary\Application\Services\WordBulkService;
use Lwt\Modules\Vocabulary\Application\Services\WordDiscoveryService;
use Lwt\Modules\Vocabulary\Application\Services\WordLinkingService;
use Lwt\Modules\Vocabulary\Application\Services\MultiWordService;
use Lwt\Modules\Vocabulary\Application\Services\ExpressionService;
use Lwt\Modules\Vocabulary\Application\Services\WordUploadService;
use Lwt\Modules\Text\Application\Services\SentenceService;
use Lwt\Modules\Text\Application\Services\TextStatisticsService;

/**
 * Base controller for vocabulary-related controllers.
 *
 * Provides shared view rendering and lazy-loaded services.
 *
 * @since 3.0.0
 */
abstract class VocabularyBaseController
{
    /**
     * View base path.
     */
    protected string $viewPath;

    /**
     * Lazy-loaded services.
     */
    protected ?WordCrudService $crudService = null;
    protected ?WordContextService $contextService = null;
    protected ?WordBulkService $bulkService = null;
    protected ?WordDiscoveryService $discoveryService = null;
    protected ?WordLinkingService $linkingService = null;
    protected ?MultiWordService $multiWordService = null;
    protected ?SentenceService $sentenceService = null;
    protected ?ExpressionService $expressionService = null;
    protected ?WordUploadService $uploadService = null;
    protected ?TextStatisticsService $textStatisticsService = null;

    /**
     * Constructor.
     */
    public function __construct()
    {
        $this->viewPath = __DIR__ . '/../Views/';
    }

    /**
     * Get WordCrudService (lazy loaded).
     *
     * @return WordCrudService
     */
    protected function getCrudService(): WordCrudService
    {
        if ($this->crudService === null) {
            $this->crudService = new WordCrudService();
        }
        return $this->crudService;
    }

    /**
     * Get WordContextService (lazy loaded).
     *
     * @return WordContextService
     */
    protected function getContextService(): WordContextService
    {
        if ($this->contextService === null) {
            $this->contextService = new WordContextService();
        }
        return $this->contextService;
    }

    /**
     * Get WordBulkService (lazy loaded).
     *
     * @return WordBulkService
     */
    protected function getBulkService(): WordBulkService
    {
        if ($this->bulkService === null) {
            $this->bulkService = new WordBulkService();
        }
        return $this->bulkService;
    }

    /**
     * Get WordDiscoveryService (lazy loaded).
     *
     * @return WordDiscoveryService
     */
    protected function getDiscoveryService(): WordDiscoveryService
    {
        if ($this->discoveryService === null) {
            $this->discoveryService = new WordDiscoveryService();
        }
        return $this->discoveryService;
    }

    /**
     * Get WordLinkingService (lazy loaded).
     *
     * @return WordLinkingService
     */
    protected function getLinkingService(): WordLinkingService
    {
        if ($this->linkingService === null) {
            $this->linkingService = new WordLinkingService();
        }
        return $this->linkingService;
    }

    /**
     * Get MultiWordService (lazy loaded).
     *
     * @return MultiWordService
     */
    protected function getMultiWordService(): MultiWordService
    {
        if ($this->multiWordService === null) {
            $this->multiWordService = new MultiWordService();
        }
        return $this->multiWordService;
    }

    /**
     * Get SentenceService (lazy loaded).
     *
     * @return SentenceService
     */
    protected function getSentenceService(): SentenceService
    {
        if ($this->sentenceService === null) {
            $this->sentenceService = new SentenceService();
        }
        return $this->sentenceService;
    }

    /**
     * Get ExpressionService (lazy loaded).
     *
     * @return ExpressionService
     */
    protected function getExpressionService(): ExpressionService
    {
        if ($this->expressionService === null) {
            $this->expressionService = new ExpressionService();
        }
        return $this->expressionService;
    }

    /**
     * Get WordUploadService (lazy loaded).
     *
     * @return WordUploadService
     */
    protected function getUploadService(): WordUploadService
    {
        if ($this->uploadService === null) {
            $this->uploadService = new WordUploadService();
        }
        return $this->uploadService;
    }

    /**
     * Get TextStatisticsService (lazy loaded).
     *
     * @return TextStatisticsService
     */
    protected function getTextStatisticsService(): TextStatisticsService
    {
        if ($this->textStatisticsService === null) {
            $this->textStatisticsService = new TextStatisticsService();
        }
        return $this->textStatisticsService;
    }

    /**
     * Set custom view path.
     *
     * @param string $path View path
     *
     * @return void
     */
    public function setViewPath(string $path): void
    {
        $this->viewPath = rtrim($path, '/') . '/';
    }

    /**
     * Render a view.
     *
     * @param string $view View name (without .php)
     * @param array  $data View data
     *
     * @return void
     */
    protected function render(string $view, array $data = []): void
    {
        $viewFile = $this->viewPath . $view . '.php';

        if (!file_exists($viewFile)) {
            throw new \RuntimeException("View not found: $view");
        }

        // EXTR_SKIP prevents overwriting existing variables
        extract($data, EXTR_SKIP);
        require $viewFile;
    }
}
