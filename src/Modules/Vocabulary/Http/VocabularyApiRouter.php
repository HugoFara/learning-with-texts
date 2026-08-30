<?php

/**
 * Vocabulary API Router
 *
 * Dispatches /terms/* API requests to the appropriate vocabulary handler.
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
use Lwt\Shared\Http\ApiRoutableInterface;
use Lwt\Shared\Http\ApiRoutableTrait;
use Lwt\Shared\Infrastructure\Http\JsonResponse;
use Lwt\Modules\Text\Application\TextFacade;

/**
 * Routes /terms/* API requests to the appropriate vocabulary handler.
 *
 * This class coordinates 6 vocabulary handlers:
 * - TermCrudApiHandler: basic CRUD, term details, quick create, full create/update
 * - WordFamilyApiHandler: word family operations
 * - MultiWordApiHandler: multi-word expression operations
 * - WordListApiHandler: paginated list, filtering, bulk actions, inline edit
 * - TermTranslationApiHandler: translation operations
 * - TermStatusApiHandler: status changes (increment, set, bulk)
 *
 * @since 3.0.0
 */
class VocabularyApiRouter implements ApiRoutableInterface
{
    use ApiRoutableTrait;

    private TermCrudApiHandler $termHandler;
    private WordFamilyApiHandler $wordFamilyHandler;
    private MultiWordApiHandler $multiWordHandler;
    private WordListApiHandler $wordListHandler;
    private TermTranslationApiHandler $termTranslationHandler;
    private TermStatusApiHandler $termStatusHandler;
    private TextFacade $textFacade;

    public function __construct(
        TermCrudApiHandler $termHandler,
        WordFamilyApiHandler $wordFamilyHandler,
        MultiWordApiHandler $multiWordHandler,
        WordListApiHandler $wordListHandler,
        TermTranslationApiHandler $termTranslationHandler,
        TermStatusApiHandler $termStatusHandler,
        TextFacade $textFacade
    ) {
        $this->termHandler = $termHandler;
        $this->wordFamilyHandler = $wordFamilyHandler;
        $this->multiWordHandler = $multiWordHandler;
        $this->wordListHandler = $wordListHandler;
        $this->termTranslationHandler = $termTranslationHandler;
        $this->termStatusHandler = $termStatusHandler;
        $this->textFacade = $textFacade;
    }

    public function routeGet(array $fragments, array $params): JsonResponse
    {
        $frag1 = $this->frag($fragments, 1);
        $frag2 = $this->frag($fragments, 2);

        if ($frag1 === 'list') {
            return Response::success($this->wordListHandler->getWordList($params));
        } elseif ($frag1 === 'filter-options') {
            $langId = isset($params['language_id']) && $params['language_id'] !== ''
                ? (int) $params['language_id']
                : null;
            return Response::success($this->wordListHandler->getFilterOptions($langId));
        } elseif ($frag1 === 'imported') {
            return Response::success($this->wordListHandler->importedTermsList(
                (string) ($params["last_update"] ?? ''),
                (int) ($params["page"] ?? 0),
                (int) ($params["count"] ?? 0)
            ));
        } elseif ($frag1 === 'unknown-for-translate') {
            $textId = (int) ($params['text_id'] ?? 0);
            if ($textId <= 0) {
                return Response::error('text_id is required', 400);
            }
            return Response::success($this->termHandler->getUnknownWordsForTranslate(
                $textId,
                (int) ($params['offset'] ?? 0),
                (int) ($params['limit'] ?? 100)
            ));
        } elseif ($frag1 === 'for-edit') {
            return Response::success($this->termHandler->formatGetTermForEdit(
                (int) ($params['term_id'] ?? 0),
                (int) ($params['ord'] ?? 0),
                isset($params['wid']) && $params['wid'] !== '' ? (int) $params['wid'] : null
            ));
        } elseif ($frag1 === 'multi') {
            return Response::success($this->multiWordHandler->getMultiWordForEdit(
                (int) ($params['term_id'] ?? 0),
                (int) ($params['ord'] ?? 0),
                isset($params['txt']) ? (string) $params['txt'] : null,
                isset($params['wid']) ? (int) $params['wid'] : null
            ));
        } elseif ($frag1 === 'family') {
            if ($frag2 === 'suggestion') {
                $termId = (int) ($params['term_id'] ?? 0);
                $newStatus = (int) ($params['status'] ?? 0);
                return Response::success($this->wordFamilyHandler->getFamilyUpdateSuggestion($termId, $newStatus));
            }
            $termId = (int) ($params['term_id'] ?? 0);
            if ($termId <= 0) {
                return Response::error('term_id is required', 400);
            }
            return Response::success($this->wordFamilyHandler->getTermFamily($termId));
        } elseif ($frag1 !== '' && ctype_digit($frag1)) {
            $termId = (int) $frag1;
            if ($frag2 === 'translations') {
                return Response::success($this->textFacade->getTermTranslations(
                    (string) ($params["term_lc"] ?? ''),
                    (int) ($params["text_id"] ?? 0)
                ));
            } elseif ($frag2 === 'details') {
                $ann = isset($params['ann']) ? (string) $params['ann'] : null;
                return Response::success($this->termHandler->formatGetTermDetails($termId, $ann));
            } elseif ($frag2 === 'family') {
                return Response::success($this->wordFamilyHandler->getTermFamily($termId));
            } elseif ($frag2 === '') {
                return Response::success($this->termHandler->formatGetTerm($termId));
            }
            return Response::error('Expected "translations", "details", "family", or no sub-path', 404);
        }

        return Response::error('Endpoint Not Found: ' . $frag1, 404);
    }

