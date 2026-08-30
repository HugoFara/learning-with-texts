<?php

/**
 * Parser Interface
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
 * Interface for text parsers that tokenize text into words and sentences.
 *
 * Implementations of this interface provide language-specific parsing strategies.
 * Each parser handles text tokenization differently based on the language's
 * characteristics (e.g., space-separated words, character-based, morphological).
 *
 * @since 3.0.0
 */
interface ParserInterface
{
    /**
     * Get unique identifier for this parser type.
     *
     * Used for registration in the ParserRegistry and selection by Language entities.
     * Examples: 'regex', 'character', 'mecab'
     *
     * @return string Parser type identifier
     */
    public function getType(): string;

    /**
     * Get human-readable name for UI display.
     *
     * This name is shown to users when selecting a parser for a language.
     * Examples: 'Standard (Regex)', 'Character-by-Character', 'MeCab (Japanese)'
     *
     * @return string Human-readable parser name
     */
    public function getName(): string;

    /**
     * Check if this parser is available on the current system.
     *
     * Some parsers may depend on external tools (e.g., MeCab binary).
     * This method checks if all dependencies are satisfied.
     *
     * @return bool True if parser can be used, false otherwise
     */
    public function isAvailable(): bool;

    /**
     * Get a description of why this parser might not be available.
     *
     * Called when isAvailable() returns false to provide helpful feedback.
     *
     * @return string Description of missing dependencies or empty if available
     */
    public function getAvailabilityMessage(): string;

    /**
     * Parse text into a structured result with sentences and tokens.
     *
     * @param string       $text   Text to parse (already preprocessed)
     * @param ParserConfig $config Parser configuration from language settings
     *
     * @return ParserResult Parsing result containing sentences and tokens
     */
    public function parse(string $text, ParserConfig $config): ParserResult;
}
