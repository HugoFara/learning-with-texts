<?php

/**
 * Local Dictionaries Index View
 *
 * The dictionary table itself is rendered by the `dictionaryList` component
 * from `GET /local-dictionaries`; this view supplies only the language ID.
 *
 * Variables expected:
 * - $langId: int current language ID
 * - $langName: string current language name
 * - $localDictMode: int (0-3)
 * - $languages: array of languages for dropdown
 *
 * PHP version 8.2
 *
 * @category Lwt
 * @package  Lwt\Modules\Dictionary\Views
 * @author   HugoFara <git@hugofara.net>
 * @license  Unlicense <http://unlicense.org/>
 * @link     https://hugofara.github.io/lwt/developer/api
 * @since    3.0.0
 */

declare(strict_types=1);

namespace Lwt\Modules\Dictionary\Views;

use Lwt\Shared\UI\Helpers\IconHelper;
use Lwt\Shared\UI\Helpers\PageLayoutHelper;
use Lwt\Shared\UI\Helpers\ConfigIsland;

/**
 * @var int $langId
 * @var string $langName
 * @var int $localDictMode
 * @var array<array{id: int, name: string}> $languages
 * @var string $message
 * @var string $error
 */

if (!empty($message)) :
    $messageText = match ($message) {
        'deleted' => __('dictionary.deleted_success'),
        default => str_starts_with($message, 'imported_')
            ? __('dictionary.imported_count', ['count' => substr($message, 9)])
            : $message,
    };
    ?>
<div class="notification is-success is-light mb-4">
    <button class="delete" @click="$el.parentElement.remove()"></button>
    <?php echo htmlspecialchars($messageText, ENT_QUOTES); ?>
</div>
<?php endif; ?>

<?php if (!empty($error)) : ?>
<div class="notification is-danger is-light mb-4">
    <button class="delete" @click="$el.parentElement.remove()"></button>
    <?php echo htmlspecialchars($error, ENT_QUOTES); ?>
</div>
<?php endif; ?>

<?php
echo PageLayoutHelper::buildActionCard([
    ['url' => '/languages', 'label' => __('dictionary.languages_link'), 'icon' => 'globe'],
    [
        'url' => '/word/upload?tab=dictionary', 'label' => __('dictionary.import_dictionary'),
        'icon' => 'upload', 'class' => 'is-primary'
    ],
]);
?>

<div class="box mb-4">
    <form method="GET" action="/dictionaries">
        <div class="field has-addons">
            <div class="control is-expanded">
                <div class="select is-fullwidth">
                    <select name="lang" @change="$el.form.submit()">
                        <?php foreach ($languages as $lang) : ?>
                        <option value="<?php echo $lang['id']; ?>"
                            <?php echo $lang['id'] == $langId ? 'selected' : ''; ?>>
                            <?php echo htmlspecialchars($lang['name'], ENT_QUOTES); ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>
            <div class="control">
                <button type="submit" class="button is-info">
                    <?php echo IconHelper::render('search', ['alt' => __('common.go')]); ?>
                </button>
            </div>
        </div>
    </form>
</div>

<!-- Mode Info -->
<div class="box mb-4">
    <h4 class="title is-5 mb-2"><?php echo __('dictionary.local_mode_title'); ?></h4>
    <p class="mb-2">
        <?php echo __('dictionary.current_mode'); ?>
        <span class="tag is-info is-medium">
            <?php
            echo match ($localDictMode) {
                0 => __('dictionary.mode_online'),
                1 => __('dictionary.mode_local_first'),
                2 => __('dictionary.mode_local_only'),
                3 => __('dictionary.mode_combined'),
                default => __('dictionary.mode_unknown'),
            };
            ?>
        </span>
    </p>
    <p class="help">
        <?php echo __('dictionary.mode_change_help'); ?>
        <a href="/languages/<?php echo $langId; ?>/edit#local-dict-mode">
            <?php echo __('dictionary.language_settings'); ?>
        </a>.
    </p>
</div>

<?php ConfigIsland::render('dictionary-list-config', ['languageId' => $langId]); ?>

<div x-data="dictionaryList" x-init="init()">

    <div x-show="error" class="notification is-danger is-light">
        <span x-text="error"></span>
    </div>

