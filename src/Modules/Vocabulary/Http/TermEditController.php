<?php

/**
 * Term Edit Controller
 *
 * PHP version 8.2
 *
 * @category Lwt
 * @package  Lwt\Modules\Vocabulary\Http
 * @author   HugoFara <git@hugofara.net>
 * @license  Unlicense <http://unlicense.org/>
 * @link     https://hugofara.github.io/lwt/developer/api
 * @since    3.0.0
 */

declare(strict_types=1);

namespace Lwt\Modules\Vocabulary\Http;

use Lwt\Shared\Infrastructure\Http\InputValidator;
use Lwt\Shared\Infrastructure\Http\RedirectResponse;
use Lwt\Shared\Infrastructure\Database\Settings;
use Lwt\Modules\Vocabulary\Application\VocabularyFacade;
use Lwt\Shared\Infrastructure\Dictionary\DictionaryAdapter;
use Lwt\Modules\Language\Application\LanguageFacade;
use Lwt\Modules\Tags\Application\TagsFacade;
use Lwt\Shared\UI\Helpers\PageLayoutHelper;

/**
 * Controller for creating and editing single-word terms.
 *
 * Handles:
 * - /word/edit - Edit word form
 * - /word/new - Create new word
 * - /word/inline-edit - Inline edit translation/romanization
 * - /word/edit-term - Edit term during review
 * - /word/delete-term - Delete term (iframe view)
 *
 * @since 3.0.0
 */
class TermEditController extends VocabularyBaseController
{
    /**
     * Vocabulary facade.
     */
    private VocabularyFacade $facade;

    /**
     * Adapters.
     */
    private DictionaryAdapter $dictionaryAdapter;

    /**
     * Services.
     */
    private LanguageFacade $languageFacade;

    /**
     * Constructor.
     *
     * @param VocabularyFacade|null  $facade            Vocabulary facade
     * @param DictionaryAdapter|null $dictionaryAdapter Dictionary adapter
     * @param LanguageFacade|null    $languageFacade    Language facade
     */
    public function __construct(
        ?VocabularyFacade $facade = null,
        ?DictionaryAdapter $dictionaryAdapter = null,
        ?LanguageFacade $languageFacade = null
    ) {
        parent::__construct();
        $this->facade = $facade ?? new VocabularyFacade();
        $this->dictionaryAdapter = $dictionaryAdapter ?? new DictionaryAdapter();
        $this->languageFacade = $languageFacade ?? new LanguageFacade();
    }

    /**
     * Render the standalone term editor.
     *
     * The page carries only the identifiers; termEditPage loads the term from
     * GET /api/v1/terms/for-edit and mounts the same editor the reading view
     * opens in a modal, so there is one editor rather than three forms.
     *
     * @param int      $textId    Text ID, or 0 when editing an existing term
     * @param int      $position  Word position in the text, or 0
     * @param int|null $wordId    Term ID, or null when creating from a position
     * @param string   $returnUrl Where to go once editing finishes
     *
     * @return void
     */
    private function renderEditorPage(int $textId, int $position, ?int $wordId, string $returnUrl): void
    {
        PageLayoutHelper::renderPageStart(__('vocabulary.form.edit_term'), true, 'words');

        $this->render('edit_page', [
            'textId' => $textId,
            'position' => $position,
            'wordId' => $wordId,
            'returnUrl' => $returnUrl,
        ]);

        PageLayoutHelper::renderPageEnd();
    }

    /**
     * Edit word by ID.
     *
     * Route: GET /words/{id}/edit
     *
     * @param int $id Word ID from route parameter
     *
     * @return void
     */
    public function editWordById(int $id): void
    {
        $this->renderEditorPage(0, 0, $id, '/words');
    }

    /**
     * Edit word form: ?wid=[wordid] or ?tid=[textid]&ord=[ord].
     *
     * @param array<string, string> $params Route parameters
     *
     * @return void
     */
    public function editWord(array $params): void
    {
        $wid = InputValidator::getInt('wid', 0) ?? 0;
        $textId = InputValidator::getInt('tid', 0) ?? 0;
        $ord = InputValidator::getInt('ord', 0) ?? 0;

        // Nothing identifies a term: neither an ID nor a position in a text.
        // Say so rather than serving a blank page.
        if ($wid <= 0 && ($textId <= 0 || $ord <= 0)) {
            throw new \RuntimeException(
                'Cannot edit term: expected a term ID, or a text ID with a position'
            );
        }

        $returnUrl = $textId > 0 ? '/text/' . $textId . '/read' : '/words';
        $this->renderEditorPage($textId, $ord, $wid > 0 ? $wid : null, $returnUrl);
    }

