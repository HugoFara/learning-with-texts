<?php

/**
 * Source-inspection tests for tag repository user-scoping.
 *
 * Both `MySqlTermTagRepository` (tags table) and
 * `MySqlTextTagRepository` (text_tags table) keep three raw-SQL
 * paths: `paginate`, `count($query)` and `deleteAll($query)`. They
 * all build a `WHERE (1=1) ...` filter from the search query. Each
 * path must also append `UserScopedQuery::forTablePrepared()` so
 * the resulting list, count, and bulk-delete only operate on the
 * caller's rows. This test reads each method's source via
 * `ReflectionMethod::getFileName()` + offsets and asserts the
 * scope helper is invoked.
 *
 * PHP version 8.2
 *
 * @category Testing
 * @package  Lwt\Tests\Modules\Tags\Infrastructure
 * @license  Unlicense <http://unlicense.org/>
 * @since    3.1.2-fork
 */

declare(strict_types=1);

namespace Lwt\Tests\Modules\Tags\Infrastructure;

use Lwt\Modules\Tags\Infrastructure\MySqlTermTagRepository;
use Lwt\Modules\Tags\Infrastructure\MySqlTextTagRepository;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

/**
 * @since 3.1.2-fork
 */
class TagRepositoryUserScopeTest extends TestCase
{
    private function getMethodSource(string $class, string $method): string
    {
        $reflection = new ReflectionMethod($class, $method);
        $file = $reflection->getFileName();
        $this->assertIsString($file);
        $start = $reflection->getStartLine();
        $end = $reflection->getEndLine();
        $this->assertIsInt($start);
        $this->assertIsInt($end);
        $contents = file_get_contents($file);
        $this->assertIsString($contents);
        $lines = explode("\n", $contents);
        return implode("\n", array_slice($lines, $start - 1, $end - $start + 1));
    }

    /**
     * @return array<string, array{class-string, string, string}>
     */
    public static function rawSqlPathProvider(): array
    {
        return [
            'term: paginate'       => [MySqlTermTagRepository::class, 'paginate',   'tags'],
            'term: count(query)'   => [MySqlTermTagRepository::class, 'count',      'tags'],
            'term: deleteAll(q)'   => [MySqlTermTagRepository::class, 'deleteAll',  'tags'],
            'text: paginate'       => [MySqlTextTagRepository::class, 'paginate',   'text_tags'],
            'text: count(query)'   => [MySqlTextTagRepository::class, 'count',      'text_tags'],
            'text: deleteAll(q)'   => [MySqlTextTagRepository::class, 'deleteAll',  'text_tags'],
        ];
    }

    /**
     * @param class-string $class
     */
    #[Test]
    #[\PHPUnit\Framework\Attributes\DataProvider('rawSqlPathProvider')]
    public function rawSqlPathsAppendUserScopeForCurrentTable(string $class, string $method, string $table): void
    {
        $source = $this->getMethodSource($class, $method);

        $this->assertStringContainsString(
            'UserScopedQuery::forTablePrepared(self::TABLE_NAME',
            $source,
            "$class::$method must append the current user scope before"
            . ' running the raw SQL filter, otherwise multi-user installs'
            . ' leak (or bulk-delete) rows owned by every other user.'
        );

        // Each repo's TABLE_NAME constant resolves to the matching table
        // name; the constant is private, so read it via reflection.
        $reflection = new \ReflectionClass($class);
        $this->assertSame($table, $reflection->getConstant('TABLE_NAME'));
    }
}
