<?php

/**
 * Lemma Service (Facade)
 *
 * Delegates to specialized sub-services for lemmatization functionality.
 *
 * PHP version 8.2
 *
 * @category Lwt
 * @package  Lwt\Modules\Vocabulary\Application\Services
 * @author   HugoFara <git@hugofara.net>
 * @license  Unlicense <http://unlicense.org/>
 * @link     https://hugofara.github.io/lwt/developer/api
 * @since    3.0.0
 */

declare(strict_types=1);

namespace Lwt\Modules\Vocabulary\Application\Services;

use Lwt\Modules\Vocabulary\Domain\LemmatizerInterface;
use Lwt\Modules\Vocabulary\Domain\Term;
use Lwt\Modules\Vocabulary\Infrastructure\Lemmatizers\DictionaryLemmatizer;
use Lwt\Modules\Vocabulary\Infrastructure\MySqlTermRepository;

/**
 * Facade for managing lemmatization of vocabulary items.
 *
 * Delegates to:
 * - LemmatizerManager: lemmatizer instantiation and NLP availability
 * - LemmaBatchService: suggest/apply/propagate/link lemmas
 * - WordFamilyService: word family queries, details, status updates
 * - LemmaStatisticsService: statistics and cleanup
 *
 * @since 3.0.0
 */
class LemmaService
{
    private LemmatizerManager $lemmatizerManager;
    private LemmaBatchService $batchService;
    private WordFamilyService $wordFamilyService;
    private LemmaStatisticsService $statisticsService;

    /**
     * Constructor.
     *
     * @param LemmatizerInterface|null  $lemmatizer Lemmatizer implementation
     * @param MySqlTermRepository|null  $repository Term repository
     */
    public function __construct(
        ?LemmatizerInterface $lemmatizer = null,
        ?MySqlTermRepository $repository = null
    ) {
        $lemmatizer = $lemmatizer ?? new DictionaryLemmatizer();
        $repository = $repository ?? new MySqlTermRepository();

        $this->lemmatizerManager = new LemmatizerManager($lemmatizer);
        $this->batchService = new LemmaBatchService($lemmatizer, $repository);
        $this->wordFamilyService = new WordFamilyService($repository);
        $this->statisticsService = new LemmaStatisticsService();
    }

    // =========================================================================
    // LemmatizerManager delegates
    // =========================================================================

    /**
     * Get the best available lemmatizer for a language.
     *
     * @param string $languageCode ISO language code
     *
     * @return LemmatizerInterface
     */
    public function getLemmatizerForLanguage(string $languageCode): LemmatizerInterface
    {
        return $this->lemmatizerManager->getLemmatizerForLanguage($languageCode);
    }

    /**
     * Get a lemmatizer by type.
     *
     * @param string $type Lemmatizer type ('dictionary', 'spacy', 'hybrid')
     *
     * @return LemmatizerInterface
     */
    public function getLemmatizerByType(string $type): LemmatizerInterface
    {
        return $this->lemmatizerManager->getLemmatizerByType($type);
    }

    /**
     * Check if NLP service (spaCy) is available.
     *
     * @return bool
     */
    public function isNlpServiceAvailable(): bool
    {
        return $this->lemmatizerManager->isNlpServiceAvailable();
    }

    /**
     * Get languages supported by the NLP service.
     *
     * @return string[]
     */
    public function getNlpSupportedLanguages(): array
    {
        return $this->lemmatizerManager->getNlpSupportedLanguages();
    }

    /**
     * Get all languages potentially supported by NLP (including uninstalled models).
     *
     * @return string[]
     */
    public function getAllNlpLanguages(): array
    {
        return $this->lemmatizerManager->getAllNlpLanguages();
    }

    /**
     * Check if lemmatization is available for a language.
     *
     * @param string $languageCode ISO language code
     *
     * @return bool True if lemmatization is available
     */
    public function isAvailableForLanguage(string $languageCode): bool
    {
        return $this->lemmatizerManager->isAvailableForLanguage($languageCode);
    }

    /**
     * Get all languages with available lemmatization support.
     *
     * @return string[] Array of language codes
     */
    public function getAvailableLanguages(): array
    {
        return $this->lemmatizerManager->getAvailableLanguages();
    }

    // =========================================================================
    // LemmaBatchService delegates
    // =========================================================================

