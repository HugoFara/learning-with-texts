<?php

/**
 * MySQL Backup Repository
 *
 * PHP version 8.1
 *
 * @category Lwt
 * @package  Lwt\Modules\Admin\Infrastructure
 * @author   HugoFara <git@hugofara.net>
 * @license  Unlicense <http://unlicense.org/>
 * @link     https://hugofara.github.io/lwt/developer/api
 * @since    3.0.0
 */

declare(strict_types=1);

namespace Lwt\Modules\Admin\Infrastructure;

use Lwt\Shared\Infrastructure\Globals;
use Lwt\Shared\Infrastructure\Database\Connection;
use Lwt\Shared\Infrastructure\Database\Escaping;
use Lwt\Shared\Infrastructure\Database\Restore;
use Lwt\Shared\Infrastructure\Database\UserScopedQuery;
use Lwt\Modules\Admin\Domain\BackupRepositoryInterface;

/**
 * MySQL repository for backup operations.
 *
 * Provides database access for backup/restore functionality.
 *
 * @since 3.0.0
 */
class MySqlBackupRepository implements BackupRepositoryInterface
{
    /**
     * Tables to include in backup.
     *
     * @var string[]
     */
    private const BACKUP_TABLES = [
        'feed_links', 'languages', 'word_occurrences', 'news_feeds', 'sentences',
        'settings', 'tags', 'text_tags', 'texts', 'text_tag_map', 'words', 'word_tag_map'
    ];

    /**
     * Tables for official LWT backup format.
     *
     * @var string[]
     */
    private const OFFICIAL_BACKUP_TABLES = [
        'languages', 'sentences', 'settings', 'tags', 'text_tags',
        'word_occurrences', 'texts', 'text_tag_map', 'words', 'word_tag_map'
    ];

    /**
     * {@inheritdoc}
     */
    public function getDatabaseName(): string
    {
        return Globals::getDatabaseName();
    }

    /**
     * {@inheritdoc}
     */
    public function restoreFromHandle($handle, string $fileName): string
    {
        return Restore::restoreFile($handle, $fileName);
    }

    /**
     * {@inheritdoc}
     */
    public function generateBackupSql(): string
    {
        $out = "";

        foreach (self::BACKUP_TABLES as $table) {
            $out .= "\nDROP TABLE IF EXISTS " . $table . ";\n";
            $row2 = mysqli_fetch_row(
                Connection::querySelect("SHOW CREATE TABLE " . $table)
            );
            if ($row2 !== null && $row2 !== false && isset($row2[1])) {
                $out .= str_replace("\n", " ", (string) $row2[1]) . ";\n";
            }

            // Sentences and word_occurrences are regenerated from texts on
            // restore (Migrations::reparseAllTexts), so we don't dump rows.
            if ($table === 'sentences' || $table === 'word_occurrences') {
                continue;
            }

            $result = Connection::querySelect($this->buildScopedSelectAll($table));
            while ($row = mysqli_fetch_row($result)) {
                $values = [];
                foreach ($row as $cell) {
                    $values[] = Escaping::formatValueForSqlOutput($cell);
                }
                $out .= 'INSERT INTO ' . $table
                    . ' VALUES(' . implode(',', $values) . ");\n";
            }
        }

        return $out;
    }

    /**
     * Build a `SELECT * FROM <table>` filtered to the current user's data.
     *
     * In single-user mode (or when no user is authenticated) the SELECT is
     * unfiltered, matching legacy behaviour. In multi-user mode it returns
     * only rows owned by the caller — directly via the table's UsID column
     * for user-scoped tables, or via a subquery on the parent table for
     * link/map tables that don't carry their own owner column.
     *
     * @param string $table Table name from BACKUP_TABLES.
     *
     * @return string SQL string ready for Connection::querySelect.
     */
    private function buildScopedSelectAll(string $table): string
    {
        if (!Globals::isMultiUserEnabled()) {
            return 'SELECT * FROM ' . $table;
        }
        $userId = Globals::getCurrentUserId();
        if ($userId === null) {
            return 'SELECT * FROM ' . $table;
        }

        // Direct user-scoped tables: filter by their UsID column.
        if (UserScopedQuery::isUserScopedTable($table)) {
            $column = UserScopedQuery::getUserIdColumn($table);
            return 'SELECT * FROM ' . $table . ' WHERE ' . $column . ' = ' . $userId;
        }

        // Link/map tables: filter by ownership of the parent row.
        switch ($table) {
            case 'text_tag_map':
                return 'SELECT * FROM text_tag_map WHERE TtTxID IN ('
                    . 'SELECT TxID FROM texts WHERE TxUsID = ' . $userId . ')';
            case 'word_tag_map':
                return 'SELECT * FROM word_tag_map WHERE WtWoID IN ('
                    . 'SELECT WoID FROM words WHERE WoUsID = ' . $userId . ')';
            case 'feed_links':
                return 'SELECT * FROM feed_links WHERE FlNfID IN ('
                    . 'SELECT NfID FROM news_feeds WHERE NfUsID = ' . $userId . ')';
        }

        // Unknown table: leave unfiltered. Should never be hit with the
        // current BACKUP_TABLES list; guarded so a future addition that
        // forgets to add a case falls back to the legacy behaviour.
        return 'SELECT * FROM ' . $table;
    }

