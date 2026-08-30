<?php

/**
 * Review Facade
 *
 * Backward-compatible facade for review/test operations.
 *
 * PHP version 8.2
 *
 * @category Lwt
 * @package  Lwt\Modules\Review\Application
 * @author   HugoFara <git@hugofara.net>
 * @license  Unlicense <http://unlicense.org/>
 * @link     https://hugofara.github.io/lwt/developer/api
 * @since    3.0.0
 */

declare(strict_types=1);

namespace Lwt\Modules\Review\Application;

use Lwt\Modules\Review\Application\UseCases\GetNextTerm;
use Lwt\Modules\Review\Application\UseCases\GetTableWords;
use Lwt\Modules\Review\Application\UseCases\GetReviewConfiguration;
use Lwt\Modules\Review\Application\UseCases\GetTomorrowCount;
use Lwt\Modules\Review\Application\UseCases\RecordScheduledReview;
use Lwt\Modules\Review\Application\UseCases\StartReviewSession;
use Lwt\Modules\Review\Application\UseCases\SubmitAnswer;
use Lwt\Modules\Review\Domain\Scheduling\Rating;
use Lwt\Modules\Review\Domain\ReviewRepositoryInterface;
use Lwt\Modules\Review\Domain\ReviewSession;
use Lwt\Modules\Review\Domain\ReviewConfiguration;
use Lwt\Modules\Review\Infrastructure\MySqlReviewRepository;
use Lwt\Modules\Review\Infrastructure\ScheduleSql;
use Lwt\Modules\Review\Infrastructure\SessionStateManager;
use Lwt\Shared\Infrastructure\Database\Connection;
use Lwt\Modules\Vocabulary\Application\Services\ExportService;

/**
 * Facade for review module operations.
 *
 * Provides a unified interface to all review-related use cases.
 * Designed for backward compatibility with existing TestService callers.
 *
 * @since 3.0.0
 */
class ReviewFacade
{
    protected ReviewRepositoryInterface $repository;
    protected SessionStateManager $sessionManager;
    protected GetNextTerm $getNextTerm;
    protected GetTableWords $getTableWords;
    protected GetReviewConfiguration $getReviewConfiguration;
    protected GetTomorrowCount $getTomorrowCount;
    protected StartReviewSession $startReviewSession;
    protected SubmitAnswer $submitAnswer;
    protected RecordScheduledReview $scheduledReview;

    /**
     * Constructor.
     *
     * @param ReviewRepositoryInterface|null $repository           Review repository
     * @param SessionStateManager|null       $sessionManager       Session manager
     * @param GetNextTerm|null               $getNextTerm          Get next term use case
     * @param GetTableWords|null             $getTableWords        Get table words use case
     * @param GetReviewConfiguration|null      $getReviewConfiguration Get config use case
     * @param GetTomorrowCount|null          $getTomorrowCount     Tomorrow count use case
     * @param StartReviewSession|null        $startReviewSession   Start session use case
     * @param SubmitAnswer|null              $submitAnswer         Submit answer use case
     * @param RecordScheduledReview|null     $scheduledReview      FSRS scheduling use case
     */
    public function __construct(
        ?ReviewRepositoryInterface $repository = null,
        ?SessionStateManager $sessionManager = null,
        ?GetNextTerm $getNextTerm = null,
        ?GetTableWords $getTableWords = null,
        ?GetReviewConfiguration $getReviewConfiguration = null,
        ?GetTomorrowCount $getTomorrowCount = null,
        ?StartReviewSession $startReviewSession = null,
        ?SubmitAnswer $submitAnswer = null,
        ?RecordScheduledReview $scheduledReview = null
    ) {
        $this->repository = $repository ?? new MySqlReviewRepository();
        $this->sessionManager = $sessionManager ?? new SessionStateManager();
        $this->getNextTerm = $getNextTerm ?? new GetNextTerm($this->repository);
        $this->getTableWords = $getTableWords ?? new GetTableWords($this->repository);
        $this->getReviewConfiguration = $getReviewConfiguration
            ?? new GetReviewConfiguration($this->repository, $this->sessionManager);
        $this->getTomorrowCount = $getTomorrowCount ?? new GetTomorrowCount($this->repository);
        $this->startReviewSession = $startReviewSession
            ?? new StartReviewSession($this->repository, $this->sessionManager);
        $this->submitAnswer = $submitAnswer
            ?? new SubmitAnswer($this->repository, $this->sessionManager);
        $this->scheduledReview = $scheduledReview ?? new RecordScheduledReview();
    }

