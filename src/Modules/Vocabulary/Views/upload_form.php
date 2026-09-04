<?php

/**
 * Word Upload Form View
 *
 * Displays a unified interface for importing terms via:
 * - Frequency word lists (with Wiktionary enrichment)
 * - Curated dictionary browser
 * - Manual upload (CSV/TSV file, paste, or dictionary file)
 *
 * Expected variables:
 * - $languages: array - Array of languages for select dropdown
 * - $activeTab: string - Active tab ('frequency', 'dictionary', or 'manual')
 * - $curatedDictionaries: list<array<string, mixed>>|null - Curated dictionaries
 * - $isFrequencyAvailable: bool - Whether frequency data exists for current language
 * - $langId: int - Current language ID
 * - $currentLanguageName: string - Current language name
 * - $importUrl: string - AJAX endpoint for frequency word import
 * - $enrichUrl: string - AJAX endpoint for enrichment
 * - $csrfToken: string - CSRF token
 *
 * PHP version 8.2
 *
 * @category Lwt
 * @package  Lwt\Views\Word
 * @author   HugoFara <git@hugofara.net>
 * @license  Unlicense <http://unlicense.org/>
 * @link     https://hugofara.github.io/lwt/developer/api
 * @since    3.0.0
 */

declare(strict_types=1);

namespace Lwt\Views\Word;

use Lwt\Shared\Infrastructure\Database\Settings;
use Lwt\Shared\UI\Helpers\SelectOptionsBuilder;
use Lwt\Shared\UI\Helpers\IconHelper;
use Lwt\Shared\UI\Helpers\PageLayoutHelper;
use Lwt\Shared\UI\Helpers\ConfigIsland;

// Type assertions for variables passed from controller
assert(is_array($languages));
/** @var array<int, array{id: int, name: string}> $languages */
/** @var string|null $activeTab */
/** @var list<array<string, mixed>>|null $curatedDictionaries */
/** @var bool $isFrequencyAvailable */
/** @var int $langId */
/** @var string $currentLanguageName */
/** @var string $importUrl */
/** @var string $enrichUrl */
/** @var string $csrfToken */
if (!isset($curatedDictionaries)) {
    $curatedDictionaries = [];
}
if (!isset($activeTab)) {
    $activeTab = 'frequency';
}

// Column options for reuse (manual upload mode)
$columnOptions = [
    'w' => __('vocabulary.upload.manual.col_term'),
    't' => __('vocabulary.upload.manual.col_translation'),
    'r' => __('vocabulary.upload.manual.col_romanization'),
    's' => __('vocabulary.upload.manual.col_sentence'),
    'g' => __('vocabulary.upload.manual.col_tag_list'),
    'x' => __('vocabulary.upload.manual.col_dont_import'),
];

// Action buttons for navigation
$actions = [
    ['url' => '/words', 'label' => __('vocabulary.actions.my_terms'), 'icon' => 'list', 'class' => 'is-primary'],
    ['url' => '/term-tags', 'label' => __('vocabulary.actions.term_tags'), 'icon' => 'tags'],
];
echo PageLayoutHelper::buildActionCard($actions);
?>

<?php ConfigIsland::render('word-upload-page-config', [
        'activeTab' => $activeTab ?: 'frequency',
        'currentLanguageId' => $langId,
        'currentLanguageName' => $currentLanguageName,
        'isFrequencyAvailable' => $isFrequencyAvailable,
        'importUrl' => $importUrl,
        'enrichUrl' => $enrichUrl,
        'csrfToken' => $csrfToken,
    ]); ?>
<?php ConfigIsland::render('curated-dictionaries-config', $curatedDictionaries); ?>
<div x-data="wordUploadPageApp">

<!-- ==================== MAIN TABS ==================== -->
<div class="tabs is-boxed mb-4">
    <ul>
        <li :class="{ 'is-active': activeTab === 'frequency' }">
            <a @click.prevent="setActiveTab('frequency')">
                <span class="icon is-small">
                    <?php echo IconHelper::render('trending-up', ['alt' => 'Frequency']); ?>
                </span>
                <span><?= __('vocabulary.upload.frequency_words') ?></span>
            </a>
        </li>
        <li :class="{ 'is-active': activeTab === 'dictionary' }">
            <a @click.prevent="setActiveTab('dictionary')">
                <span class="icon is-small">
                    <?php echo IconHelper::render('book-open', ['alt' => 'Dictionaries']); ?>
                </span>
                <span><?= __('vocabulary.upload.dictionaries') ?></span>
            </a>
        </li>
        <li :class="{ 'is-active': activeTab === 'manual' }">
            <a @click.prevent="setActiveTab('manual')">
                <span class="icon is-small">
                    <?php echo IconHelper::render('file-up', ['alt' => 'Manual']); ?>
                </span>
                <span><?= __('vocabulary.upload.manual_upload') ?></span>
            </a>
        </li>
    </ul>
