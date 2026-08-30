<?php

/**
 * Review API Handler
 *
 * REST API handler for review/test operations.
 *
 * PHP version 8.2
 *
 * @category Lwt
 * @package  Lwt\Modules\Review\Http
 * @author   HugoFara <git@hugofara.net>
 * @license  Unlicense <http://unlicense.org/>
 * @link     https://hugofara.github.io/lwt/developer/api
 * @since    3.0.0
 */

declare(strict_types=1);

namespace Lwt\Modules\Review\Http;

use Lwt\Modules\Review\Application\ReviewFacade;
use Lwt\Modules\Review\Domain\ReviewConfiguration;
use Lwt\Modules\Review\Domain\Scheduling\Rating;
use Lwt\Modules\Review\Infrastructure\SessionStateManager;
use Lwt\Modules\Language\Application\LanguageFacade;
use Lwt\Shared\Infrastructure\Language\LanguagePresets;
use Lwt\Modules\Vocabulary\Application\Services\ExportService;
use Lwt\Modules\Vocabulary\Application\Helpers\StatusHelper;
use Lwt\Modules\Vocabulary\Domain\ValueObject\TermStatus;
use Lwt\Shared\Http\ApiRoutableInterface;
use Lwt\Shared\Http\ApiRoutableTrait;
use Lwt\Shared\Infrastructure\Http\JsonResponse;
use Lwt\Api\V1\Response;

/**
 * Handler for review/test-related API operations.
 *
 * @since 3.0.0
 */
class ReviewApiHandler implements ApiRoutableInterface
{
    use ApiRoutableTrait;

    private ReviewFacade $reviewFacade;
    private SessionStateManager $sessionManager;

    /**
     * Constructor.
     *
     * @param ReviewFacade|null        $reviewFacade   Review facade (optional)
     * @param SessionStateManager|null $sessionManager Session state manager (optional)
     */
    public function __construct(
        ?ReviewFacade $reviewFacade = null,
        ?SessionStateManager $sessionManager = null
    ) {
        $this->reviewFacade = $reviewFacade ?? new ReviewFacade();
        $this->sessionManager = $sessionManager ?? new SessionStateManager();
    }

    /**
     * Get the next word to test as structured data.
     *
     * @param string          $reviewsql SQL projection query with ? placeholders
     * @param array<int, int|string> $params    Bound parameters for the SQL
     * @param bool            $wordMode  Test is in word mode
     * @param int             $testtype  Test type
     *
     * @return array{term_id: int|string, solution?: string, term_text: string, group: string, error?: string}
     */
    public function getWordReviewData(string $reviewsql, array $params, bool $wordMode, int $testtype): array
    {
        try {
            $wordRecord = $this->reviewFacade->getNextWord($reviewsql, $params);
        } catch (\mysqli_sql_exception $e) {
            error_log('Review query failed: ' . $e->getMessage());
            return [
                "term_id" => 0,
                "term_text" => '',
                "group" => '',
                "error" => 'Database error during review'
            ];
        }

        if ($wordRecord === null || $wordRecord === []) {
            return [
                "term_id" => 0,
                "term_text" => '',
                "group" => ''
            ];
        }

        // Extract typed values from word record
        $woText = is_string($wordRecord['WoText']) ? $wordRecord['WoText'] : '';
        $woTextLC = is_string($wordRecord['WoTextLC']) ? $wordRecord['WoTextLC'] : '';
        $woID = is_numeric($wordRecord['WoID']) ? (int)$wordRecord['WoID'] : 0;

        // Check context annotation settings
        $settings = $this->reviewFacade->getTableReviewSettings();
        $showContextRom = (bool) ($settings['contextRom'] ?? 0);
        $showContextTrans = (bool) ($settings['contextTrans'] ?? 0);
        $useAnnotations = !$wordMode && ($showContextRom || $showContextTrans);

        // Get sentence context
        if ($wordMode) {
            $sent = "{" . $woText . "}";
            $annotations = [];
        } elseif ($useAnnotations) {
            $sentenceData = $this->reviewFacade->getSentenceWithAnnotations(
                $woID,
                $woTextLC
            );
            $sent = $sentenceData['sentence'] ?? "{" . $woText . "}";
            $annotations = $sentenceData['annotations'] ?? [];
        } else {
            $sentenceData = $this->reviewFacade->getSentenceForWord(
                $woID,
                $woTextLC
            );
            $sent = $sentenceData['sentence'] ?? "{" . $woText . "}";
            $annotations = [];
        }

        // Format term for test display
        list($htmlSentence, $save) = $this->formatTermForTest(
            $wordRecord,
            $sent,
            $testtype,
            $useAnnotations ? $annotations : [],
            $showContextRom,
            $showContextTrans
        );

        // Get solution
        $solution = $this->reviewFacade->getTestSolution(
            $testtype,
            $wordRecord,
            $wordMode,
            $save
        );

        return [
            "term_id" => is_numeric($wordRecord['WoID']) ? (int) $wordRecord['WoID'] : 0,
            "solution" => $solution,
            "term_text" => $save,
            "group" => $htmlSentence
        ];
    }