    // ==========================================
    // BACKWARD COMPATIBILITY METHODS (TestService)
    // ==========================================

    /**
     * Get test identifier from request parameters.
     *
     * @param int|null    $selection   Test is of type selection
     * @param string|null $sessTestsql SQL string for test
     * @param int|null    $lang        Test is of type language
     * @param int|null    $text        Testing text with ID $text
     *
     * @return array{0: string, 1: int|int[]|string} Selector type and selection value
     */
    public function getReviewIdentifier(
        ?int $selection,
        ?string $sessTestsql,
        ?int $lang,
        ?int $text
    ): array {
        $config = $this->getReviewConfiguration->parseFromParams(
            $selection,
            $sessTestsql,
            $lang,
            $text
        );

        if (!$config->isValid()) {
            return ['', ''];
        }

        return [$config->reviewKey, $config->selection];
    }

    /**
     * Get SQL projection for test with prepared statement parameters.
     *
     * @param string    $selector  Type of test
     * @param int|int[] $selection Selection value
     *
     * @return array{sql: string, params: array<int, int|string>}|null SQL and params, or null
     */
    public function getReviewSql(string $selector, int|array $selection): ?array
    {
        $config = new ReviewConfiguration($selector, $selection);
        try {
            $params = [];
            $sql = $config->toSqlProjectionPrepared($params);
            return ['sql' => $sql, 'params' => $params];
        } catch (\InvalidArgumentException $e) {
            error_log('ReviewFacade::getReviewSql: ' . $e->getMessage());
            return null;
        }
    }

    /**
     * Validate test selection.
     *
     * @param string          $reviewsql SQL projection string with ? placeholders
     * @param array<int, int|string> $params    Bound parameters for the SQL
     *
     * @return array{valid: bool, langCount: int, error: string|null}
     */
    public function validateReviewSelection(string $reviewsql, array $params = []): array
    {
        $config = new ReviewConfiguration(
            ReviewConfiguration::KEY_RAW_SQL,
            $reviewsql,
            rawParams: $params
        );
        return $this->repository->validateSingleLanguage($config);
    }

    /**
     * Get test counts.
     *
     * @param string          $reviewsql SQL projection string with ? placeholders
     * @param array<int, int|string> $params    Bound parameters for the SQL
     *
     * @return array{due: int, total: int}
     */
    public function getReviewCounts(string $reviewsql, array $params = []): array
    {
        $config = new ReviewConfiguration(
            ReviewConfiguration::KEY_RAW_SQL,
            $reviewsql,
            rawParams: $params
        );
        return $this->repository->getReviewCounts($config);
    }

    /**
     * Get tomorrow's test count.
     *
     * @param string          $reviewsql SQL projection string with ? placeholders
     * @param array<int, int|string> $params    Bound parameters for the SQL
     *
     * @return int
     */
    public function getTomorrowReviewCount(string $reviewsql, array $params = []): int
    {
        $config = new ReviewConfiguration(
            ReviewConfiguration::KEY_RAW_SQL,
            $reviewsql,
            rawParams: $params
        );
        $result = $this->getTomorrowCount->execute($config);
        return $result['count'];
    }

    /**
     * Get the next word to test.
     *
     * @param string          $reviewsql SQL projection string with ? placeholders
     * @param array<int, int|string> $params    Bound parameters for the SQL
     *
     * @return array<string, mixed>|null Word record or null
     */
    public function getNextWord(string $reviewsql, array $params = []): ?array
    {
        $config = new ReviewConfiguration(
            ReviewConfiguration::KEY_RAW_SQL,
            $reviewsql,
            rawParams: $params
        );
        $word = $this->repository->findNextWordForReview($config);

        if ($word === null) {
            return null;
        }

        return [
            'WoID' => $word->id,
            'WoText' => $word->text,
            'WoTextLC' => $word->textLowercase,
            'WoTranslation' => $word->translation,
            'WoRomanization' => $word->romanization,
            'WoSentence' => $word->sentence,
            'WoLgID' => $word->languageId,
            'WoStatus' => $word->status,
            'Days' => $word->daysOld,
            'Score' => $word->score,
            'notvalid' => $word->needsNewSentence() ? 1 : 0
        ];
    }

