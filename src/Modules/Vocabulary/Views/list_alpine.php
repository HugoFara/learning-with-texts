<?php

/**
 * Word List View - Alpine.js SPA version
 *
 * This view provides a full reactive word list with:
 * - Client-side filtering, sorting, and pagination
 * - Inline editing of translations and romanizations
 * - Bulk selection and actions
 * - Mobile-responsive table/card views
 *
 * Variables expected:
 * - $currentlang: int - Currently selected language ID
 * - $perPage: int - Terms per page setting
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

namespace Lwt\Views\Word;

use Lwt\Shared\UI\Helpers\PageLayoutHelper;
use Lwt\Shared\UI\Helpers\IconHelper;
use Lwt\Shared\UI\Helpers\ConfigIsland;

/** @var int $currentlang */
/** @var int $perPage */

$grpStatus = htmlspecialchars(__('vocabulary.multi.group_status_changes'), ENT_QUOTES, 'UTF-8');
$grpEdits = htmlspecialchars(__('vocabulary.multi.group_edits'), ENT_QUOTES, 'UTF-8');
$grpExport = htmlspecialchars(__('vocabulary.multi.group_export'), ENT_QUOTES, 'UTF-8');
$grpOther = htmlspecialchars(__('vocabulary.multi.group_other'), ENT_QUOTES, 'UTF-8');
$grpDanger = htmlspecialchars(__('vocabulary.multi.group_danger_zone'), ENT_QUOTES, 'UTF-8');
$titleWcnt = htmlspecialchars(__('vocabulary.list.col_word_count_title'), ENT_QUOTES, 'UTF-8');
$titleEdit = htmlspecialchars(__('vocabulary.common.edit'), ENT_QUOTES, 'UTF-8');
$titleResetFilters = htmlspecialchars(__('vocabulary.list.reset_filters'), ENT_QUOTES, 'UTF-8');
$titleNoSent = htmlspecialchars(__('vocabulary.list.no_valid_sentence'), ENT_QUOTES, 'UTF-8');
$phSearchTerms = htmlspecialchars(__('vocabulary.list.search_placeholder'), ENT_QUOTES, 'UTF-8');
$titleFirstPage = htmlspecialchars(__('vocabulary.list.first_page'), ENT_QUOTES, 'UTF-8');
$titlePrevPage = htmlspecialchars(__('vocabulary.list.previous_page'), ENT_QUOTES, 'UTF-8');
$titleNextPage = htmlspecialchars(__('vocabulary.list.next_page'), ENT_QUOTES, 'UTF-8');
$titleLastPage = htmlspecialchars(__('vocabulary.list.last_page'), ENT_QUOTES, 'UTF-8');

?>

<?php
echo PageLayoutHelper::buildActionCard([
    [
        'url' => '/words/new',
        'label' => __('vocabulary.actions.import_single_term'),
        'icon' => 'circle-plus',
        'class' => 'is-primary'
    ],
    ['url' => '/word/upload', 'label' => __('vocabulary.actions.import_terms'), 'icon' => 'file-up'],
    ['url' => '/word/tags', 'label' => __('vocabulary.actions.term_tags'), 'icon' => 'tags'],
]);
?>

