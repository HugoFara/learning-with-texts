<?php

/**
 * Statistics View
 *
 * Modern Bulma + Alpine.js version of the statistics page.
 *
 * Variables expected:
 * - $intensityStats: array with 'languages' and 'totals' keys
 * - $frequencyStats: array with 'languages' and 'totals' keys
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

namespace Lwt\Modules\User\Views;

use Lwt\Shared\UI\Helpers\IconHelper;
use Lwt\Shared\UI\Helpers\ConfigIsland;

/**
 * @var array{
 *     languages: array<int, array{name?: string, s1: int|string, s2: int|string,
 *                                 s3: int|string, s4: int|string, s5: int|string, s99: int|string}>,
 *     totals?: array<string, int|string>
 * } $intensityStats Learning intensity statistics
 */
$intensityStats = is_array($intensityStats ?? null) && isset($intensityStats['languages'])
    ? $intensityStats
    : ['languages' => []];

/**
 * @var array{
 *     languages?: array<int, array<string, int|string>>,
 *     totals: array{ct: int|string, at: int|string, kt: int|string, cy: int|string,
 *                   ay: int|string, ky: int|string, cw: int|string, aw: int|string,
 *                   kw: int|string, cm: int|string, am: int|string, km: int|string,
 *                   ca: int|string, aa: int|string, ka: int|string}
 * } $frequencyStats Learning frequency statistics
 */
$frequencyStats = is_array($frequencyStats ?? null) && isset($frequencyStats['totals'])
    ? $frequencyStats
    : ['totals' => [
        'ct' => 0, 'at' => 0, 'kt' => 0, 'cy' => 0, 'ay' => 0, 'ky' => 0,
        'cw' => 0, 'aw' => 0, 'kw' => 0, 'cm' => 0, 'am' => 0, 'km' => 0,
        'ca' => 0, 'aa' => 0, 'ka' => 0
    ]];

// Prepare chart data as JSON for the Alpine/Chart.js module
/** @var array{name?: string, s1: int|string, s2: int|string, s3: int|string, s4: int|string, s5: int|string, s99: int|string} $lang */
$intensityChartData = array_map(function (array $lang) {
    return [
        'name' => $lang['name'] ?? '',
        's1' => (int)$lang['s1'],
        's2' => (int)$lang['s2'],
        's3' => (int)$lang['s3'],
        's4' => (int)$lang['s4'],
        's5' => (int)$lang['s5'],
        's99' => (int)$lang['s99'],
    ];
}, $intensityStats['languages']);

$frequencyChartTotals = [
    'ct' => (int)$frequencyStats['totals']['ct'],
    'at' => (int)$frequencyStats['totals']['at'],
    'kt' => (int)$frequencyStats['totals']['kt'],
    'cy' => (int)$frequencyStats['totals']['cy'],
    'ay' => (int)$frequencyStats['totals']['ay'],
    'ky' => (int)$frequencyStats['totals']['ky'],
    'cw' => (int)$frequencyStats['totals']['cw'],
    'aw' => (int)$frequencyStats['totals']['aw'],
    'kw' => (int)$frequencyStats['totals']['kw'],
    'cm' => (int)$frequencyStats['totals']['cm'],
    'am' => (int)$frequencyStats['totals']['am'],
    'km' => (int)$frequencyStats['totals']['km'],
    'ca' => (int)$frequencyStats['totals']['ca'],
    'aa' => (int)$frequencyStats['totals']['aa'],
    'ka' => (int)$frequencyStats['totals']['ka'],
];

$t = static fn(string $key): string => htmlspecialchars(__('user.' . $key), ENT_QUOTES, 'UTF-8');
?>
<div class="container">

    <!-- Activity & Streak Section -->
    <section class="box mb-4" x-data="streakDisplay">
        <h2 class="title is-4 mb-4">
            <span class="icon-text">
                <span class="icon has-text-warning">
                    <?php echo IconHelper::render('flame', ['class' => 'icon']); ?>
                </span>
                <span><?php echo $t('statistics.activity_title'); ?></span>
            </span>
        </h2>

        <!-- Streak stats row -->
        <div class="columns is-mobile is-multiline mb-3">
            <div class="column is-4-desktop is-6-mobile">
                <div class="box has-background-light has-text-centered py-3">
                    <p class="heading"><?php echo $t('statistics.current_streak'); ?></p>
                    <p class="title is-5" x-text="streakLabel(currentStreak)">--</p>
                </div>
            </div>
            <div class="column is-4-desktop is-6-mobile">
                <div class="box has-background-light has-text-centered py-3">
                    <p class="heading"><?php echo $t('statistics.best_streak'); ?></p>
                    <p class="title is-5" x-text="streakLabel(bestStreak)">--</p>
                </div>
            </div>
            <div class="column is-4-desktop is-12-mobile">
                <div class="box has-background-light has-text-centered py-3">
                    <p class="heading"><?php echo $t('statistics.today'); ?></p>
                    <p class="is-size-6">
                        <span class="has-text-weight-semibold" x-text="todayCreated"></span>
                        <?php echo $t('statistics.today_created'); ?>,
                        <span class="has-text-weight-semibold" x-text="todayReviewed"></span>
                        <?php echo $t('statistics.today_reviewed'); ?>,
                        <span class="has-text-weight-semibold" x-text="todayTextsRead"></span>
                        <?php echo $t('statistics.today_read'); ?>
                    </p>
                </div>
            </div>
        </div>

        <!-- Calendar heatmap -->
        <div x-data="calendarHeatmap">
            <div style="overflow-x: auto;">
                <p class="heading mb-2"><?php echo $t('statistics.activity_last_12_months'); ?></p>
                <div x-show="loading" class="has-text-centered py-3">
                    <span class="has-text-grey-light is-size-7"><?php echo $t('statistics.loading_activity'); ?></span>
                </div>
                <div x-show="error" class="has-text-danger is-size-7" x-text="error"></div>
                <div x-ref="svgContainer" style="min-width: 720px;"></div>
            </div>
        </div>
    </section>

