<?php

/**
 * MySQL Review Repository
 *
 * Infrastructure implementation for review/test persistence.
 *
 * PHP version 8.1
 *
 * @category Lwt
 * @package  Lwt\Modules\Review\Infrastructure
 * @author   HugoFara <git@hugofara.net>
 * @license  Unlicense <http://unlicense.org/>
 * @link     https://hugofara.github.io/lwt/developer/api
 * @since    3.0.0
 */

declare(strict_types=1);

namespace Lwt\Modules\Review\Infrastructure;

use Lwt\Shared\Infrastructure\Database\Connection;
use Lwt\Shared\Infrastructure\Database\QueryBuilder;
use Lwt\Shared\Infrastructure\Database\Settings;
use Lwt\Shared\Infrastructure\Database\UserScopedQuery;
use Lwt\Modules\Review\Domain\ReviewRepositoryInterface;
use Lwt\Modules\Review\Domain\ReviewConfiguration;
use Lwt\Modules\Review\Domain\ReviewWord;
use Lwt\Modules\Text\Application\Services\SentenceService;
use Lwt\Modules\Vocabulary\Application\Services\TermStatusService;
use Lwt\Modules\Activity\Infrastructure\MySqlActivityRepository;

/**
 * MySQL implementation of ReviewRepositoryInterface.
 *
 * Handles all database operations for the Review module.
 *
 * @since 3.0.0
 */
class MySqlReviewRepository implements ReviewRepositoryInterface
{
    private SentenceService $sentenceService;
    private MySqlActivityRepository $activityRepository;

    /**
     * Constructor.
     *
     * @param SentenceService|null        $sentenceService    Sentence service (optional)
     * @param MySqlActivityRepository|null $activityRepository Activity repository (optional)
     */
    public function __construct(
        ?SentenceService $sentenceService = null,
        ?MySqlActivityRepository $activityRepository = null
    ) {
        $this->sentenceService = $sentenceService ?? new SentenceService();
        $this->activityRepository = $activityRepository ?? new MySqlActivityRepository();
    }

    /**
     * {@inheritdoc}
     */
    public function findNextWordForReview(ReviewConfiguration $config): ?ReviewWord
    {
        $params = [];
        $reviewsql = $config->toSqlProjectionPrepared($params);
        $due = ScheduleSql::effectiveDue();

        // Most overdue first, ties broken at random. The legacy query needed
        // two passes because it sampled on WoRandom to shuffle equal scores;
        // ordering by an actual due date makes that unnecessary.
        $sql = "SELECT DISTINCT WoID, WoText, WoTextLC, WoTranslation,
            WoRomanization, WoSentence, WoLgID,
            (IFNULL(WoSentence, '') NOT LIKE CONCAT('%{', WoText, '}%')) AS notvalid,
            WoStatus,
            DATEDIFF(NOW(), WoStatusChanged) AS Days,
            DATEDIFF(" . ScheduleSql::effectiveDue() . ", NOW()) AS Score
            FROM $reviewsql AND WoStatus BETWEEN 1 AND 5
            AND WoTranslation != '' AND WoTranslation != '*' AND " . ScheduleSql::isDue() . "
            ORDER BY $due, RAND()
            LIMIT 1";

        $rows = Connection::preparedFetchAll($sql, $params);
        $record = $rows[0] ?? null;

        return $record !== null ? ReviewWord::fromRecord($record) : null;
    }

    /**
     * {@inheritdoc}
     */
    public function getSentenceForWord(int $wordId, string $wordLc): array
    {
        // Find sentence with at least 70% known words
        $sql = "SELECT DISTINCT ti.Ti2SeID AS SeID,
            1 - IFNULL(sUnknownCount.c, 0) / sWordCount.c AS KnownRatio
            FROM word_occurrences ti
            JOIN (
                SELECT t.Ti2SeID, COUNT(*) AS c
                FROM word_occurrences t
                WHERE t.Ti2WordCount = 1
                GROUP BY t.Ti2SeID
            ) AS sWordCount ON sWordCount.Ti2SeID = ti.Ti2SeID
            LEFT JOIN (
                SELECT t.Ti2SeID, COUNT(*) AS c
                FROM word_occurrences t
                WHERE t.Ti2WordCount = 1 AND t.Ti2WoID IS NULL
                GROUP BY t.Ti2SeID
            ) AS sUnknownCount ON sUnknownCount.Ti2SeID = ti.Ti2SeID
            WHERE ti.Ti2WoID = ?
            ORDER BY KnownRatio < 0.7, RAND()
            LIMIT 1";

        $rows = Connection::preparedFetchAll($sql, [$wordId]);
        $record = $rows[0] ?? null;

        if ($record === null) {
            return ['sentence' => null, 'found' => false];
        }

        $seid = (int) $record['SeID'];
        $sentenceCount = (int) Settings::getWithDefault('set-test-sentence-count');
        list($_, $sentence) = $this->sentenceService->formatSentence($seid, $wordLc, $sentenceCount);

        return ['sentence' => $sentence, 'found' => true];
    }

