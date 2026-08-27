<?php

declare(strict_types=1);

namespace Lwt\Tests\Modules\Vocabulary\Application\Services;

use Lwt\Modules\Vocabulary\Application\Services\WordListQueryService;
use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\DataProvider;
use ReflectionMethod;

/**
 * Unit tests for WordListQueryService.
 *
 * Tests method signatures, parameter handling, and return types.
 * Actual DB queries cannot be tested without a database connection.
 */
class WordListQueryServiceTest extends TestCase
{
    // =========================================================================
    // countWords -- signature & contract
    // =========================================================================

    #[Test]
    public function countWordsIsPublic(): void
    {
        $method = new ReflectionMethod(WordListQueryService::class, 'countWords');
        $this->assertTrue($method->isPublic());
    }

    #[Test]
    public function countWordsReturnsInt(): void
    {
        $method = new ReflectionMethod(WordListQueryService::class, 'countWords');
        $this->assertSame('int', $method->getReturnType()?->getName());
    }

    #[Test]
    public function countWordsAcceptsSixParameters(): void
    {
        $method = new ReflectionMethod(WordListQueryService::class, 'countWords');
        $params = $method->getParameters();
        $this->assertCount(6, $params);

        $this->assertSame('textId', $params[0]->getName());
        $this->assertSame('string', $params[0]->getType()?->getName());

        $this->assertSame('whLang', $params[1]->getName());
        $this->assertSame('string', $params[1]->getType()?->getName());

        $this->assertSame('whStat', $params[2]->getName());
        $this->assertSame('string', $params[2]->getType()?->getName());

        $this->assertSame('whQuery', $params[3]->getName());
        $this->assertSame('string', $params[3]->getType()?->getName());

        $this->assertSame('whTag', $params[4]->getName());
        $this->assertSame('string', $params[4]->getType()?->getName());

        $this->assertSame('params', $params[5]->getName());
        $this->assertSame('array', $params[5]->getType()?->getName());
    }

    #[Test]
    public function countWordsParamsHasDefaultEmptyArray(): void
    {
        $method = new ReflectionMethod(WordListQueryService::class, 'countWords');
        $params = $method->getParameters();
        $this->assertTrue($params[5]->isDefaultValueAvailable());
        $this->assertSame([], $params[5]->getDefaultValue());
    }

    #[Test]
    public function countWordsSourceUsesTextIdBranching(): void
    {
        $method = new ReflectionMethod(WordListQueryService::class, 'countWords');
        $source = $this->getMethodSource($method);

        // Should have both branches: with textId and without
        $this->assertStringContainsString('$textId ==', $source);
        $this->assertStringContainsString('word_occurrences', $source);
        $this->assertStringContainsString('buildPreparedInClause', $source);
    }

    #[Test]
    public function countWordsUsesOnlyPreparedStatements(): void
    {
        $method = new ReflectionMethod(WordListQueryService::class, 'countWords');
        $source = $this->getMethodSource($method);

        $this->assertStringContainsString('preparedFetchValue', $source);
        $this->assertStringNotContainsString('Connection::query(', $source);
        $this->assertStringNotContainsString('Connection::fetchValue(', $source);
    }

    // =========================================================================
    // getWordsList -- signature & contract
    // =========================================================================

    #[Test]
    public function getWordsListIsPublic(): void
    {
        $method = new ReflectionMethod(WordListQueryService::class, 'getWordsList');
        $this->assertTrue($method->isPublic());
    }

    #[Test]
    public function getWordsListReturnsArray(): void
    {
        $method = new ReflectionMethod(WordListQueryService::class, 'getWordsList');
        $this->assertSame('array', $method->getReturnType()?->getName());
    }

    #[Test]
    public function getWordsListAcceptsFourParameters(): void
    {
        $method = new ReflectionMethod(WordListQueryService::class, 'getWordsList');
        $params = $method->getParameters();
        $this->assertCount(4, $params);

        $this->assertSame('filters', $params[0]->getName());
        $this->assertSame('array', $params[0]->getType()?->getName());

        $this->assertSame('sort', $params[1]->getName());
        $this->assertSame('int', $params[1]->getType()?->getName());

        $this->assertSame('page', $params[2]->getName());
        $this->assertSame('int', $params[2]->getType()?->getName());

        $this->assertSame('perPage', $params[3]->getName());
        $this->assertSame('int', $params[3]->getType()?->getName());
    }