    /**
     * Format term for test display.
     *
     * @param array<string, mixed>  $wordRecord      Word database record
     * @param string $sentence        Sentence containing the word (word marked with {})
     * @param int    $testType        Test type (1-5)
     * @param array<int, array{
     *     text: string,
     *     romanization: string|null,
     *     translation: string|null,
     *     isTarget?: bool,
     *     order?: int
     * }> $annotations Word annotations (keyed by order)
     * @param bool   $showContextRom  Show romanization on context words
     * @param bool   $showContextTrans Show translation on context words
     *
     * @return array{0: string, 1: string} [HTML display, plain word text]
     */
    private function formatTermForTest(
        array $wordRecord,
        string $sentence,
        int $testType,
        array $annotations = [],
        bool $showContextRom = false,
        bool $showContextTrans = false
    ): array {
        $baseType = $this->reviewFacade->getBaseReviewType($testType);
        $wordMode = $this->reviewFacade->isWordMode($testType);
        $wordText = is_string($wordRecord['WoText']) ? $wordRecord['WoText'] : '';

        // Extract the word from sentence (marked with {})
        if (preg_match('/\{([^}]+)\}/', $sentence, $matches)) {
            $markedWord = $matches[1];
        } else {
            $markedWord = $wordText;
        }

        // If we have annotations, build HTML with ruby elements
        if (!empty($annotations) && ($showContextRom || $showContextTrans)) {
            $displayHtml = $this->buildAnnotatedSentenceHtml(
                $annotations,
                $markedWord,
                $baseType,
                $showContextRom,
                $showContextTrans
            );
        } else {
            // Build display HTML based on test type
            if ($baseType == 1) {
                // Type 1/4: Show term, guess translation
                $displayHtml = str_replace(
                    '{' . $markedWord . '}',
                    '<span class="word-test">' . htmlspecialchars($markedWord, ENT_QUOTES, 'UTF-8') . '</span>',
                    $sentence
                );
            } elseif ($baseType == 2) {
                if ($wordMode) {
                    // Type 5: Translation → Term (word mode) - show translation
                    /** @var mixed $translationRaw */
                    $translationRaw = $wordRecord['WoTranslation'] ?? '';
                    $translation = is_string($translationRaw) ? $translationRaw : '';
                    $displayHtml = '<span class="word-test">'
                        . htmlspecialchars($translation, ENT_QUOTES, 'UTF-8')
                        . '</span>';
                } else {
                    // Type 2: Sentence → Term (sentence mode) - hide term in sentence
                    $hiddenSpan = '<span class="word-test-hidden">[...]</span>';
                    $displayHtml = str_replace('{' . $markedWord . '}', $hiddenSpan, $sentence);
                }
            } else {
                // Type 3: Show sentence with hidden term
                $hiddenSpan = '<span class="word-test-hidden">[...]</span>';
                $displayHtml = str_replace('{' . $markedWord . '}', $hiddenSpan, $sentence);
            }

            // Clean up any remaining braces
            $displayHtml = str_replace(['{', '}'], '', $displayHtml);
        }

        return [$displayHtml, $markedWord];
    }