    /**
     * {@inheritdoc}
     */
    public function getReviewCounts(ReviewConfiguration $config): array
    {
        $dueParams = [];
        $dueReviewsql = $config->toSqlProjectionPrepared($dueParams);

        $due = (int) Connection::preparedFetchValue(
            "SELECT COUNT(DISTINCT WoID) AS cnt
            FROM $dueReviewsql AND WoStatus BETWEEN 1 AND 5
            AND WoTranslation != '' AND WoTranslation != '*' AND " . ScheduleSql::isDue(),
            $dueParams,
            'cnt'
        );

        $totalParams = [];
        $totalReviewsql = $config->toSqlProjectionPrepared($totalParams);

        $total = (int) Connection::preparedFetchValue(
            "SELECT COUNT(DISTINCT WoID) AS cnt
            FROM $totalReviewsql AND WoStatus BETWEEN 1 AND 5
            AND WoTranslation != '' AND WoTranslation != '*'",
            $totalParams,
            'cnt'
        );

        return ['due' => $due, 'total' => $total];
    }

    /**
     * {@inheritdoc}
     */
    public function getTomorrowCount(ReviewConfiguration $config): int
    {
        $params = [];
        $reviewsql = $config->toSqlProjectionPrepared($params);

        return (int) Connection::preparedFetchValue(
            "SELECT COUNT(DISTINCT WoID) AS cnt
            FROM $reviewsql AND WoStatus BETWEEN 1 AND 5
            AND WoTranslation != '' AND WoTranslation != '*' AND " . ScheduleSql::isDueTomorrow(),
            $params,
            'cnt'
        );
    }

    /**
     * {@inheritdoc}
     */
    public function getTableWords(ReviewConfiguration $config): array
    {
        $params = [];
        $reviewsql = $config->toSqlProjectionPrepared($params);

        $sql = "SELECT DISTINCT WoID, WoText, WoTextLC, WoTranslation, WoRomanization,
            WoSentence, WoLgID, WoStatus,
            DATEDIFF(" . ScheduleSql::effectiveDue() . ", NOW()) AS Score,
            DATEDIFF(NOW(), WoStatusChanged) AS Days
            FROM $reviewsql AND WoStatus BETWEEN 1 AND 5
            AND WoTranslation != '' AND WoTranslation != '*'
            ORDER BY " . ScheduleSql::effectiveDue() . ", RAND()";

        $rows = Connection::preparedFetchAll($sql, $params);
        $words = [];

        foreach ($rows as $record) {
            $words[] = ReviewWord::fromRecord($record);
        }

        return $words;
    }

    /**
     * {@inheritdoc}
     */
    public function updateWordStatus(int $wordId, int $newStatus): array
    {
        $oldStatus = (int) QueryBuilder::table('words')
            ->where('WoID', '=', $wordId)
            ->valuePrepared('WoStatus');

        $bindings = [$newStatus, $wordId];
        $userScope = UserScopedQuery::forTablePrepared('words', $bindings);
        Connection::preparedExecute(
            "UPDATE words
            SET WoStatus = ?, WoStatusChanged = NOW()
            WHERE WoID = ?" . $userScope,
            $bindings
        );

        $this->activityRepository->incrementTermsReviewed();

        return [
            'oldStatus' => $oldStatus,
            'newStatus' => $newStatus,
        ];
    }

