<?php

/**
 * Feed Wizard Save Form - the wizard's last step.
 *
 * Names the feed, picks its language and sets its options, then saves through
 * `POST /api/v1/feeds` (or `PUT` when reopening a saved feed), so the form
 * carries no action of its own (#262).
 *
 * Expects to be included inside a `feedWizardStep4` component.
 *
 * PHP version 8.1
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

?>
<form class="validate" @submit="handleSubmit($event)">
    <div class="box">
        <!-- Language -->
        <div class="field is-horizontal">
            <div class="field-label is-normal">
                <label class="label"><?php echo __e('feed.wizard.step4.language'); ?></label>
            </div>
            <div class="field-body">
                <div class="field has-addons">
                    <div class="control is-expanded">
                        <div class="select is-fullwidth">
                            <select name="NfLgID" x-model="languageId" required class="notempty">
                                <option value=""><?php echo __e('feed.wizard.step4.select_placeholder'); ?></option>
                                <template x-for="lang in languages" :key="lang.id">
                                    <option :value="lang.id" x-text="lang.name"></option>
                                </template>
                            </select>
                        </div>
                    </div>
                    <div class="control">
                        <span class="icon has-text-danger"
                              title="<?php echo __e('feed.wizard.common.field_required'); ?>">
                            <?php echo IconHelper::render('asterisk', ['alt' => __('feed.wizard.common.required')]); ?>
                        </span>
                    </div>
                </div>
            </div>
        </div>

        <!-- Name -->
        <div class="field is-horizontal">
            <div class="field-label is-normal">
                <label class="label"><?php echo __e('feed.wizard.common.name'); ?></label>
            </div>
            <div class="field-body">
                <div class="field has-addons">
                    <div class="control is-expanded">
                        <input class="input notempty" type="text" name="NfName"
                               x-model="feedName" required />
                    </div>
                    <div class="control">
                        <span class="icon has-text-danger"
                              title="<?php echo __e('feed.wizard.common.field_required'); ?>">
                            <?php echo IconHelper::render('asterisk', ['alt' => __('feed.wizard.common.required')]); ?>
                        </span>
                    </div>
                </div>
            </div>
        </div>

        <!-- Newsfeed URL -->
        <div class="field is-horizontal">
            <div class="field-label is-normal">
                <label class="label"><?php echo __e('feed.wizard.common.newsfeed_url'); ?></label>
            </div>
            <div class="field-body">
                <div class="field has-addons">
                    <div class="control is-expanded">
                        <input class="input notempty" type="text" name="NfSourceURI"
                               x-model="sourceUri" required />
                    </div>
                    <div class="control">
                        <span class="icon has-text-danger"
                              title="<?php echo __e('feed.wizard.common.field_required'); ?>">
                            <?php echo IconHelper::render('asterisk', ['alt' => __('feed.wizard.common.required')]); ?>
                        </span>
                    </div>
                </div>
            </div>
        </div>

        <!-- Article Section -->
        <div class="field is-horizontal">
            <div class="field-label is-normal">
                <label class="label"><?php echo __e('feed.wizard.common.article_section'); ?></label>
            </div>
            <div class="field-body">
                <div class="field has-addons">
                    <div class="control is-expanded">
                        <input class="input notempty" type="text" name="NfArticleSectionTags"
                               x-model="articleSection" required />
                    </div>
                    <div class="control">
                        <span class="icon has-text-danger"
                              title="<?php echo __e('feed.wizard.common.field_required'); ?>">
                            <?php echo IconHelper::render('asterisk', ['alt' => __('feed.wizard.common.required')]); ?>
                        </span>
                    </div>
                </div>
            </div>
        </div>

        <!-- Filter Tags -->
        <div class="field is-horizontal">
            <div class="field-label is-normal">
                <label class="label"><?php echo __e('feed.wizard.step4.filter_tags'); ?></label>
            </div>
            <div class="field-body">
                <div class="field">
                    <div class="control">
                        <input class="input" type="text" name="NfFilterTags"
                               x-model="filterTags" />
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Options Box -->
    <div class="box">
        <h2 class="subtitle is-5 mb-4"><?php echo __e('feed.wizard.step4.options'); ?></h2>

        <div class="columns">
            <div class="column">
                <!-- Edit Text -->
                <div class="field">
                    <label class="checkbox">
                        <input type="checkbox" name="edit_text" x-model="editText" />
                        <?php echo __e('feed.wizard.step4.edit_text'); ?>
                    </label>
                    <p class="help"><?php echo __e('feed.wizard.step4.edit_text_help'); ?></p>
                </div>

                <!-- Max Links -->
                <div class="field">
                    <label class="checkbox">
                        <input type="checkbox" name="c_max_links" x-model="maxLinksEnabled" />
                        <?php echo __e('feed.wizard.step4.max_links'); ?>
                    </label>
                    <div class="control mt-1">
                        <input class="input is-small" type="number" name="max_links"
                               min="0" max="300" style="width: 100px;"
                               x-model="maxLinks"
                               :disabled="!maxLinksEnabled"
                               :class="{ 'notempty': maxLinksEnabled }" />
                    </div>
                </div>

                <!-- Max Texts -->
                <div class="field">
                    <label class="checkbox">
                        <input type="checkbox" name="c_max_texts" x-model="maxTextsEnabled" />
                        <?php echo __e('feed.wizard.step4.max_texts'); ?>
                    </label>
                    <div class="control mt-1">
                        <input class="input is-small" type="number" name="max_texts"
                               min="0" max="30" style="width: 100px;"
                               x-model="maxTexts"
                               :disabled="!maxTextsEnabled"
                               :class="{ 'notempty': maxTextsEnabled }" />
                    </div>
                </div>
            </div>

            <div class="column">
                <!-- Auto Update -->
                <div class="field">
                    <label class="checkbox">
                        <input type="checkbox" name="c_autoupdate" x-model="autoUpdateEnabled" />
                        <?php echo __e('feed.wizard.step4.auto_update_interval'); ?>
                    </label>
                    <div class="field has-addons mt-1">
                        <div class="control">
                            <input class="input is-small" type="number" name="autoupdate"
                                   min="0" style="width: 80px;"
                                   x-model="autoUpdateInterval"
                                   :disabled="!autoUpdateEnabled"
                                   :class="{ 'notempty': autoUpdateEnabled }" />
                        </div>
                        <div class="control">
                            <div class="select is-small">
                                <select name="autoupdate_unit" x-model="autoUpdateUnit"
                                        :disabled="!autoUpdateEnabled">
                                    <option value="h"><?php echo __e('feed.wizard.step1.opt_unit_hours'); ?></option>
                                    <option value="d"><?php echo __e('feed.wizard.step1.opt_unit_days'); ?></option>
                                    <option value="w"><?php echo __e('feed.wizard.step1.opt_unit_weeks'); ?></option>
                                </select>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Charset -->
                <div class="field">
                    <label class="checkbox">
                        <input type="checkbox" name="c_charset" x-model="charsetEnabled" />
                        <?php echo __e('feed.wizard.step4.charset'); ?>
                    </label>
                    <div class="control mt-1">
                        <input class="input is-small" type="text" name="charset"
                               style="width: 150px;"
                               x-model="charset"
                               :disabled="!charsetEnabled"
                               :class="{ 'notempty': charsetEnabled }" />
                    </div>
                </div>

                <!-- Tag -->
                <div class="field">
                    <label class="checkbox">
                        <input type="checkbox" name="c_tag" x-model="tagEnabled" />
                        <?php echo __e('feed.wizard.step4.tag'); ?>
                    </label>
                    <div class="control mt-1">
                        <input class="input is-small" type="text" name="tag"
                               style="width: 150px;"
                               x-model="tag"
                               :disabled="!tagEnabled"
                               :class="{ 'notempty': tagEnabled }" />
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Save failure, reported by the API -->
    <div x-show="hasSaveError()" class="notification is-danger" x-cloak>
        <span x-text="saveError"></span>
    </div>

    <!-- Form Actions -->
    <div class="field is-grouped is-grouped-right mt-5">
        <div class="control">
            <button type="button" class="button is-danger is-outlined" @click="cancel">
                <?php echo __e('feed.wizard.common.cancel'); ?>
            </button>
        </div>
        <div class="control">
            <button type="button" class="button" @click="goBack">
                <span class="icon is-small">
                    <?php echo IconHelper::render('arrow-left', ['alt' => __('feed.wizard.common.back')]); ?>
                </span>
                <span><?php echo __e('feed.wizard.common.back'); ?></span>
            </button>
        </div>
        <div class="control">
            <button type="submit" class="button is-primary"
                    :disabled="saving" :class="{ 'is-loading': saving }">
                <span x-text="submitLabel()"><?php echo __e('feed.wizard.step4.save'); ?></span>
            </button>
        </div>
    </div>
</form>
