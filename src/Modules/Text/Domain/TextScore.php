<?php

/**
 * Text Score Value Object
 *
 * Represents the difficulty/comprehensibility score for a text
 * based on the user's vocabulary knowledge.
 *
 * PHP version 8.2
 *
 * @category Lwt
 * @package  Lwt\Modules\Text\Domain
 * @author   HugoFara <git@hugofara.net>
 * @license  Unlicense <http://unlicense.org/>
 * @link     https://hugofara.github.io/lwt/developer/api
 * @since    3.0.0
 */

declare(strict_types=1);

namespace Lwt\Modules\Text\Domain;

/**
 * Value object representing a text's difficulty score.
 *
 * Contains comprehensibility metrics based on comparing the text's
 * vocabulary against the user's known words.
 *
 * @since 3.0.0
 */
final readonly class TextScore
{
    /**
     * @param int      $textId           The text ID
     * @param int      $totalUniqueWords Total unique single words in text
     * @param int      $knownWords       Words with status 5 or 99
     * @param int      $learningWords    Words with status 1-4
     * @param int      $unknownWords     Words not in user's vocabulary
     * @param string[] $unknownWordsList List of unknown word texts (for preview)
     */
    public function __construct(
        public int $textId,
        public int $totalUniqueWords,
        public int $knownWords,
        public int $learningWords,
        public int $unknownWords,
        public array $unknownWordsList = []
    ) {
    }

    /**
     * Calculate the comprehensibility percentage.
     *
     * This represents the percentage of words the user already knows.
     * A value of 0.95 means 95% of words are known.
     *
     * @return float Value between 0.0 and 1.0
     */
    public function comprehensibility(): float
    {
        if ($this->totalUniqueWords === 0) {
            return 1.0;
        }
        return $this->knownWords / $this->totalUniqueWords;
    }

    /**
     * Calculate the comprehensibility as a percentage (0-100).
     *
     * @return float Value between 0.0 and 100.0
     */
    public function comprehensibilityPercent(): float
    {
        return round($this->comprehensibility() * 100, 1);
    }

    /**
     * Get a difficulty label based on comprehensibility.
     *
     * Based on research suggesting 95-98% comprehension is optimal
     * for language acquisition (Krashen's i+1 hypothesis).
     *
     * @return string One of: 'too_easy', 'optimal', 'challenging', 'difficult', 'too_hard'
     */
    public function difficultyLabel(): string
    {
        $comp = $this->comprehensibility();

        if ($comp >= 0.99) {
            return 'too_easy';
        }
        if ($comp >= 0.95) {
            return 'optimal';
        }
        if ($comp >= 0.90) {
            return 'challenging';
        }
        if ($comp >= 0.80) {
            return 'difficult';
        }
        return 'too_hard';
    }

    /**
     * Check if the text is at an optimal difficulty level.
     *
     * Optimal is defined as 95-98% comprehensibility.
     *
     * @return bool
     */
    public function isOptimal(): bool
    {
        $comp = $this->comprehensibility();
        return $comp >= 0.95 && $comp < 0.99;
    }

    /**
     * Get the number of new words to learn from this text.
     *
     * @return int
     */
    public function newWordsToLearn(): int
    {
        return $this->unknownWords + $this->learningWords;
    }

    /**
     * Convert to array for API responses.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'text_id' => $this->textId,
            'total_unique_words' => $this->totalUniqueWords,
            'known_words' => $this->knownWords,
            'learning_words' => $this->learningWords,
            'unknown_words' => $this->unknownWords,
            'comprehensibility' => $this->comprehensibility(),
            'comprehensibility_percent' => $this->comprehensibilityPercent(),
            'difficulty_label' => $this->difficultyLabel(),
            'is_optimal' => $this->isOptimal(),
            'unknown_words_list' => $this->unknownWordsList,
        ];
    }
}
