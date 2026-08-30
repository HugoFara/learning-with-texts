<?php

/**
 * Book API Handler
 *
 * PHP version 8.2
 *
 * @category Lwt
 * @package  Lwt\Modules\Book\Http
 * @author   HugoFara <git@hugofara.net>
 * @license  Unlicense <http://unlicense.org/>
 * @link     https://hugofara.github.io/lwt/developer/api
 * @since    3.0.0
 */

declare(strict_types=1);

namespace Lwt\Modules\Book\Http;

use Lwt\Api\V1\Response;
use Lwt\Modules\Book\Application\BookFacade;
use Lwt\Shared\Http\ApiRoutableInterface;
use Lwt\Shared\Http\ApiRoutableTrait;
use Lwt\Shared\Infrastructure\Globals;
use Lwt\Shared\Infrastructure\Http\InputValidator;
use Lwt\Shared\Infrastructure\Http\JsonResponse;

/**
 * API handler for book operations.
 *
 * Handles REST API endpoints for book management.
 *
 * @since 3.0.0
 */
class BookApiHandler implements ApiRoutableInterface
{
    use ApiRoutableTrait;

    private BookFacade $bookFacade;

    /**
     * Constructor.
     *
     * @param BookFacade $bookFacade Book facade
     */
    public function __construct(BookFacade $bookFacade)
    {
        $this->bookFacade = $bookFacade;
    }

    /**
     * Handle GET /api/v1/books request.
     *
     * @param array $params Request parameters
     *
     * @return array Response data
     */
    public function listBooks(array $params): array
    {
        $userId = Globals::getCurrentUserId();
        $languageId = isset($params['lg_id']) ? (int) $params['lg_id'] : null;
        $page = isset($params['page']) ? max(1, (int) $params['page']) : 1;
        $perPage = isset($params['per_page']) ? min(100, max(1, (int) $params['per_page'])) : 20;

        $result = $this->bookFacade->getBooks($userId, $languageId, $page, $perPage);

        return [
            'success' => true,
            'data' => $result['books'],
            'pagination' => [
                'total' => $result['total'],
                'page' => $result['page'],
                'per_page' => $result['perPage'],
                'total_pages' => $result['totalPages'],
            ],
        ];
    }

    /**
     * Handle GET /api/v1/books/{id} request.
     *
     * @param array $params Request parameters (id)
     *
     * @return array Response data
     */
    public function getBook(array $params): array
    {
        $bookId = (int) ($params['id'] ?? 0);

        if ($bookId <= 0) {
            return [
                'success' => false,
                'error' => 'Invalid book ID',
            ];
        }

        $result = $this->bookFacade->getBook($bookId);

        if ($result === null) {
            return [
                'success' => false,
                'error' => 'Book not found',
            ];
        }

        return [
            'success' => true,
            'data' => $result,
        ];
    }

    /**
     * Handle GET /api/v1/books/{id}/chapters request.
     *
     * @param array $params Request parameters (id)
     *
     * @return array Response data
     */
    public function getChapters(array $params): array
    {
        $bookId = (int) ($params['id'] ?? 0);

        if ($bookId <= 0) {
            return [
                'success' => false,
                'error' => 'Invalid book ID',
            ];
        }

        $chapters = $this->bookFacade->getChapters($bookId);

        return [
            'success' => true,
            'data' => $chapters,
        ];
    }

    /**
     * Handle POST /api/v1/books request — import an EPUB upload.
     *
     * The upload arrives as multipart/form-data, so the file itself comes from
     * $_FILES rather than the decoded body. Both field-name pairs the old
     * /book/import form used are accepted so either caller can post here.
     *
     * @param array $params Request parameters (LgID or TxLgID, TxTitle, TextTags)
     *
     * @return array Response data
     */
    public function importBook(array $params): array
    {
        $languageId = (int) ($params['LgID'] ?? 0);
        if ($languageId <= 0) {
            $languageId = (int) ($params['TxLgID'] ?? 0);
        }

        if ($languageId <= 0) {
            return [
                'success' => false,
                'error' => __('book.flash.select_language'),
            ];
        }

        $uploadedFile = InputValidator::getUploadedFile('thefile')
            ?? InputValidator::getUploadedFile('importFile');

        if ($uploadedFile === null || ($uploadedFile['tmp_name'] ?? '') === '') {
            return [
                'success' => false,
                'error' => __('book.flash.select_epub'),
            ];
        }

        $overrideTitle = trim((string) ($params['TxTitle'] ?? ''));

        $tagIds = $this->parseTagIds($params['TextTags'] ?? null);

        $result = $this->bookFacade->importEpub(
            $languageId,
            $uploadedFile,
            $overrideTitle !== '' ? $overrideTitle : null,
            $tagIds,
            Globals::getCurrentUserId()
        );

        if (!$result['success']) {
            return [
                'success' => false,
                'error' => $result['message'],
            ];
        }

        return [
            'success' => true,
            'message' => $result['message'],
            'bookId' => $result['bookId'],
        ];
    }

