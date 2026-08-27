<?php

declare(strict_types=1);

namespace Lwt\Tests\Modules\Vocabulary\Infrastructure;

use Lwt\Modules\Vocabulary\Infrastructure\MySqlTermRepository;
use Lwt\Modules\Vocabulary\Domain\TermRepositoryInterface;
use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\DataProvider;
use ReflectionClass;
use ReflectionMethod;

/**
 * Unit tests for MySqlTermRepository.
 *
 * Tests method signatures, trait usage, interface compliance,
 * and source-level patterns without requiring a database connection.
 */
class MySqlTermRepositoryTest extends TestCase
{
    private ReflectionClass $refClass;

    protected function setUp(): void
    {
        $this->refClass = new ReflectionClass(MySqlTermRepository::class);
    }

    // =========================================================================
    // Class structure & trait usage
    // =========================================================================

    #[Test]
    public function classImplementsTermRepositoryInterface(): void
    {
        $this->assertTrue(
            $this->refClass->implementsInterface(TermRepositoryInterface::class)
        );
    }

    #[Test]
    public function classUsesTermQueryMethodsTrait(): void
    {
        $traitNames = array_map(
            fn(\ReflectionClass $t) => $t->getShortName(),
            $this->refClass->getTraits()
        );
        $this->assertContains('TermQueryMethods', $traitNames);
    }

    #[Test]
    public function classUsesTermStatsMethodsTrait(): void
    {
        $traitNames = array_map(
            fn(\ReflectionClass $t) => $t->getShortName(),
            $this->refClass->getTraits()
        );
        $this->assertContains('TermStatsMethods', $traitNames);
    }

    #[Test]
    public function classIsNotAbstract(): void
    {
        $this->assertFalse($this->refClass->isAbstract());
    }

    // =========================================================================
    // Properties
    // =========================================================================

    #[Test]
    public function hasTableNameProperty(): void
    {
        $this->assertTrue($this->refClass->hasProperty('tableName'));
        $prop = $this->refClass->getProperty('tableName');
        $this->assertTrue($prop->isProtected());
        $this->assertTrue($prop->hasDefaultValue());
        $this->assertSame('words', $prop->getDefaultValue());
    }

    #[Test]
    public function hasPrimaryKeyProperty(): void
    {
        $this->assertTrue($this->refClass->hasProperty('primaryKey'));
        $prop = $this->refClass->getProperty('primaryKey');
        $this->assertTrue($prop->isProtected());
        $this->assertSame('WoID', $prop->getDefaultValue());
    }

    #[Test]
    public function hasColumnMapProperty(): void
    {
        $this->assertTrue($this->refClass->hasProperty('columnMap'));
        $prop = $this->refClass->getProperty('columnMap');
        $this->assertTrue($prop->isProtected());

        $default = $prop->getDefaultValue();
        $this->assertIsArray($default);
        $this->assertArrayHasKey('id', $default);
        $this->assertSame('WoID', $default['id']);
        $this->assertArrayHasKey('languageId', $default);
        $this->assertSame('WoLgID', $default['languageId']);
        $this->assertArrayHasKey('status', $default);
        $this->assertSame('WoStatus', $default['status']);
    }

    #[Test]
    public function columnMapHasAllExpectedKeys(): void
    {
        $prop = $this->refClass->getProperty('columnMap');
        $map = $prop->getDefaultValue();

        $expectedKeys = [
            'id', 'languageId', 'text', 'textLowercase', 'lemma', 'lemmaLc',
            'status', 'translation', 'sentence', 'notes', 'romanization',
            'wordCount', 'createdAt', 'statusChangedAt',
        ];

        foreach ($expectedKeys as $key) {
            $this->assertArrayHasKey($key, $map, "columnMap should contain key '$key'");
        }
        $this->assertCount(count($expectedKeys), $map);
    }

    // =========================================================================
    // Core methods — signatures (kept in base class)
    // =========================================================================

