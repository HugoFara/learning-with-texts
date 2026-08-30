<?php

/**
 * Global Digital Library Import Service
 *
 * Turns a GDL ePUB URL into reading text: downloads the ePUB via GdlClient,
 * extracts plain text with the Book module's EPUB parser, and rejects books
 * with too little readable text (GDL hosts many image-only picture books).
 *
 * PHP version 8.2
 *
 * @category Lwt
 * @package  Lwt\Modules\Text\Application\Services
 * @author   HugoFara <git@hugofara.net>
 * @license  Unlicense <http://unlicense.org/>
 * @link     https://hugofara.github.io/lwt/developer/api
 * @since    3.1.0
 */

declare(strict_types=1);

namespace Lwt\Modules\Text\Application\Services;

use Lwt\Modules\Book\Application\Services\EpubParserService;
use Lwt\Shared\Infrastructure\Http\GdlClient;

/**
 * Downloads and extracts reading text from Global Digital Library ePUBs.
 *
 * @since 3.1.0
 */
class GdlImportService
{
    /**
     * Minimum word count for a book to be importable.
     *
     * Many GDL titles are image-only picture books whose ePUB carries almost
     * no extractable text; importing those yields an empty reading text. The
     * threshold is deliberately low — a few short sentences — so genuine
     * beginner readers still pass while pure picture books are rejected.
     */
    private const MIN_WORDS = 30;

    private GdlClient $client;
    private EpubParserService $epubParser;

    public function __construct(?GdlClient $client = null, ?EpubParserService $epubParser = null)
    {
        $this->client = $client ?? new GdlClient();
        $this->epubParser = $epubParser ?? new EpubParserService();
    }

    /**
     * Download a GDL ePUB and extract its reading text.
     *
     * @param string $epubUrl ePUB URL from a GdlClient search result
     *
     * @return array{title: string, text: string, sourceUri: string}|array{error: string}
     */
    public function extractText(string $epubUrl): array
    {
        $bytes = $this->client->fetchEpub($epubUrl);
        if ($bytes === null) {
            return ['error' => 'Could not download this book from the Global Digital Library.'];
        }

        try {
            $parsed = $this->parseEpub($bytes);
        } catch (\Throwable $e) {
            return ['error' => 'This book could not be read as a valid EPUB.'];
        }

        $words = $this->wordCount($parsed['text']);
        if ($words < self::MIN_WORDS) {
            return [
                'error' => sprintf(
                    'This book has too little readable text (%d %s) — '
                    . 'it is likely an image-only picture book.',
                    $words,
                    $words === 1 ? 'word' : 'words'
                ),
            ];
        }

        return [
            'title' => $parsed['title'],
            'text' => $parsed['text'],
            'sourceUri' => $epubUrl,
        ];
    }

    /**
     * Buffer ePUB bytes to disk and parse them into title + text.
     *
     * Isolated as a seam so the download/filter orchestration can be tested
     * without a real ePUB or the zip extension.
     *
     * @param string $bytes Raw ePUB bytes
     *
     * @return array{title: string, text: string}
     *
     * @throws \RuntimeException If the bytes cannot be buffered or parsed
     */
    protected function parseEpub(string $bytes): array
    {
        $tmp = tempnam(sys_get_temp_dir(), 'lwt_gdl_') . '.epub';
        if (@file_put_contents($tmp, $bytes, LOCK_EX) === false) {
            @unlink($tmp);
            throw new \RuntimeException('Could not buffer the EPUB to disk.');
        }

        try {
            $parsed = $this->epubParser->parse($tmp);
            return [
                'title' => $parsed['metadata']['title'] ?? '',
                'text' => $this->buildText($parsed['chapters']),
            ];
        } finally {
            @unlink($tmp);
        }
    }

    /**
     * Concatenate chapter contents into a single reading text.
     *
     * @param array<array{num?: int, title?: string, content?: string}> $chapters
     *
     * @return string Blank-line-separated chapter text
     */
    protected function buildText(array $chapters): string
    {
        $parts = [];
        foreach ($chapters as $chapter) {
            $content = trim($chapter['content'] ?? '');
            if ($content !== '') {
                $parts[] = $content;
            }
        }

        return trim(implode("\n\n", $parts));
    }

    /**
     * Count whitespace-separated words in a text.
     *
     * @param string $text Text to measure
     *
     * @return int Word count (0 for empty/whitespace-only text)
     */
    private function wordCount(string $text): int
    {
        $trimmed = trim($text);
        if ($trimmed === '') {
            return 0;
        }

        $words = preg_split('/\s+/u', $trimmed);
        return $words === false ? 0 : count($words);
    }
}
