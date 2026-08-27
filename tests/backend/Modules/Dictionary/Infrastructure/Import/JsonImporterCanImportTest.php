<?php

/**
 * Unit tests for JsonImporter::canImport() — extension detection
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

use Lwt\Modules\Dictionary\Infrastructure\Import\JsonImporter;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Whether a file is JSON when the upload path has no extension, which is
 * how PHP hands over $_FILES['file']['tmp_name'].
 */
#[CoversClass(JsonImporter::class)]
class JsonImporterCanImportTest extends TestCase
{
    private string $tmpDir;
    private string $phpUploadPath;

    protected function setUp(): void
    {
        $this->tmpDir = sys_get_temp_dir() . '/lwt_json_canimport_' . uniqid();
        mkdir($this->tmpDir);
        $this->phpUploadPath = $this->tmpDir . '/phpZZ9999';
        file_put_contents($this->phpUploadPath, '[{"word":"hús","translation":"house"}]');
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
        $importer = new JsonImporter();
        $this->assertFalse($importer->canImport($this->phpUploadPath));
    }

    public function testAcceptsExtensionlessTmpUploadWhenOriginalNameIsJson(): void
    {
        $importer = new JsonImporter();
        $this->assertTrue($importer->canImport($this->phpUploadPath, 'dict.json'));
    }

    public function testRejectsWhenOriginalNameHasNonJsonExtension(): void
    {
        $importer = new JsonImporter();
        $this->assertFalse($importer->canImport($this->phpUploadPath, 'dict.csv'));
    }

    public function testRejectsJsonExtensionButNonJsonContent(): void
    {
        $bogus = $this->tmpDir . '/bogus_upload';
        file_put_contents($bogus, "not json at all\n");
        $importer = new JsonImporter();
        // Even with a .json original name, content sniff must reject non-JSON.
        $this->assertFalse($importer->canImport($bogus, 'dict.json'));
    }

    public function testAcceptsObjectRootJson(): void
    {
        $obj = $this->tmpDir . '/obj_upload';
        file_put_contents($obj, '{"hús":{"definition":"house"}}');
        $importer = new JsonImporter();
        $this->assertTrue($importer->canImport($obj, 'dict.json'));
    }

    public function testStillAcceptsRealJsonPathWithoutOriginalName(): void
    {
        $real = $this->tmpDir . '/real.json';
        copy($this->phpUploadPath, $real);
        $importer = new JsonImporter();
        $this->assertTrue($importer->canImport($real));
    }
}
