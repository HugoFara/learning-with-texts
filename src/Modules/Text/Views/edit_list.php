<?php

declare(strict_types=1);

/**
 * Active Text List View - Display texts for current language
 *
 * Variables expected:
 * - $message: string - Status/error message to display
 * - $statuses: array - Word status definitions
 * - $activeLanguageId: int - Currently selected language ID
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
 * @var array<int, array{status: int, label: string}> $statuses
 * @var int $activeLanguageId
 */

namespace Lwt\Views\Text;

use Lwt\Shared\UI\Helpers\SelectOptionsBuilder;
use Lwt\Shared\UI\Helpers\PageLayoutHelper;
use Lwt\Shared\UI\Helpers\IconHelper;
use Lwt\Shared\Infrastructure\Utilities\StringUtils;
use Lwt\Shared\UI\Helpers\ConfigIsland;

// Type-safe variable extraction from controller context
/**
 * @var string $message
*/

?>
<link rel="stylesheet" type="text/css" href="<?php StringUtils::printFilePath('css/css_charts.css');?>" />

<?php \Lwt\Shared\UI\Helpers\PageLayoutHelper::renderMessage($message, false); ?>

<?php
echo PageLayoutHelper::buildActionCard(
    [
    [
        'url' => '/texts/new',
        'label' => __('text.list.new_text'),
        'icon' => 'circle-plus',
        'class' => 'is-primary'
    ],
    [
        'url' => '/text/archived?query=&page=1',
        'label' => __('text.list.archived_texts'),
        'icon' => 'archive'
    ],
    ]
);
?>

