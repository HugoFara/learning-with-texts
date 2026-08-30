<?php

/**
 * Source-inspection tests for bulk-action user-scoping (F13).
 *
 * `WordListService` and `WordFamilyService` keep raw-SQL paths that
 * back the `terms/bulk-action`, `terms/family/apply`, and related
 * vocabulary mutation endpoints. Each path takes a list of WoIDs from
 * the request, so without an explicit `WHERE WoUsID = ?` clause an
 * intruder can pass another user's WoIDs and wipe / overwrite their
 * vocabulary. This test reads each method's source via reflection and
 * asserts `UserScopedQuery::forTablePrepared('words', ...)` is invoked
 * before the bulk SQL runs.
 *
 * PHP version 8.2
 *
 * @category Testing
 * @package  Lwt\Tests\Modules\Vocabulary\Application\Services
 * @license  Unlicense <http://unlicense.org/>
 * @since    3.1.2-fork
 */

declare(strict_types=1);

namespace Lwt\Tests\Modules\Vocabulary\Application\Services;

use Lwt\Modules\Vocabulary\Application\Services\WordFamilyService;
use Lwt\Modules\Vocabulary\Application\Services\WordListService;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

/**
 * @since 3.1.2-fork
 */
class BulkActionUserScopeTest extends TestCase
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
     * Methods that run a raw `UPDATE words` / `DELETE FROM words` against
     * a caller-supplied WoID list. They must scope by `WoUsID`.
     *
     * @return array<string, array{class-string, string}>
     */
    public static function rawWordSqlPathProvider(): array
    {
        return [
            'WordListService::updateStatusByIdList'     => [WordListService::class, 'updateStatusByIdList'],
            'WordListService::updateStatusDateByIdList' => [WordListService::class, 'updateStatusDateByIdList'],
            'WordListService::deleteSentencesByIdList'  => [WordListService::class, 'deleteSentencesByIdList'],
            'WordListService::toLowercaseByIdList'      => [WordListService::class, 'toLowercaseByIdList'],
            'WordListService::capitalizeByIdList'       => [WordListService::class, 'capitalizeByIdList'],
            'WordFamilyService::bulkUpdateTermStatus'   => [WordFamilyService::class, 'bulkUpdateTermStatus'],
        ];
    }

    /**
     * @param class-string $class
     */
    #[Test]
    #[DataProvider('rawWordSqlPathProvider')]
    public function rawSqlPathsAppendUserScopeForWordsTable(string $class, string $method): void
    {
        $source = $this->getMethodSource($class, $method);

        $this->assertStringContainsString(
            "UserScopedQuery::forTablePrepared('words'",
            $source,
            "$class::$method must scope by the current user before running"
            . ' bulk SQL on words; otherwise an intruder can pass foreign'
            . ' WoIDs and overwrite or delete another user\'s vocabulary.'
        );
    }

    /**
     * `deleteByIdList` deletes from both `words` and `word_occurrences`.
     * The latter has no UsID column, so this method pre-filters the IDs
     * to those owned by the current user via the `filterOwnedWordIds`
     * helper. Assert that helper is used.
     */
    #[Test]
    public function deleteByIdListPrefiltersToOwnedIds(): void
    {
        $source = $this->getMethodSource(WordListService::class, 'deleteByIdList');

        $this->assertStringContainsString(
            'filterOwnedWordIds',
            $source,
            'deleteByIdList must restrict the WoID list to the caller\'s'
            . ' rows before issuing the word_occurrences and words DELETEs.'
            . ' word_occurrences has no UsID column, so without this gate'
            . ' an intruder can wipe another user\'s multi-word occurrences.'
        );

        $helperSource = $this->getMethodSource(WordListService::class, 'filterOwnedWordIds');
        $this->assertStringContainsString(
            "UserScopedQuery::forTablePrepared('words'",
            $helperSource,
            'filterOwnedWordIds must scope its SELECT by WoUsID.'
        );
    }
}
