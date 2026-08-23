<?php

/**
 * Text Term API Handler
 *
 * Handles term translations, word discovery, text scoring, and text listing
 * operations. Extracted from TextApiHandler.
 *
 * PHP version 8.1
 *
 * @category Lwt
 * @package  Lwt\Modules\Text\Http
 * @author   HugoFara <git@hugofara.net>
 * @license  Unlicense <http://unlicense.org/>
 * @link     https://hugofara.github.io/lwt/developer/api
 * @since    3.0.0
 */

declare(strict_types=1);

namespace Lwt\Modules\Text\Http;

use Lwt\Shared\Infrastructure\Utilities\StringUtils;
use Lwt\Shared\Infrastructure\Database\QueryBuilder;
use Lwt\Shared\Infrastructure\Database\Settings;
use Lwt\Modules\Vocabulary\Application\Services\ExportService;
use Lwt\Modules\Text\Application\Services\AnnotationService;
use Lwt\Modules\Tags\Application\TagsFacade;
use Lwt\Modules\Text\Domain\ParseCoverage;
use Lwt\Modules\Text\Application\TextFacade;
use Lwt\Modules\Text\Application\Services\TextScoringService;

/**
 * Handler for term translations, word retrieval, text scoring, and listing.
 */
class TextTermApiHandler
{
    private TextFacade $textService;

    public function __construct(?TextFacade $textService = null)
    {
        $this->textService = $textService ?? new TextFacade();
    }

