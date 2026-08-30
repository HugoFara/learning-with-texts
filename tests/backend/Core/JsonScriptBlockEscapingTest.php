<?php

/**
 * Codebase invariant: JSON embedded in a page must not be able to close its
 * own <script> block.
 *
 * PHP version 8.2
 *
 * @category Tests
 * @package  Lwt
 * @license  Unlicense <http://unlicense.org/>
 */

declare(strict_types=1);

namespace Lwt\Tests\Core;

use Lwt\Shared\UI\Helpers\ConfigIsland;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

/**
 * `json_encode` does not escape `<`, `>` or `/`, so a `</script>` inside any
 * user-controlled field closes a `<script type="application/json">` config
 * block early and everything after it is parsed as markup.
 *
 * Config blocks are therefore emitted only by ConfigIsland, which applies the
 * escaping flags centrally. These tests pin both halves of that: the emitter
 * really does neutralise a hostile payload, and no view has gone back to
 * hand-rolling its own block. Individual sites drifted more than once while the
 * flags were per call site, which is what the single emitter exists to stop.
 */
final class JsonScriptBlockEscapingTest extends TestCase
{
    /**
     * Collect every PHP file under src/.
     *
     * @return list<string> Absolute paths
     */
    private function sourceFiles(): array
    {
        $root = dirname(__DIR__, 3) . '/src';
        $files = [];

        /** @var RecursiveDirectoryIterator $dirIterator */
        $dirIterator = new RecursiveDirectoryIterator($root, RecursiveDirectoryIterator::SKIP_DOTS);
        /** @var iterable<\SplFileInfo> $iterator */
        $iterator = new RecursiveIteratorIterator($dirIterator);

        foreach ($iterator as $file) {
            if ($file->isFile() && $file->getExtension() === 'php') {
                $files[] = $file->getPathname();
            }
        }

        sort($files);
        return $files;
    }

    /**
     * Extract the arguments of every `json_encode(` call in a source string.
     *
     * Brace matching rather than a regex, because these calls routinely span
     * a dozen lines of array literal.
     *
     * @param string $source File contents
     *
     * @return list<array{offset: int, call: string}>
     */
    private function jsonEncodeCalls(string $source): array
    {
        $calls = [];
        $offset = 0;

        while (($start = strpos($source, 'json_encode', $offset)) !== false) {
            $open = strpos($source, '(', $start);
            if ($open === false) {
                break;
            }

            $depth = 1;
            $i = $open + 1;
            $length = strlen($source);
            while ($i < $length && $depth > 0) {
                if ($source[$i] === '(') {
                    $depth++;
                } elseif ($source[$i] === ')') {
                    $depth--;
                }
                $i++;
            }

            $calls[] = ['offset' => $start, 'call' => substr($source, $start, $i - $start)];
            $offset = $i;
        }

        return $calls;
    }

    /**
     * The single sanctioned emitter neutralises a payload that tries to close
     * its own script element.
     *
     * Asserted through the rendered output rather than by reading the flag
     * constant, so the guarantee survives any future change of flags.
     */
    public function testConfigIslandNeutralisesScriptClosingPayload(): void
    {
        $html = ConfigIsland::html('hostile-config', [
            'title' => '</script><script>alert(1)</script>',
            'note' => '<img src=x onerror=alert(1)>',
        ]);

        // Exactly one closing tag: the element's own terminator.
        $this->assertSame(1, substr_count($html, '</script>'));
        $this->assertStringNotContainsString('<script>alert', $html);
        $this->assertStringNotContainsString('<img', $html);

        // The payload survives intact once the browser JSON-parses it.
        $json = substr($html, (int) strpos($html, '>') + 1, -strlen('</script>'));
        /** @var array{title: string, note: string} $decoded */
        $decoded = json_decode($json, true);
        $this->assertSame('</script><script>alert(1)</script>', $decoded['title']);
    }

    /**
     * Views do not hand-roll config blocks; they go through ConfigIsland.
     */
    public function testConfigBlocksAreEmittedOnlyByConfigIsland(): void
    {
        $offenders = [];

        foreach ($this->sourceFiles() as $path) {
            if (basename($path) === 'ConfigIsland.php') {
                continue;
            }
            $source = file_get_contents($path);
            if ($source === false) {
                continue;
            }
            if (preg_match('/<script type="application\/json" id=/', $source) === 1) {
                $offenders[] = str_replace(dirname(__DIR__, 3) . '/', '', $path);
            }
        }

        $this->assertSame(
            [],
            $offenders,
            "Config blocks must be rendered with ConfigIsland::render()/html().\n"
            . "Hand-rolled in:\n  " . implode("\n  ", $offenders)
        );
    }

    /**
     * Every json_encode feeding a <script> block sets JSON_HEX_TAG.
     */
    public function testJsonInScriptBlocksEscapesAngleBrackets(): void
    {
        $offenders = [];

        foreach ($this->sourceFiles() as $path) {
            // The emitter passes its flags via a constant, and is covered
            // behaviourally by testConfigIslandNeutralisesScriptClosingPayload().
            if (basename($path) === 'ConfigIsland.php') {
                continue;
            }
            $source = file_get_contents($path);
            if ($source === false) {
                continue;
            }

            foreach ($this->jsonEncodeCalls($source) as $call) {
                if (str_contains($call['call'], 'JSON_HEX_TAG')) {
                    continue;
                }

                // Only calls whose output lands inside a <script> element
                // matter; API responses and outbound HTTP bodies do not.
                $preceding = substr($source, 0, $call['offset']);
                $scriptOpen = strripos($preceding, '<script');
                if ($scriptOpen === false) {
                    continue;
                }
                $scriptClose = strripos($preceding, '</script>');
                if ($scriptClose !== false && $scriptClose > $scriptOpen) {
                    continue;
                }

                $line = substr_count($preceding, "\n") + 1;
                $offenders[] = str_replace(dirname(__DIR__, 3) . '/', '', $path) . ':' . $line;
            }
        }

        $this->assertSame(
            [],
            $offenders,
            "json_encode into a <script> block must pass JSON_HEX_TAG | JSON_HEX_AMP.\n"
            . "Offending call sites:\n  " . implode("\n  ", $offenders)
        );
    }
}
