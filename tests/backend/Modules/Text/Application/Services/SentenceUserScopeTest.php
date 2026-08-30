<?php

/**
 * Source-inspection tests for `SentenceService` user-scoping (F14).
 *
 * `findSentencesFromWord` and `formatSentence` back the
 * `GET /api/v1/sentences-with-term` endpoint. Both join `sentences` and
 * `word_occurrences` raw — neither table carries a UsID column of its
 * own. Without an explicit parent-text scope, an authenticated user
 * could request sentences (or sentence content) belonging to another
 * user simply by passing that user's `language_id` / SeID.
 *
 * The fix scopes via the parent `texts.TxUsID` column: `find*` rewrites
 * its WHERE to `AND SeTxID IN (SELECT TxID FROM texts WHERE TxUsID = ?)`,
 * and `formatSentence` short-circuits when `ownsSentence()` reports the
 * SeID's parent text isn't owned by the caller. This test reads each
 * method's source via reflection and asserts those gates are present.
 *
 * PHP version 8.2
 *
 * @category Testing
 * @package  Lwt\Tests\Modules\Text\Application\Services
 * @license  Unlicense <http://unlicense.org/>
 * @since    3.1.2-fork
 */

declare(strict_types=1);

namespace Lwt\Tests\Modules\Text\Application\Services;

use Lwt\Modules\Text\Application\Services\SentenceService;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

/**
 * @since 3.1.2-fork
 */
class SentenceUserScopeTest extends TestCase
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
    public function findSentencesFromWordAppliesParentTextScope(): void
    {
        $source = $this->getMethodSource(SentenceService::class, 'findSentencesFromWord');

        $this->assertStringContainsString(
            'parentTextUserScope',
            $source,
            'findSentencesFromWord must call parentTextUserScope() so'
            . ' the sentences/word_occurrences raw SQL filters by'
            . ' TxUsID via the parent text. Otherwise an authenticated'
            . ' user can pass another user\'s language_id and read'
            . ' their sentences.'
        );
    }

    #[Test]
    public function parentTextUserScopeAddsSeTxIdSubquery(): void
    {
        $source = $this->getMethodSource(SentenceService::class, 'parentTextUserScope');

        $this->assertStringContainsString(
            'SeTxID IN (SELECT TxID FROM texts WHERE TxUsID = ?)',
            $source,
            'parentTextUserScope must scope sentences via the parent'
            . ' texts row — SeTxID has no UsID of its own.'
        );
    }

    #[Test]
    public function formatSentenceGuardsOnOwnsSentence(): void
    {
        $source = $this->getMethodSource(SentenceService::class, 'formatSentence');

        $this->assertStringContainsString(
            'ownsSentence',
            $source,
            'formatSentence must short-circuit on ownsSentence() before'
            . ' running the word_occurrences/languages content query —'
            . ' that query has no UsID filter and would otherwise return'
            . ' the foreign user\'s sentence text verbatim.'
        );
    }

    #[Test]
    public function ownsSentenceFiltersByTxUsID(): void
    {
        $source = $this->getMethodSource(SentenceService::class, 'ownsSentence');

        $this->assertStringContainsString(
            'TxUsID = ?',
            $source,
            'ownsSentence must compare the sentence\'s parent TxUsID'
            . ' against the current user.'
        );
    }
}
