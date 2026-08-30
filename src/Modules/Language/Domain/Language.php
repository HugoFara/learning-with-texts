<?php

/**
 * Language Entity
 *
 * PHP version 8.2
 *
 * @category Lwt
 * @package  Lwt\Entity
 * @author   HugoFara <git@hugofara.net>
 * @license  Unlicense <http://unlicense.org/>
 * @link     https://hugofara.github.io/lwt/developer/api
 * @since    2.7.0
 */

declare(strict_types=1);

namespace Lwt\Modules\Language\Domain;

use InvalidArgumentException;
use Lwt\Modules\Language\Domain\ValueObject\LanguageId;

/**
 * A language represented as a rich domain object.
 *
 * Languages define how texts are parsed (word boundaries, sentence splitting),
 * dictionary URLs for lookups, and display settings (RTL, text size).
 *
 * This class enforces domain invariants and encapsulates business logic.
 *
 * @since 2.10.0-fork Get new ttsvoiceapi, showromanization properties
 * @since 3.0.0 Refactored to rich domain model
 */
class Language
{
    private LanguageId $id;
    private string $name;
    private string $dict1Uri;
    private string $dict2Uri;
    private string $translatorUri;
    private bool $dict1PopUp;
    private bool $dict2PopUp;
    private bool $translatorPopUp;
    private ?string $sourceLang;
    private ?string $targetLang;
    private string $exportTemplate;
    private int $textSize;
    private string $characterSubstitutions;
    private string $regexpSplitSentences;
    private string $exceptionsSplitSentences;
    private string $regexpWordCharacters;
    private bool $removeSpaces;
    private bool $splitEachChar;
    private bool $rightToLeft;
    private string $ttsVoiceApi;
    private bool $showRomanization;
    private ?string $parserType;
    private int $localDictMode;
    private ?string $piperVoiceId;

    /**
     * Private constructor - use factory methods instead.
     */
    private function __construct(
        LanguageId $id,
        string $name,
        string $dict1Uri,
        string $dict2Uri,
        string $translatorUri,
        bool $dict1PopUp,
        bool $dict2PopUp,
        bool $translatorPopUp,
        ?string $sourceLang,
        ?string $targetLang,
        string $exportTemplate,
        int $textSize,
        string $characterSubstitutions,
        string $regexpSplitSentences,
        string $exceptionsSplitSentences,
        string $regexpWordCharacters,
        bool $removeSpaces,
        bool $splitEachChar,
        bool $rightToLeft,
        string $ttsVoiceApi,
        bool $showRomanization,
        ?string $parserType = null,
        int $localDictMode = 0,
        ?string $piperVoiceId = null
    ) {
        $this->id = $id;
        $this->name = $name;
        $this->dict1Uri = $dict1Uri;
        $this->dict2Uri = $dict2Uri;
        $this->translatorUri = $translatorUri;
        $this->dict1PopUp = $dict1PopUp;
        $this->dict2PopUp = $dict2PopUp;
        $this->translatorPopUp = $translatorPopUp;
        $this->sourceLang = $sourceLang;
        $this->targetLang = $targetLang;
        $this->exportTemplate = $exportTemplate;
        $this->textSize = $textSize;
        $this->characterSubstitutions = $characterSubstitutions;
        $this->regexpSplitSentences = $regexpSplitSentences;
        $this->exceptionsSplitSentences = $exceptionsSplitSentences;
        $this->regexpWordCharacters = $regexpWordCharacters;
        $this->removeSpaces = $removeSpaces;
        $this->splitEachChar = $splitEachChar;
        $this->rightToLeft = $rightToLeft;
        $this->ttsVoiceApi = $ttsVoiceApi;
        $this->showRomanization = $showRomanization;
        $this->parserType = $parserType;
        $this->localDictMode = $localDictMode;
        $this->piperVoiceId = $piperVoiceId;
    }

