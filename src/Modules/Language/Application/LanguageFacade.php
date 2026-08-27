<?php

/**
 * Language Facade
 *
 * Backward-compatible facade for language operations.
 * Delegates to use case classes for actual implementation.
 *
 * PHP version 8.1
 *
 * @category Lwt
 * @package  Lwt\Modules\Language\Application
 * @author   HugoFara <git@hugofara.net>
 * @license  Unlicense <http://unlicense.org/>
 * @link     https://hugofara.github.io/lwt/developer/api
 * @since    3.0.0
 */

declare(strict_types=1);

namespace Lwt\Modules\Language\Application;

use Lwt\Modules\Language\Application\UseCases\CreateLanguage;
use Lwt\Modules\Language\Application\UseCases\DeleteLanguage;
use Lwt\Modules\Language\Application\UseCases\GetLanguageById;
use Lwt\Modules\Language\Application\UseCases\GetLanguageCode;
use Lwt\Modules\Language\Application\UseCases\GetPhoneticReading;
use Lwt\Modules\Language\Application\UseCases\ListLanguages;
use Lwt\Modules\Language\Application\UseCases\ReparseLanguageTexts;
use Lwt\Modules\Language\Application\UseCases\UpdateLanguage;
use Lwt\Modules\Language\Domain\Language;
use Lwt\Modules\Language\Domain\LanguageRepositoryInterface;
use Lwt\Modules\Language\Infrastructure\MySqlLanguageRepository;

/**
 * Facade for language module operations.
 *
 * Provides a unified interface to all language-related use cases.
 * Designed for backward compatibility with existing LanguageService callers.
 *
 * @since 3.0.0
 */
class LanguageFacade
{
    protected CreateLanguage $createLanguage;
    protected DeleteLanguage $deleteLanguage;
    protected GetLanguageById $getLanguageById;
    protected GetLanguageCode $getLanguageCode;
    protected GetPhoneticReading $getPhoneticReading;
    protected ListLanguages $listLanguages;
    protected ReparseLanguageTexts $reparseLanguageTexts;
    protected UpdateLanguage $updateLanguage;
    protected LanguageRepositoryInterface $repository;

    /**
     * Constructor.
     *
     * @param LanguageRepositoryInterface|null $repository          Repository instance
     * @param CreateLanguage|null              $createLanguage      Create use case
     * @param DeleteLanguage|null              $deleteLanguage      Delete use case
     * @param GetLanguageById|null             $getLanguageById     Get by ID use case
     * @param GetLanguageCode|null             $getLanguageCode     Get code use case
     * @param GetPhoneticReading|null          $getPhoneticReading  Phonetic reading use case
     * @param ListLanguages|null               $listLanguages       List use case
     * @param ReparseLanguageTexts|null        $reparseLanguageTexts Reparse use case
     * @param UpdateLanguage|null              $updateLanguage      Update use case
     */
    public function __construct(
        ?LanguageRepositoryInterface $repository = null,
        ?CreateLanguage $createLanguage = null,
        ?DeleteLanguage $deleteLanguage = null,
        ?GetLanguageById $getLanguageById = null,
        ?GetLanguageCode $getLanguageCode = null,
        ?GetPhoneticReading $getPhoneticReading = null,
        ?ListLanguages $listLanguages = null,
        ?ReparseLanguageTexts $reparseLanguageTexts = null,
        ?UpdateLanguage $updateLanguage = null
    ) {
        $this->repository = $repository ?? new MySqlLanguageRepository();
        $this->createLanguage = $createLanguage ?? new CreateLanguage();
        $this->deleteLanguage = $deleteLanguage ?? new DeleteLanguage();
        $this->getLanguageById = $getLanguageById ?? new GetLanguageById($this->repository);
        $this->getLanguageCode = $getLanguageCode ?? new GetLanguageCode($this->repository);
        $this->getPhoneticReading = $getPhoneticReading ?? new GetPhoneticReading($this->repository);
        $this->listLanguages = $listLanguages ?? new ListLanguages($this->repository);
        $this->reparseLanguageTexts = $reparseLanguageTexts ?? new ReparseLanguageTexts();
        $this->updateLanguage = $updateLanguage ?? new UpdateLanguage($this->reparseLanguageTexts);
    }

    // =====================
    // GET METHODS
    // =====================

    /**
     * Get all languages as a name => id dictionary.
     *
     * @return array<string, int>
     */
    public function getAllLanguages(): array
    {
        return $this->listLanguages->getAllLanguages();
    }

    /**
     * Get a language by ID.
     *
     * @param int $id Language ID
     *
     * @return Language|null Language entity or null if not found
     */
    public function getById(int $id): ?Language
    {
        return $this->getLanguageById->execute($id);
    }

    /**
     * Create an empty language object with default values.
     *
     * @return Language
     */
    public function createEmptyLanguage(): Language
    {
        return $this->getLanguageById->createEmpty();
    }

    /**
     * Convert a Language entity to a view object (stdClass) for templates.
     *
     * @param Language $language The Language entity
     *
     * @return \stdClass View object with public properties
     */
    public function toViewObject(Language $language): \stdClass
    {
        return $this->getLanguageById->toViewObject($language);
    }

    /**
     * Check if a language exists by ID.
     *
     * @param int $id Language ID
     *
     * @return bool
     */
    public function exists(int $id): bool
    {
        return $this->getLanguageById->exists($id);
    }

    /**
     * Check if a language name is duplicate.
     *
     * @param string $name        Language name
     * @param int    $excludeLgId Language ID to exclude from check (for updates)
     *
     * @return bool
     */
    public function isDuplicateName(string $name, int $excludeLgId = 0): bool
    {
        return $this->getLanguageById->isDuplicateName($name, $excludeLgId);
    }

