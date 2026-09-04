<?php

/**
 * Edit Feed Form View
 *
 * Variables expected:
 * - $feed: array feed data from database
 * - $feedLanguageName: the feed's language, resolved by the controller
 * - $options: array parsed feed options
 * - $autoUpdateInterval: string|null auto-update interval value
 * - $autoUpdateUnit: string|null auto-update unit (h/d/w)
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

use Lwt\Shared\UI\Helpers\IconHelper;
use Lwt\Shared\UI\Helpers\PageLayoutHelper;
use Lwt\Shared\UI\Helpers\ConfigIsland;

/**
 * @var array{NfID: int|null, NfLgID: int, NfName: string, NfSourceURI: string,
 *            NfArticleSectionTags: string, NfFilterTags: string, NfUpdate: int,
 *            NfOptions: string} $feed Feed data
 * @var string $feedLanguageName The feed's language name
 * @var array<string, string> $options Parsed feed options
 * @var string|null $autoUpdateInterval Auto-update interval value
 * @var string|null $autoUpdateUnit Auto-update unit (h/d/w)
 */

$actions = [
    ['url' => '/feeds/manage', 'label' => __('feed.edit_action_feeds'), 'icon' => 'list'],
    [
        'url' => '/feeds/wizard?edit_feed=' . (int)$feed['NfID'],
        'label' => __('feed.edit_action_wizard'),
        'icon' => 'wand-2',
        'class' => 'is-info'
    ]
];

$helpLabel = __('feed.edit_help');
?>
<h2 class="title is-4 is-flex is-align-items-center">
    <?php echo __e('feed.edit_title'); ?>
    <a target="_blank" href="docs/info.html#new_feed" class="ml-2">
        <?php echo IconHelper::render('help-circle', ['title' => $helpLabel, 'alt' => $helpLabel]); ?>
    </a>
</h2>

<?php echo PageLayoutHelper::buildActionCard($actions); ?>

