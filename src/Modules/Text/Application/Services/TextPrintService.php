<?php

/**
 * Text Print Service - Business logic for text printing functionality
 *
 * Handles print operations for both plain text and improved annotated text.
 *
 * PHP version 8.2
 *
 * @category Lwt
 * @package  Lwt\Modules\Text\Application\Services
 * @author   HugoFara <git@hugofara.net>
 * @license  Unlicense <http://unlicense.org/>
 * @link     https://hugofara.github.io/lwt/developer/api
 * @since    3.0.0
 */

declare(strict_types=1);

namespace Lwt\Modules\Text\Application\Services;

use Lwt\Shared\Infrastructure\Database\Connection;
use Lwt\Shared\Infrastructure\Database\QueryBuilder;
use Lwt\Shared\Infrastructure\Database\Settings;
use Lwt\Shared\Infrastructure\Database\UserScopedQuery;
use Lwt\Modules\Tags\Application\TagsFacade;

/**
 * Service class for managing text printing operations.
 *
 * @category Lwt
 * @package  Lwt\Modules\Text\Application\Services
 * @author   HugoFara <git@hugofara.net>
 * @license  Unlicense <http://unlicense.org/>
 * @link     https://hugofara.github.io/lwt/developer/api
 * @since    3.0.0
 */
class TextPrintService
{
    /**
     * Annotation options - show romanization.
     */
    public const ANN_SHOW_ROM = 2;

    /**
     * Annotation options - show translation.
     */
    public const ANN_SHOW_TRANS = 1;

    /**
     * Annotation options - show tags.
     */
    public const ANN_SHOW_TAGS = 4;

    /**
     * Annotation placement - behind the term.
     */
    public const ANN_PLACEMENT_BEHIND = 0;

    /**
     * Annotation placement - in front of the term.
     */
    public const ANN_PLACEMENT_INFRONT = 1;

    /**
     * Annotation placement - above the term (ruby).
     */
    public const ANN_PLACEMENT_RUBY = 2;

    // ===========================
    // TEXT DATA METHODS
    // ===========================

    /**
     * Get basic text data for printing.
     *
     * @param int $textId Text ID
     *
     * @return array|null Text data or null if not found
     */
    public function getTextData(int $textId): ?array
    {
        $result = QueryBuilder::table('texts')
            ->select(['TxID', 'TxLgID', 'TxTitle', 'TxSourceURI', 'TxAudioURI'])
            ->where('TxID', '=', $textId)
            ->getPrepared();
        return !empty($result) ? $result[0] : null;
    }

    /**
     * Get language data for a text.
     *
     * @param int $langId Language ID
     *
     * @return array|null Language data or null if not found
     */
    public function getLanguageData(int $langId): ?array
    {
        $result = QueryBuilder::table('languages')
            ->select(['LgTextSize', 'LgRemoveSpaces', 'LgRightToLeft', 'LgGoogleTranslateURI'])
            ->where('LgID', '=', $langId)
            ->getPrepared();
        return !empty($result) ? $result[0] : null;
    }

    /**
     * Get annotated text for a text ID.
     *
     * @param int $textId Text ID
     *
     * @return string|null Annotated text or null if not found/empty
     */
    public function getAnnotatedText(int $textId): ?string
    {
        $result = QueryBuilder::table('texts')
            ->select(['TxAnnotatedText'])
            ->where('TxID', '=', $textId)
            ->getPrepared();
        $ann = !empty($result) ? (string) ($result[0]['TxAnnotatedText'] ?? '') : '';
        return strlen($ann) > 0 ? $ann : null;
    }

    /**
     * Check if annotated text exists for a text.
     *
     * @param int $textId Text ID
     *
     * @return bool True if annotated text exists
     */
    public function hasAnnotation(int $textId): bool
    {
        $bindings = [$textId];
        $sql = "SELECT LENGTH(TxAnnotatedText) AS len FROM texts WHERE TxID = ?"
            . UserScopedQuery::forTablePrepared('texts', $bindings);
        $result = Connection::preparedFetchAll($sql, $bindings);
        $length = !empty($result) ? (int) ($result[0]['len'] ?? 0) : 0;
        return $length > 0;
    }