    /**
     * Build HTML for a sentence with ruby annotations.
     *
     * @param array<int, array{
     *     text: string,
     *     romanization: string|null,
     *     translation: string|null,
     *     isTarget?: bool,
     *     order?: int
     * }> $annotations Word annotations keyed by order
     * @param string $targetWord      The target word being tested
     * @param int    $baseType        Test type (1=show term, 2=hide term, 3=hide term)
     * @param bool   $showRom         Show romanization
     * @param bool   $showTrans       Show translation
     *
     * @return string HTML with ruby annotations
     */
    private function buildAnnotatedSentenceHtml(
        array $annotations,
        string $targetWord,
        int $baseType,
        bool $showRom,
        bool $showTrans
    ): string {
        // Sort annotations by order
        ksort($annotations);

        $html = '<span class="annotated-sentence">';
        $targetWordLc = mb_strtolower($targetWord, 'UTF-8');

        foreach ($annotations as $ann) {
            $text = is_string($ann['text'] ?? null) ? $ann['text'] : '';
            $textLc = mb_strtolower($text, 'UTF-8');
            $isTarget = $textLc === $targetWordLc;
            $romRaw = $ann['romanization'] ?? null;
            $transRaw = $ann['translation'] ?? null;
            $rom = is_string($romRaw) ? $romRaw : null;
            $trans = is_string($transRaw) ? $transRaw : null;
            $escapedText = htmlspecialchars($text, ENT_QUOTES, 'UTF-8');

            // Handle the target word based on test type
            if ($isTarget) {
                if ($baseType == 1) {
                    // Show term highlighted
                    $html .= '<span class="word-test">' . $escapedText . '</span>';
                } else {
                    // Hide term (type 2 and 3)
                    $html .= '<span class="word-test-hidden">[...]</span>';
                }
                continue;
            }

            // Check if this is a word with annotations to show
            $hasAnnotation = ($showRom && $rom !== null) || ($showTrans && $trans !== null);

            if ($hasAnnotation) {
                // Build ruby annotation
                $rubyText = '';
                if ($showRom && $rom !== null) {
                    $rubyText .= htmlspecialchars($rom, ENT_QUOTES, 'UTF-8');
                }
                if ($showTrans && $trans !== null) {
                    if ($rubyText !== '') {
                        $rubyText .= ' ';
                    }
                    $rubyText .= '<span class="context-trans">' .
                        htmlspecialchars($trans, ENT_QUOTES, 'UTF-8') . '</span>';
                }

                $html .= '<ruby class="context-word">' . $escapedText .
                    '<rp>(</rp><rt>' . $rubyText . '</rt><rp>)</rp></ruby>';
            } else {
                // Plain text (punctuation or unknown word)
                $html .= $escapedText;
            }
        }

        $html .= '</span>';
        return $html;
    }

    /**
     * Get the next word to review based on request parameters.
     *
     * @param array<string, mixed> $params Request parameters
     *
     * @return array{term_id: int|string, solution?: string, term_text: string, group: string, error?: string}
     */
    public function wordTestAjax(array $params): array
    {
        /** @var mixed $reviewKeyRaw */
        $reviewKeyRaw = $params['review_key'] ?? $params['test_key'] ?? '';
        $reviewKey = is_string($reviewKeyRaw) ? $reviewKeyRaw : '';
        /** @var mixed $selectionRaw */
        $selectionRaw = $params['selection'] ?? '';
        $selection = is_string($selectionRaw) ? $selectionRaw : '';

        if ($reviewKey === '' || $selection === '') {
            return [
                "term_id" => 0,
                "term_text" => '',
                "group" => ''
            ];
        }

        $result = $this->reviewFacade->getReviewSql(
            $reviewKey,
            $this->parseSelection($reviewKey, $selection)
        );
        if ($result === null) {
            return [
                "term_id" => 0,
                "term_text" => '',
                "group" => ''
            ];
        }

        /** @var mixed $wordModeRaw */
        $wordModeRaw = $params['word_mode'] ?? false;
        /** @var mixed $typeRaw */
        $typeRaw = $params['type'] ?? 1;

        return $this->getWordReviewData(
            $result['sql'],
            $result['params'],
            filter_var($wordModeRaw, FILTER_VALIDATE_BOOLEAN),
            (int) $typeRaw
        );
    }

