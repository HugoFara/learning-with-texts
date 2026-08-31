<?php

/**
 * \file
 * \brief Standard (non-Japanese) text parsing with sentence splitting.
 *
 * PHP version 8.2
 *
 * @category Database
 * @package  Lwt
 * @author   HugoFara <git@hugofara.net>
 * @license  Unlicense <http://unlicense.org/>
 * @link     https://hugofara.github.io/lwt/developer/api
 * @since    3.0.0
 */

declare(strict_types=1);

namespace Lwt\Shared\Infrastructure\Database;

use Lwt\Shared\Infrastructure\Utilities\StringUtils;
use Lwt\Modules\Language\Application\Services\TextParsingService;

/**
 * Standard text parsing with sentence splitting.
 *
 * Handles language settings retrieval, text transformations,
 * splitting, previewing, and database insertion for non-Japanese text.
 *
 * @since 3.0.0
 */
class StandardTextParser
{
    /**
     * Build the Unicode quotation-mark character class fragment used in regex patterns.
     *
     * Contains: RIGHT DOUBLE QUOTE, close-paren, LEFT/RIGHT SINGLE QUOTE,
     * single angle quotes, LEFT DOUBLE QUOTE, DOUBLE LOW-9 QUOTE,
     * guillemets, CJK brackets.
     *
     * @return string Character class content (without surrounding brackets)
     */
    private static function quoteChars(): string
    {
        return "\u{201D})\u{2018}\u{2019}\u{2039}\u{203A}\u{201C}\u{201E}\u{00AB}\u{00BB}\u{300F}\u{300D}";
    }

    /**
     * Get language settings for parsing.
     *
     * @param int $lid Language ID
     *
     * @return array{
     *     removeSpaces: string,
     *     splitSentence: string,
     *     noSentenceEnd: string,
     *     termchar: string,
     *     rtlScript: mixed,
     *     splitEachChar: bool
     * }|null Language settings or null if not found
     */
    public static function getLanguageSettings(int $lid): ?array
    {
        $record = QueryBuilder::table('languages')
            ->where('LgID', '=', $lid)
            ->firstPrepared();

        if ($record === null) {
            return null;
        }

        $removeSpaces = (int)$record['LgRemoveSpaces'] === 1;

        // A language reaches this parser only when no tokenizer ran: MeCab went
        // to JapaneseTextParser, and an opted-in parser either handled the text
        // or was unavailable. So if the language also writes without spaces,
        // there is nothing left to find word boundaries with — a regex has no
        // gaps to match on, and matches the longest run of word characters it
        // can, which is the whole sentence. That is what #278 reports: a Chinese
        // text where every sentence is one unclickable "word".
        //
        // Splitting each character is what the presets already do — every
        // language declaring removeSpaces sets makeCharacterWord too, and names
        // a tokenizer — and it is what getOptedInParserFromRow() says it falls
        // back on. This makes that hold for a language that lost the flag, or
        // never had it because it was configured by hand.
        $splitEachChar = (int)$record['LgSplitEachChar'] === 1 || $removeSpaces;

        return [
            'removeSpaces' => (string)$record['LgRemoveSpaces'],
            'splitSentence' => (string)$record['LgRegexpSplitSentences'],
            'noSentenceEnd' => (string)$record['LgExceptionsSplitSentences'],
            'termchar' => (string)$record['LgRegexpWordCharacters'],
            'rtlScript' => $record['LgRightToLeft'],
            'splitEachChar' => $splitEachChar,
        ];
    }

    /**
     * Apply initial text transformations (before display preview).
     *
     * @param string $text          Raw text
     * @param bool   $splitEachChar Whether to split each character
     *
     * @return string Text after initial transformations
     */
    public static function applyInitialTransformations(
        string $text,
        bool $splitEachChar
    ): string {
        // Split text paragraphs using " ¶" symbol
        $text = str_replace("\n", " \xC2\xB6", $text);
        $text = trim($text);
        if ($splitEachChar) {
            $text = preg_replace('/([^\s])/u', "$1\t", $text) ?? $text;
        }
        $text = preg_replace('/\s+/u', ' ', $text) ?? $text;
        return $text;
    }

    /**
     * Apply word-splitting transformations (after display preview).
     *
     * @param string $text          Text after initial transformations
     * @param string $splitSentence Sentence split regex
     * @param string $noSentenceEnd Exception patterns
     * @param string $termchar      Word character regex
     *
     * @return string Preprocessed text ready for parsing
     *
     * @psalm-suppress InvalidReturnType, InvalidReturnStatement, PossiblyNullArgument
     */
    public static function applyWordSplitting(
        string $text,
        string $splitSentence,
        string $noSentenceEnd,
        string $termchar
    ): string {
        $qc = self::quoteChars();
        // "\r" => Sentence delimiter, "\t" and "\n" => Word delimiter
        $service = new TextParsingService();
        /** @psalm-suppress TooFewArguments, MissingClosureReturnType, MissingClosureParamType, MixedArgument */
        $text = preg_replace_callback(
            "/(\S+)\s*((\.+)|([$splitSentence]))([]'`\"$qc]*)(?=(\s*)(\S+|$))/u",
            fn ($matches) => $service->findLatinSentenceEnd($matches, $noSentenceEnd),
            $text
        ) ?? $text;
        // Paragraph delimiters become a combination of ¶ and carriage return \r
        $text = str_replace(
            array("\xC2\xB6", " \xC2\xB6"),
            array("\xC2\xB6\r", "\r\xC2\xB6"),
            $text
        );
        $text = preg_replace(
            array(
                '/([^' . $termchar . '])/u',
                '/\n([' . $splitSentence . "]['`\"$qc]*)\n\t/u",
                '/([0-9])[\n]([:.,])[\n]([0-9])/u'
            ),
            array("\n$1\n", "$1", "$1$2$3"),
            $text
        ) ?? $text;

        return $text;
    }

