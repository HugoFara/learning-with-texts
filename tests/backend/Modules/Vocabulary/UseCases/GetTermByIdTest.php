<?php

declare(strict_types=1);

namespace Lwt\Tests\Modules\Vocabulary\UseCases;

use DateTimeImmutable;
use Lwt\Shared\Infrastructure\Globals;
use Lwt\Modules\Vocabulary\Application\UseCases\GetTermById;
use Lwt\Modules\Vocabulary\Domain\Term;
use Lwt\Modules\Vocabulary\Domain\TermRepositoryInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for the GetTermById use case.
 */
class GetTermByIdTest extends TestCase
{
    /** @var TermRepositoryInterface&MockObject */
    private TermRepositoryInterface $repository;
    private GetTermById $useCase;

    protected function setUp(): void
    {
        parent::setUp();
        Globals::reset();
        $this->repository = $this->createMock(TermRepositoryInterface::class);
        $this->useCase = new GetTermById($this->repository);
    }

    protected function tearDown(): void
    {
        Globals::reset();
        parent::tearDown();
    }

    private function createTestTerm(int $id = 42): Term
    {
        return Term::reconstitute(
            $id,
            1,
            'TestWord',
            'testword',
            'test',
            'test',
            3,
            'Translation',
            'Example sentence',
            'Some notes',
            'romanization',
            1,
            new DateTimeImmutable('2024-01-15 10:30:00'),
            new DateTimeImmutable('2024-01-16 11:00:00'),
            0.5,
            0.7,
            0.3
        );
    }

    // =========================================================================
    // execute() Tests
    // =========================================================================

    public function testExecuteReturnsTermWhenFound(): void
    {
        $term = $this->createTestTerm();

        $this->repository->expects($this->once())
            ->method('find')
            ->with(42)
            ->willReturn($term);

        $result = $this->useCase->execute(42);

        $this->assertInstanceOf(Term::class, $result);
        $this->assertEquals(42, $result->id()->toInt());
        $this->assertEquals('TestWord', $result->text());
    }

    public function testExecuteReturnsNullWhenNotFound(): void
    {
        $this->repository->expects($this->once())
            ->method('find')
            ->with(999)
            ->willReturn(null);

        $result = $this->useCase->execute(999);

        $this->assertNull($result);
    }

    public function testExecuteReturnsNullForZeroId(): void
    {
        $this->repository->expects($this->never())->method('find');

        $result = $this->useCase->execute(0);

        $this->assertNull($result);
    }

    public function testExecuteReturnsNullForNegativeId(): void
    {
        $this->repository->expects($this->never())->method('find');

        $result = $this->useCase->execute(-1);

        $this->assertNull($result);
    }

    // =========================================================================
    // executeAsArray() Tests
    // =========================================================================

    public function testExecuteAsArrayReturnsArrayWhenFound(): void
    {
        $term = $this->createTestTerm();

        $this->repository->method('find')->willReturn($term);

        $result = $this->useCase->executeAsArray(42);

        $this->assertIsArray($result);
        $this->assertEquals(42, $result['WoID']);
        $this->assertEquals(1, $result['WoLgID']);
        $this->assertEquals('TestWord', $result['WoText']);
        $this->assertEquals('testword', $result['WoTextLC']);
        $this->assertEquals(3, $result['WoStatus']);
        $this->assertEquals('Translation', $result['WoTranslation']);
        $this->assertEquals('Example sentence', $result['WoSentence']);
        $this->assertEquals('Some notes', $result['WoNotes']);
        $this->assertEquals('romanization', $result['WoRomanization']);
        $this->assertEquals(1, $result['WoWordCount']);
        $this->assertEquals('2024-01-15 10:30:00', $result['WoCreated']);
        $this->assertEquals('2024-01-16 11:00:00', $result['WoStatusChanged']);
    }

    public function testExecuteAsArrayReturnsNullWhenNotFound(): void
    {
        $this->repository->method('find')->willReturn(null);

        $result = $this->useCase->executeAsArray(999);

        $this->assertNull($result);
    }

    public function testExecuteAsArrayReturnsNullForZeroId(): void
    {
        $result = $this->useCase->executeAsArray(0);

        $this->assertNull($result);
    }

    public function testExecuteAsArrayReturnsNullForNegativeId(): void
    {
        $result = $this->useCase->executeAsArray(-5);

        $this->assertNull($result);
    }

    public function testExecuteAsArrayContainsAllExpectedKeys(): void
    {
        $term = $this->createTestTerm();
        $this->repository->method('find')->willReturn($term);

        $result = $this->useCase->executeAsArray(42);

        $expectedKeys = [
            'WoID',
            'WoLgID',
            'WoText',
            'WoTextLC',
            'WoStatus',
            'WoTranslation',
            'WoSentence',
            'WoNotes',
            'WoRomanization',
            'WoWordCount',
            'WoCreated',
            'WoStatusChanged',
        ];

        foreach ($expectedKeys as $key) {
            $this->assertArrayHasKey($key, $result);
        }
    }
}
