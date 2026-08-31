<?php

/**
 * \file
 * \brief Database restore operations.
 *
 * This file contains functions for restoring database backups
 * and truncating user data.
 *
 * PHP version 8.2
 *
 * @category Lwt
 * @package  Lwt\Database
 * @author   HugoFara <git@hugofara.net>
 * @license  Unlicense <http://unlicense.org/>
 * @link     https://hugofara.github.io/lwt/developer/api
 * @since    3.0.0 Split from database_operations.php
 */

declare(strict_types=1);

namespace Lwt\Shared\Infrastructure\Database;

use Lwt\Shared\Infrastructure\Globals;
use Lwt\Modules\Tags\Application\TagsFacade;
use Lwt\Shared\Infrastructure\Database\SqlValidator;

/**
 * Database restore and truncation operations.
 *
 * @since 3.0.0
 */
class Restore
{
    /**
     * Hard cap on the total decompressed bytes read from a restore
     * upload. The gzopen handle reads transparently; a tiny gzip can
     * expand to many gigabytes (gzip-bomb). 1 GB of SQL covers any
     * plausible legitimate LWT backup with margin while keeping a
     * worker from OOMing on a hostile file.
     */
    public const MAX_DECOMPRESSED_BYTES = 1024 * 1024 * 1024;

    /**
     * Drop all LWT tables to prepare for a clean restore.
     *
     * This is needed to ensure migrations run on a clean slate
     * and don't fail due to partial state from previous attempts.
     *
     * @return void
     */
    private static function dropAllLwtTables(): void
    {
        $dbname = Globals::getDatabaseName();

        // First drop all foreign keys to avoid dependency issues
        Migrations::dropAllForeignKeys();

        // Get all tables in the database
        $tables = Connection::preparedFetchAll(
            "SELECT TABLE_NAME FROM INFORMATION_SCHEMA.TABLES
             WHERE TABLE_SCHEMA = ? AND TABLE_TYPE = 'BASE TABLE'",
            [$dbname]
        );

        // Disable FK checks while dropping
        Connection::execute("SET FOREIGN_KEY_CHECKS = 0");
        try {
            foreach ($tables as $table) {
                if (!isset($table['TABLE_NAME']) || !is_string($table['TABLE_NAME'])) {
                    continue;
                }
                $escapedTable = '`' . str_replace('`', '``', $table['TABLE_NAME']) . '`';
                try {
                    Connection::execute("DROP TABLE IF EXISTS $escapedTable");
                } catch (\RuntimeException $e) {
                    // Ignore errors, table might already be gone
                }
            }
        } finally {
            Connection::execute("SET FOREIGN_KEY_CHECKS = 1");
        }
    }

