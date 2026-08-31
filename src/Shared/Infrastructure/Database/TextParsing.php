<?php

/**
 * \file
 * \brief Text parsing and processing utilities (facade).
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

use Lwt\Modules\Language\Domain\WordSpacing;
use Lwt\Modules\Language\Domain\Parser\ParserConfig;
use Lwt\Modules\Language\Infrastructure\Parser\ParserRegistry;
use Lwt\Shared\Infrastructure\Exception\DatabaseException;

/**
 * Text parsing and processing utilities (facade).
 *
 * Delegates tokenization to JapaneseTextParser / StandardTextParser and
 * persistence to TokenPersistence. Parsing happens entirely in PHP — there are
 * no scratch tables involved.
 *
 * @since 3.0.0
 */
class TextParsing
{
    /**
     * Split text into sentences without database operations.
     *
     * @param string $text Text to parse
     * @param int    $lid  Language ID
     *
     * @return string[] Array of sentences
     *
     * @psalm-return non-empty-list<string>
     */
    public static function splitIntoSentences(string $text, int $lid): array
    {
        $pre = self::preprocess($text, $lid);
        if ($pre === null) {
            return [''];
        }
        [$ptext, $isMecab] = $pre;
        if ($isMecab) {
            return JapaneseTextParser::splitJapaneseSentences($ptext);
        }
        return StandardTextParser::splitSentences($ptext, $lid);
    }

    /**
     * Parse text and save to database.
     *
     * @param string $text   Text to parse
     * @param int    $lid    Language ID
     * @param int    $textId Text ID (must be positive)
     *
     * @return void
     *
     * @throws \InvalidArgumentException If textId is not positive
     */
    public static function parseAndSave(string $text, int $lid, int $textId): void
    {
        if ($textId <= 0) {
            throw new \InvalidArgumentException(
                "Text ID must be positive, got: $textId"
            );
        }

        $record = QueryBuilder::table('languages')
            ->select(['LgID'])
            ->where('LgID', '=', $lid)
            ->firstPrepared();

        if ($record === null) {
            throw DatabaseException::recordNotFound('languages', 'LgID', $lid);
        }

        $tokens = self::tokenize($text, $lid);
        TokenPersistence::save($tokens, $lid, $textId);
    }

    /**
     * Build the full "check a text" report without saving anything.
     *
     * The data behind the report the check page used to receive as
     * server-rendered HTML, so the page can render it from the API instead
     * (#262, #266). Returns the same tokenization the import would perform,
     * so what the report shows is what a save would store.
     *
     * @param string $text Text to parse
     * @param int    $lid  Language ID
     *
     * @return array{preview: string, sentences: list<string>,
     *         words: list<array{0: string, 1: int, 2: string}>,
     *         nonWords: list<array{0: string, 1: int}>,
     *         multiWords: list<array{0: string, 1: int, 2: string}>,
     *         rtlScript: bool, warning: string}
     *
     * @throws DatabaseException If the language does not exist
     */
    public static function checkTextReport(string $text, int $lid): array
    {
        $record = QueryBuilder::table('languages')
            ->select(['LgRightToLeft'])
            ->where('LgID', '=', $lid)
            ->firstPrepared();

        if ($record === null) {
            throw DatabaseException::recordNotFound('languages', 'LgID', $lid);
        }
        $rtlScript = (bool) $record['LgRightToLeft'];

        $pre = self::preprocess($text, $lid);
        if ($pre === null) {
            throw DatabaseException::recordNotFound('languages', 'LgID', $lid);
        }
        [$ptext, $isMecab, $langRecord] = $pre;

        // The preview shows the text as the parser sees it, before word
        // splitting — the same point the old echoPreview() printed from.
        $tokens = self::tokenizeWithOptedInParser($ptext, $langRecord);
        if ($tokens === null && $isMecab) {
            $preview = JapaneseTextParser::previewText($ptext);
            $tokens = JapaneseTextParser::tokenize($ptext);
        } else {
            $preview = StandardTextParser::previewText($ptext, $lid) ?? $ptext;
            $tokens ??= StandardTextParser::tokenize($ptext, $lid);
        }

        return ['preview' => $preview] + TokenPersistence::report($tokens, $lid, $rtlScript);
    }

    /**
     * Check/preview text and return parsing statistics without saving.
     *
     * Does not output any HTML or save to database.
     *
     * @param string $text Text to parse
     * @param int    $lid  Language ID
     *
     * @return array{sentences: int, words: int, unknownPercent: float, preview: string,
     *         warning: string} `warning` is a ParseCoverage verdict, 'ok' when fine
     */
    public static function checkText(string $text, int $lid): array
    {
        $tokens = self::tokenize($text, $lid);
        return TokenPersistence::stats($tokens, $lid);
    }

