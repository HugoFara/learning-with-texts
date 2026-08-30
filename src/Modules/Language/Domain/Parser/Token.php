<?php

/**
 * Token Value Object
 *
 * PHP version 8.2
 *
 * @category Parser
 * @package  Lwt\Modules\Language\Domain\Parser
 * @author   HugoFara <git@hugofara.net>
 * @license  Unlicense <http://unlicense.org/>
 * @link     https://hugofara.github.io/lwt/developer/api
 * @since    3.0.0
 */

declare(strict_types=1);

namespace Lwt\Modules\Language\Domain\Parser;

/**
 * Represents a single token from text parsing.
 *
 * A token can be either a word (learnable content) or a non-word
 * (punctuation, whitespace, symbols). Tokens maintain their position
 * within the text for proper reconstruction and display.
 *
 * @since 3.0.0
 */
class Token
{
    /**
     * Create a new token.
     *
     * @param string $text          The token text content
     * @param int    $sentenceIndex Index of the sentence this token belongs to (0-based)
     * @param int    $order         Position of this token within its sentence (0-based)
     * @param bool   $isWord        True if this is a learnable word, false for punctuation/whitespace
     * @param int    $wordCount     Number of words (1 for single word, >1 for multi-word expressions)
     * @param string $reading       Optional phonetic reading (e.g., furigana for Japanese)
     */
    public function __construct(
        private string $text,
        private int $sentenceIndex,
        private int $order,
        private bool $isWord,
        private int $wordCount = 1,
        private string $reading = ''
    ) {
    }

    /**
     * Get the token text content.
     *
     * @return string Token text
     */
    public function getText(): string
    {
        return $this->text;
    }

    /**
     * Get the sentence index this token belongs to.
     *
     * @return int Sentence index (0-based)
     */
    public function getSentenceIndex(): int
    {
        return $this->sentenceIndex;
    }

    /**
     * Get the order/position within the sentence.
     *
     * @return int Order within sentence (0-based)
     */
    public function getOrder(): int
    {
        return $this->order;
    }

    /**
     * Check if this token is a learnable word.
     *
     * @return bool True for words, false for punctuation/whitespace
     */
    public function isWord(): bool
    {
        return $this->isWord;
    }

    /**
     * Get the word count (for multi-word expressions).
     *
     * @return int Word count (1 for single words)
     */
    public function getWordCount(): int
    {
        return $this->wordCount;
    }

    /**
     * Get the phonetic reading.
     *
     * @return string Phonetic reading or empty string if not available
     */
    public function getReading(): string
    {
        return $this->reading;
    }

    /**
     * Create a word token.
     *
     * @param string $text          Word text
     * @param int    $sentenceIndex Sentence index
     * @param int    $order         Order within sentence
     * @param string $reading       Optional phonetic reading
     *
     * @return self New word token
     */
    public static function word(
        string $text,
        int $sentenceIndex,
        int $order,
        string $reading = ''
    ): self {
        return new self($text, $sentenceIndex, $order, true, 1, $reading);
    }

    /**
     * Create a non-word token (punctuation, whitespace, etc.).
     *
     * @param string $text          Token text
     * @param int    $sentenceIndex Sentence index
     * @param int    $order         Order within sentence
     *
     * @return self New non-word token
     */
    public static function nonWord(string $text, int $sentenceIndex, int $order): self
    {
        return new self($text, $sentenceIndex, $order, false, 0);
    }
}
