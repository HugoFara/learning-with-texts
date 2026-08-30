<?php

/**
 * \file
 * \brief Language Controller - Language configuration
 *
 * PHP version 8.2
 *
 * @category Lwt
 * @package  Lwt\Modules\Language\Http
 * @author   HugoFara <git@hugofara.net>
 * @license Unlicense <http://unlicense.org/>
 * @link    https://hugofara.github.io/lwt/developer/api
 * @since   3.0.0
 */

declare(strict_types=1);

namespace Lwt\Modules\Language\Http;

use Lwt\Shared\Http\BaseController;
use Lwt\Shared\UI\Helpers\PageLayoutHelper;
use Lwt\Shared\UI\Helpers\FormHelper;
use Lwt\Shared\UI\Helpers\IconHelper;
use Lwt\Shared\Infrastructure\Database\Settings;
use Lwt\Modules\Language\Application\LanguageFacade;
use Lwt\Modules\Language\Domain\Language;
use Lwt\Shared\Infrastructure\Language\LanguagePresets;
use Lwt\Shared\Infrastructure\Http\UrlUtilities;
use Lwt\Modules\Language\Infrastructure\Parser\ParserRegistry;
use Lwt\Modules\Dictionary\Application\DictionaryFacade;
use Lwt\Shared\Infrastructure\Globals;

/**
 * Controller for language configuration.
 *
 * Handles:
 * - Language listing and management
 * - Language creation and editing
 * - Language deletion
 * - Text reparsing
 * - Language pair selection
 *
 * @category Lwt
 * @package  Lwt
 * @author   HugoFara <git@hugofara.net>
 * @license  Unlicense <http://unlicense.org/>
 * @link     https://hugofara.github.io/lwt/developer/api
 * @since    3.0.0
 */
class LanguageController extends BaseController
{
    private LanguageFacade $languageFacade;
    private DictionaryFacade $dictionaryFacade;

    /**
     * Create a new LanguageController.
     *
     * @param LanguageFacade   $languageFacade   Language facade for language operations
     * @param DictionaryFacade $dictionaryFacade Dictionary facade for local dictionaries
     */
    public function __construct(LanguageFacade $languageFacade, DictionaryFacade $dictionaryFacade)
    {
        parent::__construct();
        $this->languageFacade = $languageFacade;
        $this->dictionaryFacade = $dictionaryFacade;
    }

    /**
     * Show new language form.
     *
     * Route: GET /languages/new
     *
     * @param array $params Route parameters
     *
     * @return void
     */
    public function new(array $params): void
    {
        // Creation itself goes through POST /api/v1/languages; this route only
        // renders the scaffold the editor component binds to.
        PageLayoutHelper::renderPageStart(__('language.page_title'), true);
        $this->showNewForm();
        PageLayoutHelper::renderPageEnd();
    }

    /**
     * Edit language form.
     *
     * Route: GET/POST /languages/{id}/edit
     *
     * @param int $id Language ID from route parameter
     *
     * @return void
     */
    public function edit(int $id): void
    {
        // Saving goes through PUT /api/v1/languages/{id}; this route only
        // renders the scaffold the editor component binds to.
        PageLayoutHelper::renderPageStart(__('language.page_title'), true);

        $this->showEditForm($id);

        PageLayoutHelper::renderPageEnd();
    }

    /**
     * Delete a language.
     *
     * Route: DELETE /languages/{id}
     *
     * @param int $id Language ID from route parameter
     *
     * @return void
     */
    public function delete(int $id): void
    {
        $result = $this->languageFacade->delete($id);
        $error = $result['error'];

        if ($error !== null) {
            // Error - redirect back with error message
            header('Location: ' . url('/languages') . '?error=' . urlencode($error));
        } else {
            // Success - redirect to languages list
            header('Location: ' . url('/languages'));
        }
        exit;
    }

    /**
     * Refresh (reparse) all texts for a language.
     *
     * Route: POST /languages/{id}/refresh
     *
     * @param int $id Language ID from route parameter
     *
     * @return void
     */
    public function refresh(int $id): void
    {
        $result = $this->languageFacade->refresh($id);

        $message = __('language.flash.refresh_summary', [
            'sentencesDeleted' => $result['sentencesDeleted'],
            'textItemsDeleted' => $result['textItemsDeleted'],
            'sentencesAdded' => $result['sentencesAdded'],
            'textItemsAdded' => $result['textItemsAdded'],
        ]);

        // Redirect to languages list with message
        header('Location: ' . url('/languages') . '?message=' . urlencode($message));
        exit;
    }

    /**
     * Languages index page - shows language list.
     *
     * Route: GET /languages
     *
     * @param array $params Route parameters
     *
     * @return void
     */
    public function index(array $params): void
    {
        PageLayoutHelper::renderPageStart(__('language.page_title'), true);

        // Check for message from redirect (e.g., after refresh/delete)
        $message = $this->hasParam('message') ? $this->param('message') :
                   ($this->hasParam('error') ? $this->param('error') : '');

        $this->showList($message);

        PageLayoutHelper::renderPageEnd();
    }

    /**
     * Show the list of languages.
     *
     * @param string $message Optional message to display
     *
     * @return void
     */
    private function showList(string $message): void
    {
        $this->message($message, false);

        $currentLanguageId = (int) Settings::get('currentlanguage');
        $languages = $this->languageFacade->getLanguagesWithStats();

        include __DIR__ . '/../Views/index.php';
    }

