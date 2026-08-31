<?php

/**
 * JSON Dictionary Importer
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
 * Importer for JSON dictionary files.
 *
 * Supports arrays of entries or objects with term keys.
 *
 * @since 3.0.0
 */
class JsonImporter implements ImporterInterface
{
    /**
     * Hard cap on the JSON input file size.
     *
     * parseStreaming() currently delegates to parseSimple() (no real
     * streaming parser in tree), so the file gets loaded entirely into
     * memory before json_decode. A 500 MB JSON dictionary OOMs the PHP
     * worker; 100 MB is well above any plausible legitimate
     * single-language dictionary (LWT's own JSON exports of 100k+
     * terms are still under 20 MB).
     */
    public const MAX_FILE_SIZE = 100 * 1024 * 1024;

    /**
     * Default field mapping for JSON entries.
     */
    private const DEFAULT_FIELD_MAP = [
        'term' => ['term', 'word', 'headword', 'entry', 'lemma'],
        'definition' => ['definition', 'meaning', 'translation', 'gloss', 'def'],
        'reading' => ['reading', 'pronunciation', 'phonetic', 'furigana', 'pinyin'],
        'pos' => ['pos', 'partOfSpeech', 'part_of_speech', 'category'],
    ];

    /**
     * {@inheritdoc}
     */
    public function parse(string $filePath, array $options = []): iterable
    {
        $this->validateFile($filePath);

        /** @var array<string, string>|null $fieldMap */
        $fieldMap = $options['fieldMap'] ?? null;

        // Try streaming for large files
        $fileSize = filesize($filePath);
        if ($fileSize !== false && $fileSize > 10 * 1024 * 1024) {
            // > 10MB, use streaming parser
            yield from $this->parseStreaming($filePath, $fieldMap);
        } else {
            yield from $this->parseSimple($filePath, $fieldMap);
        }
    }

    /**
     * {@inheritdoc}
     */
    public function getSupportedExtensions(): array
    {
        return ['json'];
    }

