<?php

/**
 * Parser Registry
 *
 * PHP version 8.2
 *
 * @category Parser
 * @package  Lwt\Modules\Language\Infrastructure\Parser
 * @author   HugoFara <git@hugofara.net>
 * @license  Unlicense <http://unlicense.org/>
 * @link     https://hugofara.github.io/lwt/developer/api
 * @since    3.0.0
 */

declare(strict_types=1);

namespace Lwt\Modules\Language\Infrastructure\Parser;

use Lwt\Modules\Language\Domain\WordSpacing;
use Lwt\Modules\Language\Domain\Language;
use Lwt\Modules\Language\Domain\Parser\ParserInterface;

/**
 * Registry for parser implementations.
 *
 * Handles parser discovery, registration, and instantiation.
 * Provides methods to select the appropriate parser for a language.
 *
 * @since 3.0.0
 */
class ParserRegistry
{
    /** @var array<string, ParserInterface> Registered parsers by type */
    private array $parsers = [];

    /** @var string Default parser type when none specified */
    private const DEFAULT_PARSER = 'regex';

    /** @var ExternalParserLoader|null Loader for external parser configs */
    private ?ExternalParserLoader $externalLoader;

    /**
     * Create a new parser registry with default parsers.
     *
     * Builds its own loader when none is given, so that a registry constructed
     * directly still sees the parsers in config/parsers.php. It did not before,
     * which is why jieba never reached the language form's parser list even on
     * an install where it was installed and working.
     *
     * @param ExternalParserLoader|null $externalLoader Loader for external parsers
     */
    public function __construct(?ExternalParserLoader $externalLoader = null)
    {
        $this->externalLoader = $externalLoader ?? new ExternalParserLoader();
        $this->registerDefaultParsers();
        $this->registerExternalParsers();
    }

    /**
     * Register the default built-in parsers.
     *
     * @return void
     */
    private function registerDefaultParsers(): void
    {
        $this->register(new RegexParser());
        $this->register(new CharacterParser());
        $this->register(new MecabParser());
    }

    /**
     * Register external parsers from the configuration file.
     *
     * External parsers are loaded from config/parsers.php. This method
     * creates ExternalParser instances for each configured parser.
     *
     * @return void
     */
    private function registerExternalParsers(): void
    {
        if ($this->externalLoader === null) {
            return;
        }

        foreach ($this->externalLoader->getExternalParsers() as $config) {
            // Skip if a parser with this type is already registered
            // (built-in parsers take precedence)
            if ($this->has($config->getType())) {
                continue;
            }

            $this->register(new ExternalParser($config));
        }
    }

    /**
     * Register a parser.
     *
     * @param ParserInterface $parser Parser to register
     *
     * @return void
     */
    public function register(ParserInterface $parser): void
    {
        $this->parsers[$parser->getType()] = $parser;
    }

    /**
     * Get a parser by type.
     *
     * @param string $type Parser type identifier
     *
     * @return ParserInterface|null Parser instance or null if not found
     */
    public function get(string $type): ?ParserInterface
    {
        return $this->parsers[$type] ?? null;
    }

    /**
     * Check if a parser type is registered.
     *
     * @param string $type Parser type identifier
     *
     * @return bool True if parser is registered
     */
    public function has(string $type): bool
    {
        return isset($this->parsers[$type]);
    }

    /**
     * Get all registered parsers.
     *
     * @return array<string, ParserInterface> All parsers indexed by type
     */
    public function getAll(): array
    {
        return $this->parsers;
    }

    /**
     * Get all available parsers (those that can run on this system).
     *
     * @return array<string, ParserInterface> Available parsers indexed by type
     */
    public function getAvailable(): array
    {
        return array_filter(
            $this->parsers,
            fn(ParserInterface $parser) => $parser->isAvailable()
        );
    }

    /**
     * Get parser information for UI display.
     *
     * @return array<string, array{type: string, name: string, available: bool, message: string}>
     */
    public function getParserInfo(): array
    {
        $info = [];
        foreach ($this->parsers as $type => $parser) {
            $info[$type] = [
                'type' => $parser->getType(),
                'name' => $parser->getName(),
                'available' => $parser->isAvailable(),
                'message' => $parser->getAvailabilityMessage(),
            ];
        }
        return $info;
    }

    /**
     * Get the default parser type.
     *
     * @return string Default parser type
     */
    public function getDefaultType(): string
    {
        return self::DEFAULT_PARSER;
    }