    /**
     * Get all words for a text for client-side rendering.
     *
     * @param int $textId Text ID
     *
     * @return array{words: array, config: array}|array{error: string}
     */
    public function getWords(int $textId): array
    {
        $textInfo = QueryBuilder::table('texts')
            ->select(['TxID', 'TxLgID', 'TxTitle', 'TxAudioURI', 'TxSourceURI', 'TxAudioPosition'])
            ->where('TxID', '=', $textId)
            ->firstPrepared();

        if ($textInfo === null) {
            return ['error' => 'Text not found'];
        }

        $langId = (int)$textInfo['TxLgID'];

        $langInfo = QueryBuilder::table('languages')
            ->select(
                ['LgID', 'LgName', 'LgDict1URI', 'LgDict2URI', 'LgGoogleTranslateURI',
                'LgTextSize', 'LgRightToLeft', 'LgRegexpWordCharacters', 'LgRemoveSpaces']
            )
            ->where('LgID', '=', $langId)
            ->firstPrepared();

        if ($langInfo === null) {
            return ['error' => 'Language not found'];
        }

        $records = QueryBuilder::table('word_occurrences')
            ->select(
                [
                'CASE WHEN `Ti2WordCount`>0 THEN Ti2WordCount ELSE 1 END AS Code',
                'CASE WHEN CHAR_LENGTH(Ti2Text)>0 THEN Ti2Text ELSE `WoText` END AS TiText',
                'CASE WHEN CHAR_LENGTH(Ti2Text)>0 THEN LOWER(Ti2Text) ELSE `WoTextLC` END AS TiTextLC',
                'Ti2Order',
                'Ti2SeID',
                'CASE WHEN `Ti2WordCount`>0 THEN 0 ELSE 1 END AS TiIsNotWord',
                'CASE WHEN CHAR_LENGTH(Ti2Text)>0 THEN CHAR_LENGTH(Ti2Text) ' .
                    'ELSE CHAR_LENGTH(`WoTextLC`) END AS TiTextLength',
                'WoID',
                'WoText',
                'WoStatus',
                'WoTranslation',
                'WoRomanization',
                'WoNotes'
                ]
            )
            ->leftJoin('words', 'word_occurrences.Ti2WoID', '=', 'words.WoID')
            ->where('word_occurrences.Ti2TxID', '=', $textId)
            ->orderBy('Ti2Order', 'ASC')
            ->orderBy('Ti2WordCount', 'DESC')
            ->getPrepared();

        $words = [];
        $exprs = [];
        $lastOrder = -1;

        foreach ($records as $record) {
            $code = (int)$record['Code'];
            $order = (int)$record['Ti2Order'];
            $isNotWord = (int)$record['TiIsNotWord'];

            if ($code > 1) {
                if (empty($exprs) || $exprs[count($exprs) - 1]['text'] !== $record['TiText']) {
                    $exprs[] = [
                        'code' => $code,
                        'text' => $record['TiText'],
                        'remaining' => $code,
                        'startPos' => $order,
                        'wordId' => isset($record['WoID']) ? (int)$record['WoID'] : null,
                        'status' => (int)($record['WoStatus'] ?? 0),
                        'translation' => ExportService::replaceTabNewline((string)($record['WoTranslation'] ?? '')),
                    ];
                }
            }

            $hidden = $order <= $lastOrder;
            $hex = StringUtils::toClassName((string)($record['TiTextLC'] ?? ''));

            $wordData = [
                'position' => $order,
                'sentenceId' => (int)$record['Ti2SeID'],
                'text' => $record['TiText'] ?? '',
                'textLc' => $record['TiTextLC'] ?? '',
                'hex' => $hex,
                'isNotWord' => $isNotWord === 1,
                'wordCount' => $code,
                'hidden' => $hidden,
            ];

            if ($isNotWord === 0) {
                if (isset($record['WoID'])) {
                    $wordData['wordId'] = (int)$record['WoID'];
                    $wordData['status'] = (int)$record['WoStatus'];
                    $wordData['translation'] = ExportService::replaceTabNewline(
                        (string)($record['WoTranslation'] ?? '')
                    );
                    $wordData['romanization'] = (string)($record['WoRomanization'] ?? '');
                    $wordData['notes'] = (string)($record['WoNotes'] ?? '');

                    $tags = TagsFacade::getWordTagList((int)$record['WoID'], false);
                    if ($tags) {
                        $wordData['tags'] = $tags;
                    }
                } else {
                    $wordData['wordId'] = null;
                    $wordData['status'] = 0;
                    $wordData['translation'] = '';
                    $wordData['romanization'] = '';
                    $wordData['notes'] = '';
                }

                foreach ($exprs as $expr) {
                    $wordData['mw' . $expr['code']] = [
                        'text' => $expr['text'],
                        'translation' => $expr['translation'],
                        'status' => $expr['status'],
                        'wordId' => $expr['wordId'],
                        'startPos' => $expr['startPos'],
                        'endPos' => $expr['startPos'] + ($expr['code'] - 1) * 2,
                    ];
                }
            }

            $words[] = $wordData;

            if ($code === 1) {
                for ($i = count($exprs) - 1; $i >= 0; $i--) {
                    $exprs[$i]['remaining']--;
                    if ($exprs[$i]['remaining'] < 1) {
                        array_splice($exprs, $i, 1);
                    }
                }
            }

            $lastOrder = max($lastOrder, $order + ($code - 1) * 2);
        }

        $showLearning = Settings::getZeroOrOne('showlearningtranslations', 1);
        $displayStatTrans = (int)Settings::getWithDefault('set-display-text-frame-term-translation');
        $modeTrans = (int)Settings::getWithDefault('set-text-frame-annotation-position');
        $termDelimiter = Settings::getWithDefault('set-term-translation-delimiters');
        $textSize = (int)$langInfo['LgTextSize'];
        $readerWidth = (int)Settings::getWithDefault('set-reader-width');
        $readerTextSize = (int)Settings::getWithDefault(
            'set-reader-text-size'
        );
        if ($readerTextSize > 0) {
            $textSize = $readerTextSize;
        }

        $config = [
            'textId' => $textId,
            'langId' => $langId,
            'title' => $textInfo['TxTitle'],
            'audioUri' => $textInfo['TxAudioURI'],
            'sourceUri' => $textInfo['TxSourceURI'],
            'audioPosition' => (int)$textInfo['TxAudioPosition'],
            'rightToLeft' => (int)$langInfo['LgRightToLeft'] === 1,
            'textSize' => $textSize,
            'removeSpaces' => (int)$langInfo['LgRemoveSpaces'] === 1,
            'dictLinks' => [
                'dict1' => $langInfo['LgDict1URI'] ?? '',
                'dict2' => $langInfo['LgDict2URI'] ?? '',
                'translator' => $langInfo['LgGoogleTranslateURI'] ?? '',
            ],
            'showLearning' => $showLearning,
            'displayStatTrans' => $displayStatTrans,
            'modeTrans' => $modeTrans,
            'termDelimiter' => $termDelimiter,
            'readerWidth' => $readerWidth,
            'parseWarning' => self::parseWarning($words, $langId),
            'annTextSize' => match ($textSize) {
                100 => 50,
                150 => 50,
                200 => 40,
                250 => 25,
                default => 50,
            },
        ];

        return [
            'words' => $words,
            'config' => $config,
        ];
    }

