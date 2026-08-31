<?php

/**
 * Feed Wizard Picker Panel - the chrome both picking steps share.
 *
 * Steps 2 and 3 do the same thing to the same article — one names what to
 * keep, the other what to drop — so the advanced-XPath panel, the settings
 * modal and the article's loading and failure notices are written once here
 * and included by both.
 *
 * Expects to be included inside a `feedWizardStep2` or `feedWizardStep3`
 * component.
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

?>
<!-- Advanced Mode Panel (shown when minimized) -->
<div id="adv" class="box mb-2" x-show="isMinimized" x-cloak>
    <div class="buttons">
        <button type="button" class="button is-small is-danger is-outlined" @click="cancel">
            <?php echo __e('feed.wizard.common.cancel'); ?>
        </button>
        <button type="button" class="button is-small is-info" @click="getAdvanced" x-show="store.isAdvancedOpen">
            <?php echo __e('feed.wizard.common.get'); ?>
        </button>
    </div>
    <template x-if="store.isAdvancedOpen">
        <div class="content">
            <p class="is-size-7 mb-2"><?php echo __e('feed.wizard.common.select_xpath_option'); ?></p>
            <template x-for="option in store.advancedOptions" :key="option.value">
                <div class="field">
                    <label class="radio">
                        <input type="radio" name="adv_xpath"
                               :value="option.value"
                               @click="selectAdvancedOption(option.value)" />
                        <span x-text="option.label"></span>
                        <span class="tag is-small is-light"
                              x-text="'(' + option.count + ' ' + $t('feed.wizard.common.matches') + ')'"></span>
                    </label>
                </div>
            </template>
            <div class="field">
                <label class="radio">
                    <input type="radio" name="adv_xpath" value="" @click="selectAdvancedOption('')" />
                    <?php echo __e('feed.wizard.common.custom'); ?>
                    <input type="text" class="input is-small" style="width: 300px;"
                           x-model="store.customXPath"
                           :class="{ 'is-danger': store.customXPath && !store.customXPathValid }" />
                </label>
            </div>
            <div class="buttons mt-3">
                <button type="button" class="button is-small" @click="cancelAdvanced">
                    <?php echo __e('feed.wizard.common.cancel'); ?>
                </button>
                <button type="button" class="button is-small is-info" @click="getAdvanced">
                    <?php echo __e('feed.wizard.common.get'); ?>
                </button>
            </div>
        </div>
    </template>
</div>

<!-- Settings Modal -->
<div class="modal" :class="settingsOpen ? 'is-active' : ''">
    <div class="modal-background" @click="settingsOpen = false"></div>
    <div class="modal-card">
        <header class="modal-card-head">
            <p class="modal-card-title">
                <span class="icon mr-2">
                    <?php echo IconHelper::render('settings', ['alt' => __('feed.wizard.common.settings')]); ?>
                </span>
                <?php echo __e('feed.wizard.common.settings_title'); ?>
            </p>
            <button class="delete" aria-label="close" type="button" @click="settingsOpen = false"></button>
        </header>
        <section class="modal-card-body">
            <div class="field">
                <label class="label"><?php echo __e('feed.wizard.common.selection_mode'); ?></label>
                <div class="control">
                    <div class="select is-fullwidth">
                        <select x-model="store.selectionMode" @change="changeSelectMode">
                            <option value="smart"><?php echo __e('feed.wizard.common.mode_smart'); ?></option>
                            <option value="all"><?php echo __e('feed.wizard.common.mode_all'); ?></option>
                            <option value="adv"><?php echo __e('feed.wizard.common.mode_advanced'); ?></option>
                        </select>
                    </div>
                </div>
            </div>
            <div class="field">
                <label class="label"><?php echo __e('feed.wizard.common.hide_images'); ?></label>
                <div class="control">
                    <div class="select is-fullwidth">
                        <select x-model="store.hideImages" @change="changeHideImages">
                            <option :value="true"><?php echo __e('feed.wizard.common.yes'); ?></option>
                            <option :value="false"><?php echo __e('feed.wizard.common.no'); ?></option>
                        </select>
                    </div>
                </div>
            </div>
        </section>
        <footer class="modal-card-foot">
            <button type="button" class="button is-success" @click="settingsOpen = false">
                <?php echo __e('feed.wizard.common.ok'); ?>
            </button>
        </footer>
    </div>
</div>

<!-- The article is fetched rather than rendered into the page (#262) -->
<div x-show="loadingArticle" class="notification is-info is-light" x-cloak>
    <?php echo __e('feed.wizard.loading_article'); ?>
</div>
<div x-show="hasArticleError()" class="notification is-danger is-light" x-cloak>
    <span x-text="articleError"></span>
</div>
