<?php

/**
 * Unit tests for CsvFormulaGuard.
 *
 * PHP version 8.2
 *
 * @category Testing
 * @package  Lwt\Tests\Shared\Infrastructure\Utilities
 * @license  Unlicense <http://unlicense.org/>
 * @since    3.0.0
 */

declare(strict_types=1);

namespace Tests\Shared\Infrastructure\Utilities;

use Lwt\Shared\Infrastructure\Utilities\CsvFormulaGuard;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(CsvFormulaGuard::class)]
class CsvFormulaGuardTest extends TestCase
{
    #[Test]
    public function emptyCellPassesThroughUnchanged(): void
    {
        $this->assertSame('', CsvFormulaGuard::escapeCell(''));
    }

    #[Test]
    public function plainTextCellPassesThroughUnchanged(): void
    {
        $this->assertSame('hello', CsvFormulaGuard::escapeCell('hello'));
        $this->assertSame('Bonjour le monde', CsvFormulaGuard::escapeCell('Bonjour le monde'));
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function dangerousLeadingProvider(): array
    {
        return [
            'equals'   => ['=cmd|"/c calc"!A1', "'=cmd|\"/c calc\"!A1"],
            'plus'     => ['+1+1', "'+1+1"],
            'minus'    => ['-2+3', "'-2+3"],
            'at'       => ['@SUM(A1:A5)', "'@SUM(A1:A5)"],
            'tab'      => ["\tformula", "'\tformula"],
            'carriage' => ["\rformula", "'\rformula"],
        ];
    }

    #[Test]
    #[DataProvider('dangerousLeadingProvider')]
    public function dangerousLeadingCharGetsSingleQuotePrefix(string $input, string $expected): void
    {
        $this->assertSame($expected, CsvFormulaGuard::escapeCell($input));
    }

    #[Test]
    public function dangerousCharInsideCellIsLeftAlone(): void
    {
        // Excel only treats the formula trigger when it's the FIRST
        // character. Mid-cell `=` (e.g. "x=5") is harmless.
        $this->assertSame('x=5', CsvFormulaGuard::escapeCell('x=5'));
        $this->assertSame('foo+bar', CsvFormulaGuard::escapeCell('foo+bar'));
    }

    #[Test]
    public function leadingQuoteIsItselfSafe(): void
    {
        // Idempotency: calling escapeCell on already-escaped input
        // should not add a second quote. The single quote itself isn't
        // in the dangerous set.
        $once = CsvFormulaGuard::escapeCell('=evil');
        $twice = CsvFormulaGuard::escapeCell($once);
        $this->assertSame($once, $twice);
    }
}
