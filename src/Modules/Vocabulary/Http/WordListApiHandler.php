<?php

/**
 * Word List API Handler
 *
 * Handles API operations for word list display, filtering, and bulk operations.
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

use Lwt\Shared\Infrastructure\Database\QueryBuilder;
use Lwt\Modules\Vocabulary\Application\Services\WordListService;
use Lwt\Modules\Tags\Application\TagsFacade;
use Lwt\Modules\Vocabulary\Application\Helpers\StatusHelper;

/**
 * Handler for word list API operations.
 *
 * Provides endpoints for:
 * - Getting paginated, filtered word lists
 * - Performing bulk actions on selected words
 * - Inline editing of translations/romanizations
 * - Getting filter dropdown options
 * - Listing imported terms
 *
 * @since 3.0.0
 */
class WordListApiHandler
{
    private ?WordListService $listService = null;

    /**
     * Constructor.
     *
     * @param WordListService|null $listService Word list service instance
     */
    public function __construct(?WordListService $listService = null)
    {
        $this->listService = $listService;
    }

    /**
     * Get the WordListService instance.
     *
     * @return WordListService
     */
    private function getListService(): WordListService
    {
        if ($this->listService === null) {
            $this->listService = new WordListService();
        }
        return $this->listService;
    }

    /**
     * Get paginated, filtered word list.
     *
     * @param array $params Filter parameters:
     *                      - page: int (default 1)
     *                      - per_page: int (default 50)
     *                      - lang: int|null (language ID filter)
     *                      - status: string|null (status filter code)
     *                      - query: string|null (search query)
     *                      - query_mode: string (term, rom, transl, term,rom,transl)
     *                      - regex_mode: string ('' or 'r')
     *                      - tag1: int|null, tag2: int|null, tag12: int|null
     *                      - text_id: int|null (filter words in specific text)
     *                      - sort: int (1-7)
     *
     * @return array{words: array, pagination: array}
     */
    public function getWordList(array $params): array
    {
        $listService = $this->getListService();

        // Parse parameters with defaults
        $page = max(1, (int) ($params['page'] ?? 1));
        $perPage = max(1, min(500, (int) ($params['per_page'] ?? 50)));
        $lang = (string) ($params['lang'] ?? '');
        $status = (string) ($params['status'] ?? '');
        $query = (string) ($params['query'] ?? '');
        $queryMode = (string) ($params['query_mode'] ?? 'term,rom,transl');
        $regexMode = (string) ($params['regex_mode'] ?? '');
        $tag1 = (string) ($params['tag1'] ?? '');
        $tag2 = (string) ($params['tag2'] ?? '');
        $tag12 = (string) ($params['tag12'] ?? '0');
        $textId = (string) ($params['text_id'] ?? '');
        $sort = max(1, min(7, (int) ($params['sort'] ?? 1)));

        // Build filter conditions with parameterized queries
        /** @var array<int, mixed> $filterBindings */
        $filterBindings = [];
        $whLang = $listService->buildLangCondition($lang, $filterBindings);
        $whStat = $listService->buildStatusCondition($status);
        $whQuery = $listService->buildQueryCondition($query, $queryMode, $regexMode, $filterBindings);
        $whTag = $listService->buildTagCondition($tag1, $tag2, $tag12, $filterBindings);

        // Get total count — $filterBindings is always array after build* calls (never set to null)
        /** @psalm-suppress PossiblyNullArgument */
        $total = $listService->countWords($textId, $whLang, $whStat, $whQuery, $whTag, $filterBindings);

        // Calculate pagination
        $totalPages = (int) ceil($total / $perPage);
        if ($page > $totalPages && $totalPages > 0) {
            $page = $totalPages;
        }

        // Get words list
        $filters = [
            'whLang' => $whLang,
            'whStat' => $whStat,
            'whQuery' => $whQuery,
            'whTag' => $whTag,
            'textId' => $textId,
            'params' => $filterBindings
        ];

        $records = $listService->getWordsList($filters, $sort, $page, $perPage);
        $words = [];

        /** @var array<string, mixed> $record */
        foreach ($records as $record) {
            $words[] = $this->formatWordRecord($record, $sort);
        }

        return [
            'words' => $words,
            'pagination' => [
                'page' => $page,
                'per_page' => $perPage,
                'total' => $total,
                'total_pages' => $totalPages
            ]
        ];
    }

