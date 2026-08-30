<?php

/**
 * Unit tests for TermTagService.
 *
 * PHP version 8.2
 *
 * @category Testing
 * @package  Lwt\Tests\Modules\Tags\Application\Services
 * @license  Unlicense <http://unlicense.org/>
 * @since    3.0.0
 */

declare(strict_types=1);

namespace Lwt\Tests\Modules\Tags\Application\Services;

use Lwt\Modules\Tags\Application\Services\TermTagService;
use Lwt\Modules\Tags\Domain\TagAssociationInterface;
use Lwt\Modules\Tags\Domain\TagRepositoryInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Tests for the TermTagService.
 *
 * Covers boundary conditions for tag HTML rendering, batch operations
 * with empty arrays, and class structure verification.
 *
 * @since 3.0.0
 */
class TermTagServiceTest extends TestCase
{
    /**
     * Inject a mock association into the static property.
     */
    private function injectMockAssociation(TagAssociationInterface $mock): void
    {
        $ref = new \ReflectionProperty(TermTagService::class, 'association');
        $ref->setValue(null, $mock);
    }

    /**
     * Inject a mock repository into the static property.
     */
    private function injectMockRepository(TagRepositoryInterface $mock): void
    {
        $ref = new \ReflectionProperty(TermTagService::class, 'repository');
        $ref->setValue(null, $mock);
    }

    protected function tearDown(): void
    {
        // Reset static state after each test
        $refAssoc = new \ReflectionProperty(TermTagService::class, 'association');
        $refAssoc->setValue(null, null);

        $refRepo = new \ReflectionProperty(TermTagService::class, 'repository');
        $refRepo->setValue(null, null);
    }

    // =========================================================================
    // getWordTagsHtml() — boundary tests
    // =========================================================================

    #[Test]
    public function getWordTagsHtmlReturnsEmptyUlForZeroWordId(): void
    {
        $result = TermTagService::getWordTagsHtml(0);
        $this->assertSame('<ul id="termtags"></ul>', $result);
    }

    #[Test]
    public function getWordTagsHtmlReturnsEmptyUlForNegativeWordId(): void
    {
        $result = TermTagService::getWordTagsHtml(-1);
        $this->assertSame('<ul id="termtags"></ul>', $result);
    }

    #[Test]
    public function getWordTagsHtmlReturnsEmptyUlForLargeNegativeWordId(): void
    {
        $result = TermTagService::getWordTagsHtml(-999);
        $this->assertSame('<ul id="termtags"></ul>', $result);
    }

    #[Test]
    public function getWordTagsHtmlWithPositiveIdCallsAssociation(): void
    {
        $mockAssoc = $this->createMock(TagAssociationInterface::class);
        $mockAssoc->expects($this->once())
            ->method('getTagTextsForItem')
            ->with(42)
            ->willReturn(['grammar', 'verb']);

        $this->injectMockAssociation($mockAssoc);

        $result = TermTagService::getWordTagsHtml(42);

        $this->assertStringContainsString('<li>grammar</li>', $result);
        $this->assertStringContainsString('<li>verb</li>', $result);
        $this->assertStringStartsWith('<ul id="termtags">', $result);
        $this->assertStringEndsWith('</ul>', $result);
    }

    #[Test]
    public function getWordTagsHtmlEscapesSpecialCharacters(): void
    {
        $mockAssoc = $this->createMock(TagAssociationInterface::class);
        $mockAssoc->expects($this->once())
            ->method('getTagTextsForItem')
            ->with(1)
            ->willReturn(['<script>alert(1)</script>', 'tag&name']);

        $this->injectMockAssociation($mockAssoc);

        $result = TermTagService::getWordTagsHtml(1);

        $this->assertStringNotContainsString('<script>', $result);
        $this->assertStringContainsString('&lt;script&gt;', $result);
        $this->assertStringContainsString('tag&amp;name', $result);
    }

    #[Test]
    public function getWordTagsHtmlWithNoTagsReturnsEmptyUlForPositiveId(): void
    {
        $mockAssoc = $this->createMock(TagAssociationInterface::class);
        $mockAssoc->expects($this->once())
            ->method('getTagTextsForItem')
            ->with(5)
            ->willReturn([]);

        $this->injectMockAssociation($mockAssoc);

        $result = TermTagService::getWordTagsHtml(5);
        $this->assertSame('<ul id="termtags"></ul>', $result);
    }

