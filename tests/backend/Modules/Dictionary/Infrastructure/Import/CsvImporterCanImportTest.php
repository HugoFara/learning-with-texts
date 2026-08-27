<?php

/**
 * Unit tests for CsvImporter::canImport() — extension detection
 * via the original filename (regression test for GH discussion #233).
 *
 * PHP version 8.1
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

/**
 * Whether a file is a CSV when the upload path has no extension, which is
 * how PHP hands over $_FILES['file']['tmp_name'].
 */
#[CoversClass(CsvImporter::class)]
class CsvImporterCanImportTest extends TestCase
{
    private string $tmpDir;
    private string $phpUploadPath;

    protected function setUp(): void
    {
        $this->tmpDir = sys_get_temp_dir() . '/lwt_csv_canimport_' . uniqid();
        mkdir($this->tmpDir);
        // Mimic PHP's $_FILES['file']['tmp_name']: a path with no extension.
        $this->phpUploadPath = $this->tmpDir . '/phpAB1234';
        file_put_contents($this->phpUploadPath, "word,translation\nhús,house\n");
    }

    protected function tearDown(): void
    {
        foreach (glob($this->tmpDir . '/*') ?: [] as $f) {
            @unlink($f);
        }
        @rmdir($this->tmpDir);
    }

    public function testRejectsExtensionlessTmpUploadWithoutOriginalName(): void
    {
        $importer = new CsvImporter();
        $this->assertFalse($importer->canImport($this->phpUploadPath));
    }

    public function testAcceptsExtensionlessTmpUploadWhenOriginalNameIsCsv(): void
    {
        $importer = new CsvImporter();
        $this->assertTrue($importer->canImport($this->phpUploadPath, 'icelandic.csv'));
    }

    public function testAcceptsExtensionlessTmpUploadWhenOriginalNameIsTsv(): void
    {
        $importer = new CsvImporter();
        $this->assertTrue($importer->canImport($this->phpUploadPath, 'icelandic.tsv'));
    }

    public function testAcceptsExtensionlessTmpUploadWhenOriginalNameIsTxt(): void
    {
        $importer = new CsvImporter();
        $this->assertTrue($importer->canImport($this->phpUploadPath, 'icelandic.txt'));
    }

    public function testRejectsWhenOriginalNameHasUnsupportedExtension(): void
    {
        $importer = new CsvImporter();
        $this->assertFalse($importer->canImport($this->phpUploadPath, 'icelandic.bin'));
    }

    public function testStillAcceptsRealCsvPathWithoutOriginalName(): void
    {
        $real = $this->tmpDir . '/real.csv';
        copy($this->phpUploadPath, $real);
        $importer = new CsvImporter();
        $this->assertTrue($importer->canImport($real));
    }

    public function testRejectsMissingFileEvenWithCorrectOriginalName(): void
    {
        $importer = new CsvImporter();
        $this->assertFalse($importer->canImport($this->tmpDir . '/does_not_exist', 'foo.csv'));
    }

    public function testEmptyOriginalNameFallsBackToTmpPath(): void
    {
        $importer = new CsvImporter();
        // Empty string should be treated like null and fall back to $filePath, which has no ext.
        $this->assertFalse($importer->canImport($this->phpUploadPath, ''));
    }
}