    /**
     * Return the number of reviews for tomorrow.
     *
     * @param array<string, mixed> $params Request parameters
     *
     * @return array{count: int}
     */
    public function tomorrowTestCount(array $params): array
    {
        /** @var mixed $reviewKeyRaw */
        $reviewKeyRaw = $params['review_key'] ?? $params['test_key'] ?? '';
        $reviewKey = is_string($reviewKeyRaw) ? $reviewKeyRaw : '';
        /** @var mixed $selectionRaw */
        $selectionRaw = $params['selection'] ?? '';
        $selection = is_string($selectionRaw) ? $selectionRaw : '';

        if ($reviewKey === '' || $selection === '') {
            return ["count" => 0];
        }

        $result = $this->reviewFacade->getReviewSql(
            $reviewKey,
            $this->parseSelection($reviewKey, $selection)
        );
        if ($result === null) {
            return ["count" => 0];
        }
        return [
            "count" => $this->reviewFacade->getTomorrowReviewCount($result['sql'], $result['params'])
        ];
    }

    /**
     * Parse selection parameter based on review key type.
     *
     * @param string $reviewKey The review key type
     * @param string $selection The selection value
     *
     * @return int|int[] Parsed selection value
     */
    private function parseSelection(string $reviewKey, string $selection): int|array
    {
        if ($reviewKey === 'words' || $reviewKey === 'texts') {
            return array_map('intval', explode(',', $selection));
        }
        return (int)$selection;
    }

    // =========================================================================
    // API Response Formatters
    // =========================================================================

    /**
     * Format response for getting next word test.
     *
     * @param array<string, mixed> $params Request parameters
     *
     * @return array{term_id: int|string, solution?: string, term_text: string, group: string, error?: string}
     */
    public function formatNextWord(array $params): array
    {
        return $this->wordTestAjax($params);
    }

    /**
     * Format response for tomorrow count.
     *
     * @param array<string, mixed> $params Request parameters
     *
     * @return array{count: int}
     */
    public function formatTomorrowCount(array $params): array
    {
        return $this->tomorrowTestCount($params);
    }

    /**
     * Update word status during review/test mode.
     *
     * @param int      $wordId Word ID
     * @param int|null $status Explicit status
     * @param int|null $change Status change amount
     * @param int|null $grade  FSRS grade (1 Again, 2 Hard, 3 Good, 4 Easy)
     *
     * @return array{status?: int, controls?: string, error?: string}
     */
    public function updateReviewStatus(
        int $wordId,
        ?int $status,
        ?int $change,
        ?int $grade = null
    ): array {
        if ($grade !== null) {
            // A graded answer carries the scheduling signal, so it takes
            // precedence: it moves WoStatus by the same +-1 a binary answer
            // would, and additionally feeds the term's FSRS state.
            $rating = Rating::tryFrom($grade);
            if ($rating === null) {
                return ['error' => 'Invalid grade value'];
            }
            $result = $this->reviewFacade->submitAnswerWithGrade($wordId, $rating);
        } elseif ($status !== null) {
            // Explicit status - validate it
            if (!TermStatus::isValid($status)) {
                return ['error' => 'Invalid status value'];
            }
            $result = $this->reviewFacade->submitAnswer($wordId, $status);
        } elseif ($change !== null) {
            $result = $this->reviewFacade->submitAnswerWithChange($wordId, $change);
        } else {
            return ['error' => 'Must provide either status, change or grade'];
        }

        if (!$result['success']) {
            $errorMsg = isset($result['error']) && is_string($result['error'])
                ? $result['error']
                : 'Failed to update status';
            return ['error' => $errorMsg];
        }

        // Return the new status and controls HTML
        $newStatus = isset($result['newStatus']) ? (int) $result['newStatus'] : 1;
        $statusAbbr = StatusHelper::getAbbr($newStatus);
        $controls = StatusHelper::buildReviewTableControls(1, $newStatus, $wordId, $statusAbbr);

        return [
            'status' => $newStatus,
            'controls' => $controls
        ];
    }

    /**
     * Format response for updating review status.
     *
     * @param array $params Request parameters
     *
     * @return array{status?: int, controls?: string, error?: string}
     */
    public function formatUpdateStatus(array $params): array
    {
        $termId = (int)($params['term_id'] ?? 0);
        if ($termId === 0) {
            return ['error' => 'term_id is required'];
        }

        $status = isset($params['status']) ? (int)$params['status'] : null;
        $change = isset($params['change']) ? (int)$params['change'] : null;
        $grade = isset($params['grade']) ? (int)$params['grade'] : null;

        return $this->updateReviewStatus($termId, $status, $change, $grade);
    }

