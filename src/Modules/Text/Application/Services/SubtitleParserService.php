<?php

/**
 * Subtitle Parser Service
 *
 * Parses SRT and VTT subtitle files, extracting text content
 * and stripping timecodes for language learning purposes.
 *
 * PHP version 8.2
 *
 * @category Lwt
 * @package  Lwt\Modules\Text\Application\Services
 * @author   HugoFara <git@hugofara.net>
 * @license  Unlicense <http://unlicense.org/>
 * @link     https://hugofara.github.io/lwt/developer/api
 * @since    3.0.0
 */

declare(strict_types=1);

namespace Lwt\Modules\Text\Application\Services;

/**
 * Service for parsing subtitle files (SRT, VTT) and extracting text content.
 *
 * Supports:
 * - SRT (SubRip) format
 * - VTT (WebVTT) format
 *
 * @since 3.0.0
 */
class SubtitleParserService
{
    /**
     * Parse a subtitle file and extract text content.
     *
     * @param string $content Raw file content
     * @param string $format  Format: 'srt' or 'vtt'
     *
     * @return array{success: bool, text: string, cueCount: int, error: string|null}
     */
    public function parse(string $content, string $format): array
    {
        if (trim($content) === '') {
            return [
                'success' => false,
                'text' => '',
                'cueCount' => 0,
                'error' => 'Subtitle file is empty',
            ];
        }

        // Normalize line endings
        $content = str_replace(["\r\n", "\r"], "\n", $content);

        $text = match ($format) {
            'srt' => $this->parseSrt($content),
            'vtt' => $this->parseVtt($content),
            default => null,
        };

        if ($text === null) {
            return [
                'success' => false,
                'text' => '',
                'cueCount' => 0,
                'error' => "Unsupported format: {$format}",
            ];
        }

        $text = $this->cleanText($text);
        $cueCount = substr_count($text, "\n\n") + 1;

        if (trim($text) === '') {
            return [
                'success' => false,
                'text' => '',
                'cueCount' => 0,
                'error' => 'No text content found in subtitle file',
            ];
        }

        return [
            'success' => true,
            'text' => $text,
            'cueCount' => $cueCount,
            'error' => null,
        ];
    }

    /**
     * Detect subtitle format from filename extension or content.
     *
     * @param string $filename File name
     * @param string $content  File content (for WEBVTT header detection)
     *
     * @return string|null 'srt', 'vtt', or null if unknown
     */
    public function detectFormat(string $filename, string $content): ?string
    {
        // Check file extension first
        $extension = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
        if ($extension === 'srt') {
            return 'srt';
        }
        if ($extension === 'vtt') {
            return 'vtt';
        }

        // Fall back to content detection
        $firstLine = strtok(trim($content), "\n");
        if ($firstLine !== false && str_starts_with(trim($firstLine), 'WEBVTT')) {
            return 'vtt';
        }

        // Check for SRT pattern: starts with a number followed by timecode
        if (preg_match('/^\d+\s*\n\d{2}:\d{2}:\d{2},\d{3}\s*-->/m', $content)) {
            return 'srt';
        }

        return null;
    }

    /**
     * Validate that content appears to be a valid subtitle file.
     *
     * @param string $content File content
     * @param string $format  Expected format ('srt' or 'vtt')
     *
     * @return bool True if content appears valid for the format
     */
    public function isValidSubtitle(string $content, string $format): bool
    {
        if (trim($content) === '') {
            return false;
        }

        return match ($format) {
            'srt' => $this->isValidSrt($content),
            'vtt' => $this->isValidVtt($content),
            default => false,
        };
    }

    /**
     * Parse SRT format content.
     *
     * SRT format:
     * ```
     * 1
     * 00:00:00,000 --> 00:00:05,000
     * Subtitle text here
     *
     * 2
     * 00:00:05,100 --> 00:00:10,000
     * Another subtitle
     * ```
     *
     * @param string $content Raw SRT content
     *
     * @return string Extracted text with double newlines between cues
     */
    private function parseSrt(string $content): string
    {
        // Split by blank lines (cue boundaries)
        $blocks = preg_split('/\n\s*\n/', trim($content));
        if ($blocks === false) {
            return '';
        }

        $texts = [];
        foreach ($blocks as $block) {
            $block = trim($block);
            if ($block === '') {
                continue;
            }

            $lines = explode("\n", $block);
            $textLines = [];

            foreach ($lines as $line) {
                $line = trim($line);

                // Skip sequence number (line that's just digits)
                if (preg_match('/^\d+$/', $line)) {
                    continue;
                }

                // Skip timecode line (contains -->)
                if (str_contains($line, '-->')) {
                    continue;
                }

                // Keep text content (strip HTML tags)
                if ($line !== '') {
                    $line = strip_tags($line);
                    if ($line !== '') {
                        $textLines[] = $line;
                    }
                }
            }

            if (!empty($textLines)) {
                $texts[] = implode("\n", $textLines);
            }
        }

        return implode("\n\n", $texts);
    }

