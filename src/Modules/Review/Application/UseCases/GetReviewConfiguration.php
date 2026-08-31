<?php

/**
 * Get Review Configuration Use Case
 *
 * PHP version 8.2
 *
 * @category Lwt
 * @package  Lwt\Modules\Review\Application\UseCases
 * @author   HugoFara <git@hugofara.net>
 * @license  Unlicense <http://unlicense.org/>
 * @link     https://hugofara.github.io/lwt/developer/api
 * @since    3.0.0
 */

declare(strict_types=1);

namespace Lwt\Modules\Review\Application\UseCases;

use Lwt\Modules\Review\Domain\ReviewRepositoryInterface;
use Lwt\Modules\Review\Domain\ReviewConfiguration;
use Lwt\Modules\Review\Infrastructure\SessionStateManager;
use Lwt\Modules\Language\Application\LanguageFacade;
use Lwt\Shared\Infrastructure\Language\LanguagePresets;

// LanguageFacade loaded via autoloader

/**
 * Use case for building review configuration from request parameters.
 *
 * Parses parameters, validates selection, and builds complete
 * configuration including language settings.
 *
 * @since 3.0.0
 */
class GetReviewConfiguration
{
    private ReviewRepositoryInterface $repository;
    private SessionStateManager $sessionManager;

    /**
     * Constructor.
     *
     * @param ReviewRepositoryInterface $repository     Review repository
     * @param SessionStateManager|null  $sessionManager Session manager (optional)
     */
    public function __construct(
        ReviewRepositoryInterface $repository,
        ?SessionStateManager $sessionManager = null
    ) {
        $this->repository = $repository;
        $this->sessionManager = $sessionManager ?? new SessionStateManager();
    }

    /**
     * Parse request parameters into ReviewConfiguration.
     *
     * @param int|null    $selection    Selection type (2=words, 3=texts)
     * @param string|null $sessTestsql  Session test SQL (comma-separated IDs)
     * @param int|null    $langId       Language ID
     * @param int|null    $textId       Text ID
     * @param int         $testType     Test type (1-5 or 'table')
     * @param bool        $isTableMode  Whether table mode
     *
     * @return ReviewConfiguration
     */
    public function parseFromParams(
        ?int $selection,
        ?string $sessTestsql,
        ?int $langId,
        ?int $textId,
        int $testType = 1,
        bool $isTableMode = false
    ): ReviewConfiguration {
        // Parse selection from session
        if ($selection !== null && $sessTestsql !== null) {
            $dataStringArray = explode(',', trim($sessTestsql, '()'));
            $dataIntArray = array_map('intval', $dataStringArray);

            $testKey = match ($selection) {
                2 => ReviewConfiguration::KEY_WORDS,
                3 => ReviewConfiguration::KEY_TEXTS,
                default => ReviewConfiguration::KEY_RAW_SQL
            };

            $selectionValue = $testKey === ReviewConfiguration::KEY_RAW_SQL
                ? $sessTestsql
                : $dataIntArray;

            return new ReviewConfiguration(
                $testKey,
                $selectionValue,
                max(1, min(5, $testType)),
                $testType > 3,
                $isTableMode
            );
        }

        // Parse language
        if ($langId !== null) {
            return $isTableMode
                ? ReviewConfiguration::forTableMode(ReviewConfiguration::KEY_LANG, $langId)
                : ReviewConfiguration::fromLanguage($langId, $testType);
        }

        // Parse text
        if ($textId !== null) {
            return $isTableMode
                ? ReviewConfiguration::forTableMode(ReviewConfiguration::KEY_TEXT, $textId)
                : ReviewConfiguration::fromText($textId, $testType);
        }

        // Return invalid configuration
        return new ReviewConfiguration('', '', 1, false, false);
    }

    /**
     * Get full review configuration for frontend initialization.
     *
     * @param ReviewConfiguration $config Test configuration
     *
     * @return array Full configuration or error
     */
    public function execute(ReviewConfiguration $config): array
    {
        if (!$config->isValid()) {
            return ['error' => 'Invalid review configuration'];
        }

        // Validate single language
        $validation = $this->repository->validateSingleLanguage($config);
        if (!$validation['valid']) {
            return ['error' => $validation['error']];
        }

        // Get language ID
        $langId = $this->repository->getLanguageIdFromConfig($config);
        if ($langId === null) {
            return ['error' => 'No words available for testing'];
        }

        // Get language settings
        $langSettings = $this->repository->getLanguageSettings($langId);

        // Get language code for TTS
        $languageService = new LanguageFacade();
        $langCode = $languageService->getLanguageCode($langId, LanguagePresets::getAll());

        // Get test counts
        $counts = $this->repository->getReviewCounts($config);

        // Get or create session
        $session = $this->sessionManager->getSession();
        if ($session === null) {
            $session = \Lwt\Modules\Review\Domain\ReviewSession::start($counts['due']);
            $this->sessionManager->saveSession($session);
        }

        // Get title
        $title = $this->buildTitle($config);

        return [
            'reviewKey' => $config->reviewKey,
            'selection' => $config->getSelectionString(),
            'reviewType' => $config->getBaseType(),
            'isTableMode' => $config->isTableMode,
            'wordMode' => $config->wordMode,
            'langId' => $langId,
            'wordRegex' => $langSettings['regexWord'] ?? '',
            'langSettings' => [
                'name' => $langSettings['name'] ?? '',
                'dict1Uri' => $langSettings['dict1Uri'] ?? '',
                'dict2Uri' => $langSettings['dict2Uri'] ?? '',
                'translateUri' => $langSettings['translateUri'] ?? '',
                'textSize' => $langSettings['textSize'] ?? 100,
                'rtl' => $langSettings['rtl'] ?? false,
                'langCode' => $langCode
            ],
            'progress' => [
                'total' => $counts['due'],
                'remaining' => $session->remaining(),
                'wrong' => $session->getWrong(),
                'correct' => $session->getCorrect()
            ],
            'timer' => [
                'startTime' => $session->getStartTime(),
                'serverTime' => time()
            ],
            'title' => $title,
            'property' => $config->toUrlProperty()
        ];
    }

    /**
     * Build title for test display.
     *
     * @param ReviewConfiguration $config Test configuration
     *
     * @return string Title string
     */
    private function buildTitle(ReviewConfiguration $config): string
    {
        $langName = $this->repository->getLanguageName($config);

        return match ($config->reviewKey) {
            ReviewConfiguration::KEY_LANG => "All Terms in {$langName}",
            ReviewConfiguration::KEY_TEXT => $this->getTextTitle($config),
            ReviewConfiguration::KEY_WORDS,
            ReviewConfiguration::KEY_TEXTS => $this->getSelectionTitle($config, $langName),
            default => 'Review'
        };
    }

    /**
     * Get title for text-based test.
     *
     * @param ReviewConfiguration $config Configuration
     *
     * @return string Title
     */
    private function getTextTitle(ReviewConfiguration $config): string
    {
        // Would need TextRepository to get title, for now use generic
        return 'Text Review';
    }

    /**
     * Get title for selection-based test.
     *
     * @param ReviewConfiguration $config   Configuration
     * @param string            $langName Language name
     *
     * @return string Title
     */
    private function getSelectionTitle(ReviewConfiguration $config, string $langName): string
    {
        $count = is_array($config->selection) ? count($config->selection) : 1;
        $plural = $count === 1 ? '' : 's';
        return "Selected {$count} Term{$plural} IN {$langName}";
    }
}
