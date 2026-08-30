<?php

/**
 * Text Splitter Service
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

/**
 * Service for splitting large texts into smaller chunks.
 *
 * Splits text at paragraph boundaries, keeping chunks under a maximum
 * byte size suitable for storage in the database.
 *
 * @since 3.0.0
 */
class TextSplitterService
{
    /**
     * Default maximum bytes per chunk (60KB - leaves room for DB overhead).
     */
    public const DEFAULT_MAX_BYTES = 60000;

    /**
     * Absolute maximum bytes (MySQL TEXT column limit).
     */
    public const ABSOLUTE_MAX_BYTES = 65000;

    /**
     * Split text into chunks at paragraph boundaries.
     *
     * @param string $text     The text to split
     * @param int    $maxBytes Maximum bytes per chunk (default 60KB)
     *
     * @return array<array{num: int, title: string, content: string}>
     */
    public function split(string $text, int $maxBytes = self::DEFAULT_MAX_BYTES): array
    {
        $text = $this->normalizeText($text);

        // If text fits in one chunk, return as single chapter
        if (strlen($text) <= $maxBytes) {
            return [
                [
                    'num' => 1,
                    'title' => 'Part 1',
                    'content' => $text,
                ],
            ];
        }

        return $this->splitAtParagraphs($text, $maxBytes);
    }

    /**
     * Check if text needs to be split.
     *
     * @param string $text     The text to check
     * @param int    $maxBytes Maximum bytes threshold
     *
     * @return bool True if text exceeds maxBytes
     */
    public function needsSplit(string $text, int $maxBytes = self::DEFAULT_MAX_BYTES): bool
    {
        return strlen($text) > $maxBytes;
    }

    /**
     * Get the byte size of text.
     *
     * @param string $text The text
     *
     * @return int Size in bytes
     */
    public function getByteSize(string $text): int
    {
        return strlen($text);
    }

    /**
     * Normalize text for consistent splitting.
     *
     * @param string $text The text to normalize
     *
     * @return string Normalized text
     */
    private function normalizeText(string $text): string
    {
        // Normalize line endings to Unix style
        $text = str_replace(["\r\n", "\r"], "\n", $text);

        // Remove soft hyphens
        $text = str_replace("\u{00AD}", '', $text);

        // Normalize multiple blank lines to double newline
        $text = preg_replace('/\n{3,}/', "\n\n", $text) ?? $text;

        return trim($text);
    }

    /**
     * Split text at paragraph boundaries.
     *
     * @param string $text     The text to split
     * @param int    $maxBytes Maximum bytes per chunk
     *
     * @return array<array{num: int, title: string, content: string}>
     */
    private function splitAtParagraphs(string $text, int $maxBytes): array
    {
        // Split by double newlines (paragraphs)
        $paragraphs = preg_split('/\n\n+/', $text);
        if ($paragraphs === false) {
            return [];
        }
        $chunks = [];
        $currentChunk = '';
        $chapterNum = 1;

        foreach ($paragraphs as $paragraph) {
            $paragraph = trim($paragraph);
            if ($paragraph === '') {
                continue;
            }

            // Check if adding this paragraph would exceed limit
            $testChunk = $currentChunk === ''
                ? $paragraph
                : $currentChunk . "\n\n" . $paragraph;

            if (strlen($testChunk) > $maxBytes) {
                // Save current chunk if not empty
                if ($currentChunk !== '') {
                    $chunks[] = [
                        'num' => $chapterNum,
                        'title' => $this->generateChunkTitle($chapterNum, $currentChunk),
                        'content' => $currentChunk,
                    ];
                    $chapterNum++;
                }

                // Handle paragraph that's too long by itself
                if (strlen($paragraph) > $maxBytes) {
                    $subChunks = $this->splitLongParagraph($paragraph, $maxBytes);
                    foreach ($subChunks as $subChunk) {
                        $chunks[] = [
                            'num' => $chapterNum,
                            'title' => $this->generateChunkTitle($chapterNum, $subChunk),
                            'content' => $subChunk,
                        ];
                        $chapterNum++;
                    }
                    $currentChunk = '';
                } else {
                    $currentChunk = $paragraph;
                }
            } else {
                $currentChunk = $testChunk;
            }
        }

        // Don't forget the last chunk
        if ($currentChunk !== '') {
            $chunks[] = [
                'num' => $chapterNum,
                'title' => $this->generateChunkTitle($chapterNum, $currentChunk),
                'content' => $currentChunk,
            ];
        }

        return $chunks;
    }

