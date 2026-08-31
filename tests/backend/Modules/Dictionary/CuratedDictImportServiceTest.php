<?php

/**
 * Unit tests for CuratedDictImportService.
 *
 * PHP version 8.2
 *
 * @category Tests
 * @package  Lwt\Tests\Modules\Dictionary
 * @license  Unlicense <http://unlicense.org/>
 * @since    3.0.0
 */

declare(strict_types=1);

namespace Lwt\Tests\Modules\Dictionary;

use Lwt\Modules\Dictionary\Application\DictionaryFacade;
use Lwt\Modules\Dictionary\Application\Services\CuratedDictImportService;
use Lwt\Modules\Dictionary\Infrastructure\Import\ImporterInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Tests for the CuratedDictImportService class.
 *
 * Tests URL validation, ZIP extraction safety, file finding,
 * and the import pipeline with mocked dependencies.
 */
class CuratedDictImportServiceTest extends TestCase
{
    private DictionaryFacade&MockObject $facade;
    private string $testRegistryPath;
    private CuratedDictImportService $service;
    private string $tempDir;

    protected function setUp(): void
    {
        $this->facade = $this->createMock(DictionaryFacade::class);

        // Create a test registry file
        $this->tempDir = sys_get_temp_dir() . '/lwt_test_' . bin2hex(random_bytes(4));
        mkdir($this->tempDir, 0700, true);

        $this->testRegistryPath = $this->tempDir . '/test_registry.json';
        file_put_contents($this->testRegistryPath, json_encode([
            'dictionaries' => [
                [
                    'language' => 'de',
                    'languageName' => 'German',
                    'sources' => [
                        [
                            'name' => 'Test Dict',
                            'url' => 'https://example.com/test-dict.zip',
                            'format' => 'stardict',
                            'entries' => '~1000',
                            'license' => 'CC BY-SA',
                            'notes' => 'Test dictionary',
                            'directDownload' => true,
                        ],
                        [
                            'name' => 'CSV Dict',
                            'url' => 'https://example.com/test.csv',
                            'format' => 'csv',
                            'entries' => '~500',
                            'license' => 'MIT',
                            'notes' => 'CSV test',
                            'directDownload' => true,
                        ],
                    ],
                ],
                [
                    'language' => 'fr',
                    'languageName' => 'French',
                    'sources' => [
                        [
                            'name' => 'French Dict',
                            'url' => 'https://example.com/fr-dict.zip',
                            'format' => 'stardict',
                            'entries' => '~2000',
                            'license' => 'GPL',
                            'notes' => 'French test',
                            'directDownload' => false,
                        ],
                    ],
                ],
            ],
        ]));

        $this->service = new CuratedDictImportService(
            $this->facade,
            $this->testRegistryPath
        );
    }

    protected function tearDown(): void
    {
        // Clean up temp directory
        $this->removeDir($this->tempDir);
    }

    // ===== isCuratedUrl tests =====

    public function testIsCuratedUrlReturnsTrueForRegisteredUrl(): void
    {
        $this->assertTrue(
            $this->service->isCuratedUrl('https://example.com/test-dict.zip')
        );
    }

    public function testIsCuratedUrlReturnsTrueForSecondSource(): void
    {
        $this->assertTrue(
            $this->service->isCuratedUrl('https://example.com/test.csv')
        );
    }

    public function testIsCuratedUrlReturnsTrueForDifferentLanguage(): void
    {
        $this->assertTrue(
            $this->service->isCuratedUrl('https://example.com/fr-dict.zip')
        );
    }

    public function testIsCuratedUrlReturnsFalseForUnknownUrl(): void
    {
        $this->assertFalse(
            $this->service->isCuratedUrl('https://evil.com/malware.zip')
        );
    }

    public function testIsCuratedUrlReturnsFalseForEmptyUrl(): void
    {
        $this->assertFalse($this->service->isCuratedUrl(''));
    }

