<?php

/**
 * Get Next Term Use Case
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
use Lwt\Modules\Review\Domain\ReviewWord;
use Lwt\Modules\Tags\Application\TagsFacade;
use Lwt\Modules\Vocabulary\Application\Services\ExportService;

/**
 * Use case for fetching the next term to test.
 *
 * Retrieves the next word using spaced repetition algorithm,
 * gets sentence context, and formats the solution.
 *
 * @since 3.0.0
 */
class GetNextTerm
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
     * Get the next term for testing.
     *
     * @param ReviewConfiguration $config Test configuration
     *
     * @return array{
     *     word_id: int|string,
     *     word_text: string,
     *     solution: string,
     *     group: string,
     *     word?: ReviewWord
     * }
     */
    public function execute(ReviewConfiguration $config): array
    {
        // Get next word from repository
        $word = $this->repository->findNextWordForReview($config);

        if ($word === null) {
            return [
                'word_id' => 0,
                'word_text' => '',
                'solution' => '',
                'group' => ''
            ];
        }

        // Get sentence context if needed
        $sentence = $this->getSentenceForWord($word, $config->wordMode);

        // Format term for display
        list($htmlSentence, $displayWord) = $this->formatTermForTest(
            $word,
            $sentence,
            $config->reviewType
        );

        // Get solution text
        $solution = $this->getSolution(
            $config->reviewType,
            $word,
            $config->wordMode,
            $displayWord
        );

        return [
            'word_id' => $word->id,
            'word_text' => $displayWord,
            'solution' => $solution,
            'group' => $htmlSentence,
            'word' => $word
        ];
    }

    /**
     * Get sentence for word based on mode.
     *
     * @param ReviewWord $word     The word
     * @param bool     $wordMode Whether in word mode
     *
     * @return string Sentence with word marked
     */
    private function getSentenceForWord(ReviewWord $word, bool $wordMode): string
    {
        if ($wordMode) {
            return '{' . $word->text . '}';
        }

        // Try to get a good sentence from the database
        $sentenceData = $this->repository->getSentenceForWord(
            $word->id,
            $word->textLowercase
        );

        return $sentenceData['sentence'] ?? '{' . $word->text . '}';
    }

    /**
     * Format term for test display.
     *
     * @param ReviewWord $word     Word entity
     * @param string   $sentence Sentence containing the word
     * @param int      $testType Test type (1-5)
     *
     * @return array{0: string, 1: string} [HTML display, plain word text]
     */
    private function formatTermForTest(
        ReviewWord $word,
        string $sentence,
        int $testType
    ): array {
        $baseType = $testType > 3 ? $testType - 3 : $testType;

        // Extract the word from sentence (marked with {})
        if (preg_match('/\{([^}]+)\}/', $sentence, $matches)) {
            $markedWord = $matches[1];
        } else {
            $markedWord = $word->text;
        }

        // Build display HTML based on test type
        if ($baseType === 1) {
            // Type 1: Show term, guess translation
            $displayHtml = str_replace(
                '{' . $markedWord . '}',
                '<span class="word-test">' . htmlspecialchars($markedWord, ENT_QUOTES, 'UTF-8') . '</span>',
                $sentence
            );
        } else {
            // Types 2-3: Hide term
            $hiddenSpan = '<span class="word-test-hidden">[...]</span>';
            $displayHtml = str_replace('{' . $markedWord . '}', $hiddenSpan, $sentence);
        }

        // Clean up any remaining braces
        $displayHtml = str_replace(['{', '}'], '', $displayHtml);

        return [$displayHtml, $markedWord];
    }

    /**
     * Get solution text for the test.
     *
     * @param int      $testType Test type
     * @param ReviewWord $word     Word entity
     * @param bool     $wordMode Word mode flag
     * @param string   $wordText Displayed word text
     *
     * @return string Solution text
     */
    private function getSolution(
        int $testType,
        ReviewWord $word,
        bool $wordMode,
        string $wordText
    ): string {
        $baseType = $testType > 3 ? $testType - 3 : $testType;

        if ($baseType === 1) {
            // Show translation as solution
            $tagList = TagsFacade::getWordTagList($word->id, false);
            $tagFormatted = $tagList !== '' ? ' [' . $tagList . ']' : '';
            $trans = ExportService::replaceTabNewline($word->translation) . $tagFormatted;
            return $wordMode ? $trans : "[$trans]";
        }

        // Show term as solution
        return $wordText;
    }
}
