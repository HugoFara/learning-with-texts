<?php

/**
 * Dictionary Adapter - External dictionary integration
 *
 * PHP version 8.2
 *
 * @category Lwt
 * @package  Lwt\Shared\Infrastructure\Dictionary
 * @author   HugoFara <git@hugofara.net>
 * @license  Unlicense <http://unlicense.org/>
 * @link     https://hugofara.github.io/lwt/developer/api
 * @since    3.0.0
 */

declare(strict_types=1);

namespace Lwt\Shared\Infrastructure\Dictionary;

use Lwt\Shared\Infrastructure\Database\QueryBuilder;
use Lwt\Modules\Dictionary\Application\Services\LocalDictionaryService;

/**
 * Adapter for external dictionary services.
 *
 * Handles dictionary link generation and lookup functionality.
 *
 * @since 3.0.0
 */
class DictionaryAdapter
{
    /**
     * Create and verify a dictionary URL link.
     *
     * Case 1: url without lwt_term: append UTF-8-term
     * Case 2: url with lwt_term: substitute UTF-8-term
     *
     * @param string $url  Dictionary URL. It may contain 'lwt_term' placeholder
     * @param string $term Text that substitutes the 'lwt_term'
     *
     * @return string Dictionary link formatted
     */
    public static function createDictLink(string $url, string $term): string
    {
        $url = trim($url);
        $term = trim($term);
        $encodedTerm = $term === '' ? '+' : urlencode($term);

        // Check for lwt_term placeholder
        if (str_contains($url, 'lwt_term')) {
            return str_replace('lwt_term', $encodedTerm, $url);
        }

        // No placeholder found - append term to URL
        return $url . $encodedTerm;
    }

    /**
     * Get dictionary URIs for a language.
     *
     * @param int $langId Language ID
     *
     * @return array{dict1: string, dict2: string, translator: string, popup1: bool, popup2: bool, popup3: bool}
     */
    public function getLanguageDictionaries(int $langId): array
    {
        $record = QueryBuilder::table('languages')
            ->select([
                'LgDict1URI', 'LgDict2URI', 'LgGoogleTranslateURI',
                'LgDict1PopUp', 'LgDict2PopUp', 'LgGoogleTranslatePopUp'
            ])
            ->where('LgID', '=', $langId)
            ->firstPrepared();

        return [
            'dict1' => (string) ($record['LgDict1URI'] ?? ''),
            'dict2' => (string) ($record['LgDict2URI'] ?? ''),
            'translator' => (string) ($record['LgGoogleTranslateURI'] ?? ''),
            'popup1' => (bool) ($record['LgDict1PopUp'] ?? false),
            'popup2' => (bool) ($record['LgDict2PopUp'] ?? false),
            'popup3' => (bool) ($record['LgGoogleTranslatePopUp'] ?? false),
        ];
    }

    /**
     * Get the local dictionary mode for a language.
     *
     * @param int $langId Language ID
     *
     * @return int Mode (0=online only, 1=local first, 2=local only, 3=combined)
     */
    public function getLocalDictMode(int $langId): int
    {
        $record = QueryBuilder::table('languages')
            ->select(['LgLocalDictMode'])
            ->where('LgID', '=', $langId)
            ->firstPrepared();

        return (int) ($record['LgLocalDictMode'] ?? 0);
    }

    /**
     * Look up a term with local dictionary support.
     *
     * @param int    $langId Language ID
     * @param string $term   Term to look up
     *
     * @return array{local: array, online: array{dict1: string, dict2: string, translator: string}}
     */
    public function lookupWithLocal(int $langId, string $term): array
    {
        $mode = $this->getLocalDictMode($langId);
        $localService = new LocalDictionaryService();

        $results = [
            'local' => [],
            'online' => ['dict1' => '', 'dict2' => '', 'translator' => ''],
        ];

        // Modes 1, 2, 3 include local lookup
        if ($mode >= 1) {
            $results['local'] = $localService->lookup($langId, $term);
        }

        // Modes 0, 1, 3 include online dictionaries
        // Mode 1: only include online if local found nothing
        if ($mode === 0 || $mode === 3 || ($mode === 1 && count($results['local']) === 0)) {
            $dicts = $this->getLanguageDictionaries($langId);
            $results['online'] = [
                'dict1' => $dicts['dict1'],
                'dict2' => $dicts['dict2'],
                'translator' => $dicts['translator'],
            ];
        }

        return $results;
    }