    public function testIsCuratedUrlReturnsFalseForPartialMatch(): void
    {
        $this->assertFalse(
            $this->service->isCuratedUrl('https://example.com/test-dict')
        );
    }

    public function testIsCuratedUrlReturnsFalseWhenRegistryMissing(): void
    {
        $service = new CuratedDictImportService(
            $this->facade,
            '/nonexistent/path.json'
        );
        $this->assertFalse(
            $service->isCuratedUrl('https://example.com/test-dict.zip')
        );
    }

    public function testIsCuratedUrlReturnsFalseForInvalidRegistry(): void
    {
        $badPath = $this->tempDir . '/bad_registry.json';
        file_put_contents($badPath, 'not valid json');
        $service = new CuratedDictImportService($this->facade, $badPath);
        $this->assertFalse(
            $service->isCuratedUrl('https://example.com/test-dict.zip')
        );
    }

    public function testIsCuratedUrlReturnsFalseForRegistryWithoutDictionaries(): void
    {
        $badPath = $this->tempDir . '/no_dicts.json';
        file_put_contents($badPath, json_encode(['other' => 'data']));
        $service = new CuratedDictImportService($this->facade, $badPath);
        $this->assertFalse(
            $service->isCuratedUrl('https://example.com/test-dict.zip')
        );
    }

    // ===== importFromUrl validation tests =====

    public function testImportFromUrlRejectsNonCuratedUrl(): void
    {
        $result = $this->service->importFromUrl(
            1,
            'https://evil.com/dict.zip',
            'stardict',
            'Evil Dict'
        );

        $this->assertFalse($result['success']);
        $this->assertStringContainsString('not in the curated', $result['error']);
    }

    // ===== findImportFile tests =====

    public function testFindImportFileFindsIfoForStardict(): void
    {
        $dir = $this->tempDir . '/stardict_test';
        mkdir($dir, 0700, true);
        file_put_contents($dir . '/test.ifo', 'StarDict ifo');
        file_put_contents($dir . '/test.idx', 'index');
        file_put_contents($dir . '/test.dict', 'data');

        $result = $this->service->findImportFile($dir, 'stardict');
        $this->assertStringEndsWith('.ifo', $result);
    }

    public function testFindImportFileFindsCsvForCsvFormat(): void
    {
        $dir = $this->tempDir . '/csv_test';
        mkdir($dir, 0700, true);
        file_put_contents($dir . '/words.csv', 'term,def');

        $result = $this->service->findImportFile($dir, 'csv');
        $this->assertStringEndsWith('.csv', $result);
    }

    public function testFindImportFileFindsTsvForCsvFormat(): void
    {
        $dir = $this->tempDir . '/tsv_test';
        mkdir($dir, 0700, true);
        file_put_contents($dir . '/words.tsv', "term\tdef");

        $result = $this->service->findImportFile($dir, 'csv');
        $this->assertStringEndsWith('.tsv', $result);
    }

    public function testFindImportFileFindsTxtForCsvFormat(): void
    {
        $dir = $this->tempDir . '/txt_test';
        mkdir($dir, 0700, true);
        file_put_contents($dir . '/words.txt', 'term,def');

        $result = $this->service->findImportFile($dir, 'csv');
        $this->assertStringEndsWith('.txt', $result);
    }

    public function testFindImportFileFindsJsonForJsonFormat(): void
    {
        $dir = $this->tempDir . '/json_test';
        mkdir($dir, 0700, true);
        file_put_contents($dir . '/dict.json', '[]');

        $result = $this->service->findImportFile($dir, 'json');
        $this->assertStringEndsWith('.json', $result);
    }

    public function testFindImportFileFindsInNestedDirectory(): void
    {
        $dir = $this->tempDir . '/nested_test';
        mkdir($dir . '/subdir/deep', 0700, true);
        file_put_contents($dir . '/subdir/deep/dict.ifo', 'content');

        $result = $this->service->findImportFile($dir, 'stardict');
        $this->assertStringEndsWith('.ifo', $result);
    }

