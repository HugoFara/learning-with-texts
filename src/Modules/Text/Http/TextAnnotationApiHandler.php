<?php

/**
 * Text Annotation API Handler
 *
 * Handles annotation CRUD, print items, and improved text editing.
 * Extracted from TextApiHandler.
 *
 * PHP version 8.2
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
use Lwt\Modules\Text\Application\Services\AnnotationService;
use Lwt\Shared\Infrastructure\Dictionary\DictionaryAdapter;
use Lwt\Modules\Text\Application\Services\TextPrintService;
use Lwt\Shared\UI\Helpers\IconHelper;

/**
 * Handler for annotation CRUD and print items operations.
 */
class TextAnnotationApiHandler
{
    /**
     * Save data from printed text.
     *
     * @param int    $textid Text ID
     * @param int    $line   Line number to save
     * @param string $val    Proposed new annotation for the term
     *
     * @return array{success: bool, error?: string, requested?: int, available?: int, position?: int, found?: int}
     */
    public function saveImprTextData(int $textid, int $line, string $val): array
    {
        $ann = (string) QueryBuilder::table('texts')
            ->where('TxID', '=', $textid)
            ->valuePrepared('TxAnnotatedText');

        $items = preg_split('/[\n]/u', $ann);
        if ($items === false) {
            return ['success' => false, 'error' => 'parse_annotation_failed'];
        }
        if (count($items) <= $line) {
            return [
                'success' => false,
                'error' => 'line_out_of_range',
                'requested' => $line,
                'available' => count($items)
            ];
        }

        $vals = preg_split('/[\t]/u', $items[$line]);
        if ($vals === false) {
            return ['success' => false, 'error' => 'parse_line_failed'];
        }
        if ((int)$vals[0] <= -1) {
            return ['success' => false, 'error' => 'punctuation_term', 'position' => (int)$vals[0]];
        }
        if (count($vals) < 4) {
            return ['success' => false, 'error' => 'insufficient_columns', 'found' => count($vals)];
        }

        $items[$line] = implode("\t", array($vals[0], $vals[1], $vals[2], $val));

        QueryBuilder::table('texts')
            ->where('TxID', '=', $textid)
            ->updatePrepared(['TxAnnotatedText' => implode("\n", $items)]);

        return ['success' => true];
    }

    /**
     * Format annotation save error into human-readable message.
     *
     * @param array{success: bool, error?: string, requested?: int, available?: int,
     *              position?: int, found?: int} $result
     *
     * @return string Formatted error message
     */
    private function formatAnnotationError(array $result): string
    {
        if ($result['success']) {
            return 'OK';
        }

        return match ($result['error'] ?? '') {
            'parse_annotation_failed' => 'Failed to parse annotation text',
            'line_out_of_range' => "Unreachable translation: line request is " .
                ($result['requested'] ?? '?') . ", but only " .
                ($result['available'] ?? '?') . " translations were found",
            'parse_line_failed' => 'Failed to parse annotation line',
            'punctuation_term' => "Term is punctuation! Term position is " . ($result['position'] ?? '?'),
            'insufficient_columns' => "Not enough columns: " . ($result['found'] ?? '?'),
            default => 'Unknown error'
        };
    }

    /**
     * Save a text with improved annotations.
     *
     * @param int    $textid Text ID
     * @param string $elem   Element to select
     * @param object $data   Data element
     *
     * @return array{error?: string, success?: string}
     */
    public function saveImprText(int $textid, string $elem, object $data): array
    {
        $newAnnotation = (string)($data->{$elem} ?? '');
        $line = (int)substr($elem, 2);
        if (str_starts_with($elem, "rg") && $newAnnotation == "") {
            $newAnnotation = (string)($data->{'tx' . $line} ?? '');
        }
        $result = $this->saveImprTextData($textid, $line, $newAnnotation);
        if (!$result['success']) {
            return ["error" => $this->formatAnnotationError($result)];
        }
        return ["success" => "OK"];
    }

    /**
     * Format response for setting annotation.
     *
     * @param int    $textId Text ID
     * @param string $elem   Element selector
     * @param string $data   JSON-encoded data
     *
     * @return array{save_impr_text?: string|null, error?: string}
     */
    public function formatSetAnnotation(int $textId, string $elem, string $data): array
    {
        $decoded = json_decode($data);
        if (!is_object($decoded)) {
            return ["error" => "Invalid JSON data"];
        }
        $result = $this->saveImprText($textId, $elem, $decoded);
        if (array_key_exists("error", $result)) {
            return ["error" => $result["error"]];
        }
        return ["save_impr_text" => $result["success"] ?? null];
    }