    /**
     * Create dictionary links HTML for edit window.
     *
     * @param int    $langId    Language ID
     * @param string $word      Word to look up
     * @param string $sentctlid ID of the sentence textarea element
     * @param bool   $openFirst True if we should open right frames with translation first
     *
     * @return string HTML-formatted interface
     */
    public function createDictLinksInEditWin(
        int $langId,
        string $word,
        string $sentctlid,
        bool $openFirst
    ): string {
        $dicts = $this->getLanguageDictionaries($langId);
        $wb1 = $dicts['dict1'];
        $wb2 = $dicts['dict2'];
        $wb3 = $dicts['translator'];
        $popup1 = $dicts['popup1'];
        $popup2 = $dicts['popup2'];
        $popup3 = $dicts['popup3'];

        $r = '';
        $dictUrl = self::createDictLink($wb1, $word);
        if ($openFirst) {
            $action = $popup1 ? 'dict-auto-popup' : 'dict-auto-frame';
            $r .= '<span class="dict-auto-init" data-action="' . $action . '" data-url="' .
            htmlspecialchars($dictUrl, ENT_QUOTES, 'UTF-8') . '"></span>';
        }
        $r .= 'Lookup Term: ';
        $r .= $this->makeOpenDictStr($dictUrl, "Dict1", $popup1);
        if ($wb2 != "") {
            $r .= $this->makeOpenDictStr(self::createDictLink($wb2, $word), "Dict2", $popup2);
        }
        if ($wb3 != "") {
            $r .= $this->makeOpenDictStr(self::createDictLink($wb3, $word), "Translator", $popup3) .
            ' | ' .
            $this->makeOpenDictStrDynSent($wb3, $sentctlid, "Translate sentence", $popup3);
        }
        return $r;
    }

    /**
     * Create a dictionary open URL HTML element.
     *
     * @param string $url   The dictionary URL
     * @param string $txt   Clickable text to display
     * @param bool   $popup Whether to open in popup window
     *
     * @return string HTML-formatted string
     */
    public function makeOpenDictStr(string $url, string $txt, bool $popup = false): string
    {
        if ($url === '' || $txt === '') {
            return '';
        }
        if ($popup) {
            return ' <span class="click" data-action="dict-popup" data-url="' .
            htmlspecialchars($url, ENT_QUOTES, 'UTF-8') . '">' .
            htmlspecialchars($txt, ENT_QUOTES, 'UTF-8') .
            '</span> ';
        }
        return ' <a href="' . htmlspecialchars($url, ENT_QUOTES, 'UTF-8') .
        '" target="ru" data-action="dict-frame">' .
        htmlspecialchars($txt, ENT_QUOTES, 'UTF-8') . '</a> ';
    }

    /**
     * Create a dictionary open URL HTML element for dynamic sentence translation.
     *
     * @param string $url       Translator URL
     * @param string $sentctlid ID of the textarea element containing the sentence
     * @param string $txt       Clickable text to display
     * @param bool   $popup     Whether to open in popup window
     *
     * @return string HTML-formatted string
     */
    public function makeOpenDictStrDynSent(
        string $url,
        string $sentctlid,
        string $txt,
        bool $popup = false
    ): string {
        if ($url === '') {
            return '';
        }
        $parsed_url = parse_url($url);
        if ($parsed_url === false) {
            $parsed_url = parse_url('http://' . $url);
        }
        // Handle ggl.php translator
        if (
            str_starts_with($url, "ggl.php")
            || str_ends_with($parsed_url['path'] ?? '', "/ggl.php")
        ) {
            $url = str_replace('?', '?sent=1&', $url);
        }
        $action = $popup ? 'translate-sentence-popup' : 'translate-sentence';
        return '<span class="click" data-action="' . $action . '" ' .
        'data-url="' . htmlspecialchars($url, ENT_QUOTES, 'UTF-8') . '" ' .
        'data-sentctl="' . htmlspecialchars($sentctlid, ENT_QUOTES, 'UTF-8') . '">' .
        htmlspecialchars($txt, ENT_QUOTES, 'UTF-8') . '</span>';
    }

    /**
     * Returns dictionary links formatted as HTML (popup version).
     *
     * @param int    $langId    Language ID
     * @param string $sentctlid ID of the sentence textarea element
     * @param string $wordctlid ID of the word input element
     *
     * @return string HTML formatted interface
     */
    public function createDictLinksInEditWin2(int $langId, string $sentctlid, string $wordctlid): string
    {
        $dicts = $this->getLanguageDictionaries($langId);
        $wb1 = $dicts['dict1'];
        $wb2 = $dicts['dict2'];
        $wb3 = $dicts['translator'];

        $r = 'Lookup Term:
        <span class="click" data-action="translate-word-popup" ' .
        'data-url="' . htmlspecialchars($wb1, ENT_QUOTES, 'UTF-8') . '" ' .
        'data-wordctl="' . htmlspecialchars($wordctlid, ENT_QUOTES, 'UTF-8') . '">Dict1</span> ';
        if ($wb2 !== '') {
            $r .= '<span class="click" data-action="translate-word-popup" ' .
            'data-url="' . htmlspecialchars($wb2, ENT_QUOTES, 'UTF-8') . '" ' .
            'data-wordctl="' . htmlspecialchars($wordctlid, ENT_QUOTES, 'UTF-8') . '">Dict2</span> ';
        }
        if ($wb3 !== '') {
            $parsed_url = parse_url($wb3);
            $sentUrl = (str_starts_with($wb3, 'ggl.php') ||
                str_ends_with($parsed_url['path'] ?? '', '/ggl.php'))
                ? str_replace('?', '?sent=1&', $wb3) : $wb3;
            $r .= '<span class="click" data-action="translate-word-popup" ' .
            'data-url="' . htmlspecialchars($wb3, ENT_QUOTES, 'UTF-8') . '" ' .
            'data-wordctl="' . htmlspecialchars($wordctlid, ENT_QUOTES, 'UTF-8') . '">Translator</span>
             | <span class="click" data-action="translate-sentence-popup" ' .
            'data-url="' . htmlspecialchars($sentUrl, ENT_QUOTES, 'UTF-8') . '" ' .
            'data-sentctl="' . htmlspecialchars($sentctlid, ENT_QUOTES, 'UTF-8') . '">Translate sentence</span>';
        }
        return $r;
    }