    /**
     * {@inheritdoc}
     */
    public function getWordStatus(int $wordId): ?int
    {
        /** @var mixed $status */
        $status = QueryBuilder::table('words')
            ->where('WoID', '=', $wordId)
            ->valuePrepared('WoStatus');

        return $status !== null ? (int) $status : null;
    }

    /**
     * {@inheritdoc}
     */
    public function getLanguageSettings(int $langId): array
    {
        $record = QueryBuilder::table('languages')
            ->select([
                'LgName', 'LgDict1URI', 'LgDict2URI', 'LgGoogleTranslateURI',
                'LgTextSize', 'LgRemoveSpaces', 'LgRegexpWordCharacters',
                'LgRightToLeft', 'LgTTSVoiceAPI'
            ])
            ->where('LgID', '=', $langId)
            ->firstPrepared();

        if ($record === null) {
            return [
                'name' => '',
                'dict1Uri' => '',
                'dict2Uri' => '',
                'translateUri' => '',
                'textSize' => 100,
                'removeSpaces' => false,
                'regexWord' => '',
                'rtl' => false,
                'ttsVoiceApi' => null
            ];
        }

        /** @var mixed $nameVal */
        $nameVal = $record['LgName'] ?? '';
        /** @var mixed $regexVal */
        $regexVal = $record['LgRegexpWordCharacters'] ?? '';
        /** @var mixed $ttsVal */
        $ttsVal = $record['LgTTSVoiceAPI'] ?? null;

        return [
            'name' => is_string($nameVal) ? $nameVal : '',
            'dict1Uri' => is_string($record['LgDict1URI'] ?? '') ? (string) ($record['LgDict1URI'] ?? '') : '',
            'dict2Uri' => is_string($record['LgDict2URI'] ?? '') ? (string) ($record['LgDict2URI'] ?? '') : '',
            'translateUri' => is_string($record['LgGoogleTranslateURI'] ?? '')
                ? (string) ($record['LgGoogleTranslateURI'] ?? '')
                : '',
            'textSize' => (int) $record['LgTextSize'],
            'removeSpaces' => (bool) $record['LgRemoveSpaces'],
            'regexWord' => is_string($regexVal) ? $regexVal : '',
            'rtl' => (bool) $record['LgRightToLeft'],
            'ttsVoiceApi' => is_string($ttsVal) ? $ttsVal : null
        ];
    }

    /**
     * {@inheritdoc}
     */
    public function getLanguageIdFromConfig(ReviewConfiguration $config): ?int
    {
        $params = [];
        $reviewsql = $config->toSqlProjectionPrepared($params);

        /** @var mixed $langId */
        $langId = Connection::preparedFetchValue(
            "SELECT WoLgID FROM $reviewsql LIMIT 1",
            $params,
            'WoLgID'
        );

        return $langId !== null ? (int) $langId : null;
    }

    /**
     * {@inheritdoc}
     */
    public function validateSingleLanguage(ReviewConfiguration $config): array
    {
        $params = [];
        $reviewsql = $config->toSqlProjectionPrepared($params);

        $langCount = (int) Connection::preparedFetchValue(
            "SELECT COUNT(DISTINCT WoLgID) AS cnt FROM $reviewsql",
            $params,
            'cnt'
        );

        if ($langCount > 1) {
            return [
                'valid' => false,
                'langCount' => $langCount,
                'error' => "The selected terms are in $langCount languages, " .
                    "but tests are only possible in one language at a time."
            ];
        }

        return [
            'valid' => true,
            'langCount' => $langCount,
            'error' => null
        ];
    }

    /**
     * {@inheritdoc}
     */
    public function getLanguageName(ReviewConfiguration $config): string
    {
        if ($config->reviewKey === ReviewConfiguration::KEY_LANG) {
            /** @var mixed $name */
            $name = QueryBuilder::table('languages')
                ->where('LgID', '=', $config->selection)
                ->valuePrepared('LgName');
            return $name !== null ? (string) $name : 'L2';
        }

        if ($config->reviewKey === ReviewConfiguration::KEY_TEXT) {
            $row = QueryBuilder::table('texts')
                ->select(['LgName'])
                ->join('languages', 'TxLgID', '=', 'LgID')
                ->where('TxID', '=', $config->selection)
                ->firstPrepared();
            /** @var mixed $name */
            $name = $row['LgName'] ?? null;
            return is_string($name) ? $name : 'L2';
        }

        // For selection-based tests, get language from first word
        $validation = $this->validateSingleLanguage($config);

        if ($validation['langCount'] === 1) {
            $params = [];
            $reviewsql = $config->toSqlProjectionPrepared($params);
            $bindings = [];
            $userScope = UserScopedQuery::forTablePrepared('words', $bindings);
            /** @var mixed $name */
            $name = Connection::preparedFetchValue(
                "SELECT LgName
                FROM languages, {$reviewsql} AND LgID = WoLgID"
                . $userScope . "
                LIMIT 1",
                array_merge($params, $bindings),
                'LgName'
            );
            return $name !== null ? (string) $name : 'L2';
        }

        return 'L2';
    }

