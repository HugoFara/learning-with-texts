<?php

/**
 * Regex Parser - Standard regex-based text parser.
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
 * Standard regex-based parser for most languages.
 *
 * Uses regular expressions to identify word boundaries and sentence endings.
 * Suitable for space-separated languages like English, French, German, etc.
 *
 * @since 3.0.0
 */
class RegexParser implements ParserInterface
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
        return 'regex';
    }

    /**
     * {@inheritdoc}
     */
    public function getName(): string
    {
        return 'Standard (Regex)';
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
        // Step 1: Apply initial transformations
        $text = $this->applyInitialTransformations($text);

        // Step 2: Apply word-splitting transformations
        $text = $this->applyWordSplitting(
            $text,
            $config->getRegexpSplitSentences(),
            $config->getExceptionsSplitSentences(),
            $config->getRegexpWordCharacters()
        );

        // Step 3: Parse into sentences and tokens
        return $this->parseToResult($text, $config->shouldRemoveSpaces());
    }

    /**
     * Apply initial text transformations.
     *
     * Normalizes text by marking paragraphs and collapsing whitespace.
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
        // Collapse multiple spaces
        $text = preg_replace('/\s+/u', ' ', $text) ?? $text;
        return $text;
    }

    /**
     * Apply word-splitting transformations.
     *
     * Uses regex patterns to identify word and sentence boundaries.
     *
     * @param string $text          Text after initial transformations
     * @param string $splitSentence Sentence split regex
     * @param string $noSentenceEnd Exception patterns
     * @param string $termchar      Word character regex
     *
     * @return string Preprocessed text with \r for sentence breaks and \n for token breaks
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

        // Paragraph delimiters become a combination of ¶ and carriage return \r
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
     * @param string $text         Preprocessed text with \r and \n markers
     * @param bool   $removeSpaces Whether to remove spaces
     *
     * @return ParserResult Result with sentences and tokens
     */
    protected function parseToResult(string $text, bool $removeSpaces): ParserResult
    {
        // Clean up the text similar to parseStandardToDatabase
        // Unicode quotation marks as hex escapes for Psalm compatibility
        $quoteChars = "\xe2\x80\x9c\xe2\x80\x9d\xe2\x80\x98\xe2\x80\x99" .
            "\xe2\x80\xb9\xe2\x80\xba\xe2\x80\x9e\xc2\xab\xc2\xbb" .
            "\xe3\x80\x8f\xe3\x80\x8d";
        $preprocessed = preg_replace(
            array(
                "/\r(?=[]'`\"" . $quoteChars . " ]*\r)/u",
                '/[\n]+\r/u',
                '/\r([^\n])/u',
                "/\n[.](?![]'`\"" . $quoteChars . "]*\r)/u",
                "/(\n|^)(?=.?[a-zA-Z0-9][^\n]*(\n|$))/u"
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

            // Create token
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
