<?php

/**
 * Home Page View
 *
 * Variables expected:
 * - $dashboardData: array Dashboard data from HomeFacade
 * - $homeFacade: HomeFacade instance
 * - $languages: array Languages data for select dropdown
 *
 * PHP version 8.2
 *
 * @category Lwt
 * @package  Lwt\Modules\Home\Views
 * @author   HugoFara <git@hugofara.net>
 * @license  Unlicense <http://unlicense.org/>
 * @link     https://hugofara.github.io/lwt/developer/api
 * @since    3.0.0
 */

declare(strict_types=1);

namespace Lwt\Modules\Home\Views;

use Lwt\Shared\Infrastructure\ApplicationInfo;
use Lwt\Shared\Infrastructure\Http\UrlUtilities;

require_once __DIR__ . '/helpers.php';

// Validate injected variables from controller
assert(isset($dashboardData) && is_array($dashboardData));
assert(isset($languages) && is_array($languages));
/**
 * @var array<int, array{id: int, name: string}> $languages
 * @psalm-var list<array{id: int, name: string}> $languages
 */
/** @var array|null $lastTextInfo - Pre-computed by controller */

// Extract variables from dashboard data with proper types
/** @var int $currentlang */
$currentlang = $dashboardData['current_language_id'] ?? 0;
/** @var int $langcnt */
$langcnt = $dashboardData['language_count'] ?? 0;
/** @var bool $isWordPress */
$isWordPress = $dashboardData['is_wordpress'] ?? false;
/** @var int $textCount */
$textCount = $dashboardData['current_language_text_count'] ?? 0;

// Get base path for URL generation
$base = UrlUtilities::getBasePath();
?>

<!-- Alpine.js Home App Container -->
<div x-data="homeApp()" x-cloak>

<!-- System notifications -->
<div class="notification is-danger is-light" x-show="warnings.phpOutdated.visible" x-transition>
    <p x-text="warnings.phpOutdated.message"></p>
</div>
<div class="notification is-warning is-light" x-show="warnings.cookiesDisabled.visible" x-transition>
    <p x-text="warnings.cookiesDisabled.message"></p>
</div>
<div class="notification is-info is-light" x-show="warnings.updateAvailable.visible" x-transition>
    <button
        type="button"
        class="delete"
        aria-label="<?= htmlspecialchars(__('home.dismiss'), ENT_QUOTES, 'UTF-8') ?>"
        @click="dismissUpdateWarning()"
    ></button>
    <p>
        <span x-text="warnings.updateAvailable.message"></span>
        <a :href="warnings.updateAvailable.downloadUrl" class="button is-small is-info is-outlined ml-2">
            <?= __('home.download') ?>
        </a>
    </p>
</div>

<!-- Language change notification -->
<div class="notification is-success is-light" x-show="languageNotification.visible" x-transition>
    <button class="delete" @click="languageNotification.visible = false"></button>
    <p x-text="languageNotification.message"></p>
</div>

<!-- Welcome message -->
<section class="hero is-small is-primary is-bold mb-5">
    <div class="hero-body py-4">
        <p class="title is-4 has-text-centered"><?= __('home.welcome_title') ?></p>
    </div>
</section>

<?php if ($langcnt == 0) : ?>
<!-- Empty database: Select a language -->
<section class="section py-6">
    <div class="container">
        <div class="has-text-centered">
            <a href="<?php echo $base; ?>/languages/new" class="button is-large is-primary">
                <span class="icon"><i data-lucide="languages"></i></span>
                <span><?= __('home.select_language') ?></span>
            </a>
        </div>
    </div>
