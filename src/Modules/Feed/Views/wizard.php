<?php

/**
 * Feed Wizard - one page, four steps.
 *
 * The wizard was four views (`wizard_step1.php` .. `wizard_step4.php`), each
 * rendered by a POST to `/feeds/wizard` and each rebuilding its state from
 * `$_SESSION`. The steps are panels of this page now, mounted as the user
 * reaches them, with the state in the Alpine store and the feed and article
 * reads behind `/api/v1/feeds/wizard/*` (#262, #266).
 *
 * Variables expected:
 * - $languages: array of language data [{id, name}, ...]
 * - $curatedFeeds: array of curated feed groups
 * - $currentLanguageId / $currentLanguageName: the navbar's language
 * - $editFeedId: int|null feed being re-run through the wizard
 *
 * PHP version 8.2
 *
 * @category Lwt
 * @package  Lwt\Views
 * @author   HugoFara <git@hugofara.net>
 * @license  Unlicense <http://unlicense.org/>
 * @link     https://hugofara.github.io/lwt/developer/api
 * @since    3.5.1
 */

declare(strict_types=1);

namespace Lwt\Views\Feed;

use Lwt\Shared\UI\Helpers\IconHelper;
use Lwt\Shared\UI\Helpers\ConfigIsland;

/** @var array<int, array{id: int, name: string}> $languages */
$languages = $languages ?? [];

$wizardConfig = [
    'languages' => array_map(
        function (array $lang): array {
            return ['id' => $lang['id'], 'name' => $lang['name']];
        },
        $languages
    ),
    'curatedFeeds' => $curatedFeeds ?? [],
    'currentLanguageId' => $currentLanguageId ?? 0,
    'currentLanguageName' => $currentLanguageName ?? '',
    'editFeedId' => $editFeedId ?? null,
];

/**
 * Render the four-step progress indicator.
 *
 * @param int $current The step being shown, 1-4
 *
 * @return string The indicator's markup
 */
$renderSteps = function (int $current): string {
    $titles = [
        1 => __('feed.wizard.common.step_feed_url'),
        2 => __('feed.wizard.common.step_select_article'),
        3 => __('feed.wizard.common.step_filter_text'),
        4 => __('feed.wizard.common.step_save'),
    ];

    $html = '<div class="steps is-small mb-4">';
    foreach ($titles as $number => $title) {
        $state = 'step-item';
        if ($number < $current) {
            $state .= ' is-completed is-success';
        } elseif ($number === $current) {
            $state .= ' is-active is-primary';
        }
        $html .= '<div class="' . $state . '">'
            . '<div class="step-marker">' . $number . '</div>'
            . '<div class="step-details"><p class="step-title">'
            . htmlspecialchars($title, ENT_QUOTES, 'UTF-8')
            . '</p></div></div>';
    }

    return $html . '</div>';
};
?>
<?php ConfigIsland::render('feed-wizard-config', $wizardConfig); ?>

