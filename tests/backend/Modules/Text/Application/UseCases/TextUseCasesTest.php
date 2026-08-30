<?php

/**
 * Unit tests for Text module use cases.
 *
 * Tests ArchiveText, BuildTextFilters, GetTextForEdit, and UpdateText
 * use cases. Pure logic methods are tested directly; methods that rely
 * on static database calls are tested for structure and contracts only.
 *
 * PHP version 8.2
 *
 * @category Testing
 * @package  Lwt\Tests\Modules\Text\Application\UseCases
 * @license  Unlicense <http://unlicense.org/>
 * @since    3.0.0
 */

declare(strict_types=1);

namespace Tests\Modules\Text\Application\UseCases;

use Lwt\Modules\Text\Application\UseCases\ArchiveText;
use Lwt\Modules\Text\Application\UseCases\BuildTextFilters;
use Lwt\Modules\Text\Application\UseCases\GetTextForEdit;
use Lwt\Modules\Text\Application\UseCases\UpdateText;
use Lwt\Modules\Text\Domain\Text;
use Lwt\Modules\Text\Domain\TextRepositoryInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for the Text module use cases.
 *
 * Tests business logic in isolation using mocked repositories
 * where possible. No database access required.
 *
 * @since 3.0.0
 */
class TextUseCasesTest extends TestCase
{
    // =========================================================================
    // ArchiveText tests
    // =========================================================================

    /**
     * Test that ArchiveText can be instantiated.
     */
    public function testArchiveTextCanBeInstantiated(): void
    {
        $useCase = new ArchiveText();
        $this->assertInstanceOf(ArchiveText::class, $useCase);
    }

    /**
     * Test archiveMultiple with empty array returns zero count.
     */
    public function testArchiveMultipleWithEmptyArrayReturnsZero(): void
    {
        $useCase = new ArchiveText();
        $result = $useCase->archiveMultiple([]);

        $this->assertIsArray($result);
        $this->assertArrayHasKey('count', $result);
        $this->assertSame(0, $result['count']);
    }

    /**
     * Test unarchiveMultiple with empty array returns zero count.
     */
    public function testUnarchiveMultipleWithEmptyArrayReturnsZero(): void
    {
        $useCase = new ArchiveText();
        $result = $useCase->unarchiveMultiple([]);

        $this->assertIsArray($result);
        $this->assertArrayHasKey('count', $result);
        $this->assertSame(0, $result['count']);
    }

    // =========================================================================
    // BuildTextFilters tests
    // =========================================================================

    /**
     * Test buildQueryWhereClause with empty query returns empty clause.
     */
    public function testBuildQueryWhereClauseEmptyQuery(): void
    {
        $useCase = new BuildTextFilters();
        $result = $useCase->buildQueryWhereClause('', 'title,text', '');

        $this->assertSame('', $result['clause']);
        $this->assertSame([], $result['params']);
    }

    /**
     * Test buildQueryWhereClause with title,text mode using LIKE.
     */
    public function testBuildQueryWhereClauseTitleAndTextMode(): void
    {
        $useCase = new BuildTextFilters();
        $result = $useCase->buildQueryWhereClause('hello', 'title,text', '');

        $this->assertStringContainsString('TxTitle', $result['clause']);
        $this->assertStringContainsString('TxText', $result['clause']);
        $this->assertStringContainsString('LIKE', $result['clause']);
        $this->assertCount(2, $result['params']);
        $this->assertSame('hello', $result['params'][0]);
        $this->assertSame('hello', $result['params'][1]);
    }

    /**
     * Test buildQueryWhereClause with title-only mode.
     */
    public function testBuildQueryWhereClauseTitleOnly(): void
    {
        $useCase = new BuildTextFilters();
        $result = $useCase->buildQueryWhereClause('test', 'title', '');

        $this->assertStringContainsString('TxTitle', $result['clause']);
        $this->assertStringNotContainsString('TxText', $result['clause']);
        $this->assertCount(1, $result['params']);
    }

    /**
     * Test buildQueryWhereClause with text-only mode.
     */
    public function testBuildQueryWhereClauseTextOnly(): void
    {
        $useCase = new BuildTextFilters();
        $result = $useCase->buildQueryWhereClause('test', 'text', '');

        $this->assertStringContainsString('TxText', $result['clause']);
        $this->assertStringNotContainsString('TxTitle', $result['clause']);
        $this->assertCount(1, $result['params']);
    }