    #[Test]
    public function queryMethodIsProtected(): void
    {
        $method = $this->refClass->getMethod('query');
        $this->assertTrue($method->isProtected());
        $this->assertCount(0, $method->getParameters());
    }

    #[Test]
    public function mapToEntityIsProtected(): void
    {
        $method = $this->refClass->getMethod('mapToEntity');
        $this->assertTrue($method->isProtected());
        $this->assertCount(1, $method->getParameters());
        $this->assertSame('array', $method->getParameters()[0]->getType()?->getName());
    }

    #[Test]
    public function mapToRowIsProtected(): void
    {
        $method = $this->refClass->getMethod('mapToRow');
        $this->assertTrue($method->isProtected());
        $this->assertCount(1, $method->getParameters());
    }

    #[Test]
    public function parseDateTimeIsPrivate(): void
    {
        $method = $this->refClass->getMethod('parseDateTime');
        $this->assertTrue($method->isPrivate());
    }

    // =========================================================================
    // CRUD method signatures
    // =========================================================================

    #[Test]
    public function findAcceptsIntReturnsNullableTerm(): void
    {
        $method = new ReflectionMethod(MySqlTermRepository::class, 'find');
        $this->assertTrue($method->isPublic());
        $this->assertCount(1, $method->getParameters());
        $this->assertSame('int', $method->getParameters()[0]->getType()?->getName());
        $this->assertTrue($method->getReturnType()?->allowsNull());
    }

    #[Test]
    public function findAllReturnsArray(): void
    {
        $method = new ReflectionMethod(MySqlTermRepository::class, 'findAll');
        $this->assertTrue($method->isPublic());
        $this->assertCount(0, $method->getParameters());
        $this->assertSame('array', $method->getReturnType()?->getName());
    }

    #[Test]
    public function saveAcceptsTermReturnsInt(): void
    {
        $method = new ReflectionMethod(MySqlTermRepository::class, 'save');
        $this->assertTrue($method->isPublic());
        $this->assertCount(1, $method->getParameters());
        $this->assertSame('int', $method->getReturnType()?->getName());
    }

    #[Test]
    public function deleteAcceptsIntReturnsBool(): void
    {
        $method = new ReflectionMethod(MySqlTermRepository::class, 'delete');
        $this->assertTrue($method->isPublic());
        $this->assertCount(1, $method->getParameters());
        $this->assertSame('bool', $method->getReturnType()?->getName());
    }

    #[Test]
    public function existsAcceptsIntReturnsBool(): void
    {
        $method = new ReflectionMethod(MySqlTermRepository::class, 'exists');
        $this->assertTrue($method->isPublic());
        $this->assertCount(1, $method->getParameters());
        $this->assertSame('bool', $method->getReturnType()?->getName());
    }

    #[Test]
    public function countAcceptsOptionalArrayReturnsInt(): void
    {
        $method = new ReflectionMethod(MySqlTermRepository::class, 'count');
        $this->assertTrue($method->isPublic());
        $this->assertCount(1, $method->getParameters());
        $this->assertTrue($method->getParameters()[0]->isDefaultValueAvailable());
        $this->assertSame([], $method->getParameters()[0]->getDefaultValue());
        $this->assertSame('int', $method->getReturnType()?->getName());
    }

    #[Test]
    public function findByLanguageHasCorrectSignature(): void
    {
        $method = new ReflectionMethod(MySqlTermRepository::class, 'findByLanguage');
        $params = $method->getParameters();
        $this->assertCount(3, $params);
        $this->assertSame('languageId', $params[0]->getName());
        $this->assertSame('orderBy', $params[1]->getName());
        $this->assertSame('direction', $params[2]->getName());
        $this->assertSame('array', $method->getReturnType()?->getName());
    }

    #[Test]
    public function findByTextLcHasCorrectSignature(): void
    {
        $method = new ReflectionMethod(MySqlTermRepository::class, 'findByTextLc');
        $params = $method->getParameters();
        $this->assertCount(2, $params);
        $this->assertSame('languageId', $params[0]->getName());
        $this->assertSame('textLc', $params[1]->getName());
        $this->assertTrue($method->getReturnType()?->allowsNull());
    }

