<?php

declare(strict_types=1);

namespace Lwt\Tests\Modules\Book\Http;

use Lwt\Modules\Book\Http\BookApiHandler;
use Lwt\Modules\Book\Application\BookFacade;
use Lwt\Shared\Http\ApiRoutableInterface;
use Lwt\Shared\Infrastructure\Http\JsonResponse;
use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\MockObject;

/**
 * Unit tests for BookApiHandler.
 *
 * Tests book API operations: list, get, chapters, delete,
 * update progress, and HTTP routing methods.
 */
class BookApiHandlerTest extends TestCase
{
    /** @var BookFacade&MockObject */
    private BookFacade $bookFacade;

    private BookApiHandler $handler;

    protected function setUp(): void
    {
        $this->bookFacade = $this->createMock(BookFacade::class);
        $this->handler = new BookApiHandler($this->bookFacade);
    }

    // =========================================================================
    // Constructor tests
    // =========================================================================

    #[Test]
    public function constructorCreatesValidHandler(): void
    {
        $this->assertInstanceOf(BookApiHandler::class, $this->handler);
    }

    #[Test]
    public function constructorStoresBookFacade(): void
    {
        $reflection = new \ReflectionProperty(BookApiHandler::class, 'bookFacade');

        $this->assertSame($this->bookFacade, $reflection->getValue($this->handler));
    }

    #[Test]
    public function implementsApiRoutableInterface(): void
    {
        $this->assertInstanceOf(ApiRoutableInterface::class, $this->handler);
    }

    // =========================================================================
    // listBooks tests
    // =========================================================================

    #[Test]
    public function listBooksReturnsSuccessWithBooks(): void
    {
        $booksData = [
            'books' => [['id' => 1, 'title' => 'Book 1']],
            'total' => 1,
            'page' => 1,
            'perPage' => 20,
            'totalPages' => 1,
        ];

        $this->bookFacade->expects($this->once())
            ->method('getBooks')
            ->willReturn($booksData);

        $result = $this->handler->listBooks([]);

        $this->assertTrue($result['success']);
        $this->assertSame([['id' => 1, 'title' => 'Book 1']], $result['data']);
        $this->assertSame(1, $result['pagination']['total']);
        $this->assertSame(1, $result['pagination']['page']);
        $this->assertSame(20, $result['pagination']['per_page']);
        $this->assertSame(1, $result['pagination']['total_pages']);
    }

    #[Test]
    public function listBooksReturnsEmptyArrayWhenNoBooks(): void
    {
        $this->bookFacade->method('getBooks')
            ->willReturn([
                'books' => [],
                'total' => 0,
                'page' => 1,
                'perPage' => 20,
                'totalPages' => 0,
            ]);

        $result = $this->handler->listBooks([]);

        $this->assertTrue($result['success']);
        $this->assertSame([], $result['data']);
        $this->assertSame(0, $result['pagination']['total']);
    }

    #[Test]
    public function listBooksPassesLanguageIdFilter(): void
    {
        $this->bookFacade->expects($this->once())
            ->method('getBooks')
            ->with($this->anything(), 5, $this->anything(), $this->anything())
            ->willReturn([
                'books' => [],
                'total' => 0,
                'page' => 1,
                'perPage' => 20,
                'totalPages' => 0,
            ]);

        $this->handler->listBooks(['lg_id' => '5']);
    }

    #[Test]
    public function listBooksDefaultsPageToOne(): void
    {
        $this->bookFacade->expects($this->once())
            ->method('getBooks')
            ->with($this->anything(), $this->anything(), 1, $this->anything())
            ->willReturn([
                'books' => [],
                'total' => 0,
                'page' => 1,
                'perPage' => 20,
                'totalPages' => 0,
            ]);

        $this->handler->listBooks([]);
    }

    #[Test]
    public function listBooksPassesPageParameter(): void
    {
        $this->bookFacade->expects($this->once())
            ->method('getBooks')
            ->with($this->anything(), $this->anything(), 3, $this->anything())
            ->willReturn([
                'books' => [],
                'total' => 0,
                'page' => 3,
                'perPage' => 20,
                'totalPages' => 0,
            ]);

        $this->handler->listBooks(['page' => '3']);
    }

