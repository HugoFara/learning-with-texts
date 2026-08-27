<?php

/**
 * Review Repository Interface
 *
 * Domain port for review/test persistence operations.
 *
 * PHP version 8.1
 *
 * @category Lwt
 * @package  Lwt\Modules\Review\Domain
 * @author   HugoFara <git@hugofara.net>
 * @license  Unlicense <http://unlicense.org/>
 * @link     https://hugofara.github.io/lwt/developer/api
 * @since    3.0.0
 */

declare(strict_types=1);

namespace Lwt\Modules\Review\Domain;

/**
 * Repository interface for review/test operations.
 *
 * This is a domain port defining the contract for test data persistence.
 * Infrastructure implementations provide the actual database access.
 *
 * @since 3.0.0
 */
interface ReviewRepositoryInterface
{
    /**
     * Find the next word for testing using spaced repetition algorithm.
     *
     * @param ReviewConfiguration $config Test configuration
     *
     * @return ReviewWord|null Next word to test or null if none available
     */
    public function findNextWordForReview(ReviewConfiguration $config): ?ReviewWord;

    /**
     * Get a sentence containing the word for context.
     *
     * Finds a sentence with at least 70% known words for optimal learning.
     *
     * @param int    $wordId Word ID
     * @param string $wordLc Lowercase word text
     *
     * @return array{sentence: string|null, found: bool}
     */
    public function getSentenceForWord(int $wordId, string $wordLc): array;

    /**
     * Get test counts (due today and total).
     *
     * @param ReviewConfiguration $config Test configuration
     *
     * @return array{due: int, total: int}
     */
    public function getReviewCounts(ReviewConfiguration $config): array;

    /**
     * Get count of words due tomorrow.
     *
     * @param ReviewConfiguration $config Test configuration
     *
     * @return int Count of words due tomorrow
     */
    public function getTomorrowCount(ReviewConfiguration $config): int;

    /**
     * Get all words for table test mode.
     *
     * @param ReviewConfiguration $config Test configuration
     *
     * @return ReviewWord[] Array of test words
     */
    public function getTableWords(ReviewConfiguration $config): array;

    /**
     * Update word status during test.
     *
     * @param int $wordId    Word ID
     * @param int $newStatus New status (1-5, 98, 99)
     *
     * @return array{oldStatus: int, newStatus: int}
     */
    public function updateWordStatus(int $wordId, int $newStatus): array;

    /**
     * Get current word status.
     *
     * @param int $wordId Word ID
     *
     * @return int|null Current status or null if not found
     */
    public function getWordStatus(int $wordId): ?int;

    /**
     * Get language settings for test display.
     *
     * @param int $langId Language ID
     *
     * @return array{
     *     name: string,
     *     dict1Uri: string,
     *     dict2Uri: string,
     *     translateUri: string,
     *     textSize: int,
     *     removeSpaces: bool,
     *     regexWord: string,
     *     rtl: bool,
     *     ttsVoiceApi: string|null
     * }
     */
    public function getLanguageSettings(int $langId): array;

    /**
     * Get language ID from test configuration.
     *
     * @param ReviewConfiguration $config Test configuration
     *
     * @return int|null Language ID or null if none found
     */
    public function getLanguageIdFromConfig(ReviewConfiguration $config): ?int;

    /**
     * Validate that test selection contains only one language.
     *
     * @param ReviewConfiguration $config Test configuration
     *
     * @return array{valid: bool, langCount: int, error: string|null}
     */
    public function validateSingleLanguage(ReviewConfiguration $config): array;

    /**
     * Get language name from test configuration.
     *
     * @param ReviewConfiguration $config Test configuration
     *
     * @return string Language name or 'L2' as default
     */
    public function getLanguageName(ReviewConfiguration $config): string;

    /**
     * Get word text by ID.
     *
     * @param int $wordId Word ID
     *
     * @return string|null Word text or null if not found
     */
    public function getWordText(int $wordId): ?string;

    /**
     * Get table test visibility settings.
     *
     * @return array{
     *     edit: int,
     *     status: int,
     *     term: int,
     *     trans: int,
     *     rom: int,
     *     sentence: int,
     *     contextRom: int,
     *     contextTrans: int
     * }
     */
    public function getTableReviewSettings(): array;

    /**
     * Get sentence with annotations for surrounding words.
     *
     * Returns the sentence containing the word, along with translation and
     * romanization data for all known words in the sentence.
     *
     * @param int    $wordId Word ID
     * @param string $wordLc Lowercase word text
     *
     * @return array{
     *     sentence: string|null,
     *     sentenceId: int|null,
     *     found: bool,
     *     annotations: array<int, array{
     *         text: string,
     *         romanization: string|null,
     *         translation: string|null,
     *         isTarget: bool,
     *         order: int
     *     }>
     * }
     */
    public function getSentenceWithAnnotations(int $wordId, string $wordLc): array;
}