<div x-data="feedWizard" x-cloak>

    <!-- A feed that could not be reopened -->
    <div x-show="hasError()" class="notification is-danger is-light" x-cloak>
        <span x-text="error"></span>
    </div>

    <!-- ===================== STEP 1: How to add a feed ===================== -->
    <template x-if="isStep1">
    <div x-data="feedWizardStep1">
        <?php echo $renderSteps(1); ?>

        <!-- Tab Navigation -->
        <div class="tabs is-boxed is-medium mb-0">
            <ul>
                <li :class="{ 'is-active': activeTab === 'browse' }">
                    <a @click.prevent="activeTab = 'browse'">
                        <span class="icon is-small">
                            <?php echo IconHelper::render('library', ['alt' => 'Browse']); ?>
                        </span>
                        <span><?php echo __e('feed.wizard.step1.tab_browse'); ?></span>
                    </a>
                </li>
                <li :class="{ 'is-active': activeTab === 'wizard' }">
                    <a @click.prevent="activeTab = 'wizard'">
                        <span class="icon is-small">
                            <?php echo IconHelper::render('wand-2', ['alt' => 'Wizard']); ?>
                        </span>
                        <span><?php echo __e('feed.wizard.step1.tab_wizard'); ?></span>
                    </a>
                </li>
                <li :class="{ 'is-active': activeTab === 'manual' }">
                    <a @click.prevent="activeTab = 'manual'">
                        <span class="icon is-small">
                            <?php echo IconHelper::render('settings', ['alt' => 'Manual']); ?>
                        </span>
                        <span><?php echo __e('feed.wizard.step1.tab_manual'); ?></span>
                    </a>
                </li>
            </ul>
        </div>

        <!-- ---------- TAB 1: Browse Curated Sources ---------- -->
        <div class="box" x-show="activeTab === 'browse'" x-transition>
            <p class="mb-4 has-text-grey">
                <?php echo __e('feed.wizard.step1.browse_intro'); ?>
            </p>

            <!-- Language filter -->
            <div class="field is-grouped mb-4">
                <div class="control">
                    <div class="select">
                        <select x-model="browseLanguageFilter">
                            <option value=""><?php echo __e('feed.wizard.step1.browse_all_languages'); ?></option>
                            <template x-for="group in curatedFeeds" :key="group.language">
                                <option :value="group.language" x-text="group.languageName"></option>
                            </template>
                        </select>
                    </div>
                </div>
                <div class="control is-expanded">
                    <input class="input" type="search"
                           placeholder="<?php echo __e('feed.wizard.step1.browse_search_placeholder'); ?>"
                           x-model="browseSearch" />
                </div>
            </div>

            <!-- Feed cards grouped by language -->
            <template x-if="filteredCuratedFeeds.length === 0">
                <div class="notification is-light">
                    <?php echo __e('feed.wizard.step1.browse_no_match'); ?>
                </div>
            </template>

            <template x-for="group in filteredCuratedFeeds" :key="group.language">
                <div class="mb-5">
                    <h3 class="title is-5 mb-3" x-text="group.languageName"></h3>
                    <div class="columns is-multiline">
                        <template x-for="source in group.sources" :key="source.url">
                            <div class="column is-half-tablet is-one-third-desktop">
                                <label class="card" style="display: block; cursor: pointer;">
                                    <div class="card-content p-4">
                                        <div class="is-flex is-align-items-center mb-2">
                                            <input type="checkbox"
                                                   class="mr-2"
                                                   x-model="selectedUrls"
                                                   :value="source.url" />
                                            <p class="title is-6 mb-0" x-text="source.name"></p>
                                        </div>
                                        <div class="tags mb-2">
                                            <span class="tag is-info is-light" x-text="source.category"></span>
                                            <span class="tag is-light" x-text="source.level"></span>
                                        </div>
                                        <p class="is-size-7 has-text-grey is-clipped" x-text="source.url"
                                           style="max-height: 1.5em; overflow: hidden; text-overflow: ellipsis;"></p>
                                    </div>
                                </label>
                            </div>
                        </template>
                    </div>
                </div>
            </template>

            <!-- Add selected feeds button -->
            <div class="field is-grouped is-grouped-right mt-4">
                <div class="control">
                    <button type="button" class="button is-primary"
                            :disabled="saving" :class="{ 'is-loading': saving }"
                            @click="addSelectedFeeds()">
                        <span class="icon is-small">
                            <?php echo IconHelper::render('plus', ['alt' => 'Add']); ?>
                        </span>
                        <span><?php echo __e('feed.wizard.step1.browse_add_selected'); ?></span>
                    </button>
                </div>
            </div>
        </div>

        <!-- ---------- TAB 2: Wizard (Enter Feed URL) ---------- -->
        <div class="box" x-show="activeTab === 'wizard'" x-transition>
            <p class="mb-4 has-text-grey">
                <?php echo __e('feed.wizard.step1.wizard_intro'); ?>
            </p>

            <!-- Reads the feed through /api/v1/feeds/wizard/preview (#262). -->
            <form class="validate" @submit.prevent="openWizard()">
                <div class="field">
                    <label class="label" for="rss_url">
                        <?php echo __e('feed.wizard.step1.feed_uri_label'); ?>
                        <span class="has-text-danger"
                              title="<?php echo __e('feed.wizard.step1.required'); ?>">*</span>
                    </label>
                    <div class="control">
                        <input class="input notempty"
                               type="url"
                               name="rss_url"
                               id="rss_url"
                               placeholder="https://example.com/feed.xml"
                               x-model="rssUrl"
                               :class="{ 'is-success': isValidUrl, 'is-danger': rssUrl && !isValidUrl }"
                               required />
                    </div>
                    <p class="help"><?php echo __e('feed.wizard.step1.feed_uri_help'); ?></p>
                </div>

                <div class="field is-grouped is-grouped-right mt-5">
                    <div class="control">
                        <button type="button" class="button is-danger is-outlined" @click="cancel">
                            <?php echo __e('feed.wizard.step1.cancel'); ?>
                        </button>
                    </div>
                    <div class="control">
                        <button type="submit" class="button is-primary"
                                :disabled="!isValidUrl || saving"
                                :class="{ 'is-loading': saving }">
                            <span x-text="nextLabel()"><?php echo __e('feed.wizard.step1.next'); ?></span>
                            <span class="icon is-small">
                                <?php
                                echo IconHelper::render('arrow-right', ['alt' => __('feed.wizard.step1.next')]);
                                ?>
                            </span>
                        </button>
                    </div>
                </div>
            </form>
        </div>

        <!-- ---------- TAB 3: Manual Setup ---------- -->
        <div class="box" x-show="activeTab === 'manual'" x-transition>
            <p class="mb-4 has-text-grey">
                <?php echo __e('feed.wizard.step1.manual_intro'); ?>
            </p>

            <?php ConfigIsland::render('feed-form-config', [
                'editText' => true,
                'autoUpdate' => false,
                'autoUpdateValue' => '',
                'autoUpdateUnit' => 'h',
                'maxLinks' => false,
                'maxLinksValue' => '',
                'charset' => false,
                'charsetValue' => '',
                'maxTexts' => false,
                'maxTextsValue' => '',
                'tag' => false,
                'tagValue' => '',
                'articleSource' => false,
                'articleSourceValue' => '',
            ]); ?>
            <!-- Saves through POST /api/v1/feeds (#262), so no action of its own. -->
            <form class="validate"
                  x-data="feedForm"
                  @submit="handleSubmit($event)">

                <div x-show="hasSaveError()" class="notification is-danger" x-cloak>
                    <span x-text="saveError"></span>
                </div>

                <div class="box">
                    <input type="hidden" name="NfLgID" x-bind:value="currentLanguageId" />

                    <!-- Name -->
                    <div class="field">
                        <label class="label" for="manual_NfName">
                            <?php echo __e('feed.wizard.step1.name_label'); ?>
                            <span class="has-text-danger"
                                  title="<?php echo __e('feed.wizard.step1.required'); ?>">*</span>
                        </label>
                        <div class="control">
                            <input class="input notempty"
                                   type="text"
                                   name="NfName"
                                   id="manual_NfName"
                                   placeholder="<?php echo __e('feed.wizard.step1.name_placeholder'); ?>"
                                   required />
                        </div>
                    </div>

                    <!-- Newsfeed URL -->
                    <div class="field">
                        <label class="label" for="manual_NfSourceURI">
                            <?php echo __e('feed.wizard.step1.url_label'); ?>
                            <span class="has-text-danger"
                                  title="<?php echo __e('feed.wizard.step1.required'); ?>">*</span>
                        </label>
                        <div class="control">
                            <input class="input notempty"
                                   type="url"
                                   name="NfSourceURI"
                                   id="manual_NfSourceURI"
                                   placeholder="<?php echo __e('feed.wizard.step1.url_placeholder'); ?>"
                                   required />
                        </div>
                    </div>

                    <!-- Article Section -->
                    <div class="field">
                        <label class="label" for="manual_NfArticleSectionTags">
                            <?php echo __e('feed.wizard.step1.article_section_label'); ?>
                            <span class="has-text-danger"
                                  title="<?php echo __e('feed.wizard.step1.required'); ?>">*</span>
                        </label>
                        <div class="control">
                            <input class="input notempty"
                                   type="text"
                                   name="NfArticleSectionTags"
                                   id="manual_NfArticleSectionTags"
                                   placeholder="<?php
                                        echo __e('feed.wizard.step1.article_section_placeholder');
                                    ?>"
                                   required />
                        </div>
                    </div>

                    <!-- Filter Tags -->
                    <div class="field">
                        <label class="label" for="manual_NfFilterTags">
                            <?php echo __e('feed.wizard.step1.filter_tags_label'); ?>
                        </label>
                        <div class="control">
                            <input class="input"
                                   type="text"
                                   name="NfFilterTags"
                                   id="manual_NfFilterTags"
                                   placeholder="<?php echo __e('feed.wizard.step1.filter_tags_placeholder'); ?>" />
                        </div>
                    </div>

                    <!-- Options Section -->
                    <div class="field">
                        <label class="label"><?php echo __e('feed.wizard.step1.options_label'); ?></label>
                        <div class="box" style="background-color: var(--bulma-scheme-main-bis);">
                            <div class="columns is-multiline">
                                <!-- Edit Text -->
                                <div class="column is-half">
                                    <label class="checkbox">
                                        <input type="checkbox" name="edit_text" x-model="editText" checked />
                                        <strong><?php echo __e('feed.wizard.step1.opt_review'); ?></strong>
                                    </label>
                                    <p class="help"><?php echo __e('feed.wizard.step1.opt_review_help'); ?></p>
                                </div>

                                <!-- Auto Update -->
                                <div class="column is-half">
                                    <label class="checkbox">
                                        <input type="checkbox" name="c_autoupdate" x-model="autoUpdate" />
                                        <strong><?php echo __e('feed.wizard.step1.opt_auto_refresh'); ?></strong>
                                    </label>
                                    <div class="field has-addons mt-2" x-show="autoUpdate" x-transition>
                                        <div class="control">
                                            <input class="input is-small posintnumber"
                                                   :class="autoUpdate ? 'notempty' : ''"
                                                   type="number"
                                                   min="1"
                                                   name="autoupdate"
                                                   data_info="Auto Update Interval"
                                                   x-model="autoUpdateValue"
                                                   style="width: 80px;"
                                                   :disabled="!autoUpdate" />
                                        </div>
                                        <div class="control">
                                            <div class="select is-small">
                                                <select name="autoupdate_unit" x-model="autoUpdateUnit"
                                                    :disabled="!autoUpdate">
                                                    <option value="h">
                                                        <?php echo __e('feed.wizard.step1.opt_unit_hours'); ?>
                                                    </option>
                                                    <option value="d">
                                                        <?php echo __e('feed.wizard.step1.opt_unit_days'); ?>
                                                    </option>
                                                    <option value="w">
                                                        <?php echo __e('feed.wizard.step1.opt_unit_weeks'); ?>
                                                    </option>
                                                </select>
                                            </div>
                                        </div>
                                    </div>
                                </div>

                                <!-- Max Links -->
                                <div class="column is-half">
                                    <label class="checkbox">
                                        <input type="checkbox" name="c_max_links" x-model="maxLinks" />
                                        <strong><?php echo __e('feed.wizard.step1.opt_limit_articles'); ?></strong>
                                    </label>
                                    <p class="help">
                                        <?php echo __e('feed.wizard.step1.opt_limit_articles_help'); ?>
                                    </p>
                                    <div class="control mt-2" x-show="maxLinks" x-transition>
                                        <input class="input is-small posintnumber maxint_300"
                                               :class="maxLinks ? 'notempty' : ''"
                                               type="number"
                                               min="1"
                                               max="300"
                                               name="max_links"
                                               data_info="Max. Links"
                                               x-model="maxLinksValue"
                                               style="width: 100px;"
                                               :disabled="!maxLinks" />
                                    </div>
                                </div>

                                <!-- Charset -->
                                <div class="column is-half">
                                    <label class="checkbox">
                                        <input type="checkbox" name="c_charset" x-model="charset" />
                                        <strong><?php echo __e('feed.wizard.step1.opt_charset'); ?></strong>
                                    </label>
                                    <p class="help"><?php echo __e('feed.wizard.step1.opt_charset_help'); ?></p>
                                    <div class="control mt-2" x-show="charset" x-transition>
                                        <input class="input is-small"
                                               :class="charset ? 'notempty' : ''"
                                               type="text"
                                               name="charset"
                                               data_info="Charset"
                                               x-model="charsetValue"
                                               placeholder="e.g., UTF-8"
                                               :disabled="!charset" />
                                    </div>
                                </div>

                                <!-- Max Texts -->
                                <div class="column is-half">
                                    <label class="checkbox">
                                        <input type="checkbox" name="c_max_texts" x-model="maxTexts" />
                                        <strong><?php echo __e('feed.wizard.step1.opt_limit_texts'); ?></strong>
                                    </label>
                                    <p class="help"><?php echo __e('feed.wizard.step1.opt_limit_texts_help'); ?></p>
                                    <div class="control mt-2" x-show="maxTexts" x-transition>
                                        <input class="input is-small posintnumber maxint_30"
                                               :class="maxTexts ? 'notempty' : ''"
                                               type="number"
                                               min="1"
                                               max="30"
                                               name="max_texts"
                                               data_info="Max. Texts"
                                               x-model="maxTextsValue"
                                               style="width: 100px;"
                                               :disabled="!maxTexts" />
                                    </div>
                                </div>

                                <!-- Tag -->
                                <div class="column is-half">
                                    <label class="checkbox">
                                        <input type="checkbox" name="c_tag" x-model="tag" />
                                        <strong><?php echo __e('feed.wizard.step1.opt_auto_tag'); ?></strong>
                                    </label>
                                    <p class="help"><?php echo __e('feed.wizard.step1.opt_auto_tag_help'); ?></p>
                                    <div class="control mt-2" x-show="tag" x-transition>
                                        <input class="input is-small"
                                               :class="tag ? 'notempty' : ''"
                                               type="text"
                                               name="tag"
                                               data_info="Tag"
                                               x-model="tagValue"
                                               placeholder="Tag name"
                                               :disabled="!tag" />
                                    </div>
                                </div>

                                <!-- Article Source -->
                                <div class="column is-full">
                                    <label class="checkbox">
                                        <input type="checkbox" name="c_article_source" x-model="articleSource" />
                                        <strong><?php echo __e('feed.wizard.step1.opt_article_source'); ?></strong>
                                    </label>
                                    <div class="control mt-2" x-show="articleSource" x-transition>
                                        <input class="input is-small"
                                               :class="articleSource ? 'notempty' : ''"
                                               type="text"
                                               name="article_source"
                                               data_info="Article Source"
                                               x-model="articleSourceValue"
                                               placeholder="Source identifier"
                                               :disabled="!articleSource" />
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="field is-grouped is-grouped-right">
                    <div class="control">
                        <button type="button" class="button is-light" @click="cancel">
                            <?php echo __e('feed.wizard.step1.cancel'); ?>
                        </button>
                    </div>
                    <div class="control">
                        <button type="submit" class="button is-primary"
                                :disabled="saving" :class="{ 'is-loading': saving }">
                            <span class="icon is-small">
                                <?php echo IconHelper::render('save', ['alt' => __('feed.wizard.step1.save')]); ?>
                            </span>
                            <span><?php echo __e('feed.wizard.step1.save'); ?></span>
                        </button>
                    </div>
                </div>
            </form>
        </div>

        <!-- Whatever the API said went wrong, for either tab -->
        <div x-show="hasSaveError()" class="notification is-danger" x-cloak>
            <span x-text="saveError"></span>
        </div>
    </div>
    </template>

    <!-- ===================== STEP 2: Select Article Text ===================== -->
    <template x-if="isStep2">
    <div x-data="feedWizardStep2">
        <?php include __DIR__ . '/wizard_picker_panel.php'; ?>

        <div id="lwt_container" x-show="!isMinimized">
            <?php echo $renderSteps(2); ?>

            <!-- Selected elements list -->
            <div class="box mb-4" style="background-color: var(--bulma-scheme-main-bis);">
                <p class="is-size-7 has-text-grey mb-2">
                    <?php echo __e('feed.wizard.step2.selected_elements'); ?>
                </p>
                <ol id="lwt_sel" class="ml-4">
                    <template x-for="selector in articleSelectors" :key="selector.id">
                        <li class="is-flex is-align-items-center mb-1"
                            :class="{ 'has-text-weight-bold': selector.isHighlighted }">
                            <span class="is-family-monospace is-size-7" x-text="selector.xpath"
                                  @click="toggleSelectorHighlight(selector.id)"
                                  style="cursor: pointer;"></span>
                            <button type="button" class="delete is-small ml-2"
                                    @click="deleteSelector(selector.id)"></button>
                        </li>
                    </template>
                </ol>
            </div>

            <!-- Feed Info -->
            <div class="box">
                <div class="field is-horizontal">
                    <div class="field-label is-normal">
                        <label class="label"><?php echo __e('feed.wizard.common.name'); ?></label>
                    </div>
                    <div class="field-body">
                        <div class="field has-addons">
                            <div class="control is-expanded">
                                <input class="input notempty"
                                       type="text"
                                       name="NfName"
                                       x-model="feedName"
                                       required />
                            </div>
                            <div class="control">
                                <span class="icon has-text-danger"
                                      title="<?php echo __e('feed.wizard.common.field_required'); ?>">
                                    <?php
                                    echo IconHelper::render(
                                        'asterisk',
                                        ['alt' => __('feed.wizard.common.required')]
                                    );
                                    ?>
                                </span>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="field is-horizontal">
                    <div class="field-label is-normal">
                        <label class="label"><?php echo __e('feed.wizard.common.newsfeed_url'); ?></label>
                    </div>
                    <div class="field-body">
                        <div class="field">
                            <p class="control">
                                <span class="has-text-grey-dark is-size-7" x-text="rssUrl"></span>
                            </p>
                        </div>
                    </div>
                </div>

                <div class="field is-horizontal">
                    <div class="field-label is-normal">
                        <label class="label"><?php echo __e('feed.wizard.common.article_source'); ?></label>
                    </div>
                    <div class="field-body">
                        <div class="field has-addons">
                            <div class="control">
                                <div class="select">
                                    <select name="NfArticleSection" x-model="articleSource"
                                            @change="changeArticleSection">
                                        <option value=""><?php echo __e('feed.wizard.common.webpage_link'); ?></option>
                                        <template x-for="source in articleSources" :key="source">
                                            <option :value="source" x-text="source"></option>
                                        </template>
                                    </select>
                                </div>
                            </div>
                            <div class="control">
                                <span class="tag is-info is-light">
                                    <?php echo __e('feed.wizard.common.detected'); ?>
                                    <span class="ml-1" x-text="detectedFeed"></span>
                                </span>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Wizard Controls -->
        <nav class="level wizard-controls mt-4">
            <div class="level-left">
                <div class="level-item">
                    <button type="button" class="button is-danger is-outlined" @click="cancel">
                        <?php echo __e('feed.wizard.common.cancel'); ?>
                    </button>
                </div>
            </div>

            <div class="level-item">
                <div class="field has-addons">
                    <div class="control">
                        <div class="select">
                            <select name="selected_feed" x-model="selectedFeedIndex" @change="changeSelectedFeed">
                                <template x-for="item in feedItems" :key="item.index">
                                    <option :value="item.index"
                                            :title="item.title"
                                            x-text="(item.hasHtml ? '► ' : '- ') +
                                                    (item.index + 1) + ' ' +
                                                    item.hostStatus + ' host: ' + item.host">
                                    </option>
                                </template>
                            </select>
                        </div>
                    </div>
                    <template x-if="multipleHosts">
                        <div class="control">
                            <div class="select">
                                <select name="host_status" x-model="hostStatus" @change="changeHostStatus">
                                    <option value="-">-</option>
                                    <option value="☆">☆</option>
                                    <option value="★">★</option>
                                </select>
                            </div>
                        </div>
                    </template>
                </div>
            </div>

            <div class="level-item actions-cell">
                <div class="field has-addons">
                    <div class="control">
                        <div class="select">
                            <select name="mark_action" @change="handleMarkActionChange">
                                <option value=""><?php echo __e('feed.wizard.common.click_on_text'); ?></option>
                                <template x-for="option in markActionOptions" :key="option.value">
                                    <option :value="option.value" x-text="option.label"></option>
                                </template>
                            </select>
                        </div>
                    </div>
                    <div class="control">
                        <button type="button" class="button is-info"
                                :disabled="!currentXPath"
                                @click="getSelection"><?php echo __e('feed.wizard.common.get'); ?></button>
                    </div>
                    <div class="control">
                        <button type="button" class="button" @click="settingsOpen = true">
                            <?php
                            $settingsLabel = __('feed.wizard.common.settings');
                            echo IconHelper::render(
                                'settings',
                                ['title' => $settingsLabel, 'alt' => $settingsLabel]
                            );
                            ?>
                        </button>
                    </div>
                </div>
            </div>

            <div class="level-right">
                <div class="level-item">
                    <div class="buttons">
                        <button type="button" class="button" @click="goBack">
                            <span class="icon is-small">
                                <?php
                                echo IconHelper::render('arrow-left', ['alt' => __('feed.wizard.common.back')]);
                                ?>
                            </span>
                            <span><?php echo __e('feed.wizard.common.back'); ?></span>
                        </button>
                        <button type="button" class="button is-primary"
                                :disabled="!canProceed"
                                @click="goNext">
                            <span><?php echo __e('feed.wizard.common.next'); ?></span>
                            <span class="icon is-small">
                                <?php
                                echo IconHelper::render('arrow-right', ['alt' => __('feed.wizard.common.next')]);
                                ?>
                            </span>
                        </button>
                    </div>
                </div>
            </div>
        </nav>

        <button type="button" class="button is-small wizard-minmax mt-2" @click="toggleMinimize">
            <span class="icon is-small">
                <?php echo IconHelper::render('minimize-2', ['alt' => __('feed.wizard.common.min_max')]); ?>
            </span>
            <span><?php echo __e('feed.wizard.common.min_max'); ?></span>
        </button>
    </div>
    </template>

    <!-- ===================== STEP 3: Filter Text ===================== -->
    <template x-if="isStep3">
    <div x-data="feedWizardStep3">
        <?php include __DIR__ . '/wizard_picker_panel.php'; ?>

        <div id="lwt_container" x-show="!isMinimized">
            <?php echo $renderSteps(3); ?>

            <!-- Elements to filter out -->
            <div class="box mb-4" style="background-color: var(--bulma-scheme-main-bis);">
                <p class="is-size-7 has-text-grey mb-2">
                    <?php echo __e('feed.wizard.step3.elements_to_filter'); ?>
                </p>
                <ol id="lwt_sel" class="ml-4">
                    <template x-for="selector in filterSelectors" :key="selector.id">
                        <li class="is-flex is-align-items-center mb-1"
                            :class="{ 'has-text-weight-bold': selector.isHighlighted }">
                            <span class="is-family-monospace is-size-7" x-text="selector.xpath"
                                  @click="toggleSelectorHighlight(selector.id)"
                                  style="cursor: pointer;"></span>
                            <button type="button" class="delete is-small ml-2"
                                    @click="deleteSelector(selector.id)"></button>
                        </li>
                    </template>
                </ol>
            </div>

            <!-- Feed Info -->
            <div class="box">
                <div class="field is-horizontal">
                    <div class="field-label is-normal">
                        <label class="label"><?php echo __e('feed.wizard.common.newsfeed_url'); ?></label>
                    </div>
                    <div class="field-body">
                        <div class="field">
                            <p class="control">
                                <span class="has-text-grey-dark is-size-7" x-text="rssUrl"></span>
                            </p>
                        </div>
                    </div>
                </div>

                <div class="field is-horizontal">
                    <div class="field-label is-normal">
                        <label class="label"><?php echo __e('feed.wizard.common.article_source'); ?></label>
                    </div>
                    <div class="field-body">
                        <div class="field">
                            <p class="control">
                                <span class="tag is-light"
                                      x-text="feedText"><?php
                                          echo __e('feed.wizard.common.webpage_link');
                                        ?></span>
                            </p>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Wizard Controls -->
        <nav class="level wizard-controls mt-4">
            <div class="level-left">
                <div class="level-item">
                    <button type="button" class="button is-danger is-outlined" @click="cancel">
                        <?php echo __e('feed.wizard.common.cancel'); ?>
                    </button>
                </div>
            </div>

            <div class="level-item">
                <div class="field has-addons">
                    <div class="control">
                        <div class="select">
                            <select name="selected_feed" x-model="selectedFeedIndex" @change="changeSelectedFeed">
                                <template x-for="item in feedItems" :key="item.index">
                                    <option :value="item.index"
                                            :title="item.title"
                                            x-text="(item.hasHtml ? '► ' : '- ') +
                                                    (item.index + 1) + ' ' +
                                                    item.hostStatus + ' host: ' + item.host">
                                    </option>
                                </template>
                            </select>
                        </div>
                    </div>
                    <template x-if="multipleHosts">
                        <div class="control">
                            <div class="select">
                                <select name="host_status2" x-model="hostStatus" @change="changeHostStatus">
                                    <option value="-">-</option>
                                    <option value="☆">☆</option>
                                    <option value="★">★</option>
                                </select>
                            </div>
                        </div>
                    </template>
                </div>
            </div>

            <div class="level-item actions-cell">
                <div class="field has-addons">
                    <div class="control">
                        <div class="select">
                            <select name="mark_action" @change="handleMarkActionChange">
                                <option value=""><?php echo __e('feed.wizard.common.click_on_text'); ?></option>
                                <template x-for="option in markActionOptions" :key="option.value">
                                    <option :value="option.value" x-text="option.label"></option>
                                </template>
                            </select>
                        </div>
                    </div>
                    <div class="control">
                        <button type="button" class="button is-warning"
                                :disabled="!currentXPath"
                                @click="filterSelection"><?php echo __e('feed.wizard.step3.filter'); ?></button>
                    </div>
                    <div class="control">
                        <button type="button" class="button" @click="settingsOpen = true">
                            <?php
                            $settingsLabel = __('feed.wizard.common.settings');
                            echo IconHelper::render(
                                'settings',
                                ['title' => $settingsLabel, 'alt' => $settingsLabel]
                            );
                            ?>
                        </button>
                    </div>
                </div>
            </div>

            <div class="level-right">
                <div class="level-item">
                    <div class="buttons">
                        <button type="button" class="button" @click="goBack">
                            <span class="icon is-small">
                                <?php
                                echo IconHelper::render('arrow-left', ['alt' => __('feed.wizard.common.back')]);
                                ?>
                            </span>
                            <span><?php echo __e('feed.wizard.common.back'); ?></span>
                        </button>
                        <button type="button" class="button is-primary" @click="goNext">
                            <span><?php echo __e('feed.wizard.common.next'); ?></span>
                            <span class="icon is-small">
                                <?php
                                echo IconHelper::render('arrow-right', ['alt' => __('feed.wizard.common.next')]);
                                ?>
                            </span>
                        </button>
                    </div>
                </div>
            </div>
        </nav>

        <button type="button" class="button is-small wizard-minmax mt-2" @click="toggleMinimize">
            <span class="icon is-small">
                <?php echo IconHelper::render('minimize-2', ['alt' => __('feed.wizard.common.min_max')]); ?>
            </span>
            <span><?php echo __e('feed.wizard.common.min_max'); ?></span>
        </button>
    </div>
    </template>

    <!-- ===================== STEP 4: Edit Options ===================== -->
    <template x-if="isStep4">
    <div x-data="feedWizardStep4">
        <?php echo $renderSteps(4); ?>
        <?php include __DIR__ . '/wizard_save_form.php'; ?>
    </div>
    </template>

    <!-- The article being picked in. Its content is fetched by
         /api/v1/feeds/wizard/article and is the third-party page itself. -->
    <p id="lwt_last" x-show="isPicking"></p>
    <div id="lwt_article" x-show="isPicking"></div>
</div>
