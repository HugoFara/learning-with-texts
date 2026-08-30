<?php

/**
 * List Languages Use Case
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
use Lwt\Modules\Language\Domain\LanguageRepositoryInterface;
use Lwt\Modules\Language\Infrastructure\MySqlLanguageRepository;

/**
 * Use case for listing languages with various formats and statistics.
 *
 * @since 3.0.0
 */
class ListLanguages
{
    private LanguageRepositoryInterface $repository;

    /**
     * @param LanguageRepositoryInterface|null $repository Repository instance
     */
    public function __construct(?LanguageRepositoryInterface $repository = null)
    {
        $this->repository = $repository ?? new MySqlLanguageRepository();
    }

    /**
     * Get all languages as a name => id dictionary.
     *
     * @return array<string, int>
     */
    public function getAllLanguages(): array
    {
        return $this->repository->getAllAsDict();
    }

    /**
     * Get languages formatted for select dropdown options.
     *
     * @param int $maxNameLength Maximum name length before truncation
     *
     * @return array<int, array{id: int, name: string}>
     */
    public function getLanguagesForSelect(int $maxNameLength = 30): array
    {
        return $this->repository->getForSelect($maxNameLength);
    }

    /**
     * Get languages with statistics for display.
     *
     * @return array Language data with counts
     */
    public function getLanguagesWithStats(): array
    {
        // Get base language data
        $records = QueryBuilder::table('languages')
            ->select(['LgID', 'LgName', 'LgExportTemplate'])
            ->where('LgName', '<>', '')
            ->orderBy('LgName')
            ->getPrepared();

        // Get feed counts
        $feedCounts = $this->getFeedCounts();
        $articleCounts = $this->getArticleCounts();

        $languages = [];
        foreach ($records as $record) {
            $lid = (int)$record['LgID'];
            $stats = $this->getRelatedDataCounts($lid);

            $languages[] = [
                'id' => $lid,
                'name' => $record['LgName'],
                'hasExportTemplate' => !empty($record['LgExportTemplate']),
                'textCount' => $stats['texts'],
                'archivedTextCount' => $stats['archivedTexts'],
                'wordCount' => $stats['words'],
                'feedCount' => $feedCounts[$lid] ?? 0,
                'articleCount' => $articleCounts[$lid] ?? 0,
            ];
        }

        return $languages;
    }

    /**
     * Get languages that have at least one text, with text counts.
     *
     * @return array<int, array{id: int, name: string, text_count: int}>
     */
    public function getLanguagesWithTextCounts(): array
    {
        $records = QueryBuilder::table('languages')
            ->select(['languages.LgID', 'languages.LgName', 'COUNT(texts.TxID) AS text_count'])
            ->join('texts', 'texts.TxLgID', '=', 'languages.LgID')
            ->where('languages.LgName', '<>', '')
            ->groupBy(['languages.LgID', 'languages.LgName'])
            ->orderBy('languages.LgName')
            ->getPrepared();

        $result = [];
        foreach ($records as $record) {
            $result[] = [
                'id' => (int)$record['LgID'],
                'name' => (string)$record['LgName'],
                'text_count' => (int)$record['text_count']
            ];
        }
        return $result;
    }

    /**
     * Get languages that have at least one archived text, with archived text counts.
     *
     * @return array<int, array{id: int, name: string, text_count: int}>
     */
    public function getLanguagesWithArchivedTextCounts(): array
    {
        $records = QueryBuilder::table('languages')
            ->select(['languages.LgID', 'languages.LgName', 'COUNT(texts.TxID) AS text_count'])
            ->join('texts', 'texts.TxLgID', '=', 'languages.LgID')
            ->where('languages.LgName', '<>', '')
            ->whereNotNull('texts.TxArchivedAt')
            ->groupBy(['languages.LgID', 'languages.LgName'])
            ->orderBy('languages.LgName')
            ->getPrepared();

        $result = [];
        foreach ($records as $record) {
            $result[] = [
                'id' => (int)$record['LgID'],
                'name' => (string)$record['LgName'],
                'text_count' => (int)$record['text_count']
            ];
        }
        return $result;
    }

    /**
     * Get counts of related data for a language.
     *
     * @param int $lid Language ID
     *
     * @return array{texts: int, archivedTexts: int, words: int, feeds: int}
     */
    public function getRelatedDataCounts(int $lid): array
    {
        return [
            'texts' => QueryBuilder::table('texts')
                ->where('TxLgID', '=', $lid)
                ->whereNull('TxArchivedAt')
                ->count(),
            'archivedTexts' => QueryBuilder::table('texts')
                ->where('TxLgID', '=', $lid)
                ->whereNotNull('TxArchivedAt')
                ->count(),
            'words' => QueryBuilder::table('words')
                ->where('WoLgID', '=', $lid)
                ->count(),
            'feeds' => QueryBuilder::table('news_feeds')
                ->where('NfLgID', '=', $lid)
                ->count(),
        ];
    }

    /**
     * Get feed counts per language.
     *
     * @return array<int, int> Language ID => feed count
     */
    private function getFeedCounts(): array
    {
        $records = QueryBuilder::table('news_feeds')
            ->select(['NfLgID', 'COUNT(*) as value'])
            ->groupBy('NfLgID')
            ->getPrepared();
        $counts = [];
        foreach ($records as $record) {
            $counts[(int)$record['NfLgID']] = (int)$record['value'];
        }
        return $counts;
    }

    /**
     * Get article counts per language.
     *
     * @return array<int, int> Language ID => article count
     */
    private function getArticleCounts(): array
    {
        $records = QueryBuilder::table('news_feeds')
            ->selectRaw('news_feeds.NfLgID, COUNT(*) AS article_count')
            ->join('feed_links', 'news_feeds.NfID', '=', 'feed_links.FlNfID')
            ->groupBy('news_feeds.NfLgID')
            ->getPrepared();
        $counts = [];
        foreach ($records as $record) {
            $counts[(int)$record['NfLgID']] = (int)$record['article_count'];
        }
        return $counts;
    }
}