    /**
     * {@inheritdoc}
     */
    public function generateOfficialBackupSql(): string
    {
        $out = "";
        $scope = $this->officialBackupUserScope();

        foreach (self::OFFICIAL_BACKUP_TABLES as $table) {
            $result = null;

            if ($table == 'texts') {
                $result = Connection::querySelect(
                    'SELECT TxID, TxLgID, TxTitle, TxText, TxAnnotatedText, TxAudioURI,
                    TxSourceURI FROM ' . $table . $scope['texts']
                );
            } elseif ($table == 'words') {
                $result = Connection::querySelect(
                    'SELECT WoID, WoLgID, WoText, WoTextLC, WoStatus, WoTranslation,
                    WoRomanization, WoSentence, WoCreated, WoStatusChanged
                    FROM ' . $table . $scope['words']
                );
            } elseif ($table == 'languages') {
                $result = Connection::querySelect(
                    'SELECT LgID, LgName, LgDict1URI, LgDict2URI,
                    REPLACE(
                        LgGoogleTranslateURI, "ggl.php", "http://translate.google.com"
                    ) AS LgGoogleTranslateURI,
                    LgExportTemplate, LgTextSize, LgCharacterSubstitutions,
                    LgRegexpSplitSentences, LgExceptionsSplitSentences,
                    LgRegexpWordCharacters, LgRemoveSpaces, LgSplitEachChar,
                    LgRightToLeft FROM ' . $table . ' WHERE LgName<>""' . $scope['languages']
                );
            } elseif (
                $table !== 'sentences' && $table !== 'word_occurrences' &&
                $table !== 'settings'
            ) {
                $result = Connection::querySelect('SELECT * FROM ' . $table . $scope[$table]);
            }

            $out .= "\nDROP TABLE IF EXISTS " . $table . ";\n";
            $out .= $this->getOfficialTableSchema($table);

            if (
                $table !== 'sentences' && $table !== 'word_occurrences' &&
                $table !== 'settings' && $result !== null
            ) {
                while ($row = mysqli_fetch_row($result)) {
                    $values = [];
                    foreach ($row as $cell) {
                        $values[] = Escaping::formatValueForSqlOutput($cell);
                    }
                    $out .= 'INSERT INTO ' . $table
                        . ' VALUES(' . implode(',', $values) . ");\n";
                }
            }
        }

        return $out;
    }

    /**
     * Build the per-table WHERE-clause suffix for the official backup.
     *
     * Single-user (or unauthenticated) installs get empty suffixes,
     * preserving legacy behaviour. Multi-user installs get an `AND … = ?`
     * fragment for the languages SELECT (which already has a base WHERE)
     * and a fresh ` WHERE …` fragment for everything else, indirect link
     * tables included.
     *
     * @return array<string, string> Per-table SQL suffix keyed by
     *                               OFFICIAL_BACKUP_TABLES name.
     */
    private function officialBackupUserScope(): array
    {
        $empty = [];
        foreach (self::OFFICIAL_BACKUP_TABLES as $name) {
            $empty[$name] = '';
        }
        if (!Globals::isMultiUserEnabled()) {
            return $empty;
        }
        $userId = Globals::getCurrentUserId();
        if ($userId === null) {
            return $empty;
        }

        $scope = $empty;
        $scope['languages']    = ' AND LgUsID = ' . $userId;
        $scope['texts']        = ' WHERE TxUsID = ' . $userId;
        $scope['words']        = ' WHERE WoUsID = ' . $userId;
        $scope['tags']         = ' WHERE TgUsID = ' . $userId;
        $scope['text_tags']    = ' WHERE T2UsID = ' . $userId;
        $scope['text_tag_map'] = ' WHERE TtTxID IN (SELECT TxID FROM texts WHERE TxUsID = ' . $userId . ')';
        $scope['word_tag_map'] = ' WHERE WtWoID IN (SELECT WoID FROM words WHERE WoUsID = ' . $userId . ')';
        return $scope;
    }

