<?php

/**
 * Complete Import Service - Complex import with temp tables, translation merge, tags
 *
 * PHP version 8.2
 *
 * @category Lwt
 * @package  Lwt\Modules\Vocabulary\Application\Services
 * @author   HugoFara <git@hugofara.net>
 * @license  Unlicense <http://unlicense.org/>
 * @link     https://hugofara.github.io/lwt/developer/api
 * @since    3.0.0
 */

declare(strict_types=1);

namespace Lwt\Modules\Vocabulary\Application\Services;

use Lwt\Shared\Infrastructure\Database\Connection;
use Lwt\Shared\Infrastructure\Database\DB;
use Lwt\Shared\Infrastructure\Database\QueryBuilder;
use Lwt\Shared\Infrastructure\Database\ScratchTables;
use Lwt\Shared\Infrastructure\Database\Settings;
use Lwt\Shared\Infrastructure\Database\UserScopedQuery;
use Lwt\Modules\Tags\Application\TagsFacade;

/**
 * Handles complete term import with temp tables, translation merging,
 * overwrite modes, and tag processing.
 *
 * @category Lwt
 * @package  Lwt\Modules\Vocabulary\Application\Services
 * @author   HugoFara <git@hugofara.net>
 * @license  Unlicense <http://unlicense.org/>
 * @link     https://hugofara.github.io/lwt/developer/api
 * @since    3.0.0
 */
class CompleteImportService
{
    private ImportUtilities $utilities;

    /**
     * @param ImportUtilities $utilities Shared import helpers
     */
    public function __construct(ImportUtilities $utilities)
    {
        $this->utilities = $utilities;
    }

    /**
     * Import terms with complete processing (handles tags, overwrite modes).
     *
     * @param int    $langId        Language ID
     * @param array{txt: int, tr: int, ro: int, se: int, tl: int} $fields Field indexes
     * @param string $columnsClause SQL columns clause
     * @param string $delimiter     Field delimiter
     * @param string $fileName      Path to input file
     * @param int    $status        Word status
     * @param int    $overwrite     Overwrite mode
     * @param bool   $ignoreFirst   Ignore first line
     * @param string $translDelim   Translation delimiter
     * @param string $tabType       Tab type (c, t, h)
     *
     * @return void
     */
    public function importComplete(
        int $langId,
        array $fields,
        string $columnsClause,
        string $delimiter,
        string $fileName,
        int $status,
        int $overwrite,
        bool $ignoreFirst,
        string $translDelim,
        string $tabType
    ): void {
        $removeSpaces = (bool) QueryBuilder::table('languages')
            ->where('LgID', '=', $langId)
            ->valuePrepared('LgRemoveSpaces');

        DB::beginTransaction();
        try {
            $this->initTempTables();

            if ($this->utilities->isLocalInfileEnabled()) {
                $this->loadDataToTempTable(
                    $removeSpaces,
                    $fields,
                    $columnsClause,
                    $delimiter,
                    $fileName,
                    $ignoreFirst
                );
            } else {
                $this->loadDataToTempTableWithPHP(
                    $removeSpaces,
                    $fields,
                    $delimiter,
                    $fileName,
                    $ignoreFirst
                );
            }

            // Handle translation merging for overwrite modes 4 and 5
            if ($overwrite > 3) {
                $this->handleTranslationMerge($langId, $translDelim, $tabType);
            }

            // Execute the main import/update query
            $this->executeMainImportQuery($langId, $fields, $status, $overwrite);

            // Handle tags if tag list field is specified
            if ($fields["tl"] != 0) {
                $this->handleTagsImport($langId);
            }

            $this->cleanupTempTables();
            DB::commit();
        } catch (\Throwable $e) {
            DB::rollback();
            $this->cleanupTempTables();
            throw $e;
        }
    }

