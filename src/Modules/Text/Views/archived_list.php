<?php

declare(strict_types=1);

/**
 * Archived Text List View - Display grouped archived texts by language
 *
 * Variables expected:
 * - $message: string - Status/error message to display
 * - $activeLanguageId: int - Currently active language ID for default expansion
 *
 * PHP version 8.2
 *
 * @category Lwt
 * @package  Lwt\Views
 * @author   HugoFara <git@hugofara.net>
 * @license  Unlicense <http://unlicense.org/>
 * @link     https://hugofara.github.io/lwt/developer/api
 * @since    3.0.0
 *
 * @psalm-suppress UndefinedVariable - Variables are set by the including controller
 *
 * @var string $message
 * @var int $activeLanguageId
 */

namespace Lwt\Views\Text;

use Lwt\Shared\UI\Helpers\SelectOptionsBuilder;
use Lwt\Shared\UI\Helpers\PageLayoutHelper;
use Lwt\Shared\UI\Helpers\IconHelper;
use Lwt\Shared\UI\Helpers\ConfigIsland;

// Type-safe variable extraction from controller context
/**
 * @var string $messageTyped
*/
$messageTyped = $message;

PageLayoutHelper::renderMessage($messageTyped, false);

echo PageLayoutHelper::buildActionCard(
    [
    [
        'url' => '/texts/new',
        'label' => __('text.list.new_text'),
        'icon' => 'circle-plus',
        'class' => 'is-primary'
    ],
    [
        'url' => '/texts?query=&page=1',
        'label' => __('text.list.active_texts'),
        'icon' => 'book-open'
    ],
    ]
);
?>