    /**
     * Normalise the posted tag selection into a list of tag IDs.
     *
     * The field arrives in two shapes: a comma-separated string when the form
     * is posted as-is, and an array once Tagify has replaced the input with
     * its own multi-value control. Casting an array to string is a warning
     * that the exception handler turns into a 500, so both are handled here.
     *
     * @param mixed $raw Posted TextTags value
     *
     * @return int[] Tag IDs, without zeroes
     */
    private function parseTagIds(mixed $raw): array
    {
        if ($raw === null || $raw === '') {
            return [];
        }

        $parts = [];
        if (is_array($raw)) {
            /** @var mixed $item */
            foreach ($raw as $item) {
                if (is_scalar($item)) {
                    $parts[] = (string) $item;
                }
            }
        } elseif (is_scalar($raw)) {
            $parts = explode(',', (string) $raw);
        }

        $ids = [];
        foreach ($parts as $part) {
            $id = (int) trim($part);
            if ($id > 0) {
                $ids[] = $id;
            }
        }

        return $ids;
    }

    /**
     * Handle DELETE /api/v1/books/{id} request.
     *
     * @param array $params Request parameters (id)
     *
     * @return array Response data
     */
    public function deleteBook(array $params): array
    {
        $bookId = (int) ($params['id'] ?? 0);

        if ($bookId <= 0) {
            return [
                'success' => false,
                'error' => 'Invalid book ID',
            ];
        }

        $result = $this->bookFacade->deleteBook($bookId);

        return [
            'success' => $result['success'],
            'message' => $result['message'],
        ];
    }

    /**
     * Handle PUT /api/v1/books/{id}/progress request.
     *
     * Update reading progress for a book.
     *
     * @param array $params Request parameters (id, chapter)
     *
     * @return array Response data
     */
    public function updateProgress(array $params): array
    {
        $bookId = (int) ($params['id'] ?? 0);
        $chapterNum = (int) ($params['chapter'] ?? 0);

        if ($bookId <= 0 || $chapterNum <= 0) {
            return [
                'success' => false,
                'error' => 'Invalid book ID or chapter number',
            ];
        }

        $this->bookFacade->updateReadingProgress($bookId, $chapterNum);

        return [
            'success' => true,
            'message' => __('book.flash.progress_updated'),
        ];
    }

    public function routeGet(array $fragments, array $params): JsonResponse
    {
        $frag1 = $this->frag($fragments, 1);
        $frag2 = $this->frag($fragments, 2);

        if ($frag1 !== '' && ctype_digit($frag1) && $frag2 === 'chapters') {
            return Response::success($this->getChapters(['id' => $frag1]));
        }
        if ($frag1 !== '' && ctype_digit($frag1)) {
            return Response::success($this->getBook(['id' => $frag1]));
        }

        return Response::success($this->listBooks($params));
    }

    public function routePost(array $fragments, array $params): JsonResponse
    {
        if ($this->frag($fragments, 1) !== '') {
            return Response::error('No sub-resource accepts POST', 404);
        }

        return Response::success($this->importBook($params));
    }

    public function routePut(array $fragments, array $params): JsonResponse
    {
        $frag1 = $this->frag($fragments, 1);
        $frag2 = $this->frag($fragments, 2);

        if ($frag1 !== '' && ctype_digit($frag1) && $frag2 === 'progress') {
            $params['id'] = $frag1;
            return Response::success($this->updateProgress($params));
        }

        return Response::error('Expected "progress"', 404);
    }

    public function routeDelete(array $fragments, array $params): JsonResponse
    {
        $frag1 = $this->frag($fragments, 1);

        if ($frag1 !== '' && ctype_digit($frag1)) {
            return Response::success($this->deleteBook(['id' => $frag1]));
        }

        return Response::error('Book ID (Integer) Expected', 404);
    }
}
