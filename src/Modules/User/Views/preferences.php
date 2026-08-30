<?php

/**
 * User Preferences View
 *
 * User-scoped settings for appearance, reading, review, TTS, and pagination.
 * In multi-user mode, these are stored per-user in the settings table.
 *
 * Variables expected:
 * - $settings: array of current user preference values
 * - $currentLanguageCode: string Language code for TTS
 * - $languageOptions: string HTML options for language select
 *
 * PHP version 8.2
 *
 * @category Lwt
 * @package  Lwt\Modules\User\Views
 * @author   HugoFara <git@hugofara.net>
 * @license  Unlicense <http://unlicense.org/>
 * @link     https://hugofara.github.io/lwt/developer/api
 * @since    3.0.0
 */

declare(strict_types=1);

namespace Lwt\Modules\User\Views;

use Lwt\Shared\UI\Helpers\SelectOptionsBuilder;
use Lwt\Shared\UI\Helpers\IconHelper;
use Lwt\Shared\UI\Helpers\FormHelper;
use Lwt\Shared\UI\Helpers\ConfigIsland;

/**
 * @var array<string, string> $settings Current user preference values
 * @var string $currentLanguageCode Current language code for TTS
 * @var string $languageOptions HTML options for language select
 * @var array<int, array{
 *     name: string,
 *     path: string,
 *     description?: string,
 *     mode?: string,
 *     highlighting?: string,
 *     wordBreaking?: string
 * }> $themes Available themes from ThemeService
 */

