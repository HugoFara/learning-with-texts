<?php

/**
 * \file
 * \brief Defines GoogleTranslateClient class for word translation
 *
 * Usage:
 * use Lwt\Modules\Dictionary\Infrastructure\Translation\GoogleTranslateClient;
 *
 * $translations = GoogleTranslateClient::staticTranslate('Hello','en','de');
 *
 * if(!$translations)
 *      echo 'Error: No translation found!';
 * else
 *      foreach($translations as $transl){
 *          echo $transl, '<br />';
 *      }
 *
 * PHP version 8.2
 *
 * @category Lwt
 * @package  Lwt
 */

declare(strict_types=1);

namespace Lwt\Modules\Dictionary\Infrastructure\Translation;

/**
 * Wrapper class to get translation.
 *
 * See staticTranslate for a clssical translation.
 *
 * @author   LWT Project <lwt-project@hotmail.com>
 * @license  Unlicense <http://unlicense.org/>
 * @link     https://hugofara.github.io/lwt/developer/api
 */
class GoogleTranslateClient
{
    /**
     * @var ''|list<string>|false
     *
     * @psalm-suppress PossiblyUnusedProperty - Public property for external access
     */
    public array|string|false $lastResult = "";

    private ?string $langFrom = null;

    private ?string $langTo = null;
    /** @var list<string>|null */
    private static ?array $headers = null;

    private static string $urlFormat =
        "https://translate.googleapis.com/translate_a/single" .
        "?client=gtx&q=%s&hl=en&sl=%s&tl=%s&dt=t&dt=at&dt=bd&ie=UTF-8&oe=UTF-8";

