<?php

/**
 * List Texts Use Case
 *
 * PHP version 8.2
 *
 * @category Lwt
 * @package  Lwt\Modules\Text\Application\UseCases
 * @author   HugoFara <git@hugofara.net>
 * @license  Unlicense <http://unlicense.org/>
 * @link     https://hugofara.github.io/lwt/developer/api
 * @since    3.0.0
 */

declare(strict_types=1);

namespace Lwt\Modules\Text\Application\UseCases;

use Lwt\Shared\Infrastructure\Database\Connection;
use Lwt\Shared\Infrastructure\Database\Settings;
use Lwt\Shared\Infrastructure\Database\UserScopedQuery;
use Lwt\Modules\Text\Domain\TextRepositoryInterface;

/**
 * Use case for listing texts with filtering and pagination.
 *
 * Handles both active and archived text listing with support for
 * language filtering, search queries, tag filters, and pagination.
 *
 * @since 3.0.0
 */
class ListTexts
{
    private TextRepositoryInterface $textRepository;

    /**
     * Constructor.
     *
     * @param TextRepositoryInterface $textRepository Text repository
     */
    public function __construct(TextRepositoryInterface $textRepository)
    {
        $this->textRepository = $textRepository;
    }

    /**
     * Get texts per page setting.
     *
     * @return int Items per page
     */
    public function getTextsPerPage(): int
    {
        return (int) Settings::getWithDefault('set-texts-per-page');
    }

    /**
     * Get archived texts per page setting.
     *
     * @return int Items per page
     */
    public function getArchivedTextsPerPage(): int
    {
        return (int) Settings::getWithDefault('set-archived_texts-per-page');
    }

    /**
     * Calculate pagination info.
     *
     * @param int $totalCount  Total number of items
     * @param int $currentPage Current page number
     * @param int $perPage     Items per page
     *
     * @return array{pages: int, currentPage: int, limit: string}
     */
    public function getPagination(int $totalCount, int $currentPage, int $perPage): array
    {
        $pages = $totalCount === 0 ? 0 : (int) ceil($totalCount / $perPage);

        if ($currentPage < 1) {
            $currentPage = 1;
        }
        if ($currentPage > $pages && $pages > 0) {
            $currentPage = $pages;
        }

        $offset = ($currentPage - 1) * $perPage;
        $limit = "LIMIT {$offset},{$perPage}";

        return [
            'pages' => $pages,
            'currentPage' => $currentPage,
            'limit' => $limit
        ];
    }