    /**
     * Read a backup stream and split it into individual SQL statements.
     *
     * Statement boundaries are matched platform-independently. LWT always
     * writes ";\n", but this used to split on ';' . PHP_EOL, which is
     * ";\r\n" on a Windows host and therefore never matched: every
     * statement piled up in the accumulator and the caller received an
     * empty list, dropped every table and replayed nothing (issue #249).
     * Line endings are now normalised exactly as
     * SqlFileParser::parseFile() does, so a dump restores identically
     * regardless of which OS wrote it or reads it.
     *
     * The handle is always closed before returning.
     *
     * @param resource $handle Backup file handle (may be a gzopen handle)
     * @param string   $title  File title, used in error messages
     *
     * @return array{queries: list<string>, error: string|null}
     *
     * @since 3.2.2-fork Extracted from restoreFile() and made EOL-agnostic
     */
    private static function parseBackupStream($handle, string $title): array
    {
        $queries_list = [];
        $curr_content = '';
        $start = true;
        $bytesRead = 0;
        $error = null;

        while ($stream = fgets($handle)) {
            // Gzip-bomb defense: cap the total decompressed bytes we
            // pull off the handle. A 1 GB SQL dump is more than any
            // real LWT install produces; anything bigger is almost
            // certainly malicious or a runaway.
            $bytesRead += strlen($stream);
            if ($bytesRead > self::MAX_DECOMPRESSED_BYTES) {
                $error = "Error: $title Restore file exceeds the "
                    . intdiv(self::MAX_DECOMPRESSED_BYTES, 1024 * 1024)
                    . " MB decompressed-size limit (likely a gzip bomb).";
                break;
            }

            // Normalise line endings before anything is matched against
            // them. formatValueForSqlOutput() escapes CR inside values via
            // mysqli_real_escape_string, so a raw CR in a dump is only ever
            // a line terminator and is safe to rewrite.
            $stream = str_replace(["\r\n", "\r"], "\n", $stream);

            // Check file header
            if ($start) {
                if (
                    !str_starts_with($stream, "-- lwt-backup-")
                    && !str_starts_with($stream, "-- lwt-exp_version-backup-")
                ) {
                    $error = "Error: Invalid $title Restore file " .
                    "(possibly not created by LWT backup)";
                    break;
                }
                $start = false;
                continue;
            }
            // Skip comments (lines starting with "-- " or lines that are just "--")
            $trimmedLine = trim($stream);
            if (str_starts_with($stream, '-- ') || $trimmedLine === '--') {
                continue;
            }
            // Add stream to accumulator
            $curr_content .= $stream;
            // Get queries
            $queries = explode(";\n", $curr_content);
            // Replace line by remainders of the last element (incomplete line)
            $curr_content = array_pop($queries);

            foreach ($queries as $query) {
                $queries_list[] = trim($query);
            }
        }

        $reachedEof = feof($handle);
        fclose($handle);

        if ($error !== null) {
            return ['queries' => [], 'error' => $error];
        }

        if (!$reachedEof) {
            return [
                'queries' => [],
                'error' => "Error: cannot read the end of the $title file!",
            ];
        }

        // Flush a trailing statement that is not followed by a newline, so a
        // dump whose final line lacks its EOL still restores completely.
        $tail = trim(rtrim(trim($curr_content), ';'));
        if ($tail !== '') {
            $queries_list[] = $tail;
        }

        return ['queries' => $queries_list, 'error' => null];
    }

