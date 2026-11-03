<?php

/**
 * Guard against page titles written as English literals.
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
 * Every page title goes through the translator (#314).
 *
 * renderPageStart() and renderPageStartNobody() print their title as the page
 * heading and the browser-tab title. 29 call sites once passed a literal like
 * 'Texts' or "Term: " . $text, which stayed English in every locale, and nine
 * more passed one to BaseController::render() (the admin pages, Statistics).
 */
class PageTitlesAreTranslatedTest extends TestCase
{
    /**
     * Literal titles that are deliberately not translated: product names,
     * and the empty title a few frame pages use.
     */
    private const ALLOWED = ["''", '""', "'Google Translate'"];

    public function testNoPageTitleIsAnUntranslatedLiteral(): void
    {
        $root = dirname(__DIR__, 3) . '/src';
        $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root));
        $offenders = [];

        foreach ($files as $file) {
            if (!$file instanceof \SplFileInfo || $file->getExtension() !== 'php') {
                continue;
            }
            $source = (string) file_get_contents($file->getPathname());
            preg_match_all(
                '/renderPageStart(?:Nobody)?\(\s*(\'[^\']*\'|"[^"]*")/',
                $source,
                $matches
            );
            // BaseController::render($title, $showMenu): a literal there is a
            // title too. Module renderers that take ($view, $data) are not.
            preg_match_all(
                '/->render\(\s*(\'[^\']*\'|"[^"]*")\s*,\s*(?:true|false)\s*\)/',
                $source,
                $renderMatches
            );
            foreach (array_merge($matches[1], $renderMatches[1]) as $literal) {
                if (!in_array($literal, self::ALLOWED, true)) {
                    $offenders[] = substr($file->getPathname(), strlen($root) + 1) . ': ' . $literal;
                }
            }
        }

        $this->assertSame(
            [],
            $offenders,
            "Pass __('namespace.titles.key') instead of an English literal"
        );
    }
}
