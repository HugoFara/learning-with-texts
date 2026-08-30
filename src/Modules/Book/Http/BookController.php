<?php

/**
 * Book Controller
 *
 * PHP version 8.2
 *
 * @category Lwt
 * @package  Lwt\Modules\Book\Http
 * @author   HugoFara <git@hugofara.net>
 * @license  Unlicense <http://unlicense.org/>
 * @link     https://hugofara.github.io/lwt/developer/api
 * @since    3.0.0
 */

declare(strict_types=1);

namespace Lwt\Modules\Book\Http;

use Lwt\Modules\Book\Application\BookFacade;
use Lwt\Modules\Language\Application\LanguageFacade;
use Lwt\Shared\Infrastructure\Container\Container;
use Lwt\Shared\Infrastructure\Http\InputValidator;
use Lwt\Shared\UI\Helpers\PageLayoutHelper;
use Lwt\Shared\UI\Helpers\SelectOptionsBuilder;

/**
 * Controller for book management operations.
 *
 * @since 3.0.0
 */
class BookController
{
    /**
     * View base path.
     */
    private string $viewPath;

    /**
     * Book facade.
     */
    private BookFacade $bookFacade;

    /**
     * Constructor.
     *
     * @param BookFacade $bookFacade Book facade
     */
    public function __construct(BookFacade $bookFacade)
    {
        $this->viewPath = __DIR__ . '/../Views/';
        $this->bookFacade = $bookFacade;
    }

    /**
     * List all books.
     *
     * @param array<string, mixed> $params Route parameters
     *
     * @return void
     */
    public function index(array $params): void
    {
        // The list, the language filter and the pagination are all fetched by
        // the bookList component from /api/v1. Only the query-string state is
        // handed over, so a bookmarked ?lg_id=&page= still opens where the
        // reader left off.
        $languageId = InputValidator::getInt('lg_id') ?? 0;
        $page = max(1, InputValidator::getInt('page') ?? 1);

        PageLayoutHelper::renderPageStart(__('book.my_books'), true, 'books');
        include $this->viewPath . 'index.php';
        PageLayoutHelper::renderPageEnd();
    }

    /**
     * Show a single book with chapters.
     *
     * @param array<string, mixed> $params Route parameters (id)
     *
     * @return void
     */
    public function show(array $params): void
    {
        $bookId = (int) ($params['id'] ?? 0);

        if ($bookId <= 0) {
            header('Location: /books');
            exit;
        }

        // The bookDetail component fetches the book from /api/v1 and reports a
        // missing one itself, so no lookup happens here. The page title is
        // generic because the title is not known server-side any more.
        PageLayoutHelper::renderPageStart(__('book.my_books'), true, 'books');
        include $this->viewPath . 'show.php';
        PageLayoutHelper::renderPageEnd();
    }

    /**
     * Show the EPUB import form.
     *
     * The upload itself goes to POST /api/v1/books and the outcome is
     * rendered client-side, so this only ever serves the form.
     *
     * @param array<string, mixed> $params Route parameters
     *
     * @return void
     */
    public function import(array $params): void
    {
        $languageFacade = Container::getInstance()->getTyped(LanguageFacade::class);
        $languages = $languageFacade->getLanguagesForSelect();
        $languagesOption = SelectOptionsBuilder::forLanguages($languages, null, __('book.choose_option'));

        // Show info notice when redirected from text import page
        $showFromTextNotice = InputValidator::getString('from') === 'text';

        PageLayoutHelper::renderPageStart(__('book.import_epub'), true, 'books');
        include $this->viewPath . 'import_epub_form.php';
        PageLayoutHelper::renderPageEnd();
    }

    /**
     * Delete a book.
     *
     * @param array<string, mixed> $params Route parameters (id)
     *
     * @return void
     */
    public function delete(array $params): void
    {
        $bookId = (int) ($params['id'] ?? 0);
        $message = __('book.flash.invalid_book_id');

        if ($bookId > 0) {
            $result = $this->bookFacade->deleteBook($bookId);
            $message = $result['message'];
        }

        // Redirect back to books list with message
        header('Location: /books?message=' . urlencode($message));
        exit;
    }
}