    /**
     * Suggest a lemma for a word.
     *
     * @param string $word         The word to lemmatize
     * @param string $languageCode ISO language code (e.g., 'en', 'de')
     *
     * @return string|null The suggested lemma, or null if not found
     */
    public function suggestLemma(string $word, string $languageCode): ?string
    {
        return $this->batchService->suggestLemma($word, $languageCode);
    }

    /**
     * Suggest lemmas for multiple words.
     *
     * @param string[] $words        Array of words
     * @param string   $languageCode ISO language code
     *
     * @return array<string, string|null> Word => lemma mapping
     */
    public function suggestLemmasBatch(array $words, string $languageCode): array
    {
        return $this->batchService->suggestLemmasBatch($words, $languageCode);
    }

    /**
     * Apply lemmas to existing vocabulary for a language.
     *
     * @param int    $languageId   Language ID
     * @param string $languageCode ISO language code for lemmatizer
     * @param int    $batchSize    Number of words to process per batch
     *
     * @return array{processed: int, updated: int, skipped: int}
     */
    public function applyLemmasToVocabulary(
        int $languageId,
        string $languageCode,
        int $batchSize = 100
    ): array {
        return $this->batchService->applyLemmasToVocabulary($languageId, $languageCode, $batchSize);
    }

    /**
     * Set lemma for a specific term.
     *
     * @param int    $termId Term ID
     * @param string $lemma  The lemma to set
     *
     * @return bool True if updated
     */
    public function setLemma(int $termId, string $lemma): bool
    {
        return $this->batchService->setLemma($termId, $lemma);
    }

    /**
     * Copy lemma from one term to all related terms.
     *
     * @param int    $termId       Source term ID
     * @param int    $languageId   Language ID
     * @param string $languageCode Language code for lemmatizer
     *
     * @return int Number of terms updated
     */
    public function propagateLemma(int $termId, int $languageId, string $languageCode): int
    {
        return $this->batchService->propagateLemma($termId, $languageId, $languageCode);
    }

    /**
     * Link unmatched text items to words by lemma.
     *
     * @param int      $languageId   Language ID
     * @param string   $languageCode ISO language code for lemmatizer
     * @param int|null $textId       Optional: limit to specific text
     *
     * @return array{linked: int, unmatched: int, errors: int}
     */
    public function linkTextItemsByLemma(
        int $languageId,
        string $languageCode,
        ?int $textId = null
    ): array {
        return $this->batchService->linkTextItemsByLemma($languageId, $languageCode, $textId);
    }

    /**
     * Find a word ID by its lemma.
     *
     * @param int    $languageId Language ID
     * @param string $lemmaLc    Lowercase lemma to match
     *
     * @return int|null Word ID or null if not found
     */
    public function findWordIdByLemma(int $languageId, string $lemmaLc): ?int
    {
        return $this->batchService->findWordIdByLemma($languageId, $lemmaLc);
    }

    /**
     * Link text items directly using SQL (efficient for large datasets).
     *
     * @param int      $languageId Language ID
     * @param int|null $textId     Optional text ID filter
     *
     * @return int Number of text items linked
     */
    public function linkTextItemsByLemmaSql(int $languageId, ?int $textId = null): int
    {
        return $this->batchService->linkTextItemsByLemmaSql($languageId, $textId);
    }

    // =========================================================================
    // WordFamilyService delegates
    // =========================================================================

    /**
     * Get the word family (all words sharing a lemma).
     *
     * @param int    $languageId Language ID
     * @param string $lemmaLc    Lowercase lemma
     *
     * @return Term[] Array of terms in the word family
     */
    public function getWordFamily(int $languageId, string $lemmaLc): array
    {
        return $this->wordFamilyService->getWordFamily($languageId, $lemmaLc);
    }

    /**
     * Get words grouped by their lemma.
     *
     * @param int $languageId Language ID
     * @param int $limit      Maximum number of lemma groups to return
     *
     * @return array<string, array{lemma: string, count: int, terms: string[]}>
     */
    public function getWordFamilies(int $languageId, int $limit = 50): array
    {
        return $this->wordFamilyService->getWordFamilies($languageId, $limit);
    }

    /**
     * Find terms that might benefit from lemmatization.
     *
     * @param int $languageId Language ID
     * @param int $limit      Maximum suggestions
     *
     * @return array<int, array{base: string, variants: string[]}>
     */
    public function findPotentialLemmaGroups(int $languageId, int $limit = 20): array
    {
        return $this->wordFamilyService->findPotentialLemmaGroups($languageId, $limit);
    }

