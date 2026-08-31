<?php

/**
 * Term CRUD API Handler
 *
 * Handles API operations for term CRUD (Create, Read, Update, Delete) and editing.
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

use Lwt\Shared\Infrastructure\Utilities\StringUtils;
use Lwt\Shared\Infrastructure\Database\Connection;
use Lwt\Shared\Infrastructure\Database\Maintenance;
use Lwt\Shared\Infrastructure\Database\QueryBuilder;
use Lwt\Shared\Infrastructure\Database\UserScopedQuery;
use Lwt\Modules\Vocabulary\Application\VocabularyFacade;
use Lwt\Modules\Vocabulary\Application\UseCases\FindSimilarTerms;
use Lwt\Modules\Vocabulary\Application\Services\TermStatusService;
use Lwt\Modules\Vocabulary\Application\Services\WordBulkService;
use Lwt\Modules\Tags\Application\TagsFacade;
use Lwt\Modules\Vocabulary\Application\Services\ExpressionService;
use Lwt\Modules\Vocabulary\Application\Services\WordContextService;
use Lwt\Modules\Vocabulary\Application\Services\WordCrudService;
use Lwt\Modules\Vocabulary\Application\Services\WordDiscoveryService;
use Lwt\Modules\Vocabulary\Application\Services\WordLinkingService;
use Lwt\Modules\Vocabulary\Application\Helpers\StatusHelper;

/**
 * Handler for term CRUD API operations.
 *
 * Provides endpoints for:
 * - Basic CRUD operations (get, create, update, delete)
 * - Term details retrieval
 * - Quick term creation
 * - Full term editing with lemma, tags, notes
 *
 * @since 3.0.0
 */
class TermCrudApiHandler
{
    private VocabularyFacade $facade;
    private FindSimilarTerms $findSimilarTerms;
    private WordContextService $contextService;
    private WordDiscoveryService $discoveryService;
    private WordLinkingService $linkingService;
    private WordBulkService $bulkService;
    private WordCrudService $crudService;
    private ExpressionService $expressionService;

    /**
     * Constructor.
     *
     * @param VocabularyFacade|null     $facade           Vocabulary facade
     * @param FindSimilarTerms|null     $findSimilarTerms Find similar terms use case
     * @param WordContextService|null   $contextService   Context service
     * @param WordDiscoveryService|null $discoveryService Discovery service
     * @param WordLinkingService|null   $linkingService   Linking service
     */
    public function __construct(
        ?VocabularyFacade $facade = null,
        ?FindSimilarTerms $findSimilarTerms = null,
        ?WordContextService $contextService = null,
        ?WordDiscoveryService $discoveryService = null,
        ?WordLinkingService $linkingService = null,
        ?WordBulkService $bulkService = null,
        ?WordCrudService $crudService = null,
        ?ExpressionService $expressionService = null
    ) {
        $this->facade = $facade ?? new VocabularyFacade();
        $this->findSimilarTerms = $findSimilarTerms ?? new FindSimilarTerms();
        $this->contextService = $contextService ?? new WordContextService();
        $this->discoveryService = $discoveryService ?? new WordDiscoveryService();
        $this->linkingService = $linkingService ?? new WordLinkingService();
        $this->bulkService = $bulkService ?? new WordBulkService();
        $this->crudService = $crudService ?? new WordCrudService();
        $this->expressionService = $expressionService ?? new ExpressionService();
    }

    // =========================================================================
    // Term CRUD Operations
    // =========================================================================

    /**
     * Get a term by ID.
     *
     * @param int $termId Term ID
     *
     * @return array Term data or error
     */
    public function getTerm(int $termId): array
    {
        $term = $this->facade->getTerm($termId);

        if ($term === null) {
            return ['error' => 'Term not found'];
        }

        return [
            'id' => $term->id()->toInt(),
            'text' => $term->text(),
            'textLc' => $term->textLowercase(),
            'lemma' => $term->lemma(),
            'lemmaLc' => $term->lemmaLc(),
            'translation' => $term->translation(),
            'romanization' => $term->romanization(),
            'sentence' => $term->sentence(),
            'status' => $term->status()->toInt(),
            'statusLabel' => TermStatusService::getStatusName($term->status()->toInt()),
            'langId' => $term->languageId(),
            'wordCount' => $term->wordCount(),
        ];
    }

