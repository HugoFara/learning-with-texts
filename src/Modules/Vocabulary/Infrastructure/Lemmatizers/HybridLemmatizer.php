<?php

/**
 * HybridLemmatizer for combining dictionary and NLP approaches.
 *
 * PHP version 8.2
 *
 * @category Infrastructure
 * @package  Lwt
 * @author   HugoFara <git@hugofara.net>
 * @license  Unlicense <http://unlicense.org/>
 * @link     https://hugofara.github.io/lwt/developer/api
 * @since    3.0.0
 */

declare(strict_types=1);

namespace Lwt\Modules\Vocabulary\Infrastructure\Lemmatizers;

use Lwt\Modules\Vocabulary\Domain\LemmatizerInterface;

/**
 * Hybrid lemmatizer that combines dictionary and NLP-based approaches.
 *
 * Strategy:
 * 1. Try dictionary lookup first (fast, predictable)
 * 2. Fall back to NLP service (spaCy) for unknown words
 *
 * This provides the best of both worlds:
 * - Fast results for common words via dictionary
 * - High accuracy for uncommon words via NLP models
 */
class HybridLemmatizer implements LemmatizerInterface
{
    private DictionaryLemmatizer $dictionaryLemmatizer;
    private NlpServiceLemmatizer $nlpLemmatizer;

    /**
     * Create a hybrid lemmatizer.
     *
     * @param DictionaryLemmatizer $dictionaryLemmatizer Primary (fast) lemmatizer
     * @param NlpServiceLemmatizer $nlpLemmatizer        Fallback (accurate) lemmatizer
     */
    public function __construct(
        DictionaryLemmatizer $dictionaryLemmatizer,
        NlpServiceLemmatizer $nlpLemmatizer
    ) {
        $this->dictionaryLemmatizer = $dictionaryLemmatizer;
        $this->nlpLemmatizer = $nlpLemmatizer;
    }

    /**
     * {@inheritdoc}
     */
    public function lemmatize(string $wordForm, string $languageCode): ?string
    {
        if ($wordForm === '') {
            return null;
        }

        // Try dictionary first
        $lemma = $this->dictionaryLemmatizer->lemmatize($wordForm, $languageCode);
        if ($lemma !== null) {
            return $lemma;
        }

        // Fall back to NLP service
        return $this->nlpLemmatizer->lemmatize($wordForm, $languageCode);
    }

    /**
     * {@inheritdoc}
     */
    public function lemmatizeBatch(array $wordForms, string $languageCode): array
    {
        if (empty($wordForms)) {
            return [];
        }

        // Get dictionary results for all words
        $dictResults = $this->dictionaryLemmatizer->lemmatizeBatch($wordForms, $languageCode);

        // Find words that need NLP lookup (not found in dictionary)
        $needsNlp = [];
        foreach ($dictResults as $word => $lemma) {
            if ($lemma === null) {
                $needsNlp[] = $word;
            }
        }

        // Get NLP results for remaining words
        if (!empty($needsNlp)) {
            $nlpResults = $this->nlpLemmatizer->lemmatizeBatch($needsNlp, $languageCode);

            // Merge results (NLP fills in dictionary gaps)
            foreach ($nlpResults as $word => $lemma) {
                if ($lemma !== null) {
                    $dictResults[$word] = $lemma;
                }
            }
        }

        return $dictResults;
    }

    /**
     * {@inheritdoc}
     */
    public function supportsLanguage(string $languageCode): bool
    {
        // Supported if either lemmatizer supports it
        return $this->dictionaryLemmatizer->supportsLanguage($languageCode)
            || $this->nlpLemmatizer->supportsLanguage($languageCode);
    }

    /**
     * {@inheritdoc}
     */
    public function getSupportedLanguages(): array
    {
        // Combine supported languages from both
        $dictLangs = $this->dictionaryLemmatizer->getSupportedLanguages();
        $nlpLangs = $this->nlpLemmatizer->getSupportedLanguages();

        return array_values(array_unique(array_merge($dictLangs, $nlpLangs)));
    }

    /**
     * Check if the dictionary supports a language.
     *
     * @param string $languageCode Language code
     *
     * @return bool
     */
    public function hasDictionarySupport(string $languageCode): bool
    {
        return $this->dictionaryLemmatizer->supportsLanguage($languageCode);
    }

    /**
     * Check if the NLP service supports a language.
     *
     * @param string $languageCode Language code
     *
     * @return bool
     */
    public function hasNlpSupport(string $languageCode): bool
    {
        return $this->nlpLemmatizer->supportsLanguage($languageCode);
    }
}