    #[Test]
    public function termExistsHasCorrectSignature(): void
    {
        $method = new ReflectionMethod(MySqlTermRepository::class, 'termExists');
        $params = $method->getParameters();
        $this->assertCount(3, $params);
        $this->assertSame('bool', $method->getReturnType()?->getName());
        $this->assertTrue($params[2]->allowsNull());
    }

    #[Test]
    public function countByLanguageHasCorrectSignature(): void
    {
        $method = new ReflectionMethod(MySqlTermRepository::class, 'countByLanguage');
        $this->assertCount(1, $method->getParameters());
        $this->assertSame('int', $method->getReturnType()?->getName());
    }

    // =========================================================================
    // TermQueryMethods trait — method signatures
    // =========================================================================

    /**
     * @return array<string, array{string, int, string}>
     */
    public static function queryMethodProvider(): array
    {
        return [
            'findByStatus'          => ['findByStatus', 2, 'array'],
            'findLearning'          => ['findLearning', 1, 'array'],
            'findKnown'             => ['findKnown', 1, 'array'],
            'findIgnored'           => ['findIgnored', 1, 'array'],
            'findMultiWord'         => ['findMultiWord', 1, 'array'],
            'findSingleWord'        => ['findSingleWord', 1, 'array'],
            'findByLemma'           => ['findByLemma', 2, 'array'],
            'findPaginated'         => ['findPaginated', 5, 'array'],
            'searchByText'          => ['searchByText', 3, 'array'],
            'searchByTranslation'   => ['searchByTranslation', 3, 'array'],
            'findRecent'            => ['findRecent', 2, 'array'],
            'findRecentlyChanged'   => ['findRecentlyChanged', 3, 'array'],
            'findWithoutTranslation' => ['findWithoutTranslation', 1, 'array'],
            'getForSelect'          => ['getForSelect', 2, 'array'],
            'getBasicInfo'          => ['getBasicInfo', 1, 'array'],
        ];
    }

    #[Test]
    #[DataProvider('queryMethodProvider')]
    public function queryMethodExistsWithCorrectSignature(
        string $methodName,
        int $paramCount,
        string $returnType
    ): void {
        $this->assertTrue(
            method_exists(MySqlTermRepository::class, $methodName),
            "Method $methodName should exist"
        );

        $method = new ReflectionMethod(MySqlTermRepository::class, $methodName);
        $this->assertTrue($method->isPublic());
        $this->assertCount($paramCount, $method->getParameters());
        $this->assertSame($returnType, $method->getReturnType()?->getName());
    }

    #[Test]
    public function findByStatusFirstParamIsInt(): void
    {
        $method = new ReflectionMethod(MySqlTermRepository::class, 'findByStatus');
        $params = $method->getParameters();
        $this->assertSame('int', $params[0]->getType()?->getName());
        $this->assertSame('status', $params[0]->getName());
        $this->assertTrue($params[1]->allowsNull());
    }

    #[Test]
    public function findPaginatedHasCorrectDefaults(): void
    {
        $method = new ReflectionMethod(MySqlTermRepository::class, 'findPaginated');
        $params = $method->getParameters();

        $this->assertSame(0, $params[0]->getDefaultValue()); // languageId
        $this->assertSame(1, $params[1]->getDefaultValue()); // page
        $this->assertSame(20, $params[2]->getDefaultValue()); // perPage
        $this->assertSame('WoText', $params[3]->getDefaultValue()); // orderBy
        $this->assertSame('ASC', $params[4]->getDefaultValue()); // direction
    }

