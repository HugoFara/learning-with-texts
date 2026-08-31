<?php

/**
 * MeCab Parser - Japanese morphological analyzer parser.
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
use Lwt\Modules\Language\Application\Services\TextParsingService;

/**
 * MeCab-based parser for Japanese language.
 *
 * Uses the MeCab morphological analyzer to tokenize Japanese text
 * into words. MeCab must be installed on the system.
 *
 * @since 3.0.0
 */
class MecabParser implements ParserInterface
{
    private TextParsingService $parsingService;
    private ?bool $mecabAvailable = null;
    private string $availabilityMessage = '';

    public function __construct(?TextParsingService $parsingService = null)
    {
        $this->parsingService = $parsingService ?? new TextParsingService();
    }

    /**
     * {@inheritdoc}
     */
    public function getType(): string
    {
        return 'mecab';
    }

    /**
     * {@inheritdoc}
     */
    public function getName(): string
    {
        return 'MeCab (Japanese)';
    }

    /**
     * {@inheritdoc}
     */
    public function isAvailable(): bool
    {
        if ($this->mecabAvailable === null) {
            $this->checkAvailability();
        }
        return $this->mecabAvailable ?? false;
    }

    /**
     * {@inheritdoc}
     */
    public function getAvailabilityMessage(): string
    {
        if ($this->mecabAvailable === null) {
            $this->checkAvailability();
        }
        return $this->availabilityMessage;
    }

    /**
     * Check if MeCab is available on the system.
     *
     * This method checks for MeCab directly without calling getMecabPath()
     * which would die() if MeCab is not found.
     *
     * @return void
     */
    private function checkAvailability(): void
    {
        $os = strtoupper(PHP_OS);

        if (str_starts_with($os, 'LIN') || str_starts_with($os, 'DAR')) {
            // Linux or macOS
            /** @psalm-suppress ForbiddenCode shell_exec is required to check MeCab availability */
            $result = @shell_exec("command -v mecab 2>/dev/null");
            if ($result !== null && $result !== false && trim($result) !== '') {
                $this->mecabAvailable = true;
                $this->availabilityMessage = '';
                return;
            }
        } elseif (str_starts_with($os, 'WIN')) {
            // Windows - check common MeCab installation paths
            $checks = [
                'where /R "%ProgramFiles%\\MeCab\\bin" mecab.exe 2>nul',
                'where /R "%ProgramFiles(x86)%\\MeCab\\bin" mecab.exe 2>nul',
                'where mecab.exe 2>nul'
            ];
            foreach ($checks as $check) {
                /** @psalm-suppress ForbiddenCode shell_exec is required to check MeCab availability */
                $result = @shell_exec($check);
                if ($result !== null && $result !== false && trim($result) !== '') {
                    $this->mecabAvailable = true;
                    $this->availabilityMessage = '';
                    return;
                }
            }
        }

        // MeCab not found
        $this->mecabAvailable = false;
        $this->availabilityMessage = 'MeCab is not installed or not in PATH. ' .
            'Please install MeCab to use this parser for Japanese.';
    }

    /**
     * {@inheritdoc}
     */
    public function parse(string $text, ParserConfig $config): ParserResult
    {
        // Step 1: Preprocess text
        $text = $this->preprocessText($text);

        // Step 2: Run MeCab and parse output
        $mecabOutput = $this->runMecab($text);

        // Step 3: Parse MeCab output into sentences and tokens
        return $this->parseMecabOutput($mecabOutput);
    }

    /**
     * Preprocess text before MeCab parsing.
     *
     * @param string $text Raw text
     *
     * @return string Preprocessed text
     */
    protected function preprocessText(string $text): string
    {
        $text = preg_replace('/[ \t]+/u', ' ', $text) ?? $text;
        return trim($text);
    }

    /**
     * Run MeCab on the text and return output.
     *
     * @param string $text Text to parse
     *
     * @return string MeCab output
     */
    protected function runMecab(string $text): string
    {
        $fileName = tempnam(sys_get_temp_dir(), "lwt_mecab_");
        if ($fileName === false) {
            throw new \RuntimeException('Failed to create temporary file for MeCab');
        }

        try {
            // MeCab output format: word\tnode_type\tthird_param\n
            // EOP\t3\t7 marks end of paragraph
            $mecabArgs = " -F %m\\t%t\\t%h\\n -U %m\\t%t\\t%h\\n -E EOP\\t3\\t7\\n";
            $mecabArgs .= " -o " . escapeshellarg($fileName) . " ";

            $mecab = $this->parsingService->getMecabPath($mecabArgs);

            // Run MeCab
            $handle = popen($mecab, 'w');
            if ($handle === false) {
                throw new \RuntimeException('Failed to open MeCab process');
            }
            fwrite($handle, $text);
            pclose($handle);

            // Read output
            if (!file_exists($fileName)) {
                throw new \RuntimeException('MeCab did not produce output file');
            }

            $output = file_get_contents($fileName);

            return $output !== false ? $output : '';
        } finally {
            if (file_exists($fileName)) {
                unlink($fileName);
            }
        }
    }

