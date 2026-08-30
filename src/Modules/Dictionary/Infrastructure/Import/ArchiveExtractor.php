<?php

/**
 * Dictionary Archive Extractor
 *
 * PHP version 8.2
 *
 * @category Lwt
 * @package  Lwt\Modules\Dictionary\Infrastructure\Import
 * @author   HugoFara <git@hugofara.net>
 * @license  Unlicense <http://unlicense.org/>
 * @link     https://hugofara.github.io/lwt/developer/api
 * @since    3.1.2
 */

declare(strict_types=1);

namespace Lwt\Modules\Dictionary\Infrastructure\Import;

use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;
use SplFileInfo;
use ZipArchive;

/**
 * Extracts dictionary archives (.zip, .tar.gz, .tgz, .tar.bz2, .tar.xz)
 * to a temp directory so multi-file formats like StarDict can be imported
 * via a single file upload.
 *
 * Hardened against zip-bombs (file-count cap), path traversal, and stale
 * temp files (caller-driven cleanup).
 *
 * @since 3.1.2
 */
class ArchiveExtractor
{
    /** Maximum files allowed in an archive. */
    public const MAX_FILES = 500;

    /**
     * Decide whether a filename looks like a supported archive.
     */
    public static function isArchive(string $name): bool
    {
        $lower = strtolower($name);
        if (str_ends_with($lower, '.zip') || str_ends_with($lower, '.tgz')) {
            return true;
        }
        return (bool) preg_match('/\.tar\.(xz|gz|bz2)$/', $lower);
    }

    /**
     * Extract an archive to a fresh temp directory.
     *
     * @param string $archivePath  On-disk path to the archive (typically a PHP upload tmp_name).
     * @param string $originalName Original filename, used to detect archive type.
     *
     * @return string Path to the extraction directory.
     *
     * @throws RuntimeException On unsupported archive type or extraction failure.
     */
    public function extract(string $archivePath, string $originalName): string
    {
        $lower = strtolower($originalName);

        if (str_ends_with($lower, '.zip')) {
            return $this->extractZip($archivePath);
        }

        if (
            str_ends_with($lower, '.tgz')
            || (bool) preg_match('/\.tar\.(xz|gz|bz2)$/', $lower)
        ) {
            return $this->extractTar($archivePath);
        }

        throw new RuntimeException('Unsupported archive type: ' . $originalName);
    }

