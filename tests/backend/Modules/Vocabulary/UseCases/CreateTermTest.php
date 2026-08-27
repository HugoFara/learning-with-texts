<?php

declare(strict_types=1);

namespace Lwt\Tests\Modules\Vocabulary\UseCases;

use DateTimeImmutable;
use Lwt\Shared\Infrastructure\Globals;
use Lwt\Modules\Vocabulary\Application\UseCases\CreateTerm;
use Lwt\Modules\Vocabulary\Domain\Term;
use Lwt\Modules\Vocabulary\Domain\TermRepositoryInterface;
use Lwt\Modules\Vocabulary\Domain\ValueObject\TermId;
use Lwt\Modules\Vocabulary\Domain\ValueObject\TermStatus;
use Lwt\Modules\Language\Domain\ValueObject\LanguageId;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Unit tests for the CreateTerm use case.
 */
class CreateTermTest extends TestCase
{
    /** @var TermRepositoryInterface&MockObject */
    private TermRepositoryInterface $repository;
    private CreateTerm $useCase;

    protected function setUp(): void
    {
        parent::setUp();
        Globals::reset();
        $this->repository = $this->createMock(TermRepositoryInterface::class);
        $this->useCase = new CreateTerm($this->repository);
    }

    protected function tearDown(): void
    {
        Globals::reset();
        parent::tearDown();
    }

    // =========================================================================
    // execute() Tests
    // =========================================================================

    public function testExecuteCreatesTermSuccessfully(): void
    {
        $this->repository->expects($this->once())
            ->method('termExists')
            ->with(1, 'hello')
            ->willReturn(false);

        $this->repository->expects($this->once())
            ->method('save')
            ->willReturnCallback(function (Term $term) {
                // Simulate ID assignment
                return 42;
            });

        $term = $this->useCase->execute(
            languageId: 1,
            text: 'Hello',
            status: 1,
            translation: 'Bonjour',
            sentence: 'Hello world',
            notes: 'Common greeting',
            romanization: '',
            wordCount: 1
        );

        $this->assertInstanceOf(Term::class, $term);
        $this->assertEquals('Hello', $term->text());
        $this->assertEquals('hello', $term->textLowercase());
        $this->assertEquals('Bonjour', $term->translation());
        $this->assertEquals('Hello world', $term->sentence());
        $this->assertEquals('Common greeting', $term->notes());
        $this->assertEquals(1, $term->wordCount());
    }

    public function testExecuteThrowsExceptionForEmptyText(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Term text cannot be empty');

        $this->useCase->execute(
            languageId: 1,
            text: '   ',
            status: 1
        );
    }

    public function testExecuteThrowsExceptionForDuplicateTerm(): void
    {
        $this->repository->expects($this->once())
            ->method('termExists')
            ->with(1, 'hello')
            ->willReturn(true);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Term "hello" already exists in this language');

        $this->useCase->execute(
            languageId: 1,
            text: 'Hello',
            status: 1
        );
    }

    public function testExecuteNormalizesEmptyTranslationToAsterisk(): void
    {
        $this->repository->method('termExists')->willReturn(false);
        $this->repository->method('save')->willReturn(1);

        $term = $this->useCase->execute(
            languageId: 1,
            text: 'Test',
            status: 1,
            translation: ''
        );

        $this->assertEquals('*', $term->translation());
    }

    public function testExecuteNormalizesAsteriskTranslation(): void
    {
        $this->repository->method('termExists')->willReturn(false);
        $this->repository->method('save')->willReturn(1);

        $term = $this->useCase->execute(
            languageId: 1,
            text: 'Test',
            status: 1,
            translation: '*'
        );

        $this->assertEquals('*', $term->translation());
    }

    public function testExecuteCalculatesWordCountWhenNotProvided(): void
    {
        $this->repository->method('termExists')->willReturn(false);
        $this->repository->method('save')->willReturn(1);

        $term = $this->useCase->execute(
            languageId: 1,
            text: 'multi word expression',
            status: 1,
            wordCount: 0
        );

        $this->assertEquals(3, $term->wordCount());
    }

