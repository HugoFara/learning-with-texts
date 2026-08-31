<?php

/**
 * Tag API Handler
 *
 * Handles REST API endpoints for tag operations.
 *
 * PHP version 8.2
 *
 * @category Lwt
 * @package  Lwt\Modules\Tags\Http
 * @author   HugoFara <git@hugofara.net>
 * @license  Unlicense <http://unlicense.org/>
 * @link     https://hugofara.github.io/lwt/developer/api
 * @since    3.0.0
 */

declare(strict_types=1);

namespace Lwt\Modules\Tags\Http;

use Lwt\Api\V1\Response;
use Lwt\Modules\Tags\Application\TagsFacade;
use Lwt\Shared\Http\ApiRoutableInterface;
use Lwt\Shared\Http\ApiRoutableTrait;
use Lwt\Shared\Infrastructure\Http\JsonResponse;

/**
 * API handler for tag endpoints.
 *
 * Handles:
 * - GET    /api/v1/tags                 - Get all tags (both term and text)
 * - GET    /api/v1/tags/{type}          - Flat name list for autocomplete
 * - GET    /api/v1/tags/{type}/list     - Paginated records for the tag pages
 * - GET    /api/v1/tags/{type}/{id}     - One tag
 * - POST   /api/v1/tags/{type}          - Create
 * - PUT    /api/v1/tags/{type}/{id}     - Update
 * - DELETE /api/v1/tags/{type}/{id}     - Delete one
 * - DELETE /api/v1/tags/{type}          - Delete selected, or all matching
 *
 * `{type}` is `term` or `text`. The flat list and the paginated list are
 * deliberately different routes: Tagify and the term editor consume the flat
 * one and would break if it grew an envelope.
 *
 * @since 3.0.0
 */
class TagApiHandler implements ApiRoutableInterface
{
    use ApiRoutableTrait;

    private TagCrudApiHandler $crud;

    /** Tag collections the CRUD routes address. */
    private const TAG_TYPES = ['term', 'text'];

    public function __construct(?TagCrudApiHandler $crud = null)
    {
        $this->crud = $crud ?? new TagCrudApiHandler();
    }

    /**
     * Route a GET request to the appropriate handler.
     *
     * @param list<string>         $fragments URL path fragments
     * @param array<string, mixed> $params    Query parameters
     *
     * @return JsonResponse
     */
    public function routeGet(array $fragments, array $params): JsonResponse
    {
        $type = $this->frag($fragments, 1);
        $rest = $this->frag($fragments, 2);

        if ($rest === 'list') {
            return Response::success($this->crud->list($type, $params));
        }
        if ($rest !== '' && ctype_digit($rest)) {
            return Response::success($this->crud->get($type, (int) $rest));
        }

        return $this->handleGet(array_slice($fragments, 1));
    }

    /**
     * Route a POST request.
     *
     * @param list<string>         $fragments URL path fragments
     * @param array<string, mixed> $params    Request body
     *
     * @return JsonResponse
     */
    public function routePost(array $fragments, array $params): JsonResponse
    {
        $type = $this->frag($fragments, 1);
        if (!in_array($type, self::TAG_TYPES, true)) {
            return Response::error('Expected tag type "term" or "text"', 404);
        }

        return Response::success($this->crud->create($type, $params));
    }

    /**
     * Route a PUT request.
     *
     * @param list<string>         $fragments URL path fragments
     * @param array<string, mixed> $params    Request body
     *
     * @return JsonResponse
     */
    public function routePut(array $fragments, array $params): JsonResponse
    {
        $type = $this->frag($fragments, 1);
        if (!in_array($type, self::TAG_TYPES, true)) {
            return Response::error('Expected tag type "term" or "text"', 404);
        }

        $id = $this->frag($fragments, 2);
        if ($id === '' || !ctype_digit($id)) {
            return Response::error('Tag ID (Integer) Expected', 404);
        }

        return Response::success($this->crud->update($type, (int) $id, $params));
    }

    /**
     * Route a DELETE request.
     *
     * @param list<string>         $fragments URL path fragments
     * @param array<string, mixed> $params    Request body
     *
     * @return JsonResponse
     */
    public function routeDelete(array $fragments, array $params): JsonResponse
    {
        $type = $this->frag($fragments, 1);
        if (!in_array($type, self::TAG_TYPES, true)) {
            return Response::error('Expected tag type "term" or "text"', 404);
        }

        $id = $this->frag($fragments, 2);

        if ($id !== '' && ctype_digit($id)) {
            return Response::success($this->crud->delete($type, (int) $id));
        }

        return Response::success($this->crud->deleteMany($type, $params));
    }

    /**
     * Handle GET request for tags.
     *
     * @param array $fragments URL path fragments after /tags
     *
     * @return JsonResponse
     */
    public function handleGet(array $fragments): JsonResponse
    {
        $type = isset($fragments[0]) ? (string) $fragments[0] : '';

        switch ($type) {
            case 'term':
                return Response::success(TagsFacade::getAllTermTags());
            case 'text':
                return Response::success(TagsFacade::getAllTextTags());
            default:
                // Return both tag types
                return Response::success([
                    'term' => TagsFacade::getAllTermTags(),
                    'text' => TagsFacade::getAllTextTags(),
                ]);
        }
    }

    /**
     * Handle request routing.
     *
     * @param string $method    HTTP method
     * @param array  $fragments URL fragments
     *
     * @return JsonResponse
     */
    public function handle(string $method, array $fragments): JsonResponse
    {
        switch (strtoupper($method)) {
            case 'GET':
                return $this->handleGet($fragments);
            default:
                return Response::error('Method not allowed', 405);
        }
    }
}