<?php ConfigIsland::render('feed-form-config', [
    'feedId' => (int)$feed['NfID'],
    'editText' => isset($options['edit_text']),
    'autoUpdate' => $autoUpdateInterval !== null,
    'autoUpdateValue' => $autoUpdateInterval ?? '',
    'autoUpdateUnit' => $autoUpdateUnit ?? 'h',
    'maxLinks' => isset($options['max_links']),
    'maxLinksValue' => $options['max_links'] ?? '',
    'charset' => isset($options['charset']),
    'charsetValue' => $options['charset'] ?? '',
    'maxTexts' => isset($options['max_texts']),
    'maxTextsValue' => $options['max_texts'] ?? '',
    'tag' => isset($options['tag']),
    'tagValue' => $options['tag'] ?? '',
    'articleSource' => isset($options['article_source']),
    'articleSourceValue' => $options['article_source'] ?? '',
]); ?>
<!-- Saves through PUT /api/v1/feeds/{id} (#262), so the form carries no action. -->
<form class="validate"
      x-data="feedForm()"
      @submit="handleSubmit($event)">

    <div x-show="hasSaveError()" class="notification is-danger" x-cloak>
        <span x-text="saveError"></span>
    </div>

    <div class="box">
        <!-- Language. Shown, not asked: the navbar is what picks a language,
             and this feed keeps the one it was saved with. The id still rides
             along in a hidden field, which is where the form reads it from. -->
        <div class="field">
            <label class="label"><?php echo __e('feed.edit_label_language'); ?></label>
            <div class="control">
                <input type="hidden" name="NfLgID" id="NfLgID" value="<?php echo $feed['NfLgID']; ?>" />
                <span class="tag is-info is-light">
                    <?php echo htmlspecialchars($feedLanguageName, ENT_QUOTES, 'UTF-8'); ?>
                </span>
            </div>
        </div>

        <!-- Name -->
        <div class="field">
            <label class="label" for="NfName">
                <?php echo __e('feed.edit_label_name'); ?>
                <span class="has-text-danger" title="<?php echo __e('feed.edit_required'); ?>">*</span>
            </label>
            <div class="control">
                <input class="input notempty"
                       type="text"
                       name="NfName"
                       id="NfName"
                       value="<?php echo htmlspecialchars($feed['NfName'] ?? '', ENT_QUOTES, 'UTF-8'); ?>"
                       required />
            </div>
        </div>

        <!-- Newsfeed URL -->
        <div class="field">
            <label class="label" for="NfSourceURI">
                <?php echo __e('feed.edit_label_url'); ?>
                <span class="has-text-danger" title="<?php echo __e('feed.edit_required'); ?>">*</span>
            </label>
            <div class="control">
                <input class="input notempty"
                       type="url"
                       name="NfSourceURI"
                       id="NfSourceURI"
                       value="<?php echo htmlspecialchars($feed['NfSourceURI'] ?? '', ENT_QUOTES, 'UTF-8'); ?>"
                       required />
            </div>
        </div>

        <!-- Article Section -->
        <div class="field">
            <label class="label" for="NfArticleSectionTags">
                <?php echo __e('feed.edit_label_article_section'); ?>
                <span class="has-text-danger" title="<?php echo __e('feed.edit_required'); ?>">*</span>
            </label>
            <div class="control">
                <?php
                $articleSection = $feed['NfArticleSectionTags'] ?? '';
                $articleSectionVal = htmlspecialchars($articleSection, ENT_QUOTES, 'UTF-8');
                ?>
                <input class="input notempty"
                       type="text"
                       name="NfArticleSectionTags"
                       id="NfArticleSectionTags"
                       value="<?php echo $articleSectionVal; ?>"
                       required />
            </div>
        </div>

        <!-- Filter Tags -->
        <div class="field">
            <label class="label" for="NfFilterTags"><?php echo __e('feed.edit_label_filter_tags'); ?></label>
            <div class="control">
                <?php
                $filterTags = $feed['NfFilterTags'] ?? '';
                $filterTagsVal = htmlspecialchars($filterTags, ENT_QUOTES, 'UTF-8');
                ?>
                <input class="input"
                       type="text"
                       name="NfFilterTags"
                       id="NfFilterTags"
                       value="<?php echo $filterTagsVal; ?>" />
            </div>
        </div>

        <!-- Options Section -->
        <div class="field">
            <label class="label"><?php echo __e('feed.edit_label_options'); ?></label>
            <div class="box" style="background-color: var(--bulma-scheme-main-bis);">
                <div class="columns is-multiline">
                    <!-- Edit Text -->
                    <div class="column is-half">
                        <label class="checkbox">
                            <input type="checkbox" name="edit_text" x-model="editText" />
                            <strong><?php echo __e('feed.edit_opt_review_before_importing'); ?></strong>
                        </label>
                        <p class="help"><?php echo __e('feed.edit_opt_review_help'); ?></p>
                    </div>

                    <!-- Auto Update -->
                    <div class="column is-half">
                        <label class="checkbox">
                            <input type="checkbox" name="c_autoupdate" x-model="autoUpdate" />
                            <strong><?php echo __e('feed.edit_opt_auto_refresh'); ?></strong>
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
                                    <select name="autoupdate_unit"
                                            x-model="autoUpdateUnit"
                                            :disabled="!autoUpdate">
                                        <option value="h"><?php echo __e('feed.edit_opt_unit_hours'); ?></option>
                                        <option value="d"><?php echo __e('feed.edit_opt_unit_days'); ?></option>
                                        <option value="w"><?php echo __e('feed.edit_opt_unit_weeks'); ?></option>
                                    </select>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Max Links -->
                    <div class="column is-half">
                        <label class="checkbox">
                            <input type="checkbox" name="c_max_links" x-model="maxLinks" />
                            <strong><?php echo __e('feed.edit_opt_limit_articles'); ?></strong>
                        </label>
                        <p class="help"><?php echo __e('feed.edit_opt_limit_articles_help'); ?></p>
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
                            <strong><?php echo __e('feed.edit_opt_charset'); ?></strong>
                        </label>
                        <p class="help"><?php echo __e('feed.edit_opt_charset_help'); ?></p>
                        <div class="control mt-2" x-show="charset" x-transition>
                            <input class="input is-small"
                                   :class="charset ? 'notempty' : ''"
                                   type="text"
                                   name="charset"
                                   data_info="Charset"
                                   x-model="charsetValue"
                                   :disabled="!charset" />
                        </div>
                    </div>

                    <!-- Max Texts -->
                    <div class="column is-half">
                        <label class="checkbox">
                            <input type="checkbox" name="c_max_texts" x-model="maxTexts" />
                            <strong><?php echo __e('feed.edit_opt_limit_texts'); ?></strong>
                        </label>
                        <p class="help"><?php echo __e('feed.edit_opt_limit_texts_help'); ?></p>
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
                            <strong><?php echo __e('feed.edit_opt_auto_tag'); ?></strong>
                        </label>
                        <p class="help"><?php echo __e('feed.edit_opt_auto_tag_help'); ?></p>
                        <div class="control mt-2" x-show="tag" x-transition>
                            <input class="input is-small"
                                   :class="tag ? 'notempty' : ''"
                                   type="text"
                                   name="tag"
                                   data_info="Tag"
                                   x-model="tagValue"
                                   :disabled="!tag" />
                        </div>
                    </div>

                    <!-- Article Source -->
                    <div class="column is-full">
                        <label class="checkbox">
                            <input type="checkbox" name="c_article_source" x-model="articleSource" />
                            <strong><?php echo __e('feed.edit_opt_source'); ?></strong>
                        </label>
                        <p class="help"><?php echo __e('feed.edit_opt_source_help'); ?></p>
                        <div class="control mt-2" x-show="articleSource" x-transition>
                            <input class="input is-small"
                                   :class="articleSource ? 'notempty' : ''"
                                   type="text"
                                   name="article_source"
                                   data_info="Article Source"
                                   x-model="articleSourceValue"
                                   :disabled="!articleSource" />
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Form Actions -->
    <div class="field is-grouped is-grouped-right">
        <div class="control">
            <button type="button"
                    class="button is-light"
                    data-action="navigate"
                    data-url="/feeds/manage">
                <?php echo __e('feed.edit_cancel'); ?>
            </button>
        </div>
        <div class="control">
            <button type="submit" class="button is-primary"
                    :disabled="saving" :class="{ 'is-loading': saving }">
                <span class="icon is-small">
                    <?php echo IconHelper::render('save', ['alt' => __('feed.edit_save')]); ?>
                </span>
                <span><?php echo __e('feed.edit_update'); ?></span>
            </button>
        </div>
    </div>
</form>
<!-- Feed form component: feeds/components/feed_form_component.ts -->