    /**
     * Get print items and configuration for a text.
     *
     * @param int $textId Text ID
     *
     * @return array{items: array, config: array}|array{error: string}
     */
    public function getPrintItems(int $textId): array
    {
        $printService = new TextPrintService();

        $viewData = $printService->preparePlainPrintData($textId);
        if ($viewData === null) {
            return ['error' => 'Text not found'];
        }

        $items = $printService->getTextItemsForApi($textId);

        $savedAnn = $printService->getAnnotationSetting(null);
        $savedStatus = $printService->getStatusRangeSetting(null);
        $savedPlacement = $printService->getAnnotationPlacementSetting(null);

        return [
            'items' => $items,
            'config' => [
                'textId' => $textId,
                'title' => $viewData['title'],
                'sourceUri' => $viewData['sourceUri'],
                'audioUri' => $viewData['audioUri'],
                'langId' => $viewData['langId'],
                'textSize' => $viewData['textSize'],
                'rtlScript' => $viewData['rtlScript'],
                'hasAnnotation' => $viewData['hasAnnotation'],
                'savedAnn' => $savedAnn,
                'savedStatus' => $savedStatus,
                'savedPlacement' => $savedPlacement
            ]
        ];
    }

    /**
     * Format response for getting print items.
     *
     * @param int $textId Text ID
     *
     * @return array{items: array, config: array}|array{error: string}
     */
    public function formatGetPrintItems(int $textId): array
    {
        return $this->getPrintItems($textId);
    }

    /**
     * Get annotation items for improved/annotated text view.
     *
     * @param int $textId Text ID
     *
     * @return array{items: array|null, config: array}|array{error: string}
     */
    public function getAnnotation(int $textId): array
    {
        $printService = new TextPrintService();

        $viewData = $printService->prepareAnnotatedPrintData($textId);
        if ($viewData === null) {
            return ['error' => 'Text not found'];
        }

        $items = $printService->getAnnotationForApi($textId);

        return [
            'items' => $items,
            'config' => [
                'textId' => $textId,
                'title' => $viewData['title'],
                'sourceUri' => $viewData['sourceUri'],
                'audioUri' => $viewData['audioUri'],
                'langId' => $viewData['langId'],
                'textSize' => $viewData['textSize'],
                'rtlScript' => $viewData['rtlScript'],
                'hasAnnotation' => $viewData['hasAnnotation'],
                'ttsClass' => $viewData['ttsClass']
            ]
        ];
    }

    /**
     * Format response for getting annotation.
     *
     * @param int $textId Text ID
     *
     * @return array{items: array|null, config: array}|array{error: string}
     */
    public function formatGetAnnotation(int $textId): array
    {
        return $this->getAnnotation($textId);
    }

