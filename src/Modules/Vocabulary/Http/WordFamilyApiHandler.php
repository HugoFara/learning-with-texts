<?php

/**
 * Word Family API Handler
 *
 * Handles API operations for word families and lemmas.
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

use Lwt\Api\V1\Response;
use Lwt\Modules\Vocabulary\Application\Services\LemmaService;
use Lwt\Modules\Vocabulary\Domain\ValueObject\TermStatus;
use Lwt\Shared\Http\ApiRoutableInterface;
use Lwt\Shared\Http\ApiRoutableTrait;
use Lwt\Shared\Infrastructure\Http\JsonResponse;

/**
 * Handler for word family/lemma-related API operations.
 *
 * Provides endpoints for:
 * - Getting word families by term or lemma
 * - Listing word families for a language
 * - Updating status for entire word families
 * - Getting lemma statistics
 *
 * @since 3.0.0
 */
class WordFamilyApiHandler implements ApiRoutableInterface
{
    use ApiRoutableTrait;

    private ?LemmaService $lemmaService = null;

    /**
     * Constructor.
     *
     * @param LemmaService|null $lemmaService Lemma service instance
     */
    public function __construct(?LemmaService $lemmaService = null)
    {
        $this->lemmaService = $lemmaService;
    }

    /**
     * Handle a GET request for word families.
     *
     * Routes:
     * - GET /word-families/stats?language_id=N  -> lemma statistics
     * - GET /word-families?language_id=N&lemma_lc=X -> family by lemma
     * - GET /word-families?language_id=N         -> paginated list
     *
     * @param list<string>         $fragments URL path segments
     * @param array<string, mixed> $params    Query parameters
     *
     * @return JsonResponse
     */
    public function routeGet(array $fragments, array $params): JsonResponse
    {
        $frag1 = $this->frag($fragments, 1);

        if ($frag1 === 'stats') {
            $langId = (int) ($params['language_id'] ?? 0);
            if ($langId <= 0) {
                return Response::error('language_id is required', 400);
            }
            return Response::success($this->getLemmaStatistics($langId));
        }

        $langId = (int) ($params['language_id'] ?? 0);
        if ($langId <= 0) {
            return Response::error('language_id is required', 400);
        }

        $lemmaLc = (string) ($params['lemma_lc'] ?? '');
        if ($lemmaLc !== '') {
            return Response::success($this->getWordFamilyByLemma($langId, $lemmaLc));
        }

        return Response::success($this->getWordFamilyListFromParams($langId, $params));
    }

    /**
     * Get word family for a term.
     *
     * Returns all words sharing the same lemma with statistics.
     *
     * @param int $termId Term ID
     *
     * @return array Word family data or error
     */
    public function getTermFamily(int $termId): array
    {
        $family = $this->getLemmaService()->getWordFamilyDetails($termId);

        if ($family === null) {
            return ['error' => 'Term not found'];
        }

        return $family;
    }

    /**
     * Get word family by lemma.
     *
     * @param int    $langId  Language ID
     * @param string $lemmaLc Lowercase lemma
     *
     * @return array Word family data or error
     */
    public function getWordFamilyByLemma(int $langId, string $lemmaLc): array
    {
        $family = $this->getLemmaService()->getWordFamilyByLemma($langId, $lemmaLc);

        if ($family === null) {
            return ['error' => 'Word family not found'];
        }

        return $family;
    }

    /**
     * Get paginated list of word families for a language.
     *
     * @param int    $langId  Language ID
     * @param int    $page    Page number
     * @param int    $perPage Items per page
     * @param string $sortBy  Sort field
     * @param string $sortDir Sort direction
     *
     * @return array{families: array, pagination: array}
     */
    public function getWordFamilyList(
        int $langId,
        int $page = 1,
        int $perPage = 50,
        string $sortBy = 'lemma',
        string $sortDir = 'asc'
    ): array {
        return $this->getLemmaService()->getWordFamilyList($langId, $page, $perPage, $sortBy, $sortDir);
    }

    /**
     * Get paginated list of word families from query parameters.
     *
     * @param int   $langId Language ID
     * @param array $params Query parameters (page, per_page, sort_by, sort_dir)
     *
     * @return array{families: array, pagination: array}
     */
    public function getWordFamilyListFromParams(int $langId, array $params = []): array
    {
        $page = max(1, (int) ($params['page'] ?? 1));
        $perPage = max(1, min(100, (int) ($params['per_page'] ?? 50)));
        $sortBy = (string) ($params['sort_by'] ?? 'lemma');
        $sortDir = (string) ($params['sort_dir'] ?? 'asc');

        return $this->getWordFamilyList($langId, $page, $perPage, $sortBy, $sortDir);
    }

    /**
     * Update status for all words in a word family.
     *
     * @param int    $langId  Language ID
     * @param string $lemmaLc Lowercase lemma
     * @param int    $status  New status
     *
     * @return array{success: bool, count?: int, error?: string}
     */
    public function updateWordFamilyStatus(int $langId, string $lemmaLc, int $status): array
    {
        if (!TermStatus::isValid($status)) {
            return ['success' => false, 'error' => 'Invalid status'];
        }

        $count = $this->getLemmaService()->updateWordFamilyStatus($langId, $lemmaLc, $status);

        return ['success' => true, 'count' => $count];
    }

    /**
     * Get suggestion for updating related word family members after status change.
     *
     * @param int $termId    Term that was updated
     * @param int $newStatus New status that was set
     *
     * @return array{suggestion: string, affected_count: int, term_ids: int[]}
     */
    public function getFamilyUpdateSuggestion(int $termId, int $newStatus): array
    {
        return $this->getLemmaService()->getSuggestedFamilyUpdate($termId, $newStatus);
    }

    /**
     * Apply suggested family update (bulk status change).
     *
     * @param int[] $termIds Term IDs to update
     * @param int   $status  New status
     *
     * @return array{success: bool, count: int}
     */
    public function applyFamilyUpdate(array $termIds, int $status): array
    {
        if (!TermStatus::isValid($status)) {
            return ['success' => false, 'count' => 0];
        }

        $count = $this->getLemmaService()->bulkUpdateTermStatus($termIds, $status);

        return ['success' => true, 'count' => $count];
    }

    /**
     * Get lemma statistics for a language.
     *
     * @param int $langId Language ID
     *
     * @return array Statistics data
     */
    public function getLemmaStatistics(int $langId): array
    {
        $lemmaService = $this->getLemmaService();

        return [
            'basic' => $lemmaService->getLemmaStatistics($langId),
            'aggregate' => $lemmaService->getLemmaAggregateStats($langId),
        ];
    }

    /**
     * Get the LemmaService instance.
     *
     * @return LemmaService
     */
    private function getLemmaService(): LemmaService
    {
        if ($this->lemmaService === null) {
            $this->lemmaService = new LemmaService();
        }
        return $this->lemmaService;
    }
}
