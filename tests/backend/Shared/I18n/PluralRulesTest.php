<?php

declare(strict_types=1);

namespace Lwt\Tests\Shared\I18n;

use Lwt\Shared\I18n\PluralRules;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Pins the plural table against tests/fixtures/plural_rules.json, which the
 * frontend mirror (shared/i18n/plural_rules.ts) is tested against too (#319).
 */
#[CoversClass(PluralRules::class)]
class PluralRulesTest extends TestCase
{
    /**
     * @return array<string, array{string, int, string}>
     */
    public static function fixtureCases(): array
    {
        $path = dirname(__DIR__, 3) . '/fixtures/plural_rules.json';
        /** @var array{cases: array<string, array<string, string>>} $fixture */
        $fixture = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
        $cases = [];
        foreach ($fixture['cases'] as $locale => $expectations) {
            foreach ($expectations as $count => $category) {
                $cases["$locale $count"] = [$locale, (int) $count, $category];
            }
        }
        return $cases;
    }

    #[DataProvider('fixtureCases')]
    public function testCategoryMatchesTheFixture(string $locale, int $count, string $expected): void
    {
        $this->assertSame($expected, PluralRules::category($locale, $count));
    }

    public function testNegativeCountsUseTheirMagnitude(): void
    {
        $this->assertSame('one', PluralRules::category('en', -1));
        $this->assertSame('two', PluralRules::category('ar', -2));
    }
}
