<?php

/**
 * Admin Settings Form View
 *
 * Server-wide admin settings: feed limits, multi-user.
 * User-scoped preferences (reading, review, appearance, TTS, pagination) have
 * moved to the user preferences page at /profile/preferences.
 *
 * Variables expected:
 * - $settings: array of current settings values
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

namespace Lwt\Views\Admin;

use Lwt\Shared\UI\Helpers\IconHelper;

/**
 * @var array<string, string> $settings Current settings values
 * @psalm-suppress MixedArgument
 */
$settings = array_map(
    /**
     * @param mixed $v
     * @return string
     */
    static fn($v): string => (string)($v ?? ''),
    is_array($settings ?? null) ? $settings : []
);

?>

<!-- Link to user preferences -->
<div class="notification is-info is-light mb-5">
    <span class="icon-text">
        <?php echo IconHelper::render('settings', ['alt' => 'Preferences']); ?>
        <span class="ml-2">
            <?= __('admin.settings_preferences_link') ?>
            <a href="/profile/preferences"><strong><?= __('admin.settings_preferences_link_target') ?></strong></a>
        </span>
    </span>
</div>

<form class="validate" action="/admin/settings" method="post" data-lwt-settings-form>
    <?php echo \Lwt\Shared\UI\Helpers\FormHelper::csrfField(); ?>

    <!-- Newsfeeds Section -->
    <div class="card settings-section mb-4" x-data="{ open: true }">
        <header class="card-header is-clickable" @click="open = !open">
            <p class="card-header-title">
                <?php echo IconHelper::render('rss', ['alt' => 'Newsfeeds']); ?>
                <span class="ml-2"><?= __('admin.settings_section_newsfeeds') ?></span>
            </p>
            <button
                type="button"
                class="card-header-icon"
                aria-label="<?= htmlspecialchars(__('admin.settings_toggle_section'), ENT_QUOTES, 'UTF-8') ?>"
            >
                <span class="icon">
                    <i :class="open ? 'rotate-180' : ''" class="transition-transform" data-lucide="chevron-down"></i>
                </span>
            </button>
        </header>
        <div class="card-content" x-show="open" x-transition>
            <div class="field">
                <label class="label" for="set-max-articles-with-text">
                    <?= __('admin.settings_max_articles_with_text') ?>
                    <span class="has-text-weight-normal">
                        <?= __('admin.settings_max_articles_with_text_qualifier') ?>
                    </span>
                </label>
                <div class="field has-addons">
                    <div class="control">
                        <input class="input notempty posintnumber"
                               type="number"
                               min="0"
                               id="set-max-articles-with-text"
                               name="set-max-articles-with-text"
                               data_info="Max Articles per Feed with cached text"
                               value="<?php
                                   echo htmlspecialchars(
                                       $settings['set-max-articles-with-text'] ?? '',
                                       ENT_QUOTES,
                                       'UTF-8'
                                   );
                                    ?>"
                               maxlength="4"
                               style="width: 100px;"
                               required />
                    </div>
                    <div class="control">
                        <span
                            class="icon has-text-danger"
                            title="<?= htmlspecialchars(__('admin.settings_field_required'), ENT_QUOTES, 'UTF-8') ?>"
                        >
                            <?php echo IconHelper::render('asterisk', ['alt' => 'Required']); ?>
                        </span>
                    </div>
                </div>
                <p class="help"><?= __('admin.settings_max_articles_with_text_help') ?></p>
            </div>

            <div class="field">
                <label class="label" for="set-max-articles-without-text">
                    <?= __('admin.settings_max_articles_with_text') ?>
                    <span class="has-text-weight-normal">
                        <?= __('admin.settings_max_articles_without_text_qualifier') ?>
                    </span>
                </label>
                <div class="field has-addons">
                    <div class="control">
                        <input class="input notempty posintnumber"
                               type="number"
                               min="0"
                               id="set-max-articles-without-text"
                               name="set-max-articles-without-text"
                               data_info="Max Articles per Feed without cached text"
                               value="<?php
                                   echo htmlspecialchars(
                                       $settings['set-max-articles-without-text'] ?? '',
                                       ENT_QUOTES,
                                       'UTF-8'
                                   );
                                    ?>"
                               maxlength="4"
                               style="width: 100px;"
                               required />
                    </div>
                    <div class="control">
                        <span
                            class="icon has-text-danger"
                            title="<?= htmlspecialchars(__('admin.settings_field_required'), ENT_QUOTES, 'UTF-8') ?>"
                        >
                            <?php echo IconHelper::render('asterisk', ['alt' => 'Required']); ?>
                        </span>
                    </div>
                </div>
                <p class="help"><?= __('admin.settings_max_articles_without_text_help') ?></p>
            </div>

            <div class="field">
                <label class="label" for="set-max-texts-per-feed">
                    <?= __('admin.settings_max_texts_per_feed') ?>
                </label>
                <div class="field has-addons">
                    <div class="control">
                        <input class="input notempty posintnumber"
                               type="number"
                               min="0"
                               id="set-max-texts-per-feed"
                               name="set-max-texts-per-feed"
                               data_info="Max Texts per Feed"
                               value="<?php
                                   echo htmlspecialchars(
                                       $settings['set-max-texts-per-feed'] ?? '',
                                       ENT_QUOTES,
                                       'UTF-8'
                                   );
                                    ?>"
                               maxlength="4"
                               style="width: 100px;"
                               required />
                    </div>
                    <div class="control">
                        <span
                            class="icon has-text-danger"
                            title="<?= htmlspecialchars(__('admin.settings_field_required'), ENT_QUOTES, 'UTF-8') ?>"
                        >
                            <?php echo IconHelper::render('asterisk', ['alt' => 'Required']); ?>
                        </span>
                    </div>
                </div>
                <p class="help"><?= __('admin.settings_max_texts_per_feed_help') ?></p>
            </div>
        </div>
    </div>

    <!-- Multi-User Settings -->
    <div class="card settings-section mb-5" x-data="{ open: true }">
        <header class="card-header is-clickable" @click="open = !open">
            <p class="card-header-title">
                <?php echo IconHelper::render('users', ['alt' => 'Multi-User']); ?>
                <span class="ml-2"><?= __('admin.settings_section_multi_user') ?></span>
            </p>
            <button
                type="button"
                class="card-header-icon"
                aria-label="<?= htmlspecialchars(__('admin.settings_toggle_section'), ENT_QUOTES, 'UTF-8') ?>"
            >
                <span class="icon">
                    <i :class="open ? 'rotate-180' : ''" class="transition-transform" data-lucide="chevron-down"></i>
                </span>
            </button>
        </header>
        <div class="card-content" x-show="open" x-transition>
            <div class="field">
                <label class="label"><?= __('admin.settings_allow_registration') ?></label>
                <div class="control">
                    <label class="checkbox">
                        <input type="checkbox"
                               name="set-allow-registration"
                               value="1"
                               <?php echo ((int)($settings['set-allow-registration'] ?? '1') ? "checked" : ""); ?> />
                        <?= __('admin.settings_allow_registration_help') ?>
                    </label>
                </div>
            </div>
            <div class="field">
                <label class="label"><?= __('admin.settings_check_for_updates') ?></label>
                <div class="control">
                    <label class="checkbox">
                        <input type="checkbox"
                               name="set-check-for-updates"
                               value="1"
                               <?php echo ((int)($settings['set-check-for-updates'] ?? '1') ? "checked" : ""); ?> />
                        <?= __('admin.settings_check_for_updates_help') ?>
                    </label>
                </div>
            </div>
        </div>
    </div>

    <!-- Form Actions -->
    <div class="field is-grouped is-grouped-right">
        <div class="control">
            <button type="button"
                    class="button is-light"
                    data-action="settings-navigate"
                    data-url="index.php">
                <span class="icon is-small">
                    <?php echo IconHelper::render('arrow-left', ['alt' => 'Back']); ?>
                </span>
                <span><?= __('admin.settings_back') ?></span>
            </button>
        </div>
        <div class="control">
            <button type="button"
                    class="button is-warning is-outlined"
                    data-action="settings-navigate"
                    data-url="/admin/settings?op=reset">
                <span class="icon is-small">
                    <?php echo IconHelper::render('rotate-ccw', ['alt' => 'Reset']); ?>
                </span>
                <span><?= __('admin.settings_reset_defaults') ?></span>
            </button>
        </div>
        <div class="control">
            <button type="submit" name="op" value="Save" class="button is-primary">
                <span class="icon is-small">
                    <?php echo IconHelper::render('save', ['alt' => 'Save']); ?>
                </span>
                <span><?= __('admin.settings_save') ?></span>
            </button>
        </div>
    </div>
</form>