    /**
     * Delete annotated text for a text.
     *
     * @param int $textId Text ID
     *
     * @return bool True if deletion was successful
     */
    public function deleteAnnotation(int $textId): bool
    {
        QueryBuilder::table('texts')
            ->where('TxID', '=', $textId)
            ->updatePrepared(['TxAnnotatedText' => null]);
        return !$this->hasAnnotation($textId);
    }

    // ===========================
    // PRINT SETTINGS METHODS
    // ===========================

    /**
     * Get current print annotation setting.
     *
     * @param string|null $requestValue Value from request
     *
     * @return int Annotation flags
     */
    public function getAnnotationSetting(?string $requestValue): int
    {
        if ($requestValue !== null && $requestValue !== '') {
            return (int) $requestValue;
        }
        $setting = Settings::get('currentprintannotation');
        return $setting !== '' ? (int) $setting : 3;
    }

    /**
     * Get current print status range setting.
     *
     * @param string|null $requestValue Value from request
     *
     * @return int Status range
     */
    public function getStatusRangeSetting(?string $requestValue): int
    {
        if ($requestValue !== null && $requestValue !== '') {
            return (int) $requestValue;
        }
        $setting = Settings::get('currentprintstatus');
        return $setting !== '' ? (int) $setting : 14;
    }

    /**
     * Get current annotation placement setting.
     *
     * @param string|null $requestValue Value from request
     *
     * @return int Placement code
     */
    public function getAnnotationPlacementSetting(?string $requestValue): int
    {
        if ($requestValue !== null && $requestValue !== '') {
            return (int) $requestValue;
        }
        $setting = Settings::get('currentprintannotationplacement');
        return $setting !== '' ? (int) $setting : 0;
    }

    /**
     * Save current print settings.
     *
     * @param int $textId      Text ID
     * @param int $annotation  Annotation flags
     * @param int $statusRange Status range
     * @param int $placement   Annotation placement
     *
     * @return void
     */
    public function savePrintSettings(
        int $textId,
        int $annotation,
        int $statusRange,
        int $placement
    ): void {
        Settings::savePerUser('currenttext', $textId);
        Settings::savePerUser('currentprintannotation', $annotation);
        Settings::savePerUser('currentprintstatus', $statusRange);
        Settings::savePerUser('currentprintannotationplacement', $placement);
    }

    /**
     * Save current text setting only.
     *
     * @param int $textId Text ID
     *
     * @return void
     */
    public function setCurrentText(int $textId): void
    {
        Settings::savePerUser('currenttext', $textId);
    }

    // ===========================
    // TEXT ITEMS METHODS
    // ===========================

    /**
     * Get text items for plain print display.
     *
     * @param int $textId Text ID
     *
     * @return array Array of text items with word data
     */
    public function getTextItems(int $textId): array
    {
        $bindings = [$textId];
        $sql = "SELECT
                    CASE WHEN Ti2WordCount>0 THEN Ti2WordCount ELSE 1 END AS Code,
                    CASE WHEN CHAR_LENGTH(Ti2Text)>0 THEN Ti2Text ELSE WoText END AS TiText,
                    Ti2Order,
                    CASE WHEN Ti2WordCount > 0 THEN 0 ELSE 1 END as TiIsNotWord,
                    WoID, WoTranslation, WoRomanization, WoStatus
                FROM word_occurrences
                LEFT JOIN words ON (Ti2WoID = WoID) AND (Ti2LgID = WoLgID)
                WHERE Ti2TxID = ?"
            . UserScopedQuery::forTablePrepared('words', $bindings, 'words') . "
                ORDER BY Ti2Order asc, Ti2WordCount desc";
        return Connection::preparedFetchAll($sql, $bindings);
    }

    /**
     * Get word tags for a word ID.
     *
     * @param int $wordId Word ID
     *
     * @return string Tags list
     */
    public function getWordTags(int $wordId): string
    {
        return TagsFacade::getWordTagList($wordId, false);
    }

    // ===========================
    // TTS (TEXT-TO-SPEECH) METHODS
    // ===========================

