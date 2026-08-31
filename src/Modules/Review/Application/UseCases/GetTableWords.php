<?php

/**
 * Get Table Words Use Case
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
use Lwt\Modules\Language\Application\LanguageFacade;
use Lwt\Shared\Infrastructure\Language\LanguagePresets;
use Lwt\Modules\Vocabulary\Application\Services\ExportService;

/**
 * Use case for getting all words for table test mode.
 *
 * @since 3.0.0
 */
class GetTableWords
{
    private ReviewRepositoryInterface $repository;

    /**
     * Constructor.
     *
     * @param ReviewRepositoryInterface $repository Review repository
     */
    public function __construct(ReviewRepositoryInterface $repository)
    {
        $this->repository = $repository;
    }

    /**
     * Get all words for table test mode.
     *
     * @param ReviewConfiguration $config Test configuration
     *
     * @return array Table words data
     */
    public function execute(ReviewConfiguration $config): array
    {
        if (!$config->isValid()) {
            return ['error' => 'Invalid test configuration'];
        }

        // Validate single language
        $validation = $this->repository->validateSingleLanguage($config);
        if (!$validation['valid']) {
            return ['error' => $validation['error']];
        }

        // Get language ID
        $langId = $this->repository->getLanguageIdFromConfig($config);
        if ($langId === null) {
            return ['words' => [], 'langSettings' => null];
        }

        // Get language settings
        $langSettings = $this->repository->getLanguageSettings($langId);
        $regexWord = $langSettings['regexWord'] ?? '';

        // Get language code for TTS
        $languageService = new LanguageFacade();
        $langCode = $languageService->getLanguageCode($langId, LanguagePresets::getAll());

        // Get words
        $words = $this->repository->getTableWords($config);

        // Format words for response
        $formattedWords = [];
        foreach ($words as $word) {
            // Format sentence with highlighted word
            $sent = htmlspecialchars(
                ExportService::replaceTabNewline($word->sentence ?? ''),
                ENT_QUOTES,
                'UTF-8'
            );
            $sentenceHtml = str_replace(
                '{',
                ' <b>[',
                str_replace(
                    '}',
                    ']</b> ',
                    ExportService::maskTermInSentence($sent, $regexWord)
                )
            );

            $formattedWords[] = [
                'id' => $word->id,
                'text' => $word->text,
                'translation' => $word->translation,
                'romanization' => $word->romanization,
                'sentence' => $sent,
                'sentenceHtml' => $sentenceHtml,
                'status' => $word->status,
                'score' => $word->score
            ];
        }

        // Get table settings
        $tableSettings = $this->repository->getTableReviewSettings();

        return [
            'words' => $formattedWords,
            'langSettings' => [
                'name' => $langSettings['name'] ?? '',
                'dict1Uri' => $langSettings['dict1Uri'] ?? '',
                'dict2Uri' => $langSettings['dict2Uri'] ?? '',
                'translateUri' => $langSettings['translateUri'] ?? '',
                'textSize' => $langSettings['textSize'] ?? 100,
                'rtl' => $langSettings['rtl'] ?? false,
                'langCode' => $langCode
            ],
            'tableSettings' => $tableSettings
        ];
    }
}
