<?php

/**
 * Guard against plural lookups whose key set does not exist.
 *
 * PHP version 8.2
 *
 * @category Tests
 * @package  Lwt\Tests\Core
 * @license  Unlicense <http://unlicense.org/>
 */

declare(strict_types=1);

namespace Lwt\Tests\Core;

use PHPUnit\Framework\TestCase;

/**
 * Every key passed to __n(), __ne(), tn() or $tn() has an English "_other" form (#319).
 *
 * A plural lookup takes the base key and appends the category, so a typo or a
 * key left in its old singular shape would print the raw key name instead of
 * failing loudly. This scans the sources for literal keys and checks them
 * against locale/en.
 */
class PluralKeysExistTest extends TestCase
{
    public function testEveryPluralKeyHasAnEnglishOtherForm(): void
    {
        $root = dirname(__DIR__, 3);
        $english = [];
        foreach (glob($root . '/locale/en/*.json') ?: [] as $file) {
            /** @var array<string, string> $strings */
            $strings = json_decode((string) file_get_contents($file), true, 512, JSON_THROW_ON_ERROR);
            foreach (array_keys($strings) as $key) {
                $english[basename($file, '.json') . '.' . $key] = true;
            }
        }

        $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root . '/src'));
        $checked = 0;
        $missing = [];
        foreach ($files as $file) {
            if (!$file instanceof \SplFileInfo || !in_array($file->getExtension(), ['php', 'ts'], true)) {
                continue;
            }
            $source = (string) file_get_contents($file->getPathname());
            preg_match_all('/(?:__ne?|\$?\btn)\(\s*[\'"]([a-z_]+\.[a-z0-9_.]+)[\'"]/', $source, $matches);
            foreach ($matches[1] as $key) {
                $checked++;
                if (!isset($english[$key . '_other'])) {
                    $missing[] = substr($file->getPathname(), strlen($root) + 1) . ': ' . $key;
                }
            }
        }

        $this->assertGreaterThan(20, $checked, 'The scan found too few plural lookups; is the pattern stale?');
        $this->assertSame([], $missing, 'Add "<key>_other" (and "<key>_one" if English needs it) to locale/en');
    }
}