    /**
     * Parse VTT format content.
     *
     * VTT format:
     * ```
     * WEBVTT
     *
     * 00:00:00.000 --> 00:00:05.000
     * Subtitle text here
     *
     * NOTE
     * This is a comment
     *
     * 00:00:05.100 --> 00:00:10.000
     * Another subtitle
     * ```
     *
     * @param string $content Raw VTT content
     *
     * @return string Extracted text with double newlines between cues
     */
    private function parseVtt(string $content): string
    {
        // Remove WEBVTT header and any metadata before first blank line
        $content = preg_replace('/^WEBVTT[^\n]*\n/', '', trim($content));
        if ($content === null) {
            return '';
        }

        // Split by blank lines
        $blocks = preg_split('/\n\s*\n/', trim($content));
        if ($blocks === false) {
            return '';
        }

        $texts = [];
        foreach ($blocks as $block) {
            $block = trim($block);
            if ($block === '') {
                continue;
            }

            // Skip NOTE blocks (comments)
            if (str_starts_with($block, 'NOTE')) {
                continue;
            }

            // Skip STYLE blocks
            if (str_starts_with($block, 'STYLE')) {
                continue;
            }

            // Skip REGION blocks
            if (str_starts_with($block, 'REGION')) {
                continue;
            }

            $lines = explode("\n", $block);
            $textLines = [];
            $foundTimecode = false;

            foreach ($lines as $line) {
                $line = trim($line);

                // Skip cue identifier (optional line before timecode, no -->)
                if (!$foundTimecode && !str_contains($line, '-->')) {
                    // This might be a cue ID, check if next line has timecode
                    continue;
                }

                // Skip timecode line (contains -->)
                if (str_contains($line, '-->')) {
                    $foundTimecode = true;
                    continue;
                }

                // Keep text content (after timecode)
                if ($foundTimecode && $line !== '') {
                    // Strip VTT styling tags like <b>, <i>, <c.classname>, <v Speaker>
                    $line = $this->stripVttTags($line);
                    if ($line !== '') {
                        $textLines[] = $line;
                    }
                }
            }

            if (!empty($textLines)) {
                $texts[] = implode("\n", $textLines);
            }
        }

        return implode("\n\n", $texts);
    }

    /**
     * Strip VTT inline styling tags.
     *
     * Removes tags like:
     * - <b>, </b>, <i>, </i>, <u>, </u>
     * - <c.classname>
     * - <v Speaker Name>
     * - <lang en>
     *
     * @param string $text Text with potential VTT tags
     *
     * @return string Text with tags removed
     */
    private function stripVttTags(string $text): string
    {
        // Remove VTT-specific tags: <c>, <v>, <lang>, <b>, <i>, <u>, <ruby>, <rt>
        $text = preg_replace('/<\/?(?:c|v|lang|b|i|u|ruby|rt)[^>]*>/', '', $text);
        if ($text === null) {
            return '';
        }

        // Remove any remaining HTML-like tags
        $text = strip_tags($text);

        return $text;
    }

    /**
     * Clean extracted text.
     *
     * - Normalize whitespace
     * - Remove excessive blank lines
     * - Trim lines
     *
     * @param string $text Raw extracted text
     *
     * @return string Cleaned text
     */
    private function cleanText(string $text): string
    {
        // Decode HTML entities
        $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');

        // Normalize spaces (but keep newlines)
        $text = preg_replace('/[^\S\n]+/', ' ', $text);
        if ($text === null) {
            return '';
        }

        // Trim each line
        $lines = explode("\n", $text);
        $lines = array_map('trim', $lines);
        $text = implode("\n", $lines);

        // Remove more than 2 consecutive newlines
        $text = preg_replace('/\n{3,}/', "\n\n", $text);
        if ($text === null) {
            return '';
        }

        return trim($text);
    }

    /**
     * Check if content appears to be valid SRT format.
     *
     * @param string $content File content
     *
     * @return bool True if appears to be valid SRT
     */
    private function isValidSrt(string $content): bool
    {
        // SRT should have at least one timecode with comma milliseconds
        return (bool) preg_match('/\d{2}:\d{2}:\d{2},\d{3}\s*-->\s*\d{2}:\d{2}:\d{2},\d{3}/', $content);
    }

    /**
     * Check if content appears to be valid VTT format.
     *
     * @param string $content File content
     *
     * @return bool True if appears to be valid VTT
     */
    private function isValidVtt(string $content): bool
    {
        // VTT must start with WEBVTT
        return str_starts_with(trim($content), 'WEBVTT');
    }
}
