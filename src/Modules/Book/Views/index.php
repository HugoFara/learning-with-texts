<?php

/**
 * Books List View — scaffold only.
 *
 * Carries no book data. The `bookList` Alpine component fetches everything
 * from `GET /api/v1/books` and renders the table, filter and pagination, so
 * this page works against a configurable API base URL.
 *
 * Variables expected:
 * - $languageId: int|null - Language filter from the query string
 * - $page: int - Page number from the query string
 *
 * PHP version 8.2
 *
 * @category Lwt
 * @package  Lwt\Modules\Book\Views
 * @author   HugoFara <git@hugofara.net>
 * @license  Unlicense <http://unlicense.org/>
 * @link     https://hugofara.github.io/lwt/developer/api
 * @since    3.0.0
 */

declare(strict_types=1);

namespace Lwt\Views\Book;

use Lwt\Shared\UI\Helpers\IconHelper;
use Lwt\Shared\UI\Helpers\PageLayoutHelper;
use Lwt\Shared\UI\Helpers\ConfigIsland;

$actions = [
    ['url' => '/texts/new', 'label' => __('book.import_epub'), 'icon' => 'file-up', 'class' => 'is-primary'],
    ['url' => '/texts/new', 'label' => __('book.new_text'), 'icon' => 'circle-plus'],
    ['url' => '/texts', 'label' => __('book.all_texts'), 'icon' => 'book-open'],
];
?>

<h2 class="title is-4">
    <?php echo __('book.my_books'); ?>
    <a target="_blank" href="docs/info.html#howtotext" class="ml-2">
        <?php
        echo IconHelper::render('help-circle', [
            'title' => __('common.help'),
            'alt' => __('common.help'),
        ]);
        ?>
    </a>
</h2>

<?php echo PageLayoutHelper::buildActionCard($actions); ?>

<?php ConfigIsland::render('book-list-config', [
    'languageId' => $languageId ?? 0,
    'page' => $page ?? 1,
]); ?>

<div x-data="bookList" x-init="init()">

    <div x-show="notification" x-transition class="notification is-info is-light">
        <button class="delete" @click="clearNotification()"></button>
        <span x-text="notification"></span>
    </div>

    <div x-show="error" class="notification is-danger is-light">
        <span x-text="error"></span>
    </div>

    <!-- Filter -->
    <div class="box">
        <div class="field is-horizontal">
            <div class="field-body">
                <div class="field">
                    <label class="label is-small" for="book-language-filter">
                        <?php echo __('common.language'); ?>
                    </label>
                    <div class="control">
                        <div class="select is-small is-fullwidth">
                            <select id="book-language-filter" @change="changeLanguage($event)">
                                <option value="0" :selected="languageId === 0">
                                    <?php echo __('book.all_languages_option'); ?>
                                </option>
                                <template x-for="lang in languages" :key="lang.id">
                                    <option :value="lang.id" :selected="isSelectedLanguage(lang)"
                                            x-text="lang.name"></option>
                                </template>
                            </select>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Loading -->
    <div x-show="isLoading" class="has-text-centered py-6">
        <span class="icon is-large">
            <i data-lucide="loader-2" class="animate-spin"></i>
        </span>
    </div>

    <!-- Empty -->
    <div x-show="!isLoading && books.length === 0 && !error" class="notification is-light">
        <p><?php echo __('book.no_books_found'); ?></p>
    </div>

    <!-- Books -->
    <div class="box" x-show="!isLoading && books.length > 0">
        <table class="table is-fullwidth is-hoverable">
            <thead>
                <tr>
                    <th><?php echo __('common.title'); ?></th>
                    <th><?php echo __('common.author'); ?></th>
                    <th><?php echo __('book.col_chapters'); ?></th>
                    <th><?php echo __('book.col_progress'); ?></th>
                    <th><?php echo __('common.actions'); ?></th>
                </tr>
            </thead>
            <tbody>
                <template x-for="book in books" :key="book.id">
                    <tr>
                        <td>
                            <a :href="bookHref(book)"><strong x-text="book.title"></strong></a>
                            <span class="tag is-small is-info ml-2"
                                  x-show="book.sourceType === 'epub'">EPUB</span>
                        </td>
                        <td x-text="authorLabel(book)"></td>
                        <td x-text="book.totalChapters"></td>
                        <td>
                            <progress class="progress is-small is-primary"
                                      :value="book.progress"
                                      max="100"
                                      :title="progressLabel(book)"
                                      x-text="progressLabel(book)"></progress>
                        </td>
                        <td>
                            <a :href="bookHref(book)"
                               class="button is-small is-primary"
                               x-show="book.totalChapters > 0"
                               title="<?php echo htmlspecialchars(__('book.continue_reading'), ENT_QUOTES); ?>">
                                <?php echo IconHelper::render('book-open', ['alt' => __('common.read')]); ?>
                            </a>
                            <button type="button"
                                    class="button is-small is-danger is-outlined"
                                    @click="confirmDelete(book)"
                                    title="<?php echo htmlspecialchars(__('common.delete'), ENT_QUOTES); ?>">
                                <?php echo IconHelper::render('trash-2', ['alt' => __('common.delete')]); ?>
                            </button>
                        </td>
                    </tr>
                </template>
            </tbody>
        </table>
    </div>

    <!-- Pagination -->
    <nav class="pagination is-centered" role="navigation" x-show="pagination.total_pages > 1">
        <button type="button" class="pagination-previous"
                :disabled="pagination.page <= 1"
                @click="goToPage(pagination.page - 1)">
            <?php echo __('common.previous'); ?>
        </button>
        <button type="button" class="pagination-next"
                :disabled="pagination.page >= pagination.total_pages"
                @click="goToPage(pagination.page + 1)">
            <?php echo __('common.next'); ?>
        </button>
        <ul class="pagination-list">
            <template x-for="n in pageNumbers()" :key="n">
                <li>
                    <button type="button"
                            class="pagination-link"
                            :class="isCurrentPage(n) ? 'is-current' : ''"
                            @click="goToPage(n)"
                            x-text="n"></button>
                </li>
            </template>
        </ul>
    </nav>
</div>
