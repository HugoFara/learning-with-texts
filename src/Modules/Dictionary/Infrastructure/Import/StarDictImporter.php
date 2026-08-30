<?php

/**
 * StarDict Dictionary Importer
 *
 * PHP version 8.2
 *
 * @category Lwt
 * @package  Lwt\Modules\Dictionary\Infrastructure\Import
 * @author   HugoFara <git@hugofara.net>
 * @license  Unlicense <http://unlicense.org/>
 * @link     https://hugofara.github.io/lwt/developer/api
 * @since    3.0.0
 */

declare(strict_types=1);

namespace Lwt\Modules\Dictionary\Infrastructure\Import;

use Generator;
use RuntimeException;

/**
 * Importer for StarDict dictionary files.
 *
 * Parses .ifo (info), .idx (index), and .dict (data) files.
 * Supports both compressed (.dict.dz) and uncompressed (.dict) formats.
 *
 * @since 3.0.0
 */
class StarDictImporter implements ImporterInterface
{
    /**
     * Dictionary metadata from .ifo file.
     *
     * @var array<string, string>
     */
    private array $info = [];

    /**
     * Known part-of-speech tags (lowercase) used to detect POS in entry data.
     */
    private const POS_TAGS = [
        'noun', 'verb', 'adjective', 'adverb', 'pronoun', 'preposition',
        'conjunction', 'interjection', 'determiner', 'particle', 'article',
        'numeral', 'classifier', 'prefix', 'suffix', 'infix', 'affix',
        'phrase', 'proverb', 'idiom', 'abbreviation', 'initialism',
        'proper noun', 'adj', 'adv', 'prep', 'conj', 'det', 'pron',
    ];

    /**
     * {@inheritdoc}
     */
    public function parse(string $filePath, array $options = []): iterable
    {
        $basePath = $this->getBasePath($filePath);

        // Parse .ifo file
        $this->parseIfo($basePath . '.ifo');

        // Get index entries from .idx or .idx.gz file
        $idxFile = $this->findIdxFile($basePath);
        if ($idxFile === null) {
            throw new RuntimeException("IDX file not found for: $basePath");
        }
        $indexEntries = $this->parseIdx($idxFile);

        // Open dictionary data file
        $dictPath = $this->findDictFile($basePath);
        if ($dictPath === null) {
            throw new RuntimeException("Dictionary data file not found for: $basePath");
        }

        $dictHandle = $this->openDictFile($dictPath);
        if ($dictHandle === false) {
            throw new RuntimeException("Cannot open dictionary file: $dictPath");
        }

        try {
            foreach ($indexEntries as $entry) {
                $parsed = $this->readEntry($dictHandle, $entry['offset'], $entry['size']);
                if ($parsed !== null) {
                    $result = [
                        'term' => $entry['term'],
                        'definition' => $parsed['definition'],
                    ];
                    if ($parsed['pos'] !== null) {
                        $result['pos'] = $parsed['pos'];
                    }
                    yield $result;
                }
            }
        } finally {
            fclose($dictHandle);
        }
    }

    /**
     * {@inheritdoc}
     */
    public function getSupportedExtensions(): array
    {
        return ['ifo', 'idx', 'dict', 'dz', 'gz'];
    }

    /**
     * {@inheritdoc}
     */
    public function canImport(string $filePath, ?string $originalName = null): bool
    {
        // StarDict needs companion .ifo/.idx/.dict files at the same base path.
        // The originalName argument is accepted for interface parity but unused here:
        // a single uploaded file cannot satisfy this format — see import UI for details.
        unset($originalName);
        $basePath = $this->getBasePath($filePath);

        // Check all required files exist
        $ifoPath = $basePath . '.ifo';

        if (!file_exists($ifoPath) || !is_readable($ifoPath)) {
            return false;
        }

        if ($this->findIdxFile($basePath) === null) {
            return false;
        }

        if ($this->findDictFile($basePath) === null) {
            return false;
        }

        return true;
    }

    /**
     * {@inheritdoc}
     */
    public function preview(string $filePath, int $limit = 10, array $options = []): array
    {
        $entries = [];
        $count = 0;

        foreach ($this->parse($filePath, $options) as $entry) {
            $entries[] = $entry;
            $count++;

            if ($count >= $limit) {
                break;
            }
        }

        return $entries;
    }

    /**
     * Get dictionary metadata.
     *
     * @param string $filePath Path to any StarDict file
     *
     * @return array<string, string> Dictionary info
     */
    public function getInfo(string $filePath): array
    {
        $basePath = $this->getBasePath($filePath);
        $this->parseIfo($basePath . '.ifo');
        return $this->info;
    }

