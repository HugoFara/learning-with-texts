<?php

declare(strict_types=1);

namespace Lwt\Tests\Core\Word;

use Lwt\Modules\Vocabulary\Application\Services\TermStatusService;
use PHPUnit\Framework\TestCase;

/**
 * Tests for WordStatusService
 */
final class WordStatusTest extends TestCase
{
    /**
     * Test status name retrieval
     */
    public function testGetStatuses(): void
    {
        $statuses = TermStatusService::getStatuses();

        // Test structure
        $this->assertIsArray($statuses);
        $this->assertCount(7, $statuses);

        // Test learning statuses (1-5)
        for ($i = 1; $i <= 4; $i++) {
            $this->assertArrayHasKey($i, $statuses);
            $this->assertEquals((string)$i, $statuses[$i]['abbr']);
            $this->assertEquals('Learning', $statuses[$i]['name']);
        }

        // Test status 5 (Learned)
        $this->assertArrayHasKey(5, $statuses);
        $this->assertEquals('5', $statuses[5]['abbr']);
        $this->assertEquals('Learned', $statuses[5]['name']);

        // Test status 99 (Well Known) — no language-neutral abbreviation
        $this->assertArrayHasKey(99, $statuses);
        $this->assertEquals('', $statuses[99]['abbr']);
        $this->assertEquals('Well Known', $statuses[99]['name']);

        // Test status 98 (Ignored) — no language-neutral abbreviation
        $this->assertArrayHasKey(98, $statuses);
        $this->assertEquals('', $statuses[98]['abbr']);
        $this->assertEquals('Ignored', $statuses[98]['name']);
    }

    /**
     * Test getStatuses structure and values
     */
    public function testGetStatusesStructure(): void
    {
        $statuses = TermStatusService::getStatuses();

        // Each status should have 'name' and 'abbr' keys
        foreach ($statuses as $status => $data) {
            $this->assertArrayHasKey('name', $data);
            $this->assertArrayHasKey('abbr', $data);
            $this->assertIsString($data['name']);
            $this->assertIsString($data['abbr']);
        }

        // Verify color/style information if present
        foreach ([1, 2, 3, 4] as $status) {
            $this->assertEquals('Learning', $statuses[$status]['name']);
        }

        $this->assertNotEquals($statuses[98]['name'], $statuses[99]['name']);
    }
}
