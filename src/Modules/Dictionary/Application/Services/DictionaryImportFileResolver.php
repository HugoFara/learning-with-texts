<?php

/**
 * Dictionary Import File Resolver
 *
 * Resolves a dictionary upload (single file or archive) to the concrete
 * path an importer can read. Encapsulates the archive-extract-then-find
 * flow shared by /word/upload and the legacy /dictionaries/import route.
 *
 * PHP version 8.2
 *
 * @category Lwt
 * @package  Lwt\Modules\Dictionary\Application\Services
 * @license  Unlicense <http://unlicense.org/>
 * @since    3.1.2-fork
 */

declare(strict_types=1);

namespace Lwt\Modules\Dictionary\Application\Services;

use Lwt\Modules\Dictionary\Infrastructure\Import\ArchiveExtractor;
use RuntimeException;

/**
 * Resolves a dictionary upload to the file an importer should consume.
 *
 * Web uploads land in a single PHP temp file with no extension, but multi-file
 * formats like StarDict need their `.idx`/`.dict` siblings alongside the
 * `.ifo`. Users therefore upload an archive (`.zip` / `.tar.gz` / ...), and
 * this service extracts it, locates the entry-point file by extension, and
 * hands the importer back a real path it can open.
 *
 * The service is stateful: each call to {@see resolve()} that extracts an
 * archive registers a temp directory which the caller MUST release via
 * {@see cleanup()} (typically in a `finally` block). One resolver instance
 * per request.
 */
class DictionaryImportFileResolver
{
    /** @var list<string> */
    private array $tempDirs = [];

    private ArchiveExtractor $extractor;

    public function __construct(?ArchiveExtractor $extractor = null)
    {
        $this->extractor = $extractor ?? new ArchiveExtractor();
    }

    /**
     * Resolve the upload to a path/name pair the importer can use.
     *
     * @param string $tmpPath      PHP upload tmp_name (e.g. /tmp/phpXXXXXX)
     * @param string $originalName Original filename submitted by the browser
     * @param string $format       Import format: 'stardict' | 'json' | 'csv' | 'cedict'
     *
     * @return array{path: string, name: string} Resolved path and display name
     *
     * @throws RuntimeException If the upload cannot satisfy the requested format
     */
    public function resolve(string $tmpPath, string $originalName, string $format): array
    {
        if (ArchiveExtractor::isArchive($originalName)) {
            $extractDir = $this->extractor->extract($tmpPath, $originalName);
            $this->tempDirs[] = $extractDir;

            $extensions = $this->expectedExtensionsForFormat($format);
            $found = $this->extractor->findByExtensions($extractDir, $extensions);
            if ($found === null) {
                throw new RuntimeException(
                    'Archive does not contain a .' . implode('/.', $extensions) . ' file'
                );
            }
            return ['path' => $found, 'name' => basename($found)];
        }

        // StarDict needs companion .idx/.dict files alongside the .ifo. A single
        // uploaded file can never satisfy that — give a clear hint instead of
        // letting it fall through to the generic "Invalid file format" gate.
        if ($format === 'stardict') {
            throw new RuntimeException(
                'StarDict import requires an archive '
                . '(.zip / .tar.gz / .tar.bz2 / .tar.xz / .tgz) containing the '
                . '.ifo, .idx, and .dict (or .dict.dz) files together.'
            );
        }

        return ['path' => $tmpPath, 'name' => $originalName];
    }

    /**
     * Release any temp directories created during resolve() calls.
     *
     * Idempotent — safe to call multiple times.
     */
    public function cleanup(): void
    {
        if ($this->tempDirs === []) {
            return;
        }
        $this->extractor->cleanup(...$this->tempDirs);
        $this->tempDirs = [];
    }

    /**
     * Entry-point file extensions inside an extracted archive, by format.
     *
     * @return list<string>
     */
    private function expectedExtensionsForFormat(string $format): array
    {
        return match ($format) {
            'stardict' => ['ifo'],
            'json' => ['json'],
            'cedict' => ['u8', 'txt'],
            default => ['csv', 'tsv', 'txt'],
        };
    }
}