    /**
     * Tell the reader when a text holds nothing they can act on.
     *
     * A language whose word characters do not match the script of its texts
     * does not fail: it parses successfully into nothing. The page still shows
     * every character, so the text looks ordinary and simply refuses to
     * respond to any click, with nothing anywhere to say why (#278).
     *
     * @param array<int, array<string, mixed>> $words  The rendered word list
     * @param int                              $langId Language of the text
     *
     * @return array{headline: string, detail: string, linkLabel: string, linkHref: string}|null
     */
    private static function parseWarning(array $words, int $langId): ?array
    {
        $wordCount = 0;
        $characters = 0;
        foreach ($words as $word) {
            $characters += mb_strlen((string) ($word['text'] ?? ''), 'UTF-8');
            if (($word['isNotWord'] ?? true) === false) {
                $wordCount++;
            }
        }

        $verdict = ParseCoverage::assess($wordCount, $characters);
        if (!ParseCoverage::isWarning($verdict)) {
            return null;
        }

        return [
            'headline' => $verdict === ParseCoverage::NO_WORDS
                ? __('text.parse_warning.no_words')
                : __('text.parse_warning.almost_no_words'),
            'detail' => __('text.parse_warning.check_language'),
            'linkLabel' => __('text.parse_warning.edit_language'),
            'linkHref' => '/languages/' . $langId . '/edit',
        ];
    }

    /**
     * Format response for getting text words.
     *
     * @param int $textId Text ID
     *
     * @return array{words: array, config: array}|array{error: string}
     */
    public function formatGetWords(int $textId): array
    {
        return $this->getWords($textId);
    }

    /**
     * Format response for getting texts by language.
     *
     * @param int   $langId Language ID
     * @param array $params Query parameters (page, per_page, sort)
     *
     * @return array{texts: array, pagination: array}
     */
    public function formatTextsByLanguage(int $langId, array $params): array
    {
        $page = isset($params['page']) ? max(1, (int)$params['page']) : 1;
        $perPage = isset($params['per_page']) ? max(1, min(100, (int)$params['per_page'])) : 10;
        $sort = isset($params['sort']) ? (int)$params['sort'] : 1;

        return $this->textService->getTextsForLanguage($langId, $page, $perPage, $sort);
    }

    /**
     * Format response for getting archived texts by language.
     *
     * @param int   $langId Language ID
     * @param array $params Query parameters (page, per_page, sort)
     *
     * @return array{texts: array, pagination: array}
     */
    public function formatArchivedTextsByLanguage(int $langId, array $params): array
    {
        $page = isset($params['page']) ? max(1, (int)$params['page']) : 1;
        $perPage = isset($params['per_page']) ? max(1, min(100, (int)$params['per_page'])) : 10;
        $sort = isset($params['sort']) ? (int)$params['sort'] : 1;

        return $this->textService->getArchivedTextsForLanguage($langId, $page, $perPage, $sort);
    }

    /**
     * Find the possible translations for a term.
     *
     * @param int $wordId Term ID
     *
     * @return string[]
     */
    public function getTranslations(int $wordId): array
    {
        $translations = array();
        $alltrans = (string) QueryBuilder::table('words')
            ->where('WoID', '=', $wordId)
            ->valuePrepared('WoTranslation');
        $transarr = preg_split('/[' . StringUtils::getSeparators() . ']/u', $alltrans);
        if ($transarr === false) {
            return $translations;
        }
        foreach ($transarr as $t) {
            $tt = trim($t);
            if ($tt == '*' || $tt == '') {
                continue;
            }
            $translations[] = $tt;
        }
        return $translations;
    }

