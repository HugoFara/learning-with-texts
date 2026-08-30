<?php

/**
 * Get Language By ID Use Case
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

use Lwt\Modules\Language\Domain\Language;
use Lwt\Modules\Language\Domain\LanguageRepositoryInterface;
use Lwt\Modules\Language\Infrastructure\MySqlLanguageRepository;

/**
 * Use case for retrieving a language by ID.
 *
 * @since 3.0.0
 */
class GetLanguageById
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
     * Get a language by ID.
     *
     * @param int $id Language ID
     *
     * @return Language|null Language entity or null if not found
     */
    public function execute(int $id): ?Language
    {
        if ($id <= 0) {
            return $this->repository->createEmpty();
        }
        return $this->repository->find($id);
    }

    /**
     * Create an empty language object with default values.
     *
     * @return Language
     */
    public function createEmpty(): Language
    {
        return $this->repository->createEmpty();
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
        return $this->repository->exists($id);
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
        $view = new \stdClass();
        $view->id = $language->id()->toInt();
        $view->name = $language->name();
        $view->dict1uri = $language->dict1Uri();
        $view->dict2uri = $language->dict2Uri();
        $view->translator = $language->translatorUri();
        $view->dict1popup = $language->isDict1PopUp();
        $view->dict2popup = $language->isDict2PopUp();
        $view->translatorpopup = $language->isTranslatorPopUp();
        $view->sourcelang = $language->sourceLang();
        $view->targetlang = $language->targetLang();
        $view->exporttemplate = $language->exportTemplate();
        $view->textsize = $language->textSize();
        $view->charactersubst = $language->characterSubstitutions();
        $view->regexpsplitsent = $language->regexpSplitSentences();
        $view->exceptionsplitsent = $language->exceptionsSplitSentences();
        $view->regexpwordchar = $language->regexpWordCharacters();
        $view->removespaces = $language->removeSpaces();
        $view->spliteachchar = $language->splitEachChar();
        $view->rightoleft = $language->rightToLeft();
        $view->ttsvoiceapi = $language->ttsVoiceApi();
        $view->showromanization = $language->showRomanization();
        $view->parsertype = $language->parserType();
        $view->localdictmode = $language->localDictMode();
        return $view;
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
        $trimmedName = trim($name);
        if ($trimmedName === '') {
            return false;
        }
        return $this->repository->nameExists(
            $trimmedName,
            $excludeLgId > 0 ? $excludeLgId : null
        );
    }
}