    /**
     * Extract TTS language code from Google Translate URI.
     *
     * @param string $googleTranslateUri Google Translate URI
     *
     * @return string|null TTS class suffix or null
     */
    public function getTtsClass(string $googleTranslateUri): ?string
    {
        if (empty($googleTranslateUri)) {
            return null;
        }
        $ttsLg = preg_replace(
            '/.*[?&]sl=([a-zA-Z\-]*)(&.*)*$/',
            '$1',
            $googleTranslateUri
        );
        if ($ttsLg !== null && $googleTranslateUri !== $ttsLg) {
            return 'tts_' . $ttsLg . ' ';
        }
        return null;
    }

    // ===========================
    // ANNOTATION PARSING METHODS
    // ===========================

    /**
     * Parse annotation string into structured items.
     *
     * @param string $annotation Annotation string
     *
     * @return array Array of parsed annotation items
     */
    public function parseAnnotation(string $annotation): array
    {
        $items = preg_split('/[\n]/u', $annotation);
        if ($items === false) {
            return [];
        }
        $parsed = [];
        foreach ($items as $item) {
            $vals = preg_split('/[\t]/u', $item);
            if ($vals === false) {
                continue;
            }
            $parsed[] = [
                'order' => isset($vals[0]) ? (int) $vals[0] : -1,
                'text' => $vals[1] ?? '',
                'wordId' => isset($vals[2]) && ctype_digit($vals[2]) ? (int) $vals[2] : null,
                'translation' => $vals[3] ?? ''
            ];
        }
        return $parsed;
    }

    // ===========================
    // STATUS CHECK METHODS
    // ===========================

    /**
     * Check if a word status is within the given range.
     *
     * @param int $status      Word status
     * @param int $statusRange Status range flags
     *
     * @return bool True if status is in range
     */
    public function checkStatusInRange(int $status, int $statusRange): bool
    {
        return \Lwt\Modules\Vocabulary\Application\Helpers\StatusHelper::checkRange($status, $statusRange);
    }

    // ===========================
    // API DATA METHODS
    // ===========================

    /**
     * Get text items formatted for API response.
     *
     * Returns structured data with word tags included, suitable for
     * client-side rendering of the print view.
     *
     * @param int $textId Text ID
     *
     * @return array Array of text items with full word data
     */
    public function getTextItemsForApi(int $textId): array
    {
        $bindings = [$textId];
        $sql = "SELECT
                    CASE WHEN Ti2WordCount>0 THEN Ti2WordCount ELSE 1 END AS wordCount,
                    CASE WHEN CHAR_LENGTH(Ti2Text)>0 THEN Ti2Text ELSE WoText END AS text,
                    Ti2Order AS position,
                    CASE WHEN Ti2WordCount > 0 THEN 0 ELSE 1 END as isNotWord,
                    WoID AS wordId, WoTranslation AS translation,
                    WoRomanization AS romanization, WoStatus AS status
                FROM word_occurrences
                LEFT JOIN words ON (Ti2WoID = WoID) AND (Ti2LgID = WoLgID)
                WHERE Ti2TxID = ?"
            . UserScopedQuery::forTablePrepared('words', $bindings, 'words') . "
                ORDER BY Ti2Order asc, Ti2WordCount desc";
        $results = Connection::preparedFetchAll($sql, $bindings);

        $items = [];
        $until = 0;
        $currentItem = null;

        foreach ($results as $record) {
            $order = (int) $record['position'];
            $wordCount = (int) $record['wordCount'];
            $isNotWord = (int) $record['isNotWord'];

            // Skip items that are part of a multi-word expression we've already started
            if ($order <= $until) {
                continue;
            }

            // Output previous item if we have one
            if ($currentItem !== null) {
                $items[] = $currentItem;
            }

            $until = $order;

            if ($isNotWord !== 0) {
                // Non-word item (punctuation, etc.)
                $text = (string)($record['text'] ?? '');
                $isParagraph = str_contains($text, '¶');

                $currentItem = [
                    'position' => $order,
                    'text' => $text,
                    'isWord' => false,
                    'isParagraph' => $isParagraph,
                    'wordId' => null,
                    'status' => null,
                    'translation' => '',
                    'romanization' => '',
                    'tags' => ''
                ];
            } else {
                // Word item
                $until = $order + 2 * ($wordCount - 1);

                $translation = (string)($record['translation'] ?? '');
                if ($translation === '*') {
                    $translation = '';
                }

                $tags = '';
                if (isset($record['wordId']) && $record['wordId'] !== null) {
                    $tags = $this->getWordTags((int) $record['wordId']);
                }

                $currentItem = [
                    'position' => $order,
                    'text' => $record['text'] ?? '',
                    'isWord' => true,
                    'isParagraph' => false,
                    'wordId' => isset($record['wordId']) ? (int) $record['wordId'] : null,
                    'status' => isset($record['status']) ? (int) $record['status'] : null,
                    'translation' => $translation,
                    'romanization' => trim((string) ($record['romanization'] ?? '')),
                    'tags' => $tags
                ];
            }
        }

        // Don't forget the last item
        if ($currentItem !== null) {
            $items[] = $currentItem;
        }

        return $items;
    }

