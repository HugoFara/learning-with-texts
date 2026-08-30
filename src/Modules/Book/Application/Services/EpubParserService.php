<?php

/**
 * EPUB Parser Service
 *
 * PHP version 8.2
 *
 * @category Lwt
 * @package  Lwt\Modules\Book\Application\Services
 * @author   HugoFara <git@hugofara.net>
 * @license  Unlicense <http://unlicense.org/>
 * @link     https://hugofara.github.io/lwt/developer/api
 * @since    3.0.0
 */

declare(strict_types=1);

namespace Lwt\Modules\Book\Application\Services;

use Lwt\Modules\Book\Infrastructure\Epub\EpubBook;
use Lwt\Modules\Book\Infrastructure\Epub\EpubChapter;
use Lwt\Modules\Book\Infrastructure\Epub\EpubDocument;
use Lwt\Modules\Book\Infrastructure\Epub\EpubReader;
use InvalidArgumentException;
use RuntimeException;

/**
 * Service for parsing EPUB files and extracting content.
 *
 * Reads EPUB files through the in-tree {@see EpubReader} and extracts metadata
 * and chapter content for import into LWT. LWT bundled its own reader in 3.4.0
 * so that EPUB import, a core feature, no longer depends on an external
 * Composer package (#263).
 *
 * @since 3.0.0
 */
class EpubParserService
{
    /**
     * Hard cap on the uploaded EPUB size (compressed bytes on disk).
     *
     * Real EPUBs are usually < 5 MB; even very large illustrated books
     * stay under ~50 MB. 100 MB leaves head-room for outliers without
     * inviting trivially-DoSable uploads.
     */
    public const MAX_FILE_SIZE = 100 * 1024 * 1024;

    /**
     * Cap on the number of files inside the EPUB. EPUBs typically
     * contain dozens of HTML chapters + assets, not thousands.
     */
    public const MAX_ENTRIES = 2000;

    /**
     * Cap on total decompressed bytes across all entries — the
     * zip-bomb defense. 500 MB is well above any plausible legitimate
     * EPUB and far below the level that would crash a typical worker.
     */
    public const MAX_DECOMPRESSED_BYTES = 500 * 1024 * 1024;

    /**
     * Parse an EPUB file and extract metadata and chapters.
     *
     * @param string $filePath     Absolute path to the EPUB file
     * @param string $originalName Original filename (used to derive the
     *                             format when $filePath has no extension,
     *                             e.g. PHP upload temp paths)
     *
     * @return array{
     *     metadata: array{
     *         title: string,
     *         author: string|null,
     *         description: string|null,
     *         language: string|null,
     *         sourceHash: string
     *     },
     *     chapters: array<array{num: int, title: string, content: string}>
     * }
     *
     * @throws InvalidArgumentException If file doesn't exist
     * @throws RuntimeException If file cannot be parsed
     */
    public function parse(string $filePath, string $originalName = ''): array
    {
        if (!extension_loaded('zip')) {
            throw new RuntimeException(
                "The 'zip' PHP extension is required for EPUB import "
                . "but is not installed. Please install php-zip extension."
            );
        }

        if (!file_exists($filePath)) {
            throw new InvalidArgumentException("EPUB file not found: {$filePath}");
        }

        // Additional validation - check if file is readable and has content
        if (!is_readable($filePath)) {
            throw new InvalidArgumentException("EPUB file is not readable: {$filePath}");
        }

        $size = filesize($filePath);
        if ($size === 0) {
            throw new InvalidArgumentException("EPUB file is empty: {$filePath}");
        }
        if ($size > self::MAX_FILE_SIZE) {
            throw new InvalidArgumentException(sprintf(
                'EPUB file exceeds the %d MB limit.',
                intdiv(self::MAX_FILE_SIZE, 1024 * 1024)
            ));
        }
        $this->assertZipWithinLimits($filePath);

        try {
            $ebook = EpubReader::read($filePath);
        } catch (\Throwable $e) {
            $label = $originalName !== '' ? $originalName : $filePath;
            throw new RuntimeException(
                "Failed to parse EPUB file '{$label}': " . $e->getMessage(),
                0,
                $e
            );
        }

        $metadata = [
            'title' => $ebook->getTitle() ?? 'Unknown Title',
            'author' => $this->extractAuthor($ebook),
            'description' => $ebook->getDescription(),
            'language' => $ebook->getLanguage(),
            'sourceHash' => (string) hash_file('sha256', $filePath),
        ];

        $chapters = $this->extractChapters($ebook);

        return [
            'metadata' => $metadata,
            'chapters' => $chapters,
        ];
    }

    /**
     * Extract the primary author name from an ebook.
     *
     * @param EpubBook $ebook The parsed ebook
     *
     * @return string|null Author name or null if not found
     */
    private function extractAuthor(EpubBook $ebook): ?string
    {
        return $ebook->getAuthorMain();
    }