    public function testExecuteUsesProvidedWordCount(): void
    {
        $this->repository->method('termExists')->willReturn(false);
        $this->repository->method('save')->willReturn(1);

        $term = $this->useCase->execute(
            languageId: 1,
            text: 'multi word expression',
            status: 1,
            wordCount: 5
        );

        $this->assertEquals(5, $term->wordCount());
    }

    public function testExecuteProcessesLemma(): void
    {
        $this->repository->method('termExists')->willReturn(false);
        $this->repository->method('save')->willReturn(1);

        $term = $this->useCase->execute(
            languageId: 1,
            text: 'running',
            status: 1,
            lemma: 'Run'
        );

        $this->assertEquals('Run', $term->lemma());
        $this->assertEquals('run', $term->lemmaLc());
    }

    public function testExecuteHandlesNullLemma(): void
    {
        $this->repository->method('termExists')->willReturn(false);
        $this->repository->method('save')->willReturn(1);

        $term = $this->useCase->execute(
            languageId: 1,
            text: 'test',
            status: 1,
            lemma: null
        );

        $this->assertNull($term->lemma());
        $this->assertNull($term->lemmaLc());
    }

    public function testExecuteHandlesEmptyLemma(): void
    {
        $this->repository->method('termExists')->willReturn(false);
        $this->repository->method('save')->willReturn(1);

        $term = $this->useCase->execute(
            languageId: 1,
            text: 'test',
            status: 1,
            lemma: ''
        );

        $this->assertNull($term->lemma());
        $this->assertNull($term->lemmaLc());
    }

    public function testExecuteReplacesTabsAndNewlinesInSentence(): void
    {
        $this->repository->method('termExists')->willReturn(false);
        $this->repository->method('save')->willReturn(1);

        $term = $this->useCase->execute(
            languageId: 1,
            text: 'test',
            status: 1,
            sentence: "Line1\tTab\nLine2\r\nLine3"
        );

        $this->assertEquals('Line1 Tab Line2 Line3', $term->sentence());
    }

    public function testExecuteReplacesTabsAndNewlinesInNotes(): void
    {
        $this->repository->method('termExists')->willReturn(false);
        $this->repository->method('save')->willReturn(1);

        $term = $this->useCase->execute(
            languageId: 1,
            text: 'test',
            status: 1,
            notes: "Note1\tTab\nNote2"
        );

        $this->assertEquals('Note1 Tab Note2', $term->notes());
    }

    public function testExecuteTrimsTextWithLeadingTrailingSpaces(): void
    {
        $this->repository->expects($this->once())
            ->method('termExists')
            ->with(1, 'hello')
            ->willReturn(false);

        $this->repository->method('save')->willReturn(1);

        $term = $this->useCase->execute(
            languageId: 1,
            text: '  Hello  ',
            status: 1
        );

        $this->assertEquals('Hello', $term->text());
    }

    // =========================================================================
    // executeFromArray() Tests
    // =========================================================================

    public function testExecuteFromArrayReturnsSuccessOnCreate(): void
    {
        $this->repository->method('termExists')->willReturn(false);
        $this->repository->method('save')->willReturn(42);

        $result = $this->useCase->executeFromArray([
            'WoLgID' => 1,
            'WoText' => 'Hello',
            'WoStatus' => 2,
            'WoTranslation' => 'Bonjour',
            'WoSentence' => 'Hello world',
            'WoNotes' => 'Common greeting',
            'WoRomanization' => '',
            'WoWordCount' => 1
        ]);

        $this->assertTrue($result['success']);
        $this->assertEquals('Term saved', $result['message']);
        $this->assertEquals('hello', $result['textlc']);
        $this->assertEquals('Hello', $result['text']);
    }

    public function testExecuteFromArrayReturnsFailureOnEmptyText(): void
    {
        $result = $this->useCase->executeFromArray([
            'WoLgID' => 1,
            'WoText' => '',
            'WoStatus' => 1
        ]);

        $this->assertFalse($result['success']);
        $this->assertStringContainsString('Term text cannot be empty', $result['message']);
        $this->assertEquals(0, $result['id']);
    }

    public function testExecuteFromArrayReturnsFailureOnDuplicate(): void
    {
        $this->repository->method('termExists')->willReturn(true);

        $result = $this->useCase->executeFromArray([
            'WoLgID' => 1,
            'WoText' => 'Hello',
            'WoStatus' => 1
        ]);

        $this->assertFalse($result['success']);
        $this->assertStringContainsString('already exists', $result['message']);
    }