    /**
     * Get detailed word family information for a term.
     *
     * @param int $termId Term ID to get family for
     *
     * @return array{lemma: string, lemmaLc: string, langId: int, terms: array, stats: array}|null
     */
    public function getWordFamilyDetails(int $termId): ?array
    {
        return $this->wordFamilyService->getWordFamilyDetails($termId);
    }

    /**
     * Update status for all words in a word family.
     *
     * @param int    $languageId Language ID
     * @param string $lemmaLc    Lowercase lemma
     * @param int    $status     New status (1-5, 98, 99)
     *
     * @return int Number of words updated
     */
    public function updateWordFamilyStatus(int $languageId, string $lemmaLc, int $status): int
    {
        return $this->wordFamilyService->updateWordFamilyStatus($languageId, $lemmaLc, $status);
    }

    /**
     * Get paginated list of word families for a language.
     *
     * @param int    $languageId Language ID
     * @param int    $page       Page number (1-based)
     * @param int    $perPage    Items per page
     * @param string $sortBy     Sort field: 'lemma', 'count', 'status'
     * @param string $sortDir    Sort direction: 'asc', 'desc'
     *
     * @return array{families: array, pagination: array}
     */
    public function getWordFamilyList(
        int $languageId,
        int $page = 1,
        int $perPage = 50,
        string $sortBy = 'lemma',
        string $sortDir = 'asc'
    ): array {
        return $this->wordFamilyService->getWordFamilyList($languageId, $page, $perPage, $sortBy, $sortDir);
    }

    /**
     * Get word family by lemma directly (without requiring a term ID).
     *
     * @param int    $languageId Language ID
     * @param string $lemmaLc    Lowercase lemma
     *
     * @return array|null
     */
    public function getWordFamilyByLemma(int $languageId, string $lemmaLc): ?array
    {
        return $this->wordFamilyService->getWordFamilyByLemma($languageId, $lemmaLc);
    }

    /**
     * Suggest status update for related forms when one form's status changes.
     *
     * @param int $termId    Term that was updated
     * @param int $newStatus The new status that was set
     *
     * @return array{suggestion: string, affected_count: int, term_ids: int[]}
     */
    public function getSuggestedFamilyUpdate(int $termId, int $newStatus): array
    {
        return $this->wordFamilyService->getSuggestedFamilyUpdate($termId, $newStatus);
    }

    /**
     * Apply status to multiple terms (for bulk family updates).
     *
     * @param int[] $termIds Term IDs to update
     * @param int   $status  New status
     *
     * @return int Number of terms updated
     */
    public function bulkUpdateTermStatus(array $termIds, int $status): int
    {
        return $this->wordFamilyService->bulkUpdateTermStatus($termIds, $status);
    }

    // =========================================================================
    // LemmaStatisticsService delegates
    // =========================================================================

    /**
     * Get lemma statistics for a language.
     *
     * @param int $languageId Language ID
     *
     * @return array{total_terms: int, with_lemma: int, without_lemma: int, unique_lemmas: int}
     */
    public function getLemmaStatistics(int $languageId): array
    {
        return $this->statisticsService->getLemmaStatistics($languageId);
    }

    /**
     * Clear all lemmas for a language.
     *
     * @param int $languageId Language ID
     *
     * @return int Number of terms affected
     */
    public function clearLemmas(int $languageId): int
    {
        return $this->statisticsService->clearLemmas($languageId);
    }

    /**
     * Get statistics about unmatched text items that could benefit from lemma linking.
     *
     * @param int $languageId Language ID
     *
     * @return array{unmatched_count: int, unique_words: int, matchable_by_lemma: int}
     */
    public function getUnmatchedStatistics(int $languageId): array
    {
        return $this->statisticsService->getUnmatchedStatistics($languageId);
    }

    /**
     * Get aggregate lemma statistics for a language.
     *
     * @param int $languageId Language ID
     *
     * @return array{
     *     total_lemmas: int, single_form: int, multi_form: int,
     *     avg_forms_per_lemma: float, status_distribution: array
     * }
     */
    public function getLemmaAggregateStats(int $languageId): array
    {
        return $this->statisticsService->getLemmaAggregateStats($languageId);
    }
}