    /**
     * Get count of active texts matching filters.
     *
     * @param string $whLang  Language WHERE clause (with ? placeholders)
     * @param string $whQuery Query WHERE clause (with ? placeholders)
     * @param string $whTag   Tag HAVING clause
     * @param array  $params  Merged binding parameters for whLang + whQuery + whTag
     *
     * @return int Number of matching texts
     */
    public function getTextCount(
        string $whLang,
        string $whQuery,
        string $whTag,
        array $params = []
    ): int {
        $bindings = $params;
        $textScope = UserScopedQuery::forTablePrepared('texts', $bindings);
        $sql = "SELECT COUNT(*) AS cnt FROM (
            SELECT TxID FROM (
                texts
                LEFT JOIN text_tag_map ON TxID = TtTxID
            ) WHERE TxArchivedAt IS NULL {$whLang}{$whQuery}{$textScope}
            GROUP BY TxID {$whTag}
        ) AS dummy";
        return (int) Connection::preparedFetchValue($sql, $bindings, 'cnt');
    }

    /**
     * Get active texts list with pagination.
     *
     * @param string $whLang  Language WHERE clause (with ? placeholders)
     * @param string $whQuery Query WHERE clause (with ? placeholders)
     * @param string $whTag   Tag HAVING clause
     * @param int    $sort    Sort index (1-based)
     * @param int    $page    Page number (1-based)
     * @param int    $perPage Items per page
     * @param array  $params  Merged binding parameters for whLang + whQuery + whTag
     *
     * @return array Array of text records
     */
    public function getTextsList(
        string $whLang,
        string $whQuery,
        string $whTag,
        int $sort,
        int $page,
        int $perPage,
        array $params = []
    ): array {
        $sorts = ['TxTitle', 'TxID desc', 'TxID'];
        $sortColumn = $sorts[max(0, min($sort - 1, count($sorts) - 1))];
        $offset = ($page - 1) * $perPage;

        // text_tags is LEFT JOINed; its scope must live in the ON clause so
        // untagged texts survive the join. Its binding therefore comes first
        // in the SQL and must be at the head of $bindings.
        $tagJoinBindings = [];
        $tagJoinScope = UserScopedQuery::forTablePrepared('text_tags', $tagJoinBindings);

        $bindings = array_merge($tagJoinBindings, $params);
        $textScope = UserScopedQuery::forTablePrepared('texts', $bindings);
        $langScope = UserScopedQuery::forTablePrepared('languages', $bindings);
        $bindings[] = $offset;
        $bindings[] = $perPage;

        $sql = "SELECT TxID, TxTitle, LgName, TxAudioURI, TxSourceURI,
            LENGTH(TxAnnotatedText) AS annotlen,
            (SELECT COUNT(*) FROM sentences WHERE SeTxID = TxID) AS sentnum,
            IFNULL(GROUP_CONCAT(DISTINCT T2Text ORDER BY T2Text SEPARATOR ','), '') AS taglist
            FROM (
                (texts
                LEFT JOIN text_tag_map ON TxID = TtTxID)
                LEFT JOIN text_tags ON T2ID = TtT2ID{$tagJoinScope}
            ), languages
            WHERE LgID=TxLgID AND TxArchivedAt IS NULL {$whLang}{$whQuery}{$textScope}{$langScope}
            GROUP BY TxID {$whTag}
            ORDER BY {$sortColumn}
            LIMIT ?, ?";

        return Connection::preparedFetchAll($sql, $bindings);
    }

    /**
     * Get count of archived texts matching filters.
     *
     * @param string $whLang  Language WHERE clause (with ? placeholders)
     * @param string $whQuery Query WHERE clause (with ? placeholders)
     * @param string $whTag   Tag HAVING clause
     * @param array  $params  Merged binding parameters for whLang + whQuery + whTag
     *
     * @return int Number of matching archived texts
     */
    public function getArchivedTextCount(
        string $whLang,
        string $whQuery,
        string $whTag,
        array $params = []
    ): int {
        $bindings = $params;
        $textScope = UserScopedQuery::forTablePrepared('texts', $bindings);
        $sql = "SELECT COUNT(*) AS cnt FROM (
            SELECT TxID FROM (
                texts
                LEFT JOIN text_tag_map ON TxID = TtTxID
            ) WHERE TxArchivedAt IS NOT NULL {$whLang}{$whQuery}{$textScope}
            GROUP BY TxID {$whTag}
        ) AS dummy";
        return (int) Connection::preparedFetchValue($sql, $bindings, 'cnt');
    }

    /**
     * Get archived texts list with pagination.
     *
     * @param string $whLang  Language WHERE clause (with ? placeholders)
     * @param string $whQuery Query WHERE clause (with ? placeholders)
     * @param string $whTag   Tag HAVING clause
     * @param int    $sort    Sort index (1-based)
     * @param int    $page    Page number (1-based)
     * @param int    $perPage Items per page
     * @param array  $params  Merged binding parameters for whLang + whQuery + whTag
     *
     * @return array Array of archived text records
     */
    public function getArchivedTextsList(
        string $whLang,
        string $whQuery,
        string $whTag,
        int $sort,
        int $page,
        int $perPage,
        array $params = []
    ): array {
        $sorts = ['TxTitle', 'TxID desc', 'TxID'];
        $sortColumn = $sorts[max(0, min($sort - 1, count($sorts) - 1))];
        $offset = ($page - 1) * $perPage;

        // See getTextsList for the binding-order rationale.
        $tagJoinBindings = [];
        $tagJoinScope = UserScopedQuery::forTablePrepared('text_tags', $tagJoinBindings);

        $bindings = array_merge($tagJoinBindings, $params);
        $textScope = UserScopedQuery::forTablePrepared('texts', $bindings);
        $langScope = UserScopedQuery::forTablePrepared('languages', $bindings);
        $bindings[] = $offset;
        $bindings[] = $perPage;

        $sql = "SELECT TxID, TxTitle, LgName, TxAudioURI, TxSourceURI,
            LENGTH(TxAnnotatedText) AS annotlen,
            IFNULL(GROUP_CONCAT(DISTINCT T2Text ORDER BY T2Text SEPARATOR ','), '') AS taglist
            FROM (
                (texts
                LEFT JOIN text_tag_map ON TxID = TtTxID)
                LEFT JOIN text_tags ON T2ID = TtT2ID{$tagJoinScope}
            ), languages
            WHERE LgID=TxLgID AND TxArchivedAt IS NOT NULL {$whLang}{$whQuery}{$textScope}{$langScope}
            GROUP BY TxID {$whTag}
            ORDER BY {$sortColumn}
            LIMIT ?, ?";

        return Connection::preparedFetchAll($sql, $bindings);
    }

    /**
     * Get texts for a specific language with pagination.
     *
     * @param int $languageId Language ID
     * @param int $page       Page number
     * @param int $perPage    Items per page
     *
     * @return array{items: array, total: int, page: int, per_page: int, total_pages: int}
     */
    public function getTextsForLanguage(int $languageId, int $page = 1, int $perPage = 20): array
    {
        return $this->textRepository->findPaginated($languageId, $page, $perPage);
    }
}