    /**
     * Split a single paragraph that exceeds the limit.
     *
     * Falls back to sentence-level splitting, then word-level if needed.
     *
     * @param string $paragraph The long paragraph
     * @param int    $maxBytes  Maximum bytes per chunk
     *
     * @return string[] Array of sub-chunks
     */
    private function splitLongParagraph(string $paragraph, int $maxBytes): array
    {
        // Try splitting at sentence boundaries first
        $sentences = $this->splitIntoSentences($paragraph);

        if (count($sentences) > 1) {
            return $this->combineUnitsIntoChunks($sentences, $maxBytes);
        }

        // Fall back to word-level splitting
        $words = explode(' ', $paragraph);
        return $this->combineUnitsIntoChunks($words, $maxBytes, ' ');
    }

    /**
     * Split text into sentences.
     *
     * @param string $text The text to split
     *
     * @return string[] Array of sentences
     */
    private function splitIntoSentences(string $text): array
    {
        // Split at sentence-ending punctuation followed by space or end
        $sentences = preg_split(
            '/(?<=[.!?])\s+/',
            $text,
            -1,
            PREG_SPLIT_NO_EMPTY
        );

        if ($sentences === false) {
            return [];
        }
        return array_map('trim', $sentences);
    }

    /**
     * Combine text units into chunks under the byte limit.
     *
     * @param string[] $units     Array of text units (sentences or words)
     * @param int      $maxBytes  Maximum bytes per chunk
     * @param string   $separator Separator between units
     *
     * @return string[] Array of chunks
     */
    private function combineUnitsIntoChunks(
        array $units,
        int $maxBytes,
        string $separator = ' '
    ): array {
        $chunks = [];
        $currentChunk = '';

        foreach ($units as $unit) {
            $unit = trim($unit);
            if ($unit === '') {
                continue;
            }

            $testChunk = $currentChunk === ''
                ? $unit
                : $currentChunk . $separator . $unit;

            if (strlen($testChunk) > $maxBytes) {
                if ($currentChunk !== '') {
                    $chunks[] = $currentChunk;
                }

                // If single unit exceeds limit, truncate it
                if (strlen($unit) > $maxBytes) {
                    $chunks[] = mb_substr($unit, 0, $maxBytes - 3) . '...';
                    $currentChunk = '';
                } else {
                    $currentChunk = $unit;
                }
            } else {
                $currentChunk = $testChunk;
            }
        }

        if ($currentChunk !== '') {
            $chunks[] = $currentChunk;
        }

        return $chunks;
    }

    /**
     * Generate a title for a chunk based on its content.
     *
     * @param int    $num     Chapter number
     * @param string $content The chunk content
     *
     * @return string Generated title
     */
    private function generateChunkTitle(int $num, string $content): string
    {
        // Try to use the first line as title if it looks like a heading
        $lines = explode("\n", trim($content));
        $firstLine = trim($lines[0] ?? '');

        // Check if first line looks like a title:
        // - Short (under 80 chars)
        // - Doesn't end with common sentence punctuation
        // - Has content
        if (
            $firstLine !== '' &&
            mb_strlen($firstLine) <= 80 &&
            !preg_match('/[.,:;]$/', $firstLine)
        ) {
            return $firstLine;
        }

        return "Part {$num}";
    }

    /**
     * Estimate how many chunks a text will be split into.
     *
     * @param string $text     The text
     * @param int    $maxBytes Maximum bytes per chunk
     *
     * @return int Estimated number of chunks
     */
    public function estimateChunkCount(string $text, int $maxBytes = self::DEFAULT_MAX_BYTES): int
    {
        $size = strlen($text);
        if ($size <= $maxBytes) {
            return 1;
        }

        // Rough estimate - actual count depends on paragraph boundaries
        return (int) ceil($size / ($maxBytes * 0.9));
    }
}