    /**
     * Restore the database from a file.
     *
     * @param resource $handle       Backup file handle
     * @param string   $title        File title
     * @param bool     $validateSql  Whether to validate SQL statements (default true)
     *
     * @return string Human-readable status message
     *
     * @since 2.0.3-fork Function was broken
     * @since 2.5.3-fork Function repaired
     * @since 2.7.0-fork $handle should be an *uncompressed* file.
     * @since 2.9.1-fork It can read SQL with more or less than one instruction a line
     * @since 3.0.0 Added SQL validation for security hardening
     * @since 3.2.2-fork Refuses to drop anything when the dump yields no
     *        statements; replays with FK checks suspended so FK-carrying
     *        CREATE TABLEs no longer fail on dependency order
     */
    public static function restoreFile($handle, string $title, bool $validateSql = true): string
    {
        // Multi-user safety guard: the rest of this function drops every
        // LWT table and replaces it with the dump's contents, which would
        // wipe every other user's data. Refuse the restore when more
        // than one active user exists. The admin must take the multi-
        // user mode down first if they really mean it (or use the
        // per-user "Empty Database" + manual backup/restore recipe in
        // docs).
        $contextError = self::validateMultiUserRestoreContext();
        if ($contextError !== null) {
            fclose($handle);
            return $contextError;
        }

        $message = "";
        $hasErrors = false;
        $install_status = [
            "queries" => 0,
            "successes" => 0,
            "errors" => 0,
            "drops" => 0,
            "inserts" => 0,
            "creates" => 0
        ];

        $parsed = self::parseBackupStream($handle, $title);
        $queries_list = $parsed['queries'];
        if ($parsed['error'] !== null) {
            $message = $parsed['error'];
            $install_status["errors"] = 1;
            $hasErrors = true;
        }

        // Validate all queries before executing any (security hardening)
        if ($validateSql && !$hasErrors) {
            $validator = new SqlValidator();
            foreach ($queries_list as $query) {
                $trimmedQuery = trim($query);
                if ($trimmedQuery !== '' && !str_starts_with($trimmedQuery, '-- ')) {
                    if (!$validator->validate($trimmedQuery)) {
                        $message = "Security Error: " . ($validator->getFirstError() ?? "Invalid SQL detected");
                        $install_status["errors"] = 1;
                        $hasErrors = true;
                        break;
                    }
                }
            }
        }

        // A dump that yielded no statements must never reach
        // dropAllLwtTables(): dropping every table and then replaying nothing
        // leaves the install empty with no way back. That is exactly what the
        // PHP_EOL statement split did on Windows (issue #249) — silently, and
        // while reporting success. Refuse instead, so any future parser bug
        // costs an error message rather than the user's data.
        if (!$hasErrors && $queries_list === []) {
            $message = "Error: $title Restore file contains no SQL statements";
            $install_status["errors"] = 1;
            $hasErrors = true;
        }

        // Drop all existing tables first to ensure a clean slate
        // This prevents issues with partial state from previous attempts
        if (!$hasErrors) {
            self::dropAllLwtTables();
        }

        // Now run all queries
        $connection = Globals::getDbConnection();
        if (!$hasErrors && $connection !== null) {
            // A dump's CREATE TABLE statements are taken from SHOW CREATE
            // TABLE, so they carry their FOREIGN KEY clauses — but they are
            // emitted in backup-table order, not dependency order (feed_links
            // references news_feeds yet is created before it). With FK checks
            // on, every such CREATE fails with errno 1215 and the restore
            // leaves the install in pieces. dropAllLwtTables() restores checks
            // to 1 on the way out, so they have to be suspended again here for
            // the replay; the constraints are enforced again afterwards, once
            // every table exists.
            Connection::execute("SET FOREIGN_KEY_CHECKS = 0");
            try {
                foreach ($queries_list as $query) {
                    $sql_line = trim(
                        str_replace("\r", "", str_replace("\n", "", $query))
                    );
                    if ($sql_line != "") {
                        if (!str_starts_with($query, '-- ')) {
                            $res = mysqli_query(
                                $connection,
                                $query
                            );
                            $install_status["queries"]++;
                            if ($res == false) {
                                $install_status["errors"]++;
                                $hasErrors = true;
                            } else {
                                $install_status["successes"]++;
                                if (str_starts_with($query, "INSERT INTO")) {
                                    $install_status["inserts"]++;
                                } elseif (str_starts_with($query, "DROP TABLE")) {
                                    $install_status["drops"]++;
                                } elseif (str_starts_with($query, "CREATE TABLE")) {
                                    $install_status["creates"]++;
                                }
                            }
                        }
                    }
                }
            } finally {
                Connection::execute("SET FOREIGN_KEY_CHECKS = 1");
            }
        }

        if (!$hasErrors) {
            // Drop legacy textitems table if it exists (replaced by word_occurrences)
            Connection::execute("DROP TABLE IF EXISTS textitems");

            // Clear migration history so all migrations run fresh on restored data.
            // This handles old backups that predate the migration system or have
            // different schema versions. The migrations will bring the schema up to date.
            try {
                Connection::execute("DELETE FROM _migrations");
            } catch (\RuntimeException $e) {
                // Table might not exist in old backups, that's fine
            }

            // Reset dbversion to force migration check
            // The old backup might have a different version or no version at all
            try {
                QueryBuilder::table('settings')
                    ->where('StKey', '=', 'dbversion')
                    ->delete();
            } catch (\RuntimeException $e) {
                // Settings table might not exist yet or have different schema
            }

            // Drop all FK constraints before running migrations.
            // SET FOREIGN_KEY_CHECKS = 0 only affects INSERT/UPDATE/DELETE and DROP TABLE,
            // not ALTER TABLE MODIFY on columns referenced by FKs.
            // Only the migrations that are pending recreate theirs, so the set
            // is captured first and restored once the run is over.
            $foreignKeys = Migrations::captureForeignKeys();
            Migrations::dropAllForeignKeys();

            // Disable FK checks during migrations to handle legacy backup data
            // that may not satisfy new FK constraints until fully migrated
            Connection::execute("SET FOREIGN_KEY_CHECKS = 0");
            try {
                Migrations::checkAndUpdate();
            } finally {
                Migrations::restoreForeignKeys($foreignKeys);
                Connection::execute("SET FOREIGN_KEY_CHECKS = 1");
            }
            Migrations::reparseAllTexts();
            // Cross-install or legacy backups may carry UsID column values
            // that don't match the local users table — without this step
            // the restored rows would be invisible to every account on
            // the new install.
            self::rewriteRestoredUsIdsToCurrentUser();
            Maintenance::optimizeDatabase();
            TagsFacade::getAllTermTags(true);
            TagsFacade::getAllTextTags(true);
            $message = "Success: $title restored";
        } elseif ($message == "") {
            $message = "Error: $title NOT restored";
        }

        $message .= sprintf(
            " - %d queries - %d successful (%d/%d tables dropped/created, " .
            "%d records added), %d failed.",
            $install_status["queries"],
            $install_status["successes"],
            $install_status["drops"],
            $install_status["creates"],
            $install_status["inserts"],
            $install_status["errors"]
        );
        return $message;
    }