    // =========================================================================
    // getWordTagList() — boundary tests
    // =========================================================================

    #[Test]
    public function getWordTagListReturnsEmptyStringForZeroWordId(): void
    {
        $result = TermTagService::getWordTagList(0);
        $this->assertSame('', $result);
    }

    #[Test]
    public function getWordTagListReturnsEmptyStringForNegativeWordId(): void
    {
        $result = TermTagService::getWordTagList(-1);
        $this->assertSame('', $result);
    }

    #[Test]
    public function getWordTagListReturnsEmptyStringForLargeNegativeWordId(): void
    {
        $result = TermTagService::getWordTagList(-500);
        $this->assertSame('', $result);
    }

    #[Test]
    public function getWordTagListWithPositiveIdReturnsCommaSeparated(): void
    {
        $mockAssoc = $this->createMock(TagAssociationInterface::class);
        $mockAssoc->expects($this->once())
            ->method('getTagTextsForItem')
            ->with(10)
            ->willReturn(['noun', 'food', 'common']);

        $this->injectMockAssociation($mockAssoc);

        $result = TermTagService::getWordTagList(10);
        $this->assertSame('noun, food, common', $result);
    }

    #[Test]
    public function getWordTagListEscapesHtmlByDefault(): void
    {
        $mockAssoc = $this->createMock(TagAssociationInterface::class);
        $mockAssoc->expects($this->once())
            ->method('getTagTextsForItem')
            ->with(3)
            ->willReturn(['tag<b>bold</b>']);

        $this->injectMockAssociation($mockAssoc);

        $result = TermTagService::getWordTagList(3, true);
        $this->assertStringNotContainsString('<b>', $result);
        $this->assertStringContainsString('&lt;b&gt;', $result);
    }

    #[Test]
    public function getWordTagListDoesNotEscapeWhenFlagIsFalse(): void
    {
        $mockAssoc = $this->createMock(TagAssociationInterface::class);
        $mockAssoc->expects($this->once())
            ->method('getTagTextsForItem')
            ->with(7)
            ->willReturn(['tag<b>bold</b>']);

        $this->injectMockAssociation($mockAssoc);

        $result = TermTagService::getWordTagList(7, false);
        $this->assertStringContainsString('<b>bold</b>', $result);
    }

    #[Test]
    public function getWordTagListWithSingleTagReturnsNoComma(): void
    {
        $mockAssoc = $this->createMock(TagAssociationInterface::class);
        $mockAssoc->expects($this->once())
            ->method('getTagTextsForItem')
            ->with(2)
            ->willReturn(['onlytag']);

        $this->injectMockAssociation($mockAssoc);

        $result = TermTagService::getWordTagList(2);
        $this->assertSame('onlytag', $result);
        $this->assertStringNotContainsString(',', $result);
    }

    #[Test]
    public function getWordTagListWithEmptyArrayReturnsEmptyString(): void
    {
        $mockAssoc = $this->createMock(TagAssociationInterface::class);
        $mockAssoc->expects($this->once())
            ->method('getTagTextsForItem')
            ->with(8)
            ->willReturn([]);

        $this->injectMockAssociation($mockAssoc);

        $result = TermTagService::getWordTagList(8);
        $this->assertSame('', $result);
    }

    // =========================================================================
    // getWordTagsArray() — boundary tests
    // =========================================================================

    #[Test]
    public function getWordTagsArrayReturnsEmptyForZeroWordId(): void
    {
        $result = TermTagService::getWordTagsArray(0);
        $this->assertSame([], $result);
    }

    #[Test]
    public function getWordTagsArrayReturnsEmptyForNegativeWordId(): void
    {
        $result = TermTagService::getWordTagsArray(-1);
        $this->assertSame([], $result);
    }

    #[Test]
    public function getWordTagsArrayReturnsEmptyForLargeNegativeWordId(): void
    {
        $result = TermTagService::getWordTagsArray(-1000);
        $this->assertSame([], $result);
    }

