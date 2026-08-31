<?php

/**
 * Source-inspection tests for `getOrCreateTermTag` / `getOrCreateTextTag`
 * user-scoping (F13).
 *
 * Tag-text-to-TgID resolution must be scoped to the caller. Otherwise,
 * if another user already has a tag with the same name, an unscoped
 * `SELECT TgID FROM tags WHERE TgText = ?` returns the foreign user's
 * TgID — and any subsequent INSERT into `word_tag_map` then writes
 * (foreign-user-tag, my-words) rows that contaminate the foreign user's
 * tag-to-word membership.
 *
 * PHP version 8.2
 *
 * @category Testing
 * @package  Lwt\Tests\Modules\Tags\Application\Services
 * @license  Unlicense <http://unlicense.org/>
 * @since    3.1.2-fork
 */

declare(strict_types=1);

namespace Lwt\Tests\Modules\Tags\Application\Services;

use Lwt\Modules\Tags\Application\Services\TermTagService;
use Lwt\Modules\Tags\Application\Services\TextTagService;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

/**
 * @since 3.1.2-fork
 */
class TagCreateUserScopeTest extends TestCase
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
    public static function tagLookupProvider(): array
    {
        return [
            'TermTagService::getOrCreateTermTag' => [
                TermTagService::class, 'getOrCreateTermTag', 'tags',
            ],
            'TermTagService::removeTagFromWords' => [
                TermTagService::class, 'removeTagFromWords', 'tags',
            ],
            'TextTagService::getOrCreateTextTag' => [
                TextTagService::class, 'getOrCreateTextTag', 'text_tags',
            ],
            'TextTagService::removeTagFromTexts' => [
                TextTagService::class, 'removeTagFromTexts', 'text_tags',
            ],
            'TextTagService::removeTagFromArchivedTexts' => [
                TextTagService::class, 'removeTagFromArchivedTexts', 'text_tags',
            ],
        ];
    }

    /**
     * @param class-string $class
     */
    #[Test]
    #[DataProvider('tagLookupProvider')]
    public function tagLookupAppendsUserScope(string $class, string $method, string $table): void
    {
        $source = $this->getMethodSource($class, $method);

        $this->assertStringContainsString(
            "UserScopedQuery::forTablePrepared('{$table}'",
            $source,
            "$class::$method must scope its tag-text lookup by user, otherwise"
            . " a tag-text collision across users lets an intruder reuse the"
            . " foreign user's TgID and pollute their tag-to-row membership."
        );
    }
}