?>
<form class="validate" data-lwt-settings-form
      x-data="preferencesApp" @submit.prevent="save()">

    <div class="notification is-danger" x-show="error" x-cloak>
        <span x-text="error"></span>
    </div>
    <div class="notification is-success" x-show="success" x-cloak data-auto-hide="true">
        <span x-text="success"></span>
    </div>

    <!-- Appearance Section -->
    <div class="card settings-section mb-4" x-data="{ open: false }">
        <header class="card-header is-clickable" @click="open = !open">
            <p class="card-header-title">
                <?php echo IconHelper::render('palette', ['alt' => 'Appearance']); ?>
                <span class="ml-2"><?= __('preferences.section_appearance') ?></span>
            </p>
            <button
                type="button"
                class="card-header-icon"
                aria-label="<?= htmlspecialchars(__('preferences.toggle_section'), ENT_QUOTES, 'UTF-8') ?>"
            >
                <span class="icon">
                    <i :class="open ? 'rotate-180' : ''" class="transition-transform" data-lucide="chevron-down"></i>
                </span>
            </button>
        </header>
        <div class="card-content" x-show="open" x-transition>
            <?php $currentTheme = htmlspecialchars($settings['set-theme-dir'] ?? '', ENT_QUOTES, 'UTF-8'); ?>
            <div x-data="themeSelector"
                 data-current-theme="<?php echo $currentTheme; ?>">
                <div class="field">
                    <label class="label" for="set-theme-dir"><?= __('preferences.theme_label') ?></label>
                    <div class="field has-addons">
                        <div class="control is-expanded">
                            <div class="select is-fullwidth">
                                <select name="set-theme-dir" id="set-theme-dir" class="notempty" required
                                        x-model="currentTheme"
                                        @change="onThemeChange()">
                                    <?php
                                    echo SelectOptionsBuilder::forThemes(
                                        $themes,
                                        $settings['set-theme-dir']
                                    );
                                    ?>
                                </select>
                            </div>
                        </div>
                        <div class="control">
                            <span
                            class="icon has-text-danger"
                            title="<?= htmlspecialchars(__('preferences.field_required'), ENT_QUOTES, 'UTF-8') ?>"
                        >
                                <?php echo IconHelper::render('asterisk', ['alt' => 'Required']); ?>
                            </span>
                        </div>
                    </div>
                    <div class="box mt-3" x-show="description || highlighting || wordBreaking" x-transition>
                        <p class="mb-2" x-show="description">
                            <strong><?= __('preferences.theme_description_label') ?></strong>
                            <span x-text="description"></span>
                        </p>
                        <div class="tags">
                            <span class="tag" :class="mode === 'dark' ? 'is-dark' : 'is-light'" x-show="mode">
                                <i data-lucide="sun" style="width:14px;height:14px;margin-right:4px"></i>
                                <span x-text="
                                    mode === 'dark'
                                        ? $t('preferences.theme_dark_mode')
                                        : $t('preferences.theme_light_mode')
                                "></span>
                            </span>
                            <span class="tag is-info is-light" x-show="highlighting">
                                <i data-lucide="palette" style="width:14px;height:14px;margin-right:4px"></i>
                                <span x-text="highlighting"></span>
                            </span>
                            <span class="tag is-primary is-light" x-show="wordBreaking">
                                <i data-lucide="wrap-text" style="width:14px;height:14px;margin-right:4px"></i>
                                <span x-text="wordBreaking"></span>
                            </span>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Language Section -->
    <div class="card settings-section mb-4" x-data="{ open: false }">
        <header class="card-header is-clickable" @click="open = !open">
            <p class="card-header-title">
                <?php echo IconHelper::render('languages', ['alt' => 'Language']); ?>
                <span class="ml-2"><?= __('preferences.section_language') ?></span>
            </p>
            <button
                type="button"
                class="card-header-icon"
                aria-label="<?= htmlspecialchars(__('preferences.toggle_section'), ENT_QUOTES, 'UTF-8') ?>"
            >
                <span class="icon">
                    <i :class="open ? 'rotate-180' : ''" class="transition-transform" data-lucide="chevron-down"></i>
                </span>
            </button>
        </header>
        <div class="card-content" x-show="open" x-transition>
            <div class="field">
                <label class="label" for="app_language"><?= __('preferences.app_language_label') ?></label>
                <div class="control">
                    <div class="select is-fullwidth">
                        <?php
                        $i18nContainer = \Lwt\Shared\Infrastructure\Container\Container::getInstance();
                        $availableLocales = ['en'];
                        if ($i18nContainer->has(\Lwt\Shared\I18n\Translator::class)) {
                            $availableLocales = $i18nContainer
                                ->getTyped(\Lwt\Shared\I18n\Translator::class)
                                ->getAvailableLocales();
                        }
                        ?>
                        <select name="app_language"
                                :value="settingValue('app_language')"
                                @change="onFieldInput('app_language', $event)" id="app_language">
                            <?php
                            echo SelectOptionsBuilder::forAppLanguages(
                                $availableLocales,
                                $settings['app_language'] ?? 'en'
                            );
                            ?>
                        </select>
                    </div>
                </div>
                <p class="help"><?= __('preferences.app_language_help') ?></p>
            </div>
        </div>
    </div>

    <!-- Read Text Screen Section -->
    <div class="card settings-section mb-4" x-data="{ open: false }">
        <header class="card-header is-clickable" @click="open = !open">
            <p class="card-header-title">
                <?php echo IconHelper::render('book-open', ['alt' => 'Read Text Screen']); ?>
                <span class="ml-2"><?= __('preferences.section_read_text_screen') ?></span>
            </p>
            <button
                type="button"
                class="card-header-icon"
                aria-label="<?= htmlspecialchars(__('preferences.toggle_section'), ENT_QUOTES, 'UTF-8') ?>"
            >
                <span class="icon">
                    <i :class="open ? 'rotate-180' : ''" class="transition-transform" data-lucide="chevron-down"></i>
                </span>
            </button>
        </header>
        <div class="card-content" x-show="open" x-transition>
            <div class="field">
                <label class="label" for="set-words-to-do-buttons">
                    <?= __('preferences.words_to_do_buttons_label') ?>
                </label>
                <div class="field has-addons">
                    <div class="control is-expanded">
                        <div class="select is-fullwidth">
                            <select name="set-words-to-do-buttons"
                                :value="settingValue('set-words-to-do-buttons')"
                                @change="onFieldInput('set-words-to-do-buttons', $event)"
                                    id="set-words-to-do-buttons"
                                    class="notempty"
                                    required>
                                <?php
                                echo SelectOptionsBuilder::forWordsToDoButtons(
                                    $settings['set-words-to-do-buttons']
                                );
                                ?>
                            </select>
                        </div>
                    </div>
                    <div class="control">
                        <span
                            class="icon has-text-danger"
                            title="<?= htmlspecialchars(__('preferences.field_required'), ENT_QUOTES, 'UTF-8') ?>"
                        >
                            <?php echo IconHelper::render('asterisk', ['alt' => 'Required']); ?>
                        </span>
                    </div>
                </div>
                <p class="help"><?= __('preferences.words_to_do_buttons_help') ?></p>
            </div>

            <div class="field">
                <label class="label" for="set-tooltip-mode"><?= __('preferences.tooltips_label') ?></label>
                <div class="field has-addons">
                    <div class="control is-expanded">
                        <div class="select is-fullwidth">
                            <select name="set-tooltip-mode"
                                :value="settingValue('set-tooltip-mode')"
                                @change="onFieldInput('set-tooltip-mode', $event)"
                                id="set-tooltip-mode" class="notempty" required>
                                <?php echo SelectOptionsBuilder::forTooltipType($settings['set-tooltip-mode']); ?>
                            </select>
                        </div>
                    </div>
                    <div class="control">
                        <span
                            class="icon has-text-danger"
                            title="<?= htmlspecialchars(__('preferences.field_required'), ENT_QUOTES, 'UTF-8') ?>"
                        >
                            <?php echo IconHelper::render('asterisk', ['alt' => 'Required']); ?>
                        </span>
                    </div>
                </div>
                <p class="help"><?= __('preferences.tooltips_help') ?></p>
            </div>

            <div class="field">
                <label class="label" for="set-ggl-translation-per-page">
                    <?= __('preferences.translations_per_page_label') ?>
                </label>
                <div class="field has-addons">
                    <div class="control">
                        <input class="input notempty posintnumber"
                               type="number"
                               min="0"
                               id="set-ggl-translation-per-page"
                               name="set-ggl-translation-per-page"
                               data_info="New Term Translations per Page"
                               :value="settingValue('set-ggl-translation-per-page')"
                               @input="onFieldInput('set-ggl-translation-per-page', $event)"
                               maxlength="4"
                               style="width: 100px;"
                               required />
                    </div>
                    <div class="control">
                        <span
                            class="icon has-text-danger"
                            title="<?= htmlspecialchars(__('preferences.field_required'), ENT_QUOTES, 'UTF-8') ?>"
                        >
                            <?php echo IconHelper::render('asterisk', ['alt' => 'Required']); ?>
                        </span>
                    </div>
                </div>
                <p class="help"><?= __('preferences.translations_per_page_help') ?></p>
            </div>
        </div>
    </div>

    <!-- Review Screen Section -->
    <div class="card settings-section mb-4" x-data="{ open: false }">
        <header class="card-header is-clickable" @click="open = !open">
            <p class="card-header-title">
                <?php echo IconHelper::render('graduation-cap', ['alt' => 'Review Screen']); ?>
                <span class="ml-2"><?= __('preferences.section_review_screen') ?></span>
            </p>
            <button
                type="button"
                class="card-header-icon"
                aria-label="<?= htmlspecialchars(__('preferences.toggle_section'), ENT_QUOTES, 'UTF-8') ?>"
            >
                <span class="icon">
                    <i :class="open ? 'rotate-180' : ''" class="transition-transform" data-lucide="chevron-down"></i>
                </span>
            </button>
        </header>
        <div class="card-content" x-show="open" x-transition>
            <div class="field">
                <label class="label" for="set-test-main-frame-waiting-time">
                    <?= __('preferences.display_next_review_after_label') ?>
                </label>
                <div class="field has-addons">
                    <div class="control">
                        <input class="input notempty zeroposintnumber"
                               type="number"
                               min="0"
                               id="set-test-main-frame-waiting-time"
                               name="set-test-main-frame-waiting-time"
                               data_info="Waiting time after assessment to display next review"
                               :value="settingValue('set-test-main-frame-waiting-time')"
                               @input="onFieldInput('set-test-main-frame-waiting-time', $event)"
                               maxlength="4"
                               style="width: 120px;"
                               required />
                    </div>
                    <div class="control">
                        <span class="button is-static"><?= __('preferences.milliseconds_unit') ?></span>
                    </div>
                    <div class="control">
                        <span
                            class="icon has-text-danger"
                            title="<?= htmlspecialchars(__('preferences.field_required'), ENT_QUOTES, 'UTF-8') ?>"
                        >
                            <?php echo IconHelper::render('asterisk', ['alt' => 'Required']); ?>
                        </span>
                    </div>
                </div>
                <p class="help"><?= __('preferences.display_next_review_after_help') ?></p>
            </div>

            <div class="field">
                <label class="label" for="set-test-edit-frame-waiting-time">
                    <?= __('preferences.clear_edit_frame_after_label') ?>
                </label>
                <div class="field has-addons">
                    <div class="control">
                        <input class="input notempty zeroposintnumber"
                               type="number"
                               min="0"
                               id="set-test-edit-frame-waiting-time"
                               name="set-test-edit-frame-waiting-time"
                               data_info="Waiting Time to clear the message/edit frame"
                               :value="settingValue('set-test-edit-frame-waiting-time')"
                               @input="onFieldInput('set-test-edit-frame-waiting-time', $event)"
                               maxlength="8"
                               style="width: 120px;"
                               required />
                    </div>
                    <div class="control">
                        <span class="button is-static"><?= __('preferences.milliseconds_unit') ?></span>
                    </div>
                    <div class="control">
                        <span
                            class="icon has-text-danger"
                            title="<?= htmlspecialchars(__('preferences.field_required'), ENT_QUOTES, 'UTF-8') ?>"
                        >
                            <?php echo IconHelper::render('asterisk', ['alt' => 'Required']); ?>
                        </span>
                    </div>
                </div>
                <p class="help"><?= __('preferences.clear_edit_frame_after_help') ?></p>
            </div>
        </div>
    </div>

    <!-- Reading Section -->
    <div class="card settings-section mb-4" x-data="{ open: false }">
        <header class="card-header is-clickable" @click="open = !open">
            <p class="card-header-title">
                <?php echo IconHelper::render('glasses', ['alt' => 'Reading']); ?>
                <span class="ml-2"><?= __('preferences.section_reading') ?></span>
            </p>
            <button
                type="button"
                class="card-header-icon"
                aria-label="<?= htmlspecialchars(__('preferences.toggle_section'), ENT_QUOTES, 'UTF-8') ?>"
            >
                <span class="icon">
                    <i :class="open ? 'rotate-180' : ''" class="transition-transform" data-lucide="chevron-down"></i>
                </span>
            </button>
        </header>
        <div class="card-content" x-show="open" x-transition>
            <div class="field">
                <label class="label" for="set-text-visit-statuses-via-key">
                    <?= __('preferences.navigate_term_statuses_label') ?>
                </label>
                <div class="control">
                    <div class="select is-fullwidth">
                        <select name="set-text-visit-statuses-via-key"
                                :value="settingValue('set-text-visit-statuses-via-key')"
                                @change="onFieldInput('set-text-visit-statuses-via-key', $event)"
                                id="set-text-visit-statuses-via-key">
                            <?php
                            echo SelectOptionsBuilder::forWordStatus(
                                $settings['set-text-visit-statuses-via-key'],
                                true,
                                true,
                                true
                            );
                            ?>
                        </select>
                    </div>
                </div>
                <p class="help"><?= __('preferences.navigate_term_statuses_help') ?></p>
            </div>

            <div class="field">
                <label class="label" for="set-display-text-frame-term-translation">
                    <?= __('preferences.show_translations_for_label') ?>
                </label>
                <div class="control">
                    <div class="select is-fullwidth">
                        <select name="set-display-text-frame-term-translation"
                                :value="settingValue('set-display-text-frame-term-translation')"
                                @change="onFieldInput('set-display-text-frame-term-translation', $event)"
                                id="set-display-text-frame-term-translation">
                            <?php
                            echo SelectOptionsBuilder::forWordStatus(
                                $settings['set-display-text-frame-term-translation'],
                                true,
                                true,
                                true
                            );
                            ?>
                        </select>
                    </div>
                </div>
                <p class="help"><?= __('preferences.show_translations_for_help') ?></p>
            </div>

            <div class="field">
                <label class="label" for="set-text-frame-annotation-position">
                    <?= __('preferences.translation_position_label') ?>
                </label>
                <div class="field has-addons">
                    <div class="control is-expanded">
                        <div class="select is-fullwidth">
                            <select name="set-text-frame-annotation-position"
                                :value="settingValue('set-text-frame-annotation-position')"
                                @change="onFieldInput('set-text-frame-annotation-position', $event)"
                                    id="set-text-frame-annotation-position"
                                    class="notempty"
                                    required>
                                <?php
                                echo SelectOptionsBuilder::forAnnotationPosition(
                                    $settings['set-text-frame-annotation-position']
                                );
                                ?>
                            </select>
                        </div>
                    </div>
                    <div class="control">
                        <span
                            class="icon has-text-danger"
                            title="<?= htmlspecialchars(__('preferences.field_required'), ENT_QUOTES, 'UTF-8') ?>"
                        >
                            <?php echo IconHelper::render('asterisk', ['alt' => 'Required']); ?>
                        </span>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Review & Terms Section -->
    <div class="card settings-section mb-4" x-data="{ open: false }">
        <header class="card-header is-clickable" @click="open = !open">
            <p class="card-header-title">
                <?php echo IconHelper::render('spell-check', ['alt' => 'Review & Terms']); ?>
                <span class="ml-2"><?= __('preferences.section_review_terms') ?></span>
            </p>
            <button
                type="button"
                class="card-header-icon"
                aria-label="<?= htmlspecialchars(__('preferences.toggle_section'), ENT_QUOTES, 'UTF-8') ?>"
            >
                <span class="icon">
                    <i :class="open ? 'rotate-180' : ''" class="transition-transform" data-lucide="chevron-down"></i>
                </span>
            </button>
        </header>
        <div class="card-content" x-show="open" x-transition>
            <div class="field">
                <label class="label" for="set-test-sentence-count">
                    <?= __('preferences.review_sentence_count_label') ?>
                </label>
                <div class="field has-addons">
                    <div class="control is-expanded">
                        <div class="select is-fullwidth">
                            <select name="set-test-sentence-count"
                                :value="settingValue('set-test-sentence-count')"
                                @change="onFieldInput('set-test-sentence-count', $event)"
                                    id="set-test-sentence-count"
                                    class="notempty"
                                    required>
                                <?php
                                echo SelectOptionsBuilder::forSentenceCount(
                                    $settings['set-test-sentence-count']
                                );
                                ?>
                            </select>
                        </div>
                    </div>
                    <div class="control">
                        <span
                            class="icon has-text-danger"
                            title="<?= htmlspecialchars(__('preferences.field_required'), ENT_QUOTES, 'UTF-8') ?>"
                        >
                            <?php echo IconHelper::render('asterisk', ['alt' => 'Required']); ?>
                        </span>
                    </div>
                </div>
                <p class="help"><?= __('preferences.review_sentence_count_help') ?></p>
            </div>

            <div class="field">
                <label class="label" for="set-term-sentence-count">
                    <?= __('preferences.term_sentence_count_label') ?>
                </label>
                <div class="field has-addons">
                    <div class="control is-expanded">
                        <div class="select is-fullwidth">
                            <select name="set-term-sentence-count"
                                :value="settingValue('set-term-sentence-count')"
                                @change="onFieldInput('set-term-sentence-count', $event)"
                                    id="set-term-sentence-count"
                                    class="notempty"
                                    required>
                                <?php
                                echo SelectOptionsBuilder::forSentenceCount(
                                    $settings['set-term-sentence-count']
                                );
                                ?>
                            </select>
                        </div>
                    </div>
                    <div class="control">
                        <span
                            class="icon has-text-danger"
                            title="<?= htmlspecialchars(__('preferences.field_required'), ENT_QUOTES, 'UTF-8') ?>"
                        >
                            <?php echo IconHelper::render('asterisk', ['alt' => 'Required']); ?>
                        </span>
                    </div>
                </div>
                <p class="help"><?= __('preferences.term_sentence_count_help') ?></p>
            </div>

            <div class="field">
                <label class="label" for="set-similar-terms-count">
                    <?= __('preferences.similar_terms_count_label') ?>
                </label>
                <div class="field has-addons">
                    <div class="control">
                        <input class="input notempty zeroposintnumber"
                               type="number"
                               min="0"
                               max="9"
                               id="set-similar-terms-count"
                               name="set-similar-terms-count"
                               data_info="Similar terms to be displayed while adding/editing a term"
                               :value="settingValue('set-similar-terms-count')"
                               @input="onFieldInput('set-similar-terms-count', $event)"
                               maxlength="1"
                               style="width: 80px;"
                               required />
                    </div>
                    <div class="control">
                        <span
                            class="icon has-text-danger"
                            title="<?= htmlspecialchars(__('preferences.field_required'), ENT_QUOTES, 'UTF-8') ?>"
                        >
                            <?php echo IconHelper::render('asterisk', ['alt' => 'Required']); ?>
                        </span>
                    </div>
                </div>
                <p class="help"><?= __('preferences.similar_terms_count_help') ?></p>
            </div>

            <div class="field">
                <label class="label" for="set-term-translation-delimiters">
                    <?= __('preferences.translation_delimiters_label') ?>
                </label>
                <div class="field has-addons">
                    <div class="control">
                        <input class="input notempty"
                               type="text"
                               id="set-term-translation-delimiters"
                               name="set-term-translation-delimiters"
                               :value="settingValue('set-term-translation-delimiters')"
                               @input="onFieldInput('set-term-translation-delimiters', $event)"
                               maxlength="8"
                               style="width: 120px;"
                               required />
                    </div>
                    <div class="control">
                        <span
                            class="icon has-text-danger"
                            title="<?= htmlspecialchars(__('preferences.field_required'), ENT_QUOTES, 'UTF-8') ?>"
                        >
                            <?php echo IconHelper::render('asterisk', ['alt' => 'Required']); ?>
                        </span>
                    </div>
                </div>
                <p class="help"><?= __('preferences.translation_delimiters_help') ?></p>
            </div>
        </div>
    </div>

    <!-- Text to Speech Section -->
    <div class="card settings-section mb-4" x-data="{ open: false }">
        <header class="card-header is-clickable" @click="open = !open">
            <p class="card-header-title">
                <?php echo IconHelper::render('volume-2', ['alt' => 'Text to Speech']); ?>
                <span class="ml-2"><?= __('preferences.section_tts') ?></span>
            </p>
            <button
                type="button"
                class="card-header-icon"
                aria-label="<?= htmlspecialchars(__('preferences.toggle_section'), ENT_QUOTES, 'UTF-8') ?>"
            >
                <span class="icon">
                    <i :class="open ? 'rotate-180' : ''" class="transition-transform" data-lucide="chevron-down"></i>
                </span>
            </button>
        </header>
        <div class="card-content" x-show="open" x-transition>
            <div class="field">
                <label class="label"><?= __('preferences.save_audio_to_disk_label') ?></label>
                <div class="control">
                    <label class="checkbox">
                        <input type="checkbox"
                               name="set-tts"
                               :checked="isOn('set-tts')"
                               @change="toggle('set-tts', $event)"
                               value="1" />
                        <?= __('preferences.save_audio_checkbox_label') ?>
                    </label>
                </div>
            </div>

            <div class="field">
                <label class="label" for="set-hts"><?= __('preferences.read_word_aloud_label') ?></label>
                <div class="field has-addons">
                    <div class="control is-expanded">
                        <div class="select is-fullwidth">
                            <select name="set-hts"
                                :value="settingValue('set-hts')"
                                @change="onFieldInput('set-hts', $event)" id="set-hts" class="notempty" required>
                                <?php echo SelectOptionsBuilder::forHoverTranslation($settings['set-hts']); ?>
                            </select>
                        </div>
                    </div>
                    <div class="control">
                        <span
                            class="icon has-text-danger"
                            title="<?= htmlspecialchars(__('preferences.field_required'), ENT_QUOTES, 'UTF-8') ?>"
                        >
                            <?php echo IconHelper::render('asterisk', ['alt' => 'Required']); ?>
                        </span>
                    </div>
                </div>
            </div>

            <hr class="my-4">

            <!-- Browser Voice Settings - Alpine.js Component -->
            <?php ConfigIsland::render(
                'tts-settings-config',
                ['currentLanguageCode' => $currentLanguageCode]
            ); ?>
            <div x-data="ttsSettingsApp"
                 @submit.window="saveSettings()">

                <h4 class="subtitle is-6 mb-3"><?= __('preferences.browser_voice_settings') ?></h4>
                <p class="help mb-3"><?= __('preferences.browser_voice_settings_help') ?></p>

                <!-- Language Code -->
                <div class="field">
                    <label class="label" for="get-language"><?= __('preferences.voice_language_label') ?></label>
                    <div class="control">
                        <div class="select is-fullwidth">
                            <select name="LgName"
                                    id="get-language"
                                    x-model="currentLanguage"
                                    @change="onLanguageChange()">
                                <?php echo $languageOptions; ?>
                            </select>
                        </div>
                    </div>
                </div>

                <!-- Voice Selection -->
                <div class="field">
                    <label class="label" for="voice"><?= __('preferences.voice_label') ?></label>
                    <div class="control">
                        <div class="select is-fullwidth">
                            <select name="LgVoice" id="voice" x-model="selectedVoice">
                                <template x-if="voicesLoading">
                                    <option value="">Loading voices...</option>
                                </template>
                                <template x-if="!voicesLoading && voices.length === 0">
                                    <option value="">No voices available</option>
                                </template>
                                <template x-for="voice in voices" :key="voice.name">
                                    <option :value="voice.name"
                                            :data-lang="voice.lang"
                                            :data-name="voice.name"
                                            x-text="getVoiceDisplayName(voice)">
                                    </option>
                                </template>
                            </select>
                        </div>
                    </div>
                    <p class="help"><?= __('preferences.voice_help') ?></p>
                </div>

                <!-- Reading Rate -->
                <div class="field">
                    <label class="label" for="rate"><?= __('preferences.reading_rate_label') ?></label>
                    <div class="control">
                        <div class="columns is-vcentered is-mobile">
                            <div class="column is-narrow">
                                <span class="tag is-light">0.5x</span>
                            </div>
                            <div class="column">
                                <input type="range"
                                       name="LgTTSRate"
                                       id="rate"
                                       class="slider is-fullwidth"
                                       min="0.5"
                                       max="2"
                                       step="0.1"
                                       x-model.number="rate" />
                            </div>
                            <div class="column is-narrow">
                                <span class="tag is-light">2x</span>
                            </div>
                            <div class="column is-narrow">
                                <span class="tag is-info" x-text="getRateDisplay()"></span>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Pitch -->
                <div class="field">
                    <label class="label" for="pitch"><?= __('preferences.pitch_label') ?></label>
                    <div class="control">
                        <div class="columns is-vcentered is-mobile">
                            <div class="column is-narrow">
                                <span class="tag is-light"><?= __('preferences.pitch_low') ?></span>
                            </div>
                            <div class="column">
                                <input type="range"
                                       name="LgPitch"
                                       id="pitch"
                                       class="slider is-fullwidth"
                                       min="0"
                                       max="2"
                                       step="0.1"
                                       x-model.number="pitch" />
                            </div>
                            <div class="column is-narrow">
                                <span class="tag is-light"><?= __('preferences.pitch_high') ?></span>
                            </div>
                            <div class="column is-narrow">
                                <span class="tag is-info" x-text="pitch"></span>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Demo -->
                <div class="field">
                    <label class="label" for="tts-demo"><?= __('preferences.test_label') ?></label>
                    <div class="control">
                        <div class="columns is-vcentered">
                            <div class="column">
                                <input type="text"
                                       class="input"
                                       id="tts-demo"
                                       placeholder="Enter text to test speech..."
                                       x-model="demoText" />
                            </div>
                            <div class="column is-narrow">
                                <button type="button"
                                        class="button is-info"
                                        @click="playDemo()">
                                    <span class="icon is-small">
                                        <?php echo IconHelper::render('play', ['alt' => 'Play']); ?>
                                    </span>
                                    <span><?= __('preferences.test_button') ?></span>
                                </button>
                            </div>
                        </div>
                    </div>
                    <p class="help"><?= __('preferences.voice_storage_help') ?></p>
                </div>
            </div>
        </div>
    </div>

    <!-- Tables & Pagination Section -->
    <div class="card settings-section mb-4" x-data="{ open: false }">
        <header class="card-header is-clickable" @click="open = !open">
            <p class="card-header-title">
                <?php echo IconHelper::render('table', ['alt' => 'Tables & Pagination']); ?>
                <span class="ml-2"><?= __('preferences.section_tables') ?></span>
            </p>
            <button
                type="button"
                class="card-header-icon"
                aria-label="<?= htmlspecialchars(__('preferences.toggle_section'), ENT_QUOTES, 'UTF-8') ?>"
            >
                <span class="icon">
                    <i :class="open ? 'rotate-180' : ''" class="transition-transform" data-lucide="chevron-down"></i>
                </span>
            </button>
        </header>
        <div class="card-content" x-show="open" x-transition>
            <div class="columns">
                <div class="column is-half">
                    <div class="field">
                        <label class="label" for="set-texts-per-page">
                            <?= __('preferences.texts_per_page_label') ?>
                        </label>
                        <div class="field has-addons">
                            <div class="control is-expanded">
                                <input class="input notempty posintnumber"
                                       type="number"
                                       min="0"
                                       id="set-texts-per-page"
                                       name="set-texts-per-page"
                                       data_info="Texts per Page"
                                       :value="settingValue('set-texts-per-page')"
                               @input="onFieldInput('set-texts-per-page', $event)"
                                       maxlength="4"
                                       required />
                            </div>
                            <div class="control">
                                <span
                            class="icon has-text-danger"
                            title="<?= htmlspecialchars(__('preferences.field_required'), ENT_QUOTES, 'UTF-8') ?>"
                        >
                                    <?php echo IconHelper::render('asterisk', ['alt' => 'Required']); ?>
                                </span>
                            </div>
                        </div>
                        <p class="help"><?= __('preferences.texts_per_page_help') ?></p>
                    </div>
                </div>

                <div class="column is-half">
                    <div class="field">
                        <label class="label" for="set-archived_texts-per-page">
                            <?= __('preferences.archived_texts_per_page_label') ?>
                        </label>
                        <div class="field has-addons">
                            <div class="control is-expanded">
                                <input class="input notempty posintnumber"
                                       type="number"
                                       min="0"
                                       id="set-archived_texts-per-page"
                                       name="set-archived_texts-per-page"
                                       data_info="Archived Texts per Page"
                                       :value="settingValue('set-archived_texts-per-page')"
                                       @input="onFieldInput('set-archived_texts-per-page', $event)"
                                       maxlength="4"
                                       required />
                            </div>
                            <div class="control">
                                <span
                            class="icon has-text-danger"
                            title="<?= htmlspecialchars(__('preferences.field_required'), ENT_QUOTES, 'UTF-8') ?>"
                        >
                                    <?php echo IconHelper::render('asterisk', ['alt' => 'Required']); ?>
                                </span>
                            </div>
                        </div>
                        <p class="help"><?= __('preferences.archived_texts_per_page_help') ?></p>
                    </div>
                </div>
            </div>

            <div class="columns">
                <div class="column is-half">
                    <div class="field">
                        <label class="label" for="set-terms-per-page">
                            <?= __('preferences.terms_per_page_label') ?>
                        </label>
                        <div class="field has-addons">
                            <div class="control is-expanded">
                                <input class="input notempty posintnumber"
                                       type="number"
                                       min="0"
                                       id="set-terms-per-page"
                                       name="set-terms-per-page"
                                       data_info="Terms per Page"
                                       :value="settingValue('set-terms-per-page')"
                               @input="onFieldInput('set-terms-per-page', $event)"
                                       maxlength="4"
                                       required />
                            </div>
                            <div class="control">
                                <span
                            class="icon has-text-danger"
                            title="<?= htmlspecialchars(__('preferences.field_required'), ENT_QUOTES, 'UTF-8') ?>"
                        >
                                    <?php echo IconHelper::render('asterisk', ['alt' => 'Required']); ?>
                                </span>
                            </div>
                        </div>
                        <p class="help"><?= __('preferences.terms_per_page_help') ?></p>
                    </div>
                </div>

                <div class="column is-half">
                    <div class="field">
                        <label class="label" for="set-tags-per-page">
                            <?= __('preferences.tags_per_page_label') ?>
                        </label>
                        <div class="field has-addons">
                            <div class="control is-expanded">
                                <input class="input notempty posintnumber"
                                       type="number"
                                       min="0"
                                       id="set-tags-per-page"
                                       name="set-tags-per-page"
                                       data_info="Tags per Page"
                                       :value="settingValue('set-tags-per-page')"
                               @input="onFieldInput('set-tags-per-page', $event)"
                                       maxlength="4"
                                       required />
                            </div>
                            <div class="control">
                                <span
                            class="icon has-text-danger"
                            title="<?= htmlspecialchars(__('preferences.field_required'), ENT_QUOTES, 'UTF-8') ?>"
                        >
                                    <?php echo IconHelper::render('asterisk', ['alt' => 'Required']); ?>
                                </span>
                            </div>
                        </div>
                        <p class="help"><?= __('preferences.tags_per_page_help') ?></p>
                    </div>
                </div>
            </div>

            <div class="columns">
                <div class="column is-half">
                    <div class="field">
                        <label class="label" for="set-articles-per-page">
                            <?= __('preferences.feed_articles_per_page_label') ?>
                        </label>
                        <div class="field has-addons">
                            <div class="control is-expanded">
                                <input class="input notempty posintnumber"
                                       type="number"
                                       min="0"
                                       id="set-articles-per-page"
                                       name="set-articles-per-page"
                                       data_info="Feed Articles per Page"
                                       :value="settingValue('set-articles-per-page')"
                               @input="onFieldInput('set-articles-per-page', $event)"
                                       maxlength="4"
                                       required />
                            </div>
                            <div class="control">
                                <span
                            class="icon has-text-danger"
                            title="<?= htmlspecialchars(__('preferences.field_required'), ENT_QUOTES, 'UTF-8') ?>"
                        >
                                    <?php echo IconHelper::render('asterisk', ['alt' => 'Required']); ?>
                                </span>
                            </div>
                        </div>
                        <p class="help"><?= __('preferences.feed_articles_per_page_help') ?></p>
                    </div>
                </div>

                <div class="column is-half">
                    <div class="field">
                        <label class="label" for="set-feeds-per-page">
                            <?= __('preferences.feeds_per_page_label') ?>
                        </label>
                        <div class="field has-addons">
                            <div class="control is-expanded">
                                <input class="input notempty posintnumber"
                                       type="number"
                                       min="0"
                                       id="set-feeds-per-page"
                                       name="set-feeds-per-page"
                                       data_info="Feeds per Page"
                                       :value="settingValue('set-feeds-per-page')"
                               @input="onFieldInput('set-feeds-per-page', $event)"
                                       maxlength="4"
                                       required />
                            </div>
                            <div class="control">
                                <span
                            class="icon has-text-danger"
                            title="<?= htmlspecialchars(__('preferences.field_required'), ENT_QUOTES, 'UTF-8') ?>"
                        >
                                    <?php echo IconHelper::render('asterisk', ['alt' => 'Required']); ?>
                                </span>
                            </div>
                        </div>
                        <p class="help"><?= __('preferences.feeds_per_page_help') ?></p>
                    </div>
                </div>
            </div>

            <div class="field">
                <label class="label" for="set-regex-mode"><?= __('preferences.query_mode_label') ?></label>
                <div class="control">
                    <div class="select is-fullwidth">
                        <select name="set-regex-mode"
                                :value="settingValue('set-regex-mode')"
                                @change="onFieldInput('set-regex-mode', $event)" id="set-regex-mode">
                            <?php echo SelectOptionsBuilder::forRegexMode($settings['set-regex-mode']); ?>
                        </select>
                    </div>
                </div>
                <p class="help"><?= __('preferences.query_mode_help') ?></p>
            </div>
        </div>
    </div>

    <!-- Form Actions -->
    <div class="field is-grouped is-grouped-right">
        <div class="control">
            <a href="/profile" class="button is-light">
                <span class="icon is-small">
                    <?php echo IconHelper::render('arrow-left', ['alt' => 'Back']); ?>
                </span>
                <span><?= __('preferences.back_to_profile') ?></span>
            </a>
        </div>
        <div class="control">
            <button type="submit" class="button is-primary" :disabled="isSaving">
                <span class="icon is-small">
                    <?php echo IconHelper::render('save', ['alt' => 'Save']); ?>
                </span>
                <span><?= __('preferences.save_preferences') ?></span>
            </button>
        </div>
    </div>
</form>
