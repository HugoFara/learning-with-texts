<?php

/**
 * Archived Text Controller - Archived text management
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
use Lwt\Modules\Tags\Application\TagsFacade;
use Lwt\Modules\Language\Application\LanguageFacade;
use Lwt\Shared\Infrastructure\Database\Settings;
use Lwt\Shared\UI\Helpers\PageLayoutHelper;
use Lwt\Shared\Infrastructure\Http\RedirectResponse;

/**
 * Controller for archived text management.
 *
 * Handles:
 * - Archived text list with bulk actions
 * - Archived text edit form
 * - Delete/unarchive operations
 *
 * @since 3.0.0
 */
class ArchivedTextController extends BaseController
{
    private const MODULE_VIEWS = __DIR__ . '/../Views';
    private TextFacade $textService;
    private LanguageFacade $languageService;

    public function __construct(
        ?TextFacade $textService = null,
        ?LanguageFacade $languageService = null
    ) {
        parent::__construct();
        $this->textService = $textService ?? new TextFacade();
        $this->languageService = $languageService ?? new LanguageFacade();
    }

    /**
     * Archived texts management.
     *
     * @param array $params Route parameters
     *
     * @return RedirectResponse|null Redirect response or null if rendered
     *
     * @psalm-suppress UnusedVariable Variables are used in included view files
     */
    public function archived(array $params): ?RedirectResponse
    {
        $markAction = $this->param('markaction');
        $noPagestart = ($markAction == 'deltag');
        if (!$noPagestart) {
            PageLayoutHelper::renderPageStart('Archived Texts', true);
        }

        $message = '';

        if ($markAction !== '') {
            $result = $this->handleArchivedMarkAction(
                $markAction,
                $this->paramArray('marked'),
                $this->param('data')
            );
            if ($result instanceof RedirectResponse) {
                return $result;
            }
            $message = $result;
        }

        $delId = $this->paramInt('del');
        $unarchId = $this->paramInt('unarch');
        if ($delId !== null) {
            $message = $this->textService->deleteArchivedText($delId);
        } elseif ($unarchId !== null) {
            $result = $this->textService->unarchiveText($unarchId);
            if ($result['success'] ?? false) {
                $message = "Text unarchived: {$result['sentences']} sentences, {$result['textItems']} text items";
            } else {
                $message = $result['error'] ?? 'Failed to unarchive text';
            }
        }

        $chgId = $this->paramInt('chg');
        if ($chgId !== null) {
            $textId = $chgId;
            $record = $this->textService->getArchivedTextById($textId);
            if ($record !== null) {
                $languages = $this->languageService->getLanguagesForSelect();
                $mediaPathSelectorHtml = (new \Lwt\Modules\Admin\Application\Services\MediaService())
                    ->getMediaPathSelector('TxAudioURI');
                $archivedTextTagsHtml = TagsFacade::getArchivedTextTagsHtml($textId);
                include self::MODULE_VIEWS . '/archived_form.php';
            }
        } else {
            $activeLanguageId = (int) Settings::get('currentlanguage');
            include self::MODULE_VIEWS . '/archived_list.php';
        }

        PageLayoutHelper::renderPageEnd();

        return null;
    }

    /**
     * Edit archived text form.
     *
     * @param int $id Archived text ID from route parameter
     *
     * @return RedirectResponse|null Redirect response or null if rendered
     *
     * @psalm-suppress UnusedVariable Variables are used in included view files
     */
    public function archivedEdit(int $id): ?RedirectResponse
    {
        PageLayoutHelper::renderPageStart('Archived Texts', true);

        $textId = $id;
        $record = $this->textService->getArchivedTextById($textId);
        if ($record !== null) {
            $languages = $this->languageService->getLanguagesForSelect();
            $mediaPathSelectorHtml = (new \Lwt\Modules\Admin\Application\Services\MediaService())
                ->getMediaPathSelector('TxAudioURI');
            $archivedTextTagsHtml = TagsFacade::getArchivedTextTagsHtml($textId);
            include self::MODULE_VIEWS . '/archived_form.php';
        } else {
            echo '<p>Archived text not found.</p>';
        }

        PageLayoutHelper::renderPageEnd();

        return null;
    }

    /**
     * Delete an archived text.
     *
     * @param int $id Archived text ID from route parameter
     *
     * @return RedirectResponse Redirect to archived texts list
     */
    public function deleteArchived(int $id): RedirectResponse
    {
        $this->textService->deleteArchivedText($id);
        return $this->redirect('/text/archived');
    }

    /**
     * Handle mark actions for archived texts.
     *
     * @param string $markAction Action to perform
     * @param array  $marked     Array of marked text IDs
     * @param string $actionData Additional data for the action
     *
     * @return string|RedirectResponse Result message or redirect
     */
    public function handleArchivedMarkAction(
        string $markAction,
        array $marked,
        string $actionData
    ): string|RedirectResponse {
        $message = "Multiple Actions: 0";

        if (count($marked) === 0) {
            return $message;
        }

        switch ($markAction) {
            case 'del':
                $result = $this->textService->deleteArchivedTexts($marked);
                $message = "Archived Texts deleted: {$result['count']}";
                break;

            case 'addtag':
                $result = TagsFacade::addTagToArchivedTexts($actionData, $marked);
                $message = $result['error'] ?? "Tag added in {$result['count']} Texts";
                break;

            case 'deltag':
                TagsFacade::removeTagFromArchivedTexts($actionData, $marked);
                return $this->redirect('/text/archived');

            case 'unarch':
                $result = $this->textService->unarchiveTexts($marked);
                $message = "Unarchived Text(s): {$result['count']}";
                break;
        }

        return $message;
    }
}
