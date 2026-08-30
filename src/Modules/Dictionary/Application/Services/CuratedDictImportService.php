<?php

/**
 * Curated Dictionary Import Service
 *
 * Downloads curated dictionary archives from verified URLs,
 * extracts them, and imports entries via the existing import pipeline.
 *
 * PHP version 8.2
 *
 * @category Lwt
 * @package  Lwt\Modules\Dictionary\Application\Services
 * @license  Unlicense <http://unlicense.org/>
 * @since    3.0.0
 */

declare(strict_types=1);

namespace Lwt\Modules\Dictionary\Application\Services;

use Lwt\Modules\Dictionary\Application\DictionaryFacade;
use Lwt\Modules\Dictionary\Infrastructure\Import\ArchiveExtractor;
use RuntimeException;

/**
 * Service for importing curated dictionaries from remote URLs.
 *
 * Validates URLs against the curated registry (SSRF defense),
 * downloads the archive, extracts it, delegates to the appropriate
 * importer, and cleans up temp files.
 *
 * @since 3.0.0
 */
class CuratedDictImportService
{
    /** Maximum download size (100 MB). */
    private const MAX_DOWNLOAD_BYTES = 100 * 1024 * 1024;

    /** HTTP download timeout in seconds. */
    private const DOWNLOAD_TIMEOUT = 120;

    private DictionaryFacade $facade;

    /** @var string|null Override path for testing */
    private ?string $registryPath;

    private ArchiveExtractor $archiveExtractor;

    public function __construct(
        DictionaryFacade $facade,
        ?string $registryPath = null
    ) {
        $this->facade = $facade;
        $this->registryPath = $registryPath;
        $this->archiveExtractor = new ArchiveExtractor();
    }

    /**
     * Import a curated dictionary from a remote URL.
     *
     * @param int    $languageId Target language ID
     * @param string $url        Download URL (must be in curated registry)
     * @param string $format     Dictionary format (stardict, csv)
     * @param string $name       Dictionary name
     *
     * @return array{success: bool, dictId?: int, imported?: int, skipped?: int,
     *         vocabCreated?: int, error?: string} `skipped` counts entries
     *         whose headword was too long to store
     */
    public function importFromUrl(
        int $languageId,
        string $url,
        string $format,
        string $name
    ): array {
        // Validate URL is in curated registry
        if (!$this->isCuratedUrl($url)) {
            return ['success' => false, 'error' => 'URL is not in the curated dictionary registry'];
        }

        $tempFiles = [];
        $dictId = null;

        try {
            // Increase time limit for large downloads
            set_time_limit(300);

            // Download archive
            $archivePath = $this->downloadToTemp($url);
            $tempFiles[] = $archivePath;

            // Determine if extraction is needed
            $urlPath = parse_url($url, PHP_URL_PATH);
            $archiveName = is_string($urlPath) ? basename($urlPath) : '';
            if (ArchiveExtractor::isArchive($archiveName)) {
                $extractDir = $this->archiveExtractor->extract($archivePath, $archiveName);
                $tempFiles[] = $extractDir;
                $importFile = $this->findImportFile($extractDir, $format);
            } else {
                // Direct file (e.g. CSV) - use as-is
                $importFile = $archivePath;
            }

            // Get importer and parse
            $importer = $this->facade->getImporter($format, basename($importFile));

            if (!$importer->canImport($importFile)) {
                return ['success' => false, 'error' => 'Downloaded file is not a valid ' . $format . ' dictionary'];
            }

            // Create dictionary record and import entries
            $dictId = $this->facade->create($languageId, $name, $format);
            $entries = $importer->parse($importFile);
            $result = $this->facade->addEntriesBatch($dictId, $entries);
            $count = $result['added'];

            if ($count === 0) {
                // Clean up empty dictionary
                $this->facade->delete($dictId);
                return ['success' => false, 'error' => 'No entries found in the dictionary file'];
            }

            // Create vocabulary terms (status 1) from dictionary entries
            $vocabCreated = $this->facade->createVocabularyFromEntries($dictId, $languageId);

            // Auto-enable local dict mode if currently online-only
            $this->facade->autoEnableLocalDictMode($languageId);

            return [
                'success' => true,
                'dictId' => $dictId,
                'imported' => $count,
                'skipped' => $result['skipped'],
                'vocabCreated' => $vocabCreated,
            ];
        } catch (RuntimeException $e) {
            // Drop the half-filled dictionary. Without this a failure part-way
            // through leaves the record plus however many entries had already
            // been inserted (for a large dictionary, hundreds of thousands of
            // rows) with nothing in the UI explaining where they came from.
            if ($dictId !== null) {
                try {
                    $this->facade->delete($dictId);
                } catch (RuntimeException $cleanupError) {
                    // Keep the original failure as the reported error.
                }
            }
            return ['success' => false, 'error' => $e->getMessage()];
        } finally {
            $this->cleanup(...$tempFiles);
        }
    }

