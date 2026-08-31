<?php

/**
 * External Parser - Generic command-line tokenizer parser.
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
use Lwt\Modules\Language\Domain\Parser\ExternalParserConfig;
use Lwt\Modules\Language\Domain\Parser\ParserConfig;
use Lwt\Modules\Language\Domain\Parser\ParserInterface;
use Lwt\Modules\Language\Domain\Parser\ParserResult;
use Lwt\Modules\Language\Domain\Parser\Token;
use RuntimeException;

/**
 * Generic external parser that executes command-line tokenizers.
 *
 * This parser wraps external tokenization programs (like Jieba, Sudachi, etc.)
 * and converts their output into LWT's token format. The parser configuration
 * is loaded from config/parsers.php to ensure only allowed programs can run.
 *
 * Security: Binary paths come only from the server-side config file, not from
 * user input. All command arguments are properly escaped.
 *
 * @since 3.0.0
 */
class ExternalParser implements ParserInterface
{
    private ?bool $available = null;
    private string $availabilityMessage = '';

    /**
     * Create a new external parser.
     *
     * @param ExternalParserConfig $config Parser configuration from config file
     */
    public function __construct(
        private ExternalParserConfig $config
    ) {
    }

    /**
     * {@inheritdoc}
     */
    public function getType(): string
    {
        return $this->config->getType();
    }

    /**
     * {@inheritdoc}
     */
    public function getName(): string
    {
        return $this->config->getName();
    }

    /**
     * {@inheritdoc}
     */
    public function isAvailable(): bool
    {
        if ($this->available === null) {
            $this->checkAvailability();
        }
        return $this->available ?? false;
    }

    /**
     * {@inheritdoc}
     */
    public function getAvailabilityMessage(): string
    {
        if ($this->available === null) {
            $this->checkAvailability();
        }
        return $this->availabilityMessage;
    }

    /**
     * Check if the configured binary is available on the system.
     */
    private function checkAvailability(): void
    {
        $binary = $this->config->getBinary();
        $os = strtoupper(PHP_OS);

        // Check if binary is an absolute path
        if ($this->isAbsolutePath($binary)) {
            if (is_file($binary) && is_executable($binary)) {
                $this->available = true;
                $this->availabilityMessage = '';
                return;
            }
            $this->available = false;
            $this->availabilityMessage = sprintf(
                "Binary not found or not executable: %s",
                $binary
            );
            return;
        }

        // Check if binary is in PATH
        if (str_starts_with($os, 'WIN')) {
            $this->checkWindowsPath($binary);
        } else {
            $this->checkUnixPath($binary);
        }
    }

    /**
     * Check if a path is absolute.
     *
     * @param string $path Path to check
     *
     * @return bool True if path is absolute
     */
    private function isAbsolutePath(string $path): bool
    {
        // Unix absolute path
        if (str_starts_with($path, '/')) {
            return true;
        }

        // Windows absolute path (C:\, D:\, etc.)
        if (preg_match('/^[a-zA-Z]:[\\\\\/]/', $path)) {
            return true;
        }

        return false;
    }

    /**
     * Check if binary is available on Unix-like systems.
     *
     * @param string $binary Binary name to check
     */
    private function checkUnixPath(string $binary): void
    {
        /** @psalm-suppress ForbiddenCode Required to check binary availability */
        $result = @shell_exec(sprintf('command -v %s 2>/dev/null', escapeshellarg($binary)));

        if ($result !== null && $result !== false && trim($result) !== '') {
            $this->available = true;
            $this->availabilityMessage = '';
            return;
        }

        $this->available = false;
        $this->availabilityMessage = sprintf(
            "'%s' is not installed or not in PATH. Please install it to use this parser.",
            $binary
        );
    }

    /**
     * Check if binary is available on Windows.
     *
     * @param string $binary Binary name to check
     */
    private function checkWindowsPath(string $binary): void
    {
        /** @psalm-suppress ForbiddenCode Required to check binary availability */
        $result = @shell_exec(sprintf('where %s 2>nul', escapeshellarg($binary)));

        if ($result !== null && $result !== false && trim($result) !== '') {
            $this->available = true;
            $this->availabilityMessage = '';
            return;
        }

        $this->available = false;
        $this->availabilityMessage = sprintf(
            "'%s' is not installed or not in PATH. Please install it to use this parser.",
            $binary
        );
    }

    /**
     * {@inheritdoc}
     */
    public function parse(string $text, ParserConfig $config): ParserResult
    {
        if (!$this->isAvailable()) {
            throw new RuntimeException(sprintf(
                "Parser '%s' is not available: %s",
                $this->config->getType(),
                $this->availabilityMessage
            ));
        }

        // Preprocess text
        $text = $this->preprocessText($text);

        if (trim($text) === '') {
            return ParserResult::empty();
        }

        // Run external parser
        $output = $this->runParser($text);

        // Parse output based on format
        return $this->parseOutput($output, $config);
    }

