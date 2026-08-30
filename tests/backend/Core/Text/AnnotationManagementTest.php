<?php

declare(strict_types=1);

namespace Lwt\Tests\Core\Text;

use Lwt\Modules\Text\Application\Services\AnnotationService;
use PHPUnit\Framework\TestCase;

/**
 * Tests for annotation_management.php functions
 */
final class AnnotationManagementTest extends TestCase
{
    private AnnotationService $annotationService;

    protected function setUp(): void
    {
        $this->annotationService = new AnnotationService();
    }

    /**
     * Test annotation to JSON conversion
     *
     * @since 2.9.0 Annotation keys now match Ti2Order directly (no offset)
     */
    public function testAnnotationToJson(): void
    {
        // Empty annotation
        $this->assertEquals('{}', $this->annotationService->annotationToJson(''));

        // Single annotation - key should match the order value (1)
        $annotation = "1\tword\t5\ttranslation";
        $result = $this->annotationService->annotationToJson($annotation);
        $this->assertJson($result);
        $decoded = json_decode($result, true);
        $this->assertArrayHasKey(1, $decoded);
        $this->assertEquals(['word', '5', 'translation'], $decoded[1]);

        // Multiple annotations - keys should match order values (1 and 2)
        $annotation = "1\tword1\t5\ttrans1\n2\tword2\t3\ttrans2";
        $result = $this->annotationService->annotationToJson($annotation);
        $this->assertJson($result);
        $decoded = json_decode($result, true);
        $this->assertCount(2, $decoded);
        $this->assertEquals(['word1', '5', 'trans1'], $decoded[1]);
        $this->assertEquals(['word2', '3', 'trans2'], $decoded[2]);
    }

    /**
     * Test annotation_to_json with edge cases
     */
    public function testAnnotationToJsonEdgeCases(): void
    {
        // Annotation with special characters
        $annotation = "1\tword's\t5\t\"translation\"";
        $result = $this->annotationService->annotationToJson($annotation);
        $this->assertJson($result);

        // Annotation with tabs in translation
        $annotation = "1\tword\t5\ttranslation\twith\ttabs";
        $result = $this->annotationService->annotationToJson($annotation);
        $this->assertJson($result);

        // Malformed annotation (missing fields)
        $annotation = "1\tword";
        $result = $this->annotationService->annotationToJson($annotation);
        $this->assertJson($result);

        // Unicode in annotations - key should match order value (1)
        $annotation = "1\t日本語\t5\ttranslation";
        $result = $this->annotationService->annotationToJson($annotation);
        $this->assertJson($result);
        $decoded = json_decode($result, true);
        $this->assertStringContainsString('日本語', $decoded[1][0]);
    }
}
