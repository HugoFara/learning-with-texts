<?php

/**
 * Translation Controller - Handles translation API endpoints
 *
 * PHP version 8.2
 *
 * @category Lwt
 * @package  Lwt\Modules\Dictionary\Http
 * @author   HugoFara <git@hugofara.net>
 * @license  Unlicense <http://unlicense.org/>
 * @link     https://hugofara.github.io/lwt/developer/api
 * @since    3.0.0
 */

declare(strict_types=1);

namespace Lwt\Modules\Dictionary\Http;

use Lwt\Shared\Http\BaseController;
use Lwt\Shared\Infrastructure\Http\InputValidator;
use Lwt\Shared\Infrastructure\Dictionary\DictionaryAdapter;
use Lwt\Modules\Dictionary\Application\TranslationService;
use Lwt\Shared\Infrastructure\Database\Settings;
use Lwt\Shared\UI\Helpers\PageLayoutHelper;
use Lwt\Shared\UI\Helpers\IconHelper;

/**
 * Controller for translation API endpoints.
 *
 * Handles:
 * - Google Translate API (/api/google)
 * - Glosbe API (/api/glosbe)
 * - Generic translation (/api/translate)
 *
 * @category Lwt
 * @package  Lwt\Modules\Dictionary\Http
 * @author   HugoFara <git@hugofara.net>
 * @license  Unlicense <http://unlicense.org/>
 * @link     https://hugofara.github.io/lwt/developer/api
 * @since    3.0.0
 */
class TranslationController extends BaseController
{
    /**
     * Translation service instance
     *
     * @var TranslationService
     */
    protected TranslationService $translationService;

    /**
     * Dictionary adapter instance
     *
     * @var DictionaryAdapter
     */
    protected DictionaryAdapter $dictionaryService;

    /**
     * Create a new TranslationController.
     *
     * @param TranslationService $translationService Translation service for translation operations
     */
    public function __construct(TranslationService $translationService)
    {
        parent::__construct();
        $this->translationService = $translationService;
        $this->dictionaryService = new DictionaryAdapter();
    }

    /**
     * Get the translation service.
     *
     * @return TranslationService
     */
    public function getTranslationService(): TranslationService
    {
        return $this->translationService;
    }

    /**
     * Google Translate endpoint.
     *
     * Translates text using Google Translate API.
     *
     * Request parameters:
     * - text: Text to translate
     * - sl: Source language code
     * - tl: Target language code
     * - sent: (optional) If set, use sentence mode
     *
     * @param array $params Route parameters
     *
     * @return void
     */
    public function google(array $params): void
    {
        if (!InputValidator::hasFromGet('text')) {
            return;
        }

        $text = $this->get('text');

        header('Pragma: no-cache');
        header('Expires: 0');

        PageLayoutHelper::renderPageStartNobody('Google Translate');

        if ($text === '') {
            echo '<div class="notification is-warning">' .
                '<button class="delete" aria-label="close"></button>' .
                'Term is not set!' .
                '</div>';
            PageLayoutHelper::renderPageEnd();
            return;
        }

        $srcLang = $this->get('sl');
        $tgtLang = $this->get('tl');
        $sentenceMode = InputValidator::hasFromGet('sent');

        $this->renderGoogleTranslation($text, $srcLang, $tgtLang, $sentenceMode);

        PageLayoutHelper::renderPageEnd();
    }

    /**
     * Render Google translation results.
     *
     * @param string $text         Text to translate
     * @param string $srcLang      Source language code
     * @param string $tgtLang      Target language code
     * @param bool   $sentenceMode Whether in sentence translation mode
     *
     * @return void
     */
    protected function renderGoogleTranslation(
        string $text,
        string $srcLang,
        string $tgtLang,
        bool $sentenceMode
    ): void {
        $result = $this->translationService->translateViaGoogle(
            $text,
            $srcLang,
            $tgtLang
        );

        if (!$result['success']) {
            throw new \RuntimeException(
                $result['error'] ?? 'Unable to get translation from Google Translate API'
            );
        }

        // Build Google Translate link
        $ggLink = $this->dictionaryService->makeOpenDictStr(
            DictionaryAdapter::createDictLink(
                $this->translationService->buildGoogleTranslateUrl($text, $srcLang, $tgtLang),
                $text
            ),
            "View on Google Translate"
        );

        if ($sentenceMode) {
            $this->renderSentenceTranslation($text, $result['translations'][0] ?? '');
        } else {
            $this->renderTermTranslation($text, $result['translations'], $srcLang, $tgtLang);
        }

        // Safe: $ggLink is HTML from makeOpenDictStr() with proper escaping
        echo $ggLink;
    }

