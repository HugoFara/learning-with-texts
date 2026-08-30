<?php

/**
 * \file
 * \brief Abstract CRUD Controller for standardized resource management
 *
 * PHP version 8.2
 *
 * @category Lwt
 * @package  Lwt\Shared\Http
 * @author   HugoFara <git@hugofara.net>
 * @license  Unlicense <http://unlicense.org/>
 * @link     https://hugofara.github.io/lwt/developer/api
 * @since    3.0.0
 */

declare(strict_types=1);

namespace Lwt\Shared\Http;

use Lwt\Shared\Infrastructure\Http\InputValidator;
use Lwt\Shared\UI\Helpers\PageLayoutHelper;

/**
 * Abstract base controller providing standardized CRUD operations.
 *
 * Extends BaseController with common patterns for Create, Read, Update, Delete
 * operations. Controllers managing simple resources can extend this class to
 * reduce boilerplate code.
 *
 * ## Request Parameter Conventions
 *
 * This controller expects these standard request parameters:
 * - `op=Save` - Create a new record (from form submission)
 * - `op=Change` - Update an existing record (from form submission)
 * - `marked[]` - Array of IDs for bulk operations
 * - `markaction` - Bulk action to perform on marked items
 * - `allaction` - Action to perform on all filtered items
 *
 * Note: Edit and delete operations use RESTful routes:
 * - GET /{resource}/{id}/edit - Edit form
 * - DELETE /{resource}/{id} - Delete record
 *
 * Note: Create forms should use dedicated /new routes (e.g., /tags/new)
 *
 * ## Usage
 *
 * ```php
 * class MyResourceController extends AbstractCrudController
 * {
 *     protected string $pageTitle = 'My Resources';
 *     protected string $resourceName = 'resource';
 *
 *     protected function handleCreate(): string { ... }
 *     protected function handleUpdate(int $id): string { ... }
 *     protected function handleDelete(int $id): string { ... }
 *     protected function renderList(string $message): void { ... }
 *     protected function renderCreateForm(): void { ... }
 *     protected function renderEditForm(int $id): void { ... }
 * }
 * ```
 *
 * @category Lwt
 * @package  Lwt\Shared\Http
 * @author   HugoFara <git@hugofara.net>
 * @license  Unlicense <http://unlicense.org/>
 * @link     https://hugofara.github.io/lwt/developer/api
 * @since    3.0.0
 */
abstract class AbstractCrudController extends BaseController
{
    /**
     * Page title for the resource index page.
     *
     * @var string
     */
    protected string $pageTitle = 'Resources';

    /**
     * Singular name of the resource (for messages).
     *
     * @var string
     */
    protected string $resourceName = 'item';

    /**
     * Whether to show the navigation menu.
     *
     * @var bool
     */
    protected bool $showMenu = true;

    /**
     * Main index action - dispatches to appropriate CRUD operation.
     *
     * This method handles the standard CRUD flow:
     * 1. Process any pending actions (delete, save, update, bulk)
     * 2. Display the appropriate view (list, create form, or edit form)
     *
     * Override this method if you need custom routing logic.
     *
     * @param array $params Route parameters
     *
     * @return void
     */
    public function index(array $params): void
    {
        $this->render($this->pageTitle, $this->showMenu);

        // Process actions and get result message
        $message = $this->processActions();

        // Display appropriate view based on request
        $this->dispatchView($message);

        $this->endRender();
    }

    /**
     * Process CRUD actions from request parameters.
     *
     * Handles the standard action flow:
     * 1. Bulk actions (markaction, allaction)
     * 2. Create/Update (op parameter)
     *
     * Note: Single delete is handled via RESTful DELETE routes.
     *
     * @return string Result message from the action
     */
    protected function processActions(): string
    {
        $message = '';

        // Bulk actions on marked items
        $markAction = $this->param('markaction');
        if ($markAction !== '') {
            $result = $this->processBulkAction($markAction);
            return $this->formatBulkActionMessage($result);
        }

        // Actions on all filtered items
        $allAction = $this->param('allaction');
        if ($allAction !== '') {
            $result = $this->processAllAction($allAction);
            return $this->formatBulkActionMessage($result);
        }

        // Create or Update
        $op = $this->param('op');
        if ($op === 'Save') {
            $message = $this->handleCreate();
        } elseif ($op === 'Change') {
            $id = $this->paramInt($this->getIdParameterName(), 0) ?? 0;
            $message = $this->handleUpdate($id);
        }

        return $message;
    }

    /**
     * Format bulk action result into a display message.
     *
     * @param array{success: bool, count: int, action: string, error?: string} $result Bulk action result
     *
     * @return string Formatted message for display
     */
    protected function formatBulkActionMessage(array $result): string
    {
        if (!$result['success'] && isset($result['error'])) {
            return match ($result['error']) {
                'no_items_selected' => 'No items selected',
                'no_valid_items' => 'No valid items selected',
                'unknown_action' => "Unknown action: {$result['action']}",
                'not_implemented' => "Action '{$result['action']}' not implemented",
                default => "Error: {$result['error']}"
            };
        }

        return match ($result['action']) {
            'del' => "Deleted: {$result['count']}",
            default => "Action '{$result['action']}' completed: {$result['count']} items"
        };
    }

    /**
     * Dispatch to the appropriate view based on request parameters.
     *
     * Note: Edit forms are now handled via RESTful /{resource}/{id}/edit routes.
     *
     * @param string $message Message from processed action
     *
     * @return void
     */
    protected function dispatchView(string $message): void
    {
        $this->renderList($message);
    }

