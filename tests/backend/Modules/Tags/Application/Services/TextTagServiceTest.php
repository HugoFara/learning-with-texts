<?php

/**
 * Unit tests for TextTagService.
 *
 * Tests boundary conditions, empty-array branches, and HTML rendering
 * for zero/negative IDs where no database calls are needed.
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

use Lwt\Modules\Tags\Application\Services\TextTagService;
use Lwt\Modules\Tags\Domain\TagAssociationInterface;
use Lwt\Modules\Tags\Domain\TagRepositoryInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Tests for the TextTagService.
 *
 * Covers empty-array early returns for batch operations,
 * zero/negative ID branches for HTML rendering, and class structure.
 *
 * @since 3.0.0
 */
class TextTagServiceTest extends TestCase
{
    // =========================================================================
    // addTagToTexts — empty array branch
    // =========================================================================

    #[Test]
    public function addTagToTextsWithEmptyArrayReturnsZeroCountAndNoError(): void
    {
        $result = TextTagService::addTagToTexts('sometag', []);

        $this->assertSame(0, $result['count']);
        $this->assertNull($result['error']);
    }

    #[Test]
    public function addTagToTextsEmptyArrayReturnsBothKeys(): void
    {
        $result = TextTagService::addTagToTexts('anytag', []);

        $this->assertArrayHasKey('count', $result);
        $this->assertArrayHasKey('error', $result);
    }

    #[Test]
    public function addTagToTextsEmptyArrayDoesNotContactDatabase(): void
    {
        // If this reached the DB it would fail without a connection.
        // The empty array check returns early before any DB call.
        $result = TextTagService::addTagToTexts('', []);

        $this->assertSame(['count' => 0, 'error' => null], $result);
    }

    // =========================================================================
    // removeTagFromTexts — empty array branch
    // =========================================================================

    #[Test]
    public function removeTagFromTextsWithEmptyArrayReturnsZeroCountAndNoError(): void
    {
        $result = TextTagService::removeTagFromTexts('sometag', []);

        $this->assertSame(0, $result['count']);
        $this->assertNull($result['error']);
    }

    #[Test]
    public function removeTagFromTextsEmptyArrayReturnsBothKeys(): void
    {
        $result = TextTagService::removeTagFromTexts('anytag', []);

        $this->assertArrayHasKey('count', $result);
        $this->assertArrayHasKey('error', $result);
    }

    #[Test]
    public function removeTagFromTextsEmptyArrayDoesNotContactDatabase(): void
    {
        $result = TextTagService::removeTagFromTexts('', []);

        $this->assertSame(['count' => 0, 'error' => null], $result);
    }

    // =========================================================================
    // addTagToArchivedTexts — empty array branch
    // =========================================================================

    #[Test]
    public function addTagToArchivedTextsWithEmptyArrayReturnsZeroCountAndNoError(): void
    {
        $result = TextTagService::addTagToArchivedTexts('sometag', []);

        $this->assertSame(0, $result['count']);
        $this->assertNull($result['error']);
    }

    #[Test]
    public function addTagToArchivedTextsEmptyArrayReturnsBothKeys(): void
    {
        $result = TextTagService::addTagToArchivedTexts('anytag', []);

        $this->assertArrayHasKey('count', $result);
        $this->assertArrayHasKey('error', $result);
    }

    #[Test]
    public function addTagToArchivedTextsEmptyArrayDoesNotContactDatabase(): void
    {
        $result = TextTagService::addTagToArchivedTexts('', []);

        $this->assertSame(['count' => 0, 'error' => null], $result);
    }

    // =========================================================================
    // removeTagFromArchivedTexts — empty array branch
    // =========================================================================

    #[Test]
    public function removeTagFromArchivedTextsWithEmptyArrayReturnsZeroCountAndNoError(): void
    {
        $result = TextTagService::removeTagFromArchivedTexts('sometag', []);

        $this->assertSame(0, $result['count']);
        $this->assertNull($result['error']);
    }

    #[Test]
    public function removeTagFromArchivedTextsEmptyArrayReturnsBothKeys(): void
    {
        $result = TextTagService::removeTagFromArchivedTexts('anytag', []);

        $this->assertArrayHasKey('count', $result);
        $this->assertArrayHasKey('error', $result);
    }

    #[Test]
    public function removeTagFromArchivedTextsEmptyArrayDoesNotContactDatabase(): void
    {
        $result = TextTagService::removeTagFromArchivedTexts('', []);

        $this->assertSame(['count' => 0, 'error' => null], $result);
    }

