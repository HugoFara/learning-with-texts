<?php

/**
 * Delete Language Use Case
 *
 * PHP version 8.2
 *
 * @category Lwt
 * @package  Lwt\Modules\Language\Application\UseCases
 * @author   HugoFara <git@hugofara.net>
 * @license  Unlicense <http://unlicense.org/>
 * @link     https://hugofara.github.io/lwt/developer/api
 * @since    3.0.0
 */

declare(strict_types=1);

namespace Lwt\Modules\Language\Application\UseCases;

use Lwt\Shared\Infrastructure\Database\QueryBuilder;

/**
 * Use case for deleting a language with dependency checking.
 *
 * @since 3.0.0
 */
class DeleteLanguage
{
    /**
     * Delete a language.
     *
     * @param int $id Language ID
     *
     * @return array{success: bool, count: int, error: ?string}
     */
    public function execute(int $id): array
    {
        // Check for related data
        $stats = $this->getRelatedDataCounts($id);

        if (
            $stats['texts'] > 0 || $stats['archivedTexts'] > 0 ||
            $stats['words'] > 0 || $stats['feeds'] > 0
        ) {
            return [
                'success' => false,
                'count' => 0,
                'error' => 'You must first delete texts, archived texts, news_feeds and words with this language!'
            ];
        }

        $affected = QueryBuilder::table('languages')
            ->where('LgID', '=', $id)
            ->delete();
        return ['success' => $affected > 0, 'count' => $affected, 'error' => null];
    }

    /**
     * Delete a language by ID (API-friendly version).
     *
     * @param int $id Language ID
     *
     * @return bool True if deleted
     */
    public function deleteById(int $id): bool
    {
        $affected = QueryBuilder::table('languages')
            ->where('LgID', '=', $id)
            ->delete();
        return $affected > 0;
    }

    /**
     * Check if a language can be deleted (no related data).
     *
     * @param int $id Language ID
     *
     * @return bool
     */
    public function canDelete(int $id): bool
    {
        $stats = $this->getRelatedDataCounts($id);
        return $stats['texts'] === 0 &&
               $stats['archivedTexts'] === 0 &&
               $stats['words'] === 0 &&
               $stats['feeds'] === 0;
    }

    /**
     * Get counts of related data for a language.
     *
     * @param int $id Language ID
     *
     * @return array{texts: int, archivedTexts: int, words: int, feeds: int}
     */
    public function getRelatedDataCounts(int $id): array
    {
        return [
            'texts' => QueryBuilder::table('texts')
                ->where('TxLgID', '=', $id)
                ->whereNull('TxArchivedAt')
                ->count(),
            'archivedTexts' => QueryBuilder::table('texts')
                ->where('TxLgID', '=', $id)
                ->whereNotNull('TxArchivedAt')
                ->count(),
            'words' => QueryBuilder::table('words')
                ->where('WoLgID', '=', $id)
                ->count(),
            'feeds' => QueryBuilder::table('news_feeds')
                ->where('NfLgID', '=', $id)
                ->count(),
        ];
    }
}