    /**
     * {@inheritdoc}
     */
    public function truncateUserTables(): void
    {
        Restore::truncateUserDatabase();
    }

    /**
     * {@inheritdoc}
     */
    public function getBackupTables(): array
    {
        return self::BACKUP_TABLES;
    }

    /**
     * {@inheritdoc}
     */
    public function getOfficialBackupTables(): array
    {
        return self::OFFICIAL_BACKUP_TABLES;
    }

    /**
     * Get the official table schema for a given table.
     *
     * @param string $table Table name
     *
     * @return string SQL CREATE TABLE statement
     */
    private function getOfficialTableSchema(string $table): string
    {
        $schemas = [
            // Note: archived_texts and archived_text_tag_map have been merged into
            // the texts and text_tag_map tables with TxArchivedAt column.
            'languages' => "CREATE TABLE `languages` (
                `LgID` int(11) unsigned NOT NULL AUTO_INCREMENT,
                `LgName` varchar(40) NOT NULL,
                `LgDict1URI` varchar(200) NOT NULL,
                `LgDict2URI` varchar(200) DEFAULT NULL,
                `LgGoogleTranslateURI` varchar(200) DEFAULT NULL,
                `LgExportTemplate` varchar(1000) DEFAULT NULL,
                `LgTextSize` int(5) unsigned NOT NULL DEFAULT '100',
                `LgCharacterSubstitutions` varchar(500) NOT NULL,
                `LgRegexpSplitSentences` varchar(500) NOT NULL,
                `LgExceptionsSplitSentences` varchar(500) NOT NULL,
                `LgRegexpWordCharacters` varchar(500) NOT NULL,
                `LgRemoveSpaces` int(1) unsigned NOT NULL DEFAULT '0',
                `LgSplitEachChar` int(1) unsigned NOT NULL DEFAULT '0',
                `LgRightToLeft` int(1) unsigned NOT NULL DEFAULT '0',
                PRIMARY KEY (`LgID`),
                UNIQUE KEY `LgName` (`LgName`)
            ) ENGINE=MyISAM DEFAULT CHARSET=utf8;\n",
            'sentences' => "CREATE TABLE `sentences` (
                `SeID` int(11) unsigned NOT NULL AUTO_INCREMENT,
                `SeLgID` int(11) unsigned NOT NULL,
                `SeTxID` int(11) unsigned NOT NULL,
                `SeOrder` int(11) unsigned NOT NULL,
                `SeText` text,
                PRIMARY KEY (`SeID`),
                KEY `SeLgID` (`SeLgID`),
                KEY `SeTxID` (`SeTxID`),
                KEY `SeOrder` (`SeOrder`)
            ) ENGINE=MyISAM DEFAULT CHARSET=utf8;\n",
            'settings' => "CREATE TABLE `settings` (
                `StKey` varchar(40) NOT NULL,
                `StValue` varchar(40) DEFAULT NULL,
                PRIMARY KEY (`StKey`)
            ) ENGINE=MyISAM DEFAULT CHARSET=utf8;\n",
            'tags' => "CREATE TABLE `tags` (
                `TgID` int(11) unsigned NOT NULL AUTO_INCREMENT,
                `TgText` varchar(20) CHARACTER SET utf8 COLLATE utf8_bin NOT NULL,
                `TgComment` varchar(200) NOT NULL DEFAULT '',
                PRIMARY KEY (`TgID`),
                UNIQUE KEY `TgText` (`TgText`)
            ) ENGINE=MyISAM DEFAULT CHARSET=utf8;\n",
            'text_tags' => "CREATE TABLE `text_tags` (
                `T2ID` int(11) unsigned NOT NULL AUTO_INCREMENT,
                `T2Text` varchar(20) CHARACTER SET utf8 COLLATE utf8_bin NOT NULL,
                `T2Comment` varchar(200) NOT NULL DEFAULT '',
                PRIMARY KEY (`T2ID`),
                UNIQUE KEY `T2Text` (`T2Text`)
            ) ENGINE=MyISAM DEFAULT CHARSET=utf8;\n",
            'word_occurrences' => "CREATE TABLE `word_occurrences` (
                `Ti2ID` int(11) unsigned NOT NULL AUTO_INCREMENT,
                `Ti2WoID` int(11) unsigned DEFAULT NULL,
                `Ti2LgID` int(11) unsigned NOT NULL,
                `Ti2TxID` int(11) unsigned NOT NULL,
                `Ti2SeID` int(11) unsigned NOT NULL,
                `Ti2Order` int(11) unsigned NOT NULL,
                `Ti2WordCount` int(1) unsigned NOT NULL,
                `Ti2Text` varchar(250) NOT NULL,
                `Ti2TextLC` varchar(250) CHARACTER SET utf8 COLLATE utf8_bin NOT NULL,
                `Ti2Translation` text DEFAULT NULL,
                PRIMARY KEY (`Ti2ID`),
                KEY `Ti2WoID` (`Ti2WoID`),
                KEY `Ti2LgID` (`Ti2LgID`),
                KEY `Ti2TxID` (`Ti2TxID`),
                KEY `Ti2SeID` (`Ti2SeID`),
                KEY `Ti2Order` (`Ti2Order`),
                KEY `Ti2TextLC` (`Ti2TextLC`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8;\n",
            'texts' => "CREATE TABLE `texts` (
                `TxID` int(11) unsigned NOT NULL AUTO_INCREMENT,
                `TxLgID` int(11) unsigned NOT NULL,
                `TxTitle` varchar(200) NOT NULL,
                `TxText` text NOT NULL,
                `TxAnnotatedText` longtext NOT NULL,
                `TxAudioURI` varchar(200) DEFAULT NULL,
                `TxSourceURI` varchar(1000) DEFAULT NULL,
                PRIMARY KEY (`TxID`),
                KEY `TxLgID` (`TxLgID`)
            ) ENGINE=MyISAM DEFAULT CHARSET=utf8;\n",
            'text_tag_map' => "CREATE TABLE `text_tag_map` (
                `TtTxID` int(11) unsigned NOT NULL,
                `TtT2ID` int(11) unsigned NOT NULL,
                PRIMARY KEY (`TtTxID`,`TtT2ID`),
                KEY `TtTxID` (`TtTxID`),
                KEY `TtT2ID` (`TtT2ID`)
            ) ENGINE=MyISAM DEFAULT CHARSET=utf8;\n",
            'words' => "CREATE TABLE `words` (
                `WoID` int(11) unsigned NOT NULL AUTO_INCREMENT,
                `WoLgID` int(11) unsigned NOT NULL,
                `WoText` varchar(250) NOT NULL,
                `WoTextLC` varchar(250) CHARACTER SET utf8 COLLATE utf8_bin NOT NULL,
                `WoStatus` tinyint(4) NOT NULL,
                `WoTranslation` varchar(500) NOT NULL DEFAULT '*',
                `WoRomanization` varchar(100) DEFAULT NULL,
                `WoSentence` varchar(1000) DEFAULT NULL,
                `WoCreated` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
                `WoStatusChanged` timestamp NOT NULL DEFAULT '1970-01-01 01:00:01',
                PRIMARY KEY (`WoID`),
                UNIQUE KEY `WoLgIDTextLC` (`WoLgID`,`WoTextLC`),
                KEY `WoLgID` (`WoLgID`),
                KEY `WoStatus` (`WoStatus`),
                KEY `WoTextLC` (`WoTextLC`),
                KEY `WoTranslation` (`WoTranslation`(333)),
                KEY `WoCreated` (`WoCreated`),
                KEY `WoStatusChanged` (`WoStatusChanged`)
            ) ENGINE=MyISAM DEFAULT CHARSET=utf8;\n",
            'word_tag_map' => "CREATE TABLE `word_tag_map` (
                `WtWoID` int(11) unsigned NOT NULL,
                `WtTgID` int(11) unsigned NOT NULL,
                PRIMARY KEY (`WtWoID`,`WtTgID`),
                KEY `WtTgID` (`WtTgID`),
                KEY `WtWoID` (`WtWoID`)
            ) ENGINE=MyISAM DEFAULT CHARSET=utf8;\n",
        ];

        return $schemas[$table] ?? "";
    }
}