    /**
     * Parse MeCab output into ParserResult.
     *
     * @param string $mecabOutput MeCab output text
     *
     * @return ParserResult Parsed result
     */
    protected function parseMecabOutput(string $mecabOutput): ParserResult
    {
        $sentences = [];
        $tokens = [];
        $sentenceIndex = 0;
        $tokenOrder = 0;
        $currentSentenceParts = [];

        $termType = 0;
        $lastNodeType = 0;

        // MeCab's line terminator comes from MeCab, not from the host PHP runs
        // on, so splitting on PHP_EOL broke this everywhere PHP_EOL is not
        // "\n": on Windows the whole output stayed a single line and every
        // token collapsed into one. Normalise first, then split on "\n".
        $mecabOutput = str_replace(["\r\n", "\r"], "\n", $mecabOutput);
        $lines = explode("\n", $mecabOutput);
        $previousTokenIndex = -1;

        foreach ($lines as $line) {
            if (trim($line) === "") {
                continue;
            }

            $parts = explode("\t", $line);
            if (count($parts) < 3) {
                continue;
            }

            list($term, $nodeType, $third) = $parts;

            // Check for end of sentence/paragraph
            if ($termType == 2 || ($term === 'EOP' && $third == '7')) {
                // End of paragraph - start new sentence
                if (!empty($currentSentenceParts)) {
                    $sentences[] = implode('', $currentSentenceParts);
                    $currentSentenceParts = [];
                    $sentenceIndex++;
                    $tokenOrder = 0;
                }
            }

            // Handle EOP marker
            if ($term === 'EOP' && $third == '7') {
                $term = '¶';
                $termType = 2;
            } elseif ($third == '7') {
                $termType = 2;
            } elseif (in_array($nodeType, ['2', '6', '7', '8'])) {
                // Non-word types: punctuation, symbols, numbers
                $termType = 0;
            } else {
                // Word type
                $termType = 1;
            }

            // Special case for consecutive numbers (kazu)
            if ($lastNodeType == 8 && $nodeType == 8 && $previousTokenIndex >= 0) {
                // Concatenate with previous token
                $prevToken = $tokens[$previousTokenIndex];
                $tokens[$previousTokenIndex] = new Token(
                    $prevToken->getText() . $term,
                    $prevToken->getSentenceIndex(),
                    $prevToken->getOrder(),
                    $prevToken->isWord(),
                    $prevToken->getWordCount()
                );
                $currentSentenceParts[count($currentSentenceParts) - 1] .= $term;
                $lastNodeType = $nodeType;
                continue;
            }

            // Add to current sentence
            $currentSentenceParts[] = $term;

            // Create token.
            //
            // %t is MeCab's character type, and 2/6/7/8 — kanji, hiragana,
            // katakana and numerals — are what Japanese words are made of, so
            // those are the words and everything else is punctuation or a
            // symbol. $termType above already says this: 0 is a word, 1 is not,
            // 2 is a sentence end. This recomputed it from $nodeType with the
            // test the wrong way round, so every word came out a non-word and
            // every 。 came out a word, and a language that actually reached
            // this parser — one created from the Japanese preset, which names
            // mecab and carries a real regex — parsed into almost nothing
            // (#288). The built-in JapaneseTextParser has always read it as
            // "$term_type == 0 ? 1 : 0"; agree with it.
            $tokenIsWord = $termType === 0;
            $tokens[] = new Token(
                $term,
                $sentenceIndex,
                $tokenOrder,
                $tokenIsWord,
                $tokenIsWord ? 1 : 0
            );
            $previousTokenIndex = count($tokens) - 1;
            $tokenOrder++;

            $lastNodeType = $nodeType;
        }

        // Handle remaining content
        if (!empty($currentSentenceParts)) {
            $sentences[] = implode('', $currentSentenceParts);
        }

        // Ensure at least one sentence
        if (empty($sentences)) {
            $sentences = [''];
        }

        return new ParserResult($sentences, array_values($tokens));
    }
}