    /**
     * Get the base path (without extension) for a StarDict file.
     *
     * @param string $filePath Path to any StarDict file
     *
     * @return string Base path
     */
    private function getBasePath(string $filePath): string
    {
        // Remove common extensions
        $path = $filePath;

        if (str_ends_with($path, '.dict.dz')) {
            $path = substr($path, 0, -8);
        } elseif (str_ends_with($path, '.dz')) {
            $path = substr($path, 0, -3);
        } else {
            $extensions = ['.ifo', '.idx', '.dict', '.syn'];
            foreach ($extensions as $ext) {
                if (str_ends_with($path, $ext)) {
                    $path = substr($path, 0, -strlen($ext));
                    break;
                }
            }
        }

        return $path;
    }

    /**
     * Parse the .ifo (info) file.
     *
     * @param string $ifoPath Path to .ifo file
     *
     * @return void
     *
     * @throws RuntimeException If file is invalid
     */
    private function parseIfo(string $ifoPath): void
    {
        if (!file_exists($ifoPath)) {
            throw new RuntimeException("IFO file not found: $ifoPath");
        }

        $content = file_get_contents($ifoPath);
        if ($content === false) {
            throw new RuntimeException("Cannot read IFO file: $ifoPath");
        }

        $lines = explode("\n", $content);

        // First line should be magic header
        $firstLine = trim($lines[0] ?? '');
        if (!str_starts_with($firstLine, 'StarDict\'s dict iance')) {
            // Try alternative format
            if ($firstLine !== 'StarDict\'s dict ifo file') {
                throw new RuntimeException("Invalid StarDict IFO file format");
            }
        }

        $this->info = [];
        foreach ($lines as $line) {
            $line = trim($line);
            if ($line === '' || !str_contains($line, '=')) {
                continue;
            }

            $parts = explode('=', $line, 2);
            if (count($parts) === 2) {
                $this->info[trim($parts[0])] = trim($parts[1]);
            }
        }
    }

    /**
     * Parse the .idx (index) file.
     *
     * @param string $idxPath Path to .idx file
     *
     * @return Generator<array{term: string, offset: int, size: int}>
     */
    private function parseIdx(string $idxPath): Generator
    {
        // Support both .idx and .idx.gz
        if (str_ends_with($idxPath, '.gz')) {
            $content = file_get_contents('compress.zlib://' . $idxPath);
        } else {
            $content = file_get_contents($idxPath);
        }
        if ($content === false) {
            throw new RuntimeException("Cannot read IDX file: $idxPath");
        }

        $length = strlen($content);
        $pos = 0;

        // Determine offset/size format (32-bit by default)
        $idxOffsetBits = isset($this->info['idxoffsetbits']) ?
            (int) $this->info['idxoffsetbits'] : 32;

        while ($pos < $length) {
            // Read null-terminated term
            $termEnd = strpos($content, "\0", $pos);
            if ($termEnd === false) {
                break;
            }

            $term = substr($content, $pos, $termEnd - $pos);
            $pos = $termEnd + 1;

            // Read offset and size (4 bytes each for 32-bit)
            if ($pos + 8 > $length) {
                break;
            }

            if ($idxOffsetBits === 64) {
                // 64-bit offset
                if ($pos + 12 > $length) {
                    break;
                }
                $offsetData = substr($content, $pos, 8);
                $pos += 8;
                // PHP doesn't have native 64-bit unpack, use 32-bit parts
                $offsetParts = unpack('N2', $offsetData);
                if ($offsetParts === false) {
                    break;
                }
                $offset = ((int)$offsetParts[1] << 32) | (int)$offsetParts[2];
            } else {
                // 32-bit offset
                $offsetData = substr($content, $pos, 4);
                $pos += 4;
                $offsetUnpacked = unpack('N', $offsetData);
                if ($offsetUnpacked === false) {
                    break;
                }
                $offset = (int)$offsetUnpacked[1];
            }

            $sizeData = substr($content, $pos, 4);
            $pos += 4;
            $sizeUnpacked = unpack('N', $sizeData);
            if ($sizeUnpacked === false) {
                break;
            }
            $size = (int)$sizeUnpacked[1];

            if ($term !== '' && $size > 0) {
                yield [
                    'term' => $term,
                    'offset' => $offset,
                    'size' => $size,
                ];
            }
        }
    }

    /**
     * Find the index file (.idx or .idx.gz).
     *
     * @param string $basePath Base path without extension
     *
     * @return string|null Path to idx file or null
     */
    private function findIdxFile(string $basePath): ?string
    {
        $idxPath = $basePath . '.idx';
        if (file_exists($idxPath) && is_readable($idxPath)) {
            return $idxPath;
        }

        $idxGzPath = $basePath . '.idx.gz';
        if (file_exists($idxGzPath) && is_readable($idxGzPath)) {
            return $idxGzPath;
        }

        return null;
    }

