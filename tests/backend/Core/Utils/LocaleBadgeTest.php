<?php

declare(strict_types=1);

namespace Lwt\Tests\Core\Utils;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Tests the shields.io badge output of bin/check-locales.php.
 *
 * The badge percentages are published to the `badges` branch and rendered in
 * CONTRIBUTING.md, so a formatting slip is visible to everyone reading the
 * repository rather than only to whoever runs the script.
 */
class LocaleBadgeTest extends TestCase
{
    private string $localeRoot;
    private string $badgesDir;

    protected function setUp(): void
    {
        $base = sys_get_temp_dir() . '/lwt_locale_badge_' . uniqid();
        $this->localeRoot = $base . '/locale';
        $this->badgesDir = $base . '/badges';
        mkdir($this->localeRoot, 0755, true);
    }

    protected function tearDown(): void
    {
        $this->removeDirectory(dirname($this->localeRoot));
    }

    private function removeDirectory(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        foreach (scandir($dir) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $path = $dir . '/' . $entry;
            is_dir($path) ? $this->removeDirectory($path) : unlink($path);
        }
        rmdir($dir);
    }

    /**
     * Writes a locale namespace holding the given number of keys.
     */
    private function writeLocale(string $locale, int $keys): void
    {
        mkdir($this->localeRoot . '/' . $locale, 0755, true);
        $data = [];
        for ($i = 0; $i < $keys; $i++) {
            $data['key' . $i] = 'value' . $i;
        }
        file_put_contents(
            $this->localeRoot . '/' . $locale . '/common.json',
            json_encode($data)
        );
    }

    /**
     * Runs the checker against the fixture tree and returns the badge payloads.
     *
     * @return array<string, array<string, mixed>>
     */
    private function generateBadges(): array
    {
        // The script resolves locale/ relative to its own parent directory, so
        // the fixture tree is reached by running a copy placed alongside it.
        $sandboxBin = dirname($this->localeRoot) . '/bin';
        mkdir($sandboxBin, 0755, true);
        copy(dirname(__DIR__, 4) . '/bin/check-locales.php', $sandboxBin . '/check-locales.php');
        $command = sprintf(
            '%s -d error_reporting=0 %s --badges=%s 2>&1',
            escapeshellarg(PHP_BINARY),
            escapeshellarg($sandboxBin . '/check-locales.php'),
            escapeshellarg($this->badgesDir)
        );
        exec($command, $output, $exitCode);
        $this->assertSame(0, $exitCode, "checker failed:\n" . implode("\n", $output));

        $badges = [];
        foreach (glob($this->badgesDir . '/locale-*.json') ?: [] as $file) {
            $decoded = json_decode((string) file_get_contents($file), true);
            $this->assertIsArray($decoded, "invalid JSON in $file");
            $badges[basename($file, '.json')] = $decoded;
        }
        return $badges;
    }

    /**
     * A whole-number percentage must keep every digit.
     *
     * Trimming trailing zeros off the plain string cast turned 100.0 into "1"
     * and 90.0 into "9"; only percentages ending in a zero were affected, so
     * the bug hid behind the 99.7% that every translated locale sat at.
     *
     * @param int $keys       Keys present in the translated locale
     * @param int $totalKeys  Keys in the English reference
     * @param string $expected Badge message the pair should produce
     */
    #[DataProvider('percentageProvider')]
    public function testWholeNumberPercentagesKeepTheirDigits(
        int $keys,
        int $totalKeys,
        string $expected
    ): void {
        $this->writeLocale('en', $totalKeys);
        $this->writeLocale('xx', $keys);

        $badges = $this->generateBadges();

        $this->assertArrayHasKey('locale-xx', $badges);
        $this->assertSame($expected, $badges['locale-xx']['message']);
    }

    /**
     * @return array<string, array{int, int, string}>
     */
    public static function percentageProvider(): array
    {
        return [
            'complete'            => [100, 100, '100%'],
            'ninety percent'      => [90, 100, '90%'],
            'fifty percent'       => [50, 100, '50%'],
            'ten percent'         => [10, 100, '10%'],
            'fractional is kept'  => [997, 1000, '99.7%'],
            'nothing translated'  => [0, 100, '0%'],
        ];
    }

    /**
     * The reference locale itself is always complete.
     */
    public function testTheReferenceLocaleReportsComplete(): void
    {
        $this->writeLocale('en', 40);

        $badges = $this->generateBadges();

        $this->assertSame('100%', $badges['locale-en']['message']);
        $this->assertSame('brightgreen', $badges['locale-en']['color']);
    }

    /**
     * Badges follow the shields.io endpoint schema.
     */
    public function testBadgesUseTheShieldsEndpointSchema(): void
    {
        $this->writeLocale('en', 10);
        $this->writeLocale('fr', 5);

        $badge = $this->generateBadges()['locale-fr'];

        $this->assertSame(1, $badge['schemaVersion']);
        $this->assertSame('locale fr', $badge['label']);
        $this->assertSame('50%', $badge['message']);
        $this->assertSame('yellow', $badge['color']);
    }
}