    /**
     * Check whether a URL appears in the curated dictionaries registry.
     */
    public function isCuratedUrl(string $url): bool
    {
        $registry = $this->loadRegistry();
        if ($registry === null) {
            return false;
        }

        foreach ($registry as $group) {
            $sources = isset($group['sources']) && is_array($group['sources'])
                ? $group['sources'] : [];
            /** @var list<array<string, mixed>> $sources */
            foreach ($sources as $source) {
                if (isset($source['url']) && $source['url'] === $url) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * Download a URL to a temporary file.
     *
     * @return string Path to the downloaded temp file
     *
     * @throws RuntimeException On download failure
     */
    private function downloadToTemp(string $url): string
    {
        $context = stream_context_create([
            'http' => [
                'timeout' => self::DOWNLOAD_TIMEOUT,
                'user_agent' => 'LWT/3.0 (+https://github.com/HugoFara/lwt)',
                'max_redirects' => 5,
                'ignore_errors' => false,
            ],
            'ssl' => [
                'verify_peer' => true,
                'verify_peer_name' => true,
            ],
        ]);

        $tempPath = tempnam(sys_get_temp_dir(), 'lwt_dict_');
        if ($tempPath === false) {
            throw new RuntimeException('Failed to create temporary file');
        }

        // Download with size limit
        $source = @fopen($url, 'r', false, $context);
        if ($source === false) {
            unlink($tempPath);
            throw new RuntimeException('Failed to download dictionary from ' . $url);
        }

        $dest = fopen($tempPath, 'w');
        if ($dest === false) {
            fclose($source);
            unlink($tempPath);
            throw new RuntimeException('Failed to write temporary file');
        }

        $totalBytes = 0;
        while (!feof($source)) {
            $chunk = fread($source, 8192);
            if ($chunk === false) {
                break;
            }
            $totalBytes += strlen($chunk);
            if ($totalBytes > self::MAX_DOWNLOAD_BYTES) {
                fclose($source);
                fclose($dest);
                unlink($tempPath);
                throw new RuntimeException('Download exceeds maximum size of 100 MB');
            }
            fwrite($dest, $chunk);
        }

        fclose($source);
        fclose($dest);

        if ($totalBytes === 0) {
            unlink($tempPath);
            throw new RuntimeException('Downloaded file is empty');
        }

        return $tempPath;
    }

    /**
     * Find the importable file within an extracted directory.
     *
     * @param string $directory Extracted directory path
     * @param string $format    Expected format (stardict, csv)
     *
     * @return string Path to the importable file
     *
     * @throws RuntimeException If no importable file found
     */
    public function findImportFile(string $directory, string $format): string
    {
        $extensions = match ($format) {
            'stardict' => ['ifo'],
            'csv', 'tsv' => ['csv', 'tsv', 'txt'],
            'json' => ['json'],
            default => throw new RuntimeException('Unsupported format: ' . $format),
        };

        $found = $this->archiveExtractor->findByExtensions($directory, $extensions);
        if ($found !== null) {
            return $found;
        }

        throw new RuntimeException(
            'No ' . $format . ' file found in the downloaded archive (looked for .' .
            implode(', .', $extensions) . ')'
        );
    }

    /**
     * Load the curated dictionaries registry.
     *
     * @return list<array<string, mixed>>|null
     */
    private function loadRegistry(): ?array
    {
        $path = $this->registryPath ?? (dirname(__DIR__, 5) . '/data/curated_dictionaries.json');
        if (!file_exists($path)) {
            return null;
        }
        $json = file_get_contents($path);
        if ($json === false) {
            return null;
        }
        $data = json_decode($json, true);
        if (!is_array($data) || !isset($data['dictionaries'])) {
            return null;
        }
        /** @var list<array<string, mixed>> */
        return $data['dictionaries'];
    }

    /**
     * Clean up temporary files and directories.
     */
    private function cleanup(string ...$paths): void
    {
        $this->archiveExtractor->cleanup(...$paths);
    }
}