    #[Test]
    public function listBooksClampsPageToMinimumOne(): void
    {
        $this->bookFacade->expects($this->once())
            ->method('getBooks')
            ->with($this->anything(), $this->anything(), 1, $this->anything())
            ->willReturn([
                'books' => [],
                'total' => 0,
                'page' => 1,
                'perPage' => 20,
                'totalPages' => 0,
            ]);

        $this->handler->listBooks(['page' => '-5']);
    }

    #[Test]
    public function listBooksPassesPerPageParameter(): void
    {
        $this->bookFacade->expects($this->once())
            ->method('getBooks')
            ->with($this->anything(), $this->anything(), $this->anything(), 50)
            ->willReturn([
                'books' => [],
                'total' => 0,
                'page' => 1,
                'perPage' => 50,
                'totalPages' => 0,
            ]);

        $this->handler->listBooks(['per_page' => '50']);
    }

    #[Test]
    public function listBooksClampsPerPageToMaximumHundred(): void
    {
        $this->bookFacade->expects($this->once())
            ->method('getBooks')
            ->with($this->anything(), $this->anything(), $this->anything(), 100)
            ->willReturn([
                'books' => [],
                'total' => 0,
                'page' => 1,
                'perPage' => 100,
                'totalPages' => 0,
            ]);

        $this->handler->listBooks(['per_page' => '500']);
    }

    #[Test]
    public function listBooksClampsPerPageToMinimumOne(): void
    {
        $this->bookFacade->expects($this->once())
            ->method('getBooks')
            ->with($this->anything(), $this->anything(), $this->anything(), 1)
            ->willReturn([
                'books' => [],
                'total' => 0,
                'page' => 1,
                'perPage' => 1,
                'totalPages' => 0,
            ]);

        $this->handler->listBooks(['per_page' => '0']);
    }

    #[Test]
    public function listBooksPassesNullLanguageIdWhenNotSet(): void
    {
        $this->bookFacade->expects($this->once())
            ->method('getBooks')
            ->with($this->anything(), null, $this->anything(), $this->anything())
            ->willReturn([
                'books' => [],
                'total' => 0,
                'page' => 1,
                'perPage' => 20,
                'totalPages' => 0,
            ]);

        $this->handler->listBooks([]);
    }

    // =========================================================================
    // getBook tests
    // =========================================================================

    #[Test]
    public function getBookReturnsSuccessWithData(): void
    {
        $bookData = [
            'book' => ['id' => 1, 'title' => 'Test Book'],
            'chapters' => [['id' => 1, 'num' => 1, 'title' => 'Chapter 1']],
        ];

        $this->bookFacade->expects($this->once())
            ->method('getBook')
            ->with(1)
            ->willReturn($bookData);

        $result = $this->handler->getBook(['id' => 1]);

        $this->assertTrue($result['success']);
        $this->assertSame($bookData, $result['data']);
    }

    #[Test]
    public function getBookReturnsErrorForInvalidId(): void
    {
        $result = $this->handler->getBook(['id' => 0]);

        $this->assertFalse($result['success']);
        $this->assertSame('Invalid book ID', $result['error']);
    }

    #[Test]
    public function getBookReturnsErrorForNegativeId(): void
    {
        $result = $this->handler->getBook(['id' => -5]);

        $this->assertFalse($result['success']);
        $this->assertSame('Invalid book ID', $result['error']);
    }

    #[Test]
    public function getBookReturnsErrorForMissingId(): void
    {
        $result = $this->handler->getBook([]);

        $this->assertFalse($result['success']);
        $this->assertSame('Invalid book ID', $result['error']);
    }

    #[Test]
    public function getBookReturnsNotFoundWhenBookDoesNotExist(): void
    {
        $this->bookFacade->expects($this->once())
            ->method('getBook')
            ->with(999)
            ->willReturn(null);

        $result = $this->handler->getBook(['id' => 999]);

        $this->assertFalse($result['success']);
        $this->assertSame('Book not found', $result['error']);
    }