    /**
     * Test buildQueryWhereClause with regex mode uses RLIKE.
     */
    public function testBuildQueryWhereClauseRegexMode(): void
    {
        $useCase = new BuildTextFilters();
        $result = $useCase->buildQueryWhereClause('hel.*lo', 'title', 'R');

        $this->assertStringContainsString('RLIKE', $result['clause']);
        // Regex mode should not transform wildcards
        $this->assertSame('hel.*lo', $result['params'][0]);
    }

    /**
     * Test buildQueryWhereClause with LIKE mode converts wildcards.
     */
    public function testBuildQueryWhereClauseLikeModeConvertsWildcards(): void
    {
        $useCase = new BuildTextFilters();
        $result = $useCase->buildQueryWhereClause('hel*o', 'title', '');

        $this->assertSame('hel%o', $result['params'][0]);
    }

    /**
     * Test buildQueryWhereClause with custom table prefix.
     */
    public function testBuildQueryWhereClauseCustomPrefix(): void
    {
        $useCase = new BuildTextFilters();
        $result = $useCase->buildQueryWhereClause('test', 'title', '', 'At');

        $this->assertStringContainsString('AtTitle', $result['clause']);
    }

    /**
     * Test buildQueryWhereClause with unknown query mode falls back to title+text.
     */
    public function testBuildQueryWhereClauseUnknownModeFallback(): void
    {
        $useCase = new BuildTextFilters();
        $result = $useCase->buildQueryWhereClause('test', 'unknown', '');

        $this->assertStringContainsString('TxTitle', $result['clause']);
        $this->assertStringContainsString('TxText', $result['clause']);
        $this->assertCount(2, $result['params']);
    }

    /**
     * Test buildArchivedQueryWhereClause delegates to buildQueryWhereClause with Tx prefix.
     */
    public function testBuildArchivedQueryWhereClause(): void
    {
        $useCase = new BuildTextFilters();
        $result = $useCase->buildArchivedQueryWhereClause('test', 'title', '');

        $this->assertStringContainsString('TxTitle', $result['clause']);
    }

    /**
     * Test buildTagHavingClause with both tags empty returns empty string.
     */
    public function testBuildTagHavingClauseBothEmpty(): void
    {
        $useCase = new BuildTextFilters();
        $result = $useCase->buildTagHavingClause('', '', 'AND');

        $this->assertSame('', $result);
    }

    /**
     * Test buildTagHavingClause with single tag.
     */
    public function testBuildTagHavingClauseSingleTag(): void
    {
        $useCase = new BuildTextFilters();
        $result = $useCase->buildTagHavingClause('5', '', 'AND');

        $this->assertStringContainsString('HAVING', $result);
        $this->assertStringContainsString('5', $result);
    }

    /**
     * Test buildTagHavingClause with tag value -1 (untagged).
     */
    public function testBuildTagHavingClauseUntaggedFilter(): void
    {
        $useCase = new BuildTextFilters();
        $result = $useCase->buildTagHavingClause('-1', '', 'AND');

        $this->assertStringContainsString('HAVING', $result);
        $this->assertStringContainsString('IS NULL', $result);
    }

    /**
     * Test buildTagHavingClause with two tags and AND operator.
     */
    public function testBuildTagHavingClauseTwoTagsAnd(): void
    {
        $useCase = new BuildTextFilters();
        // tag12 is truthy for AND
        $result = $useCase->buildTagHavingClause('3', '7', '1');

        $this->assertStringContainsString('HAVING', $result);
        $this->assertStringContainsString('AND', $result);
        $this->assertStringContainsString('3', $result);
        $this->assertStringContainsString('7', $result);
    }

    /**
     * Test buildTagHavingClause with two tags and OR operator.
     */
    public function testBuildTagHavingClauseTwoTagsOr(): void
    {
        $useCase = new BuildTextFilters();
        // tag12 is falsy for OR
        $result = $useCase->buildTagHavingClause('3', '7', '');

        $this->assertStringContainsString('HAVING', $result);
        $this->assertStringContainsString('OR', $result);
    }

    /**
     * Test buildTagHavingClause with second tag only.
     */
    public function testBuildTagHavingClauseSecondTagOnly(): void
    {
        $useCase = new BuildTextFilters();
        $result = $useCase->buildTagHavingClause('', '10', 'AND');

        $this->assertStringContainsString('HAVING', $result);
        $this->assertStringContainsString('10', $result);
    }

