<?php

/**
 * Word Upload Result View
 *
 * Displays the results of a word import operation with pagination.
 *
 * Expected variables:
 * - $lastUpdate: Timestamp of last word update (for filtering)
 * - $rtl: Whether the language is right-to-left
 * - $recno: Number of records imported
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

use Lwt\Shared\UI\Helpers\IconHelper;
use Lwt\Shared\UI\Helpers\PageLayoutHelper;

// Type assertions for variables passed from controller
assert(is_string($lastUpdate));
assert(is_bool($rtl));
assert(is_int($recno));

// Action buttons for navigation
$actions = [
    [
        'url' => '/word/upload',
        'label' => __('vocabulary.actions.import_more_terms'),
        'icon' => 'file-up',
        'class' => 'is-primary'
    ],
    ['url' => '/words', 'label' => __('vocabulary.actions.my_terms'), 'icon' => 'list'],
    ['url' => '/', 'label' => __('vocabulary.actions.home'), 'icon' => 'home']
];
echo PageLayoutHelper::buildActionCard($actions);

$termsImportedLabel = $recno == 1
    ? __('vocabulary.upload.terms_imported_one')
    : __('vocabulary.upload.terms_imported_other');
$titleSentence = htmlspecialchars(__('vocabulary.common.sentence'), ENT_QUOTES, 'UTF-8');
$colStatus = __('vocabulary.upload.results.status');
?>

<!-- Import Result Feedback -->
<?php if ($recno > 0) : ?>
<article class="message is-success mb-4">
    <div class="message-body">
        <span class="icon-text">
            <span class="icon">
                <?php echo IconHelper::render('check', ['alt' => 'Success']); ?>
            </span>
            <span>
                <strong><?= __('vocabulary.upload.import_successful') ?></strong>
                <span id="recno"><?php echo $recno; ?></span>
                <?= $termsImportedLabel ?>
            </span>
        </span>
    </div>
</article>
<?php else : ?>
<article class="message is-warning mb-4">
    <div class="message-body">
        <span class="icon-text">
            <span class="icon">
                <?php echo IconHelper::render('alert-triangle', ['alt' => 'Warning']); ?>
            </span>
            <span>
                <strong><?= __('vocabulary.upload.no_terms_warning') ?></strong>
                <?= __('vocabulary.upload.no_terms_reason') ?>
            </span>
        </span>
    </div>
</article>
<?php endif; ?>

<script type="application/json" data-lwt-upload-result-config>
<?php echo json_encode([
    'lastUpdate' => $lastUpdate,
    'rtl' => $rtl,
    'recno' => $recno,
], JSON_HEX_TAG | JSON_HEX_AMP); ?>
</script>

<div class="box" x-data="wordUploadResultApp">

    <!-- No terms message -->
    <template x-if="!hasTerms && !isLoading">
        <p class="has-text-centered has-text-grey py-4">
            <?= __('vocabulary.upload.no_terms_imported') ?>
        </p>
    </template>

    <!-- Loading indicator -->
    <template x-if="isLoading">
        <div class="has-text-centered py-4">
            <span class="icon">
                <?php echo IconHelper::render('loader-2', ['alt' => 'Loading', 'class' => 'animate-spin']); ?>
            </span>
            <span>Loading...</span>
        </div>
    </template>

    <!-- Results content -->
    <template x-if="hasTerms && !isLoading">
        <div>
            <!-- Pagination Navigation -->
            <nav class="level mb-4">
                <div class="level-left">
                    <div class="level-item">
                        <span class="tag is-medium is-info is-light">
                            <span x-text="recno"></span>&nbsp;Term<span x-show="recno !== 1">s</span>
                        </span>
                    </div>
                </div>
                <div class="level-right">
                    <div class="level-item">
                        <nav class="pagination is-small" role="navigation" aria-label="pagination">
                            <span class="pagination-previous" x-show="currentPage > 1">
                                <span class="icon is-clickable" title="First Page" @click="goFirst()">
                                    <?php echo IconHelper::render('chevrons-left', ['alt' => 'First Page']); ?>
                                </span>
                                <span class="icon is-clickable" title="Previous Page" @click="goPrev()">
                                    <?php echo IconHelper::render('chevron-left', ['alt' => 'Previous Page']); ?>
                                </span>
                            </span>
                            <span class="pagination-list">
                                <span class="mr-2"><?= __('vocabulary.upload.results.page') ?></span>
                                <template x-if="totalPages <= 1">
                                    <span>1</span>
                                </template>
                                <template x-if="totalPages > 1">
                                    <select
                                        class="select is-small"
                                        x-model="currentPage"
                                        @change="goToPageFromEvent($event)"
                                    >
                                        <template x-for="p in totalPages" :key="p">
                                            <option :value="p" x-text="p" :selected="p === currentPage"></option>
                                        </template>
                                    </select>
                                </template>
                                <span class="ml-1 mr-1"><?= __('vocabulary.upload.results.of') ?></span>
                                <span x-text="totalPages"></span>
                            </span>
                            <span class="pagination-next" x-show="currentPage < totalPages">
                                <span class="icon is-clickable" title="Next Page" @click="goNext()">
                                    <?php echo IconHelper::render('chevron-right', ['alt' => 'Next Page']); ?>
                                </span>
                                <span class="icon is-clickable" title="Last Page" @click="goLast()">
                                    <?php echo IconHelper::render('chevrons-right', ['alt' => 'Last Page']); ?>
                                </span>
                            </span>
                        </nav>
                    </div>
                </div>
            </nav>

            <!-- Results Table -->
            <div class="table-container">
                <table class="table is-striped is-hoverable is-fullwidth sortable">
                    <thead>
                        <tr>
                            <th class="is-clickable"><?= __('vocabulary.upload.results.term_romanization') ?></th>
                            <th class="is-clickable"><?= __('vocabulary.upload.results.translation') ?></th>
                            <th><?= __('vocabulary.upload.results.tags') ?></th>
                            <th class="has-text-centered" title="<?= $titleSentence ?>">
                                <?= __('vocabulary.upload.results.sentence_short') ?></th>
                            <th class="has-text-centered is-clickable"><?= $colStatus ?></th>
                        </tr>
                    </thead>
                    <tbody x-effect="setTableBodyHtml($el)">
                    </tbody>
                </table>
            </div>
        </div>
    </template>
</div>