    #[Test]
    public function searchByTextHasCorrectDefaults(): void
    {
        $method = new ReflectionMethod(MySqlTermRepository::class, 'searchByText');
        $params = $method->getParameters();

        $this->assertSame('query', $params[0]->getName());
        $this->assertFalse($params[0]->allowsNull());
        $this->assertTrue($params[1]->allowsNull()); // languageId
        $this->assertSame(50, $params[2]->getDefaultValue()); // limit
    }


    #[Test]
    public function findRecentlyChangedHasCorrectDefaults(): void
    {
        $method = new ReflectionMethod(MySqlTermRepository::class, 'findRecentlyChanged');
        $params = $method->getParameters();

        $this->assertTrue($params[0]->allowsNull()); // languageId
        $this->assertSame(7, $params[1]->getDefaultValue()); // days
        $this->assertSame(50, $params[2]->getDefaultValue()); // limit
    }

    #[Test]
    public function getForSelectHasCorrectDefaults(): void
    {
        $method = new ReflectionMethod(MySqlTermRepository::class, 'getForSelect');
        $params = $method->getParameters();

        $this->assertSame(0, $params[0]->getDefaultValue()); // languageId
        $this->assertSame(40, $params[1]->getDefaultValue()); // maxNameLength
    }

    #[Test]
    public function getBasicInfoReturnsNullableArray(): void
    {
        $method = new ReflectionMethod(MySqlTermRepository::class, 'getBasicInfo');
        $this->assertTrue($method->getReturnType()?->allowsNull());
    }

    // =========================================================================
    // TermStatsMethods trait — method signatures
    // =========================================================================

    /**
     * @return array<string, array{string, int, string}>
     */
    public static function statsMethodProvider(): array
    {
        return [
            'updateStatus'            => ['updateStatus', 2, 'bool'],
            'updateTranslation'       => ['updateTranslation', 2, 'bool'],
            'updateRomanization'      => ['updateRomanization', 2, 'bool'],
            'updateSentence'          => ['updateSentence', 2, 'bool'],
            'updateNotes'             => ['updateNotes', 2, 'bool'],
            'updateLemma'             => ['updateLemma', 2, 'bool'],
            'getLanguagesWithTerms'   => ['getLanguagesWithTerms', 0, 'array'],
            'getStatistics'           => ['getStatistics', 1, 'array'],
            'getStatusDistribution'   => ['getStatusDistribution', 1, 'array'],
            'deleteMultiple'          => ['deleteMultiple', 1, 'int'],
            'updateStatusMultiple'    => ['updateStatusMultiple', 2, 'int'],
            'getWordCountDistribution' => ['getWordCountDistribution', 1, 'array'],
        ];
    }

    #[Test]
    #[DataProvider('statsMethodProvider')]
    public function statsMethodExistsWithCorrectSignature(
        string $methodName,
        int $paramCount,
        string $returnType
    ): void {
        $this->assertTrue(
            method_exists(MySqlTermRepository::class, $methodName),
            "Method $methodName should exist"
        );

        $method = new ReflectionMethod(MySqlTermRepository::class, $methodName);
        $this->assertTrue($method->isPublic());
        $this->assertCount($paramCount, $method->getParameters());
        $this->assertSame($returnType, $method->getReturnType()?->getName());
    }

    #[Test]
    public function updateStatusParamsAreCorrect(): void
    {
        $method = new ReflectionMethod(MySqlTermRepository::class, 'updateStatus');
        $params = $method->getParameters();
        $this->assertSame('int', $params[0]->getType()?->getName());
        $this->assertSame('termId', $params[0]->getName());
        $this->assertSame('int', $params[1]->getType()?->getName());
        $this->assertSame('status', $params[1]->getName());
    }

    #[Test]
    public function updateLemmaSecondParamIsNullable(): void
    {
        $method = new ReflectionMethod(MySqlTermRepository::class, 'updateLemma');
        $params = $method->getParameters();
        $this->assertTrue($params[1]->allowsNull());
        $this->assertSame('lemma', $params[1]->getName());
    }