<div x-data="statisticsApp()">
    <!-- Hidden data elements for Chart.js initialization -->
    <?php ConfigIsland::render('statistics-intensity-data', ['languages' => $intensityChartData]); ?>
    <?php ConfigIsland::render('statistics-frequency-data', ['totals' => $frequencyChartTotals]); ?>

    <!-- Learning Intensity Section -->
    <section class="box mb-4" x-data="{ open: true }">
        <header class="collapsible-header" @click="open = !open">
            <h2 class="title is-4 mb-0">
                <span class="icon-text">
                    <span class="icon has-text-info">
                        <?php echo IconHelper::render('bar-chart-2', ['class' => 'icon']); ?>
                    </span>
                    <span><?php echo $t('statistics.intensity_title'); ?></span>
                </span>
            </h2>
            <span class="icon collapse-icon" :class="{ 'is-rotated': open }">
                <?php echo IconHelper::render('chevron-down'); ?>
            </span>
        </header>

        <div class="collapsible-content" x-show="open" x-collapse>
        <!-- Intensity Chart -->
        <div class="box mb-4 mt-4">
            <h3 class="subtitle is-6"><?php echo $t('statistics.intensity_chart_title'); ?></h3>
            <canvas id="intensityChart" height="100"></canvas>
        </div>
        </div>
    </section>

    <!-- Learning Frequency Section -->
    <section class="box mb-4" x-data="{ open: true }">
        <header class="collapsible-header" @click="open = !open">
            <h2 class="title is-4 mb-0">
                <span class="icon-text">
                    <span class="icon has-text-success">
                        <?php echo IconHelper::render('trending-up', ['class' => 'icon']); ?>
                    </span>
                    <span><?php echo $t('statistics.frequency_title'); ?></span>
                </span>
            </h2>
            <span class="icon collapse-icon" :class="{ 'is-rotated': open }">
                <?php echo IconHelper::render('chevron-down'); ?>
            </span>
        </header>

        <div class="collapsible-content" x-show="open" x-collapse>
        <!-- Frequency Chart -->
        <div class="box mb-4 mt-4">
            <h3 class="subtitle is-6"><?php echo $t('statistics.frequency_chart_title'); ?></h3>
            <canvas id="frequencyChart" height="80"></canvas>
        </div>
        </div>
    </section>

    <!-- Back Button -->
    <div class="field">
        <div class="control">
            <a href="/" class="button is-light">
                <?php echo IconHelper::render('arrow-left'); ?>
                <span><?php echo $t('statistics.back_to_main'); ?></span>
            </a>
        </div>
    </div>
</div>
</div>

<style>
/* Collapsible section styles */
.collapsible-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    cursor: pointer;
    user-select: none;
}

.collapsible-header:hover {
    opacity: 0.8;
}

.collapse-icon {
    transition: transform 0.2s ease;
}

.collapse-icon.is-rotated {
    transform: rotate(180deg);
}

/* Statistics status tags - matching LWT's existing status colors */
.tag.status-1 { background-color: #F5B8A9; color: #000; }
.tag.status-2 { background-color: #F5CCA9; color: #000; }
.tag.status-3 { background-color: #F5E1A9; color: #000; }
.tag.status-4 { background-color: #F5F3A9; color: #000; }
.tag.status-5 { background-color: #CCFFCC; color: #000; }
.tag.status-98 { background-color: #E5E5E5; color: #000; }
.tag.status-99 { background-color: #99DDDF; color: #000; }
.tag.status-known { background-color: #AADDAA; color: #000; }
</style>