    // =====================
    // LIST METHODS
    // =====================

    /**
     * Get languages formatted for select dropdown options.
     *
     * @param int $maxNameLength Maximum name length before truncation
     *
     * @return array<int, array{id: int, name: string}>
     */
    public function getLanguagesForSelect(int $maxNameLength = 30): array
    {
        return $this->listLanguages->getLanguagesForSelect($maxNameLength);
    }

    /**
     * Get languages with statistics for display.
     *
     * @return array Language data with counts
     */
    public function getLanguagesWithStats(): array
    {
        return $this->listLanguages->getLanguagesWithStats();
    }

    /**
     * Get languages that have at least one text, with text counts.
     *
     * @return array<int, array{id: int, name: string, text_count: int}>
     */
    public function getLanguagesWithTextCounts(): array
    {
        return $this->listLanguages->getLanguagesWithTextCounts();
    }

    /**
     * Get languages that have at least one archived text, with archived text counts.
     *
     * @return array<int, array{id: int, name: string, text_count: int}>
     */
    public function getLanguagesWithArchivedTextCounts(): array
    {
        return $this->listLanguages->getLanguagesWithArchivedTextCounts();
    }

    /**
     * Get counts of related data for a language.
     *
     * @param int $id Language ID
     *
     * @return array{texts: int, archivedTexts: int, words: int, feeds: int}
     */
    public function getRelatedDataCounts(int $id): array
    {
        return $this->listLanguages->getRelatedDataCounts($id);
    }

    // =====================
    // CREATE/UPDATE/DELETE
    // =====================

    /**
     * Save a new language to the database from request data.
     *
     * @return array{success: bool, id: int}
     */
    public function create(): array
    {
        return $this->createLanguage->execute();
    }

    /**
     * Create a new language from data array (API-friendly version).
     *
     * @param array<string, mixed> $data Language data (camelCase keys)
     *
     * @return int Created language ID, or 0 on failure
     */
    public function createFromData(array $data): int
    {
        return $this->createLanguage->createFromData($data);
    }

    /**
     * Update an existing language from request data.
     *
     * @param int $id Language ID
     *
     * @return array{success: bool, reparsed: ?int, error: ?string}
     */
    public function update(int $id): array
    {
        return $this->updateLanguage->execute($id);
    }

    /**
     * Update an existing language from data array (API-friendly version).
     *
     * @param int   $id   Language ID
     * @param array<string, mixed> $data Language data (camelCase keys)
     *
     * @return array{success: bool, reparsed: int, message: string}
     */
    public function updateFromData(int $id, array $data): array
    {
        return $this->updateLanguage->updateFromData($id, $data);
    }

    /**
     * Delete a language.
     *
     * @param int $id Language ID
     *
     * @return array{success: bool, count: int, error: ?string}
     */
    public function delete(int $id): array
    {
        return $this->deleteLanguage->execute($id);
    }

    /**
     * Delete a language by ID (API-friendly version).
     *
     * @param int $id Language ID
     *
     * @return bool True if deleted
     */
    public function deleteById(int $id): bool
    {
        return $this->deleteLanguage->deleteById($id);
    }

    /**
     * Check if a language can be deleted (no related data).
     *
     * @param int $id Language ID
     *
     * @return bool
     */
    public function canDelete(int $id): bool
    {
        return $this->deleteLanguage->canDelete($id);
    }

    // =====================
    // REPARSE METHODS
    // =====================

    /**
     * Refresh (reparse) all texts for a language.
     *
     * @param int $id Language ID
     *
     * @return array{sentencesDeleted: int, textItemsDeleted: int, sentencesAdded: int, textItemsAdded: int}
     */
    public function refresh(int $id): array
    {
        return $this->reparseLanguageTexts->execute($id);
    }

    /**
     * Refresh (reparse) all texts for a language and return stats.
     *
     * @param int $id Language ID
     *
     * @return array{sentencesDeleted: int, textItemsDeleted: int, sentencesAdded: int, textItemsAdded: int}
     */
    public function refreshTexts(int $id): array
    {
        return $this->reparseLanguageTexts->refreshTexts($id);
    }

    // =====================
    // LANGUAGE CODE METHODS
    // =====================

    /**
     * Get language name from its ID.
     *
     * @param string|int $id Language ID
     *
     * @return string Language name, empty string if not found
     */
    public function getLanguageName($id): string
    {
        return $this->getLanguageCode->getLanguageName($id);
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
    public function getLanguageCode(int $id, array $languagesTable): string
    {
        return $this->getLanguageCode->execute($id, $languagesTable);
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
        return $this->getLanguageCode->getScriptDirectionTag($id);
    }

    // =====================
    // PHONETIC READING
    // =====================

    /**
     * Convert text to phonetic representation using MeCab (for Japanese).
     *
     * @param string $text Text to be converted
     * @param int    $id   Language ID
     *
     * @return string Parsed text in phonetic format
     */
    public function getPhoneticReadingById(string $text, int $id): string
    {
        return $this->getPhoneticReading->execute($text, $id);
    }

    /**
     * Convert text to phonetic representation by language code.
     *
     * @param string $text Text to be converted
     * @param string $lang Language code (usually BCP 47 or ISO 639-1)
     *
     * @return string Parsed text in phonetic format
     */
    public function getPhoneticReadingByCode(string $text, string $lang): string
    {
        return $this->getPhoneticReading->getByCode($text, $lang);
    }

    // =====================
    // REQUEST DATA HELPERS
    // =====================

    /**
     * Get language data from request using InputValidator.
     *
     * @return array<string, string|int|bool|null>
     */
    public function getLanguageDataFromRequest(): array
    {
        return $this->createLanguage->getLanguageDataFromRequest();
    }
}