    /**
     * Split standard text into sentences (split-only mode).
     *
     * @param string $text         Preprocessed text
     * @param string $removeSpaces Space removal setting
     *
     * @return string[] Array of sentences
     *
     * @psalm-return non-empty-list<string>
     */
    public static function splitStandardSentences(string $text, string $removeSpaces): array
    {
        $text = StringUtils::removeSpaces(
            str_replace(
                array("\r\r", "\t", "\n"),
                array("\r", "", ""),
                $text
            ),
            $removeSpaces
        );
        return explode("\r", $text);
    }

    /**
     * Build the tab-delimited token blob from preprocessed text.
     *
     * Produces one line per token as "<wordcount>\t<term>", where a term
     * ending in "\r" marks the end of a sentence. This is the same
     * serialization the old LOAD DATA path consumed; parseBlob() turns it
     * into ParsedToken objects.
     *
     * @param string $text         Text after word-splitting transformations
     * @param string $termchar     Word character regex
     * @param string $removeSpaces Space removal setting
     *
     * @return string
     */
    private static function buildTokenBlob(string $text, string $termchar, string $removeSpaces): string
    {
        $qc = self::quoteChars();
        $replaced = preg_replace(
            array(
                "/\r(?=[]'`\"$qc ]*\r)/u",
                '/[\n]+\r/u',
                '/\r([^\n])/u',
                "/\n[.](?![]'`\"$qc]*\r)/u",
                "/(\n|^)(?=.?[$termchar][^\n]*(\n|$))/u"
            ),
            array(
                "",
                "\r",
                "\r\n$1",
                ".\n",
                "\n1\t"
            ),
            str_replace(array("\t", "\n\n"), array("\n", ""), $text)
        );
        $text = trim($replaced ?? $text);
        return StringUtils::removeSpaces(
            preg_replace("/(\n|^)(?!1\t)/u", "\n0\t", $text) ?? $text,
            $removeSpaces
        );
    }

    /**
     * Turn the token blob into ParsedToken objects.
     *
     * Replicates the semantics of the former LOAD DATA `SET` clause: each line
     * is "<wordcount>\t<term>"; a term ending with "\r" ends the current
     * sentence (that token still belongs to the current sentence, the next one
     * starts a new sentence). Order is a global 1-based counter.
     *
     * Unlike the old saveWithSqlFallback(), this does NOT trim the line, so the
     * "\r" sentence markers and trailing-space tokens are preserved (that bug
     * caused LOAD-DATA-less installs to parse every text as one sentence).
     *
     * @param string $blob Token blob from buildTokenBlob()
     *
     * @return ParsedToken[]
     */
    private static function parseBlob(string $blob): array
    {
        $tokens = [];
        $sentence = 1;
        $order = 0;
        foreach (explode("\n", $blob) as $line) {
            $tab = strpos($line, "\t");
            if ($tab === false) {
                // Blank or malformed line (e.g. a stray empty line): no token.
                continue;
            }
            $wordCount = (int) substr($line, 0, $tab);
            $term = substr($line, $tab + 1);
            $sentenceEnd = str_ends_with($term, "\r");
            if ($sentenceEnd) {
                $term = str_replace("\r", '', $term);
            }
            $order++;
            $tokens[] = new ParsedToken($sentence, $order, $wordCount, $term);
            if ($sentenceEnd) {
                $sentence++;
            }
        }
        return $tokens;
    }

    /**
     * Tokenize a (character-substituted) standard text into ParsedToken objects.
     *
     * @param string $text Preprocessed text (character substitutions applied)
     * @param int    $lid  Language ID
     *
     * @return ParsedToken[]
     */
    public static function tokenize(string $text, int $lid): array
    {
        $settings = self::getLanguageSettings($lid);
        if ($settings === null) {
            return [];
        }
        $text = self::applyInitialTransformations($text, $settings['splitEachChar']);
        $text = self::applyWordSplitting(
            $text,
            $settings['splitSentence'],
            $settings['noSentenceEnd'],
            $settings['termchar']
        );
        $blob = self::buildTokenBlob($text, $settings['termchar'], $settings['removeSpaces']);
        return self::parseBlob($blob);
    }

    /**
     * Split a (character-substituted) standard text into sentences only.
     *
     * @param string $text Preprocessed text (character substitutions applied)
     * @param int    $lid  Language ID
     *
     * @return string[]
     *
     * @psalm-return non-empty-list<string>
     */
    public static function splitSentences(string $text, int $lid): array
    {
        $settings = self::getLanguageSettings($lid);
        if ($settings === null) {
            return [''];
        }
        $text = self::applyInitialTransformations($text, $settings['splitEachChar']);
        $text = self::applyWordSplitting(
            $text,
            $settings['splitSentence'],
            $settings['noSentenceEnd'],
            $settings['termchar']
        );
        return self::splitStandardSentences($text, $settings['removeSpaces']);
    }

    /**
     * The text as the preview shows it, before word splitting.
     *
     * Paragraph breaks are still carried by the "\xC2\xB6" marker, which the
     * caller renders.
     *
     * @param string $text Preprocessed text (substitutions applied)
     * @param int    $lid  Language ID
     *
     * @return string|null Preview text, or null if the language is unknown
     */
    public static function previewText(string $text, int $lid): ?string
    {
        $settings = self::getLanguageSettings($lid);
        if ($settings === null) {
            return null;
        }

        return self::applyInitialTransformations($text, $settings['splitEachChar']);
    }
}
