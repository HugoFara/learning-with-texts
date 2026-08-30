<?php

/**
 * Source-inspection tests for `CompleteImportService` user-scoping (F15).
 *
 * The complete import path runs three raw INSERT/UPDATE sites that
 * write to user-scoped tables. Pre-fix none of them stamped UsID, so
 * in multi-user mode imported rows landed with a NULL owner and were
 * invisible to every user (the per-user composite uniques on
 * `tags`/`text_tags` and the auto-injected `WoUsID = ?` filter on the
 * vocab list both excluded them). The overwrite-modes-3/5 UPDATE was
 * additionally broken: it built a parameterised user-scope clause but
 * called `Connection::execute` with no bindings, so the `?` was
 * unbound and the statement either failed or silently ran without
 * filter.
 *
 * This test reads each method's source via reflection and asserts
 * the scope helpers + correct execute helper are present.
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

use Lwt\Modules\Vocabulary\Application\Services\CompleteImportService;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

/**
 * @since 3.1.2-fork
 */
class ImportUserScopeTest extends TestCase
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

    #[Test]
    public function mainImportInsertStampsWoUsID(): void
    {
        $source = $this->getMethodSource(CompleteImportService::class, 'executeMainImportQuery');

        $this->assertStringContainsString(
            "UserScopedQuery::insertColumn('words')",
            $source,
            'executeMainImportQuery must add WoUsID to the INSERT column'
            . ' list. Without it imported rows land with WoUsID NULL and'
            . ' never appear in the user\'s vocab list.'
        );
        $this->assertStringContainsString(
            "UserScopedQuery::insertValue('words')",
            $source,
            'executeMainImportQuery must add the current user id to the'
            . ' SELECT projection that feeds the INSERT.'
        );
    }

    #[Test]
    public function mainImportUpdatePathBindsScopeParam(): void
    {
        $source = $this->getMethodSource(CompleteImportService::class, 'executeMainImportQuery');

        $this->assertStringContainsString(
            "UserScopedQuery::forTablePrepared('words', \$bindings, 'a')",
            $source,
            'The overwrite-modes-3/5 UPDATE must scope by WoUsID via the'
            . ' aliased forTablePrepared helper.'
        );
        $this->assertStringContainsString(
            'Connection::preparedExecute($sql, $bindings)',
            $source,
            'The UPDATE branch must call preparedExecute with $bindings.'
            . ' Pre-fix it called Connection::execute, which left the `?`'
            . ' from forTablePrepared unbound and the statement either'
            . ' failed in multi-user mode or ran unscoped in single-user'
            . ' mode.'
        );
    }

    #[Test]
    public function tagsImportStampsTgUsID(): void
    {
        $source = $this->getMethodSource(CompleteImportService::class, 'handleTagsImport');

        $this->assertStringContainsString(
            "UserScopedQuery::insertColumn('tags')",
            $source,
            'handleTagsImport must stamp TgUsID on the INSERT IGNORE INTO'
            . ' tags. Pre-fix the new tags landed with TgUsID NULL and'
            . ' never appeared on the user\'s tag page.'
        );
        $this->assertStringContainsString(
            "UserScopedQuery::insertValue('tags')",
            $source,
            'handleTagsImport must include the current user id in the'
            . ' SELECT projection that feeds the tags INSERT.'
        );
    }

    #[Test]
    public function tagsImportScopesBothWordsAndTags(): void
    {
        $source = $this->getMethodSource(CompleteImportService::class, 'handleTagsImport');

        $this->assertStringContainsString(
            "UserScopedQuery::forTablePrepared('words', \$bindings)",
            $source,
            'handleTagsImport must scope `words` in the INSERT INTO'
            . ' word_tag_map join.'
        );
        $this->assertStringContainsString(
            "UserScopedQuery::forTablePrepared('tags', \$bindings)",
            $source,
            'handleTagsImport must also scope `tags` in the same join'
            . ' so a foreign user\'s tag with the same name does not'
            . ' get attached to the caller\'s words via word_tag_map.'
        );
        $this->assertStringContainsString(
            'Connection::preparedExecute($sql, $bindings)',
            $source,
            'handleTagsImport must pass the merged $bindings array (not'
            . ' a fresh [$langId]) so the user-scope `?` placeholders'
            . ' are bound.'
        );
    }

    #[Test]
    public function tagsOnlyImportStampsTgUsID(): void
    {
        $source = $this->getMethodSource(CompleteImportService::class, 'importTagsOnly');

        $this->assertStringContainsString(
            "UserScopedQuery::insertColumn('tags')",
            $source,
            'importTagsOnly must stamp TgUsID on the INSERT IGNORE INTO'
            . ' tags. Pre-fix the new tags landed with TgUsID NULL.'
        );
        $this->assertStringContainsString(
            "UserScopedQuery::insertValue('tags')",
            $source,
            'importTagsOnly must include the current user id in the'
            . ' SELECT projection that feeds the tags INSERT.'
        );
    }
}