    #[Test]
    public function deleteMultipleAcceptsArray(): void
    {
        $method = new ReflectionMethod(MySqlTermRepository::class, 'deleteMultiple');
        $params = $method->getParameters();
        $this->assertSame('array', $params[0]->getType()?->getName());
        $this->assertSame('termIds', $params[0]->getName());
    }

    #[Test]
    public function updateStatusMultipleHasCorrectParams(): void
    {
        $method = new ReflectionMethod(MySqlTermRepository::class, 'updateStatusMultiple');
        $params = $method->getParameters();
        $this->assertSame('array', $params[0]->getType()?->getName());
        $this->assertSame('int', $params[1]->getType()?->getName());
    }

    // =========================================================================
    // Interface compliance — all interface methods are implemented
    // =========================================================================

    #[Test]
    public function allInterfaceMethodsAreImplemented(): void
    {
        $interfaceRef = new ReflectionClass(TermRepositoryInterface::class);
        $interfaceMethods = $interfaceRef->getMethods();

        foreach ($interfaceMethods as $method) {
            $this->assertTrue(
                $this->refClass->hasMethod($method->getName()),
                "Interface method {$method->getName()} must be implemented"
            );
        }
    }

    // =========================================================================
    // Trait abstract methods — traits declare query() and mapToEntity() abstract
    // =========================================================================

    #[Test]
    public function termQueryMethodsTraitDeclaresQueryAbstract(): void
    {
        $traitRef = new ReflectionClass('Lwt\Modules\Vocabulary\Infrastructure\TermQueryMethods');
        $method = $traitRef->getMethod('query');
        $this->assertTrue($method->isAbstract());
    }

    #[Test]
    public function termQueryMethodsTraitDeclaresMapToEntityAbstract(): void
    {
        $traitRef = new ReflectionClass('Lwt\Modules\Vocabulary\Infrastructure\TermQueryMethods');
        $method = $traitRef->getMethod('mapToEntity');
        $this->assertTrue($method->isAbstract());
    }

    #[Test]
    public function termStatsMethodsTraitDeclaresQueryAbstract(): void
    {
        $traitRef = new ReflectionClass('Lwt\Modules\Vocabulary\Infrastructure\TermStatsMethods');
        $method = $traitRef->getMethod('query');
        $this->assertTrue($method->isAbstract());
    }

    // =========================================================================
    // Source-level: update methods use updatePrepared
    // =========================================================================

    /**
     * @return array<string, array{string}>
     */
    public static function updateMethodProvider(): array
    {
        return [
            'updateStatus'        => ['updateStatus'],
            'updateTranslation'   => ['updateTranslation'],
            'updateRomanization'  => ['updateRomanization'],
            'updateSentence'      => ['updateSentence'],
            'updateNotes'         => ['updateNotes'],
            'updateLemma'         => ['updateLemma'],
        ];
    }

    #[Test]
    #[DataProvider('updateMethodProvider')]
    public function updateMethodUsesUpdatePrepared(string $methodName): void
    {
        $method = new ReflectionMethod(MySqlTermRepository::class, $methodName);
        $filename = $method->getFileName();
        $startLine = $method->getStartLine();
        $endLine = $method->getEndLine();

        $source = file_get_contents($filename);
        $lines = explode("\n", $source);
        $methodSource = implode("\n", array_slice($lines, $startLine - 1, $endLine - $startLine + 1));

        $this->assertStringContainsString(
            'updatePrepared',
            $methodSource,
            "$methodName should use updatePrepared"
        );
    }

    // =========================================================================
    // Source-level: bulk methods use correct patterns
    // =========================================================================

    #[Test]
    public function deleteMultipleUsesDeletePrepared(): void
    {
        $method = new ReflectionMethod(MySqlTermRepository::class, 'deleteMultiple');
        $filename = $method->getFileName();
        $startLine = $method->getStartLine();
        $endLine = $method->getEndLine();

        $source = file_get_contents($filename);
        $lines = explode("\n", $source);
        $methodSource = implode("\n", array_slice($lines, $startLine - 1, $endLine - $startLine + 1));

        $this->assertStringContainsString('deletePrepared', $methodSource);
        $this->assertStringContainsString('whereIn', $methodSource);
    }

