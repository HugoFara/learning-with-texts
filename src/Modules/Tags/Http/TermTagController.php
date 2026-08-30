<?php

/**
 * Term Tag Controller
 *
 * Controller for managing term tags in the Tags module.
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

use Lwt\Shared\Http\AbstractCrudController;
use Lwt\Shared\Infrastructure\Http\InputValidator;
use Lwt\Shared\Infrastructure\Database\Maintenance;
use Lwt\Shared\Infrastructure\Database\QueryBuilder;
use Lwt\Modules\Tags\Application\TagsFacade;
use Lwt\Modules\Tags\Domain\TagType;

/**
 * Controller for managing term tags (tags applied to vocabulary words).
 *
 * @since 3.0.0
 */
class TermTagController extends AbstractCrudController
{
    protected string $pageTitle = 'Term Tags';
    protected string $resourceName = 'tag';

    private TagsFacade $facade;

    /** @var string */
    private string $currentQuery = '';

    /** @var int */
    private int $currentSort = 1;

    /** @var int */
    private int $currentPage = 1;

    /**
     * Constructor.
     *
     * @param TagsFacade|null $facade Tags facade
     */
    public function __construct(?TagsFacade $facade = null)
    {
        parent::__construct();
        $this->facade = $facade ?? TagsFacade::forTermTags();
    }

    /**
     * Show new term tag form.
     *
     * Route: GET /tags/new
     *
     * @param array $params Route parameters
     *
     * @return void
     */
    public function new(array $params): void
    {
        // Creation goes through POST /api/v1/tags/{type}; this route only
        // renders the scaffold the form component binds to.
        $this->render($this->pageTitle, $this->showMenu);
        $this->renderCreateForm();
        $this->endRender();
    }

    /**
     * Edit term tag form.
     *
     * Route: GET/POST /tags/{id}/edit
     *
     * @param int $id Tag ID from route parameter
     *
     * @return void
     */
    public function edit(int $id): void
    {
        // Saving goes through PUT /api/v1/tags/{type}/{id}; this route only
        // renders the scaffold the form component binds to.
        $this->render($this->pageTitle, $this->showMenu);
        $this->renderEditForm($id);
        $this->endRender();
    }

    /**
     * Delete a term tag.
     *
     * Route: DELETE /tags/{id}
     *
     * @param int $id Tag ID from route parameter
     *
     * @return void
     */
    public function delete(int $id): void
    {
        $result = $this->facade->delete($id);

        if ($result['success']) {
            header('Location: ' . url('/tags') . '?message=' . urlencode(__('tags.flash.deleted')));
        } else {
            header('Location: ' . url('/tags') . '?error=' . urlencode(__('tags.flash.delete_failed')));
        }
        exit;
    }

    /**
     * Main index action.
     *
     * @param array $params Route parameters
     *
     * @return void
     */
    public function index(array $params): void
    {
        // Load filter/sort/page settings from URL params (sort persists to DB)
        $this->currentSort = InputValidator::getIntWithDb("sort", 'currenttagsort', 1);
        $this->currentPage = InputValidator::getIntParam("page", 1, 1);
        $this->currentQuery = InputValidator::getString("query");

        parent::index($params);
    }

    /**
     * Get the ID parameter name.
     *
     * @return string
     */
    protected function getIdParameterName(): string
    {
        return 'TgID';
    }

    /**
     * Handle create operation.
     *
     * @return string Result message
     */
    protected function handleCreate(): string
    {
        $text = $this->param('TgText', '');
        $comment = $this->param('TgComment', '');

        $result = $this->facade->create($text, $comment);
        return $result['success'] ? "Saved" : "Error: " . ($result['error'] ?? 'Unknown error');
    }

    /**
     * Handle update operation.
     *
     * @param int $id Tag ID
     *
     * @return string Result message
     */
    protected function handleUpdate(int $id): string
    {
        $text = $this->param('TgText', '');
        $comment = $this->param('TgComment', '');

        $result = $this->facade->update($id, $text, $comment);
        return $result['success'] ? "Updated" : "Error: " . ($result['error'] ?? 'Unknown error');
    }

    /**
     * Handle delete operation.
     *
     * @param int $id Tag ID
     *
     * @return string Result message
     */
    protected function handleDelete(int $id): string
    {
        $result = $this->facade->delete($id);
        return $result['success'] ? "Deleted" : "Deleted (0 rows affected)";
    }

    /**
     * Handle bulk action.
     *
     * @param string $action Action code
     * @param int[]  $ids    Tag IDs
     *
     * @return array{action: string, count: int, error?: string, success: bool}
     */
    protected function handleBulkAction(string $action, array $ids): array
    {
        if ($action === 'del') {
            $result = $this->facade->deleteMultiple($ids);
            $this->facade->cleanupOrphanedLinks();
            return [
                'success' => true,
                'count' => $result['count'],
                'action' => 'del'
            ];
        }

        return parent::handleBulkAction($action, $ids);
    }

    /**
     * Process action on all filtered items.
     *
     * @param string $action Action code
     *
     * @return array{action: string, count: int, error?: string, success: bool}
     */
    protected function processAllAction(string $action): array
    {
        if ($action === 'delall') {
            $result = $this->facade->deleteAll($this->currentQuery);
            return [
                'success' => true,
                'count' => $result['count'],
                'action' => 'delall'
            ];
        }

        return parent::processAllAction($action);
    }

    /**
     * Render the list view.
     *
     * @param string $message Message to display
     *
     * @psalm-suppress UnusedVariable Variables are used by included view
     *
     * @return void
     */
    protected function renderList(string $message): void
    {
        $this->message($message, false);

        TagsFacade::getAllTermTags(true); // Refresh cache

        // Rows, counts and pagination all come from
        // GET /api/v1/tags/{type}/list; only the initial filter and sort
        // travel with the page so a bookmarked URL still opens on them.
        $currentQuery = $this->currentQuery;
        $currentSort = $this->currentSort;
        $isTextTag = false;
        $service = $this->facade; // Backward compatible variable name

        include __DIR__ . '/../Views/tag_list.php';
    }

    /**
     * Render the create form.
     *
     * @psalm-suppress UnusedVariable Variables are used by included view
     *
     * @return void
     */
    protected function renderCreateForm(): void
    {
        $mode = 'new';
        $tag = null;
        $service = $this->facade;
        $formFieldPrefix = 'Tg';

        include __DIR__ . '/../Views/tag_form.php';
    }

    /**
     * Render the edit form.
     *
     * @param int $id Tag ID
     *
     * @psalm-suppress UnusedVariable Variables are used by included view
     *
     * @return void
     */
    protected function renderEditForm(int $id): void
    {
        $tag = $this->facade->getById($id);

        if ($tag === null) {
            $this->message(__('tags.flash.not_found'), false);
            return;
        }

        $mode = 'edit';
        $service = $this->facade;
        $formFieldPrefix = 'Tg';

        include __DIR__ . '/../Views/tag_form.php';
    }
}