    /**
     * Preprocess text before parsing.
     *
     * @param string $text Raw text
     *
     * @return string Preprocessed text
     */
    private function preprocessText(string $text): string
    {
        // Normalize whitespace
        $text = preg_replace('/[ \t]+/u', ' ', $text) ?? $text;
        return trim($text);
    }

    /**
     * Run the external parser and return its output.
     *
     * @param string $text Text to parse
     *
     * @return string Parser output
     *
     * @throws RuntimeException If parser execution fails
     */
    private function runParser(string $text): string
    {
        $command = $this->buildCommand();

        if ($this->config->usesStdin()) {
            return $this->runWithStdin($command, $text);
        }

        return $this->runWithFile($text);
    }

    /**
     * Build the command string.
     *
     * @return string Command to execute
     */
    private function buildCommand(): string
    {
        $parts = [escapeshellarg($this->config->getBinary())];

        foreach ($this->config->getArgs() as $arg) {
            $parts[] = escapeshellarg($arg);
        }

        return implode(' ', $parts);
    }

    /**
     * Run command with text piped to stdin.
     *
     * @param string $command Command to execute
     * @param string $text    Text to pipe
     *
     * @return string Command output
     *
     * @throws RuntimeException If execution fails
     */
    private function runWithStdin(string $command, string $text): string
    {
        $descriptorSpec = [
            0 => ['pipe', 'r'],  // stdin
            1 => ['pipe', 'w'],  // stdout
            2 => ['pipe', 'w'],  // stderr
        ];

        $process = proc_open($command, $descriptorSpec, $pipes);

        if (!is_resource($process)) {
            throw new RuntimeException(sprintf(
                "Failed to start parser process: %s",
                $this->config->getType()
            ));
        }

        // Write input to stdin
        fwrite($pipes[0], $text);
        fclose($pipes[0]);

        // Read output
        $output = stream_get_contents($pipes[1]);
        fclose($pipes[1]);

        // Read any errors
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[2]);

        $exitCode = proc_close($process);

        if ($exitCode !== 0) {
            $errorMessage = is_string($stderr) && $stderr !== '' ? $stderr : 'Unknown error';
            throw new RuntimeException(sprintf(
                "Parser '%s' failed with exit code %d: %s",
                $this->config->getType(),
                $exitCode,
                $errorMessage
            ));
        }