    /**
     * Find the dictionary data file (.dict or .dict.dz).
     *
     * @param string $basePath Base path without extension
     *
     * @return string|null Path to dict file or null
     */
    private function findDictFile(string $basePath): ?string
    {
        // Try uncompressed first
        $dictPath = $basePath . '.dict';
        if (file_exists($dictPath) && is_readable($dictPath)) {
            return $dictPath;
        }

        // Try gzip compressed
        $dictDzPath = $basePath . '.dict.dz';
        if (file_exists($dictDzPath) && is_readable($dictDzPath)) {
            return $dictDzPath;
        }

        return null;
    }

    /**
     * Open the dictionary data file.
     *
     * @param string $dictPath Path to dict file
     *
     * @return resource|false File handle or false
     */
    private function openDictFile(string $dictPath)
    {
        if (str_ends_with($dictPath, '.dz')) {
            // Gzip compressed - use zlib wrapper
            return gzopen($dictPath, 'rb');
        }

        return fopen($dictPath, 'rb');
    }

    /**
     * Read and parse an entry from the dictionary file.
     *
     * @param resource $handle File handle
     * @param int      $offset Byte offset
     * @param int      $size   Data size
     *
     * @return array{definition: string, pos: string|null}|null Parsed entry
     */
    private function readEntry($handle, int $offset, int $size): ?array
    {
        if (fseek($handle, $offset) === -1) {
            return null;
        }

        $data = fread($handle, $size);
        if ($data === false) {
            return null;
        }

        $sameTypeSequence = $this->info['sametypesequence'] ?? null;

        if ($sameTypeSequence !== null) {
            // All entries share the same type sequence — split fields on null bytes
            return $this->parseFields($data);
        }

        // Per-entry type markers: first byte is the type character
        if (strlen($data) > 1) {
            $typeMarker = $data[0];
            if (ctype_alpha($typeMarker)) {
                $data = substr($data, 1);
                $nullPos = strpos($data, "\0");
                if ($nullPos !== false) {
                    $data = substr($data, 0, $nullPos);
                }
            }
        }

        return $this->parseFields($data);
    }

    /**
     * Parse raw entry data into definition and optional POS.
     *
     * StarDict entries may contain multiple null-byte-separated fields.
     * The first field is often a POS tag (e.g., "noun", "verb").
     *
     * @param string $data Raw entry data
     *
     * @return array{definition: string, pos: string|null}|null
     */
    private function parseFields(string $data): ?array
    {
        // Split on null bytes — these separate fields in StarDict data
        $segments = explode("\0", $data);

        // Clean each segment
        $cleaned = [];
        foreach ($segments as $segment) {
            $segment = $this->cleanSegment($segment);
            if ($segment !== '') {
                $cleaned[] = $segment;
            }
        }

        if ($cleaned === []) {
            return null;
        }

        // Check if the first segment is a POS tag
        $pos = null;
        $firstLower = mb_strtolower($cleaned[0], 'UTF-8');
        if (count($cleaned) > 1 && in_array($firstLower, self::POS_TAGS, true)) {
            $pos = $cleaned[0];
            array_shift($cleaned);
        }

        $definition = implode('; ', $cleaned);

        return ['definition' => $definition, 'pos' => $pos];
    }

    /**
     * Clean up a single text segment.
     *
     * @param string $segment Raw segment
     *
     * @return string Cleaned segment
     */
    private function cleanSegment(string $segment): string
    {
        // Convert to UTF-8 if needed
        if (!mb_check_encoding($segment, 'UTF-8')) {
            $converted = mb_convert_encoding($segment, 'UTF-8', 'auto');
            if ($converted !== false) {
                $segment = $converted;
            }
        }

        // Convert block-level HTML to separators before stripping
        $segment = preg_replace('/<br\s*\/?>/i', '; ', $segment) ?? $segment;
        $segment = preg_replace('/<\/(?:div|p|li|tr|dt|dd)>/i', '; ', $segment) ?? $segment;

        // Remove remaining HTML/Pango markup tags
        $segment = strip_tags($segment);

        // Strip WikDict Lua template parser error prefixes
        $segment = preg_replace(
            '/^\w+TemplateParserError:LuaError\s*/',
            '',
            $segment
        ) ?? $segment;

        // Collapse whitespace and trim
        $segment = preg_replace('/\s+/', ' ', $segment) ?? $segment;
        $segment = trim($segment, " \t\n\r;");

        return $segment;
    }
}