    /**
     * Truncate the database, remove all data belonging to the current user.
     *
     * In multi-user mode with an authenticated user, only that user's rows
     * are deleted (across the 11 content tables, plus the per-user
     * `currenttext` setting). Single-user installs keep the legacy
     * "wipe everything" behaviour. Settings are kept.
     *
     * @return void
     */
    public static function truncateUserDatabase(): void
    {
        $userId = Globals::isMultiUserEnabled() ? Globals::getCurrentUserId() : null;
        if ($userId !== null) {
            self::truncateForUser($userId);
            self::truncateUserPostHook($userId);
            return;
        }
        self::truncateAllUsersData();
        self::truncateUserPostHook(null);
    }

    /**
     * Delete every row across the eleven content tables.
     *
     * Order matters: child tables with FKs to multiple parents go first,
     * then the parent tables. Used in single-user mode and as the
     * destructive base step of `restoreFile()` (which then loads a new
     * dump on top of the empty schema).
     *
     * @return void
     */
    private static function truncateAllUsersData(): void
    {
        // Level 1: Tables with FKs to multiple parents
        Connection::execute('DELETE FROM text_tag_map');
        Connection::execute('DELETE FROM word_tag_map');
        Connection::execute('DELETE FROM word_occurrences');
        Connection::execute('DELETE FROM feed_links');

        // Level 2: Tables with FKs to languages only
        Connection::execute('DELETE FROM sentences');
        Connection::execute('DELETE FROM news_feeds');
        Connection::execute('DELETE FROM texts');
        Connection::execute('DELETE FROM words');

        // Level 3: Parent tables with no FKs to other content tables
        Connection::execute('DELETE FROM tags');
        Connection::execute('DELETE FROM text_tags');
        Connection::execute('DELETE FROM languages');

        QueryBuilder::table('settings')
            ->where('StKey', '=', 'currenttext')
            ->delete();
    }

    /**
     * Delete every content row owned by `$userId`.
     *
     * The link/map/derived tables don't carry a UsID column of their own,
     * so they're scoped via a subquery on the parent table (texts /
     * words / news_feeds). FK CASCADE would handle most of these
     * implicitly — explicit deletes guarantee the same result on
     * schemas where the cascade is missing or disabled.
     *
     * @param int $userId Owner UsID
     *
     * @return void
     */
    private static function truncateForUser(int $userId): void
    {
        // Level 1: Link/map and derived tables, scoped via parent ownership.
        Connection::preparedExecute(
            'DELETE FROM text_tag_map WHERE TtTxID IN (SELECT TxID FROM texts WHERE TxUsID = ?)',
            [$userId]
        );
        Connection::preparedExecute(
            'DELETE FROM word_tag_map WHERE WtWoID IN (SELECT WoID FROM words WHERE WoUsID = ?)',
            [$userId]
        );
        Connection::preparedExecute(
            'DELETE FROM word_occurrences WHERE Ti2TxID IN (SELECT TxID FROM texts WHERE TxUsID = ?)',
            [$userId]
        );
        Connection::preparedExecute(
            'DELETE FROM feed_links WHERE FlNfID IN (SELECT NfID FROM news_feeds WHERE NfUsID = ?)',
            [$userId]
        );
        Connection::preparedExecute(
            'DELETE FROM sentences WHERE SeTxID IN (SELECT TxID FROM texts WHERE TxUsID = ?)',
            [$userId]
        );

        // Level 2: Direct user-scoped tables.
        Connection::preparedExecute('DELETE FROM news_feeds WHERE NfUsID = ?', [$userId]);
        Connection::preparedExecute('DELETE FROM texts WHERE TxUsID = ?', [$userId]);
        Connection::preparedExecute('DELETE FROM words WHERE WoUsID = ?', [$userId]);
        Connection::preparedExecute('DELETE FROM tags WHERE TgUsID = ?', [$userId]);
        Connection::preparedExecute('DELETE FROM text_tags WHERE T2UsID = ?', [$userId]);
        Connection::preparedExecute('DELETE FROM languages WHERE LgUsID = ?', [$userId]);

        // Level 3: Per-user settings entry (keep admin/global settings).
        Connection::preparedExecute(
            'DELETE FROM settings WHERE StKey = ? AND StUsID = ?',
            ['currenttext', $userId]
        );
    }

