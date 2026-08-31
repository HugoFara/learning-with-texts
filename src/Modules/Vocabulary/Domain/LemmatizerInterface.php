<?php

/**
 * Lemmatizer Interface
 *
 * Defines the contract for lemmatization implementations.
 *
 * PHP version 8.2
 *
 * @category Lwt
 * @package  Lwt\Modules\Vocabulary\Domain
 * @author   HugoFara <git@hugofara.net>
 * @license  Unlicense <http://unlicense.org/>
 * @link     https://hugofara.github.io/lwt/developer/api
 * @since    3.0.0
 */

declare(strict_types=1);

namespace Lwt\Modules\Vocabulary\Domain;

/**
 * Interface for lemmatization strategies.
 *
 * Implementations can use dictionaries, rule-based stemming,
 * or external NLP services to find the base form (lemma) of words.
 *
 * @since 3.0.0
 */
interface LemmatizerInterface
{
    /**
     * Find the lemma (base form) of a word.
     *
     * @param string $word         The word to lemmatize
     * @param string $languageCode ISO language code (e.g., 'en', 'de', 'fr')
     *
     * @return string|null The lemma, or null if not found
     */
    public function lemmatize(string $word, string $languageCode): ?string;

    /**
     * Lemmatize multiple words in batch.
     *
     * @param string[] $words        Array of words to lemmatize
     * @param string   $languageCode ISO language code
     *
     * @return array<string, string|null> Word => lemma mapping
     */
    public function lemmatizeBatch(array $words, string $languageCode): array;

    /**
     * Check if this lemmatizer supports a given language.
     *
     * @param string $languageCode ISO language code
     *
     * @return bool True if the language is supported
     */
    public function supportsLanguage(string $languageCode): bool;

    /**
     * Get the list of supported language codes.
     *
     * @return string[] Array of ISO language codes
     */
    public function getSupportedLanguages(): array;
}