</div>

<!-- ==================== TAB 1: FREQUENCY WORDS ==================== -->
<div x-show="activeTab === 'frequency'" x-transition
     <?php echo $activeTab !== 'frequency' ? 'style="display:none"' : ''; ?>>

    <?php if (empty($currentLanguageName)) : ?>
    <div class="notification is-warning">
        <?= __('vocabulary.upload.select_language_first') ?>
    </div>
    <?php elseif (!$isFrequencyAvailable) : ?>
    <div class="notification is-info is-light">
        <?= __('vocabulary.upload.freq.not_available_html', [
            'lang' => htmlspecialchars($currentLanguageName, ENT_QUOTES, 'UTF-8'),
        ]) ?>
    </div>
    <?php else : ?>
    <!-- Step: Choose -->
    <template x-if="freqStep === 'choose'">
        <div class="box">
            <p class="mb-4">
                <?= __('vocabulary.upload.freq.intro_html', [
                    'lang' => htmlspecialchars($currentLanguageName, ENT_QUOTES, 'UTF-8'),
                ]) ?>
            </p>

            <div class="field">
                <label class="label"><?= __e('vocabulary.upload.freq.enrichment_mode') ?></label>
                <div class="control">
                    <label class="radio">
                        <input type="radio" x-model="freqMode" value="translation">
                        <?= __e('vocabulary.upload.freq.translation') ?>
                        <span class="has-text-grey is-size-7">
                            <?= __e('vocabulary.upload.freq.translation_hint') ?>
                        </span>
                    </label>
                </div>
                <div class="control mt-1">
                    <label class="radio">
                        <input type="radio" x-model="freqMode" value="definition">
                        <?= __e('vocabulary.upload.freq.definition') ?>
                        <span class="has-text-grey is-size-7">
                            <?= __e('vocabulary.upload.freq.definition_hint') ?>
                        </span>
                    </label>
                </div>
            </div>

            <hr>
            <div class="field">
                <label class="label"><?= __e('vocabulary.upload.freq.how_many') ?></label>
                <div class="buttons has-addons">
                    <button type="button" :class="sizeClass(50)"
                            @click="setSize(50)">50</button>
                    <button type="button" :class="sizeClass(100)"
                            @click="setSize(100)">100</button>
                    <button type="button" :class="sizeClass(500)"
                            @click="setSize(500)">500</button>
                </div>
                <p class="help has-text-grey">
                    <?= __('vocabulary.upload.freq.source_help_html') ?>
                </p>
            </div>

            <div class="field mt-5">
                <div class="control">
                    <button type="button" class="button is-success"
                            @click="startFrequencyImport()">
                        <span class="icon is-small">
                            <?php echo IconHelper::render('download', ['alt' => 'Import']); ?>
                        </span>
                        <span><?= __e('vocabulary.upload.import') ?></span>
                    </button>
                </div>
            </div>
        </div>
    </template>

    <!-- Step: Importing -->
    <template x-if="freqStep === 'importing'">
        <div class="box">
            <p class="mb-3">
                <strong><?= __e('vocabulary.upload.freq.fetching') ?></strong>
            </p>
            <progress class="progress is-info" max="100"></progress>
            <p class="has-text-grey is-size-7">
                <?= __e('vocabulary.upload.freq.fetching_help') ?>
            </p>
        </div>
    </template>

    <!-- Step: Enriching -->
    <template x-if="freqStep === 'enriching'">
        <div class="box">
            <p class="mb-3">
                <strong x-text="freqEnrichingLabel()"></strong>
            </p>
            <progress class="progress is-success" :value="enrichProgress" max="100"></progress>
            <p class="is-size-7 mb-3">
                <span x-text="enrichStats.done"></span>
                <?= __e('vocabulary.upload.freq.words_enriched_of') ?>
                <span x-text="enrichStats.total"></span>
                <?= __e('vocabulary.upload.freq.words_enriched_suffix') ?>
                <template x-if="enrichStats.failed > 0">
                    <span class="has-text-grey">(<span x-text="enrichStats.failed"></span>
                        <?= __e('vocabulary.upload.freq.not_found') ?>)</span>
                </template>
            </p>

            <template x-if="enrichWarning">
                <div class="notification is-warning is-light is-size-7 p-3 mb-3" x-text="enrichWarning"></div>
            </template>

            <div class="field is-grouped">
                <div class="control">
                    <button type="button" class="button is-warning is-small" @click="stopEnrichment()">
                        <?= __e('vocabulary.upload.freq.stop_continue') ?>
                    </button>
                </div>
            </div>
        </div>
    </template>

    <!-- Step: Done -->
    <template x-if="freqStep === 'done'">
        <div class="box">
            <div class="notification is-success is-light">
                <template x-if="freqResult.imported > 0 || freqResult.skipped > 0">
                    <p>
                        <?= __e('vocabulary.upload.freq.imported_words') ?>
                        <strong x-text="freqResult.imported"></strong>
                        <?= __e('vocabulary.upload.freq.words') ?>
                        <template x-if="freqResult.skipped > 0">
                            <span>(<span x-text="freqResult.skipped"></span>
                                <?= __e('vocabulary.upload.freq.already_existed') ?>)</span>
                        </template>
                        <?= __e('vocabulary.upload.freq.for_lang') ?>
                        <strong><?php echo htmlspecialchars($currentLanguageName, ENT_QUOTES, 'UTF-8'); ?></strong>.
                    </p>
                </template>
                <template x-if="enrichStats.done > 0">
                    <p class="mt-1">
                        <span x-text="enrichStats.done"></span>
                        <?= __e('vocabulary.upload.freq.enriched_with') ?>
                        <span x-text="freqEnrichedModeLabel()"></span>.
                    </p>
                </template>
            </div>

            <div class="field is-grouped">
                <div class="control">
                    <button type="button" class="button is-primary" @click="resetFrequencyImport()">
                        <?= __e('vocabulary.upload.import_more') ?>
                    </button>
                </div>
                <div class="control">
                    <a class="button" href="/words?lang=<?php echo $langId; ?>">
                        <?= __e('vocabulary.upload.freq.view_vocabulary') ?>
                    </a>
                </div>
            </div>
        </div>
    </template>

    <!-- Step: Error -->
    <template x-if="freqStep === 'error'">
        <div class="box">
            <div class="notification is-danger is-light">
                <strong><?= __e('vocabulary.upload.import_failed') ?></strong>
                <span x-text="freqError"></span>
            </div>
            <div class="field">
                <div class="control">
                    <button type="button" class="button" @click="resetFrequencyImport()">
                        <?= __e('vocabulary.upload.try_again') ?>
                    </button>
                </div>
            </div>
        </div>
    </template>

    <?php endif; ?>
