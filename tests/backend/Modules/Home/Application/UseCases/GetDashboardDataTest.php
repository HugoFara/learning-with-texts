<?php

/**
 * Unit tests for GetDashboardData use case.
 *
 * PHP version 8.2
 *
 * @category Testing
 * @package  Lwt\Tests\Modules\Home\Application\UseCases
 * @license  Unlicense <http://unlicense.org/>
 * @since    3.0.0
 */

declare(strict_types=1);

namespace Lwt\Tests\Modules\Home\Application\UseCases;

use Lwt\Modules\Home\Application\UseCases\GetDashboardData;
use Lwt\Shared\Infrastructure\Database\QueryBuilder;
use Lwt\Shared\Infrastructure\Database\Settings;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Tests for the GetDashboardData use case.
 *
 * These tests require a database connection since the use case
 * uses static QueryBuilder and Settings calls.
 *
 * @since 3.0.0
 */
class GetDashboardDataTest extends TestCase
{
    private GetDashboardData $useCase;

    protected function setUp(): void
    {
        parent::setUp();

        if (!defined('LWT_TEST_DB_AVAILABLE') || !LWT_TEST_DB_AVAILABLE) {
            $this->markTestSkipped('Database connection required');
        }

        $this->useCase = new GetDashboardData();
    }

    #[Test]
    public function executeReturnsExpectedArrayStructure(): void
    {
        $result = $this->useCase->execute();

        $this->assertArrayHasKey('language_count', $result);
        $this->assertArrayHasKey('current_language_id', $result);
        $this->assertArrayHasKey('current_language_text_count', $result);
        $this->assertArrayHasKey('current_text_id', $result);
        $this->assertArrayHasKey('current_text_info', $result);
        $this->assertArrayHasKey('is_wordpress', $result);
        $this->assertArrayHasKey('is_multi_user', $result);
    }

    #[Test]
    public function executeReturnsIntegerLanguageCount(): void
    {
        $result = $this->useCase->execute();

        $this->assertIsInt($result['language_count']);
        $this->assertGreaterThanOrEqual(0, $result['language_count']);
    }

    #[Test]
    public function executeReturnsNullOrIntForCurrentLanguageId(): void
    {
        $result = $this->useCase->execute();

        $this->assertTrue(
            is_null($result['current_language_id']) || is_int($result['current_language_id']),
            'current_language_id must be null or int'
        );
    }

    #[Test]
    public function executeReturnsNullOrIntForCurrentTextId(): void
    {
        $result = $this->useCase->execute();

        $this->assertTrue(
            is_null($result['current_text_id']) || is_int($result['current_text_id']),
            'current_text_id must be null or int'
        );
    }

    #[Test]
    public function executeReturnsZeroTextCountWhenNoLanguageSelected(): void
    {
        // When no language is set, text count should be 0
        $result = $this->useCase->execute();

        if ($result['current_language_id'] === null) {
            $this->assertSame(0, $result['current_language_text_count']);
        } else {
            $this->assertIsInt($result['current_language_text_count']);
        }
    }

    #[Test]
    public function executeReturnsNullTextInfoWhenNoCurrentText(): void
    {
        $result = $this->useCase->execute();

        if ($result['current_text_id'] === null) {
            $this->assertNull($result['current_text_info']);
        } else {
            $this->assertIsArray($result['current_text_info']);
        }
    }

    #[Test]
    public function executeReturnsTextInfoStructureWhenTextExists(): void
    {
        $result = $this->useCase->execute();

        if ($result['current_text_info'] !== null) {
            $info = $result['current_text_info'];
            $this->assertArrayHasKey('exists', $info);
            $this->assertTrue($info['exists']);
            $this->assertArrayHasKey('title', $info);
            $this->assertIsString($info['title']);
            $this->assertArrayHasKey('language_id', $info);
            $this->assertIsInt($info['language_id']);
            $this->assertArrayHasKey('language_name', $info);
            $this->assertIsString($info['language_name']);
            $this->assertArrayHasKey('annotated', $info);
            $this->assertIsBool($info['annotated']);
        } else {
            // No current text set, just confirm null
            $this->assertNull($result['current_text_info']);
        }
    }

    #[Test]
    public function executeReturnsBooleanForIsWordpress(): void
    {
        $result = $this->useCase->execute();

        $this->assertIsBool($result['is_wordpress']);
    }

    #[Test]
    public function executeReturnsBooleanForIsMultiUser(): void
    {
        $result = $this->useCase->execute();

        $this->assertIsBool($result['is_multi_user']);
    }

    #[Test]
    public function executeWordpressSessionDetection(): void
    {
        // Without WordPress session variable
        $result = $this->useCase->execute();
        $this->assertFalse($result['is_wordpress']);
    }

    #[Test]
    public function executeWordpressSessionDetectionWithSession(): void
    {
        // Set up WordPress session
        if (session_status() === PHP_SESSION_NONE) {
            @session_start();
        }
        $_SESSION['LWT-WP-User'] = 'testuser';

        $result = $this->useCase->execute();
        $this->assertTrue($result['is_wordpress']);

        // Clean up
        unset($_SESSION['LWT-WP-User']);
    }

    #[Test]
    public function executeReturnsNonNegativeTextCount(): void
    {
        $result = $this->useCase->execute();

        $this->assertGreaterThanOrEqual(0, $result['current_language_text_count']);
    }

    #[Test]
    public function executeConsistentLanguageIdBetweenFieldsAndTextInfo(): void
    {
        $result = $this->useCase->execute();

        // If text info exists, its language_id should be consistent
        if ($result['current_text_info'] !== null && isset($result['current_text_info']['language_id'])) {
            $this->assertIsInt($result['current_text_info']['language_id']);
            $this->assertGreaterThan(0, $result['current_text_info']['language_id']);
        }
    }

    #[Test]
    public function executeReturnsAllSevenKeys(): void
    {
        $result = $this->useCase->execute();

        $this->assertCount(7, $result);
    }

    #[Test]
    public function executeCanBeCalledMultipleTimes(): void
    {
        $result1 = $this->useCase->execute();
        $result2 = $this->useCase->execute();

        // Should return consistent results
        $this->assertSame($result1['language_count'], $result2['language_count']);
        $this->assertSame($result1['current_language_id'], $result2['current_language_id']);
        $this->assertSame($result1['is_multi_user'], $result2['is_multi_user']);
    }
}