<!-- Alpine.js container for texts list -->
<div x-data="textsGroupedApp" x-cloak>

    <!-- Loading state -->
    <div x-show="loading" class="has-text-centered py-6">
        <span class="icon is-large">
            <i data-lucide="loader-2" class="icon-spin"></i>
        </span>
        <p class="mt-2"><?= __e('text.list.loading_texts') ?></p>
    </div>

    <!-- Sort control and summary -->
    <div x-show="!loading && texts.length > 0" class="box mb-4">
        <div class="level">
            <div class="level-left">
                <div class="level-item">
                    <span
                        class="has-text-weight-semibold"
                        x-text="summaryText"></span>
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

    <!-- Bulk actions -->
    <div x-show="!loading && texts.length > 0" class="level mb-4">
        <div class="level-left">
            <div class="level-item">
                <div class="buttons are-small">
                    <button type="button" class="button" @click="markAllTexts(true)">
                        <?php echo IconHelper::render('check-square', ['size' => 14]); ?>
                        <span class="ml-1"><?= __e('text.common.mark_all') ?></span>
                    </button>
                    <button type="button" class="button" @click="markAllTexts(false)">
                        <?php echo IconHelper::render('square', ['size' => 14]); ?>
                        <span class="ml-1"><?= __e('text.common.mark_none') ?></span>
                    </button>
                    <span
                        x-show="markedTexts.size > 0"
                        class="tag is-warning ml-2"
                        x-text="markedTexts.size + ' selected'"></span>
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
                                :disabled="markedTexts.size === 0"
                                @change="handleMultiAction($event)"
                                aria-label="<?= __e('text.list.bulk_aria') ?>">
                                <?php echo SelectOptionsBuilder::forMultipleTextsActions(); ?>
                            </select>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Text cards grid -->
    <div class="columns is-multiline text-cards" x-show="!loading && texts.length > 0">
        <template x-for="text in texts" :key="text.id">
            <div class="column is-4-desktop is-6-tablet is-12-mobile">
                <div class="card text-card">
                    <header class="card-header">
                        <label class="card-header-icon checkbox-wrapper" @click.stop>
                            <input type="checkbox"
                                   class="markcheck"
                                   :aria-label="'Select ' + text.title"
                                   :checked="isTextMarked(text.id)"
                                   :data-text-id="text.id"
                                   @change="toggleTextMark($event)" />
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
                            <a
                                x-show="text.annotated"
                                :href="'/text/' + text.id + '/print'"
                                title="<?= __e('text.common.annotated_text') ?>"
                                @click.stop>
                                <?php echo IconHelper::render('file-text', ['size' => 16]); ?>
                            </a>
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

                        <!-- Word Statistics -->
                        <div class="text-stats">
                            <template x-if="getStatsForText(text.id)">
                                <div>
                                    <div class="stat-row">
                                        <div
                                            class="stat-item"
                                            title="<?= __e('text.list.stat_total_title') ?>">
                                            <span class="stat-label"><?= __e('text.list.stat_total') ?></span>
                                            <span
                                                class="stat-value"
                                                x-text="getStatTotal(text.id)"></span>
                                        </div>
                                        <div
                                            class="stat-item"
                                            title="<?= __e('text.list.stat_saved_title') ?>">
                                            <span class="stat-label"><?= __e('text.list.stat_saved') ?></span>
                                            <span class="stat-value">
                                                <a
                                                    class="status4"
                                                    :href="'/words/edit?page=1&query=&status=' +
                                                        '&tag12=0&tag2=&tag1=&text_mode=0&text=' +
                                                        text.id"
                                                    @click.stop
                                                    x-text="getStatSaved(text.id)"></a>
                                            </span>
                                        </div>
                                        <div
                                            class="stat-item"
                                            title="<?= __e('text.list.stat_unknown_title') ?>">
                                            <span class="stat-label"><?= __e('text.list.stat_unknown') ?></span>
                                            <span
                                                class="stat-value status0"
                                                x-text="getStatUnknown(text.id)"></span>
                                        </div>
                                        <div
                                            class="stat-item"
                                            title="<?= __e('text.list.stat_unknown_percent_title') ?>">
                                            <span class="stat-label"><?= __e('text.list.stat_unknown_percent') ?></span>
                                            <span
                                                class="stat-value"
                                                x-text="getStatUnknownPercent(text.id)">
                                            </span>
                                        </div>
                                    </div>
                                    <!-- Status distribution bar chart -->
                                    <div class="status-bar-chart">
                                        <template
                                            x-for="seg in getStatusSegments(text.id)"
                                            :key="seg.status">
                                            <div :class="'status-segment bc' + seg.status"
                                                 :style="'width: ' + seg.percent"
                                                 :title="seg.label"></div>
                                        </template>
                                    </div>
                                </div>
                            </template>
                            <template x-if="!getStatsForText(text.id)">
                                <div class="stat-row">
                                    <span class="has-text-grey is-size-7">
                                        <?= __e('text.list.loading_statistics') ?>
                                    </span>
                                </div>
                            </template>
                        </div>
                    </div>

                    <footer class="card-footer">
                        <a :href="'/text/' + text.id + '/read'" class="card-footer-item is-primary-action">
                            <?php echo IconHelper::render('book-open', ['size' => 16]); ?>
                            <span><?= __e('text.common.read') ?></span>
                        </a>
                        <a :href="'/review?text=' + text.id" class="card-footer-item">
                            <?php echo IconHelper::render('circle-help', ['size' => 16]); ?>
                            <span><?= __e('text.common.review') ?></span>
                        </a>
                        <div class="card-footer-item has-dropdown" x-data="dropdownToggle">
                            <a @click.prevent.stop="toggle()" class="dropdown-trigger-link">
                                <?php echo IconHelper::render('more-horizontal', ['size' => 16]); ?>
                                <span><?= __e('text.common.more') ?></span>
                            </a>
                            <div
                                class="dropdown-menu card-dropdown"
                                x-show="open"
                                @click.outside="close()"
                                x-cloak>
                                <div class="dropdown-content">
                                    <a :href="'/text/' + text.id + '/print-plain'" class="dropdown-item">
                                        <?php echo IconHelper::render('printer', ['size' => 14]); ?>
                                        <span><?= __e('text.common.print') ?></span>
                                    </a>
                                    <a href="#"
                                       class="dropdown-item"
                                       :data-url="'/texts/' + text.id + '/archive'"
                                       @click.prevent="handlePostActionFromEvent($event)">
                                        <?php echo IconHelper::render('archive', ['size' => 14]); ?>
                                        <span><?= __e('text.common.archive') ?></span>
                                    </a>
                                    <a :href="'/texts/' + text.id + '/edit'" class="dropdown-item">
                                        <?php echo IconHelper::render('file-pen', ['size' => 14]); ?>
                                        <span><?= __e('text.common.edit') ?></span>
                                    </a>
                                    <hr class="dropdown-divider">
                                    <a
                                        class="dropdown-item has-text-danger"
                                        :data-url="'/texts/' + text.id"
                                        @click.prevent="handleRestDeleteFromEvent($event)">
                                        <?php echo IconHelper::render('trash-2', ['size' => 14]); ?>
                                        <span><?= __e('text.common.delete') ?></span>
                                    </a>
                                </div>
                            </div>
                        </div>
                    </footer>
                </div>
            </div>
        </template>
    </div>

    <!-- Show More pagination -->
    <div x-show="!loading && hasMore" class="has-text-centered mt-4">
        <button type="button"
                class="button is-info is-outlined"
                @click="loadMore()"
                :class="{ 'is-loading': loadingMore }">
            <span class="icon">
                <i data-lucide="chevron-down"></i>
            </span>
            <span><?= __e('text.common.show_more') ?></span>
        </button>
    </div>

    <!-- Empty state -->
    <div x-show="!loading && texts.length === 0" class="notification is-info is-light">
        <p><?= __e('text.list.empty') ?>
            <a href="<?php
                echo \Lwt\Shared\Infrastructure\Http\UrlUtilities::url('/texts/new');
            ?>"><?= __e('text.list.empty_create') ?></a> <?= __e('text.list.empty_to_get_started') ?></p>
    </div>
</div>

<!-- Config for Alpine - pass statuses and active language -->
<?php ConfigIsland::render('texts-grouped-config', [
    'statuses' => $statuses,
    'activeLanguageId' => $activeLanguageId
    ]); ?>
