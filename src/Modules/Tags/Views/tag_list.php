<?php

/**
 * Tag List View - scaffold for the client-rendered tag list.
 *
 * Rows, pagination and the sort options all come from
 * `GET /api/v1/tags/{type}/list`; the bulk actions post to the matching
 * DELETE routes. See tag_list_app.ts.
 *
 * Variables expected:
 * - $message: Status/error message to display
 * - $currentQuery: Current filter query
 * - $currentSort: Current sort index
 * - $service: TagsFacade instance
 * - $isTextTag: boolean - true for text tags, false for term tags
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
use Lwt\Shared\UI\Helpers\PageLayoutHelper;
use Lwt\Modules\Tags\Application\TagsFacade;
use Lwt\Shared\UI\Helpers\ConfigIsland;

/**
 * @var string $message
 * @var string $currentQuery
 * @var int $currentSort
 * @var TagsFacade $service
 * @var bool $isTextTag
 */

assert(is_string($message));
assert(is_string($currentQuery));
assert(is_int($currentSort));
assert($service instanceof TagsFacade);
assert(is_bool($isTextTag));

$baseUrl = $service->getBaseUrl();
$itemLabel = $isTextTag ? __('tags.items_label_texts') : __('tags.items_label_terms');
$newTagLabel = $isTextTag ? __('tags.list_new_text_tag') : __('tags.list_new_term_tag');
$itemsColLabel = $isTextTag
    ? __('tags.list_col_items_with_tag_texts')
    : __('tags.list_col_items_with_tag_terms');

PageLayoutHelper::renderMessage($message, false);

echo PageLayoutHelper::buildActionCard([
    [
        'url' => $baseUrl . '/new',
        'label' => $newTagLabel,
        'icon' => 'circle-plus',
        'class' => 'is-primary'
    ],
]);
?>

<?php ConfigIsland::render('tag-list-config', [
    'type' => $isTextTag ? 'text' : 'term',
    'isTextTag' => $isTextTag,
    'query' => $currentQuery,
    'sort' => $currentSort,
    'page' => 1,
]); ?>