    /**
     * Create a new term.
     *
     * @param array $data Term data:
     *                    - langId: int Language ID
     *                    - text: string Term text
     *                    - status: int Status (1-5, 98, 99)
     *                    - translation: string Translation
     *                    - romanization: string Romanization (optional)
     *                    - sentence: string Example sentence (optional)
     *
     * @return array{success: bool, id?: int, textLc?: string, hex?: string, error?: string}
     */
    public function createTerm(array $data): array
    {
        $langId = (int) ($data['langId'] ?? 0);
        $text = trim((string)($data['text'] ?? ''));
        $status = (int) ($data['status'] ?? 1);
        $translation = trim((string)($data['translation'] ?? '*'));
        $romanization = trim((string)($data['romanization'] ?? ''));
        $sentence = trim((string)($data['sentence'] ?? ''));

        if ($langId === 0 || $text === '') {
            return ['success' => false, 'error' => 'Language ID and text are required'];
        }

        if (!TermStatusService::isValidStatus($status)) {
            return ['success' => false, 'error' => 'Invalid status'];
        }

        try {
            $term = $this->facade->createTerm(
                $langId,
                $text,
                $status,
                $translation ?: '*',
                $romanization,
                $sentence
            );

            $textLc = $term->textLowercase();

            return [
                'success' => true,
                'id' => $term->id()->toInt(),
                'textLc' => $textLc,
                'hex' => StringUtils::toClassName($textLc),
            ];
        } catch (\Exception $e) {
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    /**
     * Update a term.
     *
     * @param int   $termId Term ID
     * @param array $data   Fields to update
     *
     * @return array{success: bool, error?: string}
     */
    public function updateTerm(int $termId, array $data): array
    {
        $term = $this->facade->getTerm($termId);
        if ($term === null) {
            return ['success' => false, 'error' => 'Term not found'];
        }

        $updates = [];
        if (isset($data['translation'])) {
            $updates['translation'] = trim((string)$data['translation']) ?: '*';
        }
        if (isset($data['romanization'])) {
            $updates['romanization'] = trim((string)$data['romanization']);
        }
        if (isset($data['sentence'])) {
            $updates['sentence'] = trim((string)$data['sentence']);
        }

        try {
            $status = isset($data['status']) ? (int) $data['status'] : null;
            if ($status !== null && !TermStatusService::isValidStatus($status)) {
                $status = null;
            }

            $this->facade->updateTerm(
                $termId,
                $status,
                $updates['translation'] ?? null,
                $updates['sentence'] ?? null,
                null // notes
            );

            return ['success' => true];
        } catch (\Exception $e) {
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    /**
     * Delete a term.
     *
     * @param int $termId Term ID
     *
     * @return array{deleted: bool, error?: string}
     */
    public function deleteTerm(int $termId): array
    {
        $term = $this->facade->getTerm($termId);
        if ($term === null) {
            return ['deleted' => false, 'error' => 'Term not found'];
        }

        $result = $this->facade->deleteTerm($termId);
        return ['deleted' => $result];
    }

    /**
     * Delete multiple terms.
     *
     * @param int[] $termIds Term IDs
     *
     * @return array{deleted: int, error?: string}
     */
    public function deleteTerms(array $termIds): array
    {
        if (empty($termIds)) {
            return ['deleted' => 0, 'error' => 'No term IDs provided'];
        }

        $count = $this->facade->deleteTerms($termIds);
        return ['deleted' => $count];
    }

    // =========================================================================
    // API Response Formatters
    // =========================================================================

    /**
     * Format response for getting a term.
     *
     * @param int $termId Term ID
     *
     * @return array
     */
    public function formatGetTerm(int $termId): array
    {
        return $this->getTerm($termId);
    }

    /**
     * Format response for creating a term.
     *
     * @param array $data Term data
     *
     * @return array
     */
    public function formatCreateTerm(array $data): array
    {
        return $this->createTerm($data);
    }

    /**
     * Format response for updating a term.
     *
     * @param int   $termId Term ID
     * @param array $data   Term data
     *
     * @return array
     */
    public function formatUpdateTerm(int $termId, array $data): array
    {
        return $this->updateTerm($termId, $data);
    }

    /**
     * Format response for deleting a term.
     *
     * @param int $termId Term ID
     *
     * @return array
     */
    public function formatDeleteTerm(int $termId): array
    {
        return $this->deleteTerm($termId);
    }

    /**
     * List a text's still-unknown single words, for the bulk-translate page.
     *
     * Paginated by the caller-supplied offset. One extra row beyond `limit` is
     * fetched so the response can report whether another page exists without a
     * second COUNT query — the row itself is not returned.
     *
     * The underlying query is scoped through `Ti2TxID -> texts`, so a text
     * belonging to another user yields nothing rather than leaking terms.
     *
     * @param int $textId Text whose unknown words to list
     * @param int $offset Rows to skip
     * @param int $limit  Rows to return (1-500)
     *
     * @return array{terms: list<array<string, mixed>>, has_more: bool, next_offset: int|null}
     */
    public function getUnknownWordsForTranslate(int $textId, int $offset, int $limit): array
    {
        $offset = max(0, $offset);
        $limit = min(500, max(1, $limit));

        $rows = $this->discoveryService->getUnknownWordsForBulkTranslate($textId, $offset, $limit + 1);

        $hasMore = count($rows) > $limit;
        $terms = $hasMore ? array_slice($rows, 0, $limit) : $rows;

        return [
            'terms' => array_values($terms),
            'has_more' => $hasMore,
            'next_offset' => $hasMore ? $offset + $limit : null,
        ];
    }

    // =========================================================================
    // Term Details (migrated from TermHandler)
    // =========================================================================

    /**
     * Get detailed term information including sentence and tags.
     *
     * @param int         $termId Term ID
     * @param string|null $ann    Annotation to highlight in translation
     *
     * @return array{id: int, text: string, textLc: string, lemma: string, lemmaLc: string,
     *               translation: string, romanization: string, status: int, langId: int,
     *               sentence: string, notes: string, tags: array<string>,
     *               statusLabel: string}|array{error: string}
     */
    public function getTermDetails(int $termId, ?string $ann = null): array
    {
        $record = QueryBuilder::table('words')
            ->select([
                'WoID', 'WoText', 'WoTextLC', 'WoLemma', 'WoLemmaLC', 'WoTranslation',
                'WoRomanization', 'WoStatus', 'WoLgID', 'WoSentence', 'WoNotes'
            ])
            ->where('WoID', '=', $termId)
            ->firstPrepared();

        if ($record === null) {
            return ['error' => 'Term not found'];
        }

        // Get tags for the word - using JOIN with user-scoped tables
        $tagsResult = QueryBuilder::table('word_tag_map')
            ->select(['tags.TgText'])
            ->join('tags', 'tags.TgID', '=', 'word_tag_map.WtTgID')
            ->where('word_tag_map.WtWoID', '=', $termId)
            ->orderBy('tags.TgText')
            ->getPrepared();
        $tags = array_map(fn($row) => (string)$row['TgText'], $tagsResult);

        // Process translation - highlight annotation if provided
        $translation = (string)$record['WoTranslation'];
        if ($ann !== null && $ann !== '' && $translation !== '' && $translation !== '*') {
            $translation = str_replace($ann, '<b>' . $ann . '</b>', $translation);
        }

        return [
            'id' => (int)$record['WoID'],
            'text' => (string)$record['WoText'],
            'textLc' => (string)$record['WoTextLC'],
            'lemma' => (string)($record['WoLemma'] ?? ''),
            'lemmaLc' => (string)($record['WoLemmaLC'] ?? ''),
            'translation' => $translation,
            'romanization' => (string)$record['WoRomanization'],
            'status' => (int)$record['WoStatus'],
            'langId' => (int)$record['WoLgID'],
            'sentence' => (string)$record['WoSentence'],
            'notes' => (string)($record['WoNotes'] ?? ''),
            'tags' => $tags,
            'statusLabel' => TermStatusService::getStatusName((int)$record['WoStatus'])
        ];
    }

    /**
     * Format response for getting term details.
     *
     * @param int         $termId Term ID
     * @param string|null $ann    Optional annotation to highlight
     *
     * @return array
     */
    public function formatGetTermDetails(int $termId, ?string $ann = null): array
    {
        return $this->getTermDetails($termId, $ann);
    }

    // =========================================================================
    // Quick Term Creation (migrated from TermHandler)
    // =========================================================================

    /**
     * Create a term quickly with wellknown (99) or ignored (98) status.
     *
     * @param int $textId Text ID containing the word
     * @param int $ord    Word position (order) in text
     * @param int $status Status to set (98 for ignored, 99 for well-known)
     *
     * @return array{term_id?: int, term?: string, term_lc?: string, hex?: string, error?: string}
     */
    public function createQuickTerm(int $textId, int $ord, int $status): array
    {
        // Validate status
        if ($status !== 98 && $status !== 99) {
            return ['error' => 'Status must be 98 (ignored) or 99 (well-known)'];
        }

        // Get the word at the position
        $term = $this->linkingService->getWordAtPosition($textId, $ord);
        if ($term === null) {
            return ['error' => 'Word not found at position'];
        }

        try {
            $result = $this->discoveryService->insertWordWithStatus($textId, $term, $status);
            return [
                'term_id' => $result['id'],
                'term' => $result['term'],
                'term_lc' => $result['termlc'],
                'hex' => $result['hex']
            ];
        } catch (\RuntimeException $e) {
            return ['error' => $e->getMessage()];
        }
    }

    /**
     * Format response for quick term creation.
     *
     * @param int $textId   Text ID
     * @param int $position Word position in text
     * @param int $status   Status (98 or 99)
     *
     * @return array{term_id?: int, term?: string, term_lc?: string, hex?: string, error?: string}
     */
    public function formatQuickCreate(int $textId, int $position, int $status): array
    {
        return $this->createQuickTerm($textId, $position, $status);
    }

    // =========================================================================
    // Full Term CRUD for Reactive UI (migrated from TermHandler)
    // =========================================================================

    /**
     * Get term data prepared for editing in modal.
     *
     * An existing term carries its own language, so $textId may be 0 when
     * $wordId is given. That is what lets callers with no reading context —
     * the review screen, the term list — open the same editor.
     *
     * @param int      $textId   Text ID (may be 0 when $wordId is given)
     * @param int      $position Position in text
     * @param int|null $wordId   Word ID (for existing terms)
     *
     * @return array Term data with language settings and similar terms
     */
    public function getTermForEdit(int $textId, int $position, ?int $wordId = null): array
    {
        $hasWordId = $wordId !== null && $wordId > 0;

        if ($hasWordId) {
            $wordLang = QueryBuilder::table('words')
                ->select(['WoLgID'])
                ->where('WoID', '=', $wordId)
                ->firstPrepared();

            if ($wordLang === null) {
                return ['error' => 'Term not found'];
            }

            $langId = (int) $wordLang['WoLgID'];
        } else {
            // A new term is only identifiable through its position in a text.
            $textData = QueryBuilder::table('texts')
                ->select(['TxLgID', 'TxTitle'])
                ->where('TxID', '=', $textId)
                ->firstPrepared();

            if ($textData === null) {
                return ['error' => 'Text not found'];
            }

            $langId = (int) $textData['TxLgID'];

            // The caller passes no wordId when the reader believes the word is
            // unknown, but the reader reads that from the occurrence table. A
            // term created without linking its occurrences — every term a
            // dictionary import creates — is invisible there while still
            // occupying (WoTextLC, WoLgID). Reported as new, the form would
            // POST /terms/full and collide with the unique index (issue #283).
            // Resolve the term by what identifies it, so the form opens on the
            // row that exists and saves through the update path.
            $wordAtPosition = $this->linkingService->getWordAtPosition($textId, $position);
            if ($wordAtPosition !== null) {
                $existingId = $this->findTermIdByText(
                    $langId,
                    mb_strtolower($wordAtPosition, 'UTF-8')
                );
                if ($existingId !== null) {
                    $wordId = $existingId;
                    $hasWordId = true;
                }
            }
        }

        // Get language settings
        $langData = QueryBuilder::table('languages')
            ->select(['LgName', 'LgShowRomanization', 'LgGoogleTranslateURI'])
            ->where('LgID', '=', $langId)
            ->firstPrepared();

        if ($langData === null) {
            return ['error' => 'Language not found'];
        }

        // Get all term tags for autocomplete
        $allTags = TagsFacade::getAllTermTags();

        // Build language info
        $language = [
            'id' => $langId,
            'name' => (string) $langData['LgName'],
            'showRomanization' => (bool) $langData['LgShowRomanization'],
            'translateUri' => (string) ($langData['LgGoogleTranslateURI'] ?? '')
        ];

        // If word ID provided, get existing term data
        if ($hasWordId) {
            $termData = QueryBuilder::table('words')
                ->select([
                    'WoID', 'WoText', 'WoTextLC', 'WoLemma', 'WoLemmaLC', 'WoTranslation',
                    'WoRomanization', 'WoSentence', 'WoNotes', 'WoStatus', 'WoLgID'
                ])
                ->where('WoID', '=', $wordId)
                ->firstPrepared();

            if ($termData === null) {
                return ['error' => 'Term not found'];
            }

            // Get tags for the word
            $tagsResult = QueryBuilder::table('word_tag_map')
                ->select(['tags.TgText'])
                ->join('tags', 'tags.TgID', '=', 'word_tag_map.WtTgID')
                ->where('word_tag_map.WtWoID', '=', $wordId)
                ->orderBy('tags.TgText')
                ->getPrepared();
            $tags = array_map(fn($row) => (string)$row['TgText'], $tagsResult);

            $term = [
                'id' => (int) $termData['WoID'],
                'text' => (string) $termData['WoText'],
                'textLc' => (string) $termData['WoTextLC'],
                'lemma' => (string) ($termData['WoLemma'] ?? ''),
                'lemmaLc' => (string) ($termData['WoLemmaLC'] ?? ''),
                'hex' => StringUtils::toClassName((string) $termData['WoTextLC']),
                'translation' => (string) $termData['WoTranslation'],
                'romanization' => (string) $termData['WoRomanization'],
                'sentence' => (string) $termData['WoSentence'],
                'notes' => (string) ($termData['WoNotes'] ?? ''),
                'status' => (int) $termData['WoStatus'],
                'tags' => $tags
            ];

            // Get similar terms
            $similarTerms = $this->getSimilarTermsForEdit($langId, (string) $termData['WoTextLC'], $wordId);

            return [
                'isNew' => false,
                'term' => $term,
                'language' => $language,
                'allTags' => $allTags,
                'similarTerms' => $similarTerms
            ];
        }

        // New term - get word at position
        $wordData = $this->linkingService->getWordAtPosition($textId, $position);
        if ($wordData === null) {
            return ['error' => 'Word not found at position'];
        }

        $text = $wordData;
        $textLc = mb_strtolower($text, 'UTF-8');

        // Get sentence at position
        $sentence = $this->contextService->getSentenceTextAtPosition($textId, $position);

        // Mark the term in the sentence with curly braces if not already marked
        if ($sentence !== null && strpos($sentence, '{') === false) {
            // Simple replacement - replace first occurrence of the term
            $sentence = preg_replace(
                '/\b' . preg_quote($text, '/') . '\b/iu',
                '{' . $text . '}',
                $sentence,
                1
            );
        }

        $term = [
            'id' => null,
            'text' => $text,
            'textLc' => $textLc,
            'lemma' => '',
            'lemmaLc' => '',
            'hex' => StringUtils::toClassName($textLc),
            'translation' => '',
            'romanization' => '',
            'sentence' => $sentence ?? '',
            'notes' => '',
            'status' => 1,
            'tags' => []
        ];

        // Get similar terms for new word
        $similarTerms = $this->getSimilarTermsForEdit($langId, $textLc, null);

        return [
            'isNew' => true,
            'term' => $term,
            'language' => $language,
            'allTags' => $allTags,
            'similarTerms' => $similarTerms
        ];
    }

    /**
     * The ID of the term this language already holds for `$termlc`, if any.
     *
     * Looks the term up by the pair `words` is unique on. The reader's notion
     * of a new word comes from `word_occurrences` and can be stale, so it is
     * not usable as an existence check.
     *
     * @param int    $langId Language ID
     * @param string $termlc Lowercased term text
     *
     * @return int|null Existing word ID, or null when the term is genuinely new
     */
    private function findTermIdByText(int $langId, string $termlc): ?int
    {
        $existing = QueryBuilder::table('words')
            ->select(['WoID'])
            ->where('WoTextLC', '=', $termlc)
            ->where('WoLgID', '=', $langId)
            ->firstPrepared();

        return $existing === null ? null : (int) $existing['WoID'];
    }

    /**
     * Get similar terms for the edit form.
     *
     * @param int      $langId    Language ID
     * @param string   $termLc    Term in lowercase
     * @param int|null $excludeId Word ID to exclude (current term)
     *
     * @return array Array of similar terms
     */
    private function getSimilarTermsForEdit(int $langId, string $termLc, ?int $excludeId): array
    {
        $similarIds = $this->findSimilarTerms->execute($langId, $termLc, 10, 0.33);

        $result = [];
        foreach ($similarIds as $termId) {
            if ($excludeId !== null && $termId === $excludeId) {
                continue;
            }

            $record = QueryBuilder::table('words')
                ->select(['WoID', 'WoText', 'WoTranslation', 'WoStatus'])
                ->where('WoID', '=', $termId)
                ->firstPrepared();

            if ($record !== null) {
                $result[] = [
                    'id' => (int) $record['WoID'],
                    'text' => (string) $record['WoText'],
                    'translation' => (string) $record['WoTranslation'],
                    'status' => (int) $record['WoStatus']
                ];
            }
        }

        return $result;
    }

    /**
     * Create a term with full data (translation, romanization, sentence, tags, status).
     *
     * @param array $data Term data:
     *                    - textId: Text ID
     *                    - position: Position in text
     *                    - translation: Translation
     *                    - romanization: Romanization (optional)
     *                    - sentence: Example sentence (optional)
     *                    - notes: Notes (optional)
     *                    - status: Status (1-5, default: 1)
     *                    - tags: Array of tag names (optional)
     *
     * @return array{success?: bool, term?: array, error?: string}
     */
    public function createTermFull(array $data): array
    {
        $textId = (int) ($data['textId'] ?? 0);
        $position = (int) ($data['position'] ?? 0);

        if ($textId === 0) {
            return ['error' => 'Text ID is required'];
        }

        // Get language ID from text
        $langId = $this->contextService->getLanguageIdFromText($textId);
        if ($langId === null) {
            return ['error' => 'Text not found'];
        }

        // Get the word at the position
        $wordText = $this->linkingService->getWordAtPosition($textId, $position);
        if ($wordText === null) {
            return ['error' => 'Word not found at position'];
        }

        $textLc = mb_strtolower($wordText, 'UTF-8');
        $status = (int) ($data['status'] ?? 1);

        // Validate status
        if (!TermStatusService::isValidStatus($status)) {
            return ['error' => 'Status must be 1-5, 98, or 99'];
        }

        // getTermForEdit() now routes the form to the update path when the term
        // already exists, so the caller reaching here with one is either an API
        // client of its own or a form loaded before the term appeared. Creating
        // is still the wrong verb for it — a plain INSERT would fail on the
        // unique index (issue #283) — so hand it to the update path, which
        // takes the same payload.
        $existingId = $this->findTermIdByText($langId, $textLc);
        if ($existingId !== null) {
            return $this->updateTermFull($existingId, $data);
        }

        $translation = trim((string)($data['translation'] ?? ''));
        if ($translation === '') {
            $translation = '*';
        }

        $romanization = trim((string)($data['romanization'] ?? ''));
        $sentence = trim((string)($data['sentence'] ?? ''));
        $notes = trim((string)($data['notes'] ?? ''));
        $lemma = isset($data['lemma']) && $data['lemma'] !== '' ? trim((string)$data['lemma']) : null;
        $lemmaLc = $lemma !== null ? mb_strtolower($lemma, 'UTF-8') : null;

        // Insert the word

        // Use raw SQL for complex INSERT with dynamic columns.
        // INSERT cannot use forTablePrepared (which appends " AND … = ?",
        // syntactically nonsensical after VALUES). Inject the user-scope
        // column + value into the column/value lists instead.
        $bindings = [
            $langId, $textLc, $wordText, $lemma, $lemmaLc,
            $status, $translation, $sentence, $notes, $romanization
        ];
        $userScopeColumn = '';
        $userScopeValue = '';
        $userIdForInsert = UserScopedQuery::getUserIdForInsert('words');
        if ($userIdForInsert !== null) {
            $userScopeColumn = ', WoUsID';
            $userScopeValue = ', ?';
            $bindings[] = $userIdForInsert;
        }
        $sql = "INSERT INTO words (
                WoLgID, WoTextLC, WoText, WoLemma, WoLemmaLC, WoStatus, WoTranslation,
                WoSentence, WoNotes, WoRomanization, WoStatusChanged{$userScopeColumn}
            ) VALUES(?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(){$userScopeValue})";

        $stmt = Connection::prepare($sql);
        $stmt->bindValues($bindings);
        $affected = $stmt->execute();

        if ($affected != 1) {
            return ['error' => 'Failed to create term'];
        }

        $wordId = $stmt->insertId();

        // Update text items to link to this word
        // word_occurrences inherits user context via Ti2TxID -> texts FK
        Connection::preparedExecute(
            "UPDATE word_occurrences
             SET Ti2WoID = ?
             WHERE Ti2LgID = ? AND LOWER(Ti2Text) = ?",
            [$wordId, $langId, $textLc]
        );

        // Save tags if provided
        /** @var array<int|string>|null $rawTags */
        $rawTags = $data['tags'] ?? null;
        /** @var array<string> $tags */
        $tags = [];
        if (is_array($rawTags) && count($rawTags) > 0) {
            $tags = array_map('strval', $rawTags);
            TagsFacade::saveWordTagsFromArray((int)$wordId, $tags);
        }

        // Return complete term data
        return [
            'success' => true,
            'term' => [
                'id' => $wordId,
                'text' => $wordText,
                'textLc' => $textLc,
                'lemma' => $lemma ?? '',
                'lemmaLc' => $lemmaLc ?? '',
                'hex' => StringUtils::toClassName($textLc),
                'translation' => $translation === '*' ? '' : $translation,
                'romanization' => $romanization,
                'sentence' => $sentence,
                'notes' => $notes,
                'status' => $status,
                'tags' => $tags
            ]
        ];
    }

    /**
     * Update a term with full data.
     *
     * @param int   $termId Term ID
     * @param array $data   Term data:
     *                      - translation: Translation
     *                      - romanization: Romanization (optional)
     *                      - sentence: Example sentence (optional)
     *                      - notes: Notes (optional)
     *                      - status: Status (1-5)
     *                      - tags: Array of tag names (optional)
     *
     * @return array{success?: bool, term?: array, error?: string}
     */
    public function updateTermFull(int $termId, array $data): array
    {
        // Get existing term data
        $existing = QueryBuilder::table('words')
            ->select(['WoID', 'WoText', 'WoTextLC', 'WoLgID', 'WoStatus'])
            ->where('WoID', '=', $termId)
            ->firstPrepared();

        if ($existing === null) {
            return ['error' => 'Term not found'];
        }

        $status = (int) ($data['status'] ?? $existing['WoStatus']);

        // Validate status
        if (!TermStatusService::isValidStatus($status)) {
            return ['error' => 'Status must be 1-5, 98, or 99'];
        }

        $textLc = (string) $existing['WoTextLC'];

        // The term text may only be recased, never rewritten: WoTextLC is what
        // textitems2 is linked on, so changing it would orphan the occurrences.
        $text = (string) $existing['WoText'];
        if (isset($data['text']) && is_scalar($data['text'])) {
            $candidate = trim((string) $data['text']);
            if ($candidate !== '') {
                if (mb_strtolower($candidate, 'UTF-8') !== $textLc) {
                    return ['error' => 'Term in lowercase must be exactly "' . $textLc . '"'];
                }
                $text = $candidate;
            }
        }

        $translation = trim((string)($data['translation'] ?? ''));
        if ($translation === '') {
            $translation = '*';
        }

        $romanization = trim((string)($data['romanization'] ?? ''));
        $sentence = trim((string)($data['sentence'] ?? ''));
        $notes = trim((string)($data['notes'] ?? ''));
        $lemma = isset($data['lemma']) && $data['lemma'] !== '' ? trim((string)$data['lemma']) : null;
        $lemmaLc = $lemma !== null ? mb_strtolower($lemma, 'UTF-8') : null;

        // Update the word

        // Use raw SQL for dynamic score update
        $bindings = [$text, $translation, $romanization, $sentence, $notes, $lemma, $lemmaLc, $status, $termId];
        Connection::preparedExecute(
            "UPDATE words SET
             WoText = ?,
             WoTranslation = ?,
             WoRomanization = ?,
             WoSentence = ?,
             WoNotes = ?,
             WoLemma = ?,
             WoLemmaLC = ?,
             WoStatus = ?,
             WoStatusChanged = NOW()
             WHERE WoID = ?"
            . UserScopedQuery::forTablePrepared('words', $bindings),
            [$text, $translation, $romanization, $sentence, $notes, $lemma, $lemmaLc, $status, $termId]
        );

        // A term whose occurrences were never linked stays invisible to the
        // reader however often it is edited, so saving one is the moment to
        // repair it (issue #283). Re-pointing occurrences that already carry
        // this WoID costs nothing and changes nothing.
        $this->linkingService->linkToTextItems($termId, (int) $existing['WoLgID'], $textLc);

        // Save tags if provided
        $tags = [];
        if (isset($data['tags']) && is_array($data['tags'])) {
            /** @var array<array-key, string> $tags */
            $tags = array_map(
                static fn($tag): string => is_scalar($tag) ? (string) $tag : '',
                $data['tags']
            );
            TagsFacade::saveWordTagsFromArray($termId, $tags);
        }

        // Return complete term data
        return [
            'success' => true,
            'term' => [
                'id' => $termId,
                'text' => $text,
                'textLc' => $textLc,
                'lemma' => $lemma ?? '',
                'lemmaLc' => $lemmaLc ?? '',
                'hex' => StringUtils::toClassName($textLc),
                'translation' => $translation === '*' ? '' : $translation,
                'romanization' => $romanization,
                'sentence' => $sentence,
                'notes' => $notes,
                'status' => $status,
                'tags' => $tags
            ]
        ];
    }

    /**
     * Create several terms at once, as the bulk-translate page does.
     *
     * @param array $data Request body with a `terms` list of
     *                    {lg, text, status, trans} entries
     *
     * @return array Response data
     */
    public function createTermsBulk(array $data): array
    {
        $rawTerms = $data['terms'] ?? null;
        if (!is_array($rawTerms) || $rawTerms === []) {
            return ['error' => 'No terms supplied'];
        }

        $terms = [];
        /** @var mixed $row */
        foreach ($rawTerms as $row) {
            if (!is_array($row)) {
                continue;
            }
            $text = trim((string) ($row['text'] ?? ''));
            $langId = (int) ($row['lg'] ?? 0);
            if ($text === '' || $langId <= 0) {
                continue;
            }
            $status = (int) ($row['status'] ?? 1);
            if (!TermStatusService::isValidStatus($status)) {
                return ['error' => 'Status must be 1-5, 98, or 99'];
            }
            $terms[] = [
                'lg' => $langId,
                'text' => $text,
                'status' => $status,
                'trans' => trim((string) ($row['trans'] ?? '')),
            ];
        }

        if ($terms === []) {
            return ['error' => 'No terms supplied'];
        }

        $maxWoId = $this->bulkService->bulkSaveTerms($terms);
        $newWords = $this->bulkService->getNewWordsAfter($maxWoId);

        // Newly created terms have to be attached to their occurrences, or the
        // reading view keeps showing them as unknown.
        $this->linkingService->linkNewWordsToTextItems($maxWoId);

        return [
            'success' => true,
            'saved' => count($newWords),
        ];
    }

    /**
     * Format response for getting term data for editing.
     *
     * @param int      $textId   Text ID
     * @param int      $position Position in text
     * @param int|null $wordId   Word ID
     *
     * @return array
     */
    public function formatGetTermForEdit(int $textId, int $position, ?int $wordId = null): array
    {
        return $this->getTermForEdit($textId, $position, $wordId);
    }

    /**
     * Create a term for a language, outside any text.
     *
     * Backs the standalone "new term" page, which is not anchored to a
     * position in a text the way {@see createTermFull()} is — so the language
     * arrives from the client rather than from a scoped text lookup.
     *
     * That is exactly why the ownership check below exists. The retired
     * `POST /word/new` read `WoLgID` from the request and passed it into the
     * occurrence-linking path unvalidated; moving the read here puts it behind
     * a gate instead of relying on the one inside WordLinkingService.
     *
     * @param array<string, mixed> $data Term fields
     *
     * @return array{success: bool, id?: int, error?: string}
     */
    public function createTermForLanguage(array $data): array
    {
        $langId = (int) ($data['language_id'] ?? 0);
        if ($langId <= 0) {
            return ['success' => false, 'error' => 'language_id is required'];
        }

        // languages is user-scoped, so somebody else's language finds nothing.
        $ownsLanguage = QueryBuilder::table('languages')
            ->where('LgID', '=', $langId)
            ->existsPrepared();
        if (!$ownsLanguage) {
            return ['success' => false, 'error' => 'Language not found'];
        }

        $text = trim((string) ($data['text'] ?? ''));
        if ($text === '') {
            return ['success' => false, 'error' => 'Term text is required'];
        }

        $status = (int) ($data['status'] ?? 1);
        if (!TermStatusService::isValidStatus($status)) {
            return ['success' => false, 'error' => 'Status must be 1-5, 98, or 99'];
        }

        $result = $this->crudService->create([
            'WoLgID' => $langId,
            'WoText' => $text,
            'WoStatus' => $status,
            'WoTranslation' => (string) ($data['translation'] ?? ''),
            'WoRomanization' => (string) ($data['romanization'] ?? ''),
            'WoSentence' => (string) ($data['sentence'] ?? ''),
            'WoNotes' => (string) ($data['notes'] ?? ''),
            'WoLemma' => (string) ($data['lemma'] ?? ''),
        ]);

        if (!$result['success']) {
            return ['success' => false, 'error' => $result['message'] ?? 'Failed to create term'];
        }

        $wid = $result['id'];
        $textLc = $result['textlc'];

        /** @var mixed $rawTags */
        $rawTags = $data['tags'] ?? null;
        /** @var list<string> $tags */
        $tags = [];
        if (is_array($rawTags)) {
            foreach (array_filter($rawTags, 'is_scalar') as $tag) {
                $tags[] = (string) $tag;
            }
        }
        if ($tags !== []) {
            TagsFacade::saveWordTagsFromArray($wid, $tags);
        }

        Maintenance::initWordCount();

        // A multi-word term needs its occurrences derived from the sentences it
        // appears in; a single word just links to matching occurrences.
        $length = $this->crudService->getWordCount($wid);
        if ($length > 1) {
            $this->expressionService->insertExpressions($textLc, $langId, $wid, $length, 1);
        } else {
            $this->linkingService->linkToTextItems($wid, $langId, $textLc);
        }

        return ['success' => true, 'id' => $wid];
    }

    /**
     * Format response for creating a term with full data.
     *
     * @param array $data Term data
     *
     * @return array
     */
    public function formatCreateTermFull(array $data): array
    {
        return $this->createTermFull($data);
    }

    /**
     * Format response for updating a term with full data.
     *
     * @param int   $termId Term ID
     * @param array $data   Term data
     *
     * @return array
     */
    public function formatUpdateTermFull(int $termId, array $data): array
    {
        return $this->updateTermFull($termId, $data);
    }
}