    /**
     * What each grade would schedule for a term.
     *
     * Feeds the hints under the four review buttons, so the user can see what
     * answering Hard rather than Good costs before committing to it.
     *
     * @param array $params Request parameters
     *
     * @return array{intervals?: array<int, int>, error?: string}
     */
    public function formatIntervals(array $params): array
    {
        $termId = (int)($params['term_id'] ?? 0);
        if ($termId === 0) {
            return ['error' => 'term_id is required'];
        }

        return ['intervals' => $this->reviewFacade->previewIntervals($termId)];
    }

    /**
     * Get full test configuration for Alpine.js initialization.
     *
     * @param array<string, mixed> $params Request parameters
     *
     * @return array Test configuration
     */
    public function formatTestConfig(array $params): array
    {
        $langId = isset($params['lang']) && $params['lang'] !== ''
            ? (int)$params['lang'] : null;
        $textId = isset($params['text']) && $params['text'] !== ''
            ? (int)$params['text'] : null;
        $selection = isset($params['selection']) && $params['selection'] !== ''
            ? (int)$params['selection'] : null;
        $testType = isset($params['type']) && $params['type'] !== ''
            ? (int)$params['type'] : 1;
        $isTableMode = ($params['type'] ?? '') === 'table';

        // Get selection data from session criteria
        $sessReviewSql = null;
        if ($selection !== null && $this->sessionManager->hasCriteria()) {
            $sessReviewSql = $this->sessionManager->getSelectionString();
        }

        // Get test data
        $testData = $this->reviewFacade->getReviewDataFromParams(
            $selection,
            $sessReviewSql,
            $langId,
            $textId
        );

        if ($testData === null) {
            return ['error' => 'Invalid test parameters'];
        }

        // Get test identifier
        $identifier = $this->reviewFacade->getReviewIdentifier(
            $selection,
            $sessReviewSql,
            $langId,
            $textId
        );

        if ($identifier[0] === '') {
            return ['error' => 'Invalid test identifier'];
        }

        /** @var int|int[] $sel */
        $sel = $identifier[1];
        $reviewResult = $this->reviewFacade->getReviewSql($identifier[0], $sel);

        if ($reviewResult === null) {
            return ['error' => 'Unable to generate test SQL'];
        }

        $reviewsql = $reviewResult['sql'];
        $reviewParams = $reviewResult['params'];

        $testType = $this->reviewFacade->clampReviewType($testType);
        $wordMode = $this->reviewFacade->isWordMode($testType);
        $baseType = $this->reviewFacade->getBaseReviewType($testType);

        // Get language settings
        $langIdFromSql = $this->reviewFacade->getLanguageIdFromReviewSql($reviewsql, $reviewParams);
        if ($langIdFromSql === null) {
            return ['error' => 'No words available for testing'];
        }

        $langSettings = $this->reviewFacade->getLanguageSettings($langIdFromSql);

        // Get language code for TTS
        $languageService = new LanguageFacade();
        $langCode = $languageService->getLanguageCode(
            $langIdFromSql,
            LanguagePresets::getAll()
        );

        // Initialize session
        $dueCount = (int) ($testData['counts']['due'] ?? 0);
        $this->reviewFacade->initializeReviewSession($dueCount);
        $sessionData = $this->reviewFacade->getReviewSessionData();

        return [
            'reviewKey' => $identifier[0],
            'selection' => is_array($identifier[1])
                ? implode(',', $identifier[1])
                : (string)$identifier[1],
            'reviewType' => $baseType,
            'isTableMode' => $isTableMode,
            'wordMode' => $wordMode,
            'langId' => $langIdFromSql,
            'wordRegex' => $langSettings['regexWord'] ?? '',
            'langSettings' => [
                'name' => $langSettings['name'] ?? '',
                'dict1Uri' => $langSettings['dict1Uri'] ?? '',
                'dict2Uri' => $langSettings['dict2Uri'] ?? '',
                'translateUri' => $langSettings['translateUri'] ?? '',
                'textSize' => $langSettings['textSize'] ?? 100,
                'rtl' => $langSettings['rtl'] ?? false,
                'langCode' => $langCode
            ],
            'progress' => [
                'total' => $dueCount,
                'remaining' => $dueCount,
                'wrong' => 0,
                'correct' => 0
            ],
            'timer' => [
                'startTime' => $sessionData['start'],
                'serverTime' => time()
            ],
            'title' => $testData['title'],
            'property' => $testData['property']
        ];
    }