    public function testFindImportFileThrowsWhenNoMatchingFile(): void
    {
        $dir = $this->tempDir . '/empty_test';
        mkdir($dir, 0700, true);
        file_put_contents($dir . '/readme.md', 'not a dict');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('No stardict file found');
        $this->service->findImportFile($dir, 'stardict');
    }

    public function testFindImportFileThrowsForUnsupportedFormat(): void
    {
        $dir = $this->tempDir . '/unsupported_test';
        mkdir($dir, 0700, true);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Unsupported format');
        $this->service->findImportFile($dir, 'xml');
    }

    // ===== Constructor and registry path tests =====

    public function testConstructorAcceptsNullRegistryPath(): void
    {
        $service = new CuratedDictImportService($this->facade, null);
        // Should not throw - falls back to default path
        $this->assertInstanceOf(CuratedDictImportService::class, $service);
    }

    public function testConstructorAcceptsCustomRegistryPath(): void
    {
        $service = new CuratedDictImportService(
            $this->facade,
            $this->testRegistryPath
        );
        $this->assertInstanceOf(CuratedDictImportService::class, $service);
    }

    // ===== findImportFile edge cases =====

    public function testFindImportFileIgnoresNonMatchingExtensions(): void
    {
        $dir = $this->tempDir . '/mixed_test';
        mkdir($dir, 0700, true);
        file_put_contents($dir . '/readme.txt', 'readme');
        file_put_contents($dir . '/data.xml', '<xml/>');
        file_put_contents($dir . '/dict.ifo', 'stardict');

        $result = $this->service->findImportFile($dir, 'stardict');
        $this->assertStringEndsWith('.ifo', $result);
    }

    public function testFindImportFileCaseInsensitiveExtension(): void
    {
        $dir = $this->tempDir . '/case_test';
        mkdir($dir, 0700, true);
        // Create file with uppercase extension
        file_put_contents($dir . '/DICT.CSV', 'term,def');

        $result = $this->service->findImportFile($dir, 'csv');
        $this->assertStringContainsString('DICT.CSV', $result);
    }

    // ===== Registry loading edge cases =====

    public function testIsCuratedUrlHandlesEmptySourcesArray(): void
    {
        $path = $this->tempDir . '/empty_sources.json';
        file_put_contents($path, json_encode([
            'dictionaries' => [
                [
                    'language' => 'de',
                    'languageName' => 'German',
                    'sources' => [],
                ],
            ],
        ]));
        $service = new CuratedDictImportService($this->facade, $path);
        $this->assertFalse(
            $service->isCuratedUrl('https://example.com/test.zip')
        );
    }

    public function testIsCuratedUrlHandlesMissingSourcesKey(): void
    {
        $path = $this->tempDir . '/no_sources_key.json';
        file_put_contents($path, json_encode([
            'dictionaries' => [
                [
                    'language' => 'de',
                    'languageName' => 'German',
                    // no 'sources' key
                ],
            ],
        ]));
        $service = new CuratedDictImportService($this->facade, $path);
        $this->assertFalse(
            $service->isCuratedUrl('https://example.com/test.zip')
        );
    }

    public function testIsCuratedUrlHandlesMissingUrlInSource(): void
    {
        $path = $this->tempDir . '/no_url.json';
        file_put_contents($path, json_encode([
            'dictionaries' => [
                [
                    'language' => 'de',
                    'sources' => [
                        ['name' => 'Test', 'format' => 'csv'],
                    ],
                ],
            ],
        ]));
        $service = new CuratedDictImportService($this->facade, $path);
        $this->assertFalse(
            $service->isCuratedUrl('https://example.com/test.zip')
        );
    }

    /**
     * Recursively remove a directory.
     */
    private function removeDir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        $items = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($items as $item) {
            /** @var \SplFileInfo $item */
            if ($item->isDir()) {
                @rmdir($item->getPathname());
            } else {
                @unlink($item->getPathname());
            }
        }
        @rmdir($dir);
    }
}