    /**
     * Create a new language with required settings.
     *
     * @param string $name                    Language name
     * @param string $dict1Uri                Primary dictionary URL (lwt_term is replaced with word)
     * @param string $regexpSplitSentences    Regex for sentence splitting
     * @param string $regexpWordCharacters    Regex for word characters
     *
     * @return self
     *
     * @throws InvalidArgumentException If name is empty
     */
    public static function create(
        string $name,
        string $dict1Uri,
        string $regexpSplitSentences,
        string $regexpWordCharacters
    ): self {
        $trimmedName = trim($name);
        if ($trimmedName === '') {
            throw new InvalidArgumentException('Language name cannot be empty');
        }

        return new self(
            LanguageId::new(),
            $trimmedName,
            trim($dict1Uri),
            '',
            '',
            false,    // dict1PopUp
            false,    // dict2PopUp
            false,    // translatorPopUp
            null,     // sourceLang
            null,     // targetLang
            '',
            100,
            '',
            trim($regexpSplitSentences),
            '',
            trim($regexpWordCharacters),
            false,
            false,
            false,
            '',
            true
        );
    }

    /**
     * Reconstitute a language from persistence.
     *
     * @param int         $id                        The language ID
     * @param string      $name                      Language name
     * @param string      $dict1Uri                  Primary dictionary URI
     * @param string      $dict2Uri                  Secondary dictionary URI
     * @param string      $translatorUri             Translator URI
     * @param bool        $dict1PopUp                Dictionary 1 opens in popup
     * @param bool        $dict2PopUp                Dictionary 2 opens in popup
     * @param bool        $translatorPopUp           Translator opens in popup
     * @param string|null $sourceLang                Source language code (BCP 47)
     * @param string|null $targetLang                Target language code (BCP 47)
     * @param string      $exportTemplate            Export template
     * @param int         $textSize                  Text size percentage
     * @param string      $characterSubstitutions    Character substitutions
     * @param string      $regexpSplitSentences      Sentence split regex
     * @param string      $exceptionsSplitSentences  Split exceptions
     * @param string      $regexpWordCharacters      Word character regex
     * @param bool        $removeSpaces              Remove spaces flag
     * @param bool        $splitEachChar             Split each character flag
     * @param bool        $rightToLeft               Right-to-left flag
     * @param string      $ttsVoiceApi               TTS API URL
     * @param bool        $showRomanization          Show romanization flag
     * @param string|null $parserType                Parser type (regex, character, mecab, etc.)
     * @param int         $localDictMode             Local dictionary mode (0-3)
     * @param string|null $piperVoiceId              Piper TTS voice ID
     *
     * @return self
     *
     * @internal This method is for repository use only
     */
    public static function reconstitute(
        int $id,
        string $name,
        string $dict1Uri,
        string $dict2Uri,
        string $translatorUri,
        bool $dict1PopUp,
        bool $dict2PopUp,
        bool $translatorPopUp,
        ?string $sourceLang,
        ?string $targetLang,
        string $exportTemplate,
        int $textSize,
        string $characterSubstitutions,
        string $regexpSplitSentences,
        string $exceptionsSplitSentences,
        string $regexpWordCharacters,
        bool $removeSpaces,
        bool $splitEachChar,
        bool $rightToLeft,
        string $ttsVoiceApi,
        bool $showRomanization,
        ?string $parserType = null,
        int $localDictMode = 0,
        ?string $piperVoiceId = null
    ): self {
        return new self(
            LanguageId::fromInt($id),
            $name,
            $dict1Uri,
            $dict2Uri,
            $translatorUri,
            $dict1PopUp,
            $dict2PopUp,
            $translatorPopUp,
            $sourceLang,
            $targetLang,
            $exportTemplate,
            $textSize,
            $characterSubstitutions,
            $regexpSplitSentences,
            $exceptionsSplitSentences,
            $regexpWordCharacters,
            $removeSpaces,
            $splitEachChar,
            $rightToLeft,
            $ttsVoiceApi,
            $showRomanization,
            $parserType,
            $localDictMode,
            $piperVoiceId
        );
    }

    // Domain behavior methods

    /**
     * Update the language name.
     *
     * @param string $name The new name
     *
     * @return void
     *
     * @throws InvalidArgumentException If name is empty
     */
    public function rename(string $name): void
    {
        $trimmedName = trim($name);
        if ($trimmedName === '') {
            throw new InvalidArgumentException('Language name cannot be empty');
        }
        $this->name = $trimmedName;
    }

    /**
     * Configure dictionaries.
     *
     * @param string $primary   Primary dictionary URL
     * @param string $secondary Secondary dictionary URL
     *
     * @return void
     */
    public function configureDictionaries(string $primary, string $secondary = ''): void
    {
        $this->dict1Uri = trim($primary);
        $this->dict2Uri = trim($secondary);
    }