    // =========================================================================
    // getChapters tests
    // =========================================================================

    #[Test]
    public function getChaptersReturnsSuccessWithData(): void
    {
        $chapters = [
            ['id' => 1, 'num' => 1, 'title' => 'Chapter 1'],
            ['id' => 2, 'num' => 2, 'title' => 'Chapter 2'],
        ];

        $this->bookFacade->expects($this->once())
            ->method('getChapters')
            ->with(1)
            ->willReturn($chapters);

        $result = $this->handler->getChapters(['id' => 1]);

        $this->assertTrue($result['success']);
        $this->assertSame($chapters, $result['data']);
    }

    #[Test]
    public function getChaptersReturnsErrorForInvalidId(): void
    {
        $result = $this->handler->getChapters(['id' => 0]);

        $this->assertFalse($result['success']);
        $this->assertSame('Invalid book ID', $result['error']);
    }

    #[Test]
    public function getChaptersReturnsErrorForMissingId(): void
    {
        $result = $this->handler->getChapters([]);

        $this->assertFalse($result['success']);
        $this->assertSame('Invalid book ID', $result['error']);
    }

    #[Test]
    public function getChaptersReturnsEmptyArrayWhenNoChapters(): void
    {
        $this->bookFacade->expects($this->once())
            ->method('getChapters')
            ->with(5)
            ->willReturn([]);

        $result = $this->handler->getChapters(['id' => 5]);

        $this->assertTrue($result['success']);
        $this->assertSame([], $result['data']);
    }

    // =========================================================================
    // deleteBook tests
    // =========================================================================

    #[Test]
    public function deleteBookReturnsSuccessResult(): void
    {
        $this->bookFacade->expects($this->once())
            ->method('deleteBook')
            ->with(1)
            ->willReturn(['success' => true, 'message' => 'Book deleted']);

        $result = $this->handler->deleteBook(['id' => 1]);

        $this->assertTrue($result['success']);
        $this->assertSame('Book deleted', $result['message']);
    }

    #[Test]
    public function deleteBookReturnsErrorForInvalidId(): void
    {
        $result = $this->handler->deleteBook(['id' => 0]);

        $this->assertFalse($result['success']);
        $this->assertSame('Invalid book ID', $result['error']);
    }

    #[Test]
    public function deleteBookReturnsErrorForMissingId(): void
    {
        $result = $this->handler->deleteBook([]);

        $this->assertFalse($result['success']);
        $this->assertSame('Invalid book ID', $result['error']);
    }

    #[Test]
    public function deleteBookReturnsFailureFromFacade(): void
    {
        $this->bookFacade->expects($this->once())
            ->method('deleteBook')
            ->with(42)
            ->willReturn(['success' => false, 'message' => 'Book not found']);

        $result = $this->handler->deleteBook(['id' => 42]);

        $this->assertFalse($result['success']);
        $this->assertSame('Book not found', $result['message']);
    }

    // =========================================================================
    // updateProgress tests
    // =========================================================================

    #[Test]
    public function updateProgressReturnsSuccess(): void
    {
        $this->bookFacade->expects($this->once())
            ->method('updateReadingProgress')
            ->with(1, 5);

        $result = $this->handler->updateProgress(['id' => 1, 'chapter' => 5]);

        $this->assertTrue($result['success']);
        $this->assertSame('Reading progress updated', $result['message']);
    }

    #[Test]
    public function updateProgressReturnsErrorForInvalidBookId(): void
    {
        $result = $this->handler->updateProgress(['id' => 0, 'chapter' => 1]);

        $this->assertFalse($result['success']);
        $this->assertSame('Invalid book ID or chapter number', $result['error']);
    }

    #[Test]
    public function updateProgressReturnsErrorForInvalidChapter(): void
    {
        $result = $this->handler->updateProgress(['id' => 1, 'chapter' => 0]);

        $this->assertFalse($result['success']);
        $this->assertSame('Invalid book ID or chapter number', $result['error']);
    }

