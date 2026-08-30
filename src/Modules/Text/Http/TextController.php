<?php

/**
 * Text Controller (Facade)
 *
 * Thin facade delegating to TextReadController, TextCrudController,
 * and ArchivedTextController. Maintained for backward compatibility
 * with existing route registrations.
 *
 * PHP version 8.2
 *
 * @category Lwt
 * @package  Lwt\Modules\Text\Http
 * @author   HugoFara <git@hugofara.net>
 * @license  Unlicense <http://unlicense.org/>
 * @link     https://hugofara.github.io/lwt/developer/api
 * @since    3.0.0
 */

declare(strict_types=1);

namespace Lwt\Modules\Text\Http;

use Lwt\Shared\Http\BaseController;
use Lwt\Modules\Text\Application\TextFacade;
use Lwt\Modules\Text\Application\Services\TextDisplayService;
use Lwt\Modules\Language\Application\LanguageFacade;
use Lwt\Shared\Infrastructure\Http\RedirectResponse;

/**
 * Facade controller delegating to specialized sub-controllers.
 *
 * @since 3.0.0
 */
class TextController extends BaseController
{
    private TextReadController $readController;
    private TextCrudController $crudController;
    private ArchivedTextController $archivedController;

    public function __construct(
        ?TextFacade $textService = null,
        ?LanguageFacade $languageService = null,
        ?TextDisplayService $displayService = null
    ) {
        parent::__construct();
        $textService = $textService ?? new TextFacade();
        $languageService = $languageService ?? new LanguageFacade();
        $displayService = $displayService ?? new TextDisplayService();

        $this->readController = new TextReadController($textService, $displayService);
        $this->crudController = new TextCrudController($textService, $languageService);
        $this->archivedController = new ArchivedTextController($textService, $languageService);
    }

    // =========================================================================
    // Read/Display Delegation
    // =========================================================================

    /** @psalm-suppress UnusedVariable */
    public function read(?int $text = null): ?RedirectResponse
    {
        return $this->readController->read($text);
    }

    /** @psalm-suppress UnusedVariable */
    public function display(?int $text = null): ?RedirectResponse
    {
        return $this->readController->display($text);
    }

    public function check(array $params): void
    {
        $this->readController->check($params);
    }

    // =========================================================================
    // CRUD Delegation
    // =========================================================================

    /** @psalm-suppress UnusedVariable */
    public function new(array $params): ?RedirectResponse
    {
        return $this->crudController->new($params);
    }

    public function editSingle(int $id): ?RedirectResponse
    {
        return $this->crudController->editSingle($id);
    }

    public function delete(int $id): RedirectResponse
    {
        return $this->crudController->delete($id);
    }

    public function archive(int $id): RedirectResponse
    {
        return $this->crudController->archive($id);
    }

    public function unarchive(int $id): RedirectResponse
    {
        return $this->crudController->unarchive($id);
    }

    /** @psalm-suppress UnusedVariable */
    public function edit(array $params): ?RedirectResponse
    {
        return $this->crudController->edit($params);
    }

    // =========================================================================
    // Archived Text Delegation
    // =========================================================================

    /** @psalm-suppress UnusedVariable */
    public function archived(array $params): ?RedirectResponse
    {
        return $this->archivedController->archived($params);
    }

    public function archivedEdit(int $id): ?RedirectResponse
    {
        return $this->archivedController->archivedEdit($id);
    }

    public function deleteArchived(int $id): RedirectResponse
    {
        return $this->archivedController->deleteArchived($id);
    }
}