    /**
     * Gather useful data to edit a term annotation on a specific text.
     *
     * @param string $wordlc Term in lower case
     * @param int    $textid Text ID
     *
     * @return array{term_lc?: string, wid?: int|null, trans?: string,
     *               ann_index?: int, term_ord?: int, translations?: string[],
     *               language_id?: int, error?: string}
     */
    public function getTermTranslations(string $wordlc, int $textid): array
    {
        $record = QueryBuilder::table('texts')
            ->select(['TxLgID', 'TxAnnotatedText'])
            ->where('TxID', '=', $textid)
            ->firstPrepared();
        if ($record === null) {
            return ['error' => 'Text not found'];
        }
        $langid = (int)$record['TxLgID'];
        $ann = (string)$record['TxAnnotatedText'];
        if (strlen($ann) > 0) {
            $annotationService = new AnnotationService();
            $ann = $annotationService->recreateSaveAnnotation($textid, $ann);
        }

        $annotations = preg_split('/[\n]/u', $ann);
        if ($annotations === false) {
            return ['error' => 'Failed to parse annotations'];
        }
        $i = -1;
        foreach ($annotations as $index => $annotationLine) {
            $vals = preg_split('/[\t]/u', $annotationLine);
            if ($vals === false) {
                continue;
            }
            if ($vals[0] <= -1) {
                continue;
            }
            if (trim($wordlc) != mb_strtolower(trim($vals[1]), 'UTF-8')) {
                continue;
            }
            $i = $index;
            break;
        }

        $annData = array();
        if ($i == -1) {
            $annData["error"] = "Annotation not found";
            return $annData;
        }

        $annotationLine = $annotations[$i];
        $vals = preg_split('/[\t]/u', $annotationLine);
        if ($vals === false) {
            $annData["error"] = "Annotation line is ill-formatted";
            return $annData;
        }
        $annData["term_lc"] = trim($wordlc);
        $annData["wid"] = null;
        $annData["trans"] = '';
        $annData["ann_index"] = $i;
        $annData["term_ord"] = (int)$vals[0];

        $wid = null;
        if (count($vals) > 2 && ctype_digit($vals[2])) {
            $wid = (int)$vals[2];
            $tempWid = QueryBuilder::table('words')
                ->where('WoID', '=', $wid)
                ->countPrepared();
            if ($tempWid < 1) {
                $wid = null;
            }
        }
        if ($wid !== null) {
            $annData["wid"] = $wid;
            $annData["translations"] = $this->getTranslations($wid);
        }
        if (count($vals) > 3) {
            $annData["trans"] = $vals[3];
        }
        $annData["language_id"] = $langid;
        return $annData;
    }

    /**
     * Format response for getting term translations.
     *
     * @param string $termLc Term in lowercase
     * @param int    $textId Text ID
     *
     * @return array{term_lc?: string, wid?: int|null, trans?: string,
     *               ann_index?: int, term_ord?: int, translations?: string[],
     *               language_id?: int, error?: string}
     */
    public function formatTermTranslations(string $termLc, int $textId): array
    {
        return $this->getTermTranslations($termLc, $textId);
    }

    /**
     * Get the difficulty score for a single text.
     *
     * @param int $textId Text ID to score
     *
     * @return array<string, mixed>
     */
    public function formatGetTextScore(int $textId): array
    {
        $scoringService = new TextScoringService();
        $score = $scoringService->scoreText($textId);
        return $score->toArray();
    }

    /**
     * Get scores for multiple texts.
     *
     * @param int[] $textIds Array of text IDs
     *
     * @return array{scores: array<int, array<string, mixed>>}
     */
    public function formatGetTextScores(array $textIds): array
    {
        $scoringService = new TextScoringService();
        $scores = $scoringService->scoreTexts($textIds);

        $result = [];
        foreach ($scores as $textId => $score) {
            $result[$textId] = $score->toArray();
        }

        return ['scores' => $result];
    }

    /**
     * Get recommended texts for a language based on comprehensibility.
     *
     * @param int   $languageId Language ID
     * @param array $params     Query parameters (target, limit)
     *
     * @return array{recommendations: array<array<string, mixed>>, target_comprehensibility: float}
     */
    public function formatGetRecommendedTexts(int $languageId, array $params): array
    {
        $target = isset($params['target']) ? (float)$params['target'] : 0.95;
        $limit = isset($params['limit']) ? min(50, max(1, (int)$params['limit'])) : 10;

        $target = max(0.5, min(1.0, $target));

        $scoringService = new TextScoringService();
        $recommendations = $scoringService->getRecommendedTexts($languageId, $target, $limit);

        $result = [];
        foreach ($recommendations as $score) {
            $scoreArray = $score->toArray();

            $text = QueryBuilder::table('texts')
                ->select(['TxTitle'])
                ->where('TxID', '=', $score->textId)
                ->firstPrepared();

            if ($text !== null) {
                $scoreArray['title'] = (string)$text['TxTitle'];
            }

            $result[] = $scoreArray;
        }

        return [
            'recommendations' => $result,
            'target_comprehensibility' => $target
        ];
    }
}