    /**
     * Configure the translator.
     *
     * @param string $translatorUri Translator URL
     *
     * @return void
     */
    public function configureTranslator(string $translatorUri): void
    {
        $this->translatorUri = trim($translatorUri);
    }

    /**
     * Configure text parsing rules.
     *
     * @param string $sentenceSplitRegex  Regex for splitting sentences
     * @param string $sentenceExceptions  Exceptions for sentence splitting
     * @param string $wordCharRegex       Regex for word characters
     * @param string $charSubstitutions   Character substitutions
     *
     * @return void
     */
    public function configureTextParsing(
        string $sentenceSplitRegex,
        string $sentenceExceptions,
        string $wordCharRegex,
        string $charSubstitutions = ''
    ): void {
        $this->regexpSplitSentences = trim($sentenceSplitRegex);
        $this->exceptionsSplitSentences = trim($sentenceExceptions);
        $this->regexpWordCharacters = trim($wordCharRegex);
        $this->characterSubstitutions = $charSubstitutions;
    }

    /**
     * Configure CJK-style language settings.
     *
     * For languages like Chinese/Japanese that don't use spaces between words.
     *
     * @param bool $removeSpaces   Whether to remove spaces
     * @param bool $splitEachChar  Whether to split each character
     *
     * @return void
     */
    public function configureCjkMode(bool $removeSpaces, bool $splitEachChar): void
    {
        $this->removeSpaces = $removeSpaces;
        $this->splitEachChar = $splitEachChar;
    }

    /**
     * Set right-to-left display mode.
     *
     * @param bool $rtl Whether the language is right-to-left
     *
     * @return void
     */
    public function setRightToLeft(bool $rtl): void
    {
        $this->rightToLeft = $rtl;
    }

    /**
     * Configure text display size.
     *
     * @param int $percentage Text size percentage (typically 50-200)
     *
     * @return void
     *
     * @throws InvalidArgumentException If percentage is invalid
     */
    public function setTextSize(int $percentage): void
    {
        if ($percentage < 50 || $percentage > 300) {
            throw new InvalidArgumentException('Text size must be between 50 and 300 percent');
        }
        $this->textSize = $percentage;
    }

    /**
     * Configure export template.
     *
     * @param string $template The export template
     *
     * @return void
     */
    public function setExportTemplate(string $template): void
    {
        $this->exportTemplate = trim($template);
    }

    /**
     * Configure TTS (text-to-speech) API.
     *
     * @param string $apiUrl TTS API URL
     *
     * @return void
     */
    public function configureTts(string $apiUrl): void
    {
        $this->ttsVoiceApi = trim($apiUrl);
    }

    /**
     * Set whether to show romanization.
     *
     * @param bool $show Whether to show romanization
     *
     * @return void
     */
    public function setShowRomanization(bool $show): void
    {
        $this->showRomanization = $show;
    }

    // Query methods

    /**
     * Check if this is a CJK-style language (no spaces between words).
     *
     * @return bool
     */
    public function isCjkStyle(): bool
    {
        return $this->removeSpaces || $this->splitEachChar;
    }

    /**
     * Get the explicitly set parser type.
     *
     * @return string|null Parser type or null if not set
     */
    public function parserType(): ?string
    {
        return $this->parserType;
    }

    /**
     * Get the effective parser type, deriving from settings if not explicitly set.
     *
     * @return string Parser type ('regex', 'character', 'mecab')
     */
    public function getEffectiveParserType(): string
    {
        // Use explicit parser type if set
        if ($this->parserType !== null && $this->parserType !== '') {
            return $this->parserType;
        }

        // Legacy detection: check magic word in regexpWordCharacters
        if (strtoupper(trim($this->regexpWordCharacters)) === 'MECAB') {
            return 'mecab';
        }

        // Legacy detection: check splitEachChar flag
        if ($this->splitEachChar) {
            return 'character';
        }

        return 'regex';
    }

    /**
     * Set the parser type.
     *
     * @param string|null $parserType Parser type (regex, character, mecab, etc.)
     *
     * @return void
     */
    public function setParserType(?string $parserType): void
    {
        $this->parserType = $parserType !== '' ? $parserType : null;
    }

