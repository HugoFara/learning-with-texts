<?php

declare(strict_types=1);

namespace Lwt\Tests\Core;

use PHPUnit\Framework\TestCase;

/**
 * A query scoped with forTablePrepared() must run with the array it scoped.
 *
 * forTablePrepared() appends the user ID to the bindings it is given and adds
 * a placeholder to the SQL. Running the statement with a separate literal list
 * of the same values drops that ID, and mysqli then refuses the statement. Only
 * multi-user mode adds the placeholder, so the mistake is invisible in
 * single-user mode, and it took term status changes and term edits down in
 * production.
 */
class ScopedBindingsReachTheQueryTest extends TestCase
{
    public function testScopedQueriesRunWithTheScopedBindings(): void
    {
        $root = dirname(__DIR__, 3) . '/src';
        $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root));
        $offenders = [];

        foreach ($files as $file) {
            if (!$file instanceof \SplFileInfo || $file->getExtension() !== 'php') {
                continue;
            }
            $source = (string) file_get_contents($file->getPathname());
            $found = preg_match_all(
                '/forTablePrepared\(\s*\'[^\']+\'\s*,\s*\$\w+\s*\)\s*,\s*\[/',
                $source,
                $matches,
                PREG_OFFSET_CAPTURE
            );
            if ($found === 0 || $found === false) {
                continue;
            }
            foreach ($matches[0] as $match) {
                $line = substr_count(substr($source, 0, $match[1]), "\n") + 1;
                $offenders[] = substr($file->getPathname(), strlen($root) + 1) . ':' . $line;
            }
        }

        $this->assertSame(
            [],
            $offenders,
            'Pass the array given to forTablePrepared(), not a new literal'
        );
    }
}
