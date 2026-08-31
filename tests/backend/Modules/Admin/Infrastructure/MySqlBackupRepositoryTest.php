<?php

/**
 * Unit tests for MySqlBackupRepository SQL builders.
 *
 * Exercises the private buildScopedSelectAll() and
 * officialBackupUserScope() helpers via reflection so the
 * user-scope contract is locked in without round-tripping
 * through a live MySQL connection.
 *
 * PHP version 8.2
 *
 * @category Testing
 * @package  Lwt\Tests\Modules\Admin\Infrastructure
 * @license  Unlicense <http://unlicense.org/>
 * @since    3.1.2-fork
 */

declare(strict_types=1);

namespace Lwt\Tests\Modules\Admin\Infrastructure;

use Lwt\Modules\Admin\Infrastructure\MySqlBackupRepository;
use Lwt\Shared\Infrastructure\Globals;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

/**
 * @since 3.1.2-fork
 */
class MySqlBackupRepositoryTest extends TestCase
{
    private MySqlBackupRepository $repo;

    protected function setUp(): void
    {
        $this->repo = new MySqlBackupRepository();
        // Reset multi-user state between tests; not all tests touch it.
        Globals::setMultiUserEnabled(false);
        Globals::setCurrentUserId(null);
    }

    protected function tearDown(): void
    {
        Globals::setMultiUserEnabled(false);
        Globals::setCurrentUserId(null);
    }

    private function callBuildScopedSelectAll(string $table): string
    {
        $method = new ReflectionMethod(MySqlBackupRepository::class, 'buildScopedSelectAll');
        $value = $method->invoke($this->repo, $table);
        $this->assertIsString($value);
        return $value;
    }

    /**
     * @return array<string, string>
     */
    private function callOfficialBackupUserScope(): array
    {
        $method = new ReflectionMethod(MySqlBackupRepository::class, 'officialBackupUserScope');
        $value = $method->invoke($this->repo);
        $this->assertIsArray($value);
        /** @var array<string, string> $value */
        return $value;
    }

    // =========================================================================
    // Single-user mode: no scoping, legacy behaviour
    // =========================================================================

    #[Test]
    public function singleUserModeReturnsUnfilteredSelectForEveryBackupTable(): void
    {
        Globals::setMultiUserEnabled(false);

        foreach (
            ['languages', 'texts', 'words', 'tags', 'text_tags',
                  'news_feeds', 'feed_links', 'text_tag_map', 'word_tag_map',
                  'settings'] as $table
        ) {
            $sql = $this->callBuildScopedSelectAll($table);
            $this->assertSame('SELECT * FROM ' . $table, $sql, "table=$table");
        }
    }

    #[Test]
    public function singleUserModeReturnsEmptyOfficialScopeSuffixes(): void
    {
        Globals::setMultiUserEnabled(false);
        $scope = $this->callOfficialBackupUserScope();

        foreach ($scope as $name => $suffix) {
            $this->assertSame('', $suffix, "table=$name should have no suffix");
        }
    }

    // =========================================================================
    // Multi-user mode without authenticated user: no scoping
    // =========================================================================

    #[Test]
    public function multiUserWithoutCurrentUserReturnsUnfilteredSelect(): void
    {
        Globals::setMultiUserEnabled(true);
        Globals::setCurrentUserId(null);

        $sql = $this->callBuildScopedSelectAll('words');
        $this->assertSame('SELECT * FROM words', $sql);
    }

    // =========================================================================
    // Multi-user mode: direct user-scoped tables filter by their UsID column
    // =========================================================================

    #[Test]
    public function multiUserDirectScopedTablesFilterByOwnerColumn(): void
    {
        Globals::setMultiUserEnabled(true);
        Globals::setCurrentUserId(7);

        $cases = [
            'languages' => 'LgUsID',
            'texts'     => 'TxUsID',
            'words'     => 'WoUsID',
            'tags'      => 'TgUsID',
            'text_tags' => 'T2UsID',
            'news_feeds' => 'NfUsID',
            'settings'  => 'StUsID',
        ];

        foreach ($cases as $table => $column) {
            $sql = $this->callBuildScopedSelectAll($table);
            $this->assertSame(
                'SELECT * FROM ' . $table . ' WHERE ' . $column . ' = 7',
                $sql,
                "table=$table"
            );
        }
    }

    // =========================================================================
    // Multi-user mode: link/map tables scope via the parent user-scoped table
    // =========================================================================

    #[Test]
    public function multiUserTextTagMapScopesViaParentTexts(): void
    {
        Globals::setMultiUserEnabled(true);
        Globals::setCurrentUserId(11);

        $sql = $this->callBuildScopedSelectAll('text_tag_map');
        $this->assertSame(
            'SELECT * FROM text_tag_map WHERE TtTxID IN (SELECT TxID FROM texts WHERE TxUsID = 11)',
            $sql
        );
    }

    #[Test]
    public function multiUserWordTagMapScopesViaParentWords(): void
    {
        Globals::setMultiUserEnabled(true);
        Globals::setCurrentUserId(11);

        $sql = $this->callBuildScopedSelectAll('word_tag_map');
        $this->assertSame(
            'SELECT * FROM word_tag_map WHERE WtWoID IN (SELECT WoID FROM words WHERE WoUsID = 11)',
            $sql
        );
    }

    #[Test]
    public function multiUserFeedLinksScopeViaParentNewsFeed(): void
    {
        Globals::setMultiUserEnabled(true);
        Globals::setCurrentUserId(11);

        $sql = $this->callBuildScopedSelectAll('feed_links');
        $this->assertSame(
            'SELECT * FROM feed_links WHERE FlNfID IN (SELECT NfID FROM news_feeds WHERE NfUsID = 11)',
            $sql
        );
    }

    // =========================================================================
    // Multi-user mode: official backup suffix builder
    // =========================================================================

    #[Test]
    public function multiUserOfficialScopeProducesSuffixesForEveryUserOwnedTable(): void
    {
        Globals::setMultiUserEnabled(true);
        Globals::setCurrentUserId(42);

        $scope = $this->callOfficialBackupUserScope();

        // languages already carries `WHERE LgName<>""` upstream, so the
        // user filter must extend that clause with AND, not start a new
        // WHERE.
        $this->assertSame(' AND LgUsID = 42', $scope['languages']);
        $this->assertSame(' WHERE TxUsID = 42', $scope['texts']);
        $this->assertSame(' WHERE WoUsID = 42', $scope['words']);
        $this->assertSame(' WHERE TgUsID = 42', $scope['tags']);
        $this->assertSame(' WHERE T2UsID = 42', $scope['text_tags']);
        $this->assertSame(
            ' WHERE TtTxID IN (SELECT TxID FROM texts WHERE TxUsID = 42)',
            $scope['text_tag_map']
        );
        $this->assertSame(
            ' WHERE WtWoID IN (SELECT WoID FROM words WHERE WoUsID = 42)',
            $scope['word_tag_map']
        );
    }
}