    /**
     * Check if the language has a translator configured.
     *
     * @return bool
     */
    public function hasTranslator(): bool
    {
        return $this->translatorUri !== '';
    }

    /**
     * Check if the language has a secondary dictionary.
     *
     * @return bool
     */
    public function hasSecondaryDictionary(): bool
    {
        return $this->dict2Uri !== '';
    }

    /**
     * Check if the language has an export template.
     *
     * @return bool
     */
    public function hasExportTemplate(): bool
    {
        return $this->exportTemplate !== '';
    }

    /**
     * Check if TTS is configured.
     *
     * @return bool
     */
    public function hasTts(): bool
    {
        return $this->ttsVoiceApi !== '';
    }

    /**
     * Get dictionary URL for a word.
     *
     * @param string $word     The word to look up
     * @param int    $dictNum  Which dictionary (1 or 2)
     *
     * @return string The URL with word substituted
     */
    public function getDictionaryUrl(string $word, int $dictNum = 1): string
    {
        $uri = $dictNum === 2 ? $this->dict2Uri : $this->dict1Uri;
        $encodedWord = $word === '' ? '+' : urlencode($word);
        // Only support lwt_term placeholder; ### is no longer supported
        if (str_contains($uri, 'lwt_term')) {
            return str_replace('lwt_term', $encodedWord, $uri);
        }
        // No placeholder - append word to URL
        return $uri . $encodedWord;
    }

    /**
     * Get translator URL for a word.
     *
     * @param string $word The word to translate
     *
     * @return string The URL with word substituted
     */
    public function getTranslatorUrl(string $word): string
    {
        $encodedWord = $word === '' ? '+' : urlencode($word);
        // Only support lwt_term placeholder; ### is no longer supported
        if (str_contains($this->translatorUri, 'lwt_term')) {
            return str_replace('lwt_term', $encodedWord, $this->translatorUri);
        }
        // No placeholder - append word to URL
        return $this->translatorUri . $encodedWord;
    }

    /**
     * Get RTL direction attribute for HTML.
     *
     * @return string ' dir="rtl" ' or empty string
     */
    public function getDirectionAttribute(): string
    {
        return $this->rightToLeft ? ' dir="rtl" ' : '';
    }

    /**
     * Export word data as a JSON dictionary for JavaScript.
     *
     * @return string|false JSON dictionary or false on error
     */
    public function exportJsDict(): string|false
    {
        return json_encode([
            'lgid'               => $this->id->toInt(),
            'dict1uri'           => $this->dict1Uri,
            'dict2uri'           => $this->dict2Uri,
            'translator'         => $this->translatorUri,
            'dict1popup'         => $this->dict1PopUp,
            'dict2popup'         => $this->dict2PopUp,
            'translatorpopup'    => $this->translatorPopUp,
            'sourcelang'         => $this->sourceLang,
            'targetlang'         => $this->targetLang,
            'exporttemplate'     => $this->exportTemplate,
            'textsize'           => $this->textSize,
            'charactersubst'     => $this->characterSubstitutions,
            'regexpsplitsent'    => $this->regexpSplitSentences,
            'exceptionsplitsent' => $this->exceptionsSplitSentences,
            'regexpwordchar'     => $this->regexpWordCharacters,
            'removespaces'       => $this->removeSpaces,
            'spliteachchar'      => $this->splitEachChar,
            'rightoleft'         => $this->rightToLeft,
            'ttsvoiceapi'        => $this->ttsVoiceApi,
            'showromanization'   => $this->showRomanization,
            'localdictmode'      => $this->localDictMode,
            'pipervoiceid'       => $this->piperVoiceId,
        ]);
    }

    // Getters

    public function id(): LanguageId
    {
        return $this->id;
    }

    public function name(): string
    {
        return $this->name;
    }

    public function dict1Uri(): string
    {
        return $this->dict1Uri;
    }

    public function dict2Uri(): string
    {
        return $this->dict2Uri;
    }

    public function translatorUri(): string
    {
        return $this->translatorUri;
    }

    /**
     * Check if dictionary 1 should open in a popup window.
     *
     * @return bool
     */
    public function isDict1PopUp(): bool
    {
        return $this->dict1PopUp;
    }

    /**
     * Check if dictionary 2 should open in a popup window.
     *
     * @return bool
     */
    public function isDict2PopUp(): bool
    {
        return $this->dict2PopUp;
    }