    #[Test]
    public function getWordTagsArrayWithPositiveIdDelegatesToAssociation(): void
    {
        $expected = ['adjective', 'color', 'basic'];
        $mockAssoc = $this->createMock(TagAssociationInterface::class);
        $mockAssoc->expects($this->once())
            ->method('getTagTextsForItem')
            ->with(15)
            ->willReturn($expected);

        $this->injectMockAssociation($mockAssoc);

        $result = TermTagService::getWordTagsArray(15);
        $this->assertSame($expected, $result);
    }

    #[Test]
    public function getWordTagsArrayWithPositiveIdAndNoTagsReturnsEmpty(): void
    {
        $mockAssoc = $this->createMock(TagAssociationInterface::class);
        $mockAssoc->expects($this->once())
            ->method('getTagTextsForItem')
            ->with(20)
            ->willReturn([]);

        $this->injectMockAssociation($mockAssoc);

        $result = TermTagService::getWordTagsArray(20);
        $this->assertSame([], $result);
    }

    // =========================================================================
    // getWordTagListHtml() — boundary tests
    // =========================================================================

    #[Test]
    public function getWordTagListHtmlReturnsEmptyStringForZeroWordId(): void
    {
        // wordId <= 0 => getWordTagList returns '' => TagHelper::renderInline('') returns ''
        $result = TermTagService::getWordTagListHtml(0);
        $this->assertSame('', $result);
    }

    #[Test]
    public function getWordTagListHtmlReturnsEmptyStringForNegativeWordId(): void
    {
        $result = TermTagService::getWordTagListHtml(-1);
        $this->assertSame('', $result);
    }

    #[Test]
    public function getWordTagListHtmlReturnsEmptyStringForLargeNegativeWordId(): void
    {
        $result = TermTagService::getWordTagListHtml(-42);
        $this->assertSame('', $result);
    }

    #[Test]
    public function getWordTagListHtmlWithTagsReturnsBulmaSpans(): void
    {
        $mockAssoc = $this->createMock(TagAssociationInterface::class);
        $mockAssoc->expects($this->once())
            ->method('getTagTextsForItem')
            ->with(33)
            ->willReturn(['verb', 'irregular']);

        $this->injectMockAssociation($mockAssoc);

        $result = TermTagService::getWordTagListHtml(33);

        $this->assertStringContainsString('tag', $result);
        $this->assertStringContainsString('verb', $result);
        $this->assertStringContainsString('irregular', $result);
    }

    #[Test]
    public function getWordTagListHtmlUsesDefaultSizeAndColor(): void
    {
        $mockAssoc = $this->createMock(TagAssociationInterface::class);
        $mockAssoc->expects($this->once())
            ->method('getTagTextsForItem')
            ->with(44)
            ->willReturn(['test']);

        $this->injectMockAssociation($mockAssoc);

        $result = TermTagService::getWordTagListHtml(44);

        $this->assertStringContainsString('is-small', $result);
        $this->assertStringContainsString('is-info', $result);
        $this->assertStringContainsString('is-light', $result);
    }

    #[Test]
    public function getWordTagListHtmlRespectsCustomSizeAndColor(): void
    {
        $mockAssoc = $this->createMock(TagAssociationInterface::class);
        $mockAssoc->expects($this->once())
            ->method('getTagTextsForItem')
            ->with(55)
            ->willReturn(['custom']);

        $this->injectMockAssociation($mockAssoc);

        $result = TermTagService::getWordTagListHtml(55, 'is-normal', 'is-primary', false);

        $this->assertStringContainsString('is-normal', $result);
        $this->assertStringContainsString('is-primary', $result);
        $this->assertStringNotContainsString('is-light', $result);
    }

    // =========================================================================
    // addTagToWords() — empty array boundary
    // =========================================================================

    #[Test]
    public function addTagToWordsWithEmptyArrayReturnsZeroCountNoError(): void
    {
        $result = TermTagService::addTagToWords('sometag', []);
        $this->assertSame(0, $result['count']);
        $this->assertNull($result['error']);
    }

