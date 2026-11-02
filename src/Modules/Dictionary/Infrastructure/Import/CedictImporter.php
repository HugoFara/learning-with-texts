<?php

/**
 * CC-CEDICT Dictionary Importer
 *
 * PHP version 8.2
 *
 * @category Lwt
 * @package  Lwt\Modules\Dictionary\Infrastructure\Import
 * @author   HugoFara <git@hugofara.net>
 * @license  Unlicense <http://unlicense.org/>
 * @link     https://hugofara.github.io/lwt/developer/api
 * @since    3.8.0
 */

declare(strict_types=1);

namespace Lwt\Modules\Dictionary\Infrastructure\Import;

use RuntimeException;

/**
 * Importer for CC-CEDICT files (cedict_ts.u8 as distributed by MDBG).
 *
 * The format is one entry per line, with `#` comment lines at the top:
 *
 *     傳統 传统 [chuan2 tong3] /tradition/traditional/convention/
 *
 * i.e. traditional headword, simplified headword, pinyin in brackets, then
 * slash-delimited senses. It is not a CSV: senses routinely contain commas,
 * so CsvImporter split them into garbage (GH discussion #308).
 *
 * @since 3.8.0
 */
class CedictImporter implements ImporterInterface
{
    /** One CEDICT entry line. */
    private const LINE_PATTERN = '#^(\S+)\s+(\S+)\s+\[([^\]]*)\]\s+/(.*)/\s*$#u';

    /** How many non-comment lines canImport() inspects. */
    private const SNIFF_LINES = 5;

    /**
     * {@inheritdoc}
     *
     * Options:
     * - variant: 'both' (default) | 'simplified' | 'traditional' — which
     *   headword(s) to emit. With 'both', an entry whose traditional and
     *   simplified forms differ is emitted once per form, so it is found
     *   whichever script the text uses.
     */
    public function parse(string $filePath, array $options = []): iterable
    {
        $variant = (string) ($options['variant'] ?? 'both');

        $handle = @fopen($filePath, 'r');
        if ($handle === false) {
            throw new RuntimeException('Cannot open file: ' . $filePath);
        }

        try {
            $first = true;
            while (($line = fgets($handle)) !== false) {
                if ($first) {
                    $line = self::stripBom($line);
                    $first = false;
                }
                $entry = self::parseLine($line);
                if ($entry === null) {
                    continue;
                }
                [$traditional, $simplified, $reading, $definition] = $entry;

                if ($variant !== 'traditional') {
                    yield [
                        'term' => $simplified,
                        'definition' => $definition,
                        'reading' => $reading,
                    ];
                }
                if (
                    $variant === 'traditional'
                    || ($variant === 'both' && $traditional !== $simplified)
                ) {
                    yield [
                        'term' => $traditional,
                        'definition' => $definition,
                        'reading' => $reading,
                    ];
                }
            }
        } finally {
            fclose($handle);
        }
    }

    /**
     * {@inheritdoc}
     */
    public function getSupportedExtensions(): array
    {
        return ['u8', 'txt'];
    }

    /**
     * {@inheritdoc}
     *
     * Accepted on content rather than extension: most of the first
     * non-comment lines must look like CEDICT entries. The original name is only used to turn
     * away files that are obviously something else.
     */
    public function canImport(string $filePath, ?string $originalName = null): bool
    {
        if (!is_file($filePath) || !is_readable($filePath)) {
            return false;
        }

        if ($originalName !== null && $originalName !== '') {
            $ext = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
            if ($ext !== '' && !in_array($ext, $this->getSupportedExtensions(), true)) {
                return false;
            }
        }

        $handle = @fopen($filePath, 'r');
        if ($handle === false) {
            return false;
        }

        $valid = 0;
        $invalid = 0;
        $first = true;
        try {
            while ($valid + $invalid < self::SNIFF_LINES && ($line = fgets($handle)) !== false) {
                if ($first) {
                    $line = self::stripBom($line);
                    $first = false;
                }
                $trimmed = trim($line);
                if ($trimmed === '' || str_starts_with($trimmed, '#')) {
                    continue;
                }
                if (self::parseLine($trimmed) === null) {
                    $invalid++;
                } else {
                    $valid++;
                }
            }
        } finally {
            fclose($handle);
        }

        // Most sampled lines must be entries; parse() skips the odd bad one.
        return $valid > $invalid;
    }

    /**
     * {@inheritdoc}
     */
    public function preview(string $filePath, int $limit = 10, array $options = []): array
    {
        $entries = [];
        foreach ($this->parse($filePath, $options) as $entry) {
            $entries[] = $entry;
            if (count($entries) >= $limit) {
                break;
            }
        }
        return $entries;
    }

    /**
     * Parse one CEDICT line.
     *
     * @param string $line Raw line
     *
     * @return array{0: string, 1: string, 2: string, 3: string}|null
     *         [traditional, simplified, pinyin, definition], or null for a
     *         comment, blank or malformed line
     */
    public static function parseLine(string $line): ?array
    {
        $line = trim($line);
        if ($line === '' || str_starts_with($line, '#')) {
            return null;
        }
        if (preg_match(self::LINE_PATTERN, $line, $m) !== 1) {
            return null;
        }

        $senses = array_values(array_filter(
            array_map('trim', explode('/', $m[4])),
            static fn(string $s): bool => $s !== ''
        ));
        if ($senses === []) {
            return null;
        }

        return [$m[1], $m[2], trim($m[3]), implode('; ', $senses)];
    }

    /**
     * Remove a UTF-8 byte order mark from the start of a line.
     */
    private static function stripBom(string $line): string
    {
        return str_starts_with($line, "\xEF\xBB\xBF") ? substr($line, 3) : $line;
    }
}