    /**
     * The parser a language deliberately asked for, if any.
     *
     * Only an explicit, non-default `LgParserType` counts. The legacy signals
     * resolveParserTypeFromRow() also understands — the MECAB magic word and
     * the split-each-character flag — are deliberately ignored here: the
     * pipeline has always handled those itself, so returning null for them
     * keeps their parsing byte-identical.
     *
     * A stored parser type is not by itself evidence of a choice, which is the
     * subtle half. 20251223_120000_add_parser_type.sql *backfilled* the column
     * from those same legacy signals — 'mecab' for the magic word, 'character'
     * for LgSplitEachChar — so on every upgraded install the CJK languages
     * already carry a type nobody picked. Routing those to the registry
     * retokenizes them: measured on a real database, a character-split Chinese
     * text goes from 103 words to 122 and Japanese from 46 to 60, which would
     * silently desynchronise saved terms from new text occurrences. A value
     * that merely restates the legacy signal beside it is therefore read as the
     * legacy signal, not as intent.
     *
     * Deriving intent this way is a workaround for the magic word overloading
     * LgRegexpWordCharacters; it goes away once that is retired and the column
     * means only what a user chose.
     *
     * @param array<string, mixed> $row Database row with Lg* prefixed columns
     *
     * @return ParserInterface|null The chosen parser, or null to leave the
     *         language on the built-in pipeline
     */
    public function getOptedInParserFromRow(array $row): ?ParserInterface
    {
        $type = trim((string) ($row['LgParserType'] ?? ''));
        if ($type === '' || $type === self::DEFAULT_PARSER) {
            return null;
        }

        if (self::restatesALegacySignal($type, $row)) {
            return null;
        }

        $parser = $this->get($type);
        if ($parser === null || !$parser->isAvailable()) {
            // An unavailable parser must not drop the language onto the regex
            // parser: for the CJK languages that ask for jieba or mecab, that
            // yields a text with no words at all. The built-in pipeline still
            // honours their split-each-character setting, so fall back to it.
            return null;
        }

        return $parser;
    }

    /**
     * Whether the built-in pipeline already covers this parser type.
     *
     * The backfill wrote 'character' where LgSplitEachChar was set, so that
     * combination carries no more information than the flag does, and the
     * built-in pipeline already acts on the flag. Anything else — jieba, or an
     * external tokenizer, or 'character' on a language whose split flag is off
     * — could only have been chosen.
     *
     * **MeCab always belongs to the built-in pipeline.** LWT has shipped a
     * MeCab tokenizer since long before this registry existed
     * (`JapaneseTextParser`), and `MecabParser` is a second implementation of
     * the same thing: both shell out to the same binary with the same format.
     * Two implementations of one tokenizer can only drift, and they had —
     * `MecabParser` classified every word as a non-word, and split one sentence
     * more than the built-in one did off the trailing paragraph marker. Whether
     * the type was derived from the magic word or chosen from the dropdown, the
     * language wants MeCab, and there is one MeCab worth running (#288).
     *
     * @param string               $type Trimmed, non-empty LgParserType
     * @param array<string, mixed> $row  Database row with Lg* prefixed columns
     *
     * @return bool True when the built-in pipeline should handle it
     */
    private static function restatesALegacySignal(string $type, array $row): bool
    {
        if ($type === 'mecab') {
            return true;
        }

        if ($type === 'character') {
            return (int) ($row['LgSplitEachChar'] ?? 0) === 1;
        }

        return false;
    }

    /**
     * Resolve the parser type for a language.
     *
     * Determines which parser to use based on language settings.
     * Supports both explicit parser type and legacy detection.
     *
     * @param Language $language Language entity
     *
     * @return string Resolved parser type
     */
    public function resolveParserType(Language $language): string
    {
        // Check explicit parser type first (new field)
        $parserType = $language->parserType();
        if ($parserType !== null && $parserType !== '') {
            return $parserType;
        }

        // Legacy detection: check magic word in regexpWordCharacters
        if (WordSpacing::usesMecabMagicWord($language->regexpWordCharacters())) {
            return 'mecab';
        }

        // Legacy detection: check splitEachChar flag
        if ($language->splitEachChar()) {
            return 'character';
        }

        return self::DEFAULT_PARSER;
    }

    /**
     * Resolve parser type from a database row.
     *
     * @param array<string, mixed> $row Database row with Lg* prefixed columns
     *
     * @return string Resolved parser type
     */
    public function resolveParserTypeFromRow(array $row): string
    {
        // Check explicit parser type first
        $parserType = $row['LgParserType'] ?? null;
        if ($parserType !== null && $parserType !== '') {
            return (string) $parserType;
        }

        // Legacy detection: check magic word
        if (WordSpacing::usesMecabMagicWord((string) ($row['LgRegexpWordCharacters'] ?? ''))) {
            return 'mecab';
        }

        // Legacy detection: check splitEachChar flag
        if (!empty($row['LgSplitEachChar'])) {
            return 'character';
        }

        return self::DEFAULT_PARSER;
    }

    /**
     * Get a parser for a language, with fallback to default.
     *
     * @param Language $language Language entity
     *
     * @return ParserInterface Parser instance (never null)
     */
    public function getParserForLanguage(Language $language): ParserInterface
    {
        $type = $this->resolveParserType($language);
        $parser = $this->get($type);

        // Check availability and fall back if needed
        if ($parser === null || !$parser->isAvailable()) {
            $parser = $this->get(self::DEFAULT_PARSER);
        }

        // This should never happen, but ensure we always return a parser
        if ($parser === null) {
            throw new \RuntimeException('No parsers registered in ParserRegistry');
        }

        return $parser;
    }
}
