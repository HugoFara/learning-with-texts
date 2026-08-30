<?php

/**
 * Term Editor Page View
 *
 * Emits only the data the editor needs; the form itself is rendered by the
 * termEditPage component from GET /api/v1/terms/for-edit, so /word/edit,
 * /word/edit-term and /words/{id}/edit all share one implementation with the
 * reading view's modal.
 *
 * Variables expected:
 * - $textId: int - Text ID (0 when editing an existing term directly)
 * - $position: int - Word position in the text (0 when not from a text)
 * - $wordId: int|null - Term ID, or null when creating from a text position
 * - $returnUrl: string - Where to go once editing finishes
 *
 * PHP version 8.2
 *
 * @category Lwt
 * @package  Lwt\Modules\Vocabulary\Views
 * @author   HugoFara <git@hugofara.net>
 * @license  Unlicense <http://unlicense.org/>
 * @link     https://hugofara.github.io/lwt/developer/api
 * @since    3.3.0
 */

declare(strict_types=1);

namespace Lwt\Views\Word;

use Lwt\Shared\UI\Helpers\ConfigIsland;

// Type assertions for variables passed from controller
assert(is_int($textId));
assert(is_int($position));
assert($wordId === null || is_int($wordId));
assert(is_string($returnUrl));

?>
<?php ConfigIsland::render('term-edit-page-config', [
    'textId' => $textId,
    'position' => $position,
    'wordId' => $wordId,
    'returnUrl' => $returnUrl,
]); ?>

<div class="container" style="max-width: 640px;" x-data="termEditPage">
    <h2 class="title is-4" x-text="title"></h2>

    <template x-if="isLoading">
        <p class="has-text-grey"><?php echo __('vocabulary.common.loading'); ?></p>
    </template>

    <template x-if="hasError()">
        <div class="notification is-danger">
            <span x-text="errorMessage"></span>
        </div>
    </template>

    <div id="term-edit-page-form"></div>

    <p class="mt-4">
        <a :href="returnUrl">&larr; <?php echo __('vocabulary.common.cancel'); ?></a>
    </p>
</div>