    // =========================================================================
    // getTextTagsHtml — zero/negative textId branch
    // =========================================================================

    #[Test]
    public function getTextTagsHtmlWithZeroIdReturnsEmptyUl(): void
    {
        $html = TextTagService::getTextTagsHtml(0);

        $this->assertSame(
            '<ul id="texttags" class="respinput"></ul>',
            $html
        );
    }

    #[Test]
    public function getTextTagsHtmlWithNegativeIdReturnsEmptyUl(): void
    {
        $html = TextTagService::getTextTagsHtml(-1);

        $this->assertSame(
            '<ul id="texttags" class="respinput"></ul>',
            $html
        );
    }

    #[Test]
    public function getTextTagsHtmlWithNegativeIdContainsNoListItems(): void
    {
        $html = TextTagService::getTextTagsHtml(-99);

        $this->assertStringNotContainsString('<li>', $html);
    }

    #[Test]
    public function getTextTagsHtmlZeroIdDoesNotContactDatabase(): void
    {
        // If this reached the DB it would fail without a connection.
        // The textId <= 0 check skips the DB call entirely.
        $html = TextTagService::getTextTagsHtml(0);

        $this->assertStringStartsWith('<ul', $html);
        $this->assertStringEndsWith('</ul>', $html);
    }

    #[Test]
    public function getTextTagsHtmlZeroIdHasCorrectAttributes(): void
    {
        $html = TextTagService::getTextTagsHtml(0);

        $this->assertStringContainsString('id="texttags"', $html);
        $this->assertStringContainsString('class="respinput"', $html);
    }

    // =========================================================================
    // getArchivedTextTagsHtml — zero/negative textId branch
    // =========================================================================

    #[Test]
    public function getArchivedTextTagsHtmlWithZeroIdReturnsEmptyUl(): void
    {
        $html = TextTagService::getArchivedTextTagsHtml(0);

        $this->assertSame(
            '<ul id="text_tag_map" class="respinput"></ul>',
            $html
        );
    }

    #[Test]
    public function getArchivedTextTagsHtmlWithNegativeIdReturnsEmptyUl(): void
    {
        $html = TextTagService::getArchivedTextTagsHtml(-5);

        $this->assertSame(
            '<ul id="text_tag_map" class="respinput"></ul>',
            $html
        );
    }

    #[Test]
    public function getArchivedTextTagsHtmlWithNegativeIdContainsNoListItems(): void
    {
        $html = TextTagService::getArchivedTextTagsHtml(-42);

        $this->assertStringNotContainsString('<li>', $html);
    }

    #[Test]
    public function getArchivedTextTagsHtmlZeroIdDoesNotContactDatabase(): void
    {
        $html = TextTagService::getArchivedTextTagsHtml(0);

        $this->assertStringStartsWith('<ul', $html);
        $this->assertStringEndsWith('</ul>', $html);
    }

    #[Test]
    public function getArchivedTextTagsHtmlZeroIdHasCorrectAttributes(): void
    {
        $html = TextTagService::getArchivedTextTagsHtml(0);

        $this->assertStringContainsString('id="text_tag_map"', $html);
        $this->assertStringContainsString('class="respinput"', $html);
    }

    #[Test]
    public function getArchivedTextTagsHtmlDifferentIdFromTextTagsHtml(): void
    {
        $textHtml = TextTagService::getTextTagsHtml(0);
        $archivedHtml = TextTagService::getArchivedTextTagsHtml(0);

        $this->assertStringContainsString('id="texttags"', $textHtml);
        $this->assertStringContainsString('id="text_tag_map"', $archivedHtml);
        $this->assertNotSame($textHtml, $archivedHtml);
    }

    // =========================================================================
    // Batch operations — tag text variations
    // =========================================================================

    #[Test]
    public function addTagToTextsEmptyTagNameWithEmptyArrayStillReturnsEarly(): void
    {
        $result = TextTagService::addTagToTexts('', []);

        $this->assertSame(0, $result['count']);
        $this->assertNull($result['error']);
    }

    #[Test]
    public function removeTagFromTextsEmptyTagNameWithEmptyArrayStillReturnsEarly(): void
    {
        $result = TextTagService::removeTagFromTexts('', []);

        $this->assertSame(0, $result['count']);
        $this->assertNull($result['error']);
    }