    /**
     * Test buildTagHavingClause with custom tag ID column.
     */
    public function testBuildTagHavingClauseCustomColumn(): void
    {
        $useCase = new BuildTextFilters();
        $result = $useCase->buildTagHavingClause('5', '', 'AND', 'AgT2ID');

        $this->assertStringContainsString('AgT2ID', $result);
    }

    /**
     * Test buildTextTagHavingClause uses TtT2ID column.
     */
    public function testBuildTextTagHavingClause(): void
    {
        $useCase = new BuildTextFilters();
        $result = $useCase->buildTextTagHavingClause('5', '', 'AND');

        $this->assertStringContainsString('TtT2ID', $result);
    }

    /**
     * Test buildArchivedTagHavingClause uses TtT2ID column.
     */
    public function testBuildArchivedTagHavingClause(): void
    {
        $useCase = new BuildTextFilters();
        $result = $useCase->buildArchivedTagHavingClause('5', '', 'AND');

        $this->assertStringContainsString('TtT2ID', $result);
    }

    /**
     * Test buildTagHavingClause with non-numeric tag values.
     */
    public function testBuildTagHavingClauseNonNumericTagsIgnored(): void
    {
        $useCase = new BuildTextFilters();
        $result = $useCase->buildTagHavingClause('abc', 'xyz', 'AND');

        // Non-numeric values should produce null tags, resulting in empty string
        $this->assertSame('', $result);
    }

    /**
     * Test validateRegexQuery with empty query returns true.
     */
    public function testValidateRegexQueryEmptyQueryReturnsTrue(): void
    {
        $useCase = new BuildTextFilters();
        $result = $useCase->validateRegexQuery('', 'r');

        $this->assertTrue($result);
    }

    /**
     * Test validateRegexQuery with empty regex mode returns true.
     */
    public function testValidateRegexQueryEmptyModeReturnsTrue(): void
    {
        $useCase = new BuildTextFilters();
        $result = $useCase->validateRegexQuery('some query', '');

        $this->assertTrue($result);
    }

    // =========================================================================
    // GetTextForEdit tests
    // =========================================================================

    /**
     * Test getTextById returns text data when found.
     */
    public function testGetTextByIdReturnsArrayWhenFound(): void
    {
        /** @var TextRepositoryInterface&MockObject $repository */
        $repository = $this->createMock(TextRepositoryInterface::class);

        $text = Text::reconstitute(
            42,
            1,
            'Test Title',
            'Some text content',
            '',
            'https://example.com/audio.mp3',
            'https://source.example.com',
            0,
            0.0
        );

        $repository->expects($this->once())
            ->method('find')
            ->with(42)
            ->willReturn($text);

        $useCase = new GetTextForEdit($repository);
        $result = $useCase->getTextById(42);

        $this->assertIsArray($result);
        $this->assertSame(42, $result['TxID']);
        $this->assertSame(1, $result['TxLgID']);
        $this->assertSame('Test Title', $result['TxTitle']);
        $this->assertSame('Some text content', $result['TxText']);
        $this->assertSame('https://example.com/audio.mp3', $result['TxAudioURI']);
        $this->assertSame('https://source.example.com', $result['TxSourceURI']);
        $this->assertSame(0, $result['annot_exists']);
    }

    /**
     * Test getTextById returns null when text is not found.
     */
    public function testGetTextByIdReturnsNullWhenNotFound(): void
    {
        /** @var TextRepositoryInterface&MockObject $repository */
        $repository = $this->createMock(TextRepositoryInterface::class);

        $repository->expects($this->once())
            ->method('find')
            ->with(999)
            ->willReturn(null);

        $useCase = new GetTextForEdit($repository);
        $result = $useCase->getTextById(999);

        $this->assertNull($result);
    }

    /**
     * Test getTextById returns annot_exists=1 for annotated text.
     */
    public function testGetTextByIdReturnsAnnotatedFlag(): void
    {
        /** @var TextRepositoryInterface&MockObject $repository */
        $repository = $this->createMock(TextRepositoryInterface::class);

        $text = Text::reconstitute(
            10,
            2,
            'Annotated Text',
            'Content here',
            'some annotation data',
            '',
            '',
            5,
            12.5
        );

        $repository->expects($this->once())
            ->method('find')
            ->with(10)
            ->willReturn($text);

        $useCase = new GetTextForEdit($repository);
        $result = $useCase->getTextById(10);

        $this->assertSame(1, $result['annot_exists']);
        $this->assertSame(2, $result['TxLgID']);
    }