    #[Test]
    public function updateProgressReturnsErrorWhenBothInvalid(): void
    {
        $result = $this->handler->updateProgress(['id' => 0, 'chapter' => 0]);

        $this->assertFalse($result['success']);
        $this->assertSame('Invalid book ID or chapter number', $result['error']);
    }

    #[Test]
    public function updateProgressReturnsErrorForNegativeBookId(): void
    {
        $result = $this->handler->updateProgress(['id' => -1, 'chapter' => 1]);

        $this->assertFalse($result['success']);
    }

    #[Test]
    public function updateProgressReturnsErrorForNegativeChapter(): void
    {
        $result = $this->handler->updateProgress(['id' => 1, 'chapter' => -3]);

        $this->assertFalse($result['success']);
    }

    #[Test]
    public function updateProgressReturnsErrorForMissingParams(): void
    {
        $result = $this->handler->updateProgress([]);

        $this->assertFalse($result['success']);
    }

    // =========================================================================
    // routeGet tests
    // =========================================================================

    #[Test]
    public function routeGetListsBooksWithNoFragments(): void
    {
        $this->bookFacade->method('getBooks')
            ->willReturn([
                'books' => [],
                'total' => 0,
                'page' => 1,
                'perPage' => 20,
                'totalPages' => 0,
            ]);

        $response = $this->handler->routeGet(['books'], []);

        $this->assertInstanceOf(JsonResponse::class, $response);
        $this->assertSame(200, $response->getStatusCode());
    }

    #[Test]
    public function routeGetReturnsBookForNumericFragment(): void
    {
        $this->bookFacade->method('getBook')
            ->with(42)
            ->willReturn([
                'book' => ['id' => 42, 'title' => 'Test'],
                'chapters' => [],
            ]);

        $response = $this->handler->routeGet(['books', '42'], []);

        $this->assertInstanceOf(JsonResponse::class, $response);
        $this->assertSame(200, $response->getStatusCode());
    }

    #[Test]
    public function routeGetReturnsChaptersForChaptersFragment(): void
    {
        $this->bookFacade->expects($this->once())
            ->method('getChapters')
            ->with(7)
            ->willReturn([]);

        $response = $this->handler->routeGet(['books', '7', 'chapters'], []);

        $this->assertInstanceOf(JsonResponse::class, $response);
        $this->assertSame(200, $response->getStatusCode());
    }

    #[Test]
    public function routeGetFallsBackToListForNonNumericFragment(): void
    {
        $this->bookFacade->expects($this->once())
            ->method('getBooks')
            ->willReturn([
                'books' => [],
                'total' => 0,
                'page' => 1,
                'perPage' => 20,
                'totalPages' => 0,
            ]);

        $response = $this->handler->routeGet(['books', 'abc'], []);

        $this->assertSame(200, $response->getStatusCode());
    }

    // =========================================================================
    // routePut tests
    // =========================================================================

    #[Test]
    public function routePutUpdatesProgressForValidRoute(): void
    {
        $this->bookFacade->expects($this->once())
            ->method('updateReadingProgress')
            ->with(10, 3);

        $response = $this->handler->routePut(
            ['books', '10', 'progress'],
            ['chapter' => 3]
        );

        $this->assertInstanceOf(JsonResponse::class, $response);
        $this->assertSame(200, $response->getStatusCode());
    }

    #[Test]
    public function routePutReturnsErrorForMissingProgressFragment(): void
    {
        $response = $this->handler->routePut(['books', '10', 'other'], []);

        $this->assertInstanceOf(JsonResponse::class, $response);
        $this->assertSame(404, $response->getStatusCode());
    }

    #[Test]
    public function routePutReturnsErrorForNonNumericId(): void
    {
        $response = $this->handler->routePut(['books', 'abc', 'progress'], []);

        $this->assertSame(404, $response->getStatusCode());
    }

