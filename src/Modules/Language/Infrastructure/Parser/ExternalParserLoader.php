<?php

/**
 * External Parser Configuration Loader
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

use Lwt\Modules\Language\Domain\Parser\ExternalParserConfig;
use InvalidArgumentException;

/**
 * Loads external parser configurations from the config file.
 *
 * This class is responsible for loading and validating the external parser
 * configurations from config/parsers.php. It provides a secure way to
 * register additional parsers without allowing arbitrary code execution.
 *
 * @since 3.0.0
 */
class ExternalParserLoader
{
    private const CONFIG_FILENAME = 'config/parsers.php';

    private ?string $configPath;

    /** @var ExternalParserConfig[]|null Cached parser configs */
    private ?array $cachedParsers = null;

    /**
     * Create a new loader.
     *
     * @param string|null $configPath Optional custom path to config file (for testing)
     */
    public function __construct(?string $configPath = null)
    {
        $this->configPath = $configPath;
    }

    /**
     * Get the path to the configuration file.
     *
     * @return string Absolute path to config file
     */
    public function getConfigPath(): string
    {
        if ($this->configPath !== null) {
            return $this->configPath;
        }

        // Find project root by looking for composer.json
        $dir = __DIR__;
        while ($dir !== '/' && $dir !== '') {
            if (file_exists($dir . '/composer.json')) {
                return $dir . '/' . self::CONFIG_FILENAME;
            }
            $dir = dirname($dir);
        }

        // Fallback: assume standard LWT directory structure
        // __DIR__ is src/Modules/Language/Infrastructure/Parser, project root is 5 levels up
        return dirname(__DIR__, 5) . '/' . self::CONFIG_FILENAME;
    }

    /**
     * Load the raw configuration array from the config file.
     *
     * @return array<array-key, mixed> Parser configurations (keys and values need validation)
     */
    public function loadConfig(): array
    {
        $configPath = $this->getConfigPath();

        if (!file_exists($configPath)) {
            return [];
        }

        if (!is_readable($configPath)) {
            return [];
        }

        $config = require $configPath;

        if (!is_array($config)) {
            return [];
        }

        return $config;
    }

    /**
     * Get all external parser configurations.
     *
     * Returns an array of validated ExternalParserConfig objects for each
     * parser defined in the config file. Invalid configurations are skipped
     * with a warning logged.
     *
     * @return ExternalParserConfig[] Array of parser configurations
     */
    public function getExternalParsers(): array
    {
        if ($this->cachedParsers !== null) {
            return $this->cachedParsers;
        }

        $config = $this->loadConfig();
        $parsers = [];

        foreach ($config as $type => $parserConfig) {
            // Skip non-string keys
            if (!is_string($type)) {
                continue;
            }

            // Skip non-array configurations
            if (!is_array($parserConfig)) {
                continue;
            }

            try {
                /** @var array<string, mixed> $parserConfig */
                $parsers[] = ExternalParserConfig::fromArray($type, $parserConfig);
            } catch (InvalidArgumentException $e) {
                // Log warning but continue with other parsers
                error_log(sprintf(
                    'LWT: Invalid external parser configuration for "%s": %s',
                    $type,
                    $e->getMessage()
                ));
            }
        }

        $this->cachedParsers = $parsers;
        return $parsers;
    }

    /**
     * Check if there are any external parsers configured.
     *
     * @return bool True if at least one external parser is configured
     */
    public function hasExternalParsers(): bool
    {
        return count($this->getExternalParsers()) > 0;
    }

    /**
     * Get a specific parser configuration by type.
     *
     * @param string $type Parser type identifier
     *
     * @return ExternalParserConfig|null Parser config or null if not found
     */
    public function getParser(string $type): ?ExternalParserConfig
    {
        foreach ($this->getExternalParsers() as $parser) {
            if ($parser->getType() === $type) {
                return $parser;
            }
        }

        return null;
    }

    /**
     * Clear the cached parser configurations.
     *
     * Useful for testing or when the config file has been modified.
     */
    public function clearCache(): void
    {
        $this->cachedParsers = null;
    }
}
