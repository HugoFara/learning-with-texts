<?php

declare(strict_types=1);

namespace Lwt\Tests\Core;

use Lwt\Shared\Infrastructure\Exception\AuthException;
use Lwt\Shared\Infrastructure\Globals;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for the Globals class.
 *
 * Tests request-scoped state: the database handle, database name and
 * the authenticated user context that QueryBuilder scopes queries by.
 */
class GlobalsTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // Reset Globals state before each test
        Globals::reset();
    }

    protected function tearDown(): void
    {
        // Reset Globals state after each test
        Globals::reset();

        parent::tearDown();
    }

    // ===== dbConnection tests =====

    public function testSetAndGetDbConnection(): void
    {
        $mockConnection = $this->createMock(\mysqli::class);

        Globals::setDbConnection($mockConnection);

        $this->assertSame($mockConnection, Globals::getDbConnection());
    }

    public function testGetDbConnectionReturnsNullInitially(): void
    {
        $this->assertNull(Globals::getDbConnection());
    }

    // ===== databaseName tests =====

    public function testSetAndGetDatabaseName(): void
    {
        Globals::setDatabaseName('test_database');

        $this->assertEquals('test_database', Globals::getDatabaseName());
    }

    public function testDatabaseNameDefaultsToEmpty(): void
    {
        $this->assertEquals('', Globals::getDatabaseName());
    }

    // ===== reset() tests =====

    public function testResetClearsAllValues(): void
    {
        // Set various values
        $mockConnection = $this->createMock(\mysqli::class);
        Globals::setDbConnection($mockConnection);
        Globals::setDatabaseName('testdb');
        Globals::setCurrentUserId(42);
        Globals::setMultiUserEnabled(true);

        // Reset
        Globals::reset();

        // Verify all values are cleared
        $this->assertNull(Globals::getDbConnection());
        $this->assertEquals('', Globals::getDatabaseName());
        $this->assertNull(Globals::getCurrentUserId());
        $this->assertFalse(Globals::isMultiUserEnabled());
    }

    // ===== User context tests =====

    public function testSetAndGetCurrentUserId(): void
    {
        Globals::setCurrentUserId(42);

        $this->assertEquals(42, Globals::getCurrentUserId());
    }

    public function testCurrentUserIdDefaultsToNull(): void
    {
        $this->assertNull(Globals::getCurrentUserId());
    }

    public function testSetCurrentUserIdToNull(): void
    {
        Globals::setCurrentUserId(42);
        Globals::setCurrentUserId(null);

        $this->assertNull(Globals::getCurrentUserId());
    }

    public function testRequireUserIdReturnsIdWhenSet(): void
    {
        Globals::setCurrentUserId(42);

        $this->assertEquals(42, Globals::requireUserId());
    }

    public function testRequireUserIdThrowsWhenNotSet(): void
    {
        $this->expectException(AuthException::class);

        Globals::requireUserId();
    }

    public function testIsAuthenticatedReturnsTrueWhenUserIdSet(): void
    {
        Globals::setCurrentUserId(42);

        $this->assertTrue(Globals::isAuthenticated());
    }

    public function testIsAuthenticatedReturnsFalseWhenUserIdNull(): void
    {
        $this->assertFalse(Globals::isAuthenticated());
    }

    public function testSetAndGetMultiUserEnabled(): void
    {
        Globals::setMultiUserEnabled(true);

        $this->assertTrue(Globals::isMultiUserEnabled());
    }

    public function testMultiUserEnabledDefaultsToFalse(): void
    {
        $this->assertFalse(Globals::isMultiUserEnabled());
    }

    public function testSetMultiUserEnabledToFalse(): void
    {
        Globals::setMultiUserEnabled(true);
        Globals::setMultiUserEnabled(false);

        $this->assertFalse(Globals::isMultiUserEnabled());
    }

    public function testLanguageBelongsToCurrentUserAlwaysTrueInSingleUser(): void
    {
        // Single-user mode: there are no other users to fence against,
        // so the helper is a no-op. Importantly, this branch must not
        // hit the DB — unit tests run without one and would skip
        // otherwise.
        Globals::setMultiUserEnabled(false);

        $this->assertTrue(Globals::languageBelongsToCurrentUser(1));
        $this->assertTrue(Globals::languageBelongsToCurrentUser(999999));
    }

    public function testLanguageBelongsToCurrentUserRejectsNonPositiveIds(): void
    {
        // Multi-user gate must reject sentinels (0, negative) without
        // touching the DB — those values can never name a real
        // language and signal a malformed request.
        Globals::setMultiUserEnabled(true);

        $this->assertFalse(Globals::languageBelongsToCurrentUser(0));
        $this->assertFalse(Globals::languageBelongsToCurrentUser(-1));
    }
}
