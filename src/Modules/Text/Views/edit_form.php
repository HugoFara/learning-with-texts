<?php

declare(strict_types=1);

/**
 * Text Edit Form View - Display form for creating/editing texts
 *
 * Variables expected:
 * - $textId: int - Text ID (0 for new text)
 * - $text: object{id: int, lgid: int, title: string, text: string, source: string, media_uri: string} - Text object
 * - $annotated: bool - Whether the text has annotations
 * - $languageData: array - Mapping of language ID to language code
 * - $isNew: bool - Whether this is a new text
 *
 * PHP version 8.1
 *
 * @category Lwt
 * @package  Lwt\Views
 * @author   HugoFara <git@hugofara.net>
 * @license  Unlicense <http://unlicense.org/>
 * @link     https://hugofara.github.io/lwt/developer/api
 * @since    3.0.0
 *
 * @psalm-suppress UndefinedVariable - Variables are set by the including controller
 * @psalm-suppress TypeDoesNotContainType View included from different contexts
 *
 * @var int $textId
 * @var object{id: int, lgid: int, title: string, text: string, source: string, media_uri: string} $text
 * @var bool $annotated
 * @var array<int, string> $languageData
 * @var array<int, array{id: int, name: string}> $languages
 * @var bool $isNew
 * @var string $scrdir
 */

namespace Lwt\Views\Text;

use Lwt\Shared\UI\Helpers\SelectOptionsBuilder;
use Lwt\Shared\UI\Helpers\SearchableSelectHelper;
use Lwt\Shared\UI\Helpers\IconHelper;
use Lwt\Shared\UI\Helpers\PageLayoutHelper;

// Type-safe variable extraction from controller context
assert(is_array($languageData));
assert(is_array($languages));
/** @var array{base_path: string, paths?: string[], folders?: string[], error?: string} $mediaPaths */
assert(is_array($mediaPaths));
assert(is_string($mediaPathSelectorHtml));
assert(is_bool($youtubeConfigured));
assert(is_string($textTagsHtml));
/** @var array<int, array{id: int|string, name: string}> $languagesTyped */
$languagesTyped = $languages;
assert(is_object($text) && property_exists($text, 'lgid'));

/** @var int $textIdTyped */
$textIdTyped = $textId;
/** @var int $textLgId */
$textLgId = $text->lgid;
/** @var string $textTitle */
$textTitle = $text->title;
/** @var string $textContent */
$textContent = $text->text;
/** @var string $textSource */
$textSource = $text->source;
/** @var string $textMediaUri */
$textMediaUri = $text->media_uri;
/** @var string $scrdirTyped */
$scrdirTyped = $scrdir;

// Build actions only for edit mode (not new text)
$actions = [];
if (!$isNew) {
    $actions[] = [
        'url' => '/texts/new',
        'label' => __('text.list.new_text'),
        'icon' => 'circle-plus',
        'class' => 'is-primary'
    ];
    $actions[] = ['url' => '/texts/new', 'label' => __('text.new.import_epub'), 'icon' => 'book'];
    $actions[] = ['url' => '/books', 'label' => __('text.new.my_books'), 'icon' => 'library'];
    $actions[] = [
        'url' => '/texts?query=&page=1',
        'label' => __('text.list.active_texts'),
        'icon' => 'book-open'
    ];
}

?>
<script type="application/json" id="text-edit-config">
<?php echo json_encode(
    ['languageData' => $languageData, 'textId' => $textIdTyped],
    JSON_HEX_TAG | JSON_HEX_AMP
); ?>
</script>

<?php if (!$isNew) : ?>
<h2 class="title is-4 is-flex is-align-items-center">
    <?= __e('text.edit.heading') ?>
    <a target="_blank" href="docs/info.html#howtotext" class="ml-2">
        <?php echo IconHelper::render(
            'help-circle',
            ['title' => __('text.common.help'), 'alt' => __('text.common.help')]
        ); ?>
    </a>
</h2>
    <?php echo PageLayoutHelper::buildActionCard($actions); ?>
<?php endif; ?>

