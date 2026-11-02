<?php

/**
 * Unit tests for CedictImporter (regression tests for GH discussion #308).
 *
 * PHP version 8.2
 *
 * @category Tests
 * @package  Lwt\Tests\Modules\Dictionary\Infrastructure\Import
 * @license  Unlicense <http://unlicense.org/>
 */

declare(strict_types=1);

namespace Lwt\Tests\Modules\Dictionary\Infrastructure\Import;

use Lwt\Modules\Dictionary\Application\Services\CuratedDictImportService;
use Lwt\Modules\Dictionary\Application\DictionaryFacade;
use Lwt\Modules\Dictionary\Infrastructure\Import\CedictImporter;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * CC-CEDICT parsing, detection, and the archive lookup for cedict_ts.u8.
 */
#[CoversClass(CedictImporter::class)]
class CedictImporterTest extends TestCase
{
    private const SAMPLE = "\xEF\xBB\xBF# CC-CEDICT\n"
        . "# Community maintained free Chinese-English dictionary.\n"
        . "#! version=1\n"
        . "傳統 传统 [chuan2 tong3] /tradition/traditional/convention, custom/\n"
        . "中國 中国 [Zhong1 guo2] /China/\n"
        . "好 好 [hao3] /good/well/\n"
        . "\n"
        . "this line is not an entry\n";

    private string $tmpDir;

    protected function setUp(): void
    {
        $this->tmpDir = sys_get_temp_dir() . '/lwt_cedict_' . uniqid();
        mkdir($this->tmpDir);
    }

    protected function tearDown(): void
    {
        $this->removeDir($this->tmpDir);
    }

    private function removeDir(string $dir): void
    {
        foreach (glob($dir . '/*') ?: [] as $f) {
            is_dir($f) ? $this->removeDir($f) : unlink($f);
        }
        @rmdir($dir);
    }

    private function writeSample(string $name = 'cedict_ts.u8', string $content = self::SAMPLE): string
    {
        $path = $this->tmpDir . '/' . $name;
        file_put_contents($path, $content);
        return $path;
    }

    public function testParseLineSplitsAllFields(): void
    {
        $this->assertSame(
            ['傳統', '传统', 'chuan2 tong3', 'tradition; traditional; convention, custom'],
            CedictImporter::parseLine('傳統 传统 [chuan2 tong3] /tradition/traditional/convention, custom/')
        );
    }

    public function testParseLineRejectsCommentsAndGarbage(): void
    {
        $this->assertNull(CedictImporter::parseLine('# CC-CEDICT'));
        $this->assertNull(CedictImporter::parseLine(''));
        $this->assertNull(CedictImporter::parseLine('word,translation'));
        $this->assertNull(CedictImporter::parseLine('好 好 [hao3] //'));
    }

    public function testParseEmitsBothScriptsOnlyWhenTheyDiffer(): void
    {
        $entries = iterator_to_array(
            (new CedictImporter())->parse($this->writeSample()),
            false
        );

        $this->assertSame(
            ['传统', '傳統', '中国', '中國', '好'],
            array_column($entries, 'term')
        );
        $this->assertSame('tradition; traditional; convention, custom', $entries[0]['definition']);
        $this->assertSame('chuan2 tong3', $entries[0]['reading']);
    }

    public function testParseSimplifiedOnly(): void
    {
        $entries = iterator_to_array(
            (new CedictImporter())->parse($this->writeSample(), ['variant' => 'simplified']),
            false
        );
        $this->assertSame(['传统', '中国', '好'], array_column($entries, 'term'));
    }

    public function testParseTraditionalOnly(): void
    {
        $entries = iterator_to_array(
            (new CedictImporter())->parse($this->writeSample(), ['variant' => 'traditional']),
            false
        );
        $this->assertSame(['傳統', '中國', '好'], array_column($entries, 'term'));
    }

    public function testCanImportSniffsContentOfExtensionlessUpload(): void
    {
        $path = $this->writeSample('phpAB1234');
        $importer = new CedictImporter();
        $this->assertTrue($importer->canImport($path));
        $this->assertTrue($importer->canImport($path, 'cedict_ts.u8'));
        $this->assertTrue($importer->canImport($path, 'cedict.txt'));
    }

    public function testCanImportRejectsCsvAndWrongExtension(): void
    {
        $importer = new CedictImporter();
        $csv = $this->writeSample('words.txt', "word,translation\nhús,house\n");
        $this->assertFalse($importer->canImport($csv));
        $this->assertFalse($importer->canImport($this->writeSample(), 'dict.json'));
    }

    public function testCanImportRejectsCommentOnlyFile(): void
    {
        $this->assertFalse(
            (new CedictImporter())->canImport($this->writeSample('c.u8', "# only comments\n"))
        );
    }

    public function testCuratedLookupFindsU8InExtractedArchive(): void
    {
        // The MDBG zip holds a single cedict_ts.u8, which the old 'csv'
        // lookup (.csv/.tsv/.txt) could never find.
        $this->writeSample();
        $service = new CuratedDictImportService($this->createStub(DictionaryFacade::class));
        $this->assertSame(
            $this->tmpDir . '/cedict_ts.u8',
            $service->findImportFile($this->tmpDir, 'cedict')
        );
    }

    public function testCuratedRegistryPointsChineseAtCedictFormat(): void
    {
        $registry = json_decode(
            (string) file_get_contents(dirname(__DIR__, 6) . '/data/curated_dictionaries.json'),
            true
        );
        $this->assertIsArray($registry);
        $found = null;
        foreach ($registry['dictionaries'] as $group) {
            foreach ($group['sources'] as $source) {
                if (str_starts_with($source['name'], 'CC-CEDICT')) {
                    $found = $source;
                }
            }
        }
        $this->assertNotNull($found);
        $this->assertSame('cedict', $found['format']);
        $this->assertStringStartsWith('https://www.mdbg.net/chinese/export/cedict/', $found['url']);
    }
}