    /**
     * {@inheritdoc}
     */
    public function canImport(string $filePath, ?string $originalName = null): bool
    {
        if (!file_exists($filePath) || !is_readable($filePath)) {
            return false;
        }

        // PHP uploads land at tmp_name (e.g. /tmp/phpXXXXXX) without an extension —
        // use the original filename for the extension check when supplied.
        $nameForExt = $originalName !== null && $originalName !== '' ? $originalName : $filePath;
        $extension = strtolower(pathinfo($nameForExt, PATHINFO_EXTENSION));
        if ($extension !== 'json') {
            return false;
        }

        // Quick validation: check if file starts with [ or {
        $handle = fopen($filePath, 'r');
        if ($handle === false) {
            return false;
        }

        $start = fread($handle, 1024);
        fclose($handle);

        if ($start === false) {
            return false;
        }

        $start = ltrim($start);
        return str_starts_with($start, '[') || str_starts_with($start, '{');
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
     * Detect the structure of a JSON file.
     *
     * @param string $filePath Path to the file
     *
     * @return array{type: string, fieldNames: string[]} Structure info
     */
    public function detectStructure(string $filePath): array
    {
        $handle = fopen($filePath, 'r');
        if ($handle === false) {
            return ['type' => 'unknown', 'fieldNames' => []];
        }

        $content = fread($handle, 65536); // Read first 64KB
        fclose($handle);

        if ($content === false) {
            return ['type' => 'unknown', 'fieldNames' => []];
        }

        $content = ltrim($content);

        if (str_starts_with($content, '[')) {
            // Array of entries
            /** @var array<int, array<string, mixed>>|null $data */
            $data = json_decode($content, true);
            if (is_array($data) && !empty($data) && is_array($data[0])) {
                /** @var array<string> $fieldNames */
                $fieldNames = array_keys($data[0]);
                return [
                    'type' => 'array',
                    'fieldNames' => $fieldNames,
                ];
            }
        } elseif (str_starts_with($content, '{')) {
            // Object with term keys
            /** @var array<string, array<string, mixed>>|null $data */
            $data = json_decode($content, true);
            if (is_array($data)) {
                $firstKey = array_key_first($data);
                if ($firstKey !== null && is_array($data[$firstKey])) {
                    /** @var array<string> $fieldNames */
                    $fieldNames = array_keys($data[$firstKey]);
                    return [
                        'type' => 'object',
                        'fieldNames' => $fieldNames,
                    ];
                }
            }
        }

        return ['type' => 'unknown', 'fieldNames' => []];
    }

    /**
     * Validate that the file exists, is readable, and within the size cap.
     *
     * @param string $filePath Path to the file
     *
     * @return void
     *
     * @throws RuntimeException If file is invalid or oversized
     */
    private function validateFile(string $filePath): void
    {
        if (!file_exists($filePath)) {
            throw new RuntimeException("File not found: $filePath");
        }

        if (!is_readable($filePath)) {
            throw new RuntimeException("File is not readable: $filePath");
        }

        $size = filesize($filePath);
        if ($size === false || $size > self::MAX_FILE_SIZE) {
            throw new RuntimeException(
                'JSON dictionary exceeds the '
                . intdiv(self::MAX_FILE_SIZE, 1024 * 1024)
                . ' MB import limit.'
            );
        }
    }

    /**
     * Parse JSON file by loading it entirely into memory.
     *
     * @param string                   $filePath Path to the file
     * @param array<string, string>|null $fieldMap Custom field mapping
     *
     * @return Generator<array{term: string, definition: string, reading?: ?string, pos?: ?string}>
     */
    private function parseSimple(string $filePath, ?array $fieldMap): Generator
    {
        $content = file_get_contents($filePath);
        if ($content === false) {
            throw new RuntimeException("Cannot read file: $filePath");
        }

        /** @var array<array-key, mixed>|null $data */
        $data = json_decode($content, true);
        if ($data === null && json_last_error() !== JSON_ERROR_NONE) {
            throw new RuntimeException("Invalid JSON: " . json_last_error_msg());
        }

        if (is_array($data)) {
            // Check if it's an array of entries or object with term keys
            if (array_is_list($data)) {
                // Array of entries: [{"term": "...", "definition": "..."}, ...]
                /** @var array<string, mixed> $item */
                foreach ($data as $item) {
                    if (is_array($item)) {
                        $entry = $this->mapItemToEntry($item, $fieldMap);
                        if ($entry !== null) {
                            yield $entry;
                        }
                    }
                }
            } else {
                // Object with term keys: {"term1": {"definition": "..."}, ...}
                /** @var mixed $value */
                foreach ($data as $term => $value) {
                    $entry = $this->mapObjectEntryToEntry((string) $term, $value, $fieldMap);
                    if ($entry !== null) {
                        yield $entry;
                    }
                }
            }
        }
    }

    /**
     * Parse JSON file using streaming for large files.
     *
     * @param string                     $filePath Path to the file
     * @param array<string, string>|null $fieldMap Custom field mapping
     *
     * @return Generator<array{term: string, definition: string, reading?: ?string, pos?: ?string}>
     *
     * @todo Real streaming via halaxa/json-machine. Today this is the
     *       same in-memory parse as {@see parseSimple()}; the
     *       {@see MAX_FILE_SIZE} cap is what prevents OOM in the
     *       meantime. If you raise the cap, replace this body first.
     */
    private function parseStreaming(string $filePath, ?array $fieldMap): Generator
    {
        yield from $this->parseSimple($filePath, $fieldMap);
    }

    /**
     * Map a JSON item (array entry) to a dictionary entry.
     *
     * @param array<string, mixed>       $item     JSON item
     * @param array<string, string>|null $fieldMap Custom field mapping
     *
     * @return array{term: string, definition: string, reading?: ?string, pos?: ?string}|null
     */
    private function mapItemToEntry(array $item, ?array $fieldMap): ?array
    {
        /** @var string|null $term */
        $term = $this->findField($item, 'term', $fieldMap);
        /** @var string|null $definition */
        $definition = $this->findField($item, 'definition', $fieldMap);

        if ($term === null || $definition === null) {
            return null;
        }

        $entry = [
            'term' => $term,
            'definition' => $definition,
        ];

        /** @var string|null $reading */
        $reading = $this->findField($item, 'reading', $fieldMap);
        if ($reading !== null && $reading !== '') {
            $entry['reading'] = $reading;
        }

        /** @var string|null $pos */
        $pos = $this->findField($item, 'pos', $fieldMap);
        if ($pos !== null && $pos !== '') {
            $entry['pos'] = $pos;
        }

        return $entry;
    }

    /**
     * Map an object entry (term => data) to a dictionary entry.
     *
     * @param string                     $term     The term (object key)
     * @param mixed                      $value    The entry data
     * @param array<string, string>|null $fieldMap Custom field mapping
     *
     * @return array{term: string, definition: string, reading?: ?string, pos?: ?string}|null
     */
    private function mapObjectEntryToEntry(string $term, mixed $value, ?array $fieldMap): ?array
    {
        if ($term === '') {
            return null;
        }

        if (is_string($value)) {
            // Simple: {"term": "definition"}
            return [
                'term' => $term,
                'definition' => $value,
            ];
        }

        if (is_array($value)) {
            // Complex: {"term": {"definition": "...", ...}}
            /** @var array<string, mixed> $valueTyped */
            $valueTyped = $value;
            /** @var string|null $definition */
            $definition = $this->findField($valueTyped, 'definition', $fieldMap);
            if ($definition === null) {
                // Try 'meaning' or first string value
                /** @var string|null $definition */
                $definition = $value['meaning'] ?? $value['gloss'] ?? null;
                if ($definition === null && !empty($value)) {
                    /** @var mixed $v */
                    foreach ($value as $v) {
                        if (is_string($v)) {
                            $definition = $v;
                            break;
                        }
                    }
                }
            }

            if ($definition === null) {
                return null;
            }

            $entry = [
                'term' => $term,
                'definition' => $definition,
            ];

            /** @var string|null $reading */
            $reading = $this->findField($valueTyped, 'reading', $fieldMap);
            if ($reading !== null && $reading !== '') {
                $entry['reading'] = $reading;
            }

            /** @var string|null $pos */
            $pos = $this->findField($valueTyped, 'pos', $fieldMap);
            if ($pos !== null && $pos !== '') {
                $entry['pos'] = $pos;
            }

            return $entry;
        }

        return null;
    }

    /**
     * Find a field value using custom mapping or default patterns.
     *
     * @param array<string, mixed>       $item      JSON item
     * @param string                     $fieldType Field type (term, definition, etc.)
     * @param array<string, string>|null $fieldMap  Custom field mapping
     *
     * @return mixed Field value or null
     */
    private function findField(array $item, string $fieldType, ?array $fieldMap): mixed
    {
        // Use custom mapping if provided
        if ($fieldMap !== null && isset($fieldMap[$fieldType])) {
            return $item[$fieldMap[$fieldType]] ?? null;
        }

        // Try default field names
        $patterns = self::DEFAULT_FIELD_MAP[$fieldType] ?? [];
        foreach ($patterns as $pattern) {
            if (isset($item[$pattern])) {
                return $item[$pattern];
            }
            // Try case-insensitive
            /** @var mixed $itemVal */
            foreach ($item as $key => $itemVal) {
                if (strtolower($key) === strtolower($pattern)) {
                    return $itemVal;
                }
            }
        }

        return null;
    }
}
