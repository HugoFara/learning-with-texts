<?php

/**
 * Term Display Controller
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
use Lwt\Shared\Infrastructure\Database\Settings;
use Lwt\Shared\Infrastructure\Database\Validation;
use Lwt\Modules\Vocabulary\Application\VocabularyFacade;
use Lwt\Modules\Vocabulary\Application\UseCases\FindSimilarTerms;
use Lwt\Shared\Infrastructure\Dictionary\DictionaryAdapter;
use Lwt\Modules\Language\Application\LanguageFacade;
use Lwt\Modules\Tags\Application\TagsFacade;
use Lwt\Shared\UI\Helpers\PageLayoutHelper;

/**
 * Controller for viewing terms and hover interactions.
 *
 * Handles:
 * - GET /word/{wid} - Show word details
 * - /word/show - Show word details (legacy)
 * - /vocabulary/term-hover - Hover create from reading view
 * - /vocabulary/similar-terms - Get similar terms
 * - /words - List/edit words (Alpine.js)
 * - /words/edit - Edit words list
 *
 * @since 3.0.0
 */
class TermDisplayController extends VocabularyBaseController
{
    /**
     * Vocabulary facade.
     */
    private VocabularyFacade $facade;

    /**
     * Use cases.
     */
    private FindSimilarTerms $findSimilarTerms;

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
     * @param VocabularyFacade|null     $facade              Vocabulary facade
     * @param FindSimilarTerms|null     $findSimilarTerms    Find similar terms use case
     * @param DictionaryAdapter|null    $dictionaryAdapter   Dictionary adapter
     * @param LanguageFacade|null       $languageFacade      Language facade
     */
    public function __construct(
        ?VocabularyFacade $facade = null,
        ?FindSimilarTerms $findSimilarTerms = null,
        ?DictionaryAdapter $dictionaryAdapter = null,
        ?LanguageFacade $languageFacade = null
    ) {
        parent::__construct();
        $this->facade = $facade ?? new VocabularyFacade();
        $this->findSimilarTerms = $findSimilarTerms ?? new FindSimilarTerms();
        $this->dictionaryAdapter = $dictionaryAdapter ?? new DictionaryAdapter();
        $this->languageFacade = $languageFacade ?? new LanguageFacade();
    }

    /**
     * Show term details.
     *
     * @param array<string, string> $params Route parameters
     *
     * @return void
     */
    public function show(array $params): void
    {
        $termId = InputValidator::getInt('wid', 0) ?? 0;

        if ($termId === 0) {
            http_response_code(400);
            echo 'Term ID required';
            return;
        }

        $term = $this->facade->getTerm($termId);

        if ($term === null) {
            http_response_code(404);
            echo 'Term not found';
            return;
        }

        PageLayoutHelper::renderPageStart("Term: " . $term->text(), false);

        $this->render('show', [
            'term' => $term,
        ]);

        PageLayoutHelper::renderPageEnd();
    }

    /**
     * Show word details.
     *
     * Routes:
     * - GET /word/{wid:int} (new RESTful route)
     * - GET /word/show?wid=[wordid] (legacy route)
     *
     * Optional query parameter: ann=[annotation]
     *
     * @param int|null $wid Word ID (injected from route parameter)
     *
     * @return void
     */
    public function showWord(?int $wid = null): void
    {
        PageLayoutHelper::renderPageStartNobody('Term');

        // Support both new route param injection and legacy query param
        if ($wid === null) {
            $widParam = InputValidator::getString('wid');
            $wid = $widParam !== '' ? (int) $widParam : null;
        }
        $ann = InputValidator::getString('ann');

        if ($wid === null) {
            echo '<p>Word ID is required</p>';
            PageLayoutHelper::renderPageEnd();
            return;
        }

        $term = $this->facade->getTerm($wid);
        if ($term === null) {
            echo '<p>Word not found</p>';
            PageLayoutHelper::renderPageEnd();
            return;
        }

        $tags = TagsFacade::getWordTagList($wid, false);
        $scrdir = $this->languageFacade->getScriptDirectionTag($term->languageId()->toInt());

        // Convert Term entity to array for view compatibility
        $word = [
            'text' => $term->text(),
            'translation' => $term->translation(),
            'sentence' => $term->sentence(),
            'romanization' => $term->romanization(),
            'notes' => $term->notes(),
            'status' => $term->status()->toInt(),
            'langId' => $term->languageId()->toInt(),
        ];

        $this->render('show', [
            'word' => $word,
            'tags' => $tags,
            'scrdir' => $scrdir,
            'ann' => $ann,
        ]);

        PageLayoutHelper::renderPageEnd();
    }

    /**
     * Show term edit form.
     *
     * @param array<string, string> $params Route parameters
     *
     * @return void
     */
    public function edit(array $params): void
    {
        $termId = InputValidator::getInt('wid', 0) ?? 0;

        if ($termId === 0) {
            http_response_code(400);
            echo 'Term ID required';
            return;
        }

        $term = $this->facade->getTerm($termId);

        if ($term === null) {
            http_response_code(404);
            echo 'Term not found';
            return;
        }

        PageLayoutHelper::renderPageStart("Edit Term: " . $term->text(), false);

        $this->render('form_edit', [
            'term' => $term,
            'dictionaryLinks' => $this->getDictionaryLinks(
                $term->languageId()->toInt(),
                $term->text(),
                'sentence_textarea',
                true
            ),
            'similarTermsHtml' => $this->findSimilarTerms->getFormattedTerms(
                $term->languageId()->toInt(),
                $term->textLowercase()
            ),
        ]);

        PageLayoutHelper::renderPageEnd();
    }

    /**
     * Get similar terms for a given term.
     *
     * @param array<string, string> $params Route parameters
     *
     * @return void
     */
    public function similarTerms(array $params): void
    {
        $langId = InputValidator::getInt('lgid', 0) ?? 0;
        $term = InputValidator::getString('term');

        header('Content-Type: text/html; charset=utf-8');
        // Safe: getFormattedTerms() returns pre-escaped HTML
        echo $this->findSimilarTerms->getFormattedTerms($langId, $term);
    }

    /**
     * Get dictionary links for editing.
     *
     * @param int    $langId    Language ID
     * @param string $word      Word to look up
     * @param string $sentctlid Sentence control ID
     * @param bool   $openFirst Open first dictionary
     *
     * @return string HTML dictionary links
     */
    private function getDictionaryLinks(
        int $langId,
        string $word,
        string $sentctlid,
        bool $openFirst = false
    ): string {
        return $this->dictionaryAdapter->createDictLinksInEditWin(
            $langId,
            $word,
            $sentctlid,
            $openFirst
        );
    }

    /**
     * List/edit words - Alpine.js SPA version.
     *
     * @param array<string, string> $params Route parameters
     *
     * @psalm-suppress UnresolvableInclude Path computed from viewPath property
     *
     * @return void
     */
    public function listEditAlpine(array $params): void
    {
        $currentlang = Validation::language(
            InputValidator::getStringWithDb("filterlang", 'currentlanguage')
        );

        $perPage = (int) Settings::getWithDefault('set-terms-per-page');
        if ($perPage < 1) {
            $perPage = 50;
        }

        // Use a placeholder title - Alpine.js will update it dynamically
        PageLayoutHelper::renderPageStart('Terms', true);

        include $this->viewPath . 'list_alpine.php';

        PageLayoutHelper::renderPageEnd();
    }
}
