<?php

declare(strict_types=1);

namespace Lwt\Modules\Vocabulary\Application\Services;

use Lwt\Shared\Infrastructure\Database\Connection;
use Lwt\Shared\Infrastructure\Database\DB;
use Lwt\Shared\Infrastructure\Database\UserScopedQuery;

/**
 * Fetches word frequency lists from the FrequencyWords project and
 * bulk-imports them as starter vocabulary for a language.
 *
 * @see https://github.com/hermitdave/FrequencyWords
 */
class FrequencyImportService
{
    private const BASE_URL =
        'https://raw.githubusercontent.com/hermitdave/FrequencyWords/master/content/2018';

    private const BATCH_SIZE = 500;

    private const FETCH_TIMEOUT = 30;

    /**
     * Check if frequency data is available for a language.
     */
    public function isAvailableForLanguage(string $languageName): bool
    {
        return FrequencyLanguageMap::isSupported($languageName);
    }

    /**
     * Fetch the frequency word list from GitHub.
     *
     * @return list<string> Words in frequency order (most common first)
     *
     * @throws \RuntimeException On network failure
     */
    public function fetchFrequencyList(string $languageName): array
    {
        $freqCode = FrequencyLanguageMap::getFrequencyCode($languageName);
        if ($freqCode === null) {
            throw new \RuntimeException(
                "Frequency data not available for language: $languageName"
            );
        }

        $url = self::BASE_URL . "/$freqCode/{$freqCode}_50k.txt";

        $context = stream_context_create([
            'http' => [
                'timeout' => self::FETCH_TIMEOUT,
                'user_agent' => 'LWT/3.0 (Learning with Texts)',
            ],
        ]);

        $content = @file_get_contents($url, false, $context);
        if ($content === false) {
            throw new \RuntimeException(
                "Could not fetch frequency list for $languageName. " .
                "Check your internet connection."
            );
        }

        return $this->parseFrequencyList($content);
    }

    /**
     * Import top-N frequency words into the words table.
     *
     * Words are inserted with status=1 and empty translation,
     * ready for later enrichment.
     *
     * @return array{imported: int, skipped: int, total: int}
     */
    public function importWords(int $langId, string $languageName, int $count): array
    {
        $words = $this->fetchFrequencyList($languageName);
        $words = array_slice($words, 0, $count);
        $total = count($words);

        if ($total === 0) {
            return ['imported' => 0, 'skipped' => 0, 'total' => 0];
        }

        $userId = UserScopedQuery::getUserIdForInsert('words');

        DB::beginTransaction();
        try {
            $inserted = 0;
            $batches = array_chunk($words, self::BATCH_SIZE);

            foreach ($batches as $batch) {
                $inserted += $this->insertBatch($batch, $langId, $userId);
            }

            DB::commit();
        } catch (\Throwable $e) {
            DB::rollback();
            throw $e;
        }

        $skipped = $total - $inserted;

        return ['imported' => $inserted, 'skipped' => $skipped, 'total' => $total];
    }

    /**
     * Parse the FrequencyWords text format.
     *
     * Each line is: "word frequency\n" (space-delimited).
     *
     * @return list<string> Words only, in frequency order
     */
    private function parseFrequencyList(string $content): array
    {
        $words = [];
        $lines = explode("\n", $content);

        foreach ($lines as $line) {
            $line = trim($line);
            if ($line === '') {
                continue;
            }

            // Format: "word frequency" — split on last space
            $lastSpace = strrpos($line, ' ');
            if ($lastSpace === false) {
                // No space: the whole line is the word
                $words[] = $line;
                continue;
            }

            $word = substr($line, 0, $lastSpace);
            $word = trim($word);
            if ($word !== '') {
                $words[] = $word;
            }
        }

        return $words;
    }

    /**
     * Insert a batch of words using INSERT IGNORE with prepared statements.
     *
     * @param list<string> $words  Words to insert
     * @param int          $langId Language ID
     * @param int|null     $userId User ID for multi-user mode
     *
     * @return int Number of rows actually inserted
     */
    private function insertBatch(array $words, int $langId, ?int $userId): int
    {
        if (empty($words)) {
            return 0;
        }

        $rowPlaceholder = '(?, ?, ?, ?, NOW()'
            . ($userId !== null ? ', ?' : '')
            . ')';

        $placeholders = array_fill(0, count($words), $rowPlaceholder);

        /** @var list<int|string> $params */
        $params = [];
        foreach ($words as $word) {
            $params[] = $word;                    // WoText
            $params[] = mb_strtolower($word);     // WoTextLC
            $params[] = $langId;                  // WoLgID
            $params[] = 1;                        // WoStatus
            if ($userId !== null) {
                $params[] = $userId;
            }
        }

        $sql = "INSERT IGNORE INTO words (
                WoText, WoTextLC, WoLgID, WoStatus, WoStatusChanged"
            . UserScopedQuery::insertColumn('words')
            . ") VALUES " . implode(',', $placeholders);

        return Connection::preparedExecute($sql, $params);
    }
}