    #[Test]
    public function addTagToWordsWithEmptyArrayDoesNotCallDatabase(): void
    {
        // If ids is empty, buildIntInClause returns '()' and we short-circuit.
        // No DB calls should happen, so no mock needed.
        $result = TermTagService::addTagToWords('anytag', []);
        $this->assertIsArray($result);
        $this->assertArrayHasKey('count', $result);
        $this->assertArrayHasKey('error', $result);
    }

    #[Test]
    public function addTagToWordsEmptyArrayReturnStructure(): void
    {
        $result = TermTagService::addTagToWords('', []);
        $this->assertCount(2, $result);
        $this->assertSame(['count' => 0, 'error' => null], $result);
    }

    // =========================================================================
    // removeTagFromWords() — empty array boundary
    // =========================================================================

    #[Test]
    public function removeTagFromWordsWithEmptyArrayReturnsZeroCountNoError(): void
    {
        $result = TermTagService::removeTagFromWords('sometag', []);
        $this->assertSame(0, $result['count']);
        $this->assertNull($result['error']);
    }

    #[Test]
    public function removeTagFromWordsWithEmptyArrayDoesNotCallDatabase(): void
    {
        $result = TermTagService::removeTagFromWords('anytag', []);
        $this->assertIsArray($result);
        $this->assertArrayHasKey('count', $result);
        $this->assertArrayHasKey('error', $result);
    }

    #[Test]
    public function removeTagFromWordsEmptyArrayReturnStructure(): void
    {
        $result = TermTagService::removeTagFromWords('', []);
        $this->assertCount(2, $result);
        $this->assertSame(['count' => 0, 'error' => null], $result);
    }

    // =========================================================================
    // Boundary: wordId = 0 vs wordId = 1 divergence
    // =========================================================================

    #[Test]
    #[DataProvider('nonPositiveWordIdProvider')]
    public function allGetMethodsReturnEmptyForNonPositiveIds(int $wordId): void
    {
        // None of these should attempt DB calls
        $this->assertSame(
            '<ul id="termtags"></ul>',
            TermTagService::getWordTagsHtml($wordId),
            "getWordTagsHtml should return empty UL for wordId=$wordId"
        );
        $this->assertSame(
            '',
            TermTagService::getWordTagList($wordId),
            "getWordTagList should return '' for wordId=$wordId"
        );
        $this->assertSame(
            [],
            TermTagService::getWordTagsArray($wordId),
            "getWordTagsArray should return [] for wordId=$wordId"
        );
        $this->assertSame(
            '',
            TermTagService::getWordTagListHtml($wordId),
            "getWordTagListHtml should return '' for wordId=$wordId"
        );
    }

    /**
     * @return array<string, array{int}>
     */
    public static function nonPositiveWordIdProvider(): array
    {
        return [
            'zero' => [0],
            'negative one' => [-1],
            'negative large' => [-9999],
            'int min' => [PHP_INT_MIN],
        ];
    }

    // =========================================================================
    // Class structure tests
    // =========================================================================

    #[Test]
    public function classHasExpectedPublicStaticMethods(): void
    {
        $reflection = new \ReflectionClass(TermTagService::class);

        $expectedMethods = [
            'getRepository',
            'getAssociation',
            'saveWordTags',
            'saveWordTagsFromArray',
            'saveWordTagsFromForm',
            'getWordTagsHtml',
            'getWordTagList',
            'getWordTagsArray',
            'getWordTagListHtml',
            'addTagToWords',
            'removeTagFromWords',
            'getTermTagSelectOptions',
            'getOrCreateTermTag',
        ];

        foreach ($expectedMethods as $methodName) {
            $this->assertTrue(
                $reflection->hasMethod($methodName),
                "TermTagService should have method: $methodName"
            );
            $method = $reflection->getMethod($methodName);
            $this->assertTrue(
                $method->isPublic(),
                "Method $methodName should be public"
            );
            $this->assertTrue(
                $method->isStatic(),
                "Method $methodName should be static"
            );
        }
    }

    #[Test]
    public function classHasStaticRepositoryAndAssociationProperties(): void
    {
        $reflection = new \ReflectionClass(TermTagService::class);

        $repoProp = $reflection->getProperty('repository');
        $this->assertTrue($repoProp->isStatic());
        $this->assertTrue($repoProp->isPrivate());

        $assocProp = $reflection->getProperty('association');
        $this->assertTrue($assocProp->isStatic());
        $this->assertTrue($assocProp->isPrivate());
    }

