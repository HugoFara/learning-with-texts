<?php

/**
 * Book Detail View — scaffold only.
 *
 * Carries the book ID and nothing else. The `bookDetail` Alpine component
 * fetches the book and its chapters from `GET /api/v1/books/{id}` and
 * `GET /api/v1/books/{id}/chapters`.
 *
 * Variables expected:
 * - $bookId: int - The book being shown
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
    ['url' => '/books', 'label' => __('book.all_books'), 'icon' => 'library'],
    ['url' => '/texts/new', 'label' => __('book.import_epub'), 'icon' => 'file-up'],
];
?>

<?php ConfigIsland::render('book-detail-config', ['bookId' => $bookId]); ?>

<div x-data="bookDetail" x-init="init()">

    <h2 class="title is-4" x-text="book ? book.title : ''"></h2>

    <?php echo PageLayoutHelper::buildActionCard($actions); ?>

    <div x-show="error" class="notification is-danger is-light">
        <span x-text="error"></span>
    </div>

    <div x-show="isLoading" class="has-text-centered py-6">
        <span class="icon is-large">
            <i data-lucide="loader-2" class="animate-spin"></i>
        </span>
    </div>

    <div x-show="!isLoading && book">
        <div class="box">
            <div class="columns">
                <div class="column is-8">
                    <div class="content">
                        <p x-show="book && book.author">
                            <strong><?php echo __('common.author'); ?>:</strong>
                            <span x-text="book ? book.author : ''"></span>
                        </p>

                        <p x-show="book && book.description">
                            <strong><?php echo __('common.description'); ?>:</strong>
                            <span x-text="book ? book.description : ''"></span>
                        </p>

                        <p>
                            <strong><?php echo __('book.source'); ?>:</strong>
                            <span class="tag is-info" x-text="sourceLabel()"></span>
                        </p>

                        <p>
                            <strong><?php echo __('book.col_progress'); ?>:</strong>
                            <span x-text="chapterCountLabel()"></span>
                            (<span x-text="progressLabel()"></span>)
                        </p>

                        <progress class="progress is-primary"
                                  :value="book ? book.progress : 0"
                                  max="100"
                                  x-text="progressLabel()"></progress>
                    </div>

                    <a :href="continueHref()" class="button is-primary is-medium"
                       x-show="chapters.length > 0">
                        <?php echo IconHelper::render('book-open', ['alt' => __('book.continue_reading')]); ?>
                        <span class="ml-2"><?php echo __('book.continue_reading'); ?></span>
                    </a>
                </div>

                <div class="column is-4">
                    <div class="buttons">
                        <button type="button" class="button is-danger is-outlined" @click="remove()">
                            <?php echo IconHelper::render('trash-2', ['alt' => __('common.delete')]); ?>
                            <span class="ml-2"><?php echo __('book.delete_book'); ?></span>
                        </button>
                    </div>
                </div>
            </div>
        </div>

        <!-- Chapters -->
        <div class="box">
            <h3 class="title is-5"><?php echo __('book.chapters'); ?></h3>

            <p class="has-text-grey" x-show="chapters.length === 0">
                <?php echo __('book.no_chapters_found'); ?>
            </p>

            <table class="table is-fullwidth is-hoverable" x-show="chapters.length > 0">
                <thead>
                    <tr>
                        <th style="width: 60px;">#</th>
                        <th><?php echo __('common.title'); ?></th>
                        <th style="width: 100px;"><?php echo __('common.actions'); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <template x-for="chapter in chapters" :key="chapter.id">
                        <tr :class="isCurrentChapter(chapter) ? 'is-selected' : ''">
                            <td x-text="chapter.num"></td>
                            <td>
                                <a :href="readHref(chapter)" x-text="chapter.title"></a>
                                <span class="tag is-small is-info ml-2"
                                      x-show="isCurrentChapter(chapter)">
                                    <?php echo __('common.current'); ?>
                                </span>
                            </td>
                            <td>
                                <a :href="readHref(chapter)" class="button is-small is-primary">
                                    <?php echo IconHelper::render('book-open', ['alt' => __('common.read')]); ?>
                                </a>
                                <a :href="editHref(chapter)" class="button is-small is-light">
                                    <?php echo IconHelper::render('edit', ['alt' => __('common.edit')]); ?>
                                </a>
                            </td>
                        </tr>
                    </template>
                </tbody>
            </table>
        </div>
    </div>
</div>