</section>
<?php elseif ($langcnt > 0) : ?>
<!-- Current text section -->
<section class="section py-4 mb-4">
    <div class="container">
        <!-- Text cards (single row, horizontal scroll) -->
        <div style="display: flex; gap: 0.75rem; overflow-x: auto; padding-bottom: 0.5rem;">
            <!-- Current text card -->
            <div style="flex-shrink: 0;">
                <template x-if="lastText">
                    <div class="box has-background-link-light" style="width: 280px; min-height: 180px;">
                        <p class="title is-5 mb-3" x-text="lastText.title"></p>
                        <!-- Statistics bar - colors match word status highlights -->
                        <div
                            class="mb-3"
                            x-show="lastText.stats && lastText.stats.total > 0"
                            :title="getStatsTitle()"
                        >
                            <div style="display: flex; height: 12px; border-radius: 6px;
                                overflow: hidden; background: #ddd;">
                                <div
                                    style="background: #5ABAFF;"
                                    :style="{ width: getStatPercent('unknown') + '%' }"
                                ></div>
                                <div
                                    style="background: #E85A3C;"
                                    :style="{ width: getStatPercent('s1') + '%' }"
                                ></div>
                                <div
                                    style="background: #E8893C;"
                                    :style="{ width: getStatPercent('s2') + '%' }"
                                ></div>
                                <div
                                    style="background: #E8B83C;"
                                    :style="{ width: getStatPercent('s3') + '%' }"
                                ></div>
                                <div
                                    style="background: #E8E23C;"
                                    :style="{ width: getStatPercent('s4') + '%' }"
                                ></div>
                                <div
                                    style="background: #66CC66;"
                                    :style="{ width: getStatPercent('s5') + '%' }"
                                ></div>
                                <div
                                    style="background: #CCFFCC;"
                                    :style="{ width: getStatPercent('s99') + '%' }"
                                ></div>
                                <div
                                    style="background: #888888;"
                                    :style="{ width: getStatPercent('s98') + '%' }"
                                ></div>
                            </div>
                        </div>
                        <div class="buttons">
                            <a :href="basePath + '/text/' + lastText.id + '/read'" class="button is-link is-medium">
                                <span class="icon"><i data-lucide="book-open"></i></span>
                                <span><?= __('home.read') ?></span>
                            </a>
                            <a
                                :href="basePath + '/review?text=' + lastText.id"
                                class="button is-info is-light is-medium"
                            >
                                <span class="icon"><i data-lucide="circle-help"></i></span>
                                <span><?= __('home.review') ?></span>
                            </a>
                        </div>
                        <template x-if="lastText.annotated">
                            <a
                                :href="basePath + '/text/' + lastText.id + '/print'"
                                class="button is-success is-light is-small"
                            >
                                <span class="icon"><i data-lucide="check"></i></span>
                                <span><?= __('home.annotated_text') ?></span>
                            </a>
                        </template>
                    </div>
                </template>
                <template x-if="!lastText">
                    <div class="box has-background-light" style="width: 280px; min-height: 180px;
                        display: flex; flex-direction: column; justify-content: center; align-items: center;">
                        <span class="icon is-large has-text-grey-light mb-2">
                            <i data-lucide="book-open" style="width: 36px; height: 36px;"></i>
                        </span>
                        <p class="has-text-grey is-size-7 has-text-centered">
                            <?= __('home.empty_text_card') ?>
                        </p>
                    </div>
                </template>
            </div>

            <!-- New text card -->
            <div style="flex-shrink: 0;">
                <a
                    href="<?php echo $base; ?>/texts/new"
                    class="box has-background-primary-light has-text-centered"
                    style="width: 180px; min-height: 180px; display: flex;
                        flex-direction: column; justify-content: center; align-items: center;"
                >
                    <span class="icon is-large has-text-primary">
                        <i data-lucide="plus" style="width: 48px; height: 48px;"></i>
                    </span>
                    <p class="mt-3 has-text-weight-semibold"><?= __('home.new_text') ?></p>
                </a>
            </div>

            <!-- Search library card (plain HTML, opens modal via DOM event) -->
            <div style="flex-shrink: 0;">
                <div
                    data-action="open-library-search"
                    class="box has-background-warning-light has-text-centered"
                    style="width: 180px; min-height: 180px; display: flex;
                        flex-direction: column; justify-content: center; align-items: center;
                        cursor: pointer;"
                >
                    <span class="icon is-large has-text-warning-dark">
                        <i data-lucide="search" style="width: 48px; height: 48px;"></i>
                    </span>
                    <p class="mt-3 has-text-weight-semibold"><?= __('home.search_library') ?></p>
                </div>
            </div>
        </div>

        <!-- Suggestion rows. Ordered by reader level: the GDL "easy readers"
             row exposes its own flex order (before Gutenberg for beginners,
             after for advanced); Gutenberg sits at the fixed middle order. -->
        <div class="mt-4" style="display: flex; flex-direction: column;">

            <!-- Global Digital Library suggestions (easy readers) -->
            <div x-data="gdlSuggestions" x-cloak :style="beginner ? 'order: 0' : 'order: 2'">
                <template x-if="showRow()">
                    <div class="mt-4">
                        <p class="is-size-6 has-text-grey mb-2">
                            <span class="icon-text">
                                <span class="icon"><i data-lucide="library"></i></span>
                                <span><?= __('home.suggested_from_gdl') ?></span>
                            </span>
                        </p>
                        <?php renderGdlSuggestionsGrid(); ?>
                    </div>
                </template>
            </div>

            <!-- Gutenberg suggestions -->
            <div x-data="gutenbergSuggestions" x-cloak style="order: 1;">
                <template x-if="books.length > 0 || loading">
                    <div class="mt-4">
                        <p class="is-size-6 has-text-grey mb-2">
                            <span class="icon-text">
                                <span class="icon"><i data-lucide="book-open-text"></i></span>
                                <span><?= __('home.suggested_from_gutenberg') ?></span>
                            </span>
                        </p>
                        <?php renderSuggestionsGrid(); ?>
                    </div>
                </template>
            </div>
        </div>
    </div>