    /**
     * Tokenize a text into ParsedToken objects (no database writes).
     *
     * @param string $text Text to parse
     * @param int    $lid  Language ID
     *
     * @return ParsedToken[]
     */
    private static function tokenize(string $text, int $lid): array
    {
        $pre = self::preprocess($text, $lid);
        if ($pre === null) {
            return [];
        }
        [$ptext, $isMecab, $record] = $pre;

        $opted = self::tokenizeWithOptedInParser($ptext, $record);
        if ($opted !== null) {
            return $opted;
        }

        return $isMecab
            ? JapaneseTextParser::tokenize($ptext)
            : StandardTextParser::tokenize($ptext, $lid);
    }

    /**
     * Tokenize with the parser the language explicitly selected, if any.
     *
     * The parser registry has been able to describe jieba, MeCab and any
     * configured external tokenizer for some time, but nothing consulted it:
     * `LgParserType` was written by the language form and read by no one, so
     * choosing "Jieba (Chinese)" changed nothing about how a text was parsed.
     * This is where that setting takes effect.
     *
     * Only a deliberate choice routes here. A language with no parser type —
     * which is every language created before the field meant anything — stays
     * on the built-in pipeline, as does one that asked for a parser the server
     * cannot run. Nothing changes for an install that has not opted in.
     *
     * @param string               $ptext  Preprocessed text (substitutions applied)
     * @param array<string, mixed> $record The language row
     *
     * @return ParsedToken[]|null Tokens, or null to use the built-in pipeline
     */
    private static function tokenizeWithOptedInParser(string $ptext, array $record): ?array
    {
        // Cheap guard before building a registry, which reads the external
        // parser config: almost every language names no parser at all.
        if (trim((string) ($record['LgParserType'] ?? '')) === '') {
            return null;
        }

        $parser = (new ParserRegistry())->getOptedInParserFromRow($record);
        if ($parser === null) {
            return null;
        }

        $config = ParserConfig::fromDatabaseRow($record);

        try {
            $result = $parser->parse($ptext, $config);
        } catch (\RuntimeException $e) {
            // A tokenizer that dies mid-import must not take the text with it
            error_log('LWT: parser "' . $parser->getType() . '" failed: ' . $e->getMessage());
            return null;
        }

        return self::adaptTokens($result->getTokens());
    }

    /**
     * Convert parser-module tokens into the shape the persistence layer takes.
     *
     * The two differ only in bookkeeping: the module numbers sentences from
     * zero and restarts the token order inside each one, while the persistence
     * layer numbers sentences from one and wants a single running order across
     * the whole text.
     *
     * @param array<int, \Lwt\Modules\Language\Domain\Parser\Token> $tokens Parser tokens
     *
     * @return ParsedToken[]
     */
    private static function adaptTokens(array $tokens): array
    {
        $adapted = [];
        $order = 0;
        foreach ($tokens as $token) {
            $text = $token->getText();
            if ($text === '') {
                continue;
            }
            $order++;
            $adapted[] = new ParsedToken(
                $token->getSentenceIndex() + 1,
                $order,
                $token->isWord() ? 1 : 0,
                $text
            );
        }
        return $adapted;
    }

    /**
     * Apply the language's text preprocessing (escaping + character
     * substitutions) and report whether it uses the MeCab parser.
     *
     * @param string $text Raw text
     * @param int    $lid  Language ID
     *
     * @return array{0: string, 1: bool, 2: array<string, mixed>}|null
     *         [preprocessed text, isMecab, language row] or null if missing
     */
    private static function preprocess(string $text, int $lid): ?array
    {
        $record = QueryBuilder::table('languages')
            ->where('LgID', '=', $lid)
            ->firstPrepared();

        if ($record === null) {
            return null;
        }

        $termchar = (string)$record['LgRegexpWordCharacters'];
        $replace = explode("|", (string) $record['LgCharacterSubstitutions']);
        $text = Escaping::prepareTextdata($text);

        // because of sentence special characters
        $text = str_replace(array('}', '{'), array(']', '['), $text);
        foreach ($replace as $value) {
            $fromto = explode("=", trim($value));
            if (count($fromto) >= 2) {
                $text = str_replace(trim($fromto[0]), trim($fromto[1]), $text);
            }
        }

        return [$text, WordSpacing::usesMecabMagicWord($termchar), $record];
    }
}