    /**
     * Render sentence translation result.
     *
     * @param string $text        Original text
     * @param string $translation Translated text
     *
     * @return void
     */
    protected function renderSentenceTranslation(string $text, string $translation): void
    {
        ?>
        <h2>Sentence Translation</h2>
        <span title="Translated via Google Translate">
            <?php echo htmlspecialchars($translation, ENT_QUOTES, 'UTF-8'); ?>
        </span>
        <p>Original sentence: </p>
        <blockquote><?php echo htmlspecialchars($text, ENT_QUOTES, 'UTF-8'); ?></blockquote>
        <?php
    }

    /**
     * Render term translation results.
     *
     * @param string   $text         Original text
     * @param string[] $translations Array of translations
     * @param string   $srcLang      Source language
     * @param string   $tgtLang      Target language
     *
     * @return void
     */
    protected function renderTermTranslation(
        string $text,
        array $translations,
        string $srcLang,
        string $tgtLang
    ): void {
        $lgId = Settings::get('currentlanguage');
        $hasParentFrame = true; // Will be checked client-side
        ?>
        <h2 title="Translate with Google Translate">
            Word translation: <?php echo htmlspecialchars($text, ENT_QUOTES, 'UTF-8') ?>
            <?php
            echo IconHelper::render(
                'volume-2',
                ['id' => 'textToSpeech', 'class' => 'click', 'title' => 'Click to read!', 'alt' => 'Click to read!']
            );
            ?>

            <?php
            echo IconHelper::render('brush', [
                'id' => 'del_translation', 'class' => 'click', 'title' => 'Empty Translation Field',
                'alt' => 'Empty Translation Field', 'data-action' => 'delete-translation'
            ]);
            ?>
        </h2>

        <script type="application/json" data-lwt-google-translate-config>
        <?php echo json_encode([
            'text' => $text,
            'langId' => $lgId,
            'hasParentFrame' => $hasParentFrame
        ], JSON_HEX_TAG | JSON_HEX_AMP); ?>
        </script>
        <?php
        foreach ($translations as $word) {
            echo '<span class="click" data-action="add-translation" data-word="' .
                htmlspecialchars($word, ENT_QUOTES, 'UTF-8') . '">' .
                IconHelper::render('circle-check', ['title' => 'Copy', 'alt' => 'Copy']) . ' &nbsp; ' .
                htmlspecialchars($word, ENT_QUOTES, 'UTF-8') . '</span><br />';
        }
        ?>
        <p>
            (Click on <?php echo IconHelper::render('circle-check', ['title' => 'Choose', 'alt' => 'Choose']); ?>
            to copy word(s) into above term)<br />&nbsp;
        </p>
        <hr />
        <form action="ggl.php" method="get">
            Unhappy?<br/>Change term:
            <input type="text" name="text" maxlength="250" size="15"
            value="<?php echo htmlspecialchars($text, ENT_QUOTES, 'UTF-8'); ?>">
            <input type="hidden" name="sl" value="<?php echo htmlspecialchars($srcLang, ENT_QUOTES, 'UTF-8'); ?>">
            <input type="hidden" name="tl" value="<?php echo htmlspecialchars($tgtLang, ENT_QUOTES, 'UTF-8'); ?>">
            <input type="submit" value="Translate via Google Translate">
        </form>
        <?php
    }