    public function routePost(array $fragments, array $params): JsonResponse
    {
        $frag1 = $this->frag($fragments, 1);
        $frag2 = $this->frag($fragments, 2);

        if ($frag1 !== '' && ctype_digit($frag1)) {
            $termId = (int) $frag1;

            if ($frag2 === 'status') {
                return $this->routeTermStatusPost($fragments, $termId);
            } elseif ($frag2 === 'translations') {
                return Response::success($this->termTranslationHandler->formatUpdateTranslation(
                    $termId,
                    (string) ($params['translation'] ?? '')
                ));
            }
            return Response::error('"status" or "translations" Expected', 404);
        } elseif ($frag1 === 'new') {
            return Response::success($this->termTranslationHandler->formatAddTranslation(
                (string) ($params['term_text'] ?? ''),
                (int) ($params['language_id'] ?? 0),
                (string) ($params['translation'] ?? '')
            ));
        } elseif ($frag1 === 'quick') {
            return Response::success($this->termHandler->formatQuickCreate(
                (int) ($params['text_id'] ?? 0),
                (int) ($params['position'] ?? 0),
                (int) ($params['status'] ?? 0)
            ));
        } elseif ($frag1 === 'for-language') {
            return Response::success($this->termHandler->createTermForLanguage($params));
        } elseif ($frag1 === 'full') {
            return Response::success($this->termHandler->formatCreateTermFull($params));
        } elseif ($frag1 === 'bulk') {
            return Response::success($this->termHandler->createTermsBulk($params));
        } elseif ($frag1 === 'multi') {
            return Response::success($this->multiWordHandler->createMultiWordTerm($params));
        }

        return Response::error(
            'Term ID (Integer), "new", "quick", "full", "for-language", "bulk", or "multi" Expected',
            404
        );
    }