</section>

<?php endif; ?>

<?php if ($langcnt > 0) : ?>
    <?php renderWordPressLogout($isWordPress, $base); ?>
<?php endif; ?>

<!-- Version info -->
<p class="has-text-centered has-text-grey is-size-7 mt-4">
    <?= __('home.version_label') ?> <?php echo ApplicationInfo::getVersion(); ?>
</p>

<!-- Footer - Alpine.js Component -->
<footer class="footer mt-5 py-4" x-data="footer()">
    <div class="content has-text-centered is-size-7">
        <p>
            <a target="_blank" :href="licenseUrl" class="footer-license-link">
                <img alt="Public Domain" title="Public Domain" :src="licenseImageUrl" class="footer-license-icon" />
            </a>
            <a :href="links.project.href" target="_blank" x-text="links.project.text"></a> is free
            and unencumbered software released into the
            <a :href="links.publicDomain.href" target="_blank" x-text="links.publicDomain.text"></a>.
            <a :href="links.unlicense.href" target="_blank" x-text="links.unlicense.text"></a>
        </p>
    </div>
</footer>

</div><!-- End Alpine.js container -->

<!-- Library search modal (separate Alpine scope, outside homeApp) -->
<div x-data="librarySearch" @open-library-search.document="open = true" x-cloak>
    <div class="modal" :class="{ 'is-active': open }">
        <div class="modal-background" @click="close()"></div>
        <div class="modal-card" style="max-width: 600px; width: 90vw;">
            <header class="modal-card-head">
                <p class="modal-card-title"><?= __('home.search_library_modal_title') ?></p>
                <button class="delete" aria-label="close" @click="close()"></button>
            </header>
            <section class="modal-card-body">
                <form @submit.prevent="search()" class="mb-4">
                    <div class="field has-addons">
                        <div class="control is-expanded">
                            <input
                                x-model="query"
                                class="input"
                                type="text"
                                placeholder="<?php
                                    echo htmlspecialchars(
                                        __('home.search_library_placeholder'),
                                        ENT_QUOTES,
                                        'UTF-8'
                                    );
                                    ?>"
                            />
                        </div>
                        <div class="control">
                            <button
                                type="submit"
                                class="button is-warning"
                                :class="{ 'is-loading': loading && !searched }"
                                :disabled="loading"
                            >
                                <span class="icon"><i data-lucide="search"></i></span>
                                <span><?= __('home.search') ?></span>
                            </button>
                        </div>
                    </div>
                </form>

                <!-- Error message -->
                <div x-show="error" class="notification is-danger is-light" x-text="error"></div>

                <!-- Results count -->
                <p
                    x-show="searched && !error && !loading"
                    class="has-text-grey is-size-7 mb-2"
                    x-text="booksFoundLabel(totalCount)"
                ></p>

                <!-- Results list -->
                <div
                    x-show="results.length > 0"
                    style="max-height: 400px; overflow-y: auto;"
                >
                    <template x-for="book in results" :key="book.id">
                        <div class="box p-3 mb-2" style="cursor: default;">
                            <div class="is-flex is-justify-content-space-between is-align-items-start">
                                <div style="flex: 1; min-width: 0;">
                                    <div class="is-flex is-align-items-center" style="gap: 0.5rem;">
                                        <p
                                            class="has-text-weight-semibold is-size-6"
                                            x-text="book.title"
                                            style="overflow: hidden; text-overflow: ellipsis;"
                                        ></p>
                                        <span
                                            x-show="book.difficultyTier"
                                            class="tag is-rounded is-small"
                                            :class="tierClass(book.difficultyTier || '')"
                                            x-text="tierLabel(book.difficultyTier || '')"
                                        ></span>
                                    </div>
                                    <p
                                        class="has-text-grey is-size-7"
                                        x-text="formatAuthors(book.authors)"
                                    ></p>
                                    <p
                                        class="has-text-grey-light is-size-7"
                                        x-text="downloadsLabel(book.downloadCount)"
                                    ></p>
                                </div>
                                <div class="buttons are-small ml-3" style="flex-shrink: 0;">
                                    <button
                                        @click="togglePreview(book)"
                                        class="button is-info is-outlined is-small"
                                        :class="{ 'is-loading': previewLoading && previewBookId === book.id }"
                                        :disabled="previewLoading && previewBookId === book.id"
                                        title="<?php
                                            echo htmlspecialchars(
                                                __('home.analyze_difficulty'),
                                                ENT_QUOTES,
                                                'UTF-8'
                                            );
                                            ?>"
                                    >
                                        <span class="icon"><i data-lucide="bar-chart-2"></i></span>
                                    </button>
                                    <button
                                        @click="importBook(book)"
                                        class="button is-primary is-small"
                                        :class="{ 'is-loading': importing === book.id }"
                                        :disabled="importing !== null"
                                    >
                                        <span class="icon"><i data-lucide="download"></i></span>
                                        <span><?= __('home.import') ?></span>
                                    </button>
                                </div>
                            </div>
                            <!-- Preview panel -->
                            <template x-if="previewBookId === book.id && !previewLoading">
                                <div class="mt-3 pt-3" style="border-top: 1px solid #eee;">
                                    <template x-if="previewError">
                                        <p class="has-text-danger is-size-7" x-text="previewError"></p>
                                    </template>
                                    <template x-if="previewData && !previewError">
                                        <div>
                                            <progress
                                                class="progress is-small mb-2"
                                                :class="coverageClass(previewData.difficulty_label)"
                                                :value="previewData.coverage_percent"
                                                max="100"
                                            ></progress>
                                            <p
                                                class="is-size-7"
                                                x-text="coverageDetailedLabel(previewData)"
                                            ></p>
                                            <div x-show="previewData.sample_unknown_words.length > 0" class="mt-2">
                                                <p class="has-text-grey is-size-7 mb-1">
                                                    <?= __('home.unknown_words_in_sample') ?>
                                                </p>
                                                <div class="tags">
                                                    <template x-for="w in previewData.sample_unknown_words" :key="w">
                                                        <span class="tag is-light is-small" x-text="w"></span>
                                                    </template>
                                                </div>
                                            </div>
                                        </div>
                                    </template>
                                </div>
                            </template>
                        </div>
                    </template>
                </div>

                <!-- Load more -->
                <button
                    x-show="hasMore && results.length > 0"
                    @click="loadMore()"
                    class="button is-small is-fullwidth mt-2"
                    :class="{ 'is-loading': loading }"
                    :disabled="loading"
                >
                    <?= __('home.load_more') ?>
                </button>

                <!-- No results -->
                <p
                    x-show="searched && results.length === 0 && !loading && !error"
                    class="has-text-grey is-italic"
                >
                    <?= __('home.no_books_found') ?>
                </p>
            </section>
        </div>
    </div>
</div>

<?php renderHomeConfig($lastTextInfo, $base, $textCount, $currentlang); ?>