    /**
     * Glosbe API endpoint.
     *
     * Displays the Glosbe dictionary interface for word translation.
     *
     * Request parameters:
     * - from: Source language code (Glosbe format)
     * - dest: Target language code (Glosbe format)
     * - phrase: Word or expression to translate
     *
     * @param array $params Route parameters
     *
     * @return void
     */
    public function glosbe(array $params): void
    {
        $from = trim($this->param('from', ''));
        $dest = trim($this->param('dest', ''));
        $destOrig = $dest;
        $phrase = mb_strtolower(trim($this->param('phrase', '')), 'UTF-8');

        PageLayoutHelper::renderPageStartNobody('');

        $glosbeUrl = $this->translationService->buildGlosbeUrl($phrase, $from, $dest);
        $escapedFrom = htmlspecialchars($from, ENT_QUOTES, 'UTF-8');
        $escapedDest = htmlspecialchars($dest, ENT_QUOTES, 'UTF-8');
        $escapedPhrase = htmlspecialchars($phrase, ENT_QUOTES, 'UTF-8');
        $titleText = '<a href="' . $glosbeUrl . '">Glosbe Dictionary (' .
            $escapedFrom . "-" . $escapedDest . "):  &nbsp; " .
            '<span class="has-text-danger has-text-weight-bold">' . $escapedPhrase . "</span></a>";

        echo '<h3>' . $titleText .
            ' <span id="del_translation" class="click" data-action="delete-translation">' .
            IconHelper::render('brush', ['title' => 'Empty Translation Field']) . '</span></h3>';
        echo '<p>(Click on ' . IconHelper::render('circle-check', ['title' => 'Choose', 'alt' => 'Choose']) . ' ' .
            'to copy word(s) into above term)<br />&nbsp;</p>';

        $this->renderGlosbeScript($from, $dest, $phrase);

        echo '<p id="translations"></p>';

        echo '&nbsp;<form action="glosbe_api.php" method="get">Unhappy?<br/>Change term:
            <input type="text" name="phrase" maxlength="250" size="15"
                value="' . htmlspecialchars($phrase, ENT_QUOTES, 'UTF-8') . '">
            <input type="hidden" name="from" value="' . htmlspecialchars($from, ENT_QUOTES, 'UTF-8') . '">
            <input type="hidden" name="dest" value="' . htmlspecialchars($destOrig, ENT_QUOTES, 'UTF-8') . '">
            <input type="submit" value="Translate via Glosbe">
            </form>';

        PageLayoutHelper::renderPageEnd();
    }

    /**
     * Render the JavaScript for Glosbe translation.
     *
     * @param string $from   Source language code
     * @param string $dest   Target language code
     * @param string $phrase Phrase to translate
     *
     * @return void
     */
    protected function renderGlosbeScript(string $from, string $dest, string $phrase): void
    {
        $validation = $this->translationService->validateGlosbeParams($from, $dest, $phrase);

        $config = [
            'phrase' => urlencode($phrase),
            'from' => $from,
            'dest' => $dest,
            'hasParentFrame' => true // Will be checked client-side
        ];

        if (!$validation['valid']) {
            if ($phrase === '') {
                $config['error'] = 'empty_term';
            } else {
                $config['error'] = 'api_error';
            }
        }

        ?>
        <script type="application/json" data-lwt-glosbe-config>
        <?php echo json_encode($config, JSON_HEX_TAG | JSON_HEX_AMP); ?>
        </script>
        <?php
    }

    /**
     * Generic translation endpoint.
     *
     * Handles sentence translation and dictionary lookups.
     *
     * Request parameters:
     * - x: Operation type (1=sentence translation, 2=dictionary lookup)
     * - t: Text ID (for x=1) or text to translate (for x=2)
     * - i: Position (for x=1) or dictionary URI (for x=2)
     *
     * @param array $params Route parameters
     *
     * @return void
     */
    public function translate(array $params): void
    {
        $type = $this->paramInt('x');
        if ($type === null) {
            return;
        }

        $t = $this->paramInt('t', 0) ?? 0;
        $i = $this->paramInt('i', 0) ?? 0;

        $this->processTranslationRequest($type, $t, $i);
    }

    /**
     * Process a translation request.
     *
     * @param int $type Operation type
     * @param int $t    Text ID or text parameter
     * @param int $i    Position or dictionary URI parameter
     *
     * @return void
     */
    protected function processTranslationRequest(int $type, int $t, int $i): void
    {
        // Type 1: Translate sentence
        if ($type === 1) {
            $result = $this->translationService->getTranslatorUrl($i, $t);

            if ($result['url'] !== null && $result['url'] !== '') {
                $this->redirect($result['url']);
            }
            return;
        }

        // Type 2: Dictionary lookup
        if ($type === 2) {
            $url = $this->translationService->createDictLink((string)$t, (string)$i);
            $this->redirect($url);
        }
    }
}
