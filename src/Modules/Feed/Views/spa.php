<?php

/**
 * Feed Manager SPA View - Alpine.js Single Page Application
 *
 * This view provides a reactive feed management interface with:
 * - Feed list with filtering, sorting, and pagination
 * - Article browsing with import functionality
 * - Create/edit feed forms
 * - Bulk actions
 *
 * PHP version 8.2
 *
 * @category Lwt
 * @package  Lwt\Views
 * @author   HugoFara <git@hugofara.net>
 * @license  Unlicense <http://unlicense.org/>
 * @link     https://hugofara.github.io/lwt/developer/api
 * @since    3.0.0
 */

declare(strict_types=1);

namespace Lwt\Views\Feed;

use Lwt\Shared\UI\Helpers\PageLayoutHelper;
use Lwt\Shared\UI\Helpers\IconHelper;
use Lwt\Shared\UI\Helpers\ConfigIsland;

$managerConfig = [
    'currentLanguageId' => $currentLanguageId ?? 0,
    'currentLanguageName' => $currentLanguageName ?? '',
];
?>
<?php ConfigIsland::render('feed-manager-config', $managerConfig); ?>

<!-- Notifications -->
<div
    x-data="feedNotifications()"
    class="notification-container"
    style="position: fixed; top: 1rem; right: 1rem; z-index: 100; max-width: 400px;"
>
    <template x-for="notification in notifications" :key="notification.id">
        <div class="notification" :class="getClass(notification.type)" x-transition>
            <button class="delete" @click="dismiss(notification.id)"></button>
            <span x-text="notification.message"></span>
        </div>
    </template>
</div>