<div x-data="tagListApp">
    <div x-show="error" x-cloak class="notification is-danger">
        <span x-text="error"></span>
    </div>

    <!-- Filter bar -->
    <div class="box mb-4">
        <div class="field has-addons">
            <div class="control is-expanded has-icons-left">
                <input type="text"
                       class="input"
                       x-model="searchInput"
                       @keyup.enter="search()"
                       placeholder="<?= htmlspecialchars(
                           __('tags.list_search_placeholder'),
                           ENT_QUOTES,
                           'UTF-8'
                       ) ?>" />
                <span class="icon is-left">
                    <?php echo IconHelper::render('search', ['alt' => 'Search']); ?>
                </span>
            </div>
            <div class="control">
                <button type="button" class="button is-info" @click="search()">
                    <?= __e('tags.list_search_button') ?>
                </button>
            </div>
            <div class="control" x-show="searchInput" x-cloak>
                <button type="button" class="button" @click="clearSearch()">
                    <?php echo IconHelper::render('x', ['alt' => 'Clear']); ?>
                </button>
            </div>
        </div>

        <div class="level mt-4 pt-4" style="border-top: 1px solid #dbdbdb;"
             x-show="pagination.total > 0" x-cloak>
            <div class="level-left">
                <div class="level-item">
                    <span class="tag is-info is-medium" x-text="pagination.total"></span>
                </div>
            </div>
            <div class="level-right">
                <div class="level-item">
                    <div class="field has-addons">
                        <div class="control">
                            <span class="button is-static is-small"><?= __e('tags.list_sort') ?></span>
                        </div>
                        <div class="control">
                            <div class="select is-small">
                                <select :value="sort" @change="setSort($event.target.value)">
                                    <template x-for="option in sortOptions" :key="option.value">
                                        <option :value="option.value" x-text="option.text"></option>
                                    </template>
                                </select>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <div x-show="isLoading" x-cloak class="has-text-centered py-4">
        <?= __e('tags.list_loading') ?>
    </div>

    <p x-show="isEmpty()" x-cloak class="has-text-grey"><?= __e('tags.list_no_tags') ?></p>

    <div x-show="tags.length > 0" x-cloak>
        <!-- Multi Actions -->
        <div class="box mb-4">
            <div class="level is-mobile mb-3">
                <div class="level-left">
                    <div class="level-item">
                        <span class="icon-text">
                            <?php echo IconHelper::render(
                                'zap',
                                ['title' => 'Multi Actions', 'alt' => 'Multi Actions']
                            ); ?>
                            <span class="has-text-weight-semibold ml-1"><?= __e('tags.list_multi_actions') ?></span>
                        </span>
                    </div>
                </div>
            </div>

            <div class="field is-grouped is-grouped-multiline">
                <div class="control">
                    <div class="buttons are-small">
                        <button type="button" class="button is-light" @click="markAll()">
                            <?php echo IconHelper::render('check-check', ['alt' => 'Mark All']); ?>
                            <span class="ml-1"><?= __e('tags.list_mark_all') ?></span>
                        </button>
                        <button type="button" class="button is-light" @click="markNone()">
                            <?php echo IconHelper::render('x', ['alt' => 'Mark None']); ?>
                            <span class="ml-1"><?= __e('tags.list_mark_none') ?></span>
                        </button>
                    </div>
                </div>
                <div class="control">
                    <div class="buttons are-small">
                        <button type="button" class="button is-danger is-light"
                                :disabled="selectedIds.length === 0 || isBusy"
                                @click="deleteSelected()">
                            <?php echo IconHelper::render('circle-minus', ['alt' => 'Delete']); ?>
                            <span class="ml-1"><?= __e('tags.list_delete_marked') ?></span>
                        </button>
                        <button type="button" class="button is-danger"
                                :disabled="pagination.total === 0 || isBusy"
                                @click="deleteAllMatching()">
                            <?php echo IconHelper::render('trash', ['alt' => 'Delete All']); ?>
                            <span class="ml-1"><?= __e('tags.list_delete_all') ?></span>
                        </button>
                    </div>
                </div>
            </div>
        </div>

        <!-- Desktop Table View -->
        <div class="table-container is-hidden-mobile">
            <table class="table is-striped is-hoverable is-fullwidth">
                <thead>
                    <tr>
                        <th class="has-text-centered" style="width: 3em;">
                            <input type="checkbox" :checked="allSelected()"
                                   @change="allSelected() ? markNone() : markAll()" />
                        </th>
                        <th class="has-text-centered" style="width: 6em;"><?= __e('tags.list_col_actions') ?></th>
                        <th><?= __e('tags.list_col_text') ?></th>
                        <th><?= __e('tags.list_col_comment') ?></th>
                        <th class="has-text-centered"><?php echo htmlspecialchars(
                            $itemsColLabel,
                            ENT_QUOTES,
                            'UTF-8'
                        ); ?></th>
                        <?php if ($isTextTag) : ?>
                        <th class="has-text-centered"><?= __e('tags.list_col_archived_with_tag') ?></th>
                        <?php endif; ?>
                    </tr>
                </thead>
                <tbody>
                    <template x-for="tag in tags" :key="tag.id">
                        <tr>
                            <td class="has-text-centered">
                                <input type="checkbox" class="markcheck"
                                       :checked="isSelected(tag.id)" @change="toggle(tag.id)" />
                            </td>
                            <td class="has-text-centered" style="white-space: nowrap;">
                                <div class="buttons are-small is-centered">
                                    <a :href="editUrl(tag)" class="button is-small is-ghost" title="Edit">
                                        <?php echo IconHelper::render(
                                            'file-pen',
                                            ['title' => 'Edit', 'alt' => 'Edit']
                                        ); ?>
                                    </a>
                                    <button type="button" class="button is-small is-ghost"
                                            :disabled="isBusy" @click="deleteOne(tag)" title="Delete">
                                        <?php echo IconHelper::render(
                                            'circle-minus',
                                            ['title' => 'Delete', 'alt' => 'Delete']
                                        ); ?>
                                    </button>
                                </div>
                            </td>
                            <td>
                                <span class="tag is-medium is-light" x-text="tag.text"></span>
                            </td>
                            <td class="has-text-grey" x-text="tag.comment"></td>
                            <td class="has-text-centered">
                                <template x-if="hasUsage(tag)">
                                    <a :href="tag.itemsUrl" class="tag is-link is-light"
                                       x-text="tag.usageCount"></a>
                                </template>
                                <template x-if="!hasUsage(tag)">
                                    <span class="tag is-light">0</span>
                                </template>
                            </td>
                            <?php if ($isTextTag) : ?>
                            <td class="has-text-centered">
                                <template x-if="hasArchived(tag)">
                                    <a :href="tag.archivedItemsUrl" class="tag is-link is-light"
                                       x-text="archivedCount(tag)"></a>
                                </template>
                                <template x-if="!hasArchived(tag)">
                                    <span class="tag is-light">0</span>
                                </template>
                            </td>
                            <?php endif; ?>
                        </tr>
                    </template>
                </tbody>
            </table>
        </div>

        <!-- Mobile Card View -->
        <div class="is-hidden-tablet">
            <template x-for="tag in tags" :key="tag.id">
                <div class="card mb-3">
                    <div class="card-content">
                        <div class="level is-mobile mb-2">
                            <div class="level-left">
                                <div class="level-item">
                                    <label class="checkbox">
                                        <input type="checkbox" class="markcheck"
                                               :checked="isSelected(tag.id)" @change="toggle(tag.id)" />
                                    </label>
                                </div>
                                <div class="level-item">
                                    <span class="tag is-medium is-info is-light" x-text="tag.text"></span>
                                </div>
                            </div>
                            <div class="level-right">
                                <div class="level-item">
                                    <div class="buttons are-small">
                                        <a :href="editUrl(tag)" class="button is-small is-info is-light">
                                            <?php echo IconHelper::render('file-pen', ['alt' => 'Edit']); ?>
                                        </a>
                                        <button type="button" class="button is-small is-danger is-light"
                                                :disabled="isBusy" @click="deleteOne(tag)">
                                            <?php echo IconHelper::render('circle-minus', ['alt' => 'Delete']); ?>
                                        </button>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <p class="has-text-grey mb-2" x-show="tag.comment" x-text="tag.comment"></p>

                        <div class="is-flex is-flex-wrap-wrap" style="gap: 0.5rem;">
                            <div class="tags has-addons mb-0">
                                <span class="tag is-dark"><?php echo htmlspecialchars(
                                    $itemLabel,
                                    ENT_QUOTES,
                                    'UTF-8'
                                ); ?></span>
                                <template x-if="hasUsage(tag)">
                                    <a :href="tag.itemsUrl" class="tag is-link" x-text="tag.usageCount"></a>
                                </template>
                                <template x-if="!hasUsage(tag)">
                                    <span class="tag is-light">0</span>
                                </template>
                            </div>
                            <?php if ($isTextTag) : ?>
                            <div class="tags has-addons mb-0">
                                <span class="tag is-dark"><?= __e('tags.label_archived') ?></span>
                                <template x-if="hasArchived(tag)">
                                    <a :href="tag.archivedItemsUrl" class="tag is-link"
                                       x-text="archivedCount(tag)"></a>
                                </template>
                                <template x-if="!hasArchived(tag)">
                                    <span class="tag is-light">0</span>
                                </template>
                            </div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </template>
        </div>

        <!-- Pagination -->
        <nav class="pagination is-centered mt-4" role="navigation" x-show="hasPages()" x-cloak>
            <button class="pagination-previous" :disabled="pagination.page <= 1"
                    @click="goToPage(pagination.page - 1)">
                <?= __e('tags.list_previous') ?>
            </button>
            <button class="pagination-next" :disabled="pagination.page >= pagination.total_pages"
                    @click="goToPage(pagination.page + 1)">
                <?= __e('tags.list_next') ?>
            </button>
            <ul class="pagination-list">
                <template x-for="p in pagination.total_pages" :key="p">
                    <li>
                        <button class="pagination-link" :class="{ 'is-current': p === pagination.page }"
                                @click="goToPage(p)" x-text="p"></button>
                    </li>
                </template>
            </ul>
        </nav>
    </div>
</div>
