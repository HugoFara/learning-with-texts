<?php

/**
 * \file
 * \brief Session-scoped scratch tables for text parsing and vocabulary import.
 *
 * PHP version 8.2
 *
 * @category Database
 * @package  Lwt
 * @author   HugoFara <git@hugofara.net>
 * @license  Unlicense <http://unlicense.org/>
 * @link     https://hugofara.github.io/lwt/developer/api
 * @since    3.2.2
 */

declare(strict_types=1);

namespace Lwt\Shared\Infrastructure\Database;

/**
 * Creates the per-connection scratch table used while importing vocabulary.
 *
 * `temp_words` used to be a persistent, globally-shared InnoDB table that every
 * import `TRUNCATE`d and refilled. That design caused two problems:
 *
 *  - **Tablespace crashes.** On file-per-table InnoDB, `TRUNCATE` physically
 *    drops and recreates the `.ibd`. When that file went missing (notably on
 *    Windows) the table was left with a missing tablespace and every subsequent
 *    import failed with InnoDB error 194 "Tablespace is missing for a table"
 *    (see issue #247).
 *  - **Concurrency corruption.** Because the table was shared, two imports
 *    running at once (or any two users in multi-user mode) read and truncated
 *    each other's rows.
 *
 * It is now created per database connection with `CREATE TEMPORARY TABLE`, so
 * each request gets its own private copy. Temporary InnoDB tables live in the
 * shared session temporary tablespace (there is no per-table `.ibd` to orphan),
 * and they are dropped automatically when the connection closes.
 *
 * Callers must `ensureWords()` before use, then clear rows with `DELETE FROM` —
 * never `TRUNCATE`, which is DDL that recreates the tablespace and implicitly
 * commits any open transaction.
 *
 * (Text parsing no longer uses any scratch table — see TokenPersistence.)
 *
 * @since 3.2.2
 */
final class ScratchTables
{
    /**
     * Ensure the word-import staging table exists for this connection.
     *
     * Holds the rows of a vocabulary import file before they are merged into
     * the `words` table.
     *
     * @return void
     */
    public static function ensureWords(): void
    {
        Connection::query(
            <<<'SQL'
            CREATE TEMPORARY TABLE IF NOT EXISTS temp_words (
                WoText varchar(250) DEFAULT NULL,
                WoTextLC varchar(250) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL,
                WoTranslation varchar(500) NOT NULL DEFAULT '*',
                WoRomanization varchar(100) DEFAULT NULL,
                WoSentence varchar(1000) DEFAULT NULL,
                WoTaglist varchar(255) DEFAULT NULL,
                PRIMARY KEY(WoTextLC)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
            SQL
        );
    }
}
