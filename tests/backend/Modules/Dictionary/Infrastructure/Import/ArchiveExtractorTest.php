<?php

/**
 * Unit tests for ArchiveExtractor.
 *
 * PHP version 8.1
 *
 * @category Tests
 * @package  Lwt\Tests\Modules\Dictionary\Infrastructure\Import
 * @license  Unlicense <http://unlicense.org/>
 */

declare(strict_types=1);

namespace Lwt\Tests\Modules\Dictionary\Infrastructure\Import;

use Lwt\Modules\Dictionary\Infrastructure\Import\ArchiveExtractor;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use ZipArchive;

/**
 * Archive detection, extraction and cleanup, including the path-traversal
 * guard and the nested-file search extraction relies on.
 */
#[CoversClass(ArchiveExtractor::class)]
class ArchiveExtractorTest extends TestCase
{
    private string $sandbox;
    private ArchiveExtractor $extractor;

    protected function setUp(): void
    {
        $this->sandbox = sys_get_temp_dir() . '/lwt_archive_test_' . uniqid();
        mkdir($this->sandbox);
        $this->extractor = new ArchiveExtractor();
    }

    protected function tearDown(): void
    {
        $this->rmrf($this->sandbox);
    }

    public function testIsArchiveRecognisesSupportedExtensions(): void
    {
        $this->assertTrue(ArchiveExtractor::isArchive('icelandic.zip'));
        $this->assertTrue(ArchiveExtractor::isArchive('foo.tar.gz'));
        $this->assertTrue(ArchiveExtractor::isArchive('foo.tar.bz2'));
        $this->assertTrue(ArchiveExtractor::isArchive('foo.tar.xz'));
        $this->assertTrue(ArchiveExtractor::isArchive('foo.tgz'));
        $this->assertTrue(ArchiveExtractor::isArchive('FOO.TAR.GZ'));
    }

    public function testIsArchiveRejectsNonArchives(): void
    {
        $this->assertFalse(ArchiveExtractor::isArchive('icelandic.ifo'));
        $this->assertFalse(ArchiveExtractor::isArchive('dict.csv'));
        $this->assertFalse(ArchiveExtractor::isArchive('dict.json'));
        $this->assertFalse(ArchiveExtractor::isArchive(''));
    }

    public function testExtractZipPlacesAllEntriesInTempDir(): void
    {
        $zipPath = $this->buildZip([
            'mydict.ifo' => "StarDict's dict ifo file\n",
            'mydict.idx' => "binary-idx",
            'mydict.dict' => "binary-dict",
        ]);

        $dir = $this->extractor->extract($zipPath, 'mydict.zip');
        try {
            $this->assertDirectoryExists($dir);
            $this->assertFileExists($dir . '/mydict.ifo');
            $this->assertFileExists($dir . '/mydict.idx');
            $this->assertFileExists($dir . '/mydict.dict');
        } finally {
            $this->extractor->cleanup($dir);
        }
    }

    public function testFindByExtensionsLocatesNestedFile(): void
    {
        $zipPath = $this->buildZip([
            'sub/mydict.ifo' => "StarDict's dict ifo file\n",
            'sub/mydict.idx' => "x",
            'sub/mydict.dict' => "y",
        ]);

        $dir = $this->extractor->extract($zipPath, 'mydict.zip');
        try {
            $found = $this->extractor->findByExtensions($dir, ['ifo']);
            $this->assertNotNull($found);
            $this->assertStringEndsWith('mydict.ifo', $found);
        } finally {
            $this->extractor->cleanup($dir);
        }
    }

    public function testFindByExtensionsReturnsNullWhenNoMatch(): void
    {
        $zipPath = $this->buildZip(['readme.txt' => 'hello']);
        $dir = $this->extractor->extract($zipPath, 'thing.zip');
        try {
            $this->assertNull($this->extractor->findByExtensions($dir, ['ifo']));
        } finally {
            $this->extractor->cleanup($dir);
        }
    }

    public function testExtractRejectsZipWithPathTraversal(): void
    {
        if (!class_exists(ZipArchive::class)) {
            $this->markTestSkipped('ZipArchive extension not available');
        }
        $zipPath = $this->sandbox . '/evil.zip';
        $zip = new ZipArchive();
        $this->assertTrue($zip->open($zipPath, ZipArchive::CREATE) === true);
        $zip->addFromString('../escaped.txt', 'no good');
        $zip->close();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/unsafe path/i');
        $this->extractor->extract($zipPath, 'evil.zip');
    }

    public function testExtractTarGz(): void
    {
        $payloadDir = $this->sandbox . '/payload';
        mkdir($payloadDir);
        file_put_contents($payloadDir . '/mydict.ifo', "StarDict's dict ifo file\n");
        file_put_contents($payloadDir . '/mydict.idx', 'x');
        file_put_contents($payloadDir . '/mydict.dict', 'y');

        $tarPath = $this->sandbox . '/mydict.tar.gz';
        $cmd = sprintf(
            'tar czf %s -C %s mydict.ifo mydict.idx mydict.dict',
            escapeshellarg($tarPath),
            escapeshellarg($payloadDir)
        );
        exec($cmd, $out, $rc);
        if ($rc !== 0) {
            $this->markTestSkipped('system tar not available');
        }

        $dir = $this->extractor->extract($tarPath, 'mydict.tar.gz');
        try {
            $this->assertFileExists($dir . '/mydict.ifo');
            $this->assertFileExists($dir . '/mydict.idx');
            $this->assertFileExists($dir . '/mydict.dict');
        } finally {
            $this->extractor->cleanup($dir);
        }
    }

    public function testCleanupRemovesDirectoriesAndFiles(): void
    {
        $dir = $this->sandbox . '/scratch';
        mkdir($dir . '/nested', 0700, true);
        file_put_contents($dir . '/a.txt', '1');
        file_put_contents($dir . '/nested/b.txt', '2');

        $loose = $this->sandbox . '/loose.txt';
        file_put_contents($loose, '3');

        $this->extractor->cleanup($dir, $loose);

        $this->assertDirectoryDoesNotExist($dir);
        $this->assertFileDoesNotExist($loose);
    }

    public function testExtractThrowsForUnknownArchiveType(): void
    {
        $path = $this->sandbox . '/x.bin';
        file_put_contents($path, 'not an archive');

        $this->expectException(RuntimeException::class);
        $this->extractor->extract($path, 'x.bin');
    }

    /**
     * @param array<string,string> $entries map of relative path => contents
     */
    private function buildZip(array $entries): string
    {
        if (!class_exists(ZipArchive::class)) {
            $this->markTestSkipped('ZipArchive extension not available');
        }
        $zipPath = $this->sandbox . '/' . uniqid('zip_') . '.zip';
        $zip = new ZipArchive();
        $this->assertTrue($zip->open($zipPath, ZipArchive::CREATE) === true);
        foreach ($entries as $name => $contents) {
            $zip->addFromString($name, $contents);
        }
        $zip->close();
        return $zipPath;
    }

    private function rmrf(string $path): void
    {
        if (!file_exists($path)) {
            return;
        }
        if (is_file($path) || is_link($path)) {
            @unlink($path);
            return;
        }
        $items = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );
        /** @var \SplFileInfo $item */
        foreach ($items as $item) {
            if ($item->isDir()) {
                @rmdir($item->getPathname());
            } else {
                @unlink($item->getPathname());
            }
        }
        @rmdir($path);
    }
}