    #[Test]
    public function routePutReturnsErrorForEmptyFragment(): void
    {
        $response = $this->handler->routePut(['books'], []);

        $this->assertSame(404, $response->getStatusCode());
    }

    // =========================================================================
    // routeDelete tests
    // =========================================================================

    #[Test]
    public function routeDeleteDeletesBookForNumericId(): void
    {
        $this->bookFacade->expects($this->once())
            ->method('deleteBook')
            ->with(15)
            ->willReturn(['success' => true, 'message' => 'Deleted']);

        $response = $this->handler->routeDelete(['books', '15'], []);

        $this->assertInstanceOf(JsonResponse::class, $response);
        $this->assertSame(200, $response->getStatusCode());
    }

    #[Test]
    public function routeDeleteReturnsErrorForNonNumericId(): void
    {
        $response = $this->handler->routeDelete(['books', 'abc'], []);

        $this->assertSame(404, $response->getStatusCode());
    }

    #[Test]
    public function routeDeleteReturnsErrorForMissingId(): void
    {
        $response = $this->handler->routeDelete(['books'], []);

        $this->assertSame(404, $response->getStatusCode());
    }

    #[Test]
    public function routeDeleteErrorMessageMentionsBookId(): void
    {
        $response = $this->handler->routeDelete(['books'], []);

        $data = $response->getData();
        $this->assertStringContainsString('Book ID', $data['error']);
    }

    // =========================================================================
    // routePost tests (EPUB import)
    // =========================================================================

    #[Test]
    public function routePostHandlesTheCollection(): void
    {
        // No language in the body, so this is the handled-failure path rather
        // than the trait's old 405 — POST /books is a real endpoint now, and
        // 400 rather than 405 is what distinguishes the two. It answered 200
        // until issue #284: the handler reports the missing language by
        // returning an error payload, which Response::success() used to send
        // as a success.
        $response = $this->handler->routePost([], []);

        $this->assertSame(400, $response->getStatusCode());
    }

    // =========================================================================
    // frag helper tests via route methods
    // =========================================================================

    #[Test]
    public function routeGetHandlesEmptyFragmentsArray(): void
    {
        $this->bookFacade->method('getBooks')
            ->willReturn([
                'books' => [],
                'total' => 0,
                'page' => 1,
                'perPage' => 20,
                'totalPages' => 0,
            ]);

        // Empty string at index 1 means no book ID -> falls through to list
        $response = $this->handler->routeGet([], []);

        $this->assertSame(200, $response->getStatusCode());
    }

    #[Test]
    public function routeGetPassesParamsToListBooks(): void
    {
        $this->bookFacade->expects($this->once())
            ->method('getBooks')
            ->with($this->anything(), 3, 2, 10)
            ->willReturn([
                'books' => [],
                'total' => 0,
                'page' => 2,
                'perPage' => 10,
                'totalPages' => 0,
            ]);

        $this->handler->routeGet([], ['lg_id' => '3', 'page' => '2', 'per_page' => '10']);
    }

    // =========================================================================
    // Class structure tests
    // =========================================================================

    #[Test]
    public function classHasExpectedPublicMethods(): void
    {
        $reflection = new \ReflectionClass(BookApiHandler::class);
        $expectedMethods = [
            'listBooks', 'getBook', 'getChapters', 'deleteBook',
            'updateProgress', 'routeGet', 'routePut', 'routeDelete',
        ];

        foreach ($expectedMethods as $methodName) {
            $this->assertTrue(
                $reflection->hasMethod($methodName),
                "BookApiHandler should have method: $methodName"
            );
            $this->assertTrue(
                $reflection->getMethod($methodName)->isPublic(),
                "Method $methodName should be public"
            );
        }
    }

    #[Test]
    public function listBooksReturnsArrayType(): void
    {
        $method = new \ReflectionMethod(BookApiHandler::class, 'listBooks');
        $this->assertSame('array', $method->getReturnType()->getName());
    }

    #[Test]
    public function getBookReturnsArrayType(): void
    {
        $method = new \ReflectionMethod(BookApiHandler::class, 'getBook');
        $this->assertSame('array', $method->getReturnType()->getName());
    }

