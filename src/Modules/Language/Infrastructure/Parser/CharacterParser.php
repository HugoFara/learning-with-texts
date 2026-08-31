<?php

/**
 * Character Parser - Character-by-character text parser.
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

use Lwt\Modules\Language\Domain\Parser\ParserInterface;
use Lwt\Modules\Language\Domain\Parser\ParserConfig;
use Lwt\Modules\Language\Domain\Parser\ParserResult;
use Lwt\Modules\Language\Domain\Parser\Token;
use Lwt\Shared\Infrastructure\Utilities\StringUtils;
use Lwt\Modules\Language\Application\Services\TextParsingService;

/**
 * Character-by-character parser for CJK languages.
 *
 * Each character is treated as a separate word. This is suitable for
 * Chinese and similar languages where there are no word boundaries.
 *
 * @since 3.0.0
 */
class CharacterParser implements ParserInterface
{
    private TextParsingService $parsingService;

    public function __construct(?TextParsingService $parsingService = null)
    {
        $this->parsingService = $parsingService ?? new TextParsingService();
    }

    /**
     * {@inheritdoc}
     */
    public function getType(): string
    {
        return 'character';
    }

    /**
     * {@inheritdoc}
     */
    public function getName(): string
    {
        return 'Character-by-Character';
    }

    /**
     * {@inheritdoc}
     */
    public function isAvailable(): bool
    {
        return true;
    }

    /**
     * {@inheritdoc}
     */
    public function getAvailabilityMessage(): string
    {
        return '';
    }

    /**
     * {@inheritdoc}
     */
    public function parse(string $text, ParserConfig $config): ParserResult
    {
        // Step 1: Apply initial transformations with character splitting
        $text = $this->applyInitialTransformations($text);

        // Step 2: Apply word-splitting transformations
        $text = $this->applyWordSplitting(
            $text,
            $config->getRegexpSplitSentences(),
            $config->getExceptionsSplitSentences(),
            $config->getRegexpWordCharacters()
        );

        // Step 3: Parse into sentences and tokens (always remove spaces for CJK)
        return $this->parseToResult($text, true);
    }

    /**
     * Apply initial text transformations with character splitting.
     *
     * @param string $text Raw text
     *
     * @return string Text after initial transformations
     */
    protected function applyInitialTransformations(string $text): string
    {
        // Split text paragraphs using " ¶" symbol
        $text = str_replace("\n", " ¶", $text);
        $text = trim($text);

        // Split each character with tabs (key difference from RegexParser)
        $text = preg_replace('/([^\s])/u', "$1\t", $text) ?? $text;

        // Collapse multiple spaces
        $text = preg_replace('/\s+/u', ' ', $text) ?? $text;

        return $text;
    }

    /**
     * Apply word-splitting transformations.
     *
     * @param string $text          Text after initial transformations
     * @param string $splitSentence Sentence split regex
     * @param string $noSentenceEnd Exception patterns
     * @param string $termchar      Word character regex
     *
     * @return string Preprocessed text
     */
    protected function applyWordSplitting(
        string $text,
        string $splitSentence,
        string $noSentenceEnd,
        string $termchar
    ): string {
        // "\r" => Sentence delimiter, "\t" and "\n" => Word delimiter
        $text = preg_replace_callback(
            "/(\S+)\s*((\.+)|([$splitSentence]))([]'`\"”)‘’‹›“„«»』」]*)(?=(\s*)(\S+|$))/u",
            fn ($matches) => $this->parsingService->findLatinSentenceEnd($matches, $noSentenceEnd),
            $text
        ) ?? $text;

        // Paragraph delimiters
        $text = str_replace(array("¶", " ¶"), array("¶\r", "\r¶"), $text);

        // Split on non-word characters
        $text = preg_replace(
            array(
                '/([^' . $termchar . '])/u',
                '/\n([' . $splitSentence . '][\'`"”)\]‘’‹›“„«»』」]*)\n\t/u',
                '/([0-9])[\n]([:.,])[\n]([0-9])/u'
            ),
            array("\n$1\n", "$1", "$1$2$3"),
            $text
        ) ?? $text;

        return $text;
    }

    /**
     * Parse preprocessed text into a ParserResult.
     *
     * @param string $text         Preprocessed text
     * @param bool   $removeSpaces Whether to remove spaces
     *
     * @return ParserResult Result with sentences and tokens
     */
    protected function parseToResult(string $text, bool $removeSpaces): ParserResult
    {
        // Clean up the text
        $preprocessed = preg_replace(
            array(
                "/\r(?=[]'`\"”)‘’‹›“„«»』」 ]*\r)/u",
                '/[\n]+\r/u',
                '/\r([^\n])/u',
                "/\n[.](?![]'`\"”)‘’‹›“„«»』」]*\r)/u",
                "/(\n|^)(?=.?[^\n]*(\n|$))/u"
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
        $text = trim($preprocessed ?? '');

        // Mark word vs non-word lines
        $text = preg_replace("/(\n|^)(?!1\t)/u", "\n0\t", $text) ?? $text;

        if ($removeSpaces) {
            $text = StringUtils::removeSpaces($text, '1');
        }

        // Parse into sentences and tokens
        $sentences = [];
        $tokens = [];
        $sentenceIndex = 0;
        $tokenOrder = 0;
        $currentSentenceParts = [];

        foreach (explode("\n", $text) as $line) {
            if (trim($line) === "") {
                continue;
            }

            $parts = explode("\t", $line, 2);
            if (count($parts) < 2) {
                continue;
            }

            list($wordCount, $term) = $parts;
            $isWord = ($wordCount === '1');

            // Check for sentence break
            $endsWithSentenceBreak = str_ends_with($term, "\r");
            if ($endsWithSentenceBreak) {
                $term = str_replace("\r", '', $term);
            }

            // Add to current sentence
            $currentSentenceParts[] = $term;

            // Create token (each character is a word in this parser)
            $tokens[] = new Token(
                $term,
                $sentenceIndex,
                $tokenOrder,
                $isWord,
                $isWord ? 1 : 0
            );
            $tokenOrder++;

            // End of sentence
            if ($endsWithSentenceBreak) {
                $sentences[] = implode('', $currentSentenceParts);
                $currentSentenceParts = [];
                $sentenceIndex++;
                $tokenOrder = 0;
            }
        }

        // Handle any remaining content as a sentence
        if (!empty($currentSentenceParts)) {
            $sentences[] = implode('', $currentSentenceParts);
        }

        // Ensure at least one sentence
        if (empty($sentences)) {
            $sentences = [''];
        }

        return new ParserResult($sentences, $tokens);
    }
}