    /**
     * Get annotated text items formatted for API response.
     *
     * Parses the stored annotation string into structured data.
     *
     * @param int $textId Text ID
     *
     * @return array|null Array of annotation items or null if no annotation
     */
    public function getAnnotationForApi(int $textId): ?array
    {
        $ann = $this->getAnnotatedText($textId);
        if ($ann === null) {
            return null;
        }

        // Recreate/update the annotation
        $annotationService = new AnnotationService();
        $ann = $annotationService->recreateSaveAnnotation($textId, $ann);
        if (strlen($ann) === 0) {
            return null;
        }

        $items = preg_split('/[\n]/u', $ann);
        if ($items === false) {
            return null;
        }
        $parsed = [];

        foreach ($items as $item) {
            $vals = preg_split('/[\t]/u', $item);
            if ($vals === false) {
                continue;
            }
            $order = isset($vals[0]) ? (int) $vals[0] : -1;
            $text = $vals[1] ?? '';
            $wordId = (isset($vals[2]) && ctype_digit($vals[2])) ? (int) $vals[2] : null;
            $translation = $vals[3] ?? '';

            // Handle special translation marker
            if ($translation === '*') {
                // U+200A HAIR SPACE
                $translation = $text . " ";
            }

            $parsed[] = [
                'order' => $order,
                'text' => $text,
                'wordId' => $wordId,
                'translation' => $translation,
                'isWord' => $order > -1
            ];
        }

        return $parsed;
    }

    // ===========================
    // VIEW DATA PREPARATION
    // ===========================

    /**
     * Prepare data for plain text print view.
     *
     * @param int $textId Text ID
     *
     * @return array|null View data or null if text not found
     */
    public function preparePlainPrintData(int $textId): ?array
    {
        $textData = $this->getTextData($textId);
        if ($textData === null) {
            return null;
        }

        $langData = $this->getLanguageData((int) $textData['TxLgID']);
        if ($langData === null) {
            return null;
        }

        return [
            'textId' => $textId,
            'title' => (string) $textData['TxTitle'],
            'sourceUri' => (string) $textData['TxSourceURI'],
            'audioUri' => trim((string) ($textData['TxAudioURI'] ?? '')),
            'langId' => (int) $textData['TxLgID'],
            'textSize' => (int) $langData['LgTextSize'],
            'rtlScript' => (bool) $langData['LgRightToLeft'],
            'hasAnnotation' => $this->hasAnnotation($textId)
        ];
    }

    /**
     * Prepare data for improved/annotated text print view.
     *
     * @param int $textId Text ID
     *
     * @return array|null View data or null if text not found
     */
    public function prepareAnnotatedPrintData(int $textId): ?array
    {
        $textData = $this->getTextData($textId);
        if ($textData === null) {
            return null;
        }

        $langData = $this->getLanguageData((int) $textData['TxLgID']);
        if ($langData === null) {
            return null;
        }

        $annotation = $this->getAnnotatedText($textId);
        $ttsClass = $this->getTtsClass((string) ($langData['LgGoogleTranslateURI'] ?? ''));

        return [
            'textId' => $textId,
            'title' => (string) $textData['TxTitle'],
            'sourceUri' => (string) $textData['TxSourceURI'],
            'audioUri' => trim((string) ($textData['TxAudioURI'] ?? '')),
            'langId' => (int) $textData['TxLgID'],
            'textSize' => (int) $langData['LgTextSize'],
            'rtlScript' => (bool) $langData['LgRightToLeft'],
            'annotation' => $annotation,
            'hasAnnotation' => $annotation !== null,
            'ttsClass' => $ttsClass
        ];
    }
}