    /**
     * Format a word record for API response.
     *
     * @param array $record Database record
     * @param int   $sort   Current sort option
     *
     * @return array Formatted word data
     */
    private function formatWordRecord(array $record, int $sort): array
    {
        $status = (int) $record['WoStatus'];
        $days = (int) ($record['Days'] ?? 0);

        $word = [
            'id' => (int) $record['WoID'],
            'text' => (string) $record['WoText'],
            'translation' => (string) ($record['WoTranslation'] ?? ''),
            'romanization' => (string) ($record['WoRomanization'] ?? ''),
            'sentence' => (string) ($record['WoSentence'] ?? ''),
            'sentenceOk' => (bool) ($record['SentOK'] ?? false),
            'status' => $status,
            'statusAbbr' => StatusHelper::getAbbr($status),
            'statusLabel' => StatusHelper::getName($status),
            'days' => $status > 5 ? '-' : (string) $days,
            'score' => (float) ($record['Score'] ?? 0),
            'score2' => (float) ($record['Score2'] ?? 0),
            'tags' => (string) ($record['taglist'] ?? ''),
            'langId' => 0, // Will be set from LgID if available
            'langName' => (string) ($record['LgName'] ?? ''),
            'rightToLeft' => (bool) ($record['LgRightToLeft'] ?? false),
            'ttsClass' => null
        ];

        // Extract TTS class from Google Translate URI
        $gtUri = (string) ($record['LgGoogleTranslateURI'] ?? '');
        if ($gtUri !== '' && strpos($gtUri, '&sl=') !== false) {
            $ttsLang = preg_replace('/.*[?&]sl=([a-zA-Z\-]*)(&.*)*$/', '$1', $gtUri);
            if ($ttsLang !== null && $ttsLang !== $gtUri) {
                $word['ttsClass'] = 'tts_' . $ttsLang;
            }
        }

        // Add text word count for sort option 7
        if ($sort === 7 && isset($record['textswordcount'])) {
            $word['textsWordCount'] = (int) $record['textswordcount'];
        }

        return $word;
    }

    /**
     * Perform bulk action on selected word IDs.
     *
     * @param int[]       $wordIds Array of word IDs
     * @param string      $action  Action code
     * @param string|null $data    Optional data (e.g., tag name)
     *
     * @return array{success: bool, count: int, message: string}
     */
    public function bulkAction(array $wordIds, string $action, ?string $data = null): array
    {
        if (empty($wordIds)) {
            return ['success' => false, 'count' => 0, 'message' => __('vocabulary.flash.no_terms_selected')];
        }

        $listService = $this->getListService();

        // Sanitize word IDs
        $wordIds = array_filter(array_map('intval', $wordIds));
        if (empty($wordIds)) {
            return ['success' => false, 'count' => 0, 'message' => __('vocabulary.flash.invalid_term_ids')];
        }

        $count = count($wordIds);

        switch ($action) {
            case 'del':
                $message = $listService->deleteByIdList($wordIds);
                break;

            case 'spl1': // Status +1
                $message = $listService->updateStatusByIdList($wordIds, 1, true, 'spl1');
                break;

            case 'smi1': // Status -1
                $message = $listService->updateStatusByIdList($wordIds, -1, true, 'smi1');
                break;

            case 's1':
            case 's2':
            case 's3':
            case 's4':
            case 's5':
            case 's98':
            case 's99':
                $status = (int) substr($action, 1);
                $message = $listService->updateStatusByIdList($wordIds, $status, false, $action);
                break;

            case 'today':
                $message = $listService->updateStatusDateByIdList($wordIds);
                break;

            case 'delsent':
                $message = $listService->deleteSentencesByIdList($wordIds);
                break;

            case 'lower':
                $message = $listService->toLowercaseByIdList($wordIds);
                break;

            case 'cap':
                $message = $listService->capitalizeByIdList($wordIds);
                break;

            case 'addtag':
                if ($data === null || $data === '') {
                    return ['success' => false, 'count' => 0, 'message' => __('vocabulary.flash.tag_name_required')];
                }
                $result = TagsFacade::addTagToWords($data, $wordIds);
                if ($result['error'] !== null) {
                    return ['success' => false, 'count' => 0, 'message' => $result['error']];
                }
                $message = "Tag added in {$result['count']} Terms";
                break;

            case 'deltag':
                if ($data === null || $data === '') {
                    return ['success' => false, 'count' => 0, 'message' => __('vocabulary.flash.tag_name_required')];
                }
                $result = TagsFacade::removeTagFromWords($data, $wordIds);
                if ($result['error'] !== null) {
                    return ['success' => false, 'count' => 0, 'message' => $result['error']];
                }
                $message = "Tag removed in {$result['count']} Terms";
                break;

            default:
                return [
                    'success' => false,
                    'count' => 0,
                    'message' => __('vocabulary.flash.unknown_action', ['action' => $action]),
                ];
        }

        return ['success' => true, 'count' => $count, 'message' => $message];
    }