    /**
     * Make dictionary links HTML.
     *
     * @param int    $langId Language ID
     * @param string $word   The word to translate
     *
     * @return string HTML formatted links
     */
    public function makeDictLinks(int $langId, string $word): string
    {
        $dicts = $this->getLanguageDictionaries($langId);
        $wb1 = $dicts['dict1'];
        $wb2 = $dicts['dict2'];
        $wb3 = $dicts['translator'];
        $escapedWord = htmlspecialchars($word, ENT_QUOTES, 'UTF-8');
        $r = '<span class="is-size-7">';
        $r .= '<span class="click" data-action="translate-word-direct" ' .
        'data-url="' . htmlspecialchars($wb1, ENT_QUOTES, 'UTF-8') . '" ' .
        'data-word="' . $escapedWord . '">[1]</span> ';
        if ($wb2 !== '') {
            $r .= '<span class="click" data-action="translate-word-direct" ' .
            'data-url="' . htmlspecialchars($wb2, ENT_QUOTES, 'UTF-8') . '" ' .
            'data-word="' . $escapedWord . '">[2]</span> ';
        }
        if ($wb3 !== '') {
            $r .= '<span class="click" data-action="translate-word-direct" ' .
            'data-url="' . htmlspecialchars($wb3, ENT_QUOTES, 'UTF-8') . '" ' .
            'data-word="' . $escapedWord . '">[G]</span>';
        }
        $r .= '</span>';
        return $r;
    }

    /**
     * Create dictionary links for edit window (version 3).
     *
     * @param int    $langId    Language ID
     * @param string $sentctlid ID of the sentence textarea element
     * @param string $wordctlid ID of the word input element
     *
     * @return string HTML formatted interface
     */
    public function createDictLinksInEditWin3(int $langId, string $sentctlid, string $wordctlid): string
    {
        $dicts = $this->getLanguageDictionaries($langId);
        $wb1 = $dicts['dict1'];
        $wb2 = $dicts['dict2'];
        $wb3 = $dicts['translator'];
        $popup1 = $dicts['popup1'];
        $popup2 = $dicts['popup2'];
        $popup3 = $dicts['popup3'];

        // Handle ggl.php translator for sentence mode
        $parsed_url = parse_url($wb3);
        if ($wb3 !== '' && $parsed_url === false) {
            $parsed_url = parse_url('http://' . $wb3);
        }
        $sentUrl = (str_ends_with($parsed_url['path'] ?? '', "/ggl.php")) ?
            str_replace('?', '?sent=1&', $wb3) : $wb3;

        $r = '';
        $r .= 'Lookup Term: ';
        $action1 = $popup1 ? 'translate-word-popup' : 'translate-word';
        $r .= '<span class="click" data-action="' . $action1 . '" ' .
        'data-url="' . htmlspecialchars($wb1, ENT_QUOTES, 'UTF-8') . '" ' .
        'data-wordctl="' . htmlspecialchars($wordctlid, ENT_QUOTES, 'UTF-8') . '">
        Dictionary 1</span> ';
        if ($wb2 !== '') {
            $action2 = $popup2 ? 'translate-word-popup' : 'translate-word';
            $r .= '<span class="click" data-action="' . $action2 . '" ' .
            'data-url="' . htmlspecialchars($wb2, ENT_QUOTES, 'UTF-8') . '" ' .
            'data-wordctl="' . htmlspecialchars($wordctlid, ENT_QUOTES, 'UTF-8') . '">
            Dictionary 2</span> ';
        }
        if ($wb3 !== '') {
            $action3 = $popup3 ? 'translate-word-popup' : 'translate-word';
            $action4 = $popup3 ? 'translate-sentence-popup' : 'translate-sentence';
            $r .= '<span class="click" data-action="' . $action3 . '" ' .
            'data-url="' . htmlspecialchars($wb3, ENT_QUOTES, 'UTF-8') . '" ' .
            'data-wordctl="' . htmlspecialchars($wordctlid, ENT_QUOTES, 'UTF-8') . '">
            Translator</span> |
            <span class="click" data-action="' . $action4 . '" ' .
            'data-url="' . htmlspecialchars($sentUrl, ENT_QUOTES, 'UTF-8') . '" ' .
            'data-sentctl="' . htmlspecialchars($sentctlid, ENT_QUOTES, 'UTF-8') . '">
            Translate sentence</span>';
        }
        return $r;
    }
}
