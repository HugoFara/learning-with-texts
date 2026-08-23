<?php

/**
 * Get Language Code Use Case
 *
 * PHP version 8.1
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

use Lwt\Shared\Infrastructure\Http\UrlUtilities;
use Lwt\Modules\Language\Domain\LanguageRepositoryInterface;
use Lwt\Modules\Language\Infrastructure\MySqlLanguageRepository;

/**
 * Use case for resolving language codes.
 *
 * @since 3.0.0
 */
class GetLanguageCode
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
     * Get language name from its ID.
     *
     * @param string|int $id Language ID
     *
     * @return string Language name, empty string if not found
     */
    public function getLanguageName($id): string
    {
        if (is_int($id)) {
            $lg_id = $id;
        } elseif (trim($id) != '' && ctype_digit($id)) {
            $lg_id = (int) $id;
        } else {
            return '';
        }

        return $this->repository->getName($lg_id) ?? '';
    }

    /**
     * Try to get language code from its ID.
     *
     * @param int   $id             Language ID
     * @param array<string, array{0: string, 1: string, 2: bool, 3: string,
     *               4: string, 5: bool, 6: bool, 7: bool, 8: string}> $languagesTable
     *               Table of languages, usually from LanguagePresets::getAll()
     *
     * @return string Two-letter code (e.g., BCP 47) or empty string
     */
    public function execute(int $id, array $languagesTable): string
    {
        $language = $this->repository->find($id);

        if ($language === null) {
            return '';
        }

        $lgName = $language->name();
        $translatorUri = $language->translatorUri();

        // If we are using a standard language name, use it
        if (array_key_exists($lgName, $languagesTable)) {
            return $languagesTable[$lgName][1];
        }

        // Otherwise, use the translator URL
        $lgFromDict = UrlUtilities::langFromDict($translatorUri);
        if ($lgFromDict != '') {
            return $lgFromDict;
        }
        return '';
    }

    /**
     * Return a right-to-left direction indication in HTML if language is RTL.
     *
     * @param string|int|null $id Language ID
     *
     * @return string ' dir="rtl" ' or empty string
     *
     * @psalm-return ' dir="rtl" '|''
     */
    public function getScriptDirectionTag($id): string
    {
        if (!isset($id)) {
            return '';
        }
        if (is_string($id)) {
            if (trim($id) == '' || !is_numeric($id)) {
                return '';
            }
            $lg_id = (int) $id;
        } else {
            $lg_id = $id;
        }

        if ($this->repository->isRightToLeft($lg_id)) {
            return ' dir="rtl" ';
        }
        return '';
    }
}