<!-- Quick Create -->
<div class="box mb-4">
    <h4 class="title is-5 mb-2"><?php echo __('dictionary.quick_create'); ?></h4>
    <form @submit.prevent="create()">
        <div class="field has-addons">
            <div class="control is-expanded">
                <?php
                $createPlaceholder = htmlspecialchars(
                    __('dictionary.dictionary_name_placeholder'),
                    ENT_QUOTES
                );
                ?>
                <input type="text" class="input"
                       x-model="newName"
                       placeholder="<?php echo $createPlaceholder; ?>"
                       required>
            </div>
            <div class="control">
                <button type="submit" class="button is-primary" :disabled="isCreating">
                    <?php echo IconHelper::render('plus', ['alt' => __('common.create')]); ?>
                    <?php echo __('common.create'); ?>
                </button>
            </div>
        </div>
    </form>
</div>

<!-- Dictionaries List -->
<div class="box">
    <h4 class="title is-5 mb-4">
        <?php
        echo htmlspecialchars(
            __('dictionary.dictionaries_for', ['language' => $langName]),
            ENT_QUOTES
        );
        ?>
    </h4>



    <div x-show="isLoading" class="has-text-centered py-5">
        <span class="icon is-large">
            <i data-lucide="loader-2" class="animate-spin"></i>
        </span>
    </div>

    <div x-show="!isLoading && dictionaries.length === 0" class="notification is-light">
        <p><?php echo __('dictionary.no_local_dicts'); ?></p>
        <p class="mt-2">
            <a href="/word/upload?tab=dictionary" class="button is-primary is-small">
                <?php echo IconHelper::render('upload', ['alt' => __('common.import')]); ?>
                <?php echo __('dictionary.import_a_dictionary'); ?>
            </a>
        </p>
    </div>

    <div class="table-container" x-show="!isLoading && dictionaries.length > 0">
        <table class="table is-fullwidth is-striped is-hoverable">
            <thead>
                <tr>
                    <th><?php echo __('common.name'); ?></th>
                    <th><?php echo __('dictionary.col_format'); ?></th>
                    <th><?php echo __('dictionary.col_entries'); ?></th>
                    <th><?php echo __('dictionary.col_priority'); ?></th>
                    <th><?php echo __('common.status'); ?></th>
                    <th><?php echo __('common.actions'); ?></th>
                </tr>
            </thead>
            <tbody>
                <template x-for="dict in dictionaries" :key="dict.id">
                    <tr>
                        <td>
                            <strong x-text="dict.name"></strong>
                            <template x-if="hasDescription(dict)">
                                <span>
                                    <br><span class="is-size-7 has-text-grey"
                                              x-text="descriptionOf(dict)"></span>
                                </span>
                            </template>
                        </td>
                        <td><span class="tag" x-text="formatLabel(dict)"></span></td>
                        <td x-text="entryCountLabel(dict)"></td>
                        <td x-text="dict.priority"></td>
                        <td><span :class="statusClass(dict)" x-text="statusLabel(dict)"></span></td>
                        <td>
                            <div class="buttons are-small">
                                <button type="button"
                                        :class="toggleClass(dict)"
                                        :disabled="isBusy(dict)"
                                        :title="toggleTitle(dict)"
                                        @click="toggle(dict)">
                                    <span class="icon is-small">
                                        <i :data-lucide="toggleIcon(dict)"></i>
                                    </span>
                                </button>

                                <a href="/word/upload?tab=dictionary"
                                   class="button is-info"
                                   title="<?php
                                    echo htmlspecialchars(__('dictionary.import_entries'), ENT_QUOTES);
                                    ?>">
                                    <?php echo IconHelper::render('upload', ['alt' => __('common.import')]); ?>
                                </a>

                                <button type="button" class="button is-danger"
                                        :disabled="isBusy(dict)"
                                        title="<?php echo htmlspecialchars(__('common.delete'), ENT_QUOTES); ?>"
                                        @click="confirmDelete(dict)">
                                    <?php echo IconHelper::render('trash', ['alt' => __('common.delete')]); ?>
                                </button>
                            </div>
                        </td>
                    </tr>
                </template>
            </tbody>
        </table>
    </div>
</div>

</div>