<!-- Main Alpine.js container -->
<div id="feed-manager-app" x-data x-cloak>

    <!-- Loading state -->
    <div x-show="$store.feedManager.isLoading && $store.feedManager.viewMode === 'list'" class="has-text-centered py-6">
        <span class="icon is-large">
            <?php echo IconHelper::render('loader-2', ['class' => 'animate-spin', 'alt' => 'Loading']); ?>
        </span>
        <p class="mt-2"><?php echo __e('feed.spa_loading_feeds'); ?></p>
    </div>

    <!-- ===================================================================
         FEED LIST VIEW
         =================================================================== -->
    <template x-if="$store.feedManager.viewMode === 'list'">
        <div>
            <!-- Action buttons -->
            <?php
            echo PageLayoutHelper::buildActionCard([
                [
                    'url' => '#',
                    'label' => __('feed.spa_action_new_feed'),
                    'icon' => 'circle-plus',
                    'class' => 'is-primary',
                    'attrs' => '@click.prevent="$store.feedManager.showCreateForm()"'
                ],
                ['url' => '/feeds/new', 'label' => __('feed.spa_action_wizard'), 'icon' => 'wand-2'],
            ]);
            ?>

            <!-- Filter bar -->
            <div x-data="feedFilter()" class="box mb-4">
                <div class="columns is-multiline is-vcentered">
                    <!-- Sort -->
                    <div class="column is-narrow">
                        <div class="field has-addons">
                            <div class="control">
                                <span class="button is-static is-small">
                                    <?php echo __e('feed.spa_filter_sort'); ?>
                                </span>
                            </div>
                            <div class="control">
                                <div class="select is-small">
                                    <select :value="sort" @change="setSort($event.target.value)">
                                        <option value="1"><?php echo __e('feed.spa_sort_name_az'); ?></option>
                                        <option value="2"><?php echo __e('feed.spa_sort_updated_newest'); ?></option>
                                        <option value="3"><?php echo __e('feed.spa_sort_updated_oldest'); ?></option>
                                    </select>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Search -->
                    <div class="column">
                        <div class="field has-addons">
                            <div class="control is-expanded">
                                <input class="input is-small" type="text"
                                       placeholder="<?php echo __e('feed.spa_search_placeholder'); ?>"
                                       x-model="localQuery" @keyup.enter="search()">
                            </div>
                            <div class="control">
                                <button class="button is-small is-info" @click="search()">
                                    <?php echo IconHelper::render('search', ['class' => 'icon-sm']); ?>
                                </button>
                            </div>
                            <div class="control" x-show="localQuery">
                                <button class="button is-small" @click="clearSearch()">
                                    <?php echo IconHelper::render('x', ['class' => 'icon-sm']); ?>
                                </button>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Feed list -->
            <div x-data="feedList()" x-show="!store.isLoading">
                <!-- Bulk actions -->
                <div class="level mb-4" x-show="selectedCount > 0">
                    <div class="level-left">
                        <div class="level-item">
                            <span class="tag is-info is-medium"
                                  x-text="selectedCount + ' ' + $t('feed.spa_selected_label')"></span>
                        </div>
                        <div class="level-item">
                            <div class="buttons">
                                <button class="button is-small is-success" @click="loadSelected()">
                                    <?php echo IconHelper::render('refresh-cw', ['class' => 'icon-sm']); ?>
                                    <span x-text="$t('feed.spa_load_selected')"></span>
                                </button>
                                <button class="button is-small is-danger" @click="deleteSelected()">
                                    <?php echo IconHelper::render('trash-2', ['class' => 'icon-sm']); ?>
                                    <span x-text="$t('feed.spa_delete_selected')"></span>
                                </button>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Table -->
                <div class="table-container">
                    <table class="table is-fullwidth is-hoverable">
                        <thead>
                            <tr>
                                <th style="width: 40px;">
                                    <input type="checkbox" :checked="allSelected" @change="toggleAll()">
                                </th>
                                <th><?php echo __e('feed.spa_col_name'); ?></th>
                                <th class="has-text-centered"><?php echo __e('feed.spa_col_articles'); ?></th>
                                <th><?php echo __e('feed.spa_col_last_update'); ?></th>
                                <th style="width: 200px;"><?php echo __e('feed.spa_col_actions'); ?></th>
                            </tr>
                        </thead>
                        <tbody>
                            <template x-for="feed in feeds" :key="feed.id">
                                <tr>
                                    <td>
                                        <input type="checkbox" :checked="isSelected(feed.id)"
                                               @change="toggleSelection(feed.id)">
                                    </td>
                                    <td>
                                        <a href="#" @click.prevent="viewArticles(feed)" x-text="feed.name"
                                           class="has-text-weight-semibold"></a>
                                    </td>
                                    <td class="has-text-centered">
                                        <span class="tag" x-text="feed.articleCount"></span>
                                    </td>
                                    <td>
                                        <span class="is-size-7" x-text="feed.lastUpdate"></span>
                                    </td>
                                    <td>
                                        <div class="buttons are-small">
                                            <button class="button is-info" @click="loadFeed(feed)"
                                                    :title="$t('feed.spa_action_load_title')">
                                                <?php echo IconHelper::render('refresh-cw', ['class' => 'icon-sm']); ?>
                                            </button>
                                            <button class="button" @click="viewArticles(feed)"
                                                    :title="$t('feed.spa_action_view_title')">
                                                <?php echo IconHelper::render('list', ['class' => 'icon-sm']); ?>
                                            </button>
                                            <button class="button" @click="editFeed(feed)"
                                                    :title="$t('feed.spa_action_edit_title')">
                                                <?php echo IconHelper::render('pencil', ['class' => 'icon-sm']); ?>
                                            </button>
                                            <button class="button is-danger" @click="deleteFeed(feed)"
                                                    :title="$t('feed.spa_action_delete_title')">
                                                <?php echo IconHelper::render('trash-2', ['class' => 'icon-sm']); ?>
                                            </button>
                                        </div>
                                    </td>
                                </tr>
                            </template>
                        </tbody>
                    </table>
                </div>

                <!-- Empty state -->
                <div x-show="feeds.length === 0 && !store.isLoading" class="has-text-centered py-6">
                    <p class="is-size-5 has-text-grey"><?php echo __e('feed.spa_no_feeds'); ?></p>
                    <p class="is-size-7 has-text-grey"><?php echo __e('feed.spa_no_feeds_hint'); ?></p>
                </div>

                <!-- Pagination -->
                <nav x-show="pagination.total_pages > 1" class="pagination is-centered mt-4" role="navigation">
                    <button class="pagination-previous" :disabled="pagination.page <= 1"
                            @click="goToPage(pagination.page - 1)"
                            x-text="$t('feed.spa_pagination_previous')"></button>
                    <button class="pagination-next" :disabled="pagination.page >= pagination.total_pages"
                            @click="goToPage(pagination.page + 1)"
                            x-text="$t('feed.spa_pagination_next')"></button>
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
    </template>

    <!-- ===================================================================
         ARTICLES VIEW
         =================================================================== -->
    <template x-if="$store.feedManager.viewMode === 'articles'">
        <div x-data="articleList()">
            <!-- Header -->
            <div class="level mb-4">
                <div class="level-left">
                    <div class="level-item">
                        <button class="button" @click="backToList()">
                            <?php echo IconHelper::render('arrow-left', ['class' => 'icon-sm']); ?>
                            <span x-text="$t('feed.spa_back_to_feeds')"></span>
                        </button>
                    </div>
                    <div class="level-item">
                        <h2 class="title is-4"
                            x-text="feed ? feed.name : $t('feed.spa_articles_title_default')"></h2>
                    </div>
                </div>
            </div>

            <!-- Filter bar -->
            <div x-data="articleFilter()" class="box mb-4">
                <div class="columns is-vcentered">
                    <!-- Sort -->
                    <div class="column is-narrow">
                        <div class="field has-addons">
                            <div class="control">
                                <span class="button is-static is-small">Sort</span>
                            </div>
                            <div class="control">
                                <div class="select is-small">
                                    <select :value="sort" @change="setSort($event.target.value)">
                                        <option value="1">
                                            <?php echo __e('feed.spa_article_sort_date_newest'); ?>
                                        </option>
                                        <option value="2">
                                            <?php echo __e('feed.spa_article_sort_date_oldest'); ?>
                                        </option>
                                        <option value="3">
                                            <?php echo __e('feed.spa_article_sort_title_az'); ?>
                                        </option>
                                    </select>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Search -->
                    <div class="column">
                        <div class="field has-addons">
                            <div class="control is-expanded">
                                <input class="input is-small" type="text"
                                       placeholder="<?php echo __e('feed.spa_article_search_placeholder'); ?>"
                                       x-model="localQuery" @keyup.enter="search()">
                            </div>
                            <div class="control">
                                <button class="button is-small is-info" @click="search()">
                                    <?php echo IconHelper::render('search', ['class' => 'icon-sm']); ?>
                                </button>
                            </div>
                            <div class="control" x-show="localQuery">
                                <button class="button is-small" @click="clearSearch()">
                                    <?php echo IconHelper::render('x', ['class' => 'icon-sm']); ?>
                                </button>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Bulk actions -->
            <div class="level mb-4">
                <div class="level-left">
                    <div class="level-item" x-show="selectedCount > 0">
                        <span class="tag is-info is-medium"
                              x-text="selectedCount + ' ' + $t('feed.spa_selected_label')"></span>
                    </div>
                    <div class="level-item">
                        <div class="buttons">
                            <button class="button is-small is-success" @click="importSelected()"
                                    :disabled="selectedCount === 0 || store.isSubmitting">
                                <?php echo IconHelper::render('download', ['class' => 'icon-sm']); ?>
                                <span x-text="$t('feed.spa_import_selected')"></span>
                            </button>
                            <button class="button is-small is-danger" @click="deleteSelected()"
                                    :disabled="selectedCount === 0">
                                <?php echo IconHelper::render('trash-2', ['class' => 'icon-sm']); ?>
                                <span x-text="$t('feed.spa_delete_selected')"></span>
                            </button>
                            <button class="button is-small is-warning" @click="deleteAll()">
                                <?php echo IconHelper::render('trash', ['class' => 'icon-sm']); ?>
                                <span x-text="$t('feed.spa_delete_all')"></span>
                            </button>
                            <button class="button is-small" @click="resetErrors()"
                                    :title="$t('feed.spa_reset_errors_title')">
                                <?php echo IconHelper::render('refresh-ccw', ['class' => 'icon-sm']); ?>
                                <span x-text="$t('feed.spa_reset_errors')"></span>
                            </button>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Loading -->
            <div x-show="isLoading" class="has-text-centered py-6">
                <span class="icon is-large">
                    <?php echo IconHelper::render('loader-2', ['class' => 'animate-spin', 'alt' => 'Loading']); ?>
                </span>
                <p class="mt-2"><?php echo __e('feed.spa_loading_articles'); ?></p>
            </div>

            <!-- Table -->
            <div class="table-container" x-show="!isLoading">
                <table class="table is-fullwidth is-hoverable">
                    <thead>
                        <tr>
                            <th style="width: 40px;">
                                <input type="checkbox" :checked="allSelected" @change="toggleAll()">
                            </th>
                            <th><?php echo __e('feed.spa_col_title'); ?></th>
                            <th><?php echo __e('feed.spa_col_date'); ?></th>
                            <th class="has-text-centered"><?php echo __e('feed.spa_col_status'); ?></th>
                            <th style="width: 100px;"><?php echo __e('feed.spa_col_article_actions'); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <template x-for="article in articles" :key="article.id">
                            <tr>
                                <td>
                                    <input type="checkbox" :checked="isSelected(article.id)"
                                           @change="toggleSelection(article.id)">
                                </td>
                                <td>
                                    <a :href="article.link" target="_blank" x-text="article.title"
                                       class="has-text-weight-semibold"></a>
                                    <p
                                        class="is-size-7 has-text-grey"
                                        x-text="truncateText(article.description, 100)"
                                    ></p>
                                </td>
                                <td>
                                    <span class="is-size-7" x-text="article.date"></span>
                                </td>
                                <td class="has-text-centered">
                                    <span class="tag" :class="getStatusClass(article.status)"
                                          x-text="getStatusText(article.status)"></span>
                                </td>
                                <td>
                                    <div class="buttons are-small">
                                        <a :href="article.link" target="_blank" class="button"
                                           :title="$t('feed.spa_open_article_title')">
                                            <?php echo IconHelper::render('external-link', ['class' => 'icon-sm']); ?>
                                        </a>
                                        <template x-if="article.textId">
                                            <a :href="'/text/read/' + article.textId" class="button is-success"
                                               :title="$t('feed.spa_read_imported_title')">
                                                <?php echo IconHelper::render('book-open', ['class' => 'icon-sm']); ?>
                                            </a>
                                        </template>
                                    </div>
                                </td>
                            </tr>
                        </template>
                    </tbody>
                </table>
            </div>

            <!-- Empty state -->
            <div x-show="articles.length === 0 && !isLoading" class="has-text-centered py-6">
                <p class="is-size-5 has-text-grey"><?php echo __e('feed.spa_no_articles'); ?></p>
                <p class="is-size-7 has-text-grey"><?php echo __e('feed.spa_no_articles_hint'); ?></p>
            </div>

            <!-- Pagination -->
            <nav x-show="pagination.total_pages > 1" class="pagination is-centered mt-4" role="navigation">
                <button class="pagination-previous" :disabled="pagination.page <= 1"
                        @click="goToPage(pagination.page - 1)">Previous</button>
                <button class="pagination-next" :disabled="pagination.page >= pagination.total_pages"
                        @click="goToPage(pagination.page + 1)">Next</button>
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
    </template>

    <!-- ===================================================================
         EDIT-BEFORE-IMPORT VIEW

         Reached only for feeds carrying the `edit_text` option. The rows are
         extracted by POST /feeds/articles/extract and written back by
         POST /feeds/articles/create-texts; the tag and the max-texts limit
         are resolved server-side from the feed, so they are not editable.
         =================================================================== -->
    <template x-if="$store.feedManager.viewMode === 'edit-texts'">
        <div x-data="pendingTextList">
            <div class="level mb-4">
                <div class="level-left">
                    <div class="level-item">
                        <button class="button" @click="cancel()">
                            <?php echo IconHelper::render('arrow-left', ['class' => 'icon-sm']); ?>
                            <span x-text="$t('feed.spa_back')"></span>
                        </button>
                    </div>
                    <div class="level-item">
                        <h2 class="title is-4"><?php echo __e('feed.edit_texts_title'); ?></h2>
                    </div>
                </div>
                <div class="level-right">
                    <div class="level-item">
                        <span class="tag is-info is-medium"
                              x-text="selectedCount + ' ' + $t('feed.spa_selected_label')"></span>
                    </div>
                </div>
            </div>

            <template x-for="(pending, i) in texts" :key="i">
                <div class="box mb-4">
                    <div class="field is-horizontal">
                        <div class="field-label is-normal">
                            <label class="checkbox">
                                <input type="checkbox" :checked="pending.selected" @change="toggle(i)" />
                            </label>
                        </div>
                        <div class="field-body">
                            <div class="field">
                                <div class="control is-expanded">
                                    <input type="text" class="input" x-model="pending.title"
                                           maxlength="200"
                                           placeholder="<?php echo __e('feed.edit_text_form_title_placeholder'); ?>"
                                           :disabled="!pending.selected" />
                                </div>
                            </div>
                        </div>
                    </div>

                    <div x-show="pending.selected" x-transition x-cloak>
                        <div class="field is-horizontal">
                            <div class="field-label is-normal">
                                <label class="label"><?php echo __e('feed.edit_text_form_language'); ?></label>
                            </div>
                            <div class="field-body">
                                <div class="field">
                                    <div class="control">
                                        <div class="select is-fullwidth">
                                            <select :value="pending.language_id"
                                                    @change="setLanguage(i, $event.target.value)">
                                                <template x-for="language in languages" :key="language.id">
                                                    <option :value="language.id"
                                                            :selected="language.id === pending.language_id"
                                                            x-text="language.name"></option>
                                                </template>
                                            </select>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <div class="field is-horizontal">
                            <div class="field-label is-normal">
                                <label class="label"><?php echo __e('feed.edit_text_form_text'); ?></label>
                            </div>
                            <div class="field-body">
                                <div class="field">
                                    <div class="control is-expanded">
                                        <textarea class="textarea" rows="12" x-model="pending.text"></textarea>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <div class="field is-horizontal">
                            <div class="field-label is-normal">
                                <label class="label"><?php echo __e('feed.edit_text_form_source_uri'); ?></label>
                            </div>
                            <div class="field-body">
                                <div class="field">
                                    <div class="control">
                                        <input type="url" class="input" x-model="pending.source_uri"
                                               maxlength="1000" placeholder="https://..." />
                                    </div>
                                </div>
                            </div>
                        </div>

                        <div class="field is-horizontal">
                            <div class="field-label is-normal">
                                <label class="label"><?php echo __e('feed.edit_text_form_audio_uri'); ?></label>
                            </div>
                            <div class="field-body">
                                <div class="field">
                                    <div class="control">
                                        <input type="text" class="input" x-model="pending.audio_uri"
                                               maxlength="200"
                                               placeholder="<?php echo __e('feed.edit_text_form_audio_placeholder'); ?>"
                                        />
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div x-show="!pending.selected" x-transition class="has-text-grey-light is-italic">
                        <span class="icon is-small">
                            <?php
                            $hiddenAlt = __('feed.edit_text_form_hidden_alt');
                            echo IconHelper::render('eye-off', ['alt' => $hiddenAlt]);
                            ?>
                        </span>
                        <?php echo __e('feed.edit_text_form_deselected'); ?>
                    </div>
                </div>
            </template>

            <div class="buttons">
                <button class="button is-primary" @click="save()"
                        :disabled="selectedCount === 0 || isSubmitting">
                    <?php echo IconHelper::render('save', ['class' => 'icon-sm']); ?>
                    <span><?php echo __e('feed.edit_text_footer_save'); ?></span>
                </button>
                <button class="button" @click="cancel()">
                    <?php echo __e('feed.edit_text_footer_cancel'); ?>
                </button>
            </div>
        </div>
    </template>

    <!-- ===================================================================
         CREATE/EDIT FORM VIEW
         =================================================================== -->
    <template x-if="$store.feedManager.viewMode === 'create' || $store.feedManager.viewMode === 'edit'">
        <div x-data="feedForm()">
            <!-- Header -->
            <div class="level mb-4">
                <div class="level-left">
                    <div class="level-item">
                        <button class="button" @click="cancel()">
                            <?php echo IconHelper::render('arrow-left', ['class' => 'icon-sm']); ?>
                            <span x-text="$t('feed.spa_back')"></span>
                        </button>
                    </div>
                    <div class="level-item">
                        <h2 class="title is-4"
                            x-text="isCreate ? $t('feed.spa_create_new_feed') : $t('feed.spa_edit_feed')"></h2>
                    </div>
                </div>
            </div>

            <!-- Form -->
            <div class="box">
                <form @submit.prevent="submit()">
                    <!-- Language. Shown, not asked: the navbar picked it, and
                         an existing feed keeps the one it was saved with. -->
                    <div class="field">
                        <label class="label"><?php echo __e('feed.spa_form_language'); ?></label>
                        <div class="control">
                            <span class="tag is-info is-light" x-text="languageName"></span>
                        </div>
                    </div>

                    <!-- Name -->
                    <div class="field">
                        <label class="label"><?php echo __e('feed.spa_form_feed_name'); ?></label>
                        <div class="control">
                            <input class="input" type="text" x-model="feed.name" required
                                   placeholder="<?php echo __e('feed.spa_form_feed_name_placeholder'); ?>"
                                   maxlength="40">
                        </div>
                        <p class="help"><?php echo __e('feed.spa_form_feed_name_help'); ?></p>
                    </div>

                    <!-- Source URI -->
                    <div class="field">
                        <label class="label"><?php echo __e('feed.spa_form_feed_url'); ?></label>
                        <div class="control">
                            <input class="input" type="url" x-model="feed.sourceUri" required
                                   placeholder="https://example.com/feed.xml">
                        </div>
                        <p class="help"><?php echo __e('feed.spa_form_feed_url_help'); ?></p>
                    </div>

                    <!-- Article Section Tags -->
                    <div class="field">
                        <label class="label"><?php echo __e('feed.spa_form_article_section_tags'); ?></label>
                        <div class="control">
                            <input class="input" type="text" x-model="feed.articleSectionTags"
                                   placeholder="//article | //div[@class='content']">
                        </div>
                        <p class="help"><?php echo __e('feed.spa_form_article_section_help'); ?></p>
                    </div>

                    <!-- Filter Tags -->
                    <div class="field">
                        <label class="label"><?php echo __e('feed.spa_form_filter_tags'); ?></label>
                        <div class="control">
                            <input class="input" type="text" x-model="feed.filterTags"
                                   placeholder="//nav | //aside | //footer">
                        </div>
                        <p class="help"><?php echo __e('feed.spa_form_filter_tags_help'); ?></p>
                    </div>

                    <!-- Options -->
                    <div class="field">
                        <label class="label"><?php echo __e('feed.spa_form_options'); ?></label>
                        <div class="control">
                            <input class="input" type="text" x-model="feed.options"
                                   placeholder="edit_text=1,autoupdate=2h,max_links=50">
                        </div>
                        <p class="help">
                            <?php echo __e('feed.spa_form_options_help'); ?>
                        </p>
                    </div>

                    <!-- Submit -->
                    <div class="field">
                        <div class="control">
                            <div class="buttons">
                                <button type="submit" class="button is-primary" :disabled="isSubmitting">
                                    <span class="icon" x-show="isSubmitting">
                                        <?php
                                        echo IconHelper::render(
                                            'loader-2',
                                            ['class' => 'animate-spin icon-sm']
                                        );
                                        ?>
                                    </span>
                                    <span
                                        x-text="isCreate
                                            ? $t('feed.spa_form_create_feed')
                                            : $t('feed.spa_form_update_feed')"
                                    ></span>
                                </button>
                                <button type="button" class="button" @click="cancel()"
                                        x-text="$t('feed.spa_form_cancel')"></button>
                            </div>
                        </div>
                    </div>
                </form>
            </div>

            <!-- Tip for advanced setup -->
            <div class="notification is-info is-light mt-4">
                <p>
                    <strong><?php echo __e('feed.spa_tip'); ?></strong>
                    <?php echo __e('feed.spa_tip_body'); ?>
                    <a href="/feeds/new"><?php echo __e('feed.spa_tip_link'); ?></a>.
                </p>
            </div>
        </div>
    </template>

</div>