<!-- Alpine.js container for grouped archived texts -->
<div x-data="archivedTextsGroupedApp" x-init="init()" x-cloak>

    <!-- Loading state -->
    <div x-show="loading" class="has-text-centered py-6">
        <span class="icon is-large">
            <i data-lucide="loader-2" class="icon-spin"></i>
        </span>
        <p class="mt-2"><?= __e('text.list.loading_archived_texts') ?></p>
    </div>

    <!-- Global sort control -->
    <div x-show="!loading && languages.length > 0" class="box mb-4">
        <div class="level">
            <div class="level-left">
                <div class="level-item">
                    <span
                        class="has-text-weight-semibold"
                        x-text="totalArchivedSummary()"></span>
                </div>
            </div>
            <div class="level-right">
                <div class="level-item">
                    <div class="field has-addons">
                        <div class="control">
                            <span class="button is-static is-small"><?= __e('text.common.sort') ?></span>
                        </div>
                        <div class="control">
                            <div class="select is-small">
                                <select
                                    @change="handleSortChange($event)"
                                    aria-label="<?= __e('text.list.sort_aria') ?>">
                                    <?php echo SelectOptionsBuilder::forTextSort(); ?>
                                </select>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Language sections -->
    <template x-for="lang in languages" :key="lang.id">
        <div class="card mb-4">
            <!-- Collapsible header -->
            <header class="card-header is-clickable" @click="toggleLanguage(lang.id)" style="user-select: none;">
                <p class="card-header-title">
                    <span x-text="lang.name"></span>
                    <span
                        class="tag is-warning ml-2"
                        x-text="archivedCountLabel(lang.text_count)"></span>
                </p>
                <button
                    class="card-header-icon"
                    type="button"
                    :aria-label="collapseAriaLabel(lang.id, lang.name)"
                    :aria-expanded="!isCollapsed(lang.id)">
                    <span class="icon">
                        <i :data-lucide="chevronIcon(lang.id)"></i>
                    </span>
                </button>
            </header>

            <!-- Content (texts for this language) -->
            <div class="card-content" x-show="!isCollapsed(lang.id)" x-collapse.duration.200ms>
                <!-- Loading state for this language -->
                <div
                    x-show="isLoadingMore(lang.id) && getTextsForLanguage(lang.id).length === 0"
                    class="has-text-centered py-4">
                    <span class="icon">
                        <i data-lucide="loader-2" class="icon-spin"></i>
                    </span>
                    <span class="ml-2">Loading...</span>
                </div>

                <!-- Per-language bulk actions -->
                <div x-show="getTextsForLanguage(lang.id).length > 0" class="level mb-4">
                    <div class="level-left">
                        <div class="level-item">
                            <div class="buttons are-small">
                                <button type="button" class="button" @click="markAll(lang.id, true)">
                                    <?php echo IconHelper::render('check-square', ['size' => 14]); ?>
                                    <span class="ml-1"><?= __e('text.common.mark_all') ?></span>
                                </button>
                                <button type="button" class="button" @click="markAll(lang.id, false)">
                                    <?php echo IconHelper::render('square', ['size' => 14]); ?>
                                    <span class="ml-1"><?= __e('text.common.mark_none') ?></span>
                                </button>
                                <span
                                    x-show="hasMarkedInLanguage(lang.id)"
                                    class="tag is-warning ml-2"
                                    x-text="getMarkedCount(lang.id) + ' selected'"></span>
                            </div>
                        </div>
                    </div>
                    <div class="level-right">
                        <div class="level-item">
                            <div class="field has-addons">
                                <div class="control">
                                    <span class="button is-static is-small">
                                        <?php echo IconHelper::render('zap', ['size' => 14]); ?>
                                        <span class="ml-1"><?= __e('text.common.actions') ?></span>
                                    </span>
                                </div>
                                <div class="control">
                                    <div class="select is-small">
                                        <select
                                            :disabled="!hasMarkedInLanguage(lang.id)"
                                            @change="handleMultiAction(lang.id, $event)"
                                            aria-label="<?= __e('text.list.bulk_aria') ?>">
                                            <?php echo SelectOptionsBuilder::forMultipleArchivedTextsActions(); ?>
                                        </select>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Text cards grid -->
                <div
                    class="columns is-multiline text-cards archived-text-cards"
                    x-show="getTextsForLanguage(lang.id).length > 0">
                    <template x-for="text in getTextsForLanguage(lang.id)" :key="text.id">
                        <div class="column is-4-desktop is-6-tablet is-12-mobile">
                            <div class="card text-card is-archived">
                                <header class="card-header">
                                    <label class="card-header-icon checkbox-wrapper" @click.stop>
                                        <input type="checkbox"
                                               class="markcheck"
                                               :aria-label="'Select ' + text.title"
                                               :checked="isMarked(lang.id, text.id)"
                                               @change="toggleMark(lang.id, text.id, $event.target.checked)" />
                                    </label>
                                    <p class="card-header-title" x-text="text.title"></p>
                                    <div class="card-header-icon card-icons">
                                        <span x-show="text.has_audio" title="<?= __e('text.common.with_audio') ?>">
                                            <?php echo IconHelper::render('volume-2', ['size' => 16]); ?>
                                        </span>
                                        <a
                                            x-show="text.has_source"
                                            :href="text.source_uri"
                                            target="_blank"
                                            title="<?= __e('text.common.source_link') ?>"
                                            @click.stop>
                                            <?php echo IconHelper::render('external-link', ['size' => 16]); ?>
                                        </a>
                                        <span x-show="text.annotated" title="<?= __e('text.common.annotated_text') ?>">
                                            <?php echo IconHelper::render('file-text', ['size' => 16]); ?>
                                        </span>
                                    </div>
                                </header>

                                <div class="card-content">
                                    <!-- Tags -->
                                    <div x-show="text.taglist" class="text-meta mb-3">
                                        <div class="tags">
                                            <template x-for="tag in parseTags(text.taglist)" :key="tag">
                                                <span class="tag is-info is-light is-small" x-text="tag"></span>
                                            </template>
                                        </div>
                                    </div>

                                    <!-- Archive Status Badge -->
                                    <div class="archive-badge">
                                        <span class="tag is-warning is-light">
                                            <?php echo IconHelper::render('archive', ['size' => 12]); ?>
                                            <span class="ml-1"><?= __e('text.common.archived') ?></span>
                                        </span>
                                    </div>
                                </div>

                                <footer class="card-footer">
                                    <a
                                        href="#"
                                        class="card-footer-item is-primary-action"
                                        @click.prevent="handlePostAction($event, '/texts/' + text.id + '/unarchive')">
                                        <?php echo IconHelper::render('archive-restore', ['size' => 16]); ?>
                                        <span><?= __e('text.common.unarchive') ?></span>
                                    </a>
                                    <a :href="'/text/archived/' + text.id + '/edit'" class="card-footer-item">
                                        <?php echo IconHelper::render('file-pen', ['size' => 16]); ?>
                                        <span><?= __e('text.common.edit') ?></span>
                                    </a>
                                    <a
                                        class="card-footer-item has-text-danger"
                                        @click.prevent="handleRestDelete($event, '/text/archived/' + text.id)">
                                        <?php echo IconHelper::render('trash-2', ['size' => 16]); ?>
                                        <span><?= __e('text.common.delete') ?></span>
                                    </a>
                                </footer>
                            </div>
                        </div>
                    </template>
                </div>

                <!-- Per-language "Show More" pagination -->
                <div x-show="hasMoreTexts(lang.id)" class="has-text-centered mt-4">
                    <button type="button"
                            class="button is-info is-outlined"
                            @click="loadMoreTexts(lang.id)"
                            :class="{ 'is-loading': isLoadingMore(lang.id) }">
                        <span class="icon">
                            <i data-lucide="chevron-down"></i>
                        </span>
                        <span><?= __e('text.common.show_more') ?></span>
                    </button>
                </div>
            </div>
        </div>
    </template>

    <!-- Empty state -->
    <div x-show="!loading && languages.length === 0" class="notification is-info is-light">
        <p><?= __e('text.list.empty_archived') ?></p>
    </div>
</div>

<!-- Config for Alpine - pass active language for default expansion -->
<?php ConfigIsland::render('archived-texts-grouped-config', [
    'activeLanguageId' => $activeLanguageId
    ]); ?>