    /**
     * Edit term while reviewing: ?wid=[wordid].
     *
     * @param array<string, string> $params Route parameters
     *
     * @return void
     */
    public function editTerm(array $params): void
    {
        $wid = InputValidator::getInt('wid', 0) ?? 0;

        if ($wid <= 0) {
            throw new \RuntimeException('Cannot edit term: expected a term ID');
        }

        $this->renderEditorPage(0, 0, $wid, '/review');
    }

    /**
     * Inline edit word.
     *
     * Handles AJAX inline editing of translation or romanization fields.
     * POST parameters:
     * - id: string - Field identifier (e.g., "trans123" or "roman123" where 123 is word ID)
     * - value: string - New value for the field
     *
     * @param array<string, string> $params Route parameters
     *
     * @return void
     */
    public function inlineEdit(array $params): void
    {
        $value = InputValidator::getStringFromPost('value');
        $id = InputValidator::getStringFromPost('id');

        if (substr($id, 0, 5) === 'trans') {
            $wordId = (int) substr($id, 5);
            $term = $this->facade->getTerm($wordId);
            if ($term === null) {
                echo 'ERROR - term not found!';
                return;
            }
            $this->facade->updateTerm($wordId, null, $value ?: '*', null, null, null);
            $displayValue = $value ?: '*';
            echo htmlspecialchars($displayValue, ENT_QUOTES, 'UTF-8');
            return;
        }

        if (substr($id, 0, 5) === 'roman') {
            $wordId = (int) substr($id, 5);
            $term = $this->facade->getTerm($wordId);
            if ($term === null) {
                echo 'ERROR - term not found!';
                return;
            }
            $this->facade->updateTerm($wordId, null, null, null, null, $value);
            echo htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
            return;
        }

        echo 'ERROR - please refresh page!';
    }

    /**
     * Create new word form.
     *
     * Handles:
     * - Display form: ?lang=[langid]&text=[textid]
     * - Save: ?op=Save
     *
     * @param array<string, string> $params Route parameters
     *
     * @return void
     */
    public function createWord(array $params): void
    {
        // Creation goes through POST /api/v1/terms/for-language, which
        // validates the language against the caller's own. The retired POST
        // branch here read WoLgID from the request and passed it straight into
        // the occurrence-linking path.
        $lang = InputValidator::getInt('lang', 0) ?? 0;
        $textId = InputValidator::getInt('text', 0) ?? 0;
        $scrdir = $this->languageFacade->getScriptDirectionTag($lang);

        $langData = $this->getContextService()->getLanguageData($lang);
        $showRoman = $langData['showRoman'];

        $showSimilarTerms = (int) Settings::getWithDefault("set-similar-terms-count") > 0;
        $dictLinksHtml = $this->dictionaryAdapter->createDictLinksInEditWin3($lang, 'WoSentence', 'WoText');
        $wordTagsHtml = TagsFacade::getWordTagsHtml(0);

        PageLayoutHelper::renderPageStart('New Term', true, 'terms');

        $this->render('form_new', [
            'lang' => $lang,
            'textId' => $textId,
            'scrdir' => $scrdir,
            'showRoman' => $showRoman,
            'showSimilarTerms' => $showSimilarTerms,
            'dictLinksHtml' => $dictLinksHtml,
            'wordTagsHtml' => $wordTagsHtml,
        ]);

        PageLayoutHelper::renderPageEnd();
    }


    /**
     * Delete word.
     *
     * Route: DELETE /words/{id}
     *
     * @param int $id Word ID from route parameter
     *
     * @return RedirectResponse Redirect to words list
     */
    public function deleteWord(int $id): RedirectResponse
    {
        $this->facade->deleteTerm($id);

        return new RedirectResponse('/words');
    }
}