    /**
     * Recursively scan a directory for the first file whose extension matches one of $extensions.
     *
     * @param string        $directory  Directory to scan.
     * @param list<string>  $extensions Lower-case extensions without the dot (e.g. ['ifo']).
     *
     * @return string|null Absolute path to the first match, or null if none found.
     */
    public function findByExtensions(string $directory, array $extensions): ?string
    {
        if (!is_dir($directory)) {
            return null;
        }

        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS)
        );

        /** @var SplFileInfo $file */
        foreach ($iterator as $file) {
            if ($file->isFile()) {
                $ext = strtolower($file->getExtension());
                if (in_array($ext, $extensions, true)) {
                    return $file->getPathname();
                }
            }
        }

        return null;
    }

    /**
     * Remove temp files/directories created during a previous extract().
     */
    public function cleanup(string ...$paths): void
    {
        foreach ($paths as $path) {
            if (!file_exists($path)) {
                continue;
            }
            if (is_dir($path)) {
                $this->removeDir($path);
            } else {
                @unlink($path);
            }
        }
    }

    /**
     * Extract a ZIP archive into a fresh temp directory.
     *
     * @throws RuntimeException On open failure, file-count overflow, or path traversal.
     */
    private function extractZip(string $zipPath): string
    {
        if (!class_exists(ZipArchive::class)) {
            throw new RuntimeException('PHP ZipArchive extension is required for ZIP import');
        }

        /**
         * @psalm-suppress UndefinedDocblockClass, UnnecessaryVarAnnotation
         * @var \ZipArchive $zip
         */
        $zip = new ZipArchive();
        $result = $zip->open($zipPath);
        if ($result !== true) {
            throw new RuntimeException('Failed to open ZIP archive (error code: ' . (string) $result . ')');
        }

        /**
         * @psalm-suppress UndefinedDocblockClass, UnnecessaryVarAnnotation
         * @var int $numFiles
         */
        $numFiles = $zip->numFiles;
        if ($numFiles > self::MAX_FILES) {
            $zip->close();
            throw new RuntimeException('ZIP archive contains too many files (' . $numFiles . ')');
        }

        for ($i = 0; $i < $numFiles; $i++) {
            /**
             * @psalm-suppress UnnecessaryVarAnnotation
             * @var string|false $entryName
             */
            $entryName = $zip->getNameIndex($i);
            if ($entryName === false) {
                continue;
            }
            if (str_contains($entryName, '..')) {
                $zip->close();
                throw new RuntimeException('ZIP archive contains unsafe path: ' . $entryName);
            }
        }

        $extractDir = $this->makeTempDir();
        if (!$zip->extractTo($extractDir)) {
            $zip->close();
            $this->removeDir($extractDir);
            throw new RuntimeException('Failed to extract ZIP archive');
        }

        $zip->close();
        return $extractDir;
    }

    /**
     * Extract a tar archive (.tar.xz, .tar.gz, .tar.bz2, .tgz) using the system `tar` command.
     *
     * @throws RuntimeException On extraction failure, file-count overflow, or unsafe path.
     */
    private function extractTar(string $tarPath): string
    {
        // Walk the archive listing BEFORE extracting. The legacy
        // post-extraction count let a 10 GB / 1M-file archive blow up
        // the filesystem before we noticed; listing via `tar -tf`
        // lets us bail at any zip-bomb signal without writing a byte.
        // `tar` auto-detects gzip/bzip2/xz from the magic bytes.
        $listCommand = sprintf('tar -tf %s 2>&1', escapeshellarg($tarPath));
        $listOutput = [];
        $listExit = 0;
        exec($listCommand, $listOutput, $listExit);

        if ($listExit !== 0) {
            throw new RuntimeException(
                'Failed to read tar archive (exit code ' . $listExit . '): ' .
                implode("\n", array_map(static fn ($e): string => (string) $e, $listOutput))
            );
        }

        if (count($listOutput) > self::MAX_FILES) {
            throw new RuntimeException(
                'Archive contains too many files ('
                . count($listOutput) . ' > ' . self::MAX_FILES . ')'
            );
        }

        // exec()'s out-param is typed as `array` (mixed values) at
        // the stub level — normalize to a list of strings up-front so
        // the path-safety checks below can prove their operands.
        $entries = [];
        /** @psalm-suppress MixedAssignment */
        foreach ($listOutput as $row) {
            $entries[] = (string) $row;
        }

        foreach ($entries as $entry) {
            // Absolute paths and parent-relative components are the two
            // classic ways a tar entry escapes its target directory.
            // tar's --no-absolute-names would also catch these at
            // extraction time but the error there is opaque; rejecting
            // here gives a clear cause.
            if ($entry === '' || $entry[0] === '/' || str_contains($entry, '..')) {
                throw new RuntimeException('Tar archive contains unsafe path: ' . $entry);
            }
        }

        $extractDir = $this->makeTempDir();

        // --no-same-owner: don't try to chown extracted files (we're
        //     not running as root, and even if we were, chown to a
        //     uid from the archive is never what we want).
        // --no-same-permissions: writeable+executable bits from the
        //     archive aren't trustworthy; let umask apply.
        $command = sprintf(
            'tar xf %s -C %s --no-same-owner --no-same-permissions 2>&1',
            escapeshellarg($tarPath),
            escapeshellarg($extractDir)
        );

        $output = [];
        $exitCode = 0;
        exec($command, $output, $exitCode);

        if ($exitCode !== 0) {
            $this->removeDir($extractDir);
            throw new RuntimeException(
                'Failed to extract tar archive (exit code ' . $exitCode . '): ' .
                implode("\n", $output)
            );
        }

        return $extractDir;
    }

    /**
     * Allocate a fresh temp directory. Throws on failure.
     */
    private function makeTempDir(): string
    {
        $dir = sys_get_temp_dir() . '/lwt_dict_' . bin2hex(random_bytes(8));
        if (!mkdir($dir, 0700, true)) {
            throw new RuntimeException('Failed to create extraction directory');
        }
        return $dir;
    }

    /**
     * Recursively remove a directory and all its contents.
     */
    private function removeDir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        $items = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST
        );
        /** @var SplFileInfo $item */
        foreach ($items as $item) {
            if ($item->isDir()) {
                @rmdir($item->getPathname());
            } else {
                @unlink($item->getPathname());
            }
        }
        @rmdir($dir);
    }
}