    /**
     * Extract chapters from an ebook.
     *
     * @param EpubBook $ebook The parsed ebook
     *
     * @return array<array{num: int, title: string, content: string}>
     */
    private function extractChapters(EpubBook $ebook): array
    {
        $chapters = [];
        $chapterNum = 1;

        /** @var EpubChapter[] $ebookChapters */
        $ebookChapters = $ebook->getChapters();

        foreach ($ebookChapters as $chapter) {
            $content = $this->cleanHtmlContent($chapter->getContent());

            // Skip empty chapters
            if (trim($content) === '') {
                continue;
            }

            $chapters[] = [
                'num' => $chapterNum,
                'title' => $chapter->getLabel() ?: "Chapter {$chapterNum}",
                'content' => $content,
            ];
            $chapterNum++;
        }

        // If no chapters found, try to extract from HTML files
        if (empty($chapters)) {
            $chapters = $this->extractFromHtmlFiles($ebook);
        }

        return $chapters;
    }

    /**
     * Extract content from HTML files in the EPUB as fallback.
     *
     * @param EpubBook $ebook The parsed ebook
     *
     * @return array<array{num: int, title: string, content: string}>
     */
    private function extractFromHtmlFiles(EpubBook $ebook): array
    {
        $chapters = [];
        $chapterNum = 1;

        foreach ($ebook->getHtml() as $htmlFile) {
            if ($this->isNavigationFile($htmlFile)) {
                continue;
            }

            $content = $this->cleanHtmlContent($htmlFile->getBody());

            if (trim($content) === '') {
                continue;
            }

            // Try to extract title from content
            $title = $this->extractTitleFromContent($content, $chapterNum);

            $chapters[] = [
                'num' => $chapterNum,
                'title' => $title,
                'content' => $content,
            ];
            $chapterNum++;
        }

        return $chapters;
    }

    /**
     * Detect EPUB 3 navigation / TOC documents that should not appear as
     * chapters.
     *
     * The TOC-driven getChapters() path ignores nav.xhtml, but when an EPUB
     * ships without a usable table of contents the HTML fallback would
     * otherwise include the nav document as a phantom chapter. Filename
     * heuristics cover the common cases (nav.xhtml, toc.xhtml); the body sniff
     * catches less conventionally-named EPUB 3 nav documents identified by the
     * `epub:type="toc"` (or related) attribute on a `<nav>` element.
     */
    public function isNavigationFile(EpubDocument $htmlFile): bool
    {
        $filename = strtolower(basename($htmlFile->getFilename()));
        if (preg_match('/^(nav|toc|navigation|contents)(\.|-|_)/', $filename) === 1) {
            return true;
        }
        if (in_array($filename, ['nav.xhtml', 'nav.html', 'toc.xhtml', 'toc.html'], true)) {
            return true;
        }

        $body = $htmlFile->getBody();
        if ($body === '') {
            return false;
        }
        return preg_match(
            '/<nav\b[^>]*epub:type\s*=\s*["\'][^"\']*\b(toc|landmarks|page-list)\b/i',
            $body
        ) === 1;
    }

    /**
     * Extract a title from content if possible.
     *
     * @param string $content The text content
     * @param int    $num     Default chapter number
     *
     * @return string The extracted or default title
     */
    private function extractTitleFromContent(string $content, int $num): string
    {
        // Get first line as potential title
        $lines = explode("\n", trim($content));
        $firstLine = trim($lines[0] ?? '');

        // If first line looks like a title (short, not a paragraph)
        if ($firstLine !== '' && mb_strlen($firstLine) <= 100 && !str_contains($firstLine, '.')) {
            return $firstLine;
        }

        return "Chapter {$num}";
    }

    /**
     * Clean HTML content to plain text suitable for LWT.
     *
     * Strips HTML tags while preserving paragraph structure with double
     * newlines for paragraph breaks.
     *
     * @param string $html The HTML content
     *
     * @return string Clean plain text
     */
    public function cleanHtmlContent(string $html): string
    {
        // Remove scripts and styles
        $text = preg_replace('/<script\b[^>]*>.*?<\/script>/is', '', $html) ?? $html;
        $text = preg_replace('/<style\b[^>]*>.*?<\/style>/is', '', $text) ?? $text;

        // Convert paragraph and div tags to double newlines
        $text = preg_replace('/<\/?(p|div)[^>]*>/i', "\n\n", $text) ?? $text;

        // Convert line breaks to single newlines
        $text = preg_replace('/<br\s*\/?>/i', "\n", $text) ?? $text;

        // Convert headings to preserve structure
        $text = preg_replace('/<h[1-6][^>]*>/i', "\n\n", $text) ?? $text;
        $text = preg_replace('/<\/h[1-6]>/i', "\n\n", $text) ?? $text;

        // Convert list items
        $text = preg_replace('/<li[^>]*>/i', "\n- ", $text) ?? $text;

        // Strip remaining HTML tags
        $text = strip_tags($text);

        // Decode HTML entities
        $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');

        // Normalize whitespace
        // Replace multiple spaces/tabs with single space
        $text = preg_replace('/[ \t\r]+/', ' ', $text) ?? $text;

        // Normalize multiple newlines to double newline (paragraph break)
        $text = preg_replace('/\n\s*\n/', "\n\n", $text) ?? $text;

        // Remove leading/trailing whitespace from lines
        $lines = explode("\n", $text);
        $lines = array_map('trim', $lines);
        $text = implode("\n", $lines);

        // Remove excessive newlines (more than 2)
        $text = preg_replace('/\n{3,}/', "\n\n", $text) ?? $text;

        return trim($text);
    }