        return is_string($output) ? $output : '';
    }

    /**
     * Run command with text in a temporary file.
     *
     * @param string $text Text to write to file
     *
     * @return string Command output
     *
     * @throws RuntimeException If execution fails
     */
    private function runWithFile(string $text): string
    {
        $tempFile = tempnam(sys_get_temp_dir(), 'lwt_parser_');

        if ($tempFile === false) {
            throw new RuntimeException('Failed to create temporary file for parser');
        }

        try {
            file_put_contents($tempFile, $text);

            $command = $this->buildCommand() . ' ' . escapeshellarg($tempFile);

            $descriptorSpec = [
                1 => ['pipe', 'w'],  // stdout
                2 => ['pipe', 'w'],  // stderr
            ];

            $process = proc_open($command, $descriptorSpec, $pipes);

            if (!is_resource($process)) {
                throw new RuntimeException(sprintf(
                    "Failed to start parser process: %s",
                    $this->config->getType()
                ));
            }

            $output = stream_get_contents($pipes[1]);
            fclose($pipes[1]);

            $stderr = stream_get_contents($pipes[2]);
            fclose($pipes[2]);

            $exitCode = proc_close($process);

            if ($exitCode !== 0) {
                $errorMessage = is_string($stderr) && $stderr !== '' ? $stderr : 'Unknown error';
                throw new RuntimeException(sprintf(
                    "Parser '%s' failed with exit code %d: %s",
                    $this->config->getType(),
                    $exitCode,
                    $errorMessage
                ));
            }

            return is_string($output) ? $output : '';
        } finally {
            if (file_exists($tempFile)) {
                unlink($tempFile);
            }
        }
    }

    /**
     * Parse the external parser output into a ParserResult.
     *
     * @param string       $output Parser output
     * @param ParserConfig $config Parser configuration
     *
     * @return ParserResult Parsed result
     */
    private function parseOutput(string $output, ParserConfig $config): ParserResult
    {
        $format = $this->config->getOutputFormat();

        if ($format === ExternalParserConfig::OUTPUT_FORMAT_WAKATI) {
            return $this->parseWakatiOutput($output, $config);
        }

        return $this->parseLineOutput($output, $config);
    }

    /**
     * Parse wakati-style output (space-separated tokens).
     *
     * @param string       $output Parser output
     * @param ParserConfig $config Parser configuration
     *
     * @return ParserResult Parsed result
     */
    private function parseWakatiOutput(string $output, ParserConfig $config): ParserResult
    {
        $sentences = [];
        $tokens = [];
        $sentenceIndex = 0;
        $tokenOrder = 0;

        // Split output into lines (each line is a sentence)
        $lines = preg_split('/\r?\n/', $output, -1, PREG_SPLIT_NO_EMPTY);

        if ($lines === false) {
            $lines = [];
        }

        foreach ($lines as $line) {
            $line = trim($line);
            if ($line === '') {
                continue;
            }

            $sentences[] = $line;

            // Split line by spaces to get tokens
            $parts = preg_split('/\s+/u', $line, -1, PREG_SPLIT_NO_EMPTY);
            if ($parts === false) {
                $parts = [];
            }

            foreach ($parts as $i => $part) {
                // Add space before token (except first)
                if ($i > 0) {
                    $tokens[] = Token::nonWord(' ', $sentenceIndex, $tokenOrder++);
                }

                // Determine if this is a word or non-word
                $isWord = $this->isWord($part, $config);
                if ($isWord) {
                    $tokens[] = Token::word($part, $sentenceIndex, $tokenOrder++);
                } else {
                    $tokens[] = Token::nonWord($part, $sentenceIndex, $tokenOrder++);
                }
            }

            $sentenceIndex++;
            $tokenOrder = 0;
        }

        if (empty($sentences)) {
            $sentences = [''];
        }

        return new ParserResult($sentences, $tokens);
    }

    /**
     * Parse line-style output (one token per line).
     *
     * @param string       $output Parser output
     * @param ParserConfig $config Parser configuration
     *
     * @return ParserResult Parsed result
     */
    private function parseLineOutput(string $output, ParserConfig $config): ParserResult
    {
        $sentences = [];
        $tokens = [];
        $sentenceIndex = 0;
        $tokenOrder = 0;
        $currentSentenceParts = [];

        $lines = preg_split('/\r?\n/', $output);
        if ($lines === false) {
            $lines = [];
        }

        foreach ($lines as $line) {
            $token = trim($line);

            // Empty line may indicate sentence boundary
            if ($token === '') {
                if (!empty($currentSentenceParts)) {
                    $sentences[] = implode('', $currentSentenceParts);
                    $currentSentenceParts = [];
                    $sentenceIndex++;
                    $tokenOrder = 0;
                }
                continue;
            }

            // Check for sentence-ending punctuation
            $isSentenceEnd = $this->isSentenceEnd($token, $config);

            // Add token
            $currentSentenceParts[] = $token;

            $isWord = $this->isWord($token, $config);
            if ($isWord) {
                $tokens[] = Token::word($token, $sentenceIndex, $tokenOrder++);
            } else {
                $tokens[] = Token::nonWord($token, $sentenceIndex, $tokenOrder++);
            }

            // Start new sentence after sentence-ending punctuation
            if ($isSentenceEnd) {
                $sentences[] = implode('', $currentSentenceParts);
                $currentSentenceParts = [];
                $sentenceIndex++;
                $tokenOrder = 0;
            }
        }

        // Handle remaining content
        if (!empty($currentSentenceParts)) {
            $sentences[] = implode('', $currentSentenceParts);
        }

        if (empty($sentences)) {
            $sentences = [''];
        }

        return new ParserResult($sentences, $tokens);
    }

    /**
     * Determine if a token is a word (learnable content).
     *
     * @param string       $token  Token text
     * @param ParserConfig $config Parser configuration
     *
     * @return bool True if token is a word
     */
    private function isWord(string $token, ParserConfig $config): bool
    {
        // Empty tokens are not words
        if (trim($token) === '') {
            return false;
        }

        // Use word character regex from config if available
        $wordChars = $config->getRegexpWordCharacters();
        if ($wordChars !== '' && !WordSpacing::usesMecabMagicWord($wordChars)) {
            // Check if token contains at least one word character
            $pattern = '/[' . $wordChars . ']/u';
            return (bool) preg_match($pattern, $token);
        }

        // Default: treat as word if it contains any letter or CJK character
        return (bool) preg_match('/[\p{L}\p{N}]/u', $token);
    }

    /**
     * Check if a token ends a sentence.
     *
     * @param string       $token  Token text
     * @param ParserConfig $config Parser configuration
     *
     * @return bool True if token is sentence-ending punctuation
     */
    private function isSentenceEnd(string $token, ParserConfig $config): bool
    {
        $sentenceChars = $config->getRegexpSplitSentences();

        if ($sentenceChars !== '') {
            $pattern = '/[' . $sentenceChars . ']$/u';
            return (bool) preg_match($pattern, $token);
        }

        // Default sentence-ending punctuation
        return (bool) preg_match('/[.!?。！？]$/u', $token);
    }
}
