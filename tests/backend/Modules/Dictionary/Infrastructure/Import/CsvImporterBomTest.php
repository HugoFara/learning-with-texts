<?php

/**
 * Unit tests for CsvImporter BOM / encoding handling.
 *
 * PHP version 8.2
 *
 * @category Tests
 * @package  Lwt\Tests\Modules\Dictionary\Infrastructure\Import
 * @license  Unlicense <http://unlicense.org/>
 */

declare(strict_types=1);

namespace Lwt\Tests\Modules\Dictionary\Infrastructure\Import;

use Lwt\Modules\Dictionary\Infrastructure\Import\CsvImporter;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * What the importer does with a byte-order mark: UTF-8 is stripped from the
 * first cell, UTF-16 and UTF-32 are refused with a message that says why.
 */
#[CoversClass(CsvImporter::class)]
class CsvImporterBomTest extends TestCase
{
    private string $tmpDir;

    protected function setUp(): void
    {
        $this->tmpDir = sys_get_temp_dir() . '/lwt_csv_bom_' . uniqid();
        mkdir($this->tmpDir);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->tmpDir . '/*') ?: [] as $f) {
            @unlink($f);
        }
        @rmdir($this->tmpDir);
    }

    private function writeFile(string $body): string
    {
        $path = $this->tmpDir . '/dict.csv';
        file_put_contents($path, $body);
        return $path;
    }

    public function testUtf8BomIsStrippedFromFirstCell(): void
    {
        // The BOM left in place would make the first row's term
        // come out as "\xEF\xBB\xBFword" — the mapping looks for
        // "word" and silently produces zero entries.
        $path = $this->writeFile("\xEF\xBB\xBFterm,definition\nfoo,bar\n");

        $importer = new CsvImporter();
        $entries = iterator_to_array($importer->parse($path, [
            'delimiter' => ',',
            'hasHeader' => true,
        ]));

        $this->assertCount(1, $entries);
        $this->assertSame('foo', $entries[0]['term']);
        $this->assertSame('bar', $entries[0]['definition']);
    }

    public function testUtf16LeIsRejectedWithClearError(): void
    {
        // Excel "Save As CSV" on Windows often produces UTF-16 LE.
        // fgetcsv reads NUL-padded bytes as binary garbage, so the
        // import would silently fail — better to reject up front.
        $path = $this->writeFile("\xFF\xFEt\x00e\x00r\x00m\x00");

        $importer = new CsvImporter();
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/UTF-16/');
        iterator_to_array($importer->parse($path));
    }

    public function testUtf16BeIsRejectedWithClearError(): void
    {
        $path = $this->writeFile("\xFE\xFF\x00t\x00e\x00r\x00m");

        $importer = new CsvImporter();
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/UTF-16/');
        iterator_to_array($importer->parse($path));
    }

    public function testUtf32BomIsRejected(): void
    {
        $path = $this->writeFile("\x00\x00\xFE\xFFanything");

        $importer = new CsvImporter();
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/UTF-32/');
        iterator_to_array($importer->parse($path));
    }

    public function testNonBomFileStreamsFromOffsetZero(): void
    {
        $path = $this->writeFile("term,definition\nbaz,qux\n");

        $importer = new CsvImporter();
        $entries = iterator_to_array($importer->parse($path, [
            'delimiter' => ',',
            'hasHeader' => true,
        ]));

        // skipBom() rewinds the file pointer when no BOM is found —
        // a regression here would skip the first 4 bytes of the
        // header and drop the first column entirely.
        $this->assertCount(1, $entries);
        $this->assertSame('baz', $entries[0]['term']);
    }
}