<!--
    Saving and checking both go through /api/v1/texts (#262), so the form
    carries no action of its own.
-->
<form class="validate"
      @submit="handleSubmit($event)"
      <?php if ($isNew) : ?>
      x-data="textNewForm"
      @webpage-imported="goToReview()"
      <?php else : ?>
      x-data="textEditForm"
      <?php endif; ?>>
    <?php echo \Lwt\Shared\UI\Helpers\FormHelper::csrfField(); ?>
    <input type="hidden" name="TxID" value="<?php echo $textIdTyped; ?>" />

    <?php if ($isNew) : ?>
    <!-- New Text Form -->
    <div class="container mb-5" style="max-width: 500px;">
        <!-- Language from navbar selection -->
        <input type="hidden" name="TxLgID" id="TxLgID" value="<?php echo $textLgId; ?>" />

        <!-- Where to find texts (step 1 only) -->
        <div x-show="step === 1" x-transition class="mb-5">
            <div class="box" x-data="{ open: false }">
                <header class="is-flex is-align-items-center is-justify-content-space-between is-clickable"
                        @click="open = !open">
                    <h4 class="title is-6 mb-0 is-flex is-align-items-center">
                        <span class="icon mr-2">
                            <?php echo IconHelper::render('lightbulb', ['alt' => __('text.new.where_to_find')]); ?>
                        </span>
                        <?= __e('text.new.where_to_find') ?>
                    </h4>
                    <span class="icon">
                        <i
                            :class="open ? 'rotate-180' : ''"
                            class="transition-transform"
                            data-lucide="chevron-down"></i>
                    </span>
                </header>

                <div x-show="open" x-transition x-cloak class="mt-4 content is-small">
                    <p><?php echo __('text.edit.tips.intro'); ?></p>

                    <h5 class="mb-2"><?php echo __('text.edit.tips.literature_heading'); ?></h5>
                    <ul>
                        <li><?php echo __('text.edit.tips.gutenberg'); ?></li>
                        <li><?php echo __('text.edit.tips.wikisource'); ?></li>
                    </ul>

                    <h5 class="mb-2"><?php echo __('text.edit.tips.news_heading'); ?></h5>
                    <ul>
                        <li><?php echo __('text.edit.tips.nhk'); ?></li>
                        <li><?php echo __('text.edit.tips.dw'); ?></li>
                        <li><?php echo __('text.edit.tips.rfi'); ?></li>
                        <li><?php echo __('text.edit.tips.voa'); ?></li>
                    </ul>

                    <h5 class="mb-2"><?php echo __('text.edit.tips.subtitles_heading'); ?></h5>
                    <ul>
                        <li><?php echo __('text.edit.tips.opensubtitles'); ?></li>
                        <li><?php echo __('text.edit.tips.tatoeba'); ?></li>
                    </ul>

                    <h5 class="mb-2"><?php echo __('text.edit.tips.other_heading'); ?></h5>
                    <ul>
                        <li><?php echo __('text.edit.tips.simple_wiki'); ?></li>
                        <li><?php echo __('text.edit.tips.rss'); ?></li>
                        <li><?php echo __('text.edit.tips.epub'); ?></li>
                    </ul>

                    <p class="has-text-grey mt-3"><?php echo __('text.edit.tips.fetch_tip'); ?></p>
                </div>
            </div>
        </div>

        <!-- ═══ STEP 1: Choose Source ═══ -->
        <div x-show="step === 1" x-transition>
            <label class="label is-medium mb-3"><?= __e('text.new.how_to_add') ?></label>

            <!-- Source cards -->
            <div
                style="display: grid; grid-template-columns: repeat(auto-fill, minmax(120px, 1fr)); gap: 0.75rem;"
                class="mb-4">
                <div
                    class="box has-text-centered p-3 is-clickable"
                    :class="sourceActive('paste')"
                    @click="selectSource('paste')"
                    style="cursor: pointer;">
                    <span class="icon is-medium has-text-primary">
                        <?php echo IconHelper::render('pencil', ['alt' => __('text.new.source.paste')]); ?>
                    </span>
                    <p class="is-size-7 has-text-weight-medium mt-1"><?= __e('text.new.source.paste') ?></p>
                </div>
                <div
                    class="box has-text-centered p-3 is-clickable"
                    :class="sourceActive('url')"
                    @click="selectSource('url')"
                    style="cursor: pointer;">
                    <span class="icon is-medium has-text-primary">
                        <?php echo IconHelper::render('globe', ['alt' => __('text.new.source.url')]); ?>
                    </span>
                    <p class="is-size-7 has-text-weight-medium mt-1"><?= __e('text.new.source.url') ?></p>
                </div>
                <div
                    class="box has-text-centered p-3 is-clickable"
                    :class="sourceActive('file')"
                    @click="selectSource('file')"
                    style="cursor: pointer;">
                    <span class="icon is-medium has-text-primary">
                        <?php echo IconHelper::render('file-up', ['alt' => __('text.new.source.file')]); ?>
                    </span>
                    <p class="is-size-7 has-text-weight-medium mt-1"><?= __e('text.new.source.file') ?></p>
                </div>
                <div
                    class="box has-text-centered p-3 is-clickable"
                    :class="sourceActive('gutenberg')"
                    @click="selectSource('gutenberg')"
                    style="cursor: pointer;">
                    <span class="icon is-medium has-text-primary">
                        <?php echo IconHelper::render('book-open-text', ['alt' => __('text.new.source.gutenberg')]); ?>
                    </span>
                    <p class="is-size-7 has-text-weight-medium mt-1"><?= __e('text.new.source.gutenberg') ?></p>
                </div>
                <div
                    class="box has-text-centered p-3 is-clickable"
                    :class="sourceActive('gdl')"
                    @click="selectSource('gdl')"
                    style="cursor: pointer;">
                    <span class="icon is-medium has-text-primary">
                        <?php echo IconHelper::render('library', ['alt' => __('text.new.source.gdl')]); ?>
                    </span>
                    <p class="is-size-7 has-text-weight-medium mt-1"><?= __e('text.new.source.gdl') ?></p>
                </div>
                <div
                    class="box has-text-centered p-3 is-clickable"
                    :class="sourceActive('feeds')"
                    @click="selectSource('feeds')"
                    style="cursor: pointer;">
                    <span class="icon is-medium has-text-primary">
                        <?php echo IconHelper::render('rss', ['alt' => __('text.new.source.feeds')]); ?>
                    </span>
                    <p class="is-size-7 has-text-weight-medium mt-1"><?= __e('text.new.source.feeds') ?></p>
                </div>
            </div>

        <!-- File Import Section -->
        <?php $zipMissing = !extension_loaded('zip'); ?>
        <div x-show="source === 'file'" x-transition x-cloak class="mt-4">
            <p class="help mb-4">
                <?= __e('text.new.file.help') ?>
            </p>

            <div class="tabs is-boxed">
                <ul>
                    <li :class="fileTabActive('computer')">
                        <a @click="selectFileTab('computer')">
                            <span class="icon is-small">
                                <?php echo IconHelper::render(
                                    'upload',
                                    ['alt' => __('text.new.file.from_computer')]
                                ); ?>
                            </span>
                            <span><?= __e('text.new.file.from_computer') ?></span>
                        </a>
                    </li>
                    <li :class="fileTabActive('server')">
                        <a @click="selectFileTab('server')">
                            <span class="icon is-small">
                                <?php echo IconHelper::render(
                                    'hard-drive',
                                    ['alt' => __('text.new.file.from_server')]
                                ); ?>
                            </span>
                            <span><?= __e('text.new.file.from_server') ?></span>
                        </a>
                    </li>
                </ul>
            </div>

            <!-- Tab: From computer -->
            <div x-show="fileTab === 'computer'" x-cloak>
                <div class="field">
                    <div class="file has-name is-fullwidth">
                        <label class="file-label">
                            <input class="file-input"
                                   type="file"
                                   name="importFile"
                                   id="importFile"
                                   accept=".srt,.vtt,.epub,.txt,.mp3,.mp4,.wav,.webm,.ogg,.m4a,.mkv,.flac"
                                   @change="handleFileChange($event)" />
                            <span class="file-cta">
                                <span class="file-icon">
                                    <?php echo IconHelper::render(
                                        'file-up',
                                        ['alt' => __('text.new.file.browse')]
                                    ); ?>
                                </span>
                                <span class="file-label"><?= __e('text.new.file.browse') ?></span>
                            </span>
                            <span class="file-name"><?= __e('text.new.file.no_file') ?></span>
                        </label>
                    </div>
                    <p id="importFileStatus" class="help"></p>
                </div>

                <!-- EPUB inline notice (visible once an .epub file is picked) -->
                <div x-show="isEpub()" x-cloak class="notification is-info is-light mt-3">
                    <p>
                        <?php echo IconHelper::render(
                            'book',
                            ['alt' => '', 'class' => 'mr-2']
                        ); ?>
                        <?= __e('text.new.file.epub_detected') ?>
                    </p>
                </div>

                <?php if ($zipMissing) : ?>
                <div x-show="isEpub()" x-cloak class="notification is-danger mt-3">
                    <p>
                        <strong>
                            <?php echo IconHelper::render(
                                'alert-circle',
                                ['alt' => __('common.error'), 'class' => 'mr-2']
                            ); ?>
                            <?php echo __('book.zip_required_title'); ?>
                        </strong>
                    </p>
                    <p><?php echo __('book.zip_required_body'); ?></p>
                </div>
                <?php endif; ?>
            </div>

            <!-- Tab: From server -->
            <div x-show="fileTab === 'server'" x-cloak>
                <div class="field">
                    <div class="control" id="mediaselect">
                        <?php $mediaJson = json_encode($mediaPaths, JSON_HEX_TAG | JSON_HEX_AMP); ?>
                        <?php $mediaBase = htmlspecialchars($mediaPaths['base_path'] ?? '', ENT_QUOTES, 'UTF-8'); ?>
                        <p class="help mb-2">
                            <?php echo htmlspecialchars(
                                __('text.edit.import_file.files_in', ['path' => '../' . $mediaBase . '/media'])
                            ); ?>
                        </p>
                        <p id="mediaSelectErrorMessage"></p>
                        <?php echo IconHelper::render(
                            'loader-2',
                            [
                                'id' => 'mediaSelectLoadingImg',
                                'alt' => __('text.common.loading'),
                                'class' => 'icon-spin'
                            ]
                        ); ?>
                        <select
                            name="Dir"
                            class="input"
                            data-action="media-dir-select"
                            data-target-field="TxAudioURI"></select>
                        <span class="click" data-action="refresh-media-select">
                            <?php echo IconHelper::render(
                                'refresh-cw',
                                ['title' => __('text.common.refresh'), 'alt' => __('text.common.refresh')]
                            ); ?>
                            <?= __e('text.common.refresh') ?>
                        </span>
                        <script type="application/json" data-lwt-media-select-config>
                            <?php echo $mediaJson !== false ? $mediaJson : '{}'; ?>
                        </script>
                    </div>
                </div>
            </div>

            <!-- Whisper Transcription Options (shown when audio/video selected) -->
            <div id="whisperOptions" class="box mt-3" style="display: none;">
                <h4 class="subtitle is-6 mb-3">
                    <?php echo IconHelper::render('mic', ['alt' => __('text.new.transcription_options')]); ?>
                    <?= __e('text.new.transcription_options') ?>
                </h4>

                <div class="field">
                    <label class="label is-small" for="whisperLanguage">
                        <?= __e('text.new.transcription_language') ?>
                    </label>
                    <div class="control">
                        <div class="select is-small is-fullwidth">
                            <select id="whisperLanguage" name="whisperLanguage">
                                <option value=""><?= __e('text.new.transcription_auto') ?></option>
                            </select>
                        </div>
                    </div>
                </div>

                <div class="field">
                    <label class="label is-small" for="whisperModel">
                        <?= __e('text.new.transcription_model') ?>
                    </label>
                    <div class="control">
                        <div class="select is-small is-fullwidth">
                            <select id="whisperModel" name="whisperModel">
                                <option value="base"><?= __e('text.edit.whisper.model_base') ?></option>
                                <option value="small" selected>
                                    <?= __e('text.edit.whisper.model_small') ?>
                                </option>
                                <option value="medium"><?= __e('text.edit.whisper.model_medium') ?></option>
                                <option value="large"><?= __e('text.edit.whisper.model_large') ?></option>
                            </select>
                        </div>
                    </div>
                </div>

                <div class="field">
                    <div class="control">
                        <button type="button" class="button is-info" id="startTranscription">
                            <span class="icon is-small">
                                <?php echo IconHelper::render(
                                    'mic',
                                    ['alt' => __('text.new.transcription_start')]
                                ); ?>
                            </span>
                            <span><?= __e('text.new.transcription_start') ?></span>
                        </button>
                    </div>
                </div>

                <div id="whisperProgress" class="notification is-info is-light mt-3" style="display: none;">
                    <div class="level mb-2">
                        <div class="level-left">
                            <span id="whisperStatusText">
                                <?= __e('text.new.transcription_preparing') ?>
                            </span>
                        </div>
                        <div class="level-right">
                            <button type="button" class="button is-small is-danger is-outlined" id="whisperCancel">
                                <?= __e('text.common.cancel') ?>
                            </button>
                        </div>
                    </div>
                    <progress class="progress is-info" id="whisperProgressBar" value="0" max="100"></progress>
                </div>
            </div>

            <div id="whisperUnavailable" class="notification is-warning is-light mt-3" style="display: none;">
                <span class="icon-text">
                    <span class="icon">
                        <?php echo IconHelper::render(
                            'alert-triangle',
                            ['alt' => __('text.edit.whisper.warning_alt')]
                        ); ?>
                    </span>
                    <span><?= __e('text.new.transcription_unavailable') ?></span>
                </span>
            </div>

            <!-- Next: Review -->
            <div class="field mt-4">
                <button type="button" class="button is-primary is-fullwidth" @click="goToReview()">
                    <span><?= __e('text.new.review.next') ?></span>
                    <span class="icon">
                        <?php echo IconHelper::render('arrow-right', ['alt' => __('text.new.review.next')]); ?>
                    </span>
                </button>
            </div>
        </div>

        <!-- URL Import Section -->
        <div x-show="source === 'url'" x-transition x-cloak class="mt-4"
             x-data="{ urlSubMode: 'webpage' }">

            <!-- Sub-mode toggle: Web page vs Video -->
            <div class="tabs is-small is-toggle is-centered mb-4">
                <ul>
                    <li :class="urlSubMode === 'webpage' ? 'is-active' : ''">
                        <a @click.prevent="urlSubMode = 'webpage'">
                            <span class="icon is-small">
                                <?php echo IconHelper::render('globe', ['alt' => __('text.new.url.web_page')]); ?>
                            </span>
                            <span><?= __e('text.new.url.web_page') ?></span>
                        </a>
                    </li>
                    <li :class="urlSubMode === 'video' ? 'is-active' : ''">
                        <a @click.prevent="urlSubMode = 'video'">
                            <span class="icon is-small">
                                <?php echo IconHelper::render('video', ['alt' => __('text.new.url.video')]); ?>
                            </span>
                            <span><?= __e('text.new.url.video') ?></span>
                        </a>
                    </li>
                </ul>
            </div>

            <!-- Web page import -->
            <div x-show="urlSubMode === 'webpage'" x-transition>
                <div class="field">
                    <label class="label"><?= __e('text.new.url.web_label') ?></label>
                    <div class="field has-addons mb-0">
                        <div class="control is-expanded">
                            <input type="url" class="input" id="webpageUrl"
                                   placeholder="https://example.com/article" />
                        </div>
                        <div class="control">
                            <button type="button" class="button is-info"
                                    data-action="fetch-webpage" id="fetchWebpageBtn">
                                <?= __e('text.new.url.fetch') ?>
                            </button>
                        </div>
                    </div>
                    <p class="help">
                        <?= __e('text.new.url.fetch_help') ?>
                    </p>
                    <p id="webpageImportStatus" class="help mt-2"></p>
                </div>
            </div>

            <!-- Video import -->
            <div x-show="urlSubMode === 'video'" x-transition>
                <div class="field">
                    <label class="label"><?= __e('text.new.url.video_label') ?></label>
                    <div class="control">
                        <input type="url"
                               class="input"
                               name="TxMediaURL"
                               id="TxMediaURL"
                               placeholder="https://www.youtube.com/watch?v=... or https://vimeo.com/..." />
                    </div>
                    <p class="help">
                        <?= __e('text.new.url.video_help') ?>
                    </p>
                </div>

                <?php if ($youtubeConfigured) : ?>
                <div class="field mt-3">
                    <label class="label is-small">
                        <?= __e('text.edit.import_url.youtube_id_label') ?>
                    </label>
                    <div class="control">
                        <div class="field has-addons mb-0">
                            <div class="control is-expanded">
                                <input type="text"
                                       class="input is-small"
                                       id="ytVideoId"
                                       placeholder="<?= __e('text.edit.import_url.youtube_id_placeholder') ?>" />
                            </div>
                            <div class="control">
                                <button type="button" class="button is-info is-small" data-action="fetch-youtube">
                                    <?= __e('text.edit.import_url.fetch_captions') ?>
                                </button>
                            </div>
                        </div>
                    </div>
                    <p id="ytDataStatus" class="help"></p>
                </div>
                <?php endif; ?>
            </div>
        </div>

        <!-- Gutenberg Browser Section -->
        <div x-show="source === 'gutenberg'" x-transition x-cloak class="mt-4">
            <div x-data="gutenbergBrowser">
                <p class="help mb-4">
                    <?= __e('text.edit.gutenberg.intro') ?>
                </p>

                <!-- No language selected -->
                <div x-show="showPlaceholder()" class="notification is-warning is-light">
                    <?= __e('text.edit.gutenberg.no_language') ?>
                </div>

                <!-- Language selected but no books found -->
                <div x-show="showNoResults()" class="notification is-info is-light">
                    <?= __e('text.edit.gutenberg.no_results') ?>
                </div>

                <!-- Loading state -->
                <div x-show="loading && books.length === 0" class="has-text-centered py-4">
                    <span class="icon is-large has-text-grey-light">
                        <i
                            data-lucide="loader"
                            style="width: 32px; height: 32px; animation: spin 1s linear infinite;"></i>
                    </span>
                </div>

                <!-- Error -->
                <div x-show="error" class="notification is-danger is-light is-size-7" x-text="error"></div>

                <!-- Books grid -->
                <div x-show="books.length > 0"
                     style="display: grid; grid-template-columns: repeat(auto-fill, minmax(200px, 1fr)); gap: 0.75rem;">
                    <template x-for="book in books" :key="book.id">
                        <div
                            class="box p-3"
                            style="display: flex; flex-direction: column;
                                   justify-content: space-between; min-height: 160px;">
                            <div>
                                <p class="has-text-weight-semibold is-size-7" x-text="book.title"
                                   style="overflow: hidden; text-overflow: ellipsis; display: -webkit-box;
                                          -webkit-line-clamp: 2; -webkit-box-orient: vertical;"></p>
                                <div class="mb-1">
                                    <span x-show="book.difficultyTier"
                                          class="tag is-rounded" style="font-size: 0.65rem;"
                                          :class="bookTierClass(book)"
                                          x-text="bookTierLabel(book)"></span>
                                </div>
                                <p class="has-text-grey is-size-7" x-text="formatAuthors(book.authors)"
                                   style="overflow: hidden; text-overflow: ellipsis; white-space: nowrap;"></p>

                                <!-- Vocabulary stats (loaded progressively) -->
                                <div x-show="book.statsLoading" class="mt-2">
                                    <span class="is-size-7 has-text-grey-light"
                                          x-text="$t('text.edit.gutenberg.analyzing')"></span>
                                </div>
                                <div x-show="book.stats" class="mt-2">
                                    <p class="is-size-7 has-text-grey mb-1">
                                        <span x-text="bookWordCount(book)"></span>
                                        &middot;
                                        <span x-text="coverageLabel(book)"></span>
                                    </p>
                                    <!-- Coverage bar -->
                                    <div style="height: 4px; border-radius: 2px; overflow: hidden;"
                                         class="has-background-grey-lighter">
                                        <div style="height: 100%; border-radius: 2px; transition: width 0.3s ease;"
                                             :style="coverageBarWidth(book)"
                                             :class="coverageBarClass(book)"></div>
                                    </div>
                                </div>
                            </div>
                            <div class="mt-2">
                                <button type="button" @click="importBook(book)"
                                        class="button is-primary is-small is-fullwidth"
                                        :class="importingClass(book)"
                                        :disabled="isImporting()">
                                    <span class="icon"><i data-lucide="download"></i></span>
                                    <span><?= __e('text.edit.gutenberg.preview') ?></span>
                                </button>
                            </div>
                        </div>
                    </template>
                </div>

                <!-- Load more -->
                <div x-show="hasMore && books.length > 0" class="has-text-centered mt-3">
                    <button type="button" @click="loadMore()"
                            class="button is-small is-light"
                            :class="loadingClass()"
                            :disabled="loading">
                        <span class="icon"><i data-lucide="chevron-right"></i></span>
                        <span><?= __e('text.edit.gutenberg.load_more') ?></span>
                    </button>
                </div>
            </div>
        </div>

        <!-- Global Digital Library Browser Section -->
        <div x-show="source === 'gdl'" x-transition x-cloak class="mt-4">
            <div x-data="gdlBrowser">
                <p class="help mb-4">
                    <?= __e('text.edit.gdl.intro') ?>
                </p>

                <!-- Search bar -->
                <div class="field has-addons mb-4">
                    <div class="control is-expanded">
                        <input type="text" class="input is-small"
                               x-model="query"
                               @keydown.enter.prevent="doSearch()"
                               placeholder="<?= __e('text.edit.gdl.search_placeholder') ?>" />
                    </div>
                    <div class="control">
                        <button type="button" class="button is-small is-primary"
                                @click="doSearch()" :class="loadingClass()">
                            <span class="icon"><i data-lucide="search"></i></span>
                            <span><?= __e('text.edit.gdl.search') ?></span>
                        </button>
                    </div>
                    <div class="control" x-show="query">
                        <button type="button" class="button is-small is-light" @click="clearSearch()">
                            <span class="icon"><i data-lucide="x"></i></span>
                        </button>
                    </div>
                </div>

                <!-- No language selected -->
                <div x-show="showPlaceholder()" class="notification is-warning is-light">
                    <?= __e('text.edit.gdl.no_language') ?>
                </div>

                <!-- Language selected but no books found -->
                <div x-show="showNoResults()" class="notification is-info is-light">
                    <?= __e('text.edit.gdl.no_results') ?>
                </div>

                <!-- Loading state -->
                <div x-show="loading && books.length === 0" class="has-text-centered py-4">
                    <span class="icon is-large has-text-grey-light">
                        <i
                            data-lucide="loader"
                            style="width: 32px; height: 32px; animation: spin 1s linear infinite;"></i>
                    </span>
                </div>

                <!-- Error -->
                <div x-show="error" class="notification is-danger is-light is-size-7" x-text="error"></div>

                <!-- Books grid -->
                <div x-show="books.length > 0"
                     style="display: grid; grid-template-columns: repeat(auto-fill, minmax(200px, 1fr)); gap: 0.75rem;">
                    <template x-for="book in books" :key="book.id">
                        <div
                            class="box p-3"
                            style="display: flex; flex-direction: column;
                                   justify-content: space-between; min-height: 160px;">
                            <div>
                                <figure x-show="book.thumbnail" class="image mb-2"
                                        style="height: 110px; overflow: hidden; border-radius: 4px;">
                                    <img :src="book.thumbnail" :alt="book.title" loading="lazy"
                                         style="width: 100%; height: 110px; object-fit: cover;" />
                                </figure>
                                <p class="has-text-weight-semibold is-size-7" x-text="book.title"
                                   style="overflow: hidden; text-overflow: ellipsis; display: -webkit-box;
                                          -webkit-line-clamp: 2; -webkit-box-orient: vertical;"></p>
                                <div class="mb-1">
                                    <span x-show="hasLevel(book)"
                                          class="tag is-rounded" style="font-size: 0.65rem;"
                                          :class="bookTierClass(book)"
                                          x-text="bookTierLabel(book)"></span>
                                </div>
                                <p class="has-text-grey is-size-7" x-text="formatMeta(book)"
                                   style="overflow: hidden; text-overflow: ellipsis; white-space: nowrap;"></p>
                                <p class="has-text-grey-light is-size-7 mt-1" x-text="book.description"
                                   style="overflow: hidden; text-overflow: ellipsis; display: -webkit-box;
                                          -webkit-line-clamp: 2; -webkit-box-orient: vertical;"></p>
                            </div>
                            <div class="mt-2">
                                <button type="button" @click="importBook(book)"
                                        class="button is-primary is-small is-fullwidth"
                                        :class="importingClass(book)"
                                        :disabled="isImporting()">
                                    <span class="icon"><i data-lucide="download"></i></span>
                                    <span><?= __e('text.edit.gdl.import') ?></span>
                                </button>
                            </div>
                        </div>
                    </template>
                </div>

                <!-- Load more -->
                <div x-show="hasMore && books.length > 0" class="has-text-centered mt-3">
                    <button type="button" @click="loadMore()"
                            class="button is-small is-light"
                            :class="loadingClass()"
                            :disabled="loading">
                        <span class="icon"><i data-lucide="chevron-right"></i></span>
                        <span><?= __e('text.edit.gdl.load_more') ?></span>
                    </button>
                </div>
            </div>
        </div>

        <!-- Feed Browser Section -->
        <div x-show="source === 'feeds'" x-transition x-cloak class="mt-4">
            <div x-data="feedBrowser">
                <p class="help mb-4">
                    <?php echo __('text.edit.feeds.intro'); ?>
                </p>

                <!-- Loading feeds -->
                <div x-show="loadingFeeds" class="has-text-centered py-4">
                    <span class="icon is-large has-text-grey-light">
                        <i
                            data-lucide="loader"
                            style="width: 32px; height: 32px; animation: spin 1s linear infinite;"></i>
                    </span>
                </div>

                <!-- Error -->
                <div x-show="error" class="notification is-danger is-light is-size-7" x-text="error"></div>

                <!-- Feed list (when no feed selected) -->
                <div x-show="!selectedFeed && !loadingFeeds">
                    <template x-if="showEmptyFeeds()">
                        <div class="notification is-info is-light">
                            <p><?= __e('text.edit.feeds.no_feeds') ?></p>
                            <a href="/feeds/new" class="button is-info is-small mt-2">
                                <span class="icon"><i data-lucide="plus"></i></span>
                                <span><?= __e('text.edit.feeds.add_feed') ?></span>
                            </a>
                        </div>
                    </template>

                    <div x-show="feeds.length > 0" class="menu">
                        <template x-for="feed in feeds" :key="feed.id">
                            <a class="box p-3 mb-2 is-flex is-align-items-center is-justify-content-space-between"
                               style="cursor: pointer; text-decoration: none;"
                               @click="selectFeed(feed)">
                                <div>
                                    <p class="has-text-weight-semibold is-size-7" x-text="feed.name"></p>
                                    <p class="has-text-grey is-size-7" x-text="feedInfo(feed)"></p>
                                </div>
                                <span class="icon has-text-grey">
                                    <i data-lucide="chevron-right"></i>
                                </span>
                            </a>
                        </template>
                    </div>
                </div>

                <!-- Article list (when a feed is selected) -->
                <div x-show="selectedFeed">
                    <div class="is-flex is-align-items-center mb-3" style="gap: 0.5rem;">
                        <button type="button" class="button is-small is-light" @click="backToFeeds()">
                            <span class="icon"><i data-lucide="arrow-left"></i></span>
                            <span><?= __e('text.common.back') ?></span>
                        </button>
                        <p class="has-text-weight-semibold is-size-6" x-text="selectedFeedName()"></p>
                    </div>

                    <!-- Loading articles -->
                    <div x-show="loadingArticles" class="has-text-centered py-4">
                        <span class="icon has-text-grey-light">
                            <i
                                data-lucide="loader"
                                style="width: 24px; height: 24px; animation: spin 1s linear infinite;"></i>
                        </span>
                    </div>

                    <template x-if="showEmptyArticles()">
                        <div class="notification is-info is-light is-size-7">
                            <?php echo __('text.edit.feeds.no_articles'); ?>
                        </div>
                    </template>

                    <div x-show="articles.length > 0">
                        <template x-for="article in articles" :key="article.id">
                            <div class="box p-3 mb-2">
                                <div
                                    class="is-flex is-align-items-start is-justify-content-space-between"
                                    style="gap: 0.5rem;">
                                    <div style="flex: 1; min-width: 0;">
                                        <p class="has-text-weight-semibold is-size-7" x-text="article.title"
                                           style="overflow: hidden; text-overflow: ellipsis; white-space: nowrap;"></p>
                                        <p class="has-text-grey is-size-7" x-text="article.date"></p>
                                        <span class="tag is-rounded mt-1" style="font-size: 0.6rem;"
                                              :class="statusClass(article.status)"
                                              x-text="statusLabel(article.status)"></span>
                                    </div>
                                    <button type="button" @click="importArticle(article)"
                                            class="button is-primary is-small"
                                            :disabled="isImported(article)">
                                        <span class="icon"><i data-lucide="download"></i></span>
                                        <span><?= __e('text.edit.gutenberg.preview') ?></span>
                                    </button>
                                </div>
                            </div>
                        </template>

                        <!-- Pagination -->
                        <div
                            x-show="showPagination()"
                            class="is-flex is-justify-content-center mt-3"
                            style="gap: 0.5rem;">
                            <button type="button" class="button is-small"
                                    :disabled="canGoPrev()"
                                    @click="prevPage()">
                                <span class="icon"><i data-lucide="chevron-left"></i></span>
                            </button>
                            <span class="is-size-7 is-flex is-align-items-center">
                                <?= __e('text.edit.feeds.page') ?>
                                <span x-text="articlePage" class="mx-1"></span>
                                <?= __e('text.edit.feeds.of') ?>
                                <span x-text="articleTotalPages" class="ml-1"></span>
                            </span>
                            <button type="button" class="button is-small"
                                    :disabled="canGoNext()"
                                    @click="nextPage()">
                                <span class="icon"><i data-lucide="chevron-right"></i></span>
                            </button>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        </div><!-- end step 1 -->

        <!-- ═══ STEP 2: Review & Import ═══ -->
        <div x-show="step === 2" x-transition x-cloak>
            <!-- Auto-import loading indicator -->
            <div x-show="autoImporting" class="notification is-info is-light has-text-centered mb-5"
                 @webpage-imported.window="autoImporting = false"
                 @webpage-import-error.window="autoImporting = false">
                <span class="icon is-medium">
                    <i data-lucide="loader" style="width: 24px; height: 24px; animation: spin 1s linear infinite;"></i>
                </span>
                <p class="mt-2 has-text-weight-medium"><?= __e('text.new.review.fetching_gutenberg') ?></p>
                <p class="is-size-7 has-text-grey"><?= __e('text.new.review.fetching_help') ?></p>
            </div>

            <!-- Back button -->
            <div class="is-flex is-align-items-center mb-4" style="gap: 0.5rem;">
                <button type="button" class="button is-small is-light" @click="goBack()">
                    <span class="icon">
                        <?php echo IconHelper::render('arrow-left', ['alt' => __('text.common.back')]); ?>
                    </span>
                    <span><?= __e('text.common.back') ?></span>
                </button>
                <h3 class="title is-5 mb-0"><?= __e('text.new.review.title') ?></h3>
            </div>

            <!-- Title -->
            <div class="field">
                <label class="label" for="TxTitle"><?= __e('text.new.review.title_input') ?></label>
                <div class="control">
                    <input type="text"
                           class="input notempty checkoutsidebmp"
                           data_info="Title"
                           name="TxTitle"
                           id="TxTitle"
                           value="<?php echo \htmlspecialchars($textTitle, ENT_QUOTES, 'UTF-8'); ?>"
                           maxlength="200"
                           placeholder="<?= __e('text.new.review.title_placeholder') ?>" />
                </div>
            </div>

            <!-- Text Content (hidden for file import) -->
            <div x-show="showTextArea()" class="field">
                <label class="label" for="TxText"><?= __e('text.common.text') ?></label>
                <div class="control">
                    <textarea <?php echo $scrdirTyped; ?>
                              name="TxText"
                              id="TxText"
                              class="textarea notempty checkoutsidebmp"
                              data_info="Text"
                              rows="10"
                              placeholder="<?= __e('text.new.review.text_placeholder') ?>"><?php
                                  echo \htmlspecialchars($textContent, ENT_QUOTES, 'UTF-8');
                                ?></textarea>
                </div>
            </div>

            <!-- File info (shown for file import) -->
            <div x-show="showFileInfo() && !isEpub()" class="notification is-info is-light" x-cloak>
                <span class="icon-text">
                    <span class="icon">
                        <?php echo IconHelper::render(
                            'file-check',
                            ['alt' => __('text.new.review.file_ready')]
                        ); ?>
                    </span>
                    <span><?= __e('text.new.review.file_ready') ?></span>
                </span>
            </div>

            <!-- EPUB info (shown when an .epub is queued for import) -->
            <div x-show="isEpub()" class="notification is-info is-light" x-cloak>
                <span class="icon-text">
                    <span class="icon">
                        <?php echo IconHelper::render(
                            'book',
                            ['alt' => __('text.new.file.epub_detected')]
                        ); ?>
                    </span>
                    <span><?= __e('text.new.file.epub_detected') ?></span>
                </span>
            </div>

            <!-- Save or EPUB import failure, reported by the API -->
            <div x-show="hasSaveError()" class="notification is-danger" x-cloak>
                <span x-text="saveError"></span>
            </div>

            <!-- Save / Import Button -->
            <div class="field mt-5">
                <div class="control">
                    <button type="submit" name="op"
                            value="Save and Open"
                            :value="submitOp()"
                            class="button is-primary is-medium is-fullwidth"
                            :disabled="isBusy()"
                            :class="{ 'is-loading': isBusy() }">
                        <span class="icon" x-show="!isEpub()">
                            <?php echo IconHelper::render(
                                'book-open',
                                ['alt' => __('text.new.review.save_and_read')]
                            ); ?>
                        </span>
                        <span class="icon" x-show="isEpub()" x-cloak>
                            <?php echo IconHelper::render(
                                'upload',
                                ['alt' => __('book.import_epub')]
                            ); ?>
                        </span>
                        <span x-show="!isEpub()"><?= __e('text.new.review.save_and_read') ?></span>
                        <span x-show="isEpub()" x-cloak><?php echo __('book.import_epub'); ?></span>
                    </button>
                </div>
            </div>

            <!-- Cancel link -->
            <div class="has-text-centered mt-3">
                <a href="/" class="has-text-grey"><?= __e('text.common.cancel') ?></a>
            </div>
        </div><!-- end step 2 -->
    </div>

    <!-- Additional Options (step 2 only) -->
    <div x-show="step === 2" x-cloak class="container" style="max-width: 600px;">
        <div class="box" x-data="{ open: showAdvanced }">
            <header class="is-flex is-align-items-center is-justify-content-space-between is-clickable"
                    @click="open = !open">
                <h4 class="title is-6 mb-0 is-flex is-align-items-center">
                    <span class="icon mr-2">
                        <?php echo IconHelper::render('settings', ['alt' => __('text.new.advanced')]); ?>
                    </span>
                    <?= __e('text.new.advanced') ?>
                </h4>
                <span class="icon">
                    <i :class="open ? 'rotate-180' : ''" class="transition-transform" data-lucide="chevron-down"></i>
                </span>
            </header>

            <div x-show="open" x-transition x-cloak class="mt-4">
                <!-- Source URI -->
                <div class="field">
                    <label class="label" for="TxSourceURI"><?= __e('text.common.source_uri') ?></label>
                    <div class="control">
                        <input type="url"
                               class="input checkurl checkoutsidebmp"
                               data_info="Source URI"
                               name="TxSourceURI"
                               id="TxSourceURI"
                               value="<?php echo \htmlspecialchars($textSource, ENT_QUOTES, 'UTF-8'); ?>"
                               maxlength="1000"
                               placeholder="https://example.com/article" />
                    </div>
                    <p class="help"><?= __e('text.edit.source_help') ?></p>
                </div>

                <!-- Tags -->
                <div class="field">
                    <label class="label"><?= __e('text.common.tags') ?></label>
                    <div class="control">
                        <?php echo $textTagsHtml; ?>
                    </div>
                    <p class="help"><?= __e('text.edit.tags_help') ?></p>
                </div>
            </div>
        </div>
    </div>

    <?php else : ?>
    <!-- Edit Mode: Show full form -->
    <div class="box">
        <!-- Language -->
        <div class="field">
            <label class="label" for="TxLgID">
                <?= __e('text.common.language') ?>
                <span class="icon has-text-danger is-small" title="<?= __e('text.common.field_required') ?>">
                    <?php echo IconHelper::render('asterisk', ['alt' => __('text.common.required')]); ?>
                </span>
            </label>
            <div class="control">
                <?php echo SearchableSelectHelper::forLanguages(
                    $languagesTyped,
                    $textLgId,
                    [
                        'name' => 'TxLgID',
                        'id' => 'TxLgID',
                        'placeholder' => __('text.common.choose'),
                        'required' => true,
                        'dataAction' => 'change-language'
                    ]
                ); ?>
            </div>
        </div>

        <!-- Title -->
        <div class="field">
            <label class="label" for="TxTitle">
                <?= __e('text.common.title') ?>
                <span class="icon has-text-danger is-small" title="<?= __e('text.common.field_required') ?>">
                    <?php echo IconHelper::render('asterisk', ['alt' => __('text.common.required')]); ?>
                </span>
            </label>
            <div class="control">
                <input type="text"
                       class="input notempty checkoutsidebmp"
                       data_info="Title"
                       name="TxTitle"
                       id="TxTitle"
                       value="<?php echo \htmlspecialchars($textTitle, ENT_QUOTES, 'UTF-8'); ?>"
                       maxlength="200"
                       required />
            </div>
        </div>

        <!-- Text Content -->
        <div class="field">
            <label class="label" for="TxText">
                <?= __e('text.common.text') ?>
                <span class="icon has-text-danger is-small" title="<?= __e('text.common.field_required') ?>">
                    <?php echo IconHelper::render('asterisk', ['alt' => __('text.common.required')]); ?>
                </span>
            </label>
            <div class="control">
                <textarea <?php echo $scrdirTyped; ?>
                          name="TxText"
                          id="TxText"
                          class="textarea notempty checkoutsidebmp"
                          data_info="Text"
                          rows="15"
                          required><?php echo \htmlspecialchars($textContent, ENT_QUOTES, 'UTF-8'); ?></textarea>
            </div>
        </div>

        <!-- Annotated Text (only for existing texts) -->
        <div class="field">
            <label class="label"><?= __e('text.common.annotated_text') ?></label>
            <div class="control">
                <?php if ($annotated) : ?>
                <div class="notification is-info is-light">
                    <span class="icon-text">
                        <span class="icon has-text-success">
                            <?php echo IconHelper::render('check', ['alt' => 'Has Annotation']); ?>
                        </span>
                        <span><?= __e('text.edit.exists_warning') ?></span>
                    </span>
                    <div class="mt-2">
                        <button type="button"
                                class="button is-small is-info is-outlined"
                                data-action="navigate"
                                data-url="/text/<?php echo $textIdTyped; ?>/print">
                            <span class="icon is-small">
                                <?php echo IconHelper::render('printer', ['alt' => 'Print']); ?>
                            </span>
                            <span><?= __e('text.edit.print_edit') ?></span>
                        </button>
                    </div>
                </div>
                <?php else : ?>
                <div class="notification is-light">
                    <span class="icon-text">
                        <span class="icon has-text-grey">
                            <?php echo IconHelper::render('x', ['alt' => 'No Annotation']); ?>
                        </span>
                        <span><?= __e('text.edit.no_annotation') ?></span>
                    </span>
                </div>
                <?php endif; ?>
            </div>
        </div>

        <!-- Source URI -->
        <div class="field">
            <label class="label" for="TxSourceURI"><?= __e('text.common.source_uri') ?></label>
            <div class="control">
                <input type="url"
                       class="input checkurl checkoutsidebmp"
                       data_info="Source URI"
                       name="TxSourceURI"
                       id="TxSourceURI"
                       value="<?php echo \htmlspecialchars($textSource, ENT_QUOTES, 'UTF-8'); ?>"
                       maxlength="1000"
                       placeholder="https://example.com/article" />
            </div>
        </div>

        <!-- Tags -->
        <div class="field">
            <label class="label"><?= __e('text.common.tags') ?></label>
            <div class="control">
                <?php echo $textTagsHtml; ?>
            </div>
        </div>

        <!-- Media URI -->
        <div class="field">
            <label class="label" for="TxAudioURI"><?= __e('text.common.media_uri') ?></label>
            <div class="control">
                <input type="text"
                       class="input checkoutsidebmp"
                       data_info="Audio-URI"
                       name="TxAudioURI"
                       id="TxAudioURI"
                       maxlength="2048"
                       value="<?php echo \htmlspecialchars($textMediaUri, ENT_QUOTES, 'UTF-8'); ?>"
                       placeholder="media/audio.mp3" />
            </div>
            <div class="mt-2" id="mediaselect">
                <?php echo $mediaPathSelectorHtml; ?>
            </div>
        </div>
    </div>

    <!-- Save failure, reported by the API -->
    <div x-show="hasSaveError()" class="notification is-danger" x-cloak>
        <span x-text="saveError"></span>
    </div>

    <!-- Form Actions (Edit mode) -->
    <div class="field is-grouped is-grouped-right">
        <div class="control">
            <button type="button"
                    class="button is-light"
                    data-action="cancel-form"
                    data-url="/texts#rec<?php echo $textIdTyped; ?>">
                <?= __e('text.common.cancel') ?>
            </button>
        </div>
        <div class="control">
            <button type="button" class="button is-info is-outlined"
                    :disabled="checking"
                    @click="check($event)">
                <span class="icon is-small">
                    <?php echo IconHelper::render('check', ['alt' => __('text.common.check')]); ?>
                </span>
                <span><?= __e('text.common.check') ?></span>
            </button>
        </div>
        <div class="control">
            <button type="submit" name="op" value="Change" class="button is-primary"
                    :disabled="saving" :class="{ 'is-loading': saving }">
                <span class="icon is-small">
                    <?php echo IconHelper::render('save', ['alt' => __('text.common.save')]); ?>
                </span>
                <span><?= __e('text.common.save_changes') ?></span>
            </button>
        </div>
        <div class="control">
            <button type="submit" name="op" value="Change and Open" class="button is-success"
                    :disabled="saving" :class="{ 'is-loading': saving }">
                <span class="icon is-small">
                    <?php echo IconHelper::render('book-open', ['alt' => __('text.common.save_and_open')]); ?>
                </span>
                <span><?= __e('text.common.save_and_open') ?></span>
            </button>
        </div>
    </div>
    <?php endif; ?>

    <?php if (!$isNew) : ?>
    <!-- The parse report renders here when "Check" is used. -->
    <div id="check_text" x-show="hasReport" x-cloak class="content mt-5"></div>
    <?php endif; ?>
</form>
