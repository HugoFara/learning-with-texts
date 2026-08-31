<?php

/**
 * Parser Result Value Object
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
 * Immutable result from a parser containing sentences and tokens.
 *
 * This class encapsulates the output of text parsing, providing
 * structured access to both the sentence boundaries and individual
 * tokens (words and non-words).
 *
 * @since 3.0.0
 */
class ParserResult
{
    /**
     * Create a new parser result.
     *
     * @param string[] $sentences Array of sentence strings
     * @param Token[]  $tokens    Array of Token objects
     *
     * @psalm-param list<string> $sentences
     * @psalm-param list<Token> $tokens
     */
    public function __construct(
        private array $sentences,
        private array $tokens
    ) {
    }

    /**
     * Get all sentences as strings.
     *
     * @return string[] Array of sentence strings
     *
     * @psalm-return list<string>
     */
    public function getSentences(): array
    {
        return $this->sentences;
    }

    /**
     * Get all tokens.
     *
     * @return Token[] Array of Token objects
     *
     * @psalm-return list<Token>
     */
    public function getTokens(): array
    {
        return $this->tokens;
    }

    /**
     * Get tokens for a specific sentence.
     *
     * @param int $sentenceIndex Sentence index (0-based)
     *
     * @return Token[] Tokens belonging to the specified sentence
     *
     * @psalm-return list<Token>
     */
    public function getTokensForSentence(int $sentenceIndex): array
    {
        return array_values(
            array_filter(
                $this->tokens,
                fn(Token $token) => $token->getSentenceIndex() === $sentenceIndex
            )
        );
    }

    /**
     * Get only word tokens (excluding punctuation/whitespace).
     *
     * @return Token[] Array of word tokens only
     *
     * @psalm-return list<Token>
     */
    public function getWords(): array
    {
        return array_values(
            array_filter(
                $this->tokens,
                fn(Token $token) => $token->isWord()
            )
        );
    }

    /**
     * Get count of sentences.
     *
     * @return int Number of sentences
     */
    public function getSentenceCount(): int
    {
        return count($this->sentences);
    }

    /**
     * Get count of all tokens.
     *
     * @return int Number of tokens
     */
    public function getTokenCount(): int
    {
        return count($this->tokens);
    }

    /**
     * Get count of word tokens only.
     *
     * @return int Number of words
     */
    public function getWordCount(): int
    {
        return count($this->getWords());
    }

    /**
     * Check if the result is empty (no sentences parsed).
     *
     * @return bool True if no sentences were parsed
     */
    public function isEmpty(): bool
    {
        return empty($this->sentences);
    }

    /**
     * Create an empty result.
     *
     * @return self Empty parser result
     */
    public static function empty(): self
    {
        return new self([], []);
    }
}