    public function testExecuteFromArrayHandlesMissingFields(): void
    {
        $this->repository->method('termExists')->willReturn(false);
        $this->repository->method('save')->willReturn(1);

        $result = $this->useCase->executeFromArray([
            'WoLgID' => 1,
            'WoText' => 'Test'
        ]);

        $this->assertTrue($result['success']);
    }

    public function testExecuteFromArrayHandlesDatabaseException(): void
    {
        $this->repository->method('termExists')->willReturn(false);
        $this->repository->method('save')->willThrowException(
            new \Exception('Duplicate entry for key')
        );

        $result = $this->useCase->executeFromArray([
            'WoLgID' => 1,
            'WoText' => 'Test'
        ]);

        $this->assertFalse($result['success']);
        $this->assertStringContainsString('Duplicate entry', $result['message']);
    }

    public function testExecuteFromArrayHandlesGenericException(): void
    {
        $this->repository->method('termExists')->willReturn(false);
        $this->repository->method('save')->willThrowException(
            new \Exception('Some database error')
        );

        $result = $this->useCase->executeFromArray([
            'WoLgID' => 1,
            'WoText' => 'Test'
        ]);

        $this->assertFalse($result['success']);
        $this->assertStringContainsString('Some database error', $result['message']);
    }

    // =========================================================================
    // Edge Cases
    // =========================================================================

    public function testExecuteHandlesUnicodeText(): void
    {
        $this->repository->expects($this->once())
            ->method('termExists')
            ->with(1, '日本語')
            ->willReturn(false);

        $this->repository->method('save')->willReturn(1);

        $term = $this->useCase->execute(
            languageId: 1,
            text: '日本語',
            status: 1
        );

        $this->assertEquals('日本語', $term->text());
        $this->assertEquals('日本語', $term->textLowercase());
    }

    public function testExecuteHandlesMixedCaseText(): void
    {
        $this->repository->expects($this->once())
            ->method('termExists')
            ->with(1, 'helloworld')
            ->willReturn(false);

        $this->repository->method('save')->willReturn(1);

        $term = $this->useCase->execute(
            languageId: 1,
            text: 'HelloWorld',
            status: 1
        );

        $this->assertEquals('HelloWorld', $term->text());
        $this->assertEquals('helloworld', $term->textLowercase());
    }

    public function testExecuteSetsDefaultStatus(): void
    {
        $this->repository->method('termExists')->willReturn(false);
        $this->repository->method('save')->willReturn(1);

        $term = $this->useCase->execute(
            languageId: 1,
            text: 'test'
        );

        $this->assertEquals(1, $term->status()->toInt());
    }

    public function testExecuteSetsCreatedAndStatusChangedDates(): void
    {
        $this->repository->method('termExists')->willReturn(false);
        $this->repository->method('save')->willReturn(1);

        $before = new DateTimeImmutable();
        $term = $this->useCase->execute(
            languageId: 1,
            text: 'test'
        );
        $after = new DateTimeImmutable();

        $this->assertGreaterThanOrEqual($before, $term->createdAt());
        $this->assertLessThanOrEqual($after, $term->createdAt());
        $this->assertGreaterThanOrEqual($before, $term->statusChangedAt());
    }

    #[DataProvider('wordCountProvider')]
    public function testExecuteCalculatesWordCountCorrectly(string $text, int $expectedCount): void
    {
        $this->repository->method('termExists')->willReturn(false);
        $this->repository->method('save')->willReturn(1);

        $term = $this->useCase->execute(
            languageId: 1,
            text: $text,
            wordCount: 0
        );

        $this->assertEquals($expectedCount, $term->wordCount());
    }

    /**
     * @return array<string, array{string, int}>
     */
    public static function wordCountProvider(): array
    {
        return [
            'single word' => ['hello', 1],
            'two words' => ['hello world', 2],
            'three words' => ['hello beautiful world', 3],
            'multiple spaces' => ['hello    world', 2],
            'leading spaces' => ['  hello world', 2],
            'trailing spaces' => ['hello world  ', 2],
        ];
    }
}
