<?php

/**
 * TTS Service - Business logic for Text-to-Speech settings
 *
 * PHP version 8.1
 *
 * @category Lwt
 * @package  Lwt\Modules\Admin\Application\Services
 * @author   HugoFara <git@hugofara.net>
 * @license  Unlicense <http://unlicense.org/>
 * @link     https://hugofara.github.io/lwt/developer/api
 * @since    3.0.0
 */

declare(strict_types=1);

namespace Lwt\Modules\Admin\Application\Services;

use Lwt\Shared\Infrastructure\Globals;
use Lwt\Shared\Infrastructure\Http\InputValidator;
use Lwt\Shared\Infrastructure\Http\SecurityHeaders;
use Lwt\Shared\Infrastructure\Database\Settings;
use Lwt\Modules\Language\Application\LanguageFacade;

/**
 * Service class for Text-to-Speech settings.
 *
 * @category Lwt
 * @package  Lwt\Modules\Admin\Application\Services
 * @author   HugoFara <git@hugofara.net>
 * @license  Unlicense <http://unlicense.org/>
 * @link     https://hugofara.github.io/lwt/developer/api
 * @since    3.0.0
 */
class TtsService
{
    /**
     * Language facade instance.
     *
     * @var LanguageFacade
     */
    private LanguageFacade $languageService;

    /**
     * Constructor - initialize dependencies.
     *
     * @param LanguageFacade|null $languageService Language facade (optional for BC)
     */
    public function __construct(?LanguageFacade $languageService = null)
    {
        $this->languageService = $languageService ?? new LanguageFacade();
    }

    /**
     * Get two-letter language code from language ID.
     *
     * @param int   $lgId       Language ID
     * @param array<string, array{
     *     0: string, 1: string, 2: bool, 3: string, 4: string, 5: bool, 6: bool, 7: bool, 8: string
     * }> $langArray Languages array from langdefs
     *
     * @return string Two-letter language code
     */
    public function getLanguageCode(int $lgId, array $langArray): string
    {
        return $this->languageService->getLanguageCode($lgId, $langArray);
    }

    /**
     * Get language ID from two-letter code or BCP 47 tag.
     *
     * @param string                        $code      Two letters, or four letters separated with caret
     * @param array<string, array<string>>  $langArray Languages array from langdefs
     *
     * @return int Language ID if found, -1 otherwise.
     */
    public function getLanguageIdFromCode(string $code, array $langArray): int
    {
        $trimmed = substr($code, 0, 2);
        foreach ($this->languageService->getAllLanguages() as $language => $language_id) {
            if (!isset($langArray[$language])) {
                continue;
            }
            $elem = $langArray[$language];
            if ($elem[0] == $trimmed) {
                return $language_id;
            }
        }
        return -1;
    }

    /**
     * Get language options for TTS form.
     *
     * @param array<string, array{
     *     0: string, 1: string, 2: bool, 3: string, 4: string, 5: bool, 6: bool, 7: bool, 8: string
     * }> $langArray Languages array from langdefs
     *
     * @return string HTML-formatted options string
     */
    public function getLanguageOptions(array $langArray): string
    {
        $output = '';
        foreach ($this->languageService->getAllLanguages() as $language => $language_id) {
            $languageCode = $this->languageService->getLanguageCode($language_id, $langArray);
            $output .= sprintf(
                '<option value="%s">%s</option>',
                htmlspecialchars($languageCode, ENT_QUOTES, 'UTF-8'),
                htmlspecialchars($language, ENT_QUOTES, 'UTF-8')
            );
        }
        return $output;
    }

    /**
     * Get current language code for TTS settings.
     *
     * @param array<string, array{
     *     0: string, 1: string, 2: bool, 3: string, 4: string, 5: bool, 6: bool, 7: bool, 8: string
     * }> $langArray Languages array from langdefs
     *
     * @return string Current language code
     */
    public function getCurrentLanguageCode(array $langArray): string
    {
        $lid = (int)Settings::get('currentlanguage');
        return $this->languageService->getLanguageCode($lid, $langArray);
    }

    /**
     * Save TTS settings as cookies.
     *
     * @return void
     */
    public function saveSettings(): void
    {
        $lgname = InputValidator::getString('LgName');
        $prefix = 'tts[' . $lgname;

        $isSecure = SecurityHeaders::isSecureConnection();

        $cookie_options = array(
            'expires' => strtotime('+5 years'),
            'path' => '/',
            'secure' => $isSecure,  // Only send over HTTPS when available
            'httponly' => false,    // TTS settings need to be readable by JavaScript
            'samesite' => 'Strict'
        );

        setcookie($prefix . 'Voice]', InputValidator::getString('LgVoice'), $cookie_options);
        setcookie($prefix . 'Rate]', InputValidator::getString('LgTTSRate'), $cookie_options);
        setcookie($prefix . 'Pitch]', InputValidator::getString('LgPitch'), $cookie_options);
    }
}