    public function routePut(array $fragments, array $params): JsonResponse
    {
        $frag1 = $this->frag($fragments, 1);
        $frag2 = $this->frag($fragments, 2);

        if ($frag1 === 'bulk-status') {
            /** @var array<int> $termIds */
            $termIds = is_array($params['term_ids'] ?? null) ? $params['term_ids'] : [];
            $status = (int) ($params['status'] ?? 0);
            return Response::success($this->termStatusHandler->formatBulkStatus($termIds, $status));
        } elseif ($frag1 === 'bulk-action') {
            /** @var array<int> $ids */
            $ids = is_array($params['ids'] ?? null) ? $params['ids'] : [];
            $action = (string) ($params['action'] ?? '');
            $data = isset($params['data']) ? (string) $params['data'] : null;
            return Response::success($this->wordListHandler->bulkAction($ids, $action, $data));
        } elseif ($frag1 === 'all-action') {
            /** @var array<string, mixed> $filters */
            $filters = is_array($params['filters'] ?? null) ? $params['filters'] : [];
            $action = (string) ($params['action'] ?? '');
            $data = isset($params['data']) ? (string) $params['data'] : null;
            return Response::success($this->wordListHandler->allAction($filters, $action, $data));
        } elseif ($frag1 === 'family') {
            if ($frag2 === 'status') {
                $langId = (int) ($params['language_id'] ?? 0);
                $lemmaLc = (string) ($params['lemma_lc'] ?? '');
                $status = (int) ($params['status'] ?? 0);
                if ($langId <= 0 || $lemmaLc === '') {
                    return Response::error('language_id and lemma_lc are required', 400);
                }
                return Response::success($this->wordFamilyHandler->updateWordFamilyStatus($langId, $lemmaLc, $status));
            } elseif ($frag2 === 'apply') {
                /** @var array<int> $termIds */
                $termIds = is_array($params['term_ids'] ?? null) ? $params['term_ids'] : [];
                $status = (int) ($params['status'] ?? 0);
                if (empty($termIds)) {
                    return Response::error('term_ids is required', 400);
                }
                return Response::success($this->wordFamilyHandler->applyFamilyUpdate($termIds, $status));
            }
            return Response::error('Expected "status" or "apply"', 404);
        } elseif ($frag1 !== '' && ctype_digit($frag1) && $frag2 === 'inline-edit') {
            $termId = (int) $frag1;
            $field = (string) ($params['field'] ?? '');
            $value = (string) ($params['value'] ?? '');
            return Response::success($this->wordListHandler->inlineEdit($termId, $field, $value));
        } elseif ($frag1 === 'multi' && $frag2 !== '' && ctype_digit($frag2)) {
            $termId = (int) $frag2;
            return Response::success($this->multiWordHandler->updateMultiWordTerm($termId, $params));
        } elseif ($frag1 !== '' && ctype_digit($frag1)) {
            $termId = (int) $frag1;
            if ($frag2 === 'translation') {
                return Response::success($this->termTranslationHandler->formatUpdateTranslation(
                    $termId,
                    (string) ($params['translation'] ?? '')
                ));
            } elseif ($frag2 === '') {
                return Response::success($this->termHandler->formatUpdateTermFull($termId, $params));
            }
            return Response::error('Expected "translation" or no sub-path', 404);
        }

        return Response::error('Term ID (Integer), "bulk-status", "family", or "multi/{id}" Expected', 404);
    }

    public function routeDelete(array $fragments, array $params): JsonResponse
    {
        $frag1 = $this->frag($fragments, 1);

        if ($frag1 === '' || !ctype_digit($frag1)) {
            return Response::error('Term ID (Integer) Expected', 404);
        }

        $termId = (int) $frag1;
        return Response::success($this->termHandler->formatDeleteTerm($termId));
    }

    /**
     * Route POST /terms/{id}/status/* requests.
     *
     * @param list<string> $fragments
     * @param int          $termId
     */
    private function routeTermStatusPost(array $fragments, int $termId): JsonResponse
    {
        $frag3 = $this->frag($fragments, 3);

        switch ($frag3) {
            case 'down':
                return Response::success($this->termStatusHandler->formatIncrementStatus($termId, false));
            case 'up':
                return Response::success($this->termStatusHandler->formatIncrementStatus($termId, true));
            default:
                if ($frag3 !== '' && ctype_digit($frag3)) {
                    return Response::success($this->termStatusHandler->formatSetStatus($termId, (int) $frag3));
                }
                return Response::error('Endpoint Not Found: ' . $frag3, 404);
        }
    }
}