    #[Test]
    public function deleteMultipleHandlesEmptyArray(): void
    {
        $method = new ReflectionMethod(MySqlTermRepository::class, 'deleteMultiple');
        $filename = $method->getFileName();
        $startLine = $method->getStartLine();
        $endLine = $method->getEndLine();

        $source = file_get_contents($filename);
        $lines = explode("\n", $source);
        $methodSource = implode("\n", array_slice($lines, $startLine - 1, $endLine - $startLine + 1));

        $this->assertStringContainsString('empty($termIds)', $methodSource);
        $this->assertStringContainsString('return 0', $methodSource);
    }

    #[Test]
    public function updateStatusMultipleHandlesEmptyArray(): void
    {
        $method = new ReflectionMethod(MySqlTermRepository::class, 'updateStatusMultiple');
        $filename = $method->getFileName();
        $startLine = $method->getStartLine();
        $endLine = $method->getEndLine();

        $source = file_get_contents($filename);
        $lines = explode("\n", $source);
        $methodSource = implode("\n", array_slice($lines, $startLine - 1, $endLine - $startLine + 1));

        $this->assertStringContainsString('empty($termIds)', $methodSource);
        $this->assertStringContainsString('return 0', $methodSource);
    }

    #[Test]
    public function updateStatusMultipleUsesUpdatePrepared(): void
    {
        $method = new ReflectionMethod(MySqlTermRepository::class, 'updateStatusMultiple');
        $filename = $method->getFileName();
        $startLine = $method->getStartLine();
        $endLine = $method->getEndLine();

        $source = file_get_contents($filename);
        $lines = explode("\n", $source);
        $methodSource = implode("\n", array_slice($lines, $startLine - 1, $endLine - $startLine + 1));

        $this->assertStringContainsString('updatePrepared', $methodSource);
        $this->assertStringContainsString('WoStatusChanged', $methodSource);
    }

    // =========================================================================
    // Source-level: query methods use getPrepared
    // =========================================================================

    /**
     * @return array<string, array{string}>
     */
    public static function queryBuilderMethodProvider(): array
    {
        return [
            'findByStatus'    => ['findByStatus'],
            'findLearning'    => ['findLearning'],
            'findKnown'       => ['findKnown'],
            'findMultiWord'   => ['findMultiWord'],
            'findSingleWord'  => ['findSingleWord'],
            'findByLemma'     => ['findByLemma'],
            'findPaginated'   => ['findPaginated'],
            'searchByText'    => ['searchByText'],
            'findRecent'      => ['findRecent'],
        ];
    }

    #[Test]
    #[DataProvider('queryBuilderMethodProvider')]
    public function queryMethodUsesGetPrepared(string $methodName): void
    {
        $method = new ReflectionMethod(MySqlTermRepository::class, $methodName);
        $filename = $method->getFileName();
        $startLine = $method->getStartLine();
        $endLine = $method->getEndLine();

        $source = file_get_contents($filename);
        $lines = explode("\n", $source);
        $methodSource = implode("\n", array_slice($lines, $startLine - 1, $endLine - $startLine + 1));

        $this->assertStringContainsString(
            'getPrepared',
            $methodSource,
            "$methodName should use getPrepared"
        );
    }

    // =========================================================================
    // Source-level: no raw SQL interpolation in query methods
    // =========================================================================

    #[Test]
    #[DataProvider('queryBuilderMethodProvider')]
    public function queryMethodDoesNotUseRawQuery(string $methodName): void
    {
        $method = new ReflectionMethod(MySqlTermRepository::class, $methodName);
        $filename = $method->getFileName();
        $startLine = $method->getStartLine();
        $endLine = $method->getEndLine();

        $source = file_get_contents($filename);
        $lines = explode("\n", $source);
        $methodSource = implode("\n", array_slice($lines, $startLine - 1, $endLine - $startLine + 1));

        $this->assertStringNotContainsString(
            'Connection::query(',
            $methodSource,
            "$methodName should not use raw Connection::query()"
        );
    }