    /**
     * Initialize temporary tables for import.
     *
     * @return void
     */
    private function initTempTables(): void
    {
        // Per-connection scratch table for the import rows (was a persistent
        // shared table; see ScratchTables for why it is now TEMPORARY).
        ScratchTables::ensureWords();
        Connection::execute("DELETE FROM temp_words");
        Connection::execute(
            "CREATE TEMPORARY TABLE IF NOT EXISTS numbers(
                n tinyint(3) unsigned NOT NULL
            )"
        );
        Connection::execute(
            "INSERT IGNORE INTO numbers(n) VALUES ('1'),('2'),('3'),
            ('4'),('5'),('6'),('7'),('8'),('9')"
        );
    }

    /**
     * Load data into temporary table using LOAD DATA.
     *
     * @param bool   $removeSpaces  Whether to remove spaces
     * @param array  $fields        Field indexes
     * @param string $columnsClause SQL columns clause
     * @param string $delimiter     Field delimiter
     * @param string $fileName      Path to input file
     * @param bool   $ignoreFirst   Ignore first line
     *
     * @return void
     */
    private function loadDataToTempTable(
        bool $removeSpaces,
        array $fields,
        string $columnsClause,
        string $delimiter,
        string $fileName,
        bool $ignoreFirst
    ): void {
        // MariaDB / MySQL do not accept `?` placeholders for the filename
        // in LOAD DATA INFILE — the filename is parsed at execution time,
        // not at prepare. Inline the path with proper escaping. The path
        // is server-controlled (either the PHP-generated `tmp_name` of an
        // upload, or the `createTempFile()` output for pasted text), so
        // there is no user input in this string.
        $escapedFileName = Connection::escape($fileName);

        $sql = "LOAD DATA LOCAL INFILE '$escapedFileName'
            INTO TABLE temp_words
            FIELDS TERMINATED BY '$delimiter' ENCLOSED BY '\"' LINES TERMINATED BY '\\n' " .
            ($ignoreFirst ? "IGNORE 1 LINES " : "") .
            "$columnsClause SET " .
            ($removeSpaces ?
                'WoTextLC = LOWER(REPLACE(@wotext," ","")), WoText = REPLACE(@wotext," ","")' :
                'WoTextLC = LOWER(WoText)');

        if ($fields["tl"] != 0) {
            $sql .= ', WoTaglist = REPLACE(@taglist, " ", ",")';
        }

        Connection::execute($sql);
    }

    /**
     * Load data into temporary table using PHP (fallback).
     * Uses chunked batch inserts to handle large files without excessive memory.
     *
     * @param bool   $removeSpaces Whether to remove spaces
     * @param array{txt: int, tr: int, ro: int, se: int, tl: int} $fields Field indexes
     * @param string $delimiter    Field delimiter
     * @param string $fileName     Path to input file
     * @param bool   $ignoreFirst  Ignore first line
     *
     * @return void
     */
    private function loadDataToTempTableWithPHP(
        bool $removeSpaces,
        array $fields,
        string $delimiter,
        string $fileName,
        bool $ignoreFirst
    ): void {
        if ($delimiter === '') {
            return;
        }
        $handle = fopen($fileName, 'r');
        if ($handle === false) {
            return;
        }

        /** @var list<list<string>> $rows */
        $rows = [];
        $lineNum = 0;

        while (($line = fgets($handle)) !== false) {
            if ($lineNum++ == 0 && $ignoreFirst) {
                continue;
            }

            $line = rtrim($line, "\r\n");
            if (empty(trim($line))) {
                continue;
            }

            /** @var list<string> $parsedLine */
            $parsedLine = explode($delimiter, $line);

            $txtIdx = $fields["txt"] - 1;
            if (!isset($parsedLine[$txtIdx])) {
                continue;
            }

            $wotext = $parsedLine[$txtIdx];

            /** @var list<string> $row */
            $row = [];
            // Fill WoText and WoTextLC
            if ($removeSpaces) {
                $row[] = str_replace(" ", "", $wotext);
                $row[] = mb_strtolower(str_replace(" ", "", $wotext));
            } else {
                $row[] = $wotext;
                $row[] = mb_strtolower($wotext);
            }

            $trIdx = $fields["tr"] - 1;
            $roIdx = $fields["ro"] - 1;
            $seIdx = $fields["se"] - 1;
            $tlIdx = $fields["tl"] - 1;

            if ($fields["tr"] != 0 && isset($parsedLine[$trIdx])) {
                $row[] = $parsedLine[$trIdx];
            }
            if ($fields["ro"] != 0 && isset($parsedLine[$roIdx])) {
                $row[] = $parsedLine[$roIdx];
            }
            if ($fields["se"] != 0 && isset($parsedLine[$seIdx])) {
                $row[] = $parsedLine[$seIdx];
            }
            if ($fields["tl"] != 0 && isset($parsedLine[$tlIdx])) {
                $row[] = str_replace(" ", ",", $parsedLine[$tlIdx]);
            }

            $rows[] = $row;

            // Execute batch when we reach the batch size
            if (count($rows) >= ImportUtilities::BATCH_SIZE) {
                $this->executeTempTableBatch($rows, $fields);
                $rows = [];
            }
        }

        fclose($handle);

        // Execute remaining rows
        if (!empty($rows)) {
            $this->executeTempTableBatch($rows, $fields);
        }
    }

    /**
     * Execute a batch insert for temp table import.
     *
     * @param list<list<string>> $rows   Array of row data
     * @param array{txt: int, tr: int, ro: int, se: int, tl: int} $fields Field indexes
     *
     * @return void
     */
    private function executeTempTableBatch(array $rows, array $fields): void
    {
        if (empty($rows)) {
            return;
        }

        // Build placeholder string for one row
        $rowPlaceholders = '(?, ?';  // WoText, WoTextLC
        if ($fields["tr"] != 0) {
            $rowPlaceholders .= ', ?';
        }
        if ($fields["ro"] != 0) {
            $rowPlaceholders .= ', ?';
        }
        if ($fields["se"] != 0) {
            $rowPlaceholders .= ', ?';
        }
        if ($fields["tl"] != 0) {
            $rowPlaceholders .= ', ?';
        }
        $rowPlaceholders .= ')';

        $placeholders = array_fill(0, count($rows), $rowPlaceholders);
        /** @var list<string> $params */
        $params = [];
        foreach ($rows as $row) {
            foreach ($row as $value) {
                $params[] = $value;
            }
        }

        $sql = "INSERT INTO temp_words(
                WoText, WoTextLC" .
                ($fields["tr"] != 0 ? ', WoTranslation' : '') .
                ($fields["ro"] != 0 ? ', WoRomanization' : '') .
                ($fields["se"] != 0 ? ', WoSentence' : '') .
                ($fields["tl"] != 0 ? ", WoTaglist" : "") .
            ")
            VALUES " . implode(',', $placeholders);

        Connection::preparedExecute($sql, $params);
    }

    /**
     * Handle translation merging for overwrite modes 4 and 5.
     *
     * @param int    $langId      Language ID
     * @param string $translDelim Translation delimiter from import
     * @param string $tabType     Tab type (c, t, h)
     *
     * @return void
     */
    private function handleTranslationMerge(int $langId, string $translDelim, string $tabType): void
    {
        Connection::execute(
            "CREATE TEMPORARY TABLE IF NOT EXISTS merge_words(
                MID mediumint(8) unsigned NOT NULL AUTO_INCREMENT,
                MText varchar(250) NOT NULL,
                MTranslation varchar(250) NOT NULL,
                PRIMARY KEY (MID),
                UNIQUE KEY (MText, MTranslation)
            ) DEFAULT CHARSET=utf8"
        );

        $wosep = Settings::getWithDefault('set-term-translation-delimiters');
        if (empty($wosep)) {
            $wosep = match ($tabType) {
                'h' => '#',
                'c' => ',',
                default => "\t",
            };
        }

        $seplen = mb_strlen($wosep, 'UTF-8');
        $woTrRepl = 'words.WoTranslation';
        $replaceParams = [];
        for ($i = 1; $i < $seplen; $i++) {
            $woTrRepl = 'REPLACE(' . $woTrRepl . ', ?, ?)';
            $replaceParams[] = $wosep[$i];
            $replaceParams[] = $wosep[0];
        }

        // Insert existing translations. The user-scope clause goes inside the
        // inner subquery's join condition so its placeholder sits between
        // `WoLgID = ?` and the trailing CHAR_LENGTH `?`.
        $bindings = array_merge(
            $replaceParams,
            [$wosep[0], $wosep[0], $langId]
        );
        $userScope = UserScopedQuery::forTablePrepared('words', $bindings);
        $bindings[] = $wosep[0];

        $sql = "INSERT IGNORE INTO merge_words(MText,MTranslation)
            SELECT b.WoTextLC,
            trim(
                SUBSTRING_INDEX(
                    SUBSTRING_INDEX(b.WoTranslation, ?, numbers.n),
                    ?, -1
                )
            ) name
            FROM numbers
            INNER JOIN (
                SELECT words.WoTextLC as WoTextLC, $woTrRepl as WoTranslation
                FROM temp_words
                LEFT JOIN words
                ON words.WoTextLC = temp_words.WoTextLC
                    AND words.WoTranslation != '*'
                    AND words.WoLgID = ?{$userScope}
            ) b
            ON CHAR_LENGTH(b.WoTranslation)-CHAR_LENGTH(REPLACE(b.WoTranslation, ?, ''))>= numbers.n-1
            ORDER BY b.WoTextLC, n";

        $stmt = Connection::prepare($sql);
        $stmt->bindValues($bindings);
        $stmt->execute();

        // Handle import delimiter
        $tesep = $translDelim;
        if (empty($tesep)) {
            $tesep = match ($tabType) {
                'h' => '#',
                'c' => ',',
                default => "\t",
            };
        }

        $seplen = mb_strlen($tesep, 'UTF-8');
        $woTrRepl = 'temp_words.WoTranslation';
        $replaceParams2 = [];
        for ($i = 1; $i < $seplen; $i++) {
            $woTrRepl = 'REPLACE(' . $woTrRepl . ', ?, ?)';
            $replaceParams2[] = $tesep[$i];
            $replaceParams2[] = $tesep[0];
        }

        // Insert new translations
        $params2 = array_merge(
            $replaceParams2,
            [$tesep[0], $tesep[0], $tesep[0]]
        );

        $stmt = Connection::prepare(
            "INSERT IGNORE INTO merge_words(MText,MTranslation)
            SELECT temp_words.WoTextLC,
            trim(
                SUBSTRING_INDEX(
                    SUBSTRING_INDEX($woTrRepl, ?,
                        numbers.n
                    ), ?, -1
                )
            ) name
            FROM numbers
            INNER JOIN temp_words
            ON CHAR_LENGTH(temp_words.WoTranslation)-CHAR_LENGTH(REPLACE($woTrRepl, ?, ''))>= numbers.n-1
            ORDER BY temp_words.WoTextLC, n"
        );
        $stmt->bindValues($params2);
        $stmt->execute();

        // Determine separator for output
        if ($wosep[0] == ',' || $wosep[0] == ';') {
            $wosep = $wosep[0] . ' ';
        } else {
            $wosep = ' ' . $wosep[0] . ' ';
        }

        // Update temp_words with merged translations
        Connection::preparedExecute(
            "UPDATE temp_words
            LEFT JOIN (
                SELECT MText, GROUP_CONCAT(trim(MTranslation)
                    ORDER BY MID
                    SEPARATOR ?
                ) AS Translation
                FROM merge_words
                GROUP BY MText
            ) A
            ON MText=WoTextLC
            SET WoTranslation = Translation",
            [$wosep]
        );

        Connection::execute("DROP TABLE merge_words");
    }

    /**
     * Execute the main import/update query based on overwrite mode.
     *
     * @param int   $langId    Language ID
     * @param array $fields    Field indexes
     * @param int   $status    Word status
     * @param int   $overwrite Overwrite mode (0-5)
     *
     * @return void
     */
    private function executeMainImportQuery(int $langId, array $fields, int $status, int $overwrite): void
    {
        if ($overwrite != 3 && $overwrite != 5) {
            // Stamp WoUsID on every imported row. Without this, multi-user
            // installs silently lost imports: rows landed with WoUsID NULL
            // and then never showed up in the caller's vocab list (the
            // QueryBuilder filter `WoUsID = currentUserId` excluded them).
            $userScopeCol = UserScopedQuery::insertColumn('words');
            $userScopeVal = UserScopedQuery::insertValue('words');

            $sql = "INSERT " . ($overwrite != 0 ? '' : 'IGNORE ') .
                " INTO words (
                    WoTextLC, WoText, WoTranslation, WoRomanization, WoSentence,
                    WoStatus, WoStatusChanged, WoLgID{$userScopeCol}
                )
                SELECT *, $langId as LgID{$userScopeVal}" . "
                FROM (
                    SELECT WoTextLC, WoText, WoTranslation, WoRomanization,
                    WoSentence, $status AS WoStatus,
                    NOW() AS WoStatusChanged
                    FROM temp_words
                ) AS tw";

            if ($overwrite == 1 || $overwrite == 4) {
                $sql .= " ON DUPLICATE KEY UPDATE " .
                    ($fields["tr"] ? "words.WoTranslation = tw.WoTranslation, " : "") .
                    ($fields["ro"] ? "words.WoRomanization = tw.WoRomanization, " : '') .
                    ($fields["se"] ? "words.WoSentence = tw.WoSentence, " : '') .
                    "words.WoStatus = tw.WoStatus,
                    words.WoStatusChanged = tw.WoStatusChanged";
            }

            if ($overwrite == 2) {
                $sql .= " ON DUPLICATE KEY UPDATE
                    words.WoTranslation = CASE
                        WHEN words.WoTranslation = \"*\" THEN tw.WoTranslation
                        ELSE words.WoTranslation
                    END,
                    words.WoRomanization = CASE
                        WHEN words.WoRomanization IS NULL THEN tw.WoRomanization
                        ELSE words.WoRomanization
                    END,
                    words.WoSentence = CASE
                        WHEN words.WoSentence IS NULL THEN tw.WoSentence
                        ELSE words.WoSentence
                    END,
                    words.WoStatusChanged = CASE
                        WHEN words.WoSentence IS NULL OR words.WoRomanization IS NULL OR words.WoTranslation = \"*\"
                        THEN tw.WoStatusChanged
                        ELSE words.WoStatusChanged
                    END";
            }

            Connection::execute($sql);
            return;
        }

        // Overwrite modes 3 and 5: only update existing, don't insert new.
        // forTablePrepared appends ` AND a.WoUsID = ?` and pushes the
        // current user id into $bindings; switching the call from
        // `Connection::execute` to `preparedExecute` is what binds it.
        // Pre-fix the `?` was unbound and the statement either failed
        // outright or (in single-user mode) silently ran without scope.
        $bindings = [];
        $sql = "UPDATE words AS a
            JOIN temp_words AS b
            ON a.WoTextLC = b.WoTextLC SET
            a.WoTranslation = CASE
                WHEN b.WoTranslation = '' OR b.WoTranslation = '*' THEN a.WoTranslation
                ELSE b.WoTranslation
            END,
            a.WoRomanization = CASE
                WHEN b.WoRomanization IS NULL OR b.WoRomanization = '' THEN a.WoRomanization
                ELSE b.WoRomanization
            END,
            a.WoSentence = CASE
                WHEN b.WoSentence IS NULL OR b.WoSentence = '' THEN a.WoSentence
                ELSE b.WoSentence
            END,
            a.WoStatusChanged = CASE
                WHEN (b.WoTranslation = '' OR b.WoTranslation = '*')
                    AND (b.WoRomanization IS NULL OR b.WoRomanization = '')
                    AND (b.WoSentence IS NULL OR b.WoSentence = '')
                THEN a.WoStatusChanged
                ELSE NOW()
            END"
            . UserScopedQuery::forTablePrepared('words', $bindings, 'a');

        Connection::preparedExecute($sql, $bindings);
    }

    /**
     * Handle tags import.
     *
     * @param int $langId Language ID
     *
     * @return void
     */
    private function handleTagsImport(int $langId): void
    {
        // Insert new tags. Stamp TgUsID so the new rows belong to the
        // caller — pre-fix they landed with TgUsID NULL and were
        // invisible to every user (and to the per-user composite
        // unique on (TgUsID, TgText) introduced in
        // 20260503_120000_per_user_name_uniques.sql, NULL is treated
        // as distinct, so duplicates accumulated silently).
        $tagInsertCol = UserScopedQuery::insertColumn('tags');
        $tagInsertVal = UserScopedQuery::insertValue('tags');
        Connection::execute(
            "INSERT IGNORE INTO tags (TgText{$tagInsertCol})
            SELECT name{$tagInsertVal} FROM (
                SELECT temp_words.WoTextLC,
                SUBSTRING_INDEX(
                    SUBSTRING_INDEX(
                        temp_words.WoTaglist, ',',
                        numbers.n
                    ), ',', -1) name
                FROM numbers
                INNER JOIN temp_words
                ON CHAR_LENGTH(temp_words.WoTaglist)-CHAR_LENGTH(REPLACE(temp_words.WoTaglist, ',', ''))>= numbers.n-1
                ORDER BY WoTextLC, n) A"
        );

        // Link words to tags. Scope BOTH `words` and `tags` to the
        // caller — without the tags filter the join `name = TgText`
        // could match a foreign user's tag with the same text and
        // attach it to the caller's words, polluting the foreign
        // user's tag-to-word membership (the same shape as F13's
        // getOrCreateTermTag fix).
        $bindings = [$langId];
        $userScope = UserScopedQuery::forTablePrepared('words', $bindings)
            . UserScopedQuery::forTablePrepared('tags', $bindings);
        $sql = "INSERT IGNORE INTO word_tag_map
            SELECT WoID, TgID
            FROM (
                SELECT temp_words.WoTextLC, SUBSTRING_INDEX(
                    SUBSTRING_INDEX(
                        temp_words.WoTaglist, ',', numbers.n
                    ), ',', -1) name
                FROM numbers
                INNER JOIN temp_words
                ON CHAR_LENGTH(temp_words.WoTaglist)-CHAR_LENGTH(REPLACE(temp_words.WoTaglist, ',', ''))>= numbers.n-1
                ORDER BY WoTextLC, n
            ) A, tags, words
            WHERE name=TgText AND A.WoTextLC=words.WoTextLC AND WoLgID=?"
            . $userScope;

        Connection::preparedExecute($sql, $bindings);

        TagsFacade::getAllTermTags(true);
    }

    /**
     * Cleanup temporary tables.
     *
     * @return void
     */
    private function cleanupTempTables(): void
    {
        // DROP TEMPORARY (not DROP TABLE / TRUNCATE): the former does not
        // implicitly commit, so it is safe to call inside the import transaction.
        // Both tables are recreated by initTempTables() on the next import.
        Connection::execute("DROP TEMPORARY TABLE IF EXISTS numbers");
        Connection::execute("DROP TEMPORARY TABLE IF EXISTS temp_words");
    }

    /**
     * Import tags only (no terms).
     *
     * @param array{tl: int} $fields      Field indexes
     * @param string         $tabType     Tab type (c, t, h)
     * @param string         $fileName    Path to input file
     * @param bool           $ignoreFirst Ignore first line
     *
     * @return void
     */
    public function importTagsOnly(array $fields, string $tabType, string $fileName, bool $ignoreFirst): void
    {
        // Create the per-connection scratch tables (temp_words + numbers) before
        // loading the tag rows into temp_words below.
        $this->initTempTables();

        $columns = '';
        $tlField = $fields["tl"];
        for ($j = 1; $j <= $tlField; $j++) {
            $columns .= ($j == 1 ? '(' : ',') . ($j == $fields["tl"] ? '@taglist' : '@dummy');
        }
        $columns .= ')';

        $delimiter = ' ' . $this->utilities->getSqlDelimiter($tabType);

        if ($this->utilities->isLocalInfileEnabled()) {
            // See loadDataToTempTable() — `?` is not accepted for LOAD
            // DATA's filename, inline with proper escaping. The path is
            // server-controlled.
            $escapedFileName = Connection::escape($fileName);
            $sql = "LOAD DATA LOCAL INFILE '$escapedFileName'
                IGNORE INTO TABLE temp_words
                FIELDS TERMINATED BY '$delimiter' ENCLOSED BY '\"' LINES TERMINATED BY '\\n' " .
                ($ignoreFirst ? "IGNORE 1 LINES " : "") .
                "$columns
                SET WoTextLC = REPLACE(@taglist, ' ', ',')";
            Connection::execute($sql);
        } else {
            $handle = fopen($fileName, 'r');
            if ($handle === false) {
                return;
            }
            $fileSize = filesize($fileName);
            if ($fileSize === false || $fileSize === 0) {
                fclose($handle);
                return;
            }
            $dataText = fread($handle, $fileSize);
            fclose($handle);
            if ($dataText === false) {
                return;
            }

            $params = [];
            $placeholders = [];
            $i = 0;
            $realDelimiter = $this->utilities->getDelimiter($tabType);
            if ($realDelimiter === '') {
                return;
            }

            // The upload's line terminator comes from whoever wrote the file,
            // not from the host PHP runs on, so splitting on PHP_EOL broke this
            // wherever the two disagree: a CRLF file on Linux left a stray "\r"
            // on every last field, and an LF file on Windows collapsed the whole
            // upload into one line. Normalise first, then split on "\n".
            $dataText = str_replace(["\r\n", "\r"], "\n", $dataText);

            foreach (explode("\n", $dataText) as $line) {
                if ($i++ == 0 && $ignoreFirst) {
                    continue;
                }

                if (empty(trim($line))) {
                    continue;
                }

                /** @var list<string> $parts */
                $parts = explode($realDelimiter, $line);
                $tlIdx = $tlField - 1;
                if (!isset($parts[$tlIdx])) {
                    continue;
                }

                $tags = $parts[$tlIdx];
                $tags = str_replace(' ', ',', $tags);
                $params[] = $tags;
                $placeholders[] = "(?)";
            }

            if (!empty($placeholders)) {
                $sql = "INSERT INTO temp_words(WoTextLC)
                    VALUES " . implode(',', $placeholders);
                Connection::preparedExecute($sql, $params);
            }
        }

        // Stamp TgUsID so the imported tags are owned by the caller —
        // see handleTagsImport() for context. Without this the rows
        // land with TgUsID NULL and never appear on the user's tag
        // page.
        $tagInsertCol = UserScopedQuery::insertColumn('tags');
        $tagInsertVal = UserScopedQuery::insertValue('tags');
        Connection::execute("INSERT IGNORE INTO tags (TgText{$tagInsertCol})
            SELECT NAME{$tagInsertVal} FROM (
                SELECT SUBSTRING_INDEX(
                    SUBSTRING_INDEX(
                        temp_words.WoTextLC, ',', numbers.n
                    ), ',', -1) name
                FROM numbers
                INNER JOIN temp_words
                ON CHAR_LENGTH(temp_words.WoTextLC)-CHAR_LENGTH(REPLACE(temp_words.WoTextLC, ',', ''))>= numbers.n-1
                ORDER BY WoTextLC, n) A");

        $this->cleanupTempTables();
        TagsFacade::getAllTermTags(true);
    }
}
