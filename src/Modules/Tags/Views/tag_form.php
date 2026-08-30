<?php

/**
 * Tag Form View - New/Edit tag form
 *
 * Variables expected:
 * - $mode: 'new' or 'edit'
 * - $tag: array with 'id', 'text', 'comment' (for edit mode, null for new)
 * - $service: TagsFacade instance
 * - $formFieldPrefix: 'Tg' or 'T2'
 *
 * PHP version 8.2
 *
 * @category Lwt
 * @package  Lwt\Views
 * @author   HugoFara <git@hugofara.net>
 * @license  Unlicense <http://unlicense.org/>
 * @link     https://hugofara.github.io/lwt/developer/api
 * @since    3.0.0
 *
 * @psalm-suppress UndefinedVariable - Variables are set by the including controller
 */

declare(strict_types=1);

namespace Lwt\Modules\Tags\Views;

use Lwt\Shared\UI\Helpers\IconHelper;
use Lwt\Modules\Tags\Application\TagsFacade;
use Lwt\Shared\UI\Helpers\ConfigIsland;

/**
 * @var string $mode
 * @var array{id?: int, text?: string, comment?: string}|null $tag
 * @var TagsFacade $service
 * @var string $formFieldPrefix
 */

// Ensure variables are properly typed for Psalm
assert(is_string($mode));
assert($tag === null || is_array($tag));
assert($service instanceof TagsFacade);
assert(is_string($formFieldPrefix));

$isEdit = $mode === 'edit';
$pageTitle = $isEdit ? __('tags.form_edit_title') : __('tags.form_new_title');
$formName = $isEdit ? 'edittag' : 'newtag';
$baseUrl = $service->getBaseUrl();
$tagId = $tag !== null && isset($tag['id']) ? $tag['id'] : 0;
$submitValue = $isEdit ? __('tags.form_change') : __('tags.form_save');

$placeholderTag = htmlspecialchars(__('tags.form_placeholder_tag'), ENT_QUOTES, 'UTF-8');
$placeholderComment = htmlspecialchars(__('tags.form_placeholder_comment'), ENT_QUOTES, 'UTF-8');
$titleRequired = htmlspecialchars(__('tags.form_field_required'), ENT_QUOTES, 'UTF-8');
$labelComment = __('tags.form_label_comment');

// `Tg` for term tags, `T2` for text tags — the API takes the type by name.
$tagType = $formFieldPrefix === 'T2' ? 'text' : 'term';

?>
<h2 class="title is-4"><?php echo $pageTitle; ?></h2>

<?php ConfigIsland::render('tag-form-config', [
    'type' => $tagType,
    'isEdit' => $isEdit,
    'tagId' => $tagId,
    'baseUrl' => $baseUrl,
]); ?>

<form name="<?php echo $formName; ?>" class="validate"
      x-data="tagFormApp" @submit.prevent="save()">

    <div x-show="error" x-cloak class="notification is-danger">
        <span x-text="error"></span>
    </div>

    <div x-show="isLoading" x-cloak class="has-text-centered py-4">
        <?php echo __e('tags.list_loading'); ?>
    </div>

    <div class="box">
        <!-- Tag Name -->
        <div class="field is-horizontal">
            <div class="field-label is-normal">
                <label class="label" for="<?php echo $formFieldPrefix; ?>Text"><?= __('tags.form_label_tag') ?></label>
            </div>
            <div class="field-body">
                <div class="field has-addons">
                    <div class="control is-expanded">
                        <input type="text"
                               class="input notempty noblanksnocomma checkoutsidebmp <?php
                                   echo $isEdit ? '' : 'setfocus';
                                ?>"
                               id="<?php echo $formFieldPrefix; ?>Text"
                               name="<?php echo $formFieldPrefix; ?>Text"
                               data_info="Tag"
                               maxlength="20"
                               placeholder="<?= $placeholderTag ?>"
                               x-model="tagText"
                               required />
                    </div>
                    <div class="control">
                        <span
                            class="icon has-text-danger"
                            title="<?= $titleRequired ?>"
                        >
                            <?php echo IconHelper::render('asterisk', ['alt' => 'Required']); ?>
                        </span>
                    </div>
                </div>
                <p class="help"><?= __('tags.form_help_tag') ?></p>
            </div>
        </div>

        <!-- Comment -->
        <div class="field is-horizontal">
            <div class="field-label is-normal">
                <label class="label" for="<?php echo $formFieldPrefix; ?>Comment"><?= $labelComment ?></label>
            </div>
            <div class="field-body">
                <div class="field">
                    <div class="control">
                        <textarea class="textarea textarea-noreturn checklength checkoutsidebmp"
                                  id="<?php echo $formFieldPrefix; ?>Comment"
                                  name="<?php echo $formFieldPrefix; ?>Comment"
                                  data_maxlength="200"
                                  data_info="Comment"
                                  rows="3"
                                  placeholder="<?= $placeholderComment ?>"
                                  x-model="tagComment"></textarea>
                    </div>
                    <p class="help">
                        <span :class="charCountClass()" x-text="charCountLabel()"></span>
                    </p>
                </div>
            </div>
        </div>
    </div>

    <!-- Form Actions -->
    <div class="field is-grouped is-grouped-right">
        <div class="control">
            <button type="button" class="button is-light" @click="cancel()">
                <?= __e('tags.form_cancel') ?>
            </button>
        </div>
        <div class="control">
            <button type="submit" class="button is-primary" :disabled="isSaving">
                <span class="icon is-small">
                    <?php echo IconHelper::render('save', ['alt' => 'Save']); ?>
                </span>
                <span><?php echo $submitValue; ?></span>
            </button>
        </div>
    </div>
</form>