<!-- Alpine.js container for word list -->
<div x-data="wordListApp" x-cloak>

    <!-- Loading state -->
    <div x-show="loading" class="has-text-centered py-6">
        <span class="icon is-large">
            <?php echo IconHelper::render('loader-2', [
                'class' => 'animate-spin',
                'alt' => __('vocabulary.common.loading')
            ]); ?>
        </span>
        <p class="mt-2"><?= __('vocabulary.common.loading_terms') ?></p>
    </div>

    <!-- Filter bar -->
    <div x-show="!loading" class="box mb-4">
        <div class="columns is-multiline is-vcentered">
            <!-- Text filter (only when language is selected) -->
            <div class="column is-narrow" x-show="filters.lang && filterOptions.texts.length > 0">
                <div class="field has-addons">
                    <div class="control">
                        <span class="button is-static is-small"><?= __('vocabulary.list.text_filter') ?></span>
                    </div>
                    <div class="control">
                        <div class="select is-small">
                            <select :value="filters.text_id" @change="setFilterFromEvent('text_id', $event)">
                                <option value=""><?= __('vocabulary.list.all_texts') ?></option>
                                <template x-for="text in filterOptions.texts" :key="text.id">
                                    <option :value="text.id" x-text="text.title"></option>
                                </template>
                            </select>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Status filter -->
            <div class="column is-narrow">
                <div class="field has-addons">
                    <div class="control">
                        <span class="button is-static is-small"><?= __('vocabulary.list.status_filter') ?></span>
                    </div>
                    <div class="control">
                        <div class="select is-small">
                            <select :value="filters.status" @change="setFilterFromEvent('status', $event)">
                                <template x-for="status in filterOptions.statuses" :key="status.value">
                                    <option :value="status.value" x-text="status.label"></option>
                                </template>
                            </select>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Tag filters -->
            <div class="column is-narrow" x-show="filterOptions.tags.length > 0">
                <div class="field has-addons">
                    <div class="control">
                        <span class="button is-static is-small"><?= __('vocabulary.list.tag_filter') ?></span>
                    </div>
                    <div class="control">
                        <div class="select is-small">
                            <select :value="filters.tag1" @change="setFilterFromEvent('tag1', $event)">
                                <option value=""><?= __('vocabulary.list.any_tag') ?></option>
                                <template x-for="tag in filterOptions.tags" :key="tag.id">
                                    <option :value="tag.id" x-text="tag.name"></option>
                                </template>
                            </select>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Sort -->
            <div class="column is-narrow">
                <div class="field has-addons">
                    <div class="control">
                        <span class="button is-static is-small"><?= __('vocabulary.list.sort_filter') ?></span>
                    </div>
                    <div class="control">
                        <div class="select is-small">
                            <select :value="filters.sort" @change="setFilterFromEvent('sort', $event)">
                                <template x-for="sort in filterOptions.sorts" :key="sort.value">
                                    <option :value="sort.value" x-text="sort.label"></option>
                                </template>
                            </select>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Per page -->
            <div class="column is-narrow">
                <div class="field has-addons">
                    <div class="control">
                        <span class="button is-static is-small"><?= __('vocabulary.list.show_filter') ?></span>
                    </div>
                    <div class="control">
                        <div class="select is-small">
                            <select :value="filters.per_page" @change="setPerPageFromEvent($event)">
                                <template x-for="opt in perPageOptions" :key="opt">
                                    <option :value="opt" x-text="opt"></option>
                                </template>
                            </select>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Column visibility -->
            <div class="column is-narrow">
                <div class="dropdown" :class="columnsOpen ? 'is-active' : ''">
                    <div class="dropdown-trigger">
                        <button
                            type="button"
                            class="button is-small"
                            @click="toggleColumnsDropdown()"
                            @click.outside="closeColumnsDropdown()"
                        >
                            <span><?= __('vocabulary.list.columns') ?></span>
                            <span class="icon is-small">
                                <?php
                                echo IconHelper::render('chevron-down', ['alt' => __('vocabulary.list.toggle')]);
                                ?>
                            </span>
                        </button>
                    </div>
                    <div class="dropdown-menu" style="min-width: 10rem;">
                        <div class="dropdown-content">
                            <label class="dropdown-item checkbox is-size-7">
                                <input type="checkbox" :checked="columns.romanization"
                                    @change="toggleColumn('romanization')" />
                                <?= __('vocabulary.list.col_romanization') ?>
                            </label>
                            <label class="dropdown-item checkbox is-size-7">
                                <input type="checkbox" :checked="columns.translation"
                                    @change="toggleColumn('translation')" />
                                <?= __('vocabulary.list.col_translation') ?>
                            </label>
                            <label class="dropdown-item checkbox is-size-7">
                                <input type="checkbox" :checked="columns.tags"
                                    @change="toggleColumn('tags')" /> <?= __('vocabulary.list.col_tags') ?>
                            </label>
                            <label class="dropdown-item checkbox is-size-7">
                                <input type="checkbox" :checked="columns.sentence"
                                    @change="toggleColumn('sentence')" /> <?= __('vocabulary.list.col_sentence') ?>
                            </label>
                            <label class="dropdown-item checkbox is-size-7">
                                <input type="checkbox" :checked="columns.status"
                                    @change="toggleColumn('status')" /> <?= __('vocabulary.list.col_status') ?>
                            </label>
                            <label class="dropdown-item checkbox is-size-7">
                                <input type="checkbox" :checked="columns.score"
                                    @change="toggleColumn('score')" /> <?= __('vocabulary.list.col_score') ?>
                            </label>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Search query -->
            <div class="column">
                <div class="field has-addons">
                    <div class="control is-expanded has-icons-left">
                        <input type="text"
                               class="input is-small"
                               placeholder="<?= $phSearchTerms ?>"
                               :value="filters.query"
                               @input="syncQueryValue($event)"
                               @keyup.enter="applyQueryFilter()"
                               @keyup.debounce.500ms="applyQueryFilter()" />
                        <span class="icon is-left">
                            <?php echo IconHelper::render('search', ['alt' => __('vocabulary.common.search')]); ?>
                        </span>
                    </div>
                    <div class="control">
                        <button type="button" class="button is-small" @click="resetFilters()"
                            title="<?= $titleResetFilters ?>">
                            <?php echo IconHelper::render('x', ['alt' => __('vocabulary.common.reset')]); ?>
                        </button>
                    </div>
                </div>
            </div>
        </div>

        <!-- Results summary -->
        <div class="level mt-3 pt-3" style="border-top: 1px solid #dbdbdb;" x-show="pagination.total > 0">
            <div class="level-left">
                <div class="level-item">
                    <span
                        class="tag is-info is-medium"
                        x-text="termCountLabel()"
                    ></span>
                </div>
            </div>
            <div class="level-right">
                <div class="level-item">
                    <span
                        class="has-text-grey is-size-7"
                        x-text="pageLabel()"
                    ></span>
                </div>
            </div>
        </div>
    </div>

    <!-- No results message -->
    <div x-show="!loading && words.length === 0" class="notification is-info is-light">
        <p><?= __('vocabulary.list.no_results') ?>
            <a href="/words/new"><?= __('vocabulary.list.create_new_term') ?></a>.</p>
    </div>

    <!-- Multi Actions Section -->
    <div x-show="!loading && words.length > 0" class="box mb-4">
        <div class="level is-mobile mb-3">
            <div class="level-left">
                <div class="level-item">
                    <span class="icon-text">
                        <?php echo IconHelper::render('zap', [
                            'title' => __('vocabulary.multi.title'),
                            'alt' => __('vocabulary.multi.title')
                        ]); ?>
                        <span class="has-text-weight-semibold ml-1"><?= __('vocabulary.multi.title') ?></span>
                    </span>
                </div>
            </div>
        </div>

        <div class="field is-grouped is-grouped-multiline">
            <div class="control">
                <div class="field has-addons">
                    <div class="control">
                        <span class="button is-static is-small">
                            <strong><?= __('vocabulary.multi.all') ?></strong>&nbsp;<span
                                x-text="termCountLabel()"
                            ></span>
                        </span>
                    </div>
                    <div class="control">
                        <div class="select is-small">
                            <select @change="handleAllAction($event)">
                                <option value=""><?= __('vocabulary.multi.choose_action') ?></option>
                                <optgroup label="<?= $grpStatus ?>">
                                    <option value="alls1"><?= __('vocabulary.multi.set_status_1') ?></option>
                                    <option value="alls2"><?= __('vocabulary.multi.set_status_2') ?></option>
                                    <option value="alls3"><?= __('vocabulary.multi.set_status_3') ?></option>
                                    <option value="alls4"><?= __('vocabulary.multi.set_status_4') ?></option>
                                    <option value="alls5"><?= __('vocabulary.multi.set_status_5') ?></option>
                                    <option value="alls98"><?= __('vocabulary.multi.set_status_ignored') ?></option>
                                    <option value="alls99"><?= __('vocabulary.multi.set_status_well_known') ?></option>
                                    <option value="allspl1"><?= __('vocabulary.multi.increment_status') ?></option>
                                    <option value="allsmi1"><?= __('vocabulary.multi.decrement_status') ?></option>
                                </optgroup>
                                <optgroup label="<?= $grpEdits ?>">
                                    <option value="alllower"><?= __('vocabulary.multi.set_lowercase') ?></option>
                                    <option value="allcap"><?= __('vocabulary.multi.capitalize') ?></option>
                                    <option value="alladdtag"><?= __('vocabulary.multi.add_tag') ?></option>
                                    <option value="alldeltag"><?= __('vocabulary.multi.remove_tag') ?></option>
                                </optgroup>
                                <optgroup label="<?= $grpExport ?>">
                                    <option value="allexpapkg"><?= __('vocabulary.multi.export_apkg_all') ?></option>
                                    <option value="allimpapkg"><?= __('vocabulary.multi.import_apkg') ?></option>
                                </optgroup>
                                <optgroup label="<?= $grpDanger ?>">
                                    <option value="alldel"><?= __('vocabulary.multi.delete_all') ?></option>
                                </optgroup>
                            </select>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <div class="field is-grouped is-grouped-multiline mt-3">
            <div class="control">
                <div class="buttons are-small">
                    <button type="button" class="button is-light" @click="markAll(true)">
                        <?php echo IconHelper::render('check-check', ['alt' => __('vocabulary.multi.mark_all')]); ?>
                        <span class="ml-1"><?= __('vocabulary.multi.mark_all') ?></span>
                    </button>
                    <button type="button" class="button is-light" @click="markAll(false)">
                        <?php echo IconHelper::render('x', ['alt' => __('vocabulary.multi.mark_none')]); ?>
                        <span class="ml-1"><?= __('vocabulary.multi.mark_none') ?></span>
                    </button>
                    <span
                        x-show="getMarkedCount() > 0"
                        class="tag is-warning ml-2"
                        x-text="markedCountLabel()"
                    ></span>
                </div>
            </div>
            <div class="control">
                <div class="field has-addons">
                    <div class="control">
                        <span class="button is-static is-small"><?= __('vocabulary.multi.marked_terms') ?></span>
                    </div>
                    <div class="control">
                        <div class="select is-small">
                            <select :disabled="getMarkedCount() === 0" @change="handleMultiAction($event)">
                                <option value=""><?= __('vocabulary.multi.choose_action') ?></option>
                                <optgroup label="<?= $grpStatus ?>">
                                    <option value="s1"><?= __('vocabulary.multi.set_status_1') ?></option>
                                    <option value="s2"><?= __('vocabulary.multi.set_status_2') ?></option>
                                    <option value="s3"><?= __('vocabulary.multi.set_status_3') ?></option>
                                    <option value="s4"><?= __('vocabulary.multi.set_status_4') ?></option>
                                    <option value="s5"><?= __('vocabulary.multi.set_status_5') ?></option>
                                    <option value="s98"><?= __('vocabulary.multi.set_status_ignored') ?></option>
                                    <option value="s99"><?= __('vocabulary.multi.set_status_well_known') ?></option>
                                    <option value="spl1"><?= __('vocabulary.multi.increment_status') ?></option>
                                    <option value="smi1"><?= __('vocabulary.multi.decrement_status') ?></option>
                                    <option value="today"><?= __('vocabulary.multi.set_today') ?></option>
                                </optgroup>
                                <optgroup label="<?= $grpEdits ?>">
                                    <option value="lower"><?= __('vocabulary.multi.set_lowercase') ?></option>
                                    <option value="cap"><?= __('vocabulary.multi.capitalize') ?></option>
                                    <option value="delsent"><?= __('vocabulary.multi.clear_sentences') ?></option>
                                    <option value="addtag"><?= __('vocabulary.multi.add_tag') ?></option>
                                    <option value="deltag"><?= __('vocabulary.multi.remove_tag') ?></option>
                                </optgroup>
                                <optgroup label="<?= $grpExport ?>">
                                    <option value="exp"><?= __('vocabulary.multi.export_anki') ?></option>
                                    <option value="exptsv"><?= __('vocabulary.multi.export_tsv') ?></option>
                                    <option value="expapkg"><?= __('vocabulary.multi.export_apkg_selection') ?></option>
                                </optgroup>
                                <optgroup label="<?= $grpOther ?>">
                                    <option value="review"><?= __('vocabulary.multi.review_selection') ?></option>
                                </optgroup>
                                <optgroup label="<?= $grpDanger ?>">
                                    <option value="del"><?= __('vocabulary.multi.delete_selected') ?></option>
                                </optgroup>
                            </select>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Desktop Table View -->
    <div class="table-container is-hidden-mobile" x-show="!loading && words.length > 0">
        <table class="table is-striped is-hoverable is-fullwidth">
            <thead>
                <tr>
                    <th class="has-text-centered" style="width: 3em;"><?= __('vocabulary.common.mark') ?></th>
                    <th class="has-text-centered" style="width: 3em;"><?= __('vocabulary.common.action_short') ?></th>
                    <th><?= __('vocabulary.list.col_term') ?></th>
                    <th x-show="columns.romanization"><?= __('vocabulary.list.col_romanization') ?></th>
                    <th x-show="columns.translation"><?= __('vocabulary.list.col_translation') ?></th>
                    <th x-show="columns.tags"><?= __('vocabulary.list.col_tags') ?></th>
                    <th class="has-text-centered" style="width: 6em;" x-show="columns.sentence">
                        <?= __('vocabulary.list.col_sentence') ?></th>
                    <th class="has-text-centered" style="width: 5em;" x-show="columns.status">
                        <?= __('vocabulary.list.col_status') ?></th>
                    <th class="has-text-centered" style="width: 5em;" x-show="columns.score">
                        <?= __('vocabulary.list.col_score') ?></th>
                    <th
                        class="has-text-centered"
                        style="width: 7em;"
                        x-show="filters.sort === 7"
                        title="<?= $titleWcnt ?>"
                    ><?= __('vocabulary.list.col_word_count') ?></th>
                </tr>
            </thead>
            <tbody>
                <template x-for="word in words" :key="word.id">
                    <tr>
                        <!-- Checkbox -->
                        <td class="has-text-centered">
                            <input type="checkbox"
                                   class="markcheck"
                                   :checked="isMarked(word.id)"
                                   @change="toggleMark(word.id, $event.target.checked)" />
                        </td>

                        <!-- Actions -->
                        <td class="has-text-centered" style="white-space: nowrap;">
                            <div class="buttons are-small is-centered">
                                <a
                                    :href="'/words/' + word.id + '/edit'"
                                    class="button is-small is-ghost"
                                    title="<?= $titleEdit ?>"
                                >
                                    <?php
                                    echo IconHelper::render(
                                        'file-pen-line',
                                        ['title' => __('vocabulary.common.edit'), 'alt' => __('vocabulary.common.edit')]
                                    );
                                    ?>
                                </a>
                            </div>
                        </td>

                        <!-- Term -->
                        <td>
                            <span :class="word.ttsClass" :dir="word.rightToLeft ? 'rtl' : 'ltr'">
                                <strong x-text="word.text"></strong>
                            </span>
                        </td>

                        <!-- Romanization -->
                        <td x-show="columns.romanization">
                            <template x-if="isEditing(word.id, 'romanization')">
                                <span class="inline-edit-container">
                                    <textarea class="textarea is-small"
                                              :data-edit-id="word.id"
                                              data-edit-field="romanization"
                                              x-model="editValue"
                                              @keydown.escape="cancelEdit()"
                                              @keydown.ctrl.enter="saveEdit()"
                                              rows="1"></textarea>
                                    <div class="buttons are-small mt-1">
                                        <button type="button" class="button is-small is-success"
                                            @click="saveEdit()" :disabled="editSaving">
                                            <?php
                                            echo IconHelper::render('check', ['alt' => __('vocabulary.common.save')]);
                                            ?>
                                        </button>
                                        <button type="button" class="button is-small" @click="cancelEdit()">
                                            <?php
                                            echo IconHelper::render('x', ['alt' => __('vocabulary.common.cancel')]);
                                            ?>
                                        </button>
                                    </div>
                                </span>
                            </template>
                            <template x-if="!isEditing(word.id, 'romanization')">
                                <span class="clickedit"
                                      @click="startEdit(word.id, 'romanization')"
                                      x-text="getDisplayValue(word, 'romanization')"></span>
                            </template>
                        </td>

                        <!-- Translation -->
                        <td x-show="columns.translation">
                            <template x-if="isEditing(word.id, 'translation')">
                                <span class="inline-edit-container">
                                    <textarea class="textarea is-small"
                                              :data-edit-id="word.id"
                                              data-edit-field="translation"
                                              x-model="editValue"
                                              @keydown.escape="cancelEdit()"
                                              @keydown.ctrl.enter="saveEdit()"
                                              rows="2"></textarea>
                                    <div class="buttons are-small mt-1">
                                        <button type="button" class="button is-small is-success"
                                            @click="saveEdit()" :disabled="editSaving">
                                            <?php
                                            echo IconHelper::render('check', ['alt' => __('vocabulary.common.save')]);
                                            ?>
                                        </button>
                                        <button type="button" class="button is-small" @click="cancelEdit()">
                                            <?php
                                            echo IconHelper::render('x', ['alt' => __('vocabulary.common.cancel')]);
                                            ?>
                                        </button>
                                    </div>
                                </span>
                            </template>
                            <template x-if="!isEditing(word.id, 'translation')">
                                <span class="clickedit"
                                      @click="startEdit(word.id, 'translation')"
                                      x-text="$markdown(getDisplayValue(word, 'translation'))"></span>
                            </template>
                        </td>

                        <!-- Tags -->
                        <td x-show="columns.tags">
                            <span class="has-text-grey is-size-7" x-text="word.tags"></span>
                        </td>

                        <!-- Sentence -->
                        <td class="has-text-centered" x-show="columns.sentence">
                            <template x-if="word.sentenceOk">
                                <span class="has-text-success" :title="word.sentence">
                                    <?php
                                    echo IconHelper::render('circle-check', ['alt' => __('vocabulary.list.yes')]);
                                    ?>
                                </span>
                            </template>
                            <template x-if="!word.sentenceOk">
                                <span class="has-text-danger" title="<?= $titleNoSent ?>">
                                    <?php echo IconHelper::render('circle-x', ['alt' => __('vocabulary.list.no')]); ?>
                                </span>
                            </template>
                        </td>

                        <!-- Status / Days -->
                        <td class="has-text-centered" x-show="columns.status" :title="word.statusLabel">
                            <span
                                class="tag"
                                :class="getStatusClass(word.status)"
                                x-text="statusDisplay(word)"
                            ></span>
                        </td>

                        <!-- Score -->
                        <td class="has-text-centered" x-show="columns.score" style="white-space: nowrap;">
                            <span
                                class="tag is-light"
                                :class="getStatusClass(word.status)"
                                x-text="formatScore(word.score)"
                            ></span>
                        </td>

                        <!-- Word count (for sort 7) -->
                        <td
                            class="has-text-centered"
                            x-show="filters.sort === 7"
                            x-text="word.textsWordCount || 0"
                        ></td>
                    </tr>
                </template>
            </tbody>
        </table>
    </div>

    <!-- Mobile Card View -->
    <div class="is-hidden-tablet" x-show="!loading && words.length > 0">
        <template x-for="word in words" :key="word.id">
            <div class="card mb-3">
                <div class="card-content">
                    <div class="level is-mobile mb-2">
                        <div class="level-left">
                            <div class="level-item">
                                <label class="checkbox">
                                    <input type="checkbox"
                                           class="markcheck"
                                           :checked="isMarked(word.id)"
                                           @change="toggleMark(word.id, $event.target.checked)" />
                                </label>
                            </div>
                            <div class="level-item">
                                <span :class="word.ttsClass" :dir="word.rightToLeft ? 'rtl' : 'ltr'">
                                    <strong class="is-size-5" x-text="word.text"></strong>
                                </span>
                            </div>
                        </div>
                        <div class="level-right">
                            <div class="level-item">
                                <div class="tags has-addons mb-0">
                                    <span
                                        class="tag"
                                        :class="getStatusClass(word.status)"
                                        x-text="word.statusAbbr"
                                    ></span>
                                    <span
                                        class="tag"
                                        :class="getStatusClass(word.status)"
                                        x-text="formatScore(word.score)"
                                    ></span>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Romanization (if exists) -->
                    <p
                        x-show="word.romanization && word.romanization !== '*'"
                        class="has-text-grey is-size-7 mb-1"
                    >
                        <span
                            class="clickedit"
                            @click="startEdit(word.id, 'romanization')"
                            x-text="word.romanization"
                        ></span>
                    </p>

                    <!-- Translation -->
                    <p class="mb-2">
                        <!-- Inline edit for translation on mobile -->
                        <template x-if="isEditing(word.id, 'translation')">
                            <span class="inline-edit-container">
                                <textarea class="textarea is-small"
                                          :data-edit-id="word.id"
                                          data-edit-field="translation"
                                          x-model="editValue"
                                          @keydown.escape="cancelEdit()"
                                          @keydown.ctrl.enter="saveEdit()"
                                          rows="2"></textarea>
                                <div class="buttons are-small mt-1">
                                    <button
                                        type="button"
                                        class="button is-small is-success"
                                        @click="saveEdit()"
                                        :disabled="editSaving"
                                    ><?= __('vocabulary.common.save') ?></button>
                                    <button
                                        type="button"
                                        class="button is-small"
                                        @click="cancelEdit()"
                                    ><?= __('vocabulary.common.cancel') ?></button>
                                </div>
                            </span>
                        </template>
                        <template x-if="!isEditing(word.id, 'translation')">
                            <span
                                class="clickedit"
                                @click="startEdit(word.id, 'translation')"
                                x-text="$markdown(getDisplayValue(word, 'translation'))"
                            ></span>
                        </template>
                    </p>

                    <div class="is-flex is-justify-content-space-between is-align-items-center">
                        <div class="tags">
                            <span x-show="word.tags" class="tag is-light" x-text="word.tags"></span>
                            <template x-if="columns.sentence && word.sentenceOk">
                                <span class="tag is-success is-light" :title="word.sentence">
                                    <?php
                                    $altHasSent = __('vocabulary.list.has_sentence');
                                    echo IconHelper::render('message-square', ['alt' => $altHasSent]);
                                    ?>
                                </span>
                            </template>
                        </div>
                        <div class="buttons are-small">
                            <a :href="'/words/' + word.id + '/edit'" class="button is-small is-info is-light">
                                <?php
                                echo IconHelper::render('file-pen-line', ['alt' => __('vocabulary.common.edit')]);
                                ?>
                            </a>
                        </div>
                    </div>
                </div>
            </div>
        </template>
    </div>

    <!-- Pagination -->
    <nav class="level mt-4" x-show="!loading && pagination.total_pages > 1">
        <div class="level-left">
            <div class="level-item">
                <span
                    class="tag is-info is-medium"
                    x-text="termCountLabel()"
                ></span>
            </div>
        </div>
        <div class="level-right">
            <div class="level-item">
                <div class="buttons">
                    <button type="button"
                            class="button is-small"
                            :disabled="isFirstPage()"
                            @click="goToPage(1)"
                            title="<?= $titleFirstPage ?>">
                        <?php echo IconHelper::render('chevrons-left', ['alt' => __('vocabulary.list.first')]); ?>
                    </button>
                    <button type="button"
                            class="button is-small"
                            :disabled="isFirstPage()"
                            @click="goToPrevPage()"
                            title="<?= $titlePrevPage ?>">
                        <?php echo IconHelper::render('chevron-left', ['alt' => __('vocabulary.list.previous')]); ?>
                    </button>
                    <span
                        class="button is-static is-small"
                        x-text="paginationText()"
                    ></span>
                    <button type="button"
                            class="button is-small"
                            :disabled="isLastPage()"
                            @click="goToNextPage()"
                            title="<?= $titleNextPage ?>">
                        <?php echo IconHelper::render('chevron-right', ['alt' => __('vocabulary.list.next')]); ?>
                    </button>
                    <button type="button"
                            class="button is-small"
                            :disabled="isLastPage()"
                            @click="goToLastPage()"
                            title="<?= $titleLastPage ?>">
                        <?php echo IconHelper::render('chevrons-right', ['alt' => __('vocabulary.list.last')]); ?>
                    </button>
                </div>
            </div>
        </div>
    </nav>
</div>

<!-- Config for Alpine - pass active language and per-page setting -->
<?php ConfigIsland::render('word-list-config', [
    'activeLanguageId' => $currentlang,
    'perPage' => $perPage,
    'csrfToken' => \Lwt\Shared\UI\Helpers\FormHelper::csrfToken(),
]); ?>

<style>
    .clickedit {
        cursor: pointer;
        border-bottom: 1px dotted #ccc;
    }
    .clickedit:hover {
        background-color: var(--bulma-scheme-main-bis, #f5f5f5);
    }
    .inline-edit-container {
        display: inline-block;
        min-width: 150px;
    }
    .inline-edit-container .textarea {
        min-height: 2em;
    }
</style>