</div>

<!-- ==================== TAB 2: DICTIONARIES ==================== -->
<div x-show="activeTab === 'dictionary'" x-transition
     <?php echo $activeTab !== 'dictionary' ? 'style="display:none"' : ''; ?>>
    <div x-data="curatedDictBrowser">
        <div class="notification is-info is-light mb-4">
            <?= __('vocabulary.upload.dict.intro_html') ?>
        </div>

        <!-- Batch import results -->
        <template x-for="(msg, i) in batchMessages" :key="i">
            <div :class="msg.success
                ? 'notification is-success is-light'
                : 'notification is-danger is-light'"
                class="mb-3">
                <button class="delete" @click="dismissMessage(i)"></button>
                <span x-text="msg.text"></span>
            </div>
        </template>

        <!-- Batch import progress -->
        <template x-if="batchImporting">
            <div class="notification is-info is-light mb-4">
                <p class="mb-2">
                    <strong><?= __e('vocabulary.upload.dict.importing') ?></strong>
                    <span x-text="batchCurrent"></span>
                    <?= __e('vocabulary.upload.freq.words_enriched_of') ?>
                    <span x-text="batchTotal"></span>
                </p>
                <progress class="progress is-info is-small" :value="batchCurrent" :max="batchTotal"></progress>
            </div>
        </template>

        <!-- Language filter + search -->
        <div class="field is-grouped mb-4">
            <div class="control">
                <div class="select">
                    <select x-model="dictLanguageFilter">
                        <option value=""><?= __e('vocabulary.upload.dict.all_languages') ?></option>
                        <template x-for="group in allGroups" :key="group.language">
                            <option :value="group.language" x-text="group.languageName"></option>
                        </template>
                    </select>
                </div>
            </div>
            <div class="control is-expanded">
                <input class="input" type="search"
                       placeholder="<?= __e('vocabulary.upload.dict.search_placeholder') ?>"
                       x-model="dictSearch" />
            </div>
        </div>

        <!-- Dictionary list grouped by language -->
        <template x-if="filteredGroups.length === 0">
            <div class="notification is-light">
                <?= __e('vocabulary.upload.dict.no_match') ?>
            </div>
        </template>

        <template x-for="group in filteredGroups" :key="group.language">
            <div class="mb-5">
                <h3 class="title is-5 mb-3" x-text="group.languageName"></h3>
                <template x-for="source in group.sources" :key="source.name">
                    <label class="box mb-3 p-4" style="cursor: pointer;"
                           :class="isSelected(source.url) ? 'has-background-success-light' : ''">
                        <div class="is-flex is-align-items-center">
                            <input type="checkbox" class="mr-3"
                                   :checked="isSelected(source.url)"
                                   :disabled="!source.directDownload || batchImporting"
                                   @change="toggleSelection(source.url)">
                            <div class="is-flex-grow-1">
                                <p class="has-text-weight-semibold mb-1" x-text="source.name"></p>
                                <div class="tags mb-1">
                                    <span class="tag is-info is-light" x-text="source.format"></span>
                                    <span class="tag is-light" x-text="source.entries"></span>
                                    <span class="tag is-success is-light" x-text="source.license"></span>
                                    <template x-if="source.targetLanguage">
                                        <span class="tag is-warning is-light"
                                            x-text="source.targetLanguage + ' '
                                                + $t('vocabulary.upload.dict.translations_suffix')">
                                        </span>
                                    </template>
                                </div>
                                <p class="is-size-7 has-text-grey" x-text="source.notes"></p>
                                <p class="is-size-7 has-text-warning-dark"
                                   x-show="!source.directDownload">
                                    <?= __e('vocabulary.upload.dict.manual_download') ?>
                                    <a :href="source.url" target="_blank" rel="noopener">
                                        <?= __e('vocabulary.upload.dict.visit_site') ?>
                                        <?php
                                        echo IconHelper::render(
                                            'external-link',
                                            ['alt' => 'Download', 'size' => 14]
                                        );
                                        ?>
                                    </a>
                                </p>
                            </div>
                        </div>
                    </label>
                </template>
            </div>
        </template>

        <!-- Import button -->
        <div class="field is-grouped mt-4">
            <div class="control">
                <button type="button" class="button is-success"
                        :disabled="getSelectedCount() === 0 || batchImporting"
                        @click="importSelected()">
                    <span class="icon is-small">
                        <?php echo IconHelper::render('download', ['alt' => 'Import']); ?>
                    </span>
                    <span><?= __e('vocabulary.upload.dict.import_selected') ?></span>
                </button>
            </div>
        </div>
    </div>