    /**
     * Get sentence for word.
     *
     * @param int    $wordId Word ID
     * @param string $wordlc Lowercase word text
     *
     * @return array{sentence: string|null, found: bool}
     */
    public function getSentenceForWord(int $wordId, string $wordlc): array
    {
        return $this->repository->getSentenceForWord($wordId, $wordlc);
    }

    /**
     * Get sentence for word with annotations for surrounding words.
     *
     * @param int    $wordId Word ID
     * @param string $wordlc Lowercase word text
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
    public function getSentenceWithAnnotations(int $wordId, string $wordlc): array
    {
        return $this->repository->getSentenceWithAnnotations($wordId, $wordlc);
    }

    /**
     * Update word status.
     *
     * @param int $wordId    Word ID
     * @param int $newStatus New status
     *
     * @return array{oldStatus: int, newStatus: int}
     */
    public function updateWordStatus(int $wordId, int $newStatus): array
    {
        return $this->repository->updateWordStatus($wordId, $newStatus);
    }

    /**
     * Get language settings.
     *
     * @param int $langId Language ID
     *
     * @return array
     */
    public function getLanguageSettings(int $langId): array
    {
        return $this->repository->getLanguageSettings($langId);
    }

    /**
     * Get language ID from test SQL.
     *
     * @param string          $reviewsql Test SQL with ? placeholders
     * @param array<int, int|string> $params    Bound parameters for the SQL
     *
     * @return int|null
     */
    public function getLanguageIdFromReviewSql(string $reviewsql, array $params = []): ?int
    {
        $config = new ReviewConfiguration(
            ReviewConfiguration::KEY_RAW_SQL,
            $reviewsql,
            rawParams: $params
        );
        return $this->repository->getLanguageIdFromConfig($config);
    }

    /**
     * Initialize review session.
     *
     * @param int $totalDue Total words due
     *
     * @return void
     */
    public function initializeReviewSession(int $totalDue): void
    {
        $session = ReviewSession::start($totalDue);
        $this->sessionManager->saveSession($session);
    }

    /**
     * Get review session data.
     *
     * @return array{start: int, correct: int, wrong: int, total: int}
     */
    public function getReviewSessionData(): array
    {
        $session = $this->sessionManager->getSession();
        if ($session === null) {
            return ['start' => 0, 'correct' => 0, 'wrong' => 0, 'total' => 0];
        }

        return [
            'start' => $session->getStartTime(),
            'correct' => $session->getCorrect(),
            'wrong' => $session->getWrong(),
            'total' => $session->getTotal()
        ];
    }

    /**
     * Update session progress.
     *
     * @param int $statusChange Status change direction
     *
     * @return array{total: int, wrong: int, correct: int, remaining: int}
     */
    public function updateSessionProgress(int $statusChange): array
    {
        $session = $this->sessionManager->getSession();
        if ($session === null) {
            return ['total' => 0, 'wrong' => 0, 'correct' => 0, 'remaining' => 0];
        }

        $session->recordAnswer($statusChange);
        $this->sessionManager->saveSession($session);

        return [
            'total' => $session->getTotal(),
            'wrong' => $session->getWrong(),
            'correct' => $session->getCorrect(),
            'remaining' => $session->remaining()
        ];
    }

    /**
     * Get table test settings.
     *
     * @return array
     */
    public function getTableReviewSettings(): array
    {
        return $this->repository->getTableReviewSettings();
    }

    /**
     * Get table test words.
     *
     * @param string          $reviewsql SQL projection with ? placeholders
     * @param array<int, int|string> $params    Bound parameters for the SQL
     *
     * @return array<int, array<string, mixed>> Query results as array
     */
    public function getTableReviewWords(string $reviewsql, array $params = []): array
    {
        $sql = "SELECT DISTINCT WoID, WoText, WoTranslation, WoRomanization,
            WoSentence, WoStatus,
            DATEDIFF(" . ScheduleSql::effectiveDue() . ", NOW()) AS Score
            FROM $reviewsql AND WoStatus BETWEEN 1 AND 5
            AND WoTranslation != '' AND WoTranslation != '*'
            ORDER BY " . ScheduleSql::effectiveDue() . ", RAND()";

        return Connection::preparedFetchAll($sql, $params);
    }

