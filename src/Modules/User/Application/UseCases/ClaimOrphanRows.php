<?php

/**
 * Claim Orphan Rows Use Case
 *
 * PHP version 8.2
 *
 * @category Lwt
 * @package  Lwt\Modules\User\Application\UseCases
 * @author   HugoFara <git@hugofara.net>
 * @license  Unlicense <http://unlicense.org/>
 * @link     https://hugofara.github.io/lwt/developer/api
 * @since    3.1.2-fork
 */

declare(strict_types=1);

namespace Lwt\Modules\User\Application\UseCases;

use Lwt\Shared\Infrastructure\Database\Connection;
use RuntimeException;

/**
 * Claim every NULL-owner data row across the schema for the given user.
 *
 * When an existing single-user install is migrated to multi-user mode by
 * flipping `MULTI_USER_ENABLED=true` in `.env`, the
 * `add_user_id_columns.sql` migration's backfill is a no-op (it looks for
 * a `users.UsUsername='admin'` row that doesn't exist on a fresh users
 * table). Every legacy row stays at `LgUsID/TxUsID/WoUsID = NULL`, and
 * once user-scope filters kick in those rows become invisible to
 * everyone — looks like total data loss.
 *
 * The first user to register on such an install is auto-promoted to
 * admin (see {@see Register::execute()}). At that exact moment we know
 * there is exactly one operator in charge and that the orphan rows
 * belong to them, so we re-run the backfill against their UsID. On
 * already-migrated installs the UPDATEs match zero rows and cost
 * nothing.
 *
 * @since 3.1.2-fork
 */
class ClaimOrphanRows
{
    /**
     * Tables and their owner-id columns that need backfilling. Order is
     * irrelevant — every UPDATE is independent.
     *
     * @var array<string, string>
     */
    private const TABLES = [
        'languages'           => 'LgUsID',
        'texts'               => 'TxUsID',
        'words'               => 'WoUsID',
        'tags'                => 'TgUsID',
        'text_tags'           => 'T2UsID',
        'news_feeds'          => 'NfUsID',
        'books'               => 'BkUsID',
        'local_dictionaries'  => 'LdUsID',
    ];

    /**
     * Reassign every NULL-owner row in the multi-user data tables to
     * `$userId`.
     *
     * @param int $userId UsID of the new owner (must already exist in
     *                    `users`).
     *
     * @return array<string, int> Per-table count of rows reassigned.
     *                            Tables that don't exist in this schema
     *                            version are silently omitted.
     */
    public function execute(int $userId): array
    {
        $claimed = [];
        foreach (self::TABLES as $table => $column) {
            try {
                $rows = Connection::preparedExecute(
                    "UPDATE `{$table}` SET `{$column}` = ? WHERE `{$column}` IS NULL",
                    [$userId]
                );
            } catch (RuntimeException $e) {
                // Table or column may be absent on older schemas; skip.
                continue;
            }
            if ($rows > 0) {
                $claimed[$table] = $rows;
            }
        }
        return $claimed;
    }
}
