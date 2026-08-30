<?php

/**
 * Home Facade
 *
 * PHP version 8.2
 *
 * @category Lwt
 * @package  Lwt\Modules\Home\Application
 * @author   HugoFara <git@hugofara.net>
 * @license  Unlicense <http://unlicense.org/>
 * @link     https://hugofara.github.io/lwt/developer/api
 * @since    3.0.0
 */

declare(strict_types=1);

namespace Lwt\Modules\Home\Application;

use Lwt\Shared\Infrastructure\Globals;
use Lwt\Shared\Infrastructure\Database\Connection;
use Lwt\Modules\Home\Application\UseCases\GetDashboardData;
use Lwt\Modules\Home\Application\UseCases\GetTextStatistics;

/**
 * Facade providing unified interface to Home module.
 *
 * @since 3.0.0
 */
class HomeFacade
{
    private GetDashboardData $getDashboardData;
    private GetTextStatistics $getTextStatistics;

    /**
     * Constructor.
     */
    public function __construct()
    {
        $this->getDashboardData = new GetDashboardData();
        $this->getTextStatistics = new GetTextStatistics();
    }

    /**
     * Get dashboard data for the home page.
     *
     * @return array{
     *   language_count: int,
     *   current_language_id: int|null,
     *   current_language_text_count: int,
     *   current_text_id: int|null,
     *   current_text_info: array|null,
     *   is_wordpress: bool,
     *   is_multi_user: bool
     * }
     */
    public function getDashboardData(): array
    {
        return $this->getDashboardData->execute();
    }

    /**
     * Get current text info with statistics for Alpine.js display.
     *
     * @param int|null   $textId   Current text ID
     * @param array|null $textInfo Text info from dashboard data
     *
     * @return array|null Text info with statistics, or null
     */
    public function getLastTextInfo(?int $textId, ?array $textInfo): ?array
    {
        if ($textId === null || $textInfo === null) {
            return null;
        }

        return $this->getTextStatistics->execute($textId, $textInfo);
    }

    /**
     * Get database size in MB for the current table set.
     *
     * @return float Database size in MB
     */
    public function getDatabaseSize(): float
    {
        $dbname = Globals::getDatabaseName();

        // Get the prefixed table names for all LWT tables
        $tableNames = [
            'feed_links', 'languages', 'news_feeds', 'sentences', 'settings',
            'tags', 'text_tags', 'word_occurrences', 'texts', 'text_tag_map',
            'words', 'word_tag_map'
        ];

        $placeholders = implode(', ', array_fill(0, count($tableNames), '?'));
        $bindings = array_merge([$dbname], $tableNames);

        /** @var mixed $sizeRaw */
        $sizeRaw = Connection::preparedFetchValue(
            "SELECT ROUND(SUM(data_length+index_length)/1024/1024, 1) AS size_mb
            FROM information_schema.TABLES
            WHERE table_schema = ?
            AND table_name IN ($placeholders)",
            $bindings,
            'size_mb'
        );

        return is_numeric($sizeRaw) ? (float)$sizeRaw : 0.0;
    }

    /**
     * Get the current text ID from settings.
     *
     * @return int|null Current text ID or null if not set
     */
    public function getCurrentTextId(): ?int
    {
        $dashboardData = $this->getDashboardData();
        return $dashboardData['current_text_id'];
    }

    /**
     * Get the current language ID from settings.
     *
     * @return int|null Current language ID or null if not set
     */
    public function getCurrentLanguageId(): ?int
    {
        $dashboardData = $this->getDashboardData();
        return $dashboardData['current_language_id'];
    }

    /**
     * Get the count of languages in the database.
     *
     * @return int Number of languages
     */
    public function getLanguageCount(): int
    {
        $dashboardData = $this->getDashboardData();
        return $dashboardData['language_count'];
    }

    /**
     * Get language name by ID.
     *
     * @param int $languageId Language ID
     *
     * @return string Language name or empty string if not found
     */
    public function getLanguageName(int $languageId): string
    {
        /** @var mixed $resultRaw */
        $resultRaw = \Lwt\Shared\Infrastructure\Database\QueryBuilder::table('languages')
            ->where('LgID', '=', $languageId)
            ->valuePrepared('LgName');

        return is_string($resultRaw) ? $resultRaw : '';
    }
}