    // =========================================================================
    // importBook tests
    // =========================================================================

    #[Test]
    public function importBookRejectsMissingLanguage(): void
    {
        $this->bookFacade->expects($this->never())->method('importEpub');

        $result = $this->handler->importBook([]);

        $this->assertFalse($result['success']);
        $this->assertArrayHasKey('error', $result);
    }

    #[Test]
    public function importBookRejectsNonPositiveLanguage(): void
    {
        $this->bookFacade->expects($this->never())->method('importEpub');

        $result = $this->handler->importBook(['LgID' => '0', 'TxLgID' => '-3']);

        $this->assertFalse($result['success']);
    }

    #[Test]
    public function importBookRejectsMissingFile(): void
    {
        // A language is present, so this can only fail on the absent upload.
        $_FILES = [];
        $this->bookFacade->expects($this->never())->method('importEpub');

        $result = $this->handler->importBook(['LgID' => '2']);

        $this->assertFalse($result['success']);
        $this->assertArrayHasKey('error', $result);
    }

    #[Test]
    public function importBookAcceptsTxLgIdAliasFromTextsNew(): void
    {
        // /texts/new posts the language as TxLgID rather than LgID; reaching
        // the file check proves the alias resolved to a usable language.
        $_FILES = [];

        $result = $this->handler->importBook(['TxLgID' => '7']);

        $this->assertFalse($result['success']);
        $this->assertStringNotContainsString('language', strtolower((string) $result['error']));
    }

    #[Test]
    public function importBookReadsEitherFileFieldName(): void
    {
        // /book/import names the input 'thefile', /texts/new 'importFile'.
        $source = file_get_contents(
            (new \ReflectionClass(BookApiHandler::class))->getFileName()
        );

        $this->assertStringContainsString("getUploadedFile('thefile')", $source);
        $this->assertStringContainsString("getUploadedFile('importFile')", $source);
    }

    #[Test]
    public function parseTagIdsHandlesACommaSeparatedString(): void
    {
        $this->assertSame([3, 7, 12], $this->parseTagIds('3,7,12'));
        $this->assertSame([3, 7], $this->parseTagIds(' 3 , 7 '));
    }

    #[Test]
    public function parseTagIdsHandlesAnArray(): void
    {
        // Tagify replaces the text input with its own multi-value control, so
        // the field arrives as an array. Casting that to string raises a
        // warning the exception handler turns into a 500.
        $this->assertSame([4, 9], $this->parseTagIds(['4', '9']));
        $this->assertSame([4, 9], $this->parseTagIds([4, 9]));
    }

    #[Test]
    public function parseTagIdsDropsEmptyAndNonNumericEntries(): void
    {
        $this->assertSame([], $this->parseTagIds(''));
        $this->assertSame([], $this->parseTagIds(null));
        $this->assertSame([], $this->parseTagIds([]));
        $this->assertSame([5], $this->parseTagIds(['', '5', 'abc', '0']));
    }

    /**
     * Call the private tag parser.
     *
     * @param mixed $raw Posted TextTags value
     *
     * @return int[]
     */
    private function parseTagIds(mixed $raw): array
    {
        $method = new \ReflectionMethod(BookApiHandler::class, 'parseTagIds');

        /** @var int[] $result */
        $result = $method->invoke($this->handler, $raw);

        return $result;
    }

    #[Test]
    public function routePostRejectsSubResources(): void
    {
        $response = $this->handler->routePost(['books', '12'], []);

        $this->assertSame(404, $response->getStatusCode());
    }

    #[Test]
    public function routeMethodsReturnJsonResponse(): void
    {
        $methods = ['routeGet', 'routePost', 'routePut', 'routeDelete'];
        foreach ($methods as $methodName) {
            $method = new \ReflectionMethod(BookApiHandler::class, $methodName);
            $this->assertSame(
                JsonResponse::class,
                $method->getReturnType()->getName(),
                "$methodName should return JsonResponse"
            );
        }
    }
}