    /**
     * Check if translator should open in a popup window.
     *
     * @return bool
     */
    public function isTranslatorPopUp(): bool
    {
        return $this->translatorPopUp;
    }

    /**
     * Get the source language code (BCP 47).
     *
     * @return string|null Source language code or null if not set
     */
    public function sourceLang(): ?string
    {
        return $this->sourceLang;
    }

    /**
     * Get the target language code (BCP 47).
     *
     * @return string|null Target language code or null if not set
     */
    public function targetLang(): ?string
    {
        return $this->targetLang;
    }

    /**
     * Configure popup settings for dictionaries.
     *
     * @param bool $dict1     Dictionary 1 opens in popup
     * @param bool $dict2     Dictionary 2 opens in popup
     * @param bool $translator Translator opens in popup
     *
     * @return void
     */
    public function configureDictionaryPopups(bool $dict1, bool $dict2, bool $translator): void
    {
        $this->dict1PopUp = $dict1;
        $this->dict2PopUp = $dict2;
        $this->translatorPopUp = $translator;
    }

    /**
     * Configure translator language codes.
     *
     * @param string|null $sourceLang Source language code (BCP 47)
     * @param string|null $targetLang Target language code (BCP 47)
     *
     * @return void
     */
    public function configureTranslatorLanguages(?string $sourceLang, ?string $targetLang): void
    {
        $this->sourceLang = $sourceLang !== '' ? $sourceLang : null;
        $this->targetLang = $targetLang !== '' ? $targetLang : null;
    }

    public function exportTemplate(): string
    {
        return $this->exportTemplate;
    }

    public function textSize(): int
    {
        return $this->textSize;
    }

    public function characterSubstitutions(): string
    {
        return $this->characterSubstitutions;
    }

    public function regexpSplitSentences(): string
    {
        return $this->regexpSplitSentences;
    }

    public function exceptionsSplitSentences(): string
    {
        return $this->exceptionsSplitSentences;
    }

    public function regexpWordCharacters(): string
    {
        return $this->regexpWordCharacters;
    }

    public function removeSpaces(): bool
    {
        return $this->removeSpaces;
    }

    public function splitEachChar(): bool
    {
        return $this->splitEachChar;
    }

    public function rightToLeft(): bool
    {
        return $this->rightToLeft;
    }

    public function ttsVoiceApi(): string
    {
        return $this->ttsVoiceApi;
    }

    public function showRomanization(): bool
    {
        return $this->showRomanization;
    }

    /**
     * Get the local dictionary mode.
     *
     * @return int Mode (0=online only, 1=local first, 2=local only, 3=combined)
     */
    public function localDictMode(): int
    {
        return $this->localDictMode;
    }

    /**
     * Set the local dictionary mode.
     *
     * @param int $mode Mode (0=online only, 1=local first, 2=local only, 3=combined)
     *
     * @return void
     *
     * @throws \InvalidArgumentException If mode is invalid
     */
    public function setLocalDictMode(int $mode): void
    {
        if ($mode < 0 || $mode > 3) {
            throw new \InvalidArgumentException('Local dictionary mode must be between 0 and 3');
        }
        $this->localDictMode = $mode;
    }

    /**
     * Get the Piper TTS voice ID.
     *
     * @return string|null Piper voice ID or null if not set
     */
    public function piperVoiceId(): ?string
    {
        return $this->piperVoiceId;
    }

    /**
     * Set the Piper TTS voice ID.
     *
     * @param string|null $voiceId Piper voice ID (e.g., "en_US-lessac-medium")
     *
     * @return void
     */
    public function setPiperVoiceId(?string $voiceId): void
    {
        $this->piperVoiceId = $voiceId !== '' ? $voiceId : null;
    }

    /**
     * Check if Piper TTS is configured.
     *
     * @return bool
     */
    public function hasPiperTts(): bool
    {
        return $this->piperVoiceId !== null && $this->piperVoiceId !== '';
    }

    /**
     * Internal method to set the ID after persistence.
     *
     * @param LanguageId $id The new ID
     *
     * @return void
     *
     * @internal This method is for repository use only
     */
    public function setId(LanguageId $id): void
    {
        if (!$this->id->isNew()) {
            throw new \LogicException('Cannot change ID of a persisted language');
        }
        $this->id = $id;
    }
}