    /**
     * Process bulk action on marked items.
     *
     * Override this method to handle bulk operations like bulk delete,
     * bulk status change, etc.
     *
     * @param string $action The action code (e.g., 'del', 'export')
     *
     * @return array{success: bool, count: int, action: string, error?: string} Result data
     */
    protected function processBulkAction(string $action): array
    {
        $marked = InputValidator::getArray('marked');
        if (empty($marked)) {
            return ['success' => false, 'count' => 0, 'action' => $action, 'error' => 'no_items_selected'];
        }

        $ids = $this->getMarkedIds($marked);
        if (empty($ids)) {
            return ['success' => false, 'count' => 0, 'action' => $action, 'error' => 'no_valid_items'];
        }

        return $this->handleBulkAction($action, $ids);
    }

    /**
     * Handle a bulk action on the given IDs.
     *
     * Override this method to implement bulk operations.
     *
     * @param string $action The action code
     * @param int[]  $ids    Array of record IDs
     *
     * @return array{success: bool, count: int, action: string, error?: string} Result data
     */
    protected function handleBulkAction(string $action, array $ids): array
    {
        if ($action === 'del') {
            return $this->handleBulkDelete($ids);
        }

        return ['success' => false, 'count' => 0, 'action' => $action, 'error' => 'unknown_action'];
    }

    /**
     * Handle bulk delete operation.
     *
     * Override this method to implement bulk delete with proper cleanup.
     *
     * @param int[] $ids Array of record IDs to delete
     *
     * @return array{success: bool, count: int, action: string} Result data
     */
    protected function handleBulkDelete(array $ids): array
    {
        $count = 0;
        foreach ($ids as $id) {
            $result = $this->handleDelete($id);
            if (!str_starts_with($result, 'Error')) {
                $count++;
            }
        }
        return ['success' => true, 'count' => $count, 'action' => 'del'];
    }

    /**
     * Process action on all filtered items.
     *
     * Override this method to handle actions like "delete all matching filter".
     *
     * @param string $action The action code (e.g., 'delall')
     *
     * @return array{success: bool, count: int, action: string, error?: string} Result data
     */
    protected function processAllAction(string $action): array
    {
        return ['success' => false, 'count' => 0, 'action' => $action, 'error' => 'not_implemented'];
    }

    /**
     * Get the name of the ID parameter for update operations.
     *
     * Override this if your ID parameter has a different name.
     * Default is based on resource name (e.g., 'resourceId').
     *
     * @return string Parameter name
     */
    protected function getIdParameterName(): string
    {
        return $this->resourceName . 'Id';
    }

    // ==================== ABSTRACT METHODS ====================
    // Subclasses must implement these core CRUD operations

    /**
     * Handle create operation.
     *
     * Called when `op=Save` is submitted. Read form data from request
     * and create the new record.
     *
     * @return string Result message (e.g., "Created successfully")
     */
    abstract protected function handleCreate(): string;

    /**
     * Handle update operation.
     *
     * Called when `op=Change` is submitted. Read form data from request
     * and update the existing record.
     *
     * @param int $id Record ID to update
     *
     * @return string Result message (e.g., "Updated successfully")
     */
    abstract protected function handleUpdate(int $id): string;

    /**
     * Handle delete operation.
     *
     * Called from RESTful DELETE routes and bulk delete operations.
     *
     * @param int $id Record ID to delete
     *
     * @return string Result message (e.g., "Deleted")
     */
    abstract protected function handleDelete(int $id): string;

    /**
     * Render the list view.
     *
     * Called when no create/edit parameters are present.
     *
     * @param string $message Optional message to display (from previous action)
     *
     * @return void
     */
    abstract protected function renderList(string $message): void;

    /**
     * Render the create form.
     *
     * Called from RESTful /{resource}/new routes.
     *
     * @return void
     */
    abstract protected function renderCreateForm(): void;

    /**
     * Render the edit form.
     *
     * Called from RESTful /{resource}/{id}/edit routes.
     *
     * @param int $id Record ID to edit
     *
     * @return void
     */
    abstract protected function renderEditForm(int $id): void;

    // ==================== HELPER METHODS ====================

    /**
     * Get current page number from request.
     *
     * @param string $requestKey Request parameter name
     * @param string $sessionKey Unused, kept for BC (was session key)
     * @param int    $default    Default page number
     *
     * @return int Current page number
     */
    protected function getCurrentPage(
        string $requestKey = 'page',
        string $sessionKey = 'currentpage',
        int $default = 1
    ): int {
        return InputValidator::getInt($requestKey, $default, 1) ?? $default;
    }

    /**
     * Get current sort order from request/database.
     *
     * @param string $requestKey Request parameter name
     * @param string $dbKey      Database setting key for persistence
     * @param int    $default    Default sort order
     *
     * @return int Current sort order
     */
    protected function getCurrentSort(
        string $requestKey = 'sort',
        string $dbKey = 'currentsort',
        int $default = 1
    ): int {
        return InputValidator::getIntWithDb($requestKey, $dbKey, $default);
    }

    /**
     * Get current search query from request.
     *
     * @param string $requestKey Request parameter name
     * @param string $sessionKey Unused, kept for BC (was session key)
     * @param string $default    Default query
     *
     * @return string Current search query
     */
    protected function getCurrentQuery(
        string $requestKey = 'query',
        string $sessionKey = 'currentquery',
        string $default = ''
    ): string {
        return InputValidator::getString($requestKey, $default);
    }

    /**
     * Display a success message.
     *
     * @param string $action Action that was performed (e.g., "Created", "Updated")
     *
     * @return string Formatted message
     */
    protected function successMessage(string $action): string
    {
        return ucfirst($this->resourceName) . " $action successfully";
    }

    /**
     * Display an error message.
     *
     * @param string $error Error description
     *
     * @return string Formatted error message
     */
    protected function errorMessage(string $error): string
    {
        return "Error: $error";
    }
}
