<?php

/**
 * Translation Service - Business logic for translation APIs
 *
 * Handles translations via Google Translate, Glosbe, and generic dictionary services.
 *
 * PHP version 8.2
 *
 * @category Lwt
 * @package  Lwt\Modules\Dictionary\Application
 * @author   HugoFara <git@hugofara.net>
 * @license  Unlicense <http://unlicense.org/>
 * @link     https://hugofara.github.io/lwt/developer/api
 * @since    3.0.0
 */

declare(strict_types=1);

namespace Lwt\Modules\Dictionary\Application;

use Lwt\Modules\Dictionary\Infrastructure\Translation\GoogleTranslateClient;
use Lwt\Shared\Infrastructure\Dictionary\DictionaryAdapter;
use Lwt\Shared\Infrastructure\Database\QueryBuilder;
use Lwt\Shared\Infrastructure\Database\Settings;

/**
 * Service class for handling translation operations.
 *
 * Provides methods for translating text using various services
 * including Google Translate and Glosbe API.
 *
 * @category Lwt
 * @package  Lwt\Modules\Dictionary\Application
 * @author   HugoFara <git@hugofara.net>
 * @license  Unlicense <http://unlicense.org/>
 * @link     https://hugofara.github.io/lwt/developer/api
 * @since    3.0.0
 */
class TranslationService
{
    /**
     * Translate text using Google Translate.
     *
     * @param string $text    Text to translate
     * @param string $srcLang Source language code (e.g., "es")
     * @param string $tgtLang Target language code (e.g., "en")
     *
     * @return array{success: bool, translations: string[], error?: string}
     */
    public function translateViaGoogle(
        string $text,
        string $srcLang,
        string $tgtLang
    ): array {
        if (trim($text) === '') {
            return [
                'success' => false,
                'translations' => [],
                'error' => 'Text is empty'
            ];
        }

        if (empty($srcLang) || empty($tgtLang)) {
            return [
                'success' => false,
                'translations' => [],
                'error' => 'Source and target languages are required'
            ];
        }

        $result = GoogleTranslateClient::staticTranslate($text, $srcLang, $tgtLang);

        if ($result === false) {
            return [
                'success' => false,
                'translations' => [],
                'error' => 'Unable to get translation from Google'
            ];
        }

        return [
            'success' => true,
            'translations' => $result
        ];
    }

    /**
     * Build the Glosbe API URL for a translation request.
     *
     * @param string $phrase Phrase to translate
     * @param string $from   Source language code
     * @param string $dest   Destination language code
     *
     * @return string The Glosbe dictionary URL
     */
    public function buildGlosbeUrl(string $phrase, string $from, string $dest): string
    {
        return 'https://glosbe.com/' . urlencode($from) . '/' . urlencode($dest) . '/' . urlencode($phrase);
    }

    /**
     * Validate Glosbe API parameters.
     *
     * @param string $from   Source language code
     * @param string $dest   Destination language code
     * @param string $phrase Phrase to translate
     *
     * @return array{valid: bool, error?: string}
     */
    public function validateGlosbeParams(string $from, string $dest, string $phrase): array
    {
        if ($from === '' || $dest === '') {
            return [
                'valid' => false,
                'error' => 'Language codes are required'
            ];
        }

        if ($phrase === '') {
            return [
                'valid' => false,
                'error' => 'Term is not set'
            ];
        }

        return ['valid' => true];
    }

    /**
     * Get the translator URL for a sentence.
     *
     * @param int $textId Text ID
     * @param int $order  Order/position in the text
     *
     * @return array{url: string|null, sentence: string|null, error?: string}
     */
    public function getTranslatorUrl(int $textId, int $order): array
    {
        $result = QueryBuilder::table('word_occurrences')
            ->select(['sentences.SeText', 'languages.LgGoogleTranslateURI'])
            ->join('sentences', 'word_occurrences.Ti2SeID', '=', 'sentences.SeID')
            ->join('languages', 'word_occurrences.Ti2LgID', '=', 'languages.LgID')
            ->where('word_occurrences.Ti2TxID', '=', $textId)
            ->where('word_occurrences.Ti2Order', '=', $order)
            ->getPrepared();

        $record = $result[0] ?? null;

        if ($record === null) {
            return [
                'url' => null,
                'sentence' => null,
                'error' => 'No results found'
            ];
        }

        $sentence = isset($record['SeText']) ? (string) $record['SeText'] : '';
        $trans = isset($record['LgGoogleTranslateURI']) ?
            (string) $record['LgGoogleTranslateURI'] : "";

        // Remove leading asterisk (deprecated popup marker)
        if (substr($trans, 0, 1) === '*') {
            $trans = substr($trans, 1);
        }

        if ($trans === '') {
            return [
                'url' => null,
                'sentence' => $sentence,
                'error' => 'No translator configured'
            ];
        }

        // Add sentence mode parameter for Google Translate
        $parsedUrl = parse_url($trans, PHP_URL_PATH);
        if (
            substr($trans, 0, 7) === 'ggl.php'
            || (is_string($parsedUrl) && str_ends_with($parsedUrl, 'ggl.php'))
        ) {
            $trans = str_replace('?', '?sent=1&', $trans);
        }

        // Create the dictionary link with the sentence
        $url = DictionaryAdapter::createDictLink($trans, $sentence);

        return [
            'url' => $url,
            'sentence' => $sentence
        ];
    }

    /**
     * Create a dictionary link by substituting the term in the URL.
     *
     * @param string $dictUrl Dictionary URL with placeholder
     * @param string $term    Term to substitute
     *
     * @return string Formatted dictionary URL
     */
    public function createDictLink(string $dictUrl, string $term): string
    {
        return DictionaryAdapter::createDictLink($dictUrl, $term);
    }

    /**
     * Get the current language's TTS voice API setting.
     *
     * @return string|null The TTS voice API setting or null if not set
     */
    public function getCurrentLanguageTtsVoice(): ?string
    {
        $lgId = Settings::get('currentlanguage');

        if (!$lgId) {
            return null;
        }

        $result = QueryBuilder::table('languages')
            ->select(['LgTTSVoiceAPI'])
            ->where('LgID', '=', $lgId)
            ->getPrepared();

        /** @var string|null $ttsVoice */
        $ttsVoice = $result[0]['LgTTSVoiceAPI'] ?? null;
        return $ttsVoice;
    }

    /**
     * Build Google Translate page URL.
     *
     * @param string $text   Text to translate
     * @param string $srcLang Source language
     * @param string $tgtLang Target language
     *
     * @return string Google Translate URL
     */
    public function buildGoogleTranslateUrl(string $text, string $srcLang, string $tgtLang): string
    {
        return "https://translate.google.com/?sl=$srcLang&tl=$tgtLang&text=" .
            urlencode($text) . "&lwt_popup=true";
    }
}
