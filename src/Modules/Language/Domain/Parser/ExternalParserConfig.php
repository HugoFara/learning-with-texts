<?php

/**
 * External Parser Configuration Value Object
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

use InvalidArgumentException;

/**
 * Immutable configuration for an external parser loaded from config file.
 *
 * This value object holds the configuration for a single external parser
 * as defined in config/parsers.php. It is immutable and validated on construction.
 *
 * @since 3.0.0
 */
class ExternalParserConfig
{
    public const INPUT_MODE_STDIN = 'stdin';
    public const INPUT_MODE_FILE = 'file';

    public const OUTPUT_FORMAT_LINE = 'line';
    public const OUTPUT_FORMAT_WAKATI = 'wakati';

    private const VALID_INPUT_MODES = [self::INPUT_MODE_STDIN, self::INPUT_MODE_FILE];
    private const VALID_OUTPUT_FORMATS = [self::OUTPUT_FORMAT_LINE, self::OUTPUT_FORMAT_WAKATI];

    /**
     * Create a new external parser configuration.
     *
     * @param string   $type         Unique parser type identifier (e.g., 'jieba')
     * @param string   $name         Human-readable display name
     * @param string   $binary       Path to executable or command name
     * @param string[] $args         Command-line arguments
     * @param string   $inputMode    How input is passed: 'stdin' or 'file'
     * @param string   $outputFormat Output format: 'line' or 'wakati'
     *
     * @throws InvalidArgumentException If configuration values are invalid
     */
    public function __construct(
        private string $type,
        private string $name,
        private string $binary,
        private array $args,
        private string $inputMode,
        private string $outputFormat
    ) {
        $this->validate();
    }

    /**
     * Validate the configuration values.
     *
     * @throws InvalidArgumentException If any value is invalid
     */
    private function validate(): void
    {
        if (trim($this->type) === '') {
            throw new InvalidArgumentException('Parser type cannot be empty');
        }

        if (trim($this->name) === '') {
            throw new InvalidArgumentException('Parser name cannot be empty');
        }

        if (trim($this->binary) === '') {
            throw new InvalidArgumentException('Parser binary cannot be empty');
        }

        if (!in_array($this->inputMode, self::VALID_INPUT_MODES, true)) {
            throw new InvalidArgumentException(sprintf(
                "Invalid input mode '%s'. Must be one of: %s",
                $this->inputMode,
                implode(', ', self::VALID_INPUT_MODES)
            ));
        }

        if (!in_array($this->outputFormat, self::VALID_OUTPUT_FORMATS, true)) {
            throw new InvalidArgumentException(sprintf(
                "Invalid output format '%s'. Must be one of: %s",
                $this->outputFormat,
                implode(', ', self::VALID_OUTPUT_FORMATS)
            ));
        }

        // Args are already typed as string[] via constructor,
        // validation happens at the call site
    }

    /**
     * Create configuration from a config array.
     *
     * @param string               $type   Parser type identifier
     * @param array<string, mixed> $config Configuration array from config file
     *
     * @return self Parser configuration
     *
     * @throws InvalidArgumentException If required fields are missing
     */
    public static function fromArray(string $type, array $config): self
    {
        if (!isset($config['name'])) {
            throw new InvalidArgumentException(
                "Parser '$type' is missing required 'name' field"
            );
        }

        if (!isset($config['binary'])) {
            throw new InvalidArgumentException(
                "Parser '$type' is missing required 'binary' field"
            );
        }

        $args = isset($config['args'])
            ? array_map(
                /** @param mixed $v */
                fn($v): string => (string) $v,
                array_values((array) $config['args'])
            )
            : [];

        return new self(
            $type,
            (string) $config['name'],
            (string) $config['binary'],
            $args,
            (string) ($config['input_mode'] ?? self::INPUT_MODE_STDIN),
            (string) ($config['output_format'] ?? self::OUTPUT_FORMAT_LINE)
        );
    }

    /**
     * Get the unique parser type identifier.
     *
     * @return string Parser type (e.g., 'jieba', 'sudachi')
     */
    public function getType(): string
    {
        return $this->type;
    }

    /**
     * Get the human-readable display name.
     *
     * @return string Display name for UI
     */
    public function getName(): string
    {
        return $this->name;
    }

    /**
     * Get the path to the executable.
     *
     * @return string Absolute path or command name
     */
    public function getBinary(): string
    {
        return $this->binary;
    }

    /**
     * Get the command-line arguments.
     *
     * @return string[] Arguments to pass to the binary
     */
    public function getArgs(): array
    {
        return $this->args;
    }

    /**
     * Get the input mode.
     *
     * @return string 'stdin' or 'file'
     */
    public function getInputMode(): string
    {
        return $this->inputMode;
    }

    /**
     * Get the output format.
     *
     * @return string 'line' or 'wakati'
     */
    public function getOutputFormat(): string
    {
        return $this->outputFormat;
    }

    /**
     * Check if input is passed via stdin.
     *
     * @return bool True if input mode is stdin
     */
    public function usesStdin(): bool
    {
        return $this->inputMode === self::INPUT_MODE_STDIN;
    }

    /**
     * Check if input is passed via temporary file.
     *
     * @return bool True if input mode is file
     */
    public function usesFile(): bool
    {
        return $this->inputMode === self::INPUT_MODE_FILE;
    }
}
