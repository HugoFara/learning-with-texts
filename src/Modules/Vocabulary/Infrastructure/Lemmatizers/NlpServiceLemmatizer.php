<?php

/**
 * NlpServiceLemmatizer for spaCy-based lemmatization.
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

use Lwt\Modules\Language\Infrastructure\NlpServiceHandler;
use Lwt\Modules\Vocabulary\Domain\LemmatizerInterface;

/**
 * Lemmatizer that uses the NLP microservice (spaCy).
 *
 * This lemmatizer communicates with the Python NLP microservice for
 * high-accuracy lemmatization using spaCy models. It supports 25+
 * languages with context-aware lemmatization.
 *
 * The NLP service must be running and accessible via NLP_SERVICE_URL.
 */
class NlpServiceLemmatizer implements LemmatizerInterface
{
    private NlpServiceHandler $handler;
    private string $lemmatizer;

    /** @var array<string, bool>|null Cached language support info */
    private ?array $supportedLanguages = null;

    /**
     * Supported spaCy models by language code.
     *
     * @var array<string, string>
     */
    private const SPACY_MODELS = [
        'en' => 'en_core_web_sm',
        'de' => 'de_core_news_sm',
        'fr' => 'fr_core_news_sm',
        'es' => 'es_core_news_sm',
        'pt' => 'pt_core_news_sm',
        'it' => 'it_core_news_sm',
        'nl' => 'nl_core_news_sm',
        'el' => 'el_core_news_sm',
        'nb' => 'nb_core_news_sm',
        'lt' => 'lt_core_news_sm',
        'pl' => 'pl_core_news_sm',
        'ro' => 'ro_core_news_sm',
        'ru' => 'ru_core_news_sm',
        'ca' => 'ca_core_news_sm',
        'da' => 'da_core_news_sm',
        'fi' => 'fi_core_news_sm',
        'hr' => 'hr_core_news_sm',
        'ko' => 'ko_core_news_sm',
        'mk' => 'mk_core_news_sm',
        'sl' => 'sl_core_news_sm',
        'sv' => 'sv_core_news_sm',
        'uk' => 'uk_core_news_sm',
        'zh' => 'zh_core_web_sm',
        'ja' => 'ja_core_news_sm',
    ];

    /**
     * Create a new NLP service lemmatizer.
     *
     * @param NlpServiceHandler|null $handler    NLP service handler (auto-created if null)
     * @param string                 $lemmatizer Lemmatizer type ('spacy')
     */
    public function __construct(?NlpServiceHandler $handler = null, string $lemmatizer = 'spacy')
    {
        $this->handler = $handler ?? new NlpServiceHandler();
        $this->lemmatizer = $lemmatizer;
    }

    /**
     * {@inheritdoc}
     */
    public function lemmatize(string $wordForm, string $languageCode): ?string
    {
        if ($wordForm === '') {
            return null;
        }

        $langCode = $this->normalizeLanguageCode($languageCode);

        // Check if service supports this language
        if (!$this->supportsLanguage($langCode)) {
            return null;
        }

        return $this->handler->lemmatize($wordForm, $langCode, $this->lemmatizer);
    }

    /**
     * {@inheritdoc}
     */
    public function lemmatizeBatch(array $wordForms, string $languageCode): array
    {
        if (empty($wordForms)) {
            return [];
        }

        $langCode = $this->normalizeLanguageCode($languageCode);

        // Check if service supports this language
        if (!$this->supportsLanguage($langCode)) {
            return array_fill_keys($wordForms, null);
        }

        return $this->handler->lemmatizeBatch(array_values($wordForms), $langCode, $this->lemmatizer);
    }

    /**
     * {@inheritdoc}
     */
    public function supportsLanguage(string $languageCode): bool
    {
        $langCode = $this->normalizeLanguageCode($languageCode);

        // First check if it's a potentially supported language
        if (!isset(self::SPACY_MODELS[$langCode])) {
            return false;
        }

        // Check if service is available and model is installed
        if ($this->supportedLanguages === null) {
            $this->loadSupportedLanguages();
        }

        return $this->supportedLanguages[$langCode] ?? false;
    }

    /**
     * {@inheritdoc}
     */
    public function getSupportedLanguages(): array
    {
        if ($this->supportedLanguages === null) {
            $this->loadSupportedLanguages();
        }

        return array_keys(array_filter($this->supportedLanguages ?? []));
    }

    /**
     * Get all potentially supported languages (including uninstalled models).
     *
     * @return string[]
     */
    public function getAllPotentialLanguages(): array
    {
        return array_keys(self::SPACY_MODELS);
    }

    /**
     * Check if the NLP service is available.
     *
     * @return bool
     */
    public function isServiceAvailable(): bool
    {
        return $this->handler->isAvailable();
    }

    /**
     * Get detailed info about available lemmatizers.
     *
     * @return array
     */
    public function getLemmatizerInfo(): array
    {
        return $this->handler->getAvailableLemmatizers();
    }

    /**
     * Load supported languages from the NLP service.
     */
    private function loadSupportedLanguages(): void
    {
        $this->supportedLanguages = [];

        if (!$this->handler->isAvailable()) {
            return;
        }

        $info = $this->handler->getAvailableLemmatizers();
        $installed = (array) ($info['spacy_models']['installed'] ?? []);

        foreach (self::SPACY_MODELS as $lang => $model) {
            $this->supportedLanguages[$lang] = in_array($lang, $installed, true);
        }
    }

    /**
     * Normalize language code to base form.
     *
     * Converts codes like 'en-US', 'en_GB', 'eng' to 'en'.
     *
     * @param string $languageCode Language code
     *
     * @return string Normalized code
     */
    private function normalizeLanguageCode(string $languageCode): string
    {
        // Remove region/variant (en-US -> en, en_GB -> en)
        $code = strtolower(preg_replace('/[-_].*$/', '', $languageCode) ?? $languageCode);

        // Map 3-letter codes to 2-letter codes
        $iso3to2 = [
            'eng' => 'en',
            'deu' => 'de',
            'ger' => 'de',
            'fra' => 'fr',
            'fre' => 'fr',
            'spa' => 'es',
            'por' => 'pt',
            'ita' => 'it',
            'nld' => 'nl',
            'dut' => 'nl',
            'ell' => 'el',
            'gre' => 'el',
            'nob' => 'nb',
            'lit' => 'lt',
            'pol' => 'pl',
            'ron' => 'ro',
            'rum' => 'ro',
            'rus' => 'ru',
            'cat' => 'ca',
            'dan' => 'da',
            'fin' => 'fi',
            'hrv' => 'hr',
            'kor' => 'ko',
            'mkd' => 'mk',
            'mac' => 'mk',
            'slv' => 'sl',
            'swe' => 'sv',
            'ukr' => 'uk',
            'zho' => 'zh',
            'chi' => 'zh',
            'jpn' => 'ja',
        ];

        return $iso3to2[$code] ?? $code;
    }
}