    // =========================================================================
    // Source-level: statistics methods use countPrepared
    // =========================================================================

    #[Test]
    public function getStatisticsUsesCountPrepared(): void
    {
        $method = new ReflectionMethod(MySqlTermRepository::class, 'getStatistics');
        $filename = $method->getFileName();
        $startLine = $method->getStartLine();
        $endLine = $method->getEndLine();

        $source = file_get_contents($filename);
        $lines = explode("\n", $source);
        $methodSource = implode("\n", array_slice($lines, $startLine - 1, $endLine - $startLine + 1));

        $this->assertStringContainsString('countPrepared', $methodSource);
        // Should count total, learning, known, ignored, multi_word = at least 5 count calls
        $this->assertGreaterThanOrEqual(5, substr_count($methodSource, 'countPrepared'));
    }

    #[Test]
    public function getStatusDistributionUsesCountPrepared(): void
    {
        $method = new ReflectionMethod(MySqlTermRepository::class, 'getStatusDistribution');
        $filename = $method->getFileName();
        $startLine = $method->getStartLine();
        $endLine = $method->getEndLine();

        $source = file_get_contents($filename);
        $lines = explode("\n", $source);
        $methodSource = implode("\n", array_slice($lines, $startLine - 1, $endLine - $startLine + 1));

        $this->assertStringContainsString('countPrepared', $methodSource);
    }

    #[Test]
    public function getWordCountDistributionUsesPreparedFetchAll(): void
    {
        $method = new ReflectionMethod(MySqlTermRepository::class, 'getWordCountDistribution');
        $filename = $method->getFileName();
        $startLine = $method->getStartLine();
        $endLine = $method->getEndLine();

        $source = file_get_contents($filename);
        $lines = explode("\n", $source);
        $methodSource = implode("\n", array_slice($lines, $startLine - 1, $endLine - $startLine + 1));

        $this->assertStringContainsString('preparedFetchAll', $methodSource);
        $this->assertStringContainsString('GROUP BY WoWordCount', $methodSource);
    }

    // =========================================================================
    // Source-level: findWithoutTranslation uses prepared SQL
    // =========================================================================

    #[Test]
    public function findWithoutTranslationUsesPreparedFetchAll(): void
    {
        $method = new ReflectionMethod(MySqlTermRepository::class, 'findWithoutTranslation');
        $filename = $method->getFileName();
        $startLine = $method->getStartLine();
        $endLine = $method->getEndLine();

        $source = file_get_contents($filename);
        $lines = explode("\n", $source);
        $methodSource = implode("\n", array_slice($lines, $startLine - 1, $endLine - $startLine + 1));

        $this->assertStringContainsString('preparedFetchAll', $methodSource);
        $this->assertStringContainsString("WoTranslation = ''", $methodSource);
        $this->assertStringContainsString("WoTranslation = '*'", $methodSource);
    }

    // =========================================================================
    // Total method count — verify no methods were lost
    // =========================================================================

    #[Test]
    public function totalPublicMethodCountIsCorrect(): void
    {
        $publicMethods = array_filter(
            $this->refClass->getMethods(ReflectionMethod::IS_PUBLIC),
            fn(ReflectionMethod $m) => $m->getDeclaringClass()->getName() === MySqlTermRepository::class
                || in_array($m->getDeclaringClass()->getShortName(), ['TermQueryMethods', 'TermStatsMethods'])
        );

        // Count all unique public method names
        $methodNames = array_unique(array_map(fn($m) => $m->getName(), $publicMethods));

        // Original had 36 methods total. We should have at least that many public.
        $this->assertGreaterThanOrEqual(
            36,
            count($methodNames),
            'Should have at least 36 public methods (same as original)'
        );
    }
}
