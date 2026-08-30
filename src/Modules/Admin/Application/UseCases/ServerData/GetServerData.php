<?php

/**
 * Get Server Data Use Case
 *
 * PHP version 8.2
 *
 * @category Lwt
 * @package  Lwt\Modules\Admin\Application\UseCases\ServerData
 * @author   HugoFara <git@hugofara.net>
 * @license  Unlicense <http://unlicense.org/>
 * @link     https://hugofara.github.io/lwt/developer/api
 * @since    3.0.0
 */

declare(strict_types=1);

namespace Lwt\Modules\Admin\Application\UseCases\ServerData;

use Lwt\Shared\Infrastructure\ApplicationInfo;
use Lwt\Shared\Infrastructure\Globals;
use Lwt\Shared\Infrastructure\Database\Connection;
use Lwt\Shared\Infrastructure\Database\Migrations;
use Lwt\Shared\Infrastructure\Http\UrlUtilities;

/**
 * Use case for getting server and database information.
 *
 * @since 3.0.0
 */
class GetServerData
{
    private string $serverSoftware;

    /**
     * Constructor.
     *
     * @param string $serverSoftware Server software string (e.g. from $_SERVER['SERVER_SOFTWARE'])
     */
    public function __construct(string $serverSoftware = 'Unknown')
    {
        $this->serverSoftware = $serverSoftware;
    }

    /**
     * Execute the use case.
     *
     * @return array{
     *   db_name: string,
     *   db_size: float,
     *   server_soft: string,
     *   apache: string,
     *   php: string|false,
     *   mysql: string,
     *   lwt_version: string,
     *   server_location: string,
     *   failed_migrations: array<array{filename: string, attempts: int, error: string}>,
     *   missing_foreign_keys: array<array{name: string, table: string}>
     * }
     */
    public function execute(): array
    {
        return [
            'db_name' => Globals::getDatabaseName(),
            'db_size' => $this->getDatabaseSize(),
            'server_soft' => $this->serverSoftware,
            'apache' => $this->parseApacheVersion($this->serverSoftware),
            'php' => phpversion(),
            'mysql' => (string) Connection::fetchValue("SELECT VERSION() AS version", 'version'),
            'lwt_version' => ApplicationInfo::getVersionNumber(),
            'server_location' => UrlUtilities::getAppOrigin(),
            // A migration that did not run through leaves the schema
            // incomplete, which only shows up later as "table doesn't exist"
            // errors in whichever feature needed it (issue #247).
            'failed_migrations' => Migrations::getFailedMigrations(),
            // Constraints the schema declares but the database does not have:
            // writes that should be refused are being accepted (issue #273).
            'missing_foreign_keys' => Migrations::getMissingForeignKeys(),
        ];
    }

    /**
     * Get database size in MB.
     *
     * @return float Database size in MB
     */
    private function getDatabaseSize(): float
    {
        $dbname = Globals::getDatabaseName();

        $tableNames = [
            'feed_links', 'languages', 'news_feeds', 'sentences', 'settings',
            'tags', 'text_tags', 'word_occurrences', 'texts', 'text_tag_map',
            'words', 'word_tag_map'
        ];

        $placeholders = implode(', ', array_fill(0, count($tableNames), '?'));
        $bindings = array_merge([$dbname], $tableNames);

        /** @var float|int|string|null $temp_size */
        $temp_size = Connection::preparedFetchValue(
            "SELECT ROUND(SUM(data_length+index_length)/1024/1024, 1) AS size_mb
            FROM information_schema.TABLES
            WHERE table_schema = ?
            AND table_name IN ($placeholders)",
            $bindings,
            'size_mb'
        );

        if ($temp_size === null) {
            return 0.0;
        }

        return (float) $temp_size;
    }

    /**
     * Parse Apache version from server software string.
     *
     * @param string $serverSoft Server software string
     *
     * @return string Apache version string
     */
    private function parseApacheVersion(string $serverSoft): string
    {
        if (str_starts_with($serverSoft, "Apache/")) {
            $temp_soft = explode(' ', $serverSoft);
            return $temp_soft[0];
        }

        return "Apache/?";
    }
}