</div>

<!-- ==================== TAB 3: MANUAL UPLOAD ==================== -->
<div x-show="activeTab === 'manual'" x-transition
     <?php echo $activeTab !== 'manual' ? 'style="display:none"' : ''; ?>>

<form enctype="multipart/form-data"
      class="validate"
      action="/word/upload"
      method="post"
      x-data="wordUploadFormApp">
    <?php echo \Lwt\Shared\UI\Helpers\FormHelper::csrfField(); ?>
    <!-- Language ID from current language setting -->
    <input type="hidden" name="LgID" value="<?php echo $langId; ?>" />

    <!-- ==================== INPUT SOURCE ==================== -->
    <div class="box">
        <!-- Import Source Tabs -->
        <div class="field">
            <label class="label"><?= __e('vocabulary.upload.manual.import_from') ?></label>
            <div class="tabs is-boxed is-small mb-3">
                <ul>
                    <li :class="{ 'is-active': manualMethod === 'dict-file' }">
                        <a @click.prevent="setManualMethod('dict-file')">
                            <span class="icon is-small">
                                <?php echo IconHelper::render('book-open', ['alt' => 'Dictionary']); ?>
                            </span>
                            <span><?= __e('vocabulary.upload.manual.dict_file') ?></span>
                        </a>
                    </li>
                    <li :class="{ 'is-active': manualMethod === 'csv-file' }">
                        <a @click.prevent="setManualMethod('csv-file')">
                            <span class="icon is-small">
                                <?php echo IconHelper::render('file-up', ['alt' => 'File']); ?>
                            </span>
                            <span><?= __e('vocabulary.upload.manual.csv_tsv_file') ?></span>
                        </a>
                    </li>
                    <li :class="{ 'is-active': manualMethod === 'paste' }">
                        <a @click.prevent="setManualMethod('paste')">
                            <span class="icon is-small">
                                <?php echo IconHelper::render('clipboard-paste', ['alt' => 'Paste']); ?>
                            </span>
                            <span><?= __e('vocabulary.upload.manual.paste_text') ?></span>
                        </a>
                    </li>
                </ul>
            </div>

            <!-- Dictionary File -->
            <div x-show="manualMethod === 'dict-file'" x-transition>

                <!-- Upload section -->
                <h5 class="title is-6 mb-3"><?= __e('vocabulary.upload.manual.upload_dict_file') ?></h5>
                <div class="field mb-3">
                    <label class="label is-small"><?= __e('vocabulary.upload.manual.file_format') ?></label>
                    <div class="control">
                        <div class="select is-fullwidth">
                            <select name="dict_format" x-model="dictFormat">
                                <option value="csv"><?= __e('vocabulary.upload.manual.fmt_csv') ?></option>
                                <option value="json"><?= __e('vocabulary.upload.manual.fmt_json') ?></option>
                                <option value="stardict"><?= __e('vocabulary.upload.manual.fmt_stardict') ?></option>
                            </select>
                        </div>
                    </div>
                </div>
                <div class="file has-name is-fullwidth">
                    <label class="file-label">
                        <input class="file-input" type="file" name="dict_file"
                               @change="updateDictFileName($event)" />
                        <span class="file-cta">
                            <span class="file-icon">
                                <?php echo IconHelper::render('upload', ['alt' => 'Upload']); ?>
                            </span>
                            <span class="file-label"><?= __e('vocabulary.upload.manual.choose_file') ?></span>
                        </span>
                        <span class="file-name" x-text="dictFileLabel"></span>
                    </label>
                </div>
                <p class="help" x-show="dictFormat === 'stardict'">
                    <?= __e('vocabulary.upload.manual.stardict_help') ?>
                </p>
                <div class="field mt-3">
                    <label class="label is-small"><?= __e('vocabulary.upload.manual.dict_name') ?></label>
                    <div class="control">
                        <input type="text" name="dict_name" class="input is-small"
                               placeholder="<?= __e('vocabulary.upload.manual.dict_name_placeholder') ?>">
                    </div>
                </div>
            </div>

            <!-- CSV/TSV File Upload -->
            <div x-show="manualMethod === 'csv-file'" x-transition>
                <div class="file has-name is-fullwidth">
                    <label class="file-label">
                        <input class="file-input" type="file" name="thefile" />
                        <span class="file-cta">
                            <span class="file-icon">
                                <?php echo IconHelper::render('upload', ['alt' => 'Upload']); ?>
                            </span>
                            <span class="file-label"><?= __e('vocabulary.upload.manual.choose_file') ?></span>
                        </span>
                        <span class="file-name"><?= __e('vocabulary.upload.manual.no_file_selected') ?></span>
                    </label>
                </div>
                <p class="help"><?= __e('vocabulary.upload.manual.csv_help') ?></p>
            </div>

            <!-- Paste Text -->
            <div x-show="manualMethod === 'paste'" x-transition>
                <div class="control">
                    <textarea class="textarea checkoutsidebmp"
                              data_info="Upload"
                              name="Upload"
                              rows="10"
                              placeholder="<?= __e('vocabulary.upload.manual.paste_placeholder') ?>"></textarea>
                </div>
                <p class="help"><?= __e('vocabulary.upload.manual.paste_help') ?></p>
            </div>
        </div>
    </div>

    <!-- ==================== FORMAT SETTINGS (csv-file/paste modes) ==================== -->
    <div class="box" x-show="isNotDictFile" x-transition>
        <h4 class="title is-5 mb-4">
            <span class="icon-text">
                <span class="icon">
                    <?php echo IconHelper::render('settings-2', ['alt' => 'Settings']); ?>
                </span>
                <span><?= __e('vocabulary.upload.manual.format_settings') ?></span>
            </span>
        </h4>

        <div class="notification is-light is-small mb-4">
            <?= __('vocabulary.upload.manual.format_intro_html') ?>
        </div>

        <div class="columns">
            <div class="column is-half">
                <div class="field">
                    <label class="label is-small"><?= __e('vocabulary.upload.manual.field_delimiter') ?></label>
                    <div class="control">
                        <div class="select is-fullwidth">
                            <select name="Tab" x-model="delimiter">
                                <option value="c"><?= __e('vocabulary.upload.manual.delim_comma') ?></option>
                                <option value="t"><?= __e('vocabulary.upload.manual.delim_tab') ?></option>
                                <option value="h"><?= __e('vocabulary.upload.manual.delim_hash') ?></option>
                            </select>
                        </div>
                    </div>
                </div>
            </div>
            <div class="column is-half">
                <div class="field">
                    <label class="label is-small"><?= __e('vocabulary.upload.manual.ignore_first') ?></label>
                    <div class="control">
                        <div class="select is-fullwidth">
                            <select name="IgnFirstLine">
                                <option value="0" selected><?= __e('vocabulary.upload.manual.no') ?></option>
                                <option value="1"><?= __e('vocabulary.upload.manual.yes_header') ?></option>
                            </select>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Column Assignment -->
        <h5 class="title is-6 mt-4 mb-3"><?= __e('vocabulary.upload.manual.column_assignment') ?></h5>
        <div class="columns is-multiline">
            <?php
            $columnDefaults = ['w', 't', 'x', 'x', 'x'];
            for ($i = 1; $i <= 2; $i++) {
                /** @var int<0, 4> $colIndex */
                $colIndex = $i - 1;
                $default = $columnDefaults[$colIndex];
                ?>
            <div class="column is-half-tablet">
                <div class="field">
                    <label class="label is-small">
                        <?= __e('vocabulary.upload.manual.column_n', ['n' => $i]) ?>
                    </label>
                    <div class="control">
                        <div class="select is-fullwidth is-small">
                            <select name="Col<?php echo $i; ?>"
                                    x-model="cols[<?php echo $colIndex; ?>]">
                                <?php foreach ($columnOptions as $val => $label) : ?>
                                <option value="<?php echo $val; ?>"<?php
                                    echo ($val === $default) ? ' selected' : '';
                                ?>>
                                    <?php echo htmlspecialchars($label, ENT_QUOTES, 'UTF-8'); ?>
                                </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>
                </div>
            </div>
            <?php } ?>
        </div>

        <!-- Extra columns (shown on demand) -->
        <?php for ($i = 3; $i <= 5; $i++) {
            $colIndex = $i - 1;
            ?>
        <div class="columns" x-show="extraCols >= <?php echo $i - 2; ?>" x-transition>
            <div class="column is-half-tablet">
                <div class="field">
                    <label class="label is-small">
                        <?= __e('vocabulary.upload.manual.column_n', ['n' => $i]) ?>
                    </label>
                    <div class="control">
                        <div class="select is-fullwidth is-small">
                            <select name="Col<?php echo $i; ?>"
                                    x-model="cols[<?php echo $colIndex; ?>]">
                                <?php foreach ($columnOptions as $val => $label) : ?>
                                <option value="<?php echo $val; ?>"<?php
                                    echo ($val === 'x') ? ' selected' : '';
                                ?>>
                                    <?php echo htmlspecialchars($label, ENT_QUOTES, 'UTF-8'); ?>
                                </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <?php } ?>

        <div class="buttons mt-2">
            <button type="button"
                    class="button is-small is-light"
                    x-show="extraCols < 3"
                    @click="addColumn()">
                <span class="icon is-small">
                    <?php echo IconHelper::render('plus', ['alt' => 'Add']); ?>
                </span>
                <span><?= __e('vocabulary.upload.manual.add_column') ?></span>
            </button>
            <button type="button"
                    class="button is-small is-light"
                    x-show="extraCols > 0"
                    @click="removeColumn()">
                <span class="icon is-small">
                    <?php echo IconHelper::render('minus', ['alt' => 'Remove']); ?>
                </span>
                <span><?= __e('vocabulary.upload.manual.remove_column') ?></span>
            </button>
        </div>

        <!-- Live Preview -->
        <div class="mt-3" x-show="hasPreview()" x-transition>
            <h5 class="title is-6 mb-2"><?= __e('vocabulary.upload.manual.preview') ?></h5>
            <div class="table-container">
                <table class="table is-bordered is-narrow is-size-7 is-fullwidth">
                    <thead>
                        <tr>
                            <template x-for="header in previewHeaders()" :key="header">
                                <th x-text="header" class="has-background-light"></th>
                            </template>
                        </tr>
                    </thead>
                    <tbody>
                        <tr>
                            <template x-for="(cell, i) in previewRow()" :key="i">
                                <td x-text="cell"></td>
                            </template>
                        </tr>
                    </tbody>
                </table>
            </div>
            <p class="help has-text-grey">
                <?= __e('vocabulary.upload.manual.preview_help') ?>
            </p>
        </div>
    </div>

    <!-- ==================== DICTIONARY CSV OPTIONS (dict CSV mode only) ==================== -->
    <div class="box" x-show="showDictCsvOptions" x-transition>
        <h4 class="title is-5 mb-4">
            <span class="icon-text">
                <span class="icon">
                    <?php echo IconHelper::render('settings-2', ['alt' => 'Settings']); ?>
                </span>
                <span><?= __e('vocabulary.upload.manual.csv_options') ?></span>
            </span>
        </h4>

        <div class="columns">
            <div class="column is-one-third">
                <div class="field">
                    <label class="label is-small"><?= __e('vocabulary.upload.manual.delimiter') ?></label>
                    <div class="control">
                        <div class="select is-fullwidth">
                            <select name="dict_delimiter">
                                <option value=","><?= __e('vocabulary.upload.manual.delim_comma_short') ?></option>
                                <option value="tab"><?= __e('vocabulary.upload.manual.delim_tab_short') ?></option>
                                <option value=";"><?= __e('vocabulary.upload.manual.delim_semicolon') ?></option>
                            </select>
                        </div>
                    </div>
                </div>
            </div>
            <div class="column is-one-third">
                <div class="field">
                    <label class="label is-small"><?= __e('vocabulary.upload.manual.first_row') ?></label>
                    <div class="control">
                        <div class="select is-fullwidth">
                            <select name="dict_has_header">
                                <option value="yes"><?= __e('vocabulary.upload.manual.header_row') ?></option>
                                <option value="no"><?= __e('vocabulary.upload.manual.data_row') ?></option>
                            </select>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <div class="columns">
            <div class="column is-one-third">
                <div class="field">
                    <label class="label is-small"><?= __e('vocabulary.upload.manual.term_column') ?></label>
                    <div class="control">
                        <input type="number" name="dict_term_column" class="input is-small"
                               value="0" min="0">
                    </div>
                    <p class="help"><?= __e('vocabulary.upload.manual.first_col_help') ?></p>
                </div>
            </div>
            <div class="column is-one-third">
                <div class="field">
                    <label class="label is-small"><?= __e('vocabulary.upload.manual.definition_column') ?></label>
                    <div class="control">
                        <input type="number" name="dict_definition_column" class="input is-small"
                               value="1" min="0">
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- ==================== IMPORT OPTIONS (csv-file/paste modes) ==================== -->
    <div class="box" x-show="isNotDictFile" x-transition>
        <h4 class="title is-5 mb-4">
            <span class="icon-text">
                <span class="icon">
                    <?php echo IconHelper::render('package-import', ['alt' => 'Import']); ?>
                </span>
                <span><?= __e('vocabulary.upload.manual.import_options') ?></span>
            </span>
        </h4>

        <div class="columns">
            <div class="column is-half">
                <div class="field">
                    <label class="label is-small"><?= __e('vocabulary.upload.manual.import_mode') ?></label>
                    <div class="control">
                        <div class="select is-fullwidth">
                            <select name="Over"
                                    data-action="update-import-mode"
                                    x-model="importMode"
                                    @change="updateImportMode($event)">
                                <option value="0"
                                        title="<?= __e('vocabulary.upload.manual.mode_only_new_title') ?>">
                                    <?= __e('vocabulary.upload.manual.mode_only_new') ?>
                                </option>
                                <option value="1"
                                        title="<?= __e('vocabulary.upload.manual.mode_replace_title') ?>">
                                    <?= __e('vocabulary.upload.manual.mode_replace') ?>
                                </option>
                                <option value="2"
                                        title="<?= __e('vocabulary.upload.manual.mode_update_empty_title') ?>">
                                    <?= __e('vocabulary.upload.manual.mode_update_empty') ?>
                                </option>
                                <option value="3"
                                        title="<?= __e('vocabulary.upload.manual.mode_no_new_title') ?>">
                                    <?= __e('vocabulary.upload.manual.mode_no_new') ?>
                                </option>
                                <option value="4"
                                        title="<?= __e('vocabulary.upload.manual.mode_merge_title') ?>">
                                    <?= __e('vocabulary.upload.manual.mode_merge') ?>
                                </option>
                                <option value="5"
                                        title="<?= __e('vocabulary.upload.manual.mode_update_existing_title') ?>">
                                    <?= __e('vocabulary.upload.manual.mode_update_existing') ?>
                                </option>
                            </select>
                        </div>
                    </div>
                </div>

                <!-- Translation Delimiter (conditional) -->
                <div class="field mt-3" x-show="showDelimiter" x-transition x-cloak>
                    <label class="label is-small">
                        <?= __e('vocabulary.upload.manual.translation_delimiter') ?>
                    </label>
                    <div class="field has-addons">
                        <div class="control">
                            <input class="input is-small notempty"
                                   type="text"
                                   name="transl_delim"
                                   style="width: 5em;"
                                   value="<?php echo Settings::getWithDefault('set-term-translation-delimiters'); ?>" />
                        </div>
                        <div class="control">
                            <span class="icon has-text-danger mt-1"
                                  title="<?= __e('vocabulary.upload.manual.required') ?>">
                                <?php echo IconHelper::render('asterisk', ['alt' => 'Required']); ?>
                            </span>
                        </div>
                    </div>
                </div>
            </div>

            <div class="column is-half">
                <div class="field">
                    <label class="label is-small">
                        <?= __e('vocabulary.upload.manual.status_for_all') ?>
                    </label>
                    <div class="field has-addons">
                        <div class="control is-expanded">
                            <div class="select is-fullwidth">
                                <select class="notempty" name="WoStatus" required>
                                    <?php echo SelectOptionsBuilder::forWordStatus(null, false, false); ?>
                                </select>
                            </div>
                        </div>
                        <div class="control">
                            <span class="icon has-text-danger mt-2"
                                  title="<?= __e('vocabulary.upload.manual.required') ?>">
                                <?php echo IconHelper::render('asterisk', ['alt' => 'Required']); ?>
                            </span>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- ==================== WARNING & SUBMIT ==================== -->
    <article class="message is-warning" x-show="isNotDictFile">
        <div class="message-body">
            <div class="level">
                <div class="level-left">
                    <div class="level-item">
                        <span class="icon is-medium">
                            <?php echo IconHelper::render('alert-triangle', ['alt' => 'Warning']); ?>
                        </span>
                    </div>
                    <div class="level-item">
                        <div>
                            <p class="has-text-weight-bold">
                                <?= __e('vocabulary.upload.manual.backup_advisable') ?>
                            </p>
                            <p class="is-size-7">
                                <?= __e('vocabulary.upload.manual.double_check') ?>
                            </p>
                        </div>
                    </div>
                </div>
                <div class="level-right">
                    <div class="level-item">
                        <button type="button"
                                class="button is-warning is-outlined is-small"
                                data-action="navigate"
                                data-url="/admin/backup">
                            <span class="icon is-small">
                                <?php echo IconHelper::render('database', ['alt' => 'Backup']); ?>
                            </span>
                            <span><?= __e('vocabulary.upload.manual.backup') ?></span>
                        </button>
                    </div>
                </div>
            </div>
        </div>
    </article>

    <!-- Form Actions -->
    <div class="field is-grouped is-grouped-right">
        <div class="control" x-show="isNotDictFile">
            <button type="submit" name="op" value="Import" class="button is-primary">
                <span class="icon is-small">
                    <?php echo IconHelper::render('upload', ['alt' => 'Import']); ?>
                </span>
                <span><?= __e('vocabulary.upload.manual.import_terms') ?></span>
            </button>
        </div>
        <div class="control" x-show="isDictFile">
            <button type="submit" name="op" value="ImportDictionary" class="button is-primary">
                <span class="icon is-small">
                    <?php echo IconHelper::render('upload', ['alt' => 'Import']); ?>
                </span>
                <span><?= __e('vocabulary.upload.manual.import_dictionary') ?></span>
            </button>
        </div>
    </div>
</form>

<!-- Help notes -->
<article class="message is-light mt-5">
    <div class="message-body is-size-7">
        <p>
            <?= __('vocabulary.upload.manual.help_note_html') ?>
        </p>
    </div>
</article>

</div><!-- /manual tab -->

</div><!-- /x-data wordUploadPageApp -->