    /**
     * Get L2 language name.
     *
     * @param int|null    $lang      Language ID
     * @param int|null    $text      Text ID
     * @param int|null    $selection Selection type
     * @param string|null $reviewsql   Test SQL
     *
     * @return string
     */
    public function getL2LanguageName(
        ?int $lang,
        ?int $text,
        ?int $selection = null,
        ?string $reviewsql = null
    ): string {
        $config = $this->getReviewConfiguration->parseFromParams(
            $selection,
            $reviewsql,
            $lang,
            $text
        );

        return $this->repository->getLanguageName($config);
    }

    /**
     * Get test data from params.
     *
     * @param int|null    $selection   Selection type
     * @param string|null $sessTestsql Session test SQL
     * @param int|null    $langId      Language ID
     * @param int|null    $textId      Text ID
     *
     * @return array|null
     */
    public function getReviewDataFromParams(
        ?int $selection,
        ?string $sessTestsql,
        ?int $langId,
        ?int $textId
    ): ?array {
        $config = $this->getReviewConfiguration->parseFromParams(
            $selection,
            $sessTestsql,
            $langId,
            $textId
        );

        if (!$config->isValid()) {
            return null;
        }

        $validation = $this->repository->validateSingleLanguage($config);
        if (!$validation['valid']) {
            return null;
        }

        $counts = $this->repository->getReviewCounts($config);

        $params = [];
        $reviewsql = $config->toSqlProjectionPrepared($params);

        return [
            'title' => $this->buildTitle($config),
            'property' => $config->toUrlProperty(),
            'reviewsql' => $reviewsql,
            'reviewParams' => $params,
            'counts' => $counts
        ];
    }

    /**
     * Build title for test.
     *
     * @param ReviewConfiguration $config Configuration
     *
     * @return string
     */
    private function buildTitle(ReviewConfiguration $config): string
    {
        $langName = $this->repository->getLanguageName($config);

        return match ($config->reviewKey) {
            ReviewConfiguration::KEY_LANG => "All Terms in {$langName}",
            ReviewConfiguration::KEY_TEXT => "Text Review",
            ReviewConfiguration::KEY_WORDS,
            ReviewConfiguration::KEY_TEXTS => $this->getSelectionTitle($config, $langName),
            default => 'Review'
        };
    }

    /**
     * Get selection title.
     *
     * @param ReviewConfiguration $config   Configuration
     * @param string            $langName Language name
     *
     * @return string
     */
    private function getSelectionTitle(ReviewConfiguration $config, string $langName): string
    {
        $count = is_array($config->selection) ? count($config->selection) : 1;
        $plural = $count === 1 ? '' : 's';
        return "Selected {$count} Term{$plural} IN {$langName}";
    }

    // ==========================================
    // NEW USE CASE-BASED METHODS
    // ==========================================

    /**
     * Get next term for testing (new API).
     *
     * @param ReviewConfiguration $config Test configuration
     *
     * @return array
     */
    public function fetchNextTerm(ReviewConfiguration $config): array
    {
        return $this->getNextTerm->execute($config);
    }

    /**
     * Get table words (new API).
     *
     * @param ReviewConfiguration $config Test configuration
     *
     * @return array
     */
    public function fetchTableWords(ReviewConfiguration $config): array
    {
        return $this->getTableWords->execute($config);
    }

    /**
     * Get test configuration (new API).
     *
     * @param ReviewConfiguration $config Test configuration
     *
     * @return array
     */
    public function fetchReviewConfiguration(ReviewConfiguration $config): array
    {
        return $this->getReviewConfiguration->execute($config);
    }

    /**
     * Submit answer (new API).
     *
     * @param int $wordId    Word ID
     * @param int $newStatus New status
     *
     * @return array
     */
    public function submitAnswer(int $wordId, int $newStatus): array
    {
        return $this->submitAnswer->execute($wordId, $newStatus);
    }

    /**
     * Submit answer with relative change (new API).
     *
     * @param int $wordId Word ID
     * @param int $change Change amount
     *
     * @return array
     */
    public function submitAnswerWithChange(int $wordId, int $change): array
    {
        return $this->submitAnswer->executeWithChange($wordId, $change);
    }

    /**
     * Submit a graded answer (issue #238, phase 2b).
     *
     * @param int    $wordId Word ID
     * @param Rating $grade  Again / Hard / Good / Easy
     *
     * @return array
     */
    public function submitAnswerWithGrade(int $wordId, Rating $grade): array
    {
        return $this->submitAnswer->executeWithGrade($wordId, $grade);
    }