    /**
     * {@inheritdoc}
     */
    public function getWordText(int $wordId): ?string
    {
        /** @var mixed $text */
        $text = QueryBuilder::table('words')
            ->where('WoID', '=', $wordId)
            ->valuePrepared('WoText');

        return $text !== null ? (string) $text : null;
    }

    /**
     * {@inheritdoc}
     */
    public function getTableReviewSettings(): array
    {
        return [
            'edit' => Settings::getZeroOrOne('currenttabletestsetting1', 1),
            'status' => Settings::getZeroOrOne('currenttabletestsetting2', 1),
            'term' => Settings::getZeroOrOne('currenttabletestsetting3', 0),
            'trans' => Settings::getZeroOrOne('currenttabletestsetting4', 1),
            'rom' => Settings::getZeroOrOne('currenttabletestsetting5', 0),
            'sentence' => Settings::getZeroOrOne('currenttabletestsetting6', 1),
            'contextRom' => Settings::getZeroOrOne('currenttabletestsetting7', 0),
            'contextTrans' => Settings::getZeroOrOne('currenttabletestsetting8', 0)
        ];
    }

    /**
     * {@inheritdoc}
     *
     * @return array{
     *     sentence: string|null,
     *     sentenceId: int|null,
     *     found: bool,
     *     annotations: array<int, array{
     *         text: string,
     *         romanization: string|null,
     *         translation: string|null,
     *         isTarget: bool,
     *         order: int
     *     }>
     * }
     */
    public function getSentenceWithAnnotations(int $wordId, string $wordLc): array
    {
        // First, find the best sentence (same logic as getSentenceForWord)
        $sql = "SELECT DISTINCT ti.Ti2SeID AS SeID, ti.Ti2TxID AS TxID,
            1 - IFNULL(sUnknownCount.c, 0) / sWordCount.c AS KnownRatio
            FROM word_occurrences ti
            JOIN (
                SELECT t.Ti2SeID, COUNT(*) AS c
                FROM word_occurrences t
                WHERE t.Ti2WordCount = 1
                GROUP BY t.Ti2SeID
            ) AS sWordCount ON sWordCount.Ti2SeID = ti.Ti2SeID
            LEFT JOIN (
                SELECT t.Ti2SeID, COUNT(*) AS c
                FROM word_occurrences t
                WHERE t.Ti2WordCount = 1 AND t.Ti2WoID IS NULL
                GROUP BY t.Ti2SeID
            ) AS sUnknownCount ON sUnknownCount.Ti2SeID = ti.Ti2SeID
            WHERE ti.Ti2WoID = ?
            ORDER BY KnownRatio < 0.7, RAND()
            LIMIT 1";

        $rows = Connection::preparedFetchAll($sql, [$wordId]);
        $record = $rows[0] ?? null;

        if ($record === null) {
            return ['sentence' => null, 'sentenceId' => null, 'found' => false, 'annotations' => []];
        }

        $seid = (int) $record['SeID'];
        $txid = (int) $record['TxID'];
        $sentenceCount = (int) Settings::getWithDefault('set-test-sentence-count');

        // Get the formatted sentence
        list($_, $sentence) = $this->sentenceService->formatSentence($seid, $wordLc, $sentenceCount);

        // Now fetch all word annotations for this sentence (and surrounding sentences if mode > 1)
        $annotations = $this->fetchSentenceAnnotations($seid, $txid, $sentenceCount, $wordLc);

        return [
            'sentence' => $sentence,
            'sentenceId' => $seid,
            'found' => true,
            'annotations' => $annotations
        ];
    }