    private static function setHeaders(): void
    {
        self::$headers = array(
            'Accept: text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8',
            'Accept-Language: en-US,en',
            'Connection: keep-alive',
            'Host: translate.googleapis.com',
            'Referer: https://translate.googleapis.com/',
            'User-Agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:128.0) Gecko/20100101 Firefox/128.0'
        );
    }
    /**
     * Return the current domain.
     *
     * @param string|void $domain (Optionnal) Google Translate domain to use.
     *                            * Usually two letters (e.g "en" or "com")
     *                            * Random if not provided.
     *
     * @return string
     */
    public static function getDomain($domain): string
    {
        $loc = array(
            'com.ar', 'at', 'com.au', 'be', 'com.br', 'ca', 'cat', 'ch', 'cl', 'cn',
            'cz', 'de', 'dk', 'es', 'fi', 'fr', 'gr', 'com.hk', 'hr', 'hu', 'co.id',
            'ie', 'co.il', 'im', 'co.in', 'it', 'co.jp', 'co.kr', 'com.mx',
            'nl', 'no', 'pl', 'pt', 'ru', 'se', 'com.sg', 'co.th', 'com.tw',
            'co.uk', 'com'
        );
        if ($domain === null || $domain === '' || !in_array($domain, $loc, true)) {
            return $loc[mt_rand(0, count($loc) - 1)];
        }
        return $domain;
    }
    /**
     * Case-insensitive array unique.
     *
     * @param array<array-key, string> $array
     *
     * @return array<array-key, string>
     */
    public static function arrayIunique(array $array): array
    {
        return array_intersect_key(
            $array,
            array_unique(array_map("strtolower", $array))
        );
    }
    public function setLangFrom(?string $lang): static
    {
        $this->langFrom = $lang;
        return $this;
    }
    public function setLangTo(string $lang): static
    {
        $this->langTo = $lang;
        return $this;
    }
    public function __construct(string|null $from, string $to)
    {
        $this->setLangFrom($from)->setLangTo($to);
    }
    public static function makeCurl(string $url, bool $cookieSet = false): string|bool
    {
        if (is_callable('curl_init')) {
            if (!$cookieSet) {
                $cookie = tempnam(sys_get_temp_dir(), "CURLCOOKIE");
                if ($cookie === false) {
                    throw new \RuntimeException('Failed to create temporary cookie file');
                }
                try {
                    $curl = curl_init($url);
                    if ($curl === false) {
                        return false;
                    }
                    curl_setopt($curl, CURLOPT_COOKIEJAR, $cookie);
                    curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
                    curl_setopt($curl, CURLOPT_HTTPHEADER, self::$headers ?? []);
                    curl_setopt($curl, CURLOPT_ENCODING, "gzip");
                    $output = curl_exec($curl);
                    unset($curl);
                    return $output;
                } finally {
                    if (file_exists($cookie)) {
                        unlink($cookie);
                    }
                }
            }
            $curl = curl_init($url);
            if ($curl === false) {
                return false;
            }
            curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($curl, CURLOPT_HTTPHEADER, self::$headers ?? []);
            curl_setopt($curl, CURLOPT_ENCODING, "gzip");
            $output = curl_exec($curl);
            unset($curl);
        } else {
            $headerStr = self::$headers !== null ? implode("\r\n", self::$headers) . "\r\n" : "";
            $ctx = stream_context_create(
                array(
                    "http" => array(
                        "method" => "GET",
                        "header" => $headerStr
                    )
                )
            );
            $output = file_get_contents($url, false, $ctx);
        }
        return $output;
    }
    /**
     * @return false|string[]
     *
     * @psalm-return list<string>|false
     */
    public function translate(string $string): array|false
    {
        if ($this->langFrom === null || $this->langTo === null) {
            return false;
        }
        $result = self::staticTranslate(
            $string,
            $this->langFrom,
            $this->langTo
        );
        $this->lastResult = $result === false ? false : array_values($result);
        return $this->lastResult;
    }
    /**
     * Returns an array of Translations
     *
     * @param string $string Word to translate
     * @param string $from   Source language code (i.e. en,de,fr,...)
     * @param string $to     Target language code (i.e. en,de,fr,...)
     *                       all supported language codes can be found here:
     *                       https://cloud.google.com/translate/docs/basic/discovering-supported-languages#getting_a_list_of_supported_languages
     *
     * @return string[]|false An array of translation, or false if an error occured.
     */
    public static function staticTranslate(
        string $string,
        string $from,
        string $to
    ): array|false {
        self::setHeaders();
        $url = sprintf(
            self::$urlFormat,
            rawurlencode($string),
            $from,
            $to
        );
        $curlResult = self::makeCurl($url);
        if ($curlResult === false || $curlResult === true) {
            return false;
        }
        $result = preg_replace('!([[,])(?=,)!', '$1[]', $curlResult);
        if ($result === null) {
            return false;
        }
        /** @var array<int, mixed>|null $resultArray */
        $resultArray = json_decode($result, true);
        if (!is_array($resultArray)) {
            return false;
        }
        /** @var list<string> $finalResult */
        $finalResult = [];
        if (!empty($resultArray[0]) && is_array($resultArray[0])) {
            /** @var mixed $results */
            foreach ($resultArray[0] as $results) {
                if (is_array($results) && isset($results[0])) {
                    $finalResult[] = (string) $results[0];
                }
            }
            if (!empty($resultArray[1]) && is_array($resultArray[1])) {
                /** @var mixed $v */
                foreach ($resultArray[1] as $v) {
                    if (is_array($v) && isset($v[1]) && is_array($v[1])) {
                        /** @var mixed $results */
                        foreach ($v[1] as $results) {
                            $finalResult[] = (string) $results;
                        }
                    }
                }
            }
            if (!empty($resultArray[5]) && is_array($resultArray[5])) {
                /** @var mixed $v */
                foreach ($resultArray[5] as $v) {
                    if (is_array($v) && isset($v[0]) && $v[0] == $string) {
                        if (isset($v[2]) && is_array($v[2])) {
                            /** @var mixed $results */
                            foreach ($v[2] as $results) {
                                if (is_array($results) && isset($results[0])) {
                                    $finalResult[] = (string) $results[0];
                                }
                            }
                        }
                    }
                }
            }
            return self::arrayIunique($finalResult);
        }
        return false;
    }
}