    /**
     * What each grade would schedule for a term, in days.
     *
     * @param int $wordId Word ID
     *
     * @return array<int, int> Interval in days, keyed by grade (1-4)
     */
    public function previewIntervals(int $wordId): array
    {
        return $this->scheduledReview->previewIntervals($wordId);
    }

    // ==========================================
    // UTILITY METHODS
    // ==========================================

    /**
     * Clamp test type to valid range.
     *
     * @param int $testType Test type
     *
     * @return int
     */
    public function clampReviewType(int $testType): int
    {
        return max(1, min(5, $testType));
    }

    /**
     * Check if word mode.
     *
     * @param int $testType Test type
     *
     * @return bool
     */
    public function isWordMode(int $testType): bool
    {
        return $testType > 3;
    }

    /**
     * Get base test type.
     *
     * @param int $testType Test type
     *
     * @return int
     */
    public function getBaseReviewType(int $testType): int
    {
        return $testType > 3 ? $testType - 3 : $testType;
    }

    /**
     * Calculate new status.
     *
     * @param int $oldStatus Old status
     * @param int $change    Change amount
     *
     * @return int
     */
    public function calculateNewStatus(int $oldStatus, int $change): int
    {
        $newStatus = $oldStatus + $change;
        return max(1, min(5, $newStatus));
    }

    /**
     * Calculate status change.
     *
     * @param int $oldStatus Old status
     * @param int $newStatus New status
     *
     * @return int
     */
    public function calculateStatusChange(int $oldStatus, int $newStatus): int
    {
        $diff = $newStatus - $oldStatus;
        if ($diff < 0) {
            return -1;
        }
        if ($diff > 0) {
            return 1;
        }
        return 0;
    }

    /**
     * Get word text.
     *
     * @param int $wordId Word ID
     *
     * @return string|null
     */
    public function getWordText(int $wordId): ?string
    {
        return $this->repository->getWordText($wordId);
    }

    /**
     * Get test solution.
     *
     * @param int                  $testType Test type
     * @param array<string, mixed> $wordData Word data
     * @param bool                 $wordMode Word mode
     * @param string               $wordText Word text
     *
     * @return string
     */
    public function getTestSolution(
        int $testType,
        array $wordData,
        bool $wordMode,
        string $wordText
    ): string {
        $baseType = $this->getBaseReviewType($testType);

        if ($baseType === 1) {
            $tagList = \Lwt\Modules\Tags\Application\TagsFacade::getWordTagList((int) $wordData['WoID'], false);
            $tagFormatted = $tagList !== '' ? ' [' . $tagList . ']' : '';
            $translation = isset($wordData['WoTranslation']) && is_string($wordData['WoTranslation'])
                ? $wordData['WoTranslation']
                : '';
            $trans = ExportService::replaceTabNewline($translation) . $tagFormatted;
            return $wordMode ? $trans : "[$trans]";
        }

        return $wordText;
    }

    /**
     * Build selection test SQL with prepared statement parameters.
     *
     * @param int    $selectionType Selection type
     * @param string $selectionData Comma-separated IDs
     *
     * @return array{sql: string, params: array<int, int|string>}|null SQL and params, or null
     */
    public function buildSelectionReviewSql(int $selectionType, string $selectionData): ?array
    {
        $dataStringArray = explode(',', trim($selectionData, '()'));
        $dataIntArray = array_map('intval', $dataStringArray);

        $testKey = match ($selectionType) {
            2 => ReviewConfiguration::KEY_WORDS,
            3 => ReviewConfiguration::KEY_TEXTS,
            default => null
        };

        if ($testKey === null) {
            return null;
        }

        $config = new ReviewConfiguration($testKey, $dataIntArray);
        $params = [];
        $sql = $config->toSqlProjectionPrepared($params);
        return ['sql' => $sql, 'params' => $params];
    }

    /**
     * Get waiting time setting.
     *
     * @return int
     */
    public function getWaitingTime(): int
    {
        return (int) \Lwt\Shared\Infrastructure\Database\Settings::getWithDefault('set-test-main-frame-waiting-time');
    }

    /**
     * Get edit frame waiting time setting.
     *
     * @return int
     */
    public function getEditFrameWaitingTime(): int
    {
        return (int) \Lwt\Shared\Infrastructure\Database\Settings::getWithDefault('set-test-edit-frame-waiting-time');
    }
}