    /**
     * Fetch word annotations for a sentence and surrounding context.
     *
     * @param int    $seid          Main sentence ID
     * @param int    $txid          Text ID
     * @param int    $sentenceCount Number of sentences (1=current, 2=prev+current, 3=prev+current+next)
     * @param string $targetWordLc  Lowercase target word text
     *
     * @return array<int, array{
     *     text: string,
     *     romanization: string|null,
     *     translation: string|null,
     *     isTarget: bool,
     *     order: int
     * }>
     */
    private function fetchSentenceAnnotations(int $seid, int $txid, int $sentenceCount, string $targetWordLc): array
    {
        // Build list of sentence IDs to include
        $sentenceIds = [$seid];

        if ($sentenceCount > 1) {
            // Get previous sentence
            /** @var mixed $prevSeid */
            $prevSeid = Connection::preparedFetchValue(
                "SELECT SeID FROM sentences
                WHERE SeID < ? AND SeTxID = ?
                AND TRIM(SeText) NOT IN ('¶', '')
                ORDER BY SeID DESC LIMIT 1",
                [$seid, $txid],
                'SeID'
            );
            if ($prevSeid !== null) {
                array_unshift($sentenceIds, (int) $prevSeid);
            }
        }

        if ($sentenceCount > 2) {
            // Get next sentence
            /** @var mixed $nextSeid */
            $nextSeid = Connection::preparedFetchValue(
                "SELECT SeID FROM sentences
                WHERE SeID > ? AND SeTxID = ?
                AND TRIM(SeText) NOT IN ('¶', '')
                ORDER BY SeID ASC LIMIT 1",
                [$seid, $txid],
                'SeID'
            );
            if ($nextSeid !== null) {
                $sentenceIds[] = (int) $nextSeid;
            }
        }

        if (empty($sentenceIds)) {
            return [];
        }

        $placeholders = implode(',', array_fill(0, count($sentenceIds), '?'));

        // Fetch all text items with their word data
        $sql = "SELECT ti.Ti2Order, ti.Ti2Text, ti.Ti2WordCount, ti.Ti2WoID,
                w.WoTextLC, w.WoRomanization, w.WoTranslation
            FROM word_occurrences ti
            LEFT JOIN words w ON ti.Ti2WoID = w.WoID
            WHERE ti.Ti2SeID IN ($placeholders) AND ti.Ti2WordCount < 2
            AND ti.Ti2Text != '¶'
            ORDER BY ti.Ti2Order";

        $rows = Connection::preparedFetchAll($sql, $sentenceIds);

        $annotations = [];
        foreach ($rows as $row) {
            $order = (int) $row['Ti2Order'];
            $text = (string) $row['Ti2Text'];
            /** @var mixed $woId */
            $woId = $row['Ti2WoID'];
            $isTarget = mb_strtolower($text, 'UTF-8') === $targetWordLc;

            // Only include annotation data if the word is known (has a WoID)
            if ($woId !== null) {
                /** @var mixed $romanization */
                $romanization = $row['WoRomanization'];
                $annotations[$order] = [
                    'text' => $text,
                    'romanization' => ($romanization === null || $romanization === '') ? null : (string)$romanization,
                    'translation' => $this->getFirstTranslation((string)($row['WoTranslation'] ?? '')),
                    'isTarget' => $isTarget,
                    'order' => $order
                ];
            } else {
                // Still track the position for non-word tokens (punctuation, etc.)
                $annotations[$order] = [
                    'text' => $text,
                    'romanization' => null,
                    'translation' => null,
                    'isTarget' => false,
                    'order' => $order
                ];
            }
        }

        return $annotations;
    }

    /**
     * Get the first translation from a translation string.
     *
     * @param string $trans Full translation string (may contain separators)
     *
     * @return string|null First translation only, or null if empty
     */
    private function getFirstTranslation(string $trans): ?string
    {
        if ($trans === '' || $trans === '*') {
            return null;
        }
        $arr = preg_split('/[' . \Lwt\Shared\Infrastructure\Utilities\StringUtils::getSeparators() . ']/u', $trans);
        if ($arr === false) {
            return null;
        }
        $r = trim($arr[0]);
        return $r !== '' && $r !== '*' ? $r : null;
    }
}
