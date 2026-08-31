<?php

/**
 * Parser Configuration Value Object
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

use Lwt\Modules\Language\Domain\Language;

/**
 * Configuration passed to parsers from language settings.
 *
 * This value object encapsulates all the language-specific settings
 * that affect how text is parsed into words and sentences.
 *
 * @since 3.0.0
 */
class ParserConfig
{
    /**
     * Create a new parser configuration.
     *
     * @param int    $languageId                Language ID
     * @param string $regexpSplitSentences      Regex pattern for sentence boundaries
     * @param string $exceptionsSplitSentences  Exceptions to sentence splitting
     * @param string $regexpWordCharacters      Regex pattern defining word characters
     * @param string $characterSubstitutions    Character replacement rules (pipe-separated)
     * @param bool   $removeSpaces              Whether to remove spaces (CJK languages)
     * @param bool   $splitEachChar             Whether to split each character (CJK languages)
     * @param bool   $rightToLeft               Whether text is right-to-left
     * @param array<string, mixed>  $parserOptions  Additional parser-specific options
     */
    public function __construct(
        private int $languageId,
        private string $regexpSplitSentences,
        private string $exceptionsSplitSentences,
        private string $regexpWordCharacters,
        private string $characterSubstitutions,
        private bool $removeSpaces,
        private bool $splitEachChar,
        private bool $rightToLeft,
        /** @var array<string, mixed> */
        private array $parserOptions = []
    ) {
    }

    /**
     * Get the language ID.
     *
     * @return int Language ID
     */
    public function getLanguageId(): int
    {
        return $this->languageId;
    }

    /**
     * Get the sentence split regex pattern.
     *
     * Characters in this pattern mark sentence boundaries.
     * Example: ".!?" for English.
     *
     * @return string Sentence split regex
     */
    public function getRegexpSplitSentences(): string
    {
        return $this->regexpSplitSentences;
    }

    /**
     * Get exceptions to sentence splitting.
     *
     * Patterns that should not trigger a sentence split even if they
     * contain sentence-ending characters. Example: "Mr.|Dr.|etc."
     *
     * @return string Exception patterns
     */
    public function getExceptionsSplitSentences(): string
    {
        return $this->exceptionsSplitSentences;
    }

    /**
     * Get the word character regex pattern.
     *
     * Defines which characters can form words. Everything else is
     * considered a non-word (punctuation, whitespace).
     * Example: "a-zA-Z0-9" for basic English.
     *
     * @return string Word character regex
     */
    public function getRegexpWordCharacters(): string
    {
        return $this->regexpWordCharacters;
    }

    /**
     * Get character substitution rules.
     *
     * Pipe-separated list of "from=to" replacements applied before parsing.
     * Example: "ß=ss|ä=ae"
     *
     * @return string Character substitution rules
     */
    public function getCharacterSubstitutions(): string
    {
        return $this->characterSubstitutions;
    }

    /**
     * Check if spaces should be removed before parsing.
     *
     * Used for CJK languages where spaces are not word boundaries.
     *
     * @return bool True if spaces should be removed
     */
    public function shouldRemoveSpaces(): bool
    {
        return $this->removeSpaces;
    }

    /**
     * Check if each character should be treated as a separate word.
     *
     * Used for Chinese and similar languages without word boundaries.
     *
     * @return bool True if character-by-character splitting is enabled
     */
    public function shouldSplitEachChar(): bool
    {
        return $this->splitEachChar;
    }

    /**
     * Check if text is right-to-left.
     *
     * @return bool True for RTL languages like Arabic, Hebrew
     */
    public function isRightToLeft(): bool
    {
        return $this->rightToLeft;
    }

    /**
     * Get parser-specific options.
     *
     * @return array<string, mixed> Parser-specific options
     */
    public function getParserOptions(): array
    {
        return $this->parserOptions;
    }

    /**
     * Get a specific parser option.
     *
     * @param string $key     Option key
     * @param mixed  $default Default value if option not set
     *
     * @return mixed Option value or default
     */
    public function getParserOption(string $key, mixed $default = null): mixed
    {
        return $this->parserOptions[$key] ?? $default;
    }

    /**
     * Create configuration from a Language entity.
     *
     * @param Language $language Language entity
     *
     * @return self Parser configuration
     */
    public static function fromLanguage(Language $language): self
    {
        return new self(
            $language->id()->toInt(),
            $language->regexpSplitSentences(),
            $language->exceptionsSplitSentences(),
            $language->regexpWordCharacters(),
            $language->characterSubstitutions(),
            $language->removeSpaces(),
            $language->splitEachChar(),
            $language->rightToLeft()
        );
    }

    /**
     * Create configuration from database row array.
     *
     * @param array $row Database row with Lg* prefixed columns
     *
     * @return self Parser configuration
     */
    public static function fromDatabaseRow(array $row): self
    {
        return new self(
            (int) ($row['LgID'] ?? 0),
            (string) ($row['LgRegexpSplitSentences'] ?? ''),
            (string) ($row['LgExceptionsSplitSentences'] ?? ''),
            (string) ($row['LgRegexpWordCharacters'] ?? ''),
            (string) ($row['LgCharacterSubstitutions'] ?? ''),
            (bool) ($row['LgRemoveSpaces'] ?? false),
            (bool) ($row['LgSplitEachChar'] ?? false),
            (bool) ($row['LgRightToLeft'] ?? false)
        );
    }
}
