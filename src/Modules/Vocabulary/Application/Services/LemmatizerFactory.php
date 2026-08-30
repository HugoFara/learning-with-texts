<?php

/**
 * LemmatizerFactory for creating lemmatizer instances.
 *
 * PHP version 8.2
 *
 * @category Application
 * @package  Lwt
 * @author   HugoFara <git@hugofara.net>
 * @license  Unlicense <http://unlicense.org/>
 * @link     https://hugofara.github.io/lwt/developer/api
 * @since    3.0.0
 */

declare(strict_types=1);

namespace Lwt\Modules\Vocabulary\Application\Services;

use Lwt\Modules\Vocabulary\Domain\LemmatizerInterface;
use Lwt\Modules\Vocabulary\Infrastructure\Lemmatizers\DictionaryLemmatizer;
use Lwt\Modules\Vocabulary\Infrastructure\Lemmatizers\HybridLemmatizer;
use Lwt\Modules\Vocabulary\Infrastructure\Lemmatizers\NlpServiceLemmatizer;
use Lwt\Shared\Infrastructure\Database\Connection;

/**
 * Factory for creating lemmatizers based on language configuration.
 *
 * This factory implements a hybrid approach:
 * 1. Dictionary-based lookup (fast, predictable)
 * 2. NLP service fallback (spaCy, high accuracy)
 *
 * The lemmatizer type can be configured per language in the database.
 */
class LemmatizerFactory
{
    /** Lemmatizer types */
    public const TYPE_NONE = 'none';
    public const TYPE_DICTIONARY = 'dictionary';
    public const TYPE_SPACY = 'spacy';
    public const TYPE_HYBRID = 'hybrid';

    /** @var array<string, LemmatizerInterface> Cached lemmatizer instances */
    private static array $instances = [];

    /** @var DictionaryLemmatizer|null Cached dictionary lemmatizer */
    private static ?DictionaryLemmatizer $dictionaryLemmatizer = null;

    /** @var NlpServiceLemmatizer|null Cached NLP service lemmatizer */
    private static ?NlpServiceLemmatizer $nlpLemmatizer = null;

    /**
     * Get the appropriate lemmatizer for a language.
     *
     * @param int         $languageId Language ID
     * @param string|null $type       Force specific type (null = use language config)
     *
     * @return LemmatizerInterface|null Lemmatizer or null if none configured
     */
    public static function getForLanguage(int $languageId, ?string $type = null): ?LemmatizerInterface
    {
        // Get lemmatizer type from language configuration or use provided type
        $lemmatizerType = $type ?? self::getLanguageLemmatizerType($languageId);

        if ($lemmatizerType === self::TYPE_NONE) {
            return null;
        }

        return self::createLemmatizer($lemmatizerType);
    }

    /**
     * Get the best available lemmatizer for a language code.
     *
     * Uses a fallback chain:
     * 1. Try NLP service (spaCy) if available for this language
     * 2. Fall back to dictionary-based lemmatizer
     *
     * @param string $languageCode ISO language code
     *
     * @return LemmatizerInterface
     */
    public static function getBestAvailable(string $languageCode): LemmatizerInterface
    {
        // Try NLP service first
        $nlp = self::getNlpServiceLemmatizer();
        if ($nlp->supportsLanguage($languageCode)) {
            return self::createHybridLemmatizer();
        }

        // Fall back to dictionary
        return self::getDictionaryLemmatizer();
    }

    /**
     * Create a lemmatizer by type.
     *
     * @param string $type Lemmatizer type
     *
     * @return LemmatizerInterface
     */
    public static function createLemmatizer(string $type): LemmatizerInterface
    {
        return match ($type) {
            self::TYPE_DICTIONARY => self::getDictionaryLemmatizer(),
            self::TYPE_SPACY => self::getNlpServiceLemmatizer(),
            self::TYPE_HYBRID => self::createHybridLemmatizer(),
            default => self::getDictionaryLemmatizer(),
        };
    }

    /**
     * Get the dictionary-based lemmatizer.
     *
     * @return DictionaryLemmatizer
     */
    public static function getDictionaryLemmatizer(): DictionaryLemmatizer
    {
        if (self::$dictionaryLemmatizer === null) {
            self::$dictionaryLemmatizer = new DictionaryLemmatizer();
        }
        return self::$dictionaryLemmatizer;
    }

    /**
     * Get the NLP service lemmatizer (spaCy).
     *
     * @return NlpServiceLemmatizer
     */
    public static function getNlpServiceLemmatizer(): NlpServiceLemmatizer
    {
        if (self::$nlpLemmatizer === null) {
            self::$nlpLemmatizer = new NlpServiceLemmatizer();
        }
        return self::$nlpLemmatizer;
    }

    /**
     * Create a hybrid lemmatizer (dictionary + NLP fallback).
     *
     * @return LemmatizerInterface
     */
    public static function createHybridLemmatizer(): LemmatizerInterface
    {
        if (!isset(self::$instances['hybrid'])) {
            self::$instances['hybrid'] = new HybridLemmatizer(
                self::getDictionaryLemmatizer(),
                self::getNlpServiceLemmatizer()
            );
        }
        return self::$instances['hybrid'];
    }

    /**
     * Get the configured lemmatizer type for a language.
     *
     * @param int $languageId Language ID
     *
     * @return string Lemmatizer type
     */
    private static function getLanguageLemmatizerType(int $languageId): string
    {
        $result = Connection::preparedFetchOne(
            "SELECT LgLemmatizerType FROM languages WHERE LgID = ?",
            [$languageId]
        );

        return (string) ($result['LgLemmatizerType'] ?? self::TYPE_DICTIONARY);
    }

    /**
     * Check if NLP service is available.
     *
     * @return bool
     */
    public static function isNlpServiceAvailable(): bool
    {
        return self::getNlpServiceLemmatizer()->isServiceAvailable();
    }

    /**
     * Get list of languages supported by the NLP service.
     *
     * @return string[]
     */
    public static function getNlpSupportedLanguages(): array
    {
        return self::getNlpServiceLemmatizer()->getSupportedLanguages();
    }

    /**
     * Get all potentially supported NLP languages (including uninstalled).
     *
     * @return string[]
     */
    public static function getAllNlpLanguages(): array
    {
        return self::getNlpServiceLemmatizer()->getAllPotentialLanguages();
    }

    /**
     * Clear cached instances (useful for testing).
     */
    public static function clearCache(): void
    {
        self::$instances = [];
        self::$dictionaryLemmatizer = null;
        self::$nlpLemmatizer = null;
    }
}