    #[Test]
    public function getRepositoryReturnsSameInstance(): void
    {
        $mockRepo = $this->createMock(TagRepositoryInterface::class);
        $this->injectMockRepository($mockRepo);

        $first = TermTagService::getRepository();
        $second = TermTagService::getRepository();

        $this->assertSame($first, $second);
    }

    #[Test]
    public function getAssociationReturnsSameInstance(): void
    {
        $mockAssoc = $this->createMock(TagAssociationInterface::class);
        $this->injectMockAssociation($mockAssoc);

        $first = TermTagService::getAssociation();
        $second = TermTagService::getAssociation();

        $this->assertSame($first, $second);
    }

    // =========================================================================
    // saveWordTags delegates to association
    // =========================================================================

    #[Test]
    public function saveWordTagsDelegatesToAssociation(): void
    {
        $mockAssoc = $this->createMock(TagAssociationInterface::class);
        $mockAssoc->expects($this->once())
            ->method('setTagsByName')
            ->with(42, ['grammar', 'verb']);

        $this->injectMockAssociation($mockAssoc);

        TermTagService::saveWordTags(42, ['grammar', 'verb']);
    }

    #[Test]
    public function saveWordTagsWithEmptyArrayClearsTags(): void
    {
        $mockAssoc = $this->createMock(TagAssociationInterface::class);
        $mockAssoc->expects($this->once())
            ->method('setTagsByName')
            ->with(10, []);

        $this->injectMockAssociation($mockAssoc);

        TermTagService::saveWordTags(10, []);
    }

    // =========================================================================
    // Method parameter type verification
    // =========================================================================

    #[Test]
    public function addTagToWordsAcceptsStringAndIntArray(): void
    {
        $method = new \ReflectionMethod(TermTagService::class, 'addTagToWords');
        $params = $method->getParameters();

        $this->assertCount(2, $params);
        $this->assertSame('tagText', $params[0]->getName());
        $this->assertSame('string', $params[0]->getType()->getName());
        $this->assertSame('ids', $params[1]->getName());
        $this->assertSame('array', $params[1]->getType()->getName());
    }

    #[Test]
    public function removeTagFromWordsAcceptsStringAndIntArray(): void
    {
        $method = new \ReflectionMethod(TermTagService::class, 'removeTagFromWords');
        $params = $method->getParameters();

        $this->assertCount(2, $params);
        $this->assertSame('tagText', $params[0]->getName());
        $this->assertSame('string', $params[0]->getType()->getName());
        $this->assertSame('ids', $params[1]->getName());
        $this->assertSame('array', $params[1]->getType()->getName());
    }

    #[Test]
    public function addTagToWordsReturnsArrayType(): void
    {
        $method = new \ReflectionMethod(TermTagService::class, 'addTagToWords');
        $returnType = $method->getReturnType();

        $this->assertSame('array', $returnType->getName());
    }

    #[Test]
    public function removeTagFromWordsReturnsArrayType(): void
    {
        $method = new \ReflectionMethod(TermTagService::class, 'removeTagFromWords');
        $returnType = $method->getReturnType();

        $this->assertSame('array', $returnType->getName());
    }

    #[Test]
    public function getWordTagListHasEscapeHtmlDefaultTrue(): void
    {
        $method = new \ReflectionMethod(TermTagService::class, 'getWordTagList');
        $params = $method->getParameters();

        $this->assertCount(2, $params);
        $this->assertTrue($params[1]->isDefaultValueAvailable());
        $this->assertTrue($params[1]->getDefaultValue());
    }

    #[Test]
    public function getWordTagListHtmlHasCorrectDefaults(): void
    {
        $method = new \ReflectionMethod(TermTagService::class, 'getWordTagListHtml');
        $params = $method->getParameters();

        $this->assertCount(4, $params);
        $this->assertSame('is-small', $params[1]->getDefaultValue());
        $this->assertSame('is-info', $params[2]->getDefaultValue());
        $this->assertTrue($params[3]->getDefaultValue());
    }
}