    /**
     * Perform action on ALL words matching current filter.
     *
     * @param array       $filters Filter parameters
     * @param string      $action  Action code
     * @param string|null $data    Optional data
     *
     * @return array{success: bool, count: int, message: string}
     */
    public function allAction(array $filters, string $action, ?string $data = null): array
    {
        $listService = $this->getListService();

        // Build filter conditions from params
        $lang = (string) ($filters['lang'] ?? '');
        $status = (string) ($filters['status'] ?? '');
        $query = (string) ($filters['query'] ?? '');
        $queryMode = (string) ($filters['query_mode'] ?? 'term,rom,transl');
        $regexMode = (string) ($filters['regex_mode'] ?? '');
        $tag1 = (string) ($filters['tag1'] ?? '');
        $tag2 = (string) ($filters['tag2'] ?? '');
        $tag12 = (string) ($filters['tag12'] ?? '0');
        $textId = (string) ($filters['text_id'] ?? '');

        /** @var array<int, mixed> $filterBindings */
        $filterBindings = [];
        $whLang = $listService->buildLangCondition($lang, $filterBindings);
        $whStat = $listService->buildStatusCondition($status);
        $whQuery = $listService->buildQueryCondition($query, $queryMode, $regexMode, $filterBindings);
        $whTag = $listService->buildTagCondition($tag1, $tag2, $tag12, $filterBindings);

        // Get all word IDs matching the filter — $filterBindings is always array (never set to null)
        /** @psalm-suppress PossiblyNullArgument */
        $wordIds = $listService->getFilteredWordIds($textId, $whLang, $whStat, $whQuery, $whTag, $filterBindings);

        if (empty($wordIds)) {
            return ['success' => false, 'count' => 0, 'message' => __('vocabulary.flash.no_terms_match_filter')];
        }

        // Remove 'all' suffix from action if present
        $action = (string) preg_replace('/all$/', '', $action);

        return $this->bulkAction($wordIds, $action, $data);
    }

    /**
     * Inline edit translation or romanization.
     *
     * @param int    $termId Term ID
     * @param string $field  Field name ('translation' or 'romanization')
     * @param string $value  New value
     *
     * @return array{success: bool, value: string, error?: string}
     */
    public function inlineEdit(int $termId, string $field, string $value): array
    {
        // Validate field
        if (!in_array($field, ['translation', 'romanization'])) {
            return ['success' => false, 'value' => '', 'error' => 'Invalid field'];
        }

        // Check term exists
        $exists = QueryBuilder::table('words')
            ->where('WoID', '=', $termId)
            ->countPrepared();

        if ($exists === 0) {
            return ['success' => false, 'value' => '', 'error' => 'Term not found'];
        }

        // Prepare value
        $value = trim($value);
        $displayValue = $value;

        if ($field === 'translation') {
            if ($value === '') {
                $value = '*';
                $displayValue = '*';
            }
            QueryBuilder::table('words')
                ->where('WoID', '=', $termId)
                ->updatePrepared(['WoTranslation' => $value]);
        } else {
            // romanization
            if ($value === '') {
                $displayValue = '*';
            }
            QueryBuilder::table('words')
                ->where('WoID', '=', $termId)
                ->updatePrepared(['WoRomanization' => $value]);
        }

        return ['success' => true, 'value' => $displayValue];
    }