    #[Test]
    public function getWordsListSourceHas7SortOptions(): void
    {
        $method = new ReflectionMethod(WordListQueryService::class, 'getWordsList');
        $source = $this->getMethodSource($method);

        $this->assertStringContainsString('WoTextLC', $source);
        $this->assertStringContainsString('ScheduleSql::effectiveDue()', $source);
        $this->assertStringContainsString('textswordcount desc', $source);
    }

    #[Test]
    public function getWordsListSourceDelegatesSort7ToWordCount(): void
    {
        $method = new ReflectionMethod(WordListQueryService::class, 'getWordsList');
        $source = $this->getMethodSource($method);

        $this->assertStringContainsString('getWordsListWithWordCount', $source);
    }

    #[Test]
    public function getWordsListSourceUsesPreparedFetchAll(): void
    {
        $method = new ReflectionMethod(WordListQueryService::class, 'getWordsList');
        $source = $this->getMethodSource($method);

        $this->assertStringContainsString('preparedFetchAll', $source);
        $this->assertStringNotContainsString('Connection::query(', $source);
    }

    #[Test]
    public function getWordsListSourceHandlesThreeTextIdBranches(): void
    {
        $method = new ReflectionMethod(WordListQueryService::class, 'getWordsList');
        $source = $this->getMethodSource($method);

        // Branch 1: no textId, no tag
        // Branch 2: no textId, with tag
        // Branch 3: with textId
        $this->assertStringContainsString('$textId ==', $source);
        $this->assertStringContainsString('$whTag ==', $source);
        $this->assertStringContainsString('buildPreparedInClause', $source);
    }

    #[Test]
    public function getWordsListSourceUsesParameterizedLimit(): void
    {
        $method = new ReflectionMethod(WordListQueryService::class, 'getWordsList');
        $source = $this->getMethodSource($method);

        $this->assertStringContainsString('LIMIT ?, ?', $source);
    }

    // =========================================================================
    // getWordsListWithWordCount -- signature & contract
    // =========================================================================

    #[Test]
    public function getWordsListWithWordCountIsPublic(): void
    {
        $method = new ReflectionMethod(WordListQueryService::class, 'getWordsListWithWordCount');
        $this->assertTrue($method->isPublic());
    }

    #[Test]
    public function getWordsListWithWordCountReturnsArray(): void
    {
        $method = new ReflectionMethod(WordListQueryService::class, 'getWordsListWithWordCount');
        $this->assertSame('array', $method->getReturnType()?->getName());
    }

    #[Test]
    public function getWordsListWithWordCountAcceptsTwoParameters(): void
    {
        $method = new ReflectionMethod(WordListQueryService::class, 'getWordsListWithWordCount');
        $params = $method->getParameters();
        $this->assertCount(2, $params);

        $this->assertSame('filters', $params[0]->getName());
        $this->assertSame('sortExpr', $params[1]->getName());
    }

    #[Test]
    public function getWordsListWithWordCountSourceUsesUnion(): void
    {
        $method = new ReflectionMethod(WordListQueryService::class, 'getWordsListWithWordCount');
        $source = $this->getMethodSource($method);

        $this->assertStringContainsString('UNION', $source);
        $this->assertStringContainsString('textswordcount', $source);
    }

    #[Test]
    public function getWordsListWithWordCountSourceDuplicatesParams(): void
    {
        $method = new ReflectionMethod(WordListQueryService::class, 'getWordsListWithWordCount');
        $source = $this->getMethodSource($method);

        // UNION needs filterParams threaded through both halves: a fresh copy
        // for the first half, then re-append a second copy for the UNION's
        // second half. The append uses a foreach instead of array_merge so
        // Psalm preserves the `array<int, mixed>` shape that
        // UserScopedQuery::forTablePrepared() requires.
        $this->assertStringContainsString(
            'foreach ($filterParams as $param)',
            $source,
            'expected the UNION second half to iterate $filterParams to append a second copy'
        );
        $this->assertStringContainsString(
            '$bindings[] = $param',
            $source,
            'expected each iteration to push $param onto $bindings'
        );
    }

    #[Test]
    public function getWordsListWithWordCountSourceUsesPreparedFetchAll(): void
    {
        $method = new ReflectionMethod(WordListQueryService::class, 'getWordsListWithWordCount');
        $source = $this->getMethodSource($method);

        $this->assertStringContainsString('preparedFetchAll', $source);
    }