    /**
     * Validate that a file is an EPUB.
     *
     * @param string $filePath Path to the file
     *
     * @return bool True if valid EPUB
     */
    public function isValidEpub(string $filePath, string $originalName = ''): bool
    {
        if (!file_exists($filePath)) {
            return false;
        }

        if (!is_readable($filePath)) {
            return false;
        }

        if (filesize($filePath) === 0) {
            return false;
        }

        // Check file extension using original filename when available
        // (PHP stores uploads at temp paths like /tmp/phpXXXXXX with no extension)
        $nameToCheck = $originalName !== '' ? $originalName : $filePath;
        $extension = strtolower(pathinfo($nameToCheck, PATHINFO_EXTENSION));
        if ($extension !== 'epub') {
            return false;
        }

        // Check if it's a ZIP file (EPUBs are ZIP archives)
        $fh = fopen($filePath, 'rb');
        if ($fh === false) {
            return false;
        }

        $header = fread($fh, 4);
        fclose($fh);

        // ZIP magic number - basic validation that doesn't require zip extension
        if ($header !== "PK\x03\x04") {
            return false;
        }

        // If ZIP extension is not loaded, we can't do deeper validation
        if (!extension_loaded('zip')) {
            return true; // Basic validation passed, let the parser handle the rest
        }

        // Additional validation with ZIP extension if available
        /** @psalm-suppress UndefinedClass */
        $zip = new \ZipArchive();
        /** @psalm-suppress UndefinedClass */
        $result = $zip->open($filePath, \ZipArchive::RDONLY);

        if ($result !== true) {
            return false;
        }

        // Check for EPUB-specific files
        $hasMimetype = $zip->locateName('mimetype') !== false;
        $hasContainer = $zip->locateName('META-INF/container.xml') !== false;

        $zip->close();

        return $hasMimetype && $hasContainer;
    }

    /**
     * Walk the ZIP central directory and refuse archives that exceed
     * the entry-count or decompressed-size budget — the zip-bomb
     * defense. Skipped silently when ext-zip is unavailable so the
     * minimal magic-byte path in isValidEpub stays the only check.
     *
     * @param string $filePath Path to the EPUB on disk
     *
     * @throws InvalidArgumentException When the archive exceeds limits
     */
    private function assertZipWithinLimits(string $filePath): void
    {
        if (!extension_loaded('zip')) {
            return;
        }

        /** @psalm-suppress UndefinedClass */
        $zip = new \ZipArchive();
        /** @psalm-suppress UndefinedClass */
        if ($zip->open($filePath, \ZipArchive::RDONLY) !== true) {
            return;
        }

        $entryCount = $zip->numFiles;
        if ($entryCount > self::MAX_ENTRIES) {
            $zip->close();
            throw new InvalidArgumentException(sprintf(
                'EPUB contains too many entries (%d > %d).',
                $entryCount,
                self::MAX_ENTRIES
            ));
        }

        $totalUncompressed = 0;
        for ($i = 0; $i < $entryCount; $i++) {
            $stat = $zip->statIndex($i);
            if ($stat === false) {
                continue;
            }
            $totalUncompressed += (int) ($stat['size'] ?? 0);
            if ($totalUncompressed > self::MAX_DECOMPRESSED_BYTES) {
                $zip->close();
                throw new InvalidArgumentException(sprintf(
                    'EPUB exceeds the %d MB decompressed size limit (likely a zip bomb).',
                    intdiv(self::MAX_DECOMPRESSED_BYTES, 1024 * 1024)
                ));
            }
        }

        $zip->close();
    }

    /**
     * Get just the metadata without parsing chapters.
     *
     * @param string $filePath     Path to the EPUB file
     * @param string $originalName Original filename (used to derive the
     *                             format when $filePath has no extension,
     *                             e.g. PHP upload temp paths)
     *
     * @return array{
     *     title: string,
     *     author: string|null,
     *     description: string|null,
     *     language: string|null
     * }|null Metadata or null on failure
     */
    public function getMetadata(string $filePath, string $originalName = ''): ?array
    {
        if (!extension_loaded('zip')) {
            return null;
        }

        if (!file_exists($filePath)) {
            return null;
        }

        try {
            $ebook = EpubReader::read($filePath);

            return [
                'title' => $ebook->getTitle() ?? 'Unknown Title',
                'author' => $this->extractAuthor($ebook),
                'description' => $ebook->getDescription(),
                'language' => $ebook->getLanguage(),
            ];
        } catch (\Throwable $e) {
            $label = $originalName !== '' ? $originalName : $filePath;
            error_log("EpubParserService::getMetadata failed for '$label': " . $e->getMessage());
            return null;
        }
    }
}
