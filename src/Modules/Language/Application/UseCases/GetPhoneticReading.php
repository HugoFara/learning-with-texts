<?php

/**
 * Get Phonetic Reading Use Case
 *
 * PHP version 8.2
 *
 * @category Lwt
 * @package  Lwt\Modules\Language\Application\UseCases
 * @author   HugoFara <git@hugofara.net>
 * @license  Unlicense <http://unlicense.org/>
 * @link     https://hugofara.github.io/lwt/developer/api
 * @since    3.0.0
 */

declare(strict_types=1);

namespace Lwt\Modules\Language\Application\UseCases;

use Lwt\Modules\Language\Domain\LanguageRepositoryInterface;
use Lwt\Modules\Language\Domain\WordSpacing;
use Lwt\Modules\Language\Infrastructure\MySqlLanguageRepository;
use Lwt\Modules\Language\Application\Services\TextParsingService;

/**
 * Use case for converting text to phonetic representation.
 *
 * @since 3.0.0
 */
class GetPhoneticReading
{
    private LanguageRepositoryInterface $repository;

    /**
     * @param LanguageRepositoryInterface|null $repository Repository instance
     */
    public function __construct(?LanguageRepositoryInterface $repository = null)
    {
        $this->repository = $repository ?? new MySqlLanguageRepository();
    }

    /**
     * Convert text to phonetic representation using MeCab (for Japanese).
     *
     * @param string $text Text to be converted
     * @param int    $id   Language ID
     *
     * @return string Parsed text in phonetic format
     */
    public function execute(string $text, int $id): string
    {
        $wordCharacters = $this->repository->getWordCharacters($id);

        // For now we only support phonetic text with MeCab. Every other
        // reader normalises before comparing; this one did not, so a field
        // holding MECAB in any other casing silently returned the input
        // unchanged (#288).
        if (!WordSpacing::usesMecabMagicWord((string) $wordCharacters)) {
            return $text;
        }

        return $this->processMecabPhonetic($text);
    }

    /**
     * Convert text to phonetic representation by language code.
     *
     * @param string $text Text to be converted
     * @param string $lang Language code (usually BCP 47 or ISO 639-1)
     *
     * @return string Parsed text in phonetic format
     */
    public function getByCode(string $text, string $lang): string
    {
        // Many languages are already phonetic
        if (!str_starts_with($lang, "ja") && !str_starts_with($lang, "jp")) {
            return $text;
        }

        return $this->processMecabPhonetic($text);
    }

    /**
     * Process text through MeCab for phonetic reading.
     *
     * @param string $text Text to process
     *
     * @return string Phonetic reading from MeCab
     */
    private function processMecabPhonetic(string $text): string
    {
        $mecab_file = sys_get_temp_dir() . "/lwt_mecab_to_db.txt";
        $mecab_args = ' -O yomi ';
        if (file_exists($mecab_file)) {
            unlink($mecab_file);
        }
        $fp = fopen($mecab_file, 'w');
        if ($fp === false) {
            return '';
        }
        fwrite($fp, $text . "\n");
        fclose($fp);
        $mecab = (new TextParsingService())->getMecabPath($mecab_args);
        $handle = popen($mecab . escapeshellarg($mecab_file), "r");
        if ($handle === false) {
            unlink($mecab_file);
            return '';
        }
        $mecab_str = '';
        while (($line = fgets($handle, 4096)) !== false) {
            $mecab_str .= $line;
        }
        if (!feof($handle)) {
            echo "Error: unexpected fgets() fail\n";
        }
        pclose($handle);
        unlink($mecab_file);
        return $mecab_str;
    }
}