    /**
     * Common cleanup after a truncate (whether scoped to a user or not).
     *
     * Optimises tables and clears the in-process tag caches so the next
     * page load doesn't return ghost tag names.
     *
     * @param int|null $_userId Reserved for future per-user cache busting
     *
     * @return void
     */
    private static function truncateUserPostHook(?int $_userId): void
    {
        Maintenance::optimizeDatabase();
        TagsFacade::getAllTermTags(true);
        TagsFacade::getAllTextTags(true);
    }

    /**
     * Refuse the restore when more than one user lives on this install.
     *
     * Returning a non-null string aborts `restoreFile` early. Single-user
     * installs always pass. The check looks at the `users` table because
     * `BACKUP_RESTORE_ENABLED=true` may have been set by an admin who
     * understood the single-user case but didn't realise the dump+drop
     * step nukes every account.
     *
     * @return string|null Error message to surface, or null when safe
     */
    private static function validateMultiUserRestoreContext(): ?string
    {
        if (!Globals::isMultiUserEnabled()) {
            return null;
        }

        try {
            $count = (int) Connection::preparedFetchValue(
                'SELECT COUNT(*) AS cnt FROM users',
                [],
                'cnt'
            );
        } catch (\RuntimeException $e) {
            // No users table yet (fresh install). Nothing to protect.
            return null;
        }

        if ($count <= 1) {
            return null;
        }

        return "Error: Restore is not supported in multi-user mode when more"
            . " than one user account exists ($count found). The restore"
            . " process drops every LWT table — including data belonging"
            . " to other users — before loading the dump. To proceed,"
            . " either (a) take the install down, set"
            . " MULTI_USER_ENABLED=false, restore, then re-enable"
            . " multi-user, or (b) follow the manual per-user migration"
            . " recipe in docs/guide/post-installation.md.";
    }

    /**
     * Reassign all restored content to the current admin in multi-user mode.
     *
     * `restoreFile` runs after the multi-user guard above, so when this
     * fires we know the install has at most one real account. Rewrite
     * every UsID column on the user-scoped tables to that account so
     * the freshly-loaded rows are visible — backups from a different
     * install or made before multi-user mode existed otherwise carry
     * UsIDs that don't correspond to any local user. Safe to no-op
     * when no current user is set (single-user mode reaches the same
     * behaviour by skipping the rewrite entirely).
     *
     * @return void
     */
    private static function rewriteRestoredUsIdsToCurrentUser(): void
    {
        if (!Globals::isMultiUserEnabled()) {
            return;
        }
        $userId = Globals::getCurrentUserId();
        if ($userId === null) {
            return;
        }

        // Only the directly-scoped tables carry UsID columns. Link/map
        // and derived tables inherit ownership through their parent
        // (TtTxID → texts, WtWoID → words, etc.) and don't need rewriting.
        foreach (
            [
                'languages'          => 'LgUsID',
                'texts'              => 'TxUsID',
                'words'              => 'WoUsID',
                'tags'                => 'TgUsID',
                'text_tags'          => 'T2UsID',
                'news_feeds'         => 'NfUsID',
                'settings'           => 'StUsID',
                'local_dictionaries' => 'LdUsID',
            ] as $table => $column
        ) {
            try {
                Connection::preparedExecute(
                    "UPDATE `{$table}` SET `{$column}` = ? WHERE `{$column}` IS NULL OR `{$column}` <> ?",
                    [$userId, $userId]
                );
            } catch (\RuntimeException $e) {
                // Older schemas may not have every table/column yet;
                // skip silently — the restored data still loaded, just
                // without the UsID rewrite for this one table.
                continue;
            }
        }
    }
}