    // =========================================================================
    // getFilteredWordIds -- signature & contract
    // =========================================================================

    #[Test]
    public function getFilteredWordIdsIsPublic(): void
    {
        $method = new ReflectionMethod(WordListQueryService::class, 'getFilteredWordIds');
        $this->assertTrue($method->isPublic());
    }

    #[Test]
    public function getFilteredWordIdsReturnsArray(): void
    {
        $method = new ReflectionMethod(WordListQueryService::class, 'getFilteredWordIds');
        $this->assertSame('array', $method->getReturnType()?->getName());
    }

    #[Test]
    public function getFilteredWordIdsAcceptsSixParameters(): void
    {
        $method = new ReflectionMethod(WordListQueryService::class, 'getFilteredWordIds');
        $params = $method->getParameters();
        $this->assertCount(6, $params);

        $this->assertSame('textId', $params[0]->getName());
        $this->assertSame('whLang', $params[1]->getName());
        $this->assertSame('whStat', $params[2]->getName());
        $this->assertSame('whQuery', $params[3]->getName());
        $this->assertSame('whTag', $params[4]->getName());
        $this->assertSame('params', $params[5]->getName());
    }

    #[Test]
    public function getFilteredWordIdsParamsHasDefaultEmptyArray(): void
    {
        $method = new ReflectionMethod(WordListQueryService::class, 'getFilteredWordIds');
        $this->assertTrue($method->getParameters()[5]->isDefaultValueAvailable());
        $this->assertSame([], $method->getParameters()[5]->getDefaultValue());
    }

    #[Test]
    public function getFilteredWordIdsSourceCastsToInt(): void
    {
        $method = new ReflectionMethod(WordListQueryService::class, 'getFilteredWordIds');
        $source = $this->getMethodSource($method);

        $this->assertStringContainsString('(int) $record[\'WoID\']', $source);
    }

    #[Test]
    public function getFilteredWordIdsSourceUsesTextIdBranching(): void
    {
        $method = new ReflectionMethod(WordListQueryService::class, 'getFilteredWordIds');
        $source = $this->getMethodSource($method);

        $this->assertStringContainsString('$textId ==', $source);
        $this->assertStringContainsString('word_occurrences', $source);
        $this->assertStringContainsString('buildPreparedInClause', $source);
    }

    #[Test]
    public function getFilteredWordIdsSourceUsesPreparedFetchAll(): void
    {
        $method = new ReflectionMethod(WordListQueryService::class, 'getFilteredWordIds');
        $source = $this->getMethodSource($method);

        $this->assertStringContainsString('preparedFetchAll', $source);
        $this->assertStringNotContainsString('Connection::query(', $source);
    }

    // =========================================================================
    // All methods - no raw query usage
    // =========================================================================

    /**
     * @return array<string, array{string}>
     */
    public static function queryMethodProvider(): array
    {
        return [
            'countWords' => ['countWords'],
            'getWordsList' => ['getWordsList'],
            'getWordsListWithWordCount' => ['getWordsListWithWordCount'],
            'getFilteredWordIds' => ['getFilteredWordIds'],
        ];
    }

    #[Test]
    #[DataProvider('queryMethodProvider')]
    public function queryMethodDoesNotUseRawQuery(string $methodName): void
    {
        $method = new ReflectionMethod(WordListQueryService::class, $methodName);
        $source = $this->getMethodSource($method);

        $this->assertStringNotContainsString(
            'Connection::query(',
            $source,
            "$methodName should not use raw Connection::query()"
        );
    }

    #[Test]
    #[DataProvider('queryMethodProvider')]
    public function queryMethodExists(string $methodName): void
    {
        $this->assertTrue(
            method_exists(WordListQueryService::class, $methodName),
            "Method $methodName should exist on WordListQueryService"
        );
    }

    // =========================================================================
    // Helper
    // =========================================================================

    private function getMethodSource(ReflectionMethod $method): string
    {
        $filename = $method->getFileName();
        $startLine = $method->getStartLine();
        $endLine = $method->getEndLine();

        $source = file_get_contents($filename);
        $lines = explode("\n", $source);
        return implode("\n", array_slice($lines, $startLine - 1, $endLine - $startLine + 1));
    }
}