    /**
     * Get filter dropdown options.
     *
     * @param int|null $langId Language ID for filtering texts
     *
     * @return array{languages: array, texts: array, tags: array, statuses: array, sorts: array}
     */
    public function getFilterOptions(?int $langId = null): array
    {
        // Get languages
        $languages = [];
        $langResult = QueryBuilder::table('languages')
            ->select(['LgID', 'LgName', 'LgShowRomanization'])
            ->orderBy('LgName')
            ->getPrepared();
        foreach ($langResult as $row) {
            $languages[] = [
                'id' => (int) $row['LgID'],
                'name' => (string) $row['LgName'],
                'showRomanization' => (bool) $row['LgShowRomanization']
            ];
        }

        // Get texts (optionally filtered by language)
        $texts = [];
        if ($langId !== null && $langId > 0) {
            $textResult = QueryBuilder::table('texts')
                ->select(['TxID', 'TxTitle'])
                ->where('TxLgID', '=', $langId)
                ->orderBy('TxTitle')
                ->getPrepared();
            foreach ($textResult as $row) {
                $texts[] = [
                    'id' => (int) $row['TxID'],
                    'title' => (string) $row['TxTitle']
                ];
            }
        }

        // Get term tags (from tags table - text_tags is for text tags)
        $tags = [];
        $tagResult = QueryBuilder::table('tags')
            ->select(['TgID', 'TgText'])
            ->orderBy('TgText')
            ->getPrepared();
        foreach ($tagResult as $row) {
            $tags[] = [
                'id' => (int) $row['TgID'],
                'name' => (string) $row['TgText']
            ];
        }

        // Static status options
        $statuses = [
            ['value' => '', 'label' => '[All Terms]'],
            ['value' => '1', 'label' => 'Learning (1)'],
            ['value' => '2', 'label' => 'Learning (2)'],
            ['value' => '3', 'label' => 'Learning (3)'],
            ['value' => '4', 'label' => 'Learning (4)'],
            ['value' => '5', 'label' => 'Learned (5)'],
            ['value' => '99', 'label' => 'Well Known (99)'],
            ['value' => '98', 'label' => 'Ignored (98)'],
            ['value' => '12', 'label' => 'Learning (1-2)'],
            ['value' => '13', 'label' => 'Learning (1-3)'],
            ['value' => '14', 'label' => 'Learning (1-4)'],
            ['value' => '15', 'label' => 'Learning (1-5)'],
            ['value' => '599', 'label' => 'Learned (5+99)'],
            ['value' => '34', 'label' => 'Learning (3-4)'],
            ['value' => '35', 'label' => 'Learning (3-5)'],
            ['value' => '24', 'label' => 'Learning (2-4)'],
            ['value' => '25', 'label' => 'Learning (2-5)'],
        ];

        // Static sort options
        $sorts = [
            ['value' => 1, 'label' => 'Term A-Z'],
            ['value' => 2, 'label' => 'Translation A-Z'],
            ['value' => 3, 'label' => 'Newest first'],
            ['value' => 4, 'label' => 'Oldest first'],
            ['value' => 5, 'label' => 'Status'],
            ['value' => 6, 'label' => 'Score'],
            ['value' => 7, 'label' => 'Word count in texts'],
        ];

        return [
            'languages' => $languages,
            'texts' => $texts,
            'tags' => $tags,
            'statuses' => $statuses,
            'sorts' => $sorts
        ];
    }

    /**
     * Limit the current page within valid bounds.
     *
     * @param int $currentpage Current page number
     * @param int $recno       Record number
     * @param int $maxperpage  Maximum records per page
     *
     * @return int Valid page number
     */
    public function limitCurrentPage(int $currentpage, int $recno, int $maxperpage): int
    {
        $pages = intval(($recno - 1) / $maxperpage) + 1;
        if ($currentpage < 1) {
            $currentpage = 1;
        }
        if ($currentpage > $pages) {
            $currentpage = $pages;
        }
        return $currentpage;
    }

    /**
     * Select imported terms from the database.
     *
     * @param string $lastUpdate Last update timestamp
     * @param int    $offset     Offset for pagination
     * @param int    $maxTerms   Maximum terms to return
     *
     * @return array<int, array<string, mixed>>
     */
    public function selectImportedTerms(string $lastUpdate, int $offset, int $maxTerms): array
    {
        return QueryBuilder::table('words')
            ->select([
                'words.WoID',
                'words.WoText',
                'words.WoTranslation',
                'words.WoRomanization',
                'words.WoSentence',
                "IFNULL(words.WoSentence, '') LIKE CONCAT('%{', words.WoText, '}%') AS SentOK",
                'words.WoStatus',
                "IFNULL(group_concat(DISTINCT tags.TgText ORDER BY tags.TgText separator ','), '') AS taglist"
            ])
            ->leftJoin('word_tag_map', 'words.WoID', '=', 'word_tag_map.WtWoID')
            ->leftJoin('tags', 'tags.TgID', '=', 'word_tag_map.WtTgID')
            ->where('words.WoStatusChanged', '>', $lastUpdate)
            ->groupBy('words.WoID')
            ->limit($maxTerms)
            ->offset($offset)
            ->getPrepared();
    }

    /**
     * Return the list of imported terms with pagination information.
     *
     * @param string $lastUpdate  Terms import time
     * @param int    $currentpage Current page number
     * @param int    $recno       Number of imported terms
     *
     * @return array{navigation: array{current_page: int, total_pages: int}, terms: array<int, array<string, mixed>>}
     */
    public function importedTermsList(string $lastUpdate, int $currentpage, int $recno): array
    {
        $maxperpage = 100;
        $currentpage = $this->limitCurrentPage($currentpage, $recno, $maxperpage);
        $offset = ($currentpage - 1) * $maxperpage;

        $pages = intval(($recno - 1) / $maxperpage) + 1;
        return [
            "navigation" => [
                "current_page" => $currentpage,
                "total_pages" => $pages
            ],
            "terms" => $this->selectImportedTerms($lastUpdate, $offset, $maxperpage)
        ];
    }
}
