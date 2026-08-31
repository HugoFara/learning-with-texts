<?php

/**
 * Unit tests for Restore's backup-stream parsing.
 *
 * Regression cover for issue #249: splitting statements on ';' . PHP_EOL
 * matched nothing on a Windows host (PHP_EOL === "\r\n") while LWT dumps
 * always terminate with ";\n". The parser handed back an empty statement
 * list, and the caller then dropped every table and replayed nothing.
 *
 * PHP version 8.2
 *
 * @category Testing
 * @package  Lwt\Tests\Shared\Infrastructure\Database
 * @license  Unlicense <http://unlicense.org/>
 * @since    3.2.2-fork
 */

declare(strict_types=1);

namespace Tests\Backend\Shared\Infrastructure\Database;

use Lwt\Shared\Infrastructure\Database\Restore;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

/**
 * Unit tests for Restore::parseBackupStream().
 *
 * These exercise the parser in isolation, so they need no database.
 *
 * @since 3.2.2-fork
 */
#[CoversClass(Restore::class)]
class RestoreParseBackupStreamTest extends TestCase
{
    /**
     * The header line every LWT dump starts with.
     */
    private const HEADER = "-- lwt-backup-2026-07-08-11-20-52.sql.gz";

    /**
     * Call the private parser with the given dump contents.
     *
     * @param string $contents Raw dump bytes
     *
     * @return array{queries: list<string>, error: string|null}
     */
    private function parse(string $contents): array
    {
        $handle = fopen('php://memory', 'r+');
        $this->assertIsResource($handle);
        fwrite($handle, $contents);
        rewind($handle);

        // Private methods are reflection-accessible without setAccessible()
        // since PHP 8.1, and the call is deprecated as of 8.5.
        $method = new ReflectionMethod(Restore::class, 'parseBackupStream');

        /** @var array{queries: list<string>, error: string|null} $result */
        $result = $method->invoke(null, $handle, 'Database');
        return $result;
    }

    /**
     * Build a dump body with the given line terminator.
     *
     * @param string $eol Line terminator to join with
     *
     * @return string
     */
    private function dump(string $eol): string
    {
        return implode($eol, [
            self::HEADER,
            '',
            'DROP TABLE IF EXISTS words;',
            "CREATE TABLE words (WoID int, WoText varchar(250));",
            "INSERT INTO words VALUES(1,'Haus');",
            "INSERT INTO words VALUES(2,'Baum');",
            '',
        ]);
    }

    #[Test]
    public function parsesAnLfDump(): void
    {
        $result = $this->parse($this->dump("\n"));

        $this->assertNull($result['error']);
        $this->assertCount(4, $result['queries']);
        $this->assertSame('DROP TABLE IF EXISTS words', $result['queries'][0]);
        $this->assertSame("INSERT INTO words VALUES(2,'Baum')", $result['queries'][3]);
    }

    /**
     * The #249 regression: a CRLF dump, or an LF dump read on a Windows host,
     * must produce the same statements as an LF dump on Linux.
     */
    #[Test]
    public function parsesACrlfDumpIdenticallyToAnLfDump(): void
    {
        $lf = $this->parse($this->dump("\n"));
        $crlf = $this->parse($this->dump("\r\n"));

        $this->assertNull($crlf['error']);
        $this->assertSame($lf['queries'], $crlf['queries']);
        $this->assertNotEmpty($crlf['queries']);
    }

    #[Test]
    public function neverReturnsAnEmptyStatementListForAValidDump(): void
    {
        foreach (["\n", "\r\n"] as $eol) {
            $result = $this->parse($this->dump($eol));
            $this->assertNotSame(
                [],
                $result['queries'],
                'A valid dump must never parse to zero statements: the caller '
                . 'would drop every table and restore nothing.'
            );
        }
    }

    #[Test]
    public function keepsAFinalStatementThatLacksATrailingNewline(): void
    {
        $contents = self::HEADER . "\n"
            . "INSERT INTO words VALUES(1,'Haus');\n"
            . "INSERT INTO words VALUES(2,'Baum');";

        $result = $this->parse($contents);

        $this->assertNull($result['error']);
        $this->assertCount(2, $result['queries']);
        $this->assertSame("INSERT INTO words VALUES(2,'Baum')", $result['queries'][1]);
    }

    #[Test]
    public function joinsAStatementSpanningSeveralLines(): void
    {
        $contents = self::HEADER . "\n"
            . "CREATE TABLE words (\n"
            . "  WoID int,\n"
            . "  WoText varchar(250)\n"
            . ");\n";

        $result = $this->parse($contents);

        $this->assertNull($result['error']);
        $this->assertCount(1, $result['queries']);
        $this->assertStringContainsString('WoText varchar(250)', $result['queries'][0]);
    }

    #[Test]
    public function skipsCommentLines(): void
    {
        $contents = self::HEADER . "\n"
            . "-- a comment\n"
            . "--\n"
            . "INSERT INTO words VALUES(1,'Haus');\n";

        $result = $this->parse($contents);

        $this->assertNull($result['error']);
        $this->assertSame(["INSERT INTO words VALUES(1,'Haus')"], $result['queries']);
    }

    #[Test]
    public function acceptsTheExpVersionHeaderVariant(): void
    {
        $contents = "-- lwt-exp_version-backup-2026-07-08.sql.gz\n"
            . "INSERT INTO words VALUES(1,'Haus');\n";

        $result = $this->parse($contents);

        $this->assertNull($result['error']);
        $this->assertCount(1, $result['queries']);
    }

    #[Test]
    public function rejectsAFileWithoutAnLwtHeader(): void
    {
        $result = $this->parse("-- some other dump\nINSERT INTO words VALUES(1,'x');\n");

        $this->assertNotNull($result['error']);
        $this->assertStringContainsString('possibly not created by LWT backup', $result['error']);
        $this->assertSame([], $result['queries']);
    }

    #[Test]
    public function rejectsAHeaderOnlyFileWithNoStatements(): void
    {
        $result = $this->parse(self::HEADER . "\n");

        // The parser itself reports no error here; restoreFile() turns the
        // empty list into a refusal so nothing is ever dropped.
        $this->assertNull($result['error']);
        $this->assertSame([], $result['queries']);
    }
}