    /**
     * Make the translations choices for a term.
     *
     * @param int      $i     Word unique index in the form
     * @param int|null $wid   Word ID or null
     * @param string   $trans Current translation set for the term, may be empty
     * @param string   $word  Term text
     * @param int      $lang  Language ID
     *
     * @return string HTML-formatted string
     */
    public function makeTrans(int $i, ?int $wid, string $trans, string $word, int $lang): string
    {
        $trans = trim($trans);
        $widset = is_numeric($wid);
        $r = "";
        $set = false;
        if ($widset) {
            $alltrans = (string) QueryBuilder::table('words')
                ->where('WoID', '=', $wid)
                ->valuePrepared('WoTranslation');
            $transarr = preg_split('/[' . StringUtils::getSeparators() . ']/u', $alltrans);
            $set = false;
            if ($transarr === false) {
                $transarr = [];
            }
            foreach ($transarr as $t) {
                $tt = trim($t);
                if ($tt == '*' || $tt == '') {
                    continue;
                }
                $set = $set || $tt == $trans;
                $r .= '<span class="nowrap">
                    <input class="impr-ann-radio" ' .
                    ($tt == $trans ? 'checked="checked" ' : '') . 'type="radio" name="rg' .
                    $i . '" value="' . htmlspecialchars($tt, ENT_QUOTES, 'UTF-8') . '" />
                    &nbsp;' . htmlspecialchars($tt, ENT_QUOTES, 'UTF-8') . '
                </span>
                <br />';
            }
        }
        $r .= '<span class="nowrap">
        <input class="impr-ann-radio" type="radio" name="rg' . $i . '" ' .
        ($set ? 'checked="checked" ' : '') . 'value="" />
        &nbsp;
        <input class="impr-ann-text" type="text" name="tx' . $i .
        '" id="tx' . $i . '" value="' .
        ($set ? htmlspecialchars($trans, ENT_QUOTES, 'UTF-8') : '') .
        '" maxlength="50" size="40" />
         &nbsp;' . IconHelper::render(
            'eraser',
            [
            'title' => 'Erase Text Field',
            'alt' => 'Erase Text Field',
            'class' => 'click',
            'data-action' => 'erase-field',
            'data-target' => '#tx' . $i
            ]
        ) . '
         &nbsp;
        ' . IconHelper::render(
            'star',
            [
                'title' => '* (Set to Term)',
                'alt' => '* (Set to Term)',
                'class' => 'click',
                'data-action' => 'set-star',
                'data-target' => '#tx' . $i
            ]
        ) . '
        &nbsp;';
        if ($widset) {
            $r .= IconHelper::render(
                'circle-plus',
                [
                    'title' => 'Save another translation to existent term',
                    'alt' => 'Save another translation to existent term',
                    'class' => 'click',
                    'data-action' => 'update-term-translation',
                    'data-wid' => (string)$wid,
                    'data-target' => '#tx' . $i
                ]
            );
        } else {
            $r .= IconHelper::render(
                'circle-plus',
                [
                    'title' => 'Save translation to new term',
                    'alt' => 'Save translation to new term',
                    'class' => 'click',
                    'data-action' => 'add-term-translation',
                    'data-target' => '#tx' . $i,
                    'data-word' => htmlspecialchars($word, ENT_QUOTES, 'UTF-8'),
                    'data-lang' => (string)$lang
                ]
            );
        }
        $r .= '&nbsp;&nbsp;
        <span id="wait' . $i . '">
            ' . IconHelper::render('empty', []) . '
        </span>
        </span>';
        return $r;
    }

    /**
     * Full form for terms edition in a given text.
     *
     * @param int $textid Text ID.
     *
     * @return string HTML table for all terms
     */
    public function editTermForm(int $textid): string
    {
        $record = QueryBuilder::table('texts')
            ->select(['TxLgID', 'TxAnnotatedText'])
            ->where('TxID', '=', $textid)
            ->firstPrepared();
        if ($record === null) {
            return '<p>Text not found</p>';
        }
        $langid = (int) $record['TxLgID'];
        $ann = (string) $record['TxAnnotatedText'];
        if (strlen($ann) > 0) {
            $annotationService = new AnnotationService();
            $ann = $annotationService->recreateSaveAnnotation($textid, $ann);
        }

        $langRecord = QueryBuilder::table('languages')
            ->select(['LgTextSize', 'LgRightToLeft'])
            ->where('LgID', '=', $langid)
            ->firstPrepared();
        $textsize = $langRecord !== null ? (int)$langRecord['LgTextSize'] : 100;
        if ($textsize > 100) {
            $textsize = intval($textsize * 0.8);
        }
        $rtlScript = $langRecord !== null && !empty($langRecord['LgRightToLeft']);

        $dictionaryAdapter = new DictionaryAdapter();

        $r =
        '<form action="" method="post">' .
            \Lwt\Shared\UI\Helpers\FormHelper::csrfField() .
            '<table class="table is-bordered is-fullwidth">
                <tr>
                    <th class="has-text-centered">Text</th>
                    <th class="has-text-centered">Dict.</th>
                    <th class="has-text-centered">Edit<br />Term</th>
                    <th class="has-text-centered">
                        Term Translations (Delim.: ' .
                        htmlspecialchars(
                            Settings::getWithDefault('set-term-translation-delimiters'),
                            ENT_QUOTES,
                            'UTF-8'
                        ) . ')
                        <br />
                        <input type="button" value="Reload" data-action="reload-impr-text" />
                    </th>
                </tr>';
        $items = preg_split('/[\n]/u', $ann);
        if ($items === false) {
            return $r . '</table>';
        }
        $nontermbuffer = '';
        foreach ($items as $i => $item) {
            $vals = preg_split('/[\t]/u', $item);
            if ($vals === false) {
                continue;
            }
            if ((int)$vals[0] > -1) {
                if ($nontermbuffer != '') {
                    $r .= '<tr>
                        <td class="has-text-centered" style="font-size:' . $textsize . '%;">' .
                            $nontermbuffer .
                        '</td>
                        <td class="has-text-right" colspan="3">
                        ' . IconHelper::render(
                            'check',
                            [
                                'title' => "Back to 'Display/Print Mode'",
                                'alt' => "Back to 'Display/Print Mode'",
                                'class' => 'click',
                                'data-action' => 'back-to-print-mode',
                                'data-textid' => (string)$textid
                            ]
                        ) . '
                        </td>
                    </tr>';
                    $nontermbuffer = '';
                }
                $wid = null;
                $trans = '';
                if (count($vals) > 2) {
                    $strWid = $vals[2];
                    if (is_numeric($strWid)) {
                        $tempWid = QueryBuilder::table('words')
                            ->where('WoID', '=', $strWid)
                            ->countPrepared();
                        if ($tempWid < 1) {
                            $wid = null;
                        } else {
                            $wid = (int) $strWid;
                        }
                    } else {
                        $wid = null;
                    }
                }
                if (count($vals) > 3) {
                    $trans = $vals[3];
                }
                $wordLink = "&nbsp;";
                if ($wid !== null) {
                    $wordLink = '<a name="rec' . $i . '"></a>
                    <span class="click"
                    data-action="edit-term-popup" data-wid="' . $wid .
                    '" data-textid="' . $textid . '" data-ord="' . (int)$vals[0] . '">
                        ' .
                        IconHelper::render(
                            'file-pen-line',
                            ['title' => 'Edit Term', 'alt' => 'Edit Term']
                        ) . '
                    </span>';
                }
                $termText = $vals[1] ?? '';
                $r .= '<tr>
                    <td class="has-text-centered" style="font-size:' . $textsize . '%;"' .
                    ($rtlScript ? ' dir="rtl"' : '') . '>
                        <span id="term' . $i . '">' . htmlspecialchars($termText, ENT_QUOTES, 'UTF-8') .
                        '</span>
                    </td>
                    <td class="has-text-centered" nowrap="nowrap">' .
                        $dictionaryAdapter->makeDictLinks($langid, $termText) .
                    '</td>
                    <td class="has-text-centered">
                        <span id="editlink' . $i . '">' . $wordLink . '</span>
                    </td>
                    <td class="" style="font-size:90%;">
                        <span id="transsel' . $i . '">' .
                            $this->makeTrans($i, $wid, $trans, $termText, $langid) . '
                        </span>
                    </td>
                </tr>';
            } else {
                $nontermbuffer .= str_replace(
                    "¶",
                    '' . IconHelper::render('wrap-text', ['title' => 'New Line', 'alt' => 'New Line']) . '',
                    htmlspecialchars(trim($vals[1] ?? ''), ENT_QUOTES, 'UTF-8')
                );
            }
        }
        if ($nontermbuffer != '') {
            $r .= '<tr>
                <td class="has-text-centered" style="font-size:' . $textsize . '%;">' .
                $nontermbuffer .
                '</td>
                <td class="has-text-right" colspan="3">
                    ' . IconHelper::render(
                    'check',
                    [
                            'title' => "Back to 'Display/Print Mode'",
                            'alt' => "Back to 'Display/Print Mode'",
                            'class' => 'click',
                            'data-action' => 'back-to-print-mode',
                            'data-textid' => (string)$textid
                        ]
                ) . '
                </td>
            </tr>';
        }
        $r .= '
                    <th class="has-text-centered">Text</th>
                    <th class="has-text-centered">Dict.</th>
                    <th class="has-text-centered">Edit<br />Term</th>
                    <th class="has-text-centered">
                        Term Translations (Delim.: ' .
                        htmlspecialchars(
                            Settings::getWithDefault('set-term-translation-delimiters'),
                            ENT_QUOTES,
                            'UTF-8'
                        ) . ')
                        <br />
                        <input type="button" value="Reload" data-action="reload-impr-text" />
                        <a name="bottom"></a>
                    </th>
                </tr>
            </table>
        </form>';
        return $r;
    }

    /**
     * Format response for edit term form HTML.
     *
     * @param int $textId Text ID
     *
     * @return array{html: string}
     */
    public function formatEditTermForm(int $textId): array
    {
        return ['html' => $this->editTermForm($textId)];
    }
}