    /**
     * Test getTextsForSelect delegates to repository.
     */
    public function testGetTextsForSelectDelegatesToRepository(): void
    {
        /** @var TextRepositoryInterface&MockObject $repository */
        $repository = $this->createMock(TextRepositoryInterface::class);

        $expected = [
            ['id' => 1, 'title' => 'Text One', 'language_id' => 1],
            ['id' => 2, 'title' => 'Text Two', 'language_id' => 1],
        ];

        $repository->expects($this->once())
            ->method('getForSelect')
            ->with(1, 30)
            ->willReturn($expected);

        $useCase = new GetTextForEdit($repository);
        $result = $useCase->getTextsForSelect(1, 30);

        $this->assertSame($expected, $result);
    }

    /**
     * Test getTextsForSelect with default parameters.
     */
    public function testGetTextsForSelectDefaultParams(): void
    {
        /** @var TextRepositoryInterface&MockObject $repository */
        $repository = $this->createMock(TextRepositoryInterface::class);

        $repository->expects($this->once())
            ->method('getForSelect')
            ->with(0, 30)
            ->willReturn([]);

        $useCase = new GetTextForEdit($repository);
        $result = $useCase->getTextsForSelect();

        $this->assertSame([], $result);
    }

    /**
     * Test getTextById returns media URI correctly.
     */
    public function testGetTextByIdReturnsMediaUri(): void
    {
        /** @var TextRepositoryInterface&MockObject $repository */
        $repository = $this->createMock(TextRepositoryInterface::class);

        $text = Text::reconstitute(
            5,
            1,
            'Audio Text',
            'Content',
            '',
            'https://example.com/audio.mp3',
            'https://source.com',
            0,
            0.0
        );

        $repository->expects($this->once())
            ->method('find')
            ->with(5)
            ->willReturn($text);

        $useCase = new GetTextForEdit($repository);
        $result = $useCase->getTextById(5);

        $this->assertSame('https://example.com/audio.mp3', $result['TxAudioURI']);
        $this->assertSame('https://source.com', $result['TxSourceURI']);
    }

    // =========================================================================
    // UpdateText tests
    // =========================================================================

    /**
     * Test UpdateText can be instantiated.
     */
    public function testUpdateTextCanBeInstantiated(): void
    {
        $useCase = new UpdateText();
        $this->assertInstanceOf(UpdateText::class, $useCase);
    }

    /**
     * Test formatUpdateMessage with no update.
     */
    public function testFormatUpdateMessageNoUpdate(): void
    {
        $result = UpdateText::formatUpdateMessage(false, false);
        $this->assertSame('No changes', $result);
    }

    /**
     * Test formatUpdateMessage with update but no reparse.
     */
    public function testFormatUpdateMessageUpdatedNoReparse(): void
    {
        $result = UpdateText::formatUpdateMessage(true, false);
        $this->assertSame('Updated', $result);
    }

    /**
     * Test formatUpdateMessage with update and reparse.
     */
    public function testFormatUpdateMessageUpdatedAndReparsed(): void
    {
        $result = UpdateText::formatUpdateMessage(true, true);
        $this->assertSame('Updated and reparsed', $result);
    }

    /**
     * Test formatArchivedUpdateMessage.
     */
    public function testFormatArchivedUpdateMessage(): void
    {
        $this->assertSame('Updated: 0', UpdateText::formatArchivedUpdateMessage(0));
        $this->assertSame('Updated: 1', UpdateText::formatArchivedUpdateMessage(1));
        $this->assertSame('Updated: 5', UpdateText::formatArchivedUpdateMessage(5));
    }

    /**
     * Test formatRebuildMessage.
     */
    public function testFormatRebuildMessage(): void
    {
        $this->assertSame('Rebuilt Text(s): 0', UpdateText::formatRebuildMessage(0));
        $this->assertSame('Rebuilt Text(s): 3', UpdateText::formatRebuildMessage(3));
    }

    /**
     * Test rebuildTexts with empty array returns zero.
     */
    public function testRebuildTextsEmptyArrayReturnsZero(): void
    {
        $useCase = new UpdateText();
        $result = $useCase->rebuildTexts([]);

        $this->assertSame(0, $result);
    }
}
