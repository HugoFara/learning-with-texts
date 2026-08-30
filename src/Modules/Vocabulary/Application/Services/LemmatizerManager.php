<?php

/**
 * Lemmatizer Manager
 *
 * Handles lemmatizer instantiation and NLP availability checks.
 *
 * PHP version 8.2
 *
 * @category Lwt
 * @package  Lwt\Modules\Vocabulary\Application\Services
 * @author   HugoFara <git@hugofara.net>
 * @license  Unlicense <http://unlicense.org/>
 * @link     https://hugofara.github.io/lwt/developer/api
 * @since    3.0.0
 */

declare(strict_types=1);

namespace Lwt\Modules\Vocabulary\Application\Services;

use Lwt\Modules\Vocabulary\Domain\LemmatizerInterface;

/**
 * Manages lemmatizer instantiation and NLP availability checks.
 *
 * @since 3.0.0
 */
class LemmatizerManager
{
    private LemmatizerInterface $lemmatizer;

    /**
     * Constructor.
     *
     * @param LemmatizerInterface $lemmatizer Lemmatizer implementation
     */
    public function __construct(LemmatizerInterface $lemmatizer)
    {
        $this->lemmatizer = $lemmatizer;
    }

    /**
     * Get the best available lemmatizer for a language.
     *
     * Uses the LemmatizerFactory to select the appropriate lemmatizer
     * based on language configuration and availability.
     *
     * @param string $languageCode ISO language code
     *
     * @return LemmatizerInterface
     */
    public function getLemmatizerForLanguage(string $languageCode): LemmatizerInterface
    {
        return LemmatizerFactory::getBestAvailable($languageCode);
    }

    /**
     * Get a lemmatizer by type.
     *
     * @param string $type Lemmatizer type ('dictionary', 'spacy', 'hybrid')
     *
     * @return LemmatizerInterface
     */
    public function getLemmatizerByType(string $type): LemmatizerInterface
    {
        return LemmatizerFactory::createLemmatizer($type);
    }

    /**
     * Check if NLP service (spaCy) is available.
     *
     * @return bool
     */
    public function isNlpServiceAvailable(): bool
    {
        return LemmatizerFactory::isNlpServiceAvailable();
    }

    /**
     * Get languages supported by the NLP service.
     *
     * @return string[]
     */
    public function getNlpSupportedLanguages(): array
    {
        return LemmatizerFactory::getNlpSupportedLanguages();
    }

    /**
     * Get all languages potentially supported by NLP (including uninstalled models).
     *
     * @return string[]
     */
    public function getAllNlpLanguages(): array
    {
        return LemmatizerFactory::getAllNlpLanguages();
    }

    /**
     * Check if lemmatization is available for a language.
     *
     * @param string $languageCode ISO language code
     *
     * @return bool True if lemmatization is available
     */
    public function isAvailableForLanguage(string $languageCode): bool
    {
        return $this->lemmatizer->supportsLanguage($languageCode);
    }

    /**
     * Get all languages with available lemmatization support.
     *
     * @return string[] Array of language codes
     */
    public function getAvailableLanguages(): array
    {
        return $this->lemmatizer->getSupportedLanguages();
    }
}