    /**
     * Show the new language form.
     *
     * @return void
     */
    private function showNewForm(): void
    {
        $currentNativeLanguage = Settings::get('currentnativelanguage');
        $languageOptions = $this->getWizardSelectOptions($currentNativeLanguage);
        $languageOptionsEmpty = $this->getWizardSelectOptions('');
        $languageDefsJson = json_encode(LanguagePresets::getAll());
        $languagePresetsArray = $this->getLanguagePresetsArray();

        ?>
        <h2>
            <?php echo __('language.form.new_title'); ?>
            <a target="_blank" href="docs/info.html#howtolang">
                <?php echo IconHelper::render(
                    'help-circle',
                    ['title' => __('language.form.help'), 'alt' => __('language.form.help')]
                ); ?>
            </a>
        </h2>
        <?php

        include __DIR__ . '/../Views/wizard.php';

        $languageEntity = $this->languageFacade->createEmptyLanguage();
        $language = $this->languageFacade->toViewObject($languageEntity);
        $sourceLg = '';
        $targetLg = '';
        $isNew = true;

        $this->prepareLanguageCodes($languageEntity, $currentNativeLanguage, $sourceLg, $targetLg);

        $allLanguages = $this->languageFacade->getAllLanguages();
        $parserInfo = (new ParserRegistry())->getParserInfo();
        $isAdmin = false;
        $dictionaries = [];

        include __DIR__ . '/../Views/form.php';
    }

    /**
     * Show the edit language form.
     *
     * @param int $lid Language ID
     *
     * @return void
     */
    private function showEditForm(int $lid): void
    {
        $languageEntity = $this->languageFacade->getById($lid);

        if ($languageEntity === null) {
            echo '<div class="notification is-danger">' .
                '<button class="delete" aria-label="close"></button>' .
                __('language.errors.language_not_found') . '</div>';
            return;
        }

        $language = $this->languageFacade->toViewObject($languageEntity);
        $currentNativeLanguage = Settings::get('currentnativelanguage');
        $sourceLg = '';
        $targetLg = '';
        $isNew = false;

        $this->prepareLanguageCodes($languageEntity, $currentNativeLanguage, $sourceLg, $targetLg);

        $allLanguages = $this->languageFacade->getAllLanguages();
        $parserInfo = (new ParserRegistry())->getParserInfo();

        // Dictionary data (all users can see their dictionaries)
        $isAdmin = !Globals::isMultiUserEnabled() || Globals::isCurrentUserAdmin();
        $dictionaries = $this->dictionaryFacade->getAllForLanguage($lid);

        ?>
    <h2><?php echo __('language.form.edit_title'); ?>
        <a target="_blank" href="docs/info.html#howtolang">
            <?php echo IconHelper::render(
                'help-circle',
                ['title' => __('language.form.help'), 'alt' => __('language.form.help')]
            ); ?>
        </a>
    </h2>
        <?php

        include __DIR__ . '/../Views/form.php';
    }

    /**
     * Prepare source and target language codes for the form.
     *
     * @param Language $language              Language object
     * @param string   $currentNativeLanguage Current native language
     * @param string   &$sourceLg             Output source language code
     * @param string   &$targetLg             Output target language code
     *
     * @return void
     */
    private function prepareLanguageCodes(
        Language $language,
        string $currentNativeLanguage,
        string &$sourceLg,
        string &$targetLg
    ): void {
        if (array_key_exists($currentNativeLanguage, LanguagePresets::getAll())) {
            $targetLg = LanguagePresets::getAll()[$currentNativeLanguage][1];
        }

        $langName = $language->name();
        if ($langName) {
            if (array_key_exists($langName, LanguagePresets::getAll())) {
                $sourceLg = LanguagePresets::getAll()[$langName][1];
            }
            $lgFromDict = UrlUtilities::langFromDict($language->translatorUri());
            if ($lgFromDict != '' && $lgFromDict != $sourceLg) {
                $sourceLg = $lgFromDict;
            }

            $targetFromDict = UrlUtilities::targetLangFromDict($language->translatorUri());
            if ($targetFromDict != '' && $targetFromDict != $targetLg) {
                $targetLg = $targetFromDict;
            }
        }
    }

    /**
     * Generate wizard select options HTML.
     *
     * @param string $selected Currently selected value
     *
     * @return string HTML options
     */
    private function getWizardSelectOptions(string $selected): string
    {
        $r = '<option value=""' . FormHelper::getSelected($selected, '') . '>[Choose...]</option>';
        $keys = array_keys(LanguagePresets::getAll());
        foreach ($keys as $item) {
            $r .= '<option value="' . $item . '"' .
                FormHelper::getSelected($selected, $item) . '>' . $item . '</option>';
        }
        return $r;
    }

    /**
     * Get language presets as array for searchable select.
     *
     * @return array<int, array{id: int|string, name: string}>
     */
    private function getLanguagePresetsArray(): array
    {
        $presets = [];
        $keys = array_keys(LanguagePresets::getAll());
        foreach ($keys as $item) {
            $presets[] = ['id' => $item, 'name' => $item];
        }
        return $presets;
    }
}