    #[Test]
    public function addTagToArchivedTextsEmptyTagNameWithEmptyArrayStillReturnsEarly(): void
    {
        $result = TextTagService::addTagToArchivedTexts('', []);

        $this->assertSame(0, $result['count']);
        $this->assertNull($result['error']);
    }

    #[Test]
    public function removeTagFromArchivedTextsEmptyTagNameWithEmptyArrayStillReturnsEarly(): void
    {
        $result = TextTagService::removeTagFromArchivedTexts('', []);

        $this->assertSame(0, $result['count']);
        $this->assertNull($result['error']);
    }

    // =========================================================================
    // Batch operations — special characters in tag name (empty array branch)
    // =========================================================================

    #[Test]
    #[DataProvider('specialTagNameProvider')]
    public function addTagToTextsEmptyArrayIgnoresTagNameContent(string $tagName): void
    {
        $result = TextTagService::addTagToTexts($tagName, []);

        $this->assertSame(['count' => 0, 'error' => null], $result);
    }

    #[Test]
    #[DataProvider('specialTagNameProvider')]
    public function removeTagFromTextsEmptyArrayIgnoresTagNameContent(string $tagName): void
    {
        $result = TextTagService::removeTagFromTexts($tagName, []);

        $this->assertSame(['count' => 0, 'error' => null], $result);
    }

    #[Test]
    #[DataProvider('specialTagNameProvider')]
    public function addTagToArchivedTextsEmptyArrayIgnoresTagNameContent(string $tagName): void
    {
        $result = TextTagService::addTagToArchivedTexts($tagName, []);

        $this->assertSame(['count' => 0, 'error' => null], $result);
    }

    #[Test]
    #[DataProvider('specialTagNameProvider')]
    public function removeTagFromArchivedTextsEmptyArrayIgnoresTagNameContent(string $tagName): void
    {
        $result = TextTagService::removeTagFromArchivedTexts($tagName, []);

        $this->assertSame(['count' => 0, 'error' => null], $result);
    }

    /**
     * @return array<string, array{string}>
     */
    public static function specialTagNameProvider(): array
    {
        return [
            'empty string' => [''],
            'whitespace' => ['  '],
            'unicode' => ["\xC3\xA9\xC3\xA0\xC3\xBC"],
            'html entities' => ['<script>alert(1)</script>'],
            'sql injection attempt' => ["'; DROP TABLE texts; --"],
            'very long tag' => [str_repeat('a', 500)],
        ];
    }

    // =========================================================================
    // Class structure tests
    // =========================================================================