    /**
     * Get all words for table test mode.
     *
     * @param array<string, mixed> $params Request parameters
     *
     * @return array Table words data
     */
    public function formatTableWords(array $params): array
    {
        /** @var mixed $reviewKeyRaw */
        $reviewKeyRaw = $params['review_key'] ?? $params['test_key'] ?? '';
        $reviewKey = is_string($reviewKeyRaw) ? $reviewKeyRaw : '';
        /** @var mixed $selectionRaw */
        $selectionRaw = $params['selection'] ?? '';
        $selection = is_string($selectionRaw) ? $selectionRaw : '';

        if ($reviewKey === '' || $selection === '') {
            return ['error' => 'review_key and selection are required'];
        }

        $parsedSelection = $this->parseSelection($reviewKey, $selection);
        $reviewResult = $this->reviewFacade->getReviewSql($reviewKey, $parsedSelection);

        if ($reviewResult === null) {
            return ['error' => 'Unable to generate test SQL'];
        }

        $reviewsql = $reviewResult['sql'];
        $reviewParams = $reviewResult['params'];

        // Validate single language
        $validation = $this->reviewFacade->validateReviewSelection($reviewsql, $reviewParams);
        if (!$validation['valid']) {
            return ['error' => $validation['error']];
        }

        // Get language settings
        $langIdFromSql = $this->reviewFacade->getLanguageIdFromReviewSql($reviewsql, $reviewParams);
        if ($langIdFromSql === null) {
            return ['words' => [], 'langSettings' => null];
        }

        $langSettings = $this->reviewFacade->getLanguageSettings($langIdFromSql);
        /** @var mixed $regexWordRaw */
        $regexWordRaw = $langSettings['regexWord'] ?? '';
        $regexWord = is_string($regexWordRaw) ? $regexWordRaw : '';

        // Get language code for TTS
        $languageService = new LanguageFacade();
        $langCode = $languageService->getLanguageCode(
            $langIdFromSql,
            LanguagePresets::getAll()
        );

        // Get words
        $wordsResult = $this->reviewFacade->getTableReviewWords($reviewsql, $reviewParams);
        $words = [];

        foreach ($wordsResult as $word) {
            // Format sentence with highlighted word
            $sent = htmlspecialchars(
                ExportService::replaceTabNewline((string)($word['WoSentence'] ?? '')),
                ENT_QUOTES,
                'UTF-8'
            );
            $sentenceHtml = str_replace(
                "{",
                ' <b>[',
                str_replace(
                    "}",
                    ']</b> ',
                    ExportService::maskTermInSentence($sent, $regexWord)
                )
            );

            $words[] = [
                'id' => (int)$word['WoID'],
                'text' => $word['WoText'] ?? '',
                'translation' => $word['WoTranslation'] ?? '',
                'romanization' => $word['WoRomanization'] ?? '',
                'sentence' => $sent,
                'sentenceHtml' => $sentenceHtml,
                'status' => (int)($word['WoStatus'] ?? 1),
                'score' => (int)($word['Score'] ?? 0)
            ];
        }

        return [
            'words' => $words,
            'langSettings' => [
                'name' => $langSettings['name'] ?? '',
                'dict1Uri' => $langSettings['dict1Uri'] ?? '',
                'dict2Uri' => $langSettings['dict2Uri'] ?? '',
                'translateUri' => $langSettings['translateUri'] ?? '',
                'textSize' => $langSettings['textSize'] ?? 100,
                'rtl' => $langSettings['rtl'] ?? false,
                'langCode' => $langCode
            ]
        ];
    }

    public function routeGet(array $fragments, array $params): JsonResponse
    {
        $frag1 = $this->frag($fragments, 1);

        switch ($frag1) {
            case 'next-word':
                return Response::success($this->formatNextWord($params));
            case 'tomorrow-count':
                return Response::success($this->formatTomorrowCount($params));
            case 'config':
                return Response::success($this->formatTestConfig($params));
            case 'table-words':
                return Response::success($this->formatTableWords($params));
            case 'intervals':
                return Response::success($this->formatIntervals($params));
            default:
                return Response::error('Endpoint Not Found: ' . $frag1, 404);
        }
    }

    public function routePut(array $fragments, array $params): JsonResponse
    {
        $frag1 = $this->frag($fragments, 1);

        if ($frag1 === 'status') {
            return Response::success($this->formatUpdateStatus($params));
        }
        return Response::error('Expected "status"', 404);
    }
}