    #[Test]
    public function classHasExpectedPublicStaticMethods(): void
    {
        $reflection = new \ReflectionClass(TextTagService::class);

        $expectedMethods = [
            'getRepository',
            'getTextAssociation',
            'getArchivedTextAssociation',
            'saveTextTags',
            'saveArchivedTextTags',
            'saveTextTagsFromForm',
            'saveArchivedTextTagsFromForm',
            'getTextTagsHtml',
            'getArchivedTextTagsHtml',
            'addTagToTexts',
            'removeTagFromTexts',
            'addTagToArchivedTexts',
            'removeTagFromArchivedTexts',
            'getTextTagSelectOptions',
            'getTextTagSelectOptionsWithTextIds',
            'getArchivedTextTagSelectOptions',
            'getOrCreateTextTag',
        ];

        foreach ($expectedMethods as $methodName) {
            $this->assertTrue(
                $reflection->hasMethod($methodName),
                "TextTagService should have method: $methodName"
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
    public function getRepositoryReturnsTagRepositoryInterface(): void
    {
        $method = new \ReflectionMethod(TextTagService::class, 'getRepository');
        $returnType = $method->getReturnType();

        $this->assertNotNull($returnType);
        $this->assertSame(TagRepositoryInterface::class, $returnType->getName());
    }

    #[Test]
    public function getTextAssociationReturnsTagAssociationInterface(): void
    {
        $method = new \ReflectionMethod(TextTagService::class, 'getTextAssociation');
        $returnType = $method->getReturnType();

        $this->assertNotNull($returnType);
        $this->assertSame(TagAssociationInterface::class, $returnType->getName());
    }

    #[Test]
    public function getArchivedTextAssociationReturnsTagAssociationInterface(): void
    {
        $method = new \ReflectionMethod(TextTagService::class, 'getArchivedTextAssociation');
        $returnType = $method->getReturnType();

        $this->assertNotNull($returnType);
        $this->assertSame(TagAssociationInterface::class, $returnType->getName());
    }

    #[Test]
    public function addTagToTextsReturnsArrayShape(): void
    {
        $method = new \ReflectionMethod(TextTagService::class, 'addTagToTexts');
        $returnType = $method->getReturnType();

        $this->assertNotNull($returnType);
        $this->assertSame('array', $returnType->getName());
    }

    #[Test]
    public function addTagToTextsAcceptsStringAndIntArray(): void
    {
        $method = new \ReflectionMethod(TextTagService::class, 'addTagToTexts');
        $params = $method->getParameters();

        $this->assertCount(2, $params);
        $this->assertSame('tagText', $params[0]->getName());
        $this->assertSame('string', $params[0]->getType()->getName());
        $this->assertSame('ids', $params[1]->getName());
        $this->assertSame('array', $params[1]->getType()->getName());
    }

    #[Test]
    public function removeTagFromTextsAcceptsStringAndIntArray(): void
    {
        $method = new \ReflectionMethod(TextTagService::class, 'removeTagFromTexts');
        $params = $method->getParameters();

        $this->assertCount(2, $params);
        $this->assertSame('tagText', $params[0]->getName());
        $this->assertSame('ids', $params[1]->getName());
    }

    #[Test]
    public function classHasThreePrivateStaticProperties(): void
    {
        $reflection = new \ReflectionClass(TextTagService::class);
        $properties = $reflection->getProperties(\ReflectionProperty::IS_PRIVATE | \ReflectionProperty::IS_STATIC);

        $propertyNames = array_map(fn($p) => $p->getName(), $properties);

        $this->assertContains('repository', $propertyNames);
        $this->assertContains('textAssociation', $propertyNames);
        $this->assertContains('archivedTextAssociation', $propertyNames);
    }

    // =========================================================================
    // saveTextTagsFromForm — validation branch (null / missing TagList)
    // =========================================================================

    #[Test]
    public function saveTextTagsFromFormWithEmptyArrayDelegatesToAssociation(): void
    {
        // Passing an empty array should clear tags (calls setTagsByName with [])
        // This would need DB, so we just verify the method signature accepts it
        $method = new \ReflectionMethod(TextTagService::class, 'saveTextTagsFromForm');
        $params = $method->getParameters();

        $this->assertCount(2, $params);
        $this->assertSame('textId', $params[0]->getName());
        $this->assertSame('textTags', $params[1]->getName());
        $this->assertTrue($params[1]->allowsNull());
        $this->assertTrue($params[1]->isDefaultValueAvailable());
        $this->assertNull($params[1]->getDefaultValue());
    }

    #[Test]
    public function saveTextTagsFromFormSecondParamIsNullable(): void
    {
        $method = new \ReflectionMethod(TextTagService::class, 'saveTextTagsFromForm');
        $params = $method->getParameters();

        $this->assertTrue($params[1]->getType()->allowsNull());
    }

    // =========================================================================
    // getTextTagsHtml — boundary at exactly 0 vs 1
    // =========================================================================

    #[Test]
    public function getTextTagsHtmlAtExactlyZeroSkipsDbCall(): void
    {
        // textId = 0 should skip the DB path
        $html = TextTagService::getTextTagsHtml(0);
        $this->assertSame('<ul id="texttags" class="respinput"></ul>', $html);
    }

    #[Test]
    public function getArchivedTextTagsHtmlAtExactlyZeroSkipsDbCall(): void
    {
        $html = TextTagService::getArchivedTextTagsHtml(0);
        $this->assertSame('<ul id="text_tag_map" class="respinput"></ul>', $html);
    }

    #[Test]
    #[DataProvider('nonPositiveIdProvider')]
    public function getTextTagsHtmlNonPositiveIdsReturnEmptyList(int $id): void
    {
        $html = TextTagService::getTextTagsHtml($id);

        $this->assertSame('<ul id="texttags" class="respinput"></ul>', $html);
    }

    #[Test]
    #[DataProvider('nonPositiveIdProvider')]
    public function getArchivedTextTagsHtmlNonPositiveIdsReturnEmptyList(int $id): void
    {
        $html = TextTagService::getArchivedTextTagsHtml($id);

        $this->assertSame('<ul id="text_tag_map" class="respinput"></ul>', $html);
    }

    /**
     * @return array<string, array{int}>
     */
    public static function nonPositiveIdProvider(): array
    {
        return [
            'zero' => [0],
            'negative one' => [-1],
            'large negative' => [-9999],
            'int min' => [PHP_INT_MIN],
        ];
    }
}
