<?php

/**
 * \file
 * \brief Database migrations and initialization utilities.
 *
 * PHP version 8.1
 *
 * @category Database
 * @package  Lwt
 * @author   HugoFara <git@hugofara.net>
 * @license  Unlicense <http://unlicense.org/>
 * @link     https://hugofara.github.io/lwt/developer/api
 * @since    3.0.0
 */

declare(strict_types=1);

namespace Lwt\Shared\Infrastructure\Database;

use Lwt\Shared\Infrastructure\ApplicationInfo;
use Lwt\Shared\Infrastructure\Exception\DatabaseException;
use Lwt\Shared\Infrastructure\Globals;
use Lwt\Shared\Infrastructure\Utilities\ErrorHandler;
use Lwt\Modules\Vocabulary\Application\Services\TermStatusService;

/**
 * Database migrations and initialization utilities.
 *
 * Provides methods for updating database schema, running migrations,
 * and initializing the database.
 *
 * @since 3.0.0
 */
class Migrations
{
    /**
     * Status recorded for a migration whose statements all succeeded.
     */
    public const STATUS_APPLIED = 'applied';

    /**
     * Status recorded for a migration that had at least one failing statement.
     */
    public const STATUS_FAILED = 'failed';

    /**
     * How many times a failed migration is retried before being given up on.
     *
     * Counts requests: a failed migration is retried on the next request, and
     * the one after, then left alone so a broken statement is not re-run on
     * every page load. An upgrade that brings new migration files restores the
     * budget — see {@see restoreRetryBudget()}.
     */
    public const MAX_ATTEMPTS = 3;

    /**
     * MySQL error codes that mean "nothing to do here", not "this broke".
     *
     * Old migrations rename or alter tables that a fresh install never had,
     * because db/schema/baseline.sql already creates the modern schema. Those
     * statements fail by design, so they must not be reported as failures —
     * otherwise every healthy install would show migration errors.
     *
     * @var array<int>
     */
    private const HARMLESS_SQL_ERRORS = [
        1007, // ER_DB_CREATE_EXISTS
        1022, // ER_DUP_KEY
        1050, // ER_TABLE_EXISTS_ERROR
        1060, // ER_DUP_FIELDNAME: baseline already has the column
        1061, // ER_DUP_KEYNAME
        1091, // ER_CANT_DROP_FIELD_OR_KEY: already dropped
        1146, // ER_NO_SUCH_TABLE: legacy table a fresh install never had
        1826, // ER_FK_DUP_NAME
    ];

    /**
     * Read back every foreign key in the database, in enough detail to rebuild it.
     *
     * `dropAllForeignKeys()` clears the way for the migration run, but only the
     * migrations that happen to be pending put constraints back. Everything
     * created by an already-applied migration would be gone for good, so an
     * upgrade used to strip the database of its referential integrity
     * (cascade deletes, orphan protection) without a word. Capturing the set
     * first is what lets `restoreForeignKeys()` put back what the run did not.
     *
     * @return array<array{
     *     name: string, table: string, columns: array<string>,
     *     refTable: string, refColumns: array<string>,
     *     onUpdate: string, onDelete: string
     * }> The database's foreign keys
     */
    public static function captureForeignKeys(): array
    {
        $rows = Connection::preparedFetchAll(
            "SELECT k.CONSTRAINT_NAME, k.TABLE_NAME, k.COLUMN_NAME,
                    k.REFERENCED_TABLE_NAME, k.REFERENCED_COLUMN_NAME,
                    r.UPDATE_RULE, r.DELETE_RULE
             FROM INFORMATION_SCHEMA.KEY_COLUMN_USAGE k
             JOIN INFORMATION_SCHEMA.REFERENTIAL_CONSTRAINTS r
               ON r.CONSTRAINT_SCHEMA = k.CONSTRAINT_SCHEMA
              AND r.CONSTRAINT_NAME = k.CONSTRAINT_NAME
              AND r.TABLE_NAME = k.TABLE_NAME
             WHERE k.CONSTRAINT_SCHEMA = ? AND k.REFERENCED_TABLE_NAME IS NOT NULL
             ORDER BY k.TABLE_NAME, k.CONSTRAINT_NAME, k.ORDINAL_POSITION",
            [Globals::getDatabaseName()]
        );

        $keys = [];
        foreach ($rows as $row) {
            $name = $row['CONSTRAINT_NAME'] ?? null;
            $table = $row['TABLE_NAME'] ?? null;
            $column = $row['COLUMN_NAME'] ?? null;
            $refTable = $row['REFERENCED_TABLE_NAME'] ?? null;
            $refColumn = $row['REFERENCED_COLUMN_NAME'] ?? null;
            if (
                !is_string($name) || !is_string($table) || !is_string($column)
                || !is_string($refTable) || !is_string($refColumn)
            ) {
                continue;
            }

            // A composite key spans several rows, one per column.
            $id = $table . '.' . $name;
            if (!isset($keys[$id])) {
                $keys[$id] = [
                    'name' => $name,
                    'table' => $table,
                    'columns' => [],
                    'refTable' => $refTable,
                    'refColumns' => [],
                    'onUpdate' => is_string($row['UPDATE_RULE'] ?? null)
                        ? (string) $row['UPDATE_RULE']
                        : 'RESTRICT',
                    'onDelete' => is_string($row['DELETE_RULE'] ?? null)
                        ? (string) $row['DELETE_RULE']
                        : 'RESTRICT',
                ];
            }
            $keys[$id]['columns'][] = $column;
            $keys[$id]['refColumns'][] = $refColumn;
        }

        return array_values($keys);
    }

    /**
     * Recreate captured foreign keys that are no longer there.
     *
     * Anything the migration run already put back, under the same name, is left
     * alone. A key whose table or column has since been renamed or dropped is
     * skipped: the migration that renamed it owns the new shape.
     *
     * @param array<array{
     *     name: string, table: string, columns: array<string>,
     *     refTable: string, refColumns: array<string>,
     *     onUpdate: string, onDelete: string
     * }> $keys Foreign keys from captureForeignKeys()
     *
     * @return int How many were restored
     */
    public static function restoreForeignKeys(array $keys): int
    {
        return self::addMissingForeignKeys($keys);
    }

    /**
     * Add back the declared constraints an install has lost along the way.
     *
     * Restoring a snapshot only preserves what a database still has. Releases
     * before 3.4.0 dropped the constraints for every migration run and put back
     * only the ones owned by pending migrations, so long-lived installs are
     * missing constraints no snapshot can recover — half of them on a 3.3.0
     * database (#273). SchemaConstraints::FOREIGN_KEYS says what should be
     * there; whatever is absent is added.
     *
     * Rows that a missing constraint would have prevented are already in the
     * database. Adding under FOREIGN_KEY_CHECKS = 0 accepts them and gates
     * writes from here on; a constraint InnoDB still refuses is logged and
     * reported by getMissingForeignKeys() rather than failing the upgrade.
     *
     * @return int How many were added
     */
    public static function reconcileForeignKeys(): int
    {
        return self::addMissingForeignKeys(SchemaConstraints::FOREIGN_KEYS);
    }

    /**
     * The declared constraints that are still not there.
     *
     * A non-empty result means writes that should be refused are being
     * accepted, so it is worth showing to an administrator.
     *
     * @return array<array{name: string, table: string}> Missing constraints
     */
    public static function getMissingForeignKeys(): array
    {
        $existing = [];
        foreach (self::captureForeignKeys() as $key) {
            $existing[$key['table'] . '.' . $key['name']] = true;
        }

        $missing = [];
        foreach (SchemaConstraints::FOREIGN_KEYS as $key) {
            if (isset($existing[$key['table'] . '.' . $key['name']])) {
                continue;
            }
            // A table this install does not have is not a missing constraint.
            if (!self::columnsStillExist($key['table'], $key['columns'])) {
                continue;
            }
            $missing[] = ['name' => $key['name'], 'table' => $key['table']];
        }
        return $missing;
    }

    /**
     * Create each of the given foreign keys that is not already there.
     *
     * A key whose table or column is missing is skipped: on an install that
     * never had the table, or where a migration renamed it, the constraint is
     * not this method's business.
     *
     * @param array<array{
     *     name: string, table: string, columns: array<string>,
     *     refTable: string, refColumns: array<string>,
     *     onUpdate: string, onDelete: string
     * }> $keys Constraints to ensure
     *
     * @return int How many were created
     */
    private static function addMissingForeignKeys(array $keys): int
    {
        // Rows a missing constraint would have prevented are already in the
        // database — that is the whole premise of repairing an install that
        // lost its keys. InnoDB validates existing rows when a constraint is
        // added, so with checks on it refuses the very keys most worth putting
        // back, and the failure is only logged: the constraint is quietly lost.
        //
        // update() and Restore both happen to set this already, so the repair
        // worked from there and nowhere else. Establish it here instead of
        // depending on the caller, since the caller cannot know it is needed.
        $checksWereOn = ((int) Connection::preparedFetchValue(
            'SELECT @@FOREIGN_KEY_CHECKS AS value'
        )) === 1;
        if ($checksWereOn) {
            Connection::execute('SET FOREIGN_KEY_CHECKS = 0');
        }

        try {
            return self::addForeignKeysUnchecked($keys);
        } finally {
            if ($checksWereOn) {
                Connection::execute('SET FOREIGN_KEY_CHECKS = 1');
            }
        }
    }

    /**
     * Add the absent constraints, assuming FK checks are already off.
     *
     * @param array<array{
     *     name: string, table: string, columns: array<string>,
     *     refTable: string, refColumns: array<string>,
     *     onUpdate: string, onDelete: string
     * }> $keys Foreign keys to add if missing
     *
     * @return int How many were added
     */
    private static function addForeignKeysUnchecked(array $keys): int
    {
        $existing = [];
        foreach (self::captureForeignKeys() as $key) {
            $existing[$key['table'] . '.' . $key['name']] = true;
        }

        $quote = static fn(string $identifier): string
            => '`' . str_replace('`', '``', $identifier) . '`';

        $added = 0;
        foreach ($keys as $key) {
            if (isset($existing[$key['table'] . '.' . $key['name']])) {
                continue;
            }
            if (!self::columnsStillExist($key['table'], $key['columns'])) {
                continue;
            }
            if (!self::columnsStillExist($key['refTable'], $key['refColumns'])) {
                continue;
            }

            $columns = implode(', ', array_map($quote, $key['columns']));
            $refColumns = implode(', ', array_map($quote, $key['refColumns']));

            try {
                Connection::execute(
                    'ALTER TABLE ' . $quote($key['table']) .
                    ' ADD CONSTRAINT ' . $quote($key['name']) .
                    " FOREIGN KEY ($columns) REFERENCES " . $quote($key['refTable']) .
                    " ($refColumns)" .
                    ' ON DELETE ' . self::sanitizeReferentialAction($key['onDelete']) .
                    ' ON UPDATE ' . self::sanitizeReferentialAction($key['onUpdate'])
                );
                $added++;
            } catch (\RuntimeException $e) {
                error_log(
                    "Could not create foreign key {$key['name']} on {$key['table']} - "
                    . $e->getMessage()
                );
            }
        }

        return $added;
    }

    /**
     * Check that a table still has all of the given columns.
     *
     * @param string        $table   Table name
     * @param array<string> $columns Column names
     *
     * @return bool True when every column is present
     */
    private static function columnsStillExist(string $table, array $columns): bool
    {
        if ($columns === []) {
            return false;
        }
        $bindings = [Globals::getDatabaseName(), $table];
        $placeholders = implode(', ', array_fill(0, count($columns), '?'));
        foreach ($columns as $column) {
            $bindings[] = $column;
        }

        /** @var mixed $found */
        $found = Connection::preparedFetchValue(
            "SELECT COUNT(*) AS n FROM INFORMATION_SCHEMA.COLUMNS
             WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? AND COLUMN_NAME IN ($placeholders)",
            $bindings,
            'n'
        );

        return is_numeric($found) && (int) $found === count($columns);
    }

    /**
     * Keep a referential action to the values MySQL accepts.
     *
     * The value comes from INFORMATION_SCHEMA rather than user input, but it is
     * interpolated into DDL, so it is checked against the allowed set anyway.
     *
     * @param string $action Action read back from the database
     *
     * @return string A safe ON DELETE/ON UPDATE action
     */
    private static function sanitizeReferentialAction(string $action): string
    {
        $allowed = ['CASCADE', 'SET NULL', 'RESTRICT', 'NO ACTION', 'SET DEFAULT'];
        $action = strtoupper(trim($action));
        return in_array($action, $allowed, true) ? $action : 'RESTRICT';
    }

    /**
     * Drop all foreign key constraints from all tables in the database.
     *
     * This is needed before running migrations from scratch because
     * SET FOREIGN_KEY_CHECKS = 0 only affects INSERT/UPDATE/DELETE and DROP TABLE,
     * not ALTER TABLE MODIFY on columns referenced by FKs.
     *
     * Callers that drop keys to make way for schema changes must capture them
     * first (see captureForeignKeys()) and restore them afterwards.
     *
     * @return void
     */
    public static function dropAllForeignKeys(): void
    {
        $dbname = Globals::getDatabaseName();

        // Get all foreign key constraints in the database
        $constraints = Connection::preparedFetchAll(
            "SELECT CONSTRAINT_NAME, TABLE_NAME
             FROM INFORMATION_SCHEMA.TABLE_CONSTRAINTS
             WHERE CONSTRAINT_TYPE = 'FOREIGN KEY'
             AND TABLE_SCHEMA = ?",
            [$dbname]
        );

        foreach ($constraints as $constraint) {
            if (
                !isset($constraint['TABLE_NAME']) || !is_string($constraint['TABLE_NAME'])
                || !isset($constraint['CONSTRAINT_NAME']) || !is_string($constraint['CONSTRAINT_NAME'])
            ) {
                continue;
            }
            // Use backtick escaping for identifiers
            $escapedTable = '`' . str_replace('`', '``', $constraint['TABLE_NAME']) . '`';
            $escapedConstraint = '`' . str_replace('`', '``', $constraint['CONSTRAINT_NAME']) . '`';
            try {
                Connection::execute(
                    "ALTER TABLE $escapedTable DROP FOREIGN KEY $escapedConstraint"
                );
            } catch (\RuntimeException $e) {
                // FK might already be dropped, continue
            }
        }
    }

    /**
     * Make every reference column use the same integer type as the key it
     * points at.
     *
     * LWT names foreign key columns after the primary key they reference:
     * `texts.TxLgID` points at `languages.LgID`, `word_occurrences.Ti2TxID` at
     * `texts.TxID`, and so on. Over the years several of those primary keys
     * were widened (`languages.LgID` went from `tinyint(3)` to `int(11)` in
     * 20251221_120000_add_inter_table_foreign_keys.sql) without every
     * referencing column following along.
     *
     * A mismatched pair makes InnoDB reject the foreign key with errno 150,
     * "Foreign key constraint is incorrectly formed" — even under
     * FOREIGN_KEY_CHECKS = 0. When the constraint sits inside a CREATE TABLE,
     * the whole table is never created, which is how installs ended up without
     * `books` or `local_dictionaries` and crashed with "Table 'books' doesn't
     * exist" (issue #247).
     *
     * Each family is aligned on its widest member, so a column is only ever
     * widened, never narrowed: no value can be truncated. Families that
     * already agree are left alone, making this a no-op on healthy installs.
     *
     * Callers must have dropped foreign keys first — ALTER TABLE MODIFY is
     * refused on a column an FK points at.
     *
     * @return void
     */
    public static function alignReferenceColumnTypes(): void
    {
        // Primary keys other tables reference, by suffix. Any column whose name
        // ends with the suffix belongs to that family.
        $keySuffixes = [
            'LgID', 'TxID', 'WoID', 'SeID', 'TgID', 'T2ID', 'UsID', 'NfID', 'BkID', 'LdID',
        ];

        // Collect first, alter once per table. MODIFY COLUMN rewrites the whole
        // table, and word_occurrences carries four of these columns: altering
        // them one at a time rebuilt a three-million-row table four times over
        // (~8s each) where a single statement does it once.
        $changes = [];
        foreach ($keySuffixes as $suffix) {
            foreach (self::columnFamilyChanges($suffix) as $table => $clauses) {
                foreach ($clauses as $clause) {
                    $changes[$table][] = $clause;
                }
            }
        }

        foreach ($changes as $table => $clauses) {
            $escapedTable = '`' . str_replace('`', '``', $table) . '`';
            try {
                Connection::execute(
                    "ALTER TABLE $escapedTable " . implode(', ', $clauses)
                );
            } catch (\RuntimeException $e) {
                // One column MySQL will not touch — a foreign key still points
                // at it, say — would otherwise take the whole table's batch
                // down with it. Fall back to a statement per column so the rest
                // still get aligned; the table is rewritten more than once, but
                // only where the fast path failed.
                error_log("Could not align columns on $table in one pass - " . $e->getMessage());
                foreach ($clauses as $clause) {
                    try {
                        Connection::execute("ALTER TABLE $escapedTable $clause");
                    } catch (\RuntimeException $inner) {
                        error_log("Could not align $table - $clause - " . $inner->getMessage());
                    }
                }
            }
        }
    }

    /**
     * Work out which columns of one family are out of step with their key.
     *
     * @param string $suffix Column name suffix identifying the family (e.g. 'LgID')
     *
     * @return array<string, array<string>> MODIFY clauses, keyed by table
     */
    private static function columnFamilyChanges(string $suffix): array
    {
        $columns = Connection::preparedFetchAll(
            "SELECT TABLE_NAME, COLUMN_NAME, COLUMN_TYPE, DATA_TYPE, IS_NULLABLE, EXTRA
             FROM INFORMATION_SCHEMA.COLUMNS
             WHERE TABLE_SCHEMA = ? AND COLUMN_NAME LIKE ?",
            [Globals::getDatabaseName(), '%' . $suffix]
        );

        // Integer types from narrowest to widest; anything else is left alone.
        $widths = [
            'tinyint' => 1, 'smallint' => 2, 'mediumint' => 3, 'int' => 4, 'bigint' => 5,
        ];

        $family = [];
        $widest = null;
        foreach ($columns as $column) {
            $dataType = is_string($column['DATA_TYPE'] ?? null)
                ? strtolower((string) $column['DATA_TYPE'])
                : '';
            if (!isset($widths[$dataType])) {
                continue;
            }
            $family[] = $column;
            if ($widest === null || $widths[$dataType] > $widths[$widest['type']]) {
                $widest = [
                    'type' => $dataType,
                    'columnType' => is_string($column['COLUMN_TYPE'] ?? null)
                        ? (string) $column['COLUMN_TYPE']
                        : '',
                ];
            }
        }

        if ($widest === null || $widest['columnType'] === '') {
            return [];
        }

        $changes = [];
        foreach ($family as $column) {
            $table = $column['TABLE_NAME'] ?? null;
            $name = $column['COLUMN_NAME'] ?? null;
            $dataType = is_string($column['DATA_TYPE'] ?? null)
                ? strtolower((string) $column['DATA_TYPE'])
                : '';
            if (!is_string($table) || !is_string($name) || $dataType === $widest['type']) {
                continue;
            }

            $escapedColumn = '`' . str_replace('`', '``', $name) . '`';
            // Preserve nullability and AUTO_INCREMENT; only the width changes.
            $nullable = ($column['IS_NULLABLE'] ?? 'YES') === 'YES' ? 'NULL' : 'NOT NULL';
            $extra = is_string($column['EXTRA'] ?? null)
                && stripos((string) $column['EXTRA'], 'auto_increment') !== false
                ? ' AUTO_INCREMENT'
                : '';

            $changes[$table][] =
                "MODIFY COLUMN $escapedColumn {$widest['columnType']} $nullable$extra";
        }

        return $changes;
    }

    /**
     * Add a prefix to table in a SQL query string.
     *
     * @param string $sql_line SQL string to prefix.
     * @param string $prefix   Prefix to add
     *
     * @return string Prefixed SQL query
     */
    public static function prefixQuery(string $sql_line, string $prefix): string
    {
        // Handle INSERT INTO (case-insensitive)
        if (strcasecmp(substr($sql_line, 0, 12), "INSERT INTO ") === 0) {
            return substr($sql_line, 0, 12) . $prefix . substr($sql_line, 12);
        }
        // Handle DROP/CREATE/ALTER TABLE with optional IF [NOT] EXISTS (case-insensitive)
        $res = preg_match(
            '/^(?:DROP|CREATE|ALTER) TABLE (?:IF (?:NOT )?EXISTS )?`?/i',
            $sql_line,
            $matches
        );
        if ($res) {
            return $matches[0] . $prefix .
            substr($sql_line, strlen($matches[0]));
        }
        return $sql_line;
    }

    /**
     * Reparse all texts in order.
     *
     * @return void
     */
    public static function reparseAllTexts(): void
    {
        // Use DELETE instead of TRUNCATE to respect foreign key constraints
        // Delete word_occurrences first (child), then sentences (parent)
        // Use raw DELETE FROM to delete all records
        Connection::execute("DELETE FROM word_occurrences");
        Connection::execute("DELETE FROM sentences");
        Maintenance::adjustAutoIncrement('sentences', 'SeID');
        Maintenance::initWordCount();
        // Only reparse texts that have a valid language reference
        $rows = QueryBuilder::table('texts')
            ->select(['texts.TxID', 'texts.TxLgID'])
            ->join('languages', 'texts.TxLgID', '=', 'languages.LgID')
            ->getPrepared();
        foreach ($rows as $record) {
            $id = (int) $record['TxID'];
            $lgId = (int) $record['TxLgID'];
            /** @var string|null $textValue */
            $textValue = QueryBuilder::table('texts')
                ->where('TxID', '=', $id)
                ->valuePrepared('TxText');
            TextParsing::parseAndSave(
                (string)$textValue,
                $lgId,
                $id
            );
        }
    }

    /**
     * Get list of all migration files from the migrations directory.
     *
     * @return array<string> Sorted list of migration filenames
     */
    public static function getMigrationFiles(): array
    {
        $migrationsDir = __DIR__ . '/../../../../db/migrations/';
        $files = glob($migrationsDir . '*.sql');
        if ($files === false) {
            return [];
        }
        // Extract just the filenames and sort them
        $filenames = array_map('basename', $files);
        sort($filenames);
        return $filenames;
    }

    /**
     * Get list of migrations that ran without any failing statement.
     *
     * @return array<string> List of successfully applied migration filenames
     */
    public static function getAppliedMigrations(): array
    {
        return self::fetchMigrationNames(
            "SELECT filename FROM _migrations WHERE status = '" . self::STATUS_APPLIED . "'"
        );
    }

    /**
     * Get list of migrations that have an entry in `_migrations`, whatever
     * their outcome.
     *
     * These are the migrations that have already been attempted at least once,
     * so they are not "new" any more.
     *
     * @return array<string> List of recorded migration filenames
     */
    public static function getRecordedMigrations(): array
    {
        return self::fetchMigrationNames("SELECT filename FROM _migrations");
    }

    /**
     * Give every failed migration its attempts back.
     *
     * Called when an upgrade brings new migration files along. A migration
     * usually fails on a prerequisite rather than on itself, and the new files
     * may be exactly what repairs it, so an exhausted attempt counter should
     * not be what keeps it from ever running again.
     *
     * @return void
     */
    public static function restoreRetryBudget(): void
    {
        try {
            Connection::execute(
                "UPDATE _migrations SET attempts = 0
                 WHERE status = '" . self::STATUS_FAILED . "'"
            );
        } catch (\RuntimeException $e) {
            // Nothing to restore on an install too old to have the table yet
            error_log('Could not restore migration retry budget: ' . $e->getMessage());
        }
    }

    /**
     * Get migrations that failed and are still worth retrying.
     *
     * A migration is retried until MAX_ATTEMPTS is reached; past that it stays
     * on record as failed so an administrator can investigate — until an
     * upgrade restores the budget, see {@see restoreRetryBudget()}.
     *
     * @return array<string> List of failed migration filenames
     */
    public static function getRetryableMigrations(): array
    {
        return self::fetchMigrationNames(
            "SELECT filename FROM _migrations
             WHERE status = '" . self::STATUS_FAILED . "' AND attempts < " . self::MAX_ATTEMPTS
        );
    }

    /**
     * Get the migrations that failed, with the error that made them fail.
     *
     * Meant for administrative reporting: a non-empty result means the schema
     * is incomplete and some features will fail at runtime.
     *
     * @return array<array{filename: string, attempts: int, error: string}> Failed migrations
     */
    public static function getFailedMigrations(): array
    {
        try {
            $rows = Connection::fetchAll(
                "SELECT filename, attempts, error FROM _migrations
                 WHERE status = '" . self::STATUS_FAILED . "'
                 ORDER BY filename"
            );
        } catch (\RuntimeException $e) {
            // Table or columns don't exist yet
            return [];
        }

        $failed = [];
        foreach ($rows as $row) {
            if (!isset($row['filename']) || !is_string($row['filename'])) {
                continue;
            }
            $attempts = $row['attempts'] ?? 0;
            $error = $row['error'] ?? '';
            $failed[] = [
                'filename' => $row['filename'],
                'attempts' => is_numeric($attempts) ? (int) $attempts : 0,
                'error'    => is_string($error) ? $error : '',
            ];
        }
        return $failed;
    }

    /**
     * Run a query returning a `filename` column and collect the values.
     *
     * @param string $sql Query selecting the filename column
     *
     * @return array<string> List of migration filenames
     */
    private static function fetchMigrationNames(string $sql): array
    {
        try {
            $rows = Connection::fetchAll($sql);
        } catch (\RuntimeException $e) {
            // Table doesn't exist yet
            return [];
        }

        $filenames = [];
        foreach ($rows as $row) {
            if (isset($row['filename']) && is_string($row['filename'])) {
                $filenames[] = $row['filename'];
            }
        }
        return $filenames;
    }

    /**
     * Run every statement of one migration file.
     *
     * Statements are independent: one failing statement does not stop the
     * others, because a migration often mixes work that is still needed with
     * work a fresh install already has.
     *
     * @param string $filepath Full path to the migration file
     * @param string $filename Migration filename, for logging
     *
     * @return string|null The first real error, or null when nothing broke
     */
    private static function runMigrationFile(string $filepath, string $filename): ?string
    {
        $firstError = null;
        foreach (SqlFileParser::parseFile($filepath) as $sql_query) {
            if (trim($sql_query) === '') {
                continue;
            }
            try {
                Connection::execute($sql_query);
            } catch (\RuntimeException $e) {
                // Continue with the remaining statements either way: a fresh
                // install runs legacy migrations against table names that
                // baseline.sql never created, and those fail by design.
                //
                // Classify before logging, and say which kind this was. Logging
                // both under one wording buried the eight real failures of
                // issue #275 among ~170 expected ones, in a log where they read
                // identically (#285).
                if (self::isHarmlessFailure($e)) {
                    error_log("Migration statement skipped (expected): $filename - " . $e->getMessage());
                    continue;
                }
                error_log("Migration failed: $filename - " . $e->getMessage());
                if ($firstError === null) {
                    $firstError = $e->getMessage();
                }
            }
        }
        return $firstError;
    }

    /**
     * Tell an expected statement failure apart from a real one.
     *
     * @param \RuntimeException $e Exception raised while running a statement
     *
     * @return bool True when the failure can be safely ignored
     */
    private static function isHarmlessFailure(\RuntimeException $e): bool
    {
        if (!$e instanceof DatabaseException) {
            return false;
        }
        $code = $e->getSqlErrorCode();
        return $code !== null && in_array($code, self::HARMLESS_SQL_ERRORS, true);
    }

    /**
     * Record the outcome of a migration run.
     *
     * Re-recording the same migration keeps a single row: the status and error
     * are overwritten and the attempt counter is incremented, so a migration
     * that failed once and succeeds on a later upgrade ends up as applied.
     *
     * @param string      $filename The migration filename
     * @param string      $checksum SHA-256 hash of the migration file
     * @param string      $status   STATUS_APPLIED or STATUS_FAILED
     * @param string|null $error    Error message when the migration failed
     *
     * @return void
     */
    public static function recordMigration(
        string $filename,
        string $checksum = '',
        string $status = self::STATUS_APPLIED,
        ?string $error = null
    ): void {
        // Keep the message short enough to stay readable in the admin report.
        if ($error !== null && mb_strlen($error) > 1000) {
            $error = mb_substr($error, 0, 1000) . '…';
        }

        Connection::preparedExecute(
            "INSERT INTO _migrations (filename, applied_at, checksum, status, attempts, error)
             VALUES (?, NOW(), ?, ?, 1, ?)
             ON DUPLICATE KEY UPDATE
                applied_at = NOW(),
                checksum = VALUES(checksum),
                status = VALUES(status),
                attempts = attempts + 1,
                error = VALUES(error)",
            [$filename, $checksum, $status, $error]
        );
    }

    /**
     * Calculate SHA-256 checksum for a migration file.
     *
     * @param string $filepath Full path to the migration file
     *
     * @return string SHA-256 hash or empty string if file not readable
     */
    public static function calculateChecksum(string $filepath): string
    {
        if (!file_exists($filepath) || !is_readable($filepath)) {
            return '';
        }
        $hash = hash_file('sha256', $filepath);
        return $hash !== false ? $hash : '';
    }

    /**
     * Validate that applied migrations haven't been modified.
     *
     * Checks the checksum of each applied migration against its stored value.
     * This detects tampering or accidental modification of migration files.
     *
     * @return array{valid: bool, errors: array<string>} Validation result
     */
    public static function validateMigrationIntegrity(): array
    {
        $errors = [];
        $migrationsDir = __DIR__ . '/../../../../db/migrations/';

        try {
            $rows = Connection::fetchAll(
                "SELECT filename, checksum FROM _migrations WHERE checksum IS NOT NULL AND checksum != ''"
            );
        } catch (\RuntimeException $e) {
            // Table doesn't exist or no checksum column yet
            return ['valid' => true, 'errors' => []];
        }

        foreach ($rows as $row) {
            $filename = $row['filename'] ?? null;
            $storedChecksum = $row['checksum'] ?? null;
            if (!is_string($filename) || !is_string($storedChecksum)) {
                continue;
            }
            $filepath = $migrationsDir . $filename;

            if (!file_exists($filepath)) {
                $errors[] = "Migration file missing: $filename";
                continue;
            }

            $currentChecksum = self::calculateChecksum($filepath);
            if ($currentChecksum !== $storedChecksum) {
                $errors[] = "Migration file integrity check failed: $filename (file was modified after being applied)";
            }
        }

        return [
            'valid' => count($errors) === 0,
            'errors' => $errors
        ];
    }

    /**
     * Upgrade the _migrations table from old schema to new schema.
     *
     * Old schema stored migrations to be run; new schema tracks applied migrations.
     * This method adds the applied_at and checksum columns.
     *
     * @return void
     */
    public static function upgradeMigrationsTable(): void
    {
        // Check which columns exist
        $dbname = Globals::getDatabaseName();
        $columns = Connection::preparedFetchAll(
            "SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS
             WHERE TABLE_SCHEMA = ? AND TABLE_NAME = '_migrations'",
            [$dbname]
        );
        $columnNames = array_column($columns, 'COLUMN_NAME');

        if (!in_array('applied_at', $columnNames)) {
            // Add applied_at column
            Connection::execute(
                "ALTER TABLE _migrations ADD COLUMN applied_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP"
            );
        }

        if (!in_array('checksum', $columnNames)) {
            // Add checksum column for integrity validation
            Connection::execute(
                "ALTER TABLE _migrations ADD COLUMN checksum VARCHAR(64) DEFAULT NULL"
            );
        }

        if (!in_array('status', $columnNames)) {
            // Track whether the migration actually ran through. Rows written
            // before this column existed are assumed to have succeeded.
            Connection::execute(
                "ALTER TABLE _migrations
                 ADD COLUMN status VARCHAR(16) NOT NULL DEFAULT '" . self::STATUS_APPLIED . "'"
            );
        }

        if (!in_array('attempts', $columnNames)) {
            Connection::execute(
                "ALTER TABLE _migrations ADD COLUMN attempts INT UNSIGNED NOT NULL DEFAULT 1"
            );
        }

        if (!in_array('error', $columnNames)) {
            Connection::execute(
                "ALTER TABLE _migrations ADD COLUMN error TEXT DEFAULT NULL"
            );
        }
    }

    /**
     * Update the database if it is using an outdate version.
     *
     * @return void
     */
    public static function update(): void
    {
        $dbname = Globals::getDatabaseName();

        // DB Version
        $currversion = ApplicationInfo::getVersionNumber();

        try {
            /** @var string|null $dbversion */
            $dbversion = QueryBuilder::table('settings')
                ->where('StKey', '=', 'dbversion')
                ->valuePrepared('StValue');
            if ($dbversion === null) {
                $dbversion = 'v001000000';
            }
        } catch (\RuntimeException $e) {
            ErrorHandler::die(
                'There is something wrong with your database ' . $dbname .
                '. Please reinstall.'
            );
        }

        // Always check for pending migrations, even if dbversion is current.
        // This handles fix migrations added after a version was released.
        $allMigrations = self::getMigrationFiles();
        $newMigrations = array_diff($allMigrations, self::getRecordedMigrations());

        // A migration that failed before gets another chance, bounded by
        // MAX_ATTEMPTS so ordinary requests cannot re-run broken SQL forever.
        //
        // This used to be gated on `count($newMigrations) > 0`, which made the
        // retry unreachable exactly where it was needed most: a fresh install
        // records every migration on its first run, so the second run has no
        // new ones, the gate never opens, and a migration that failed stays
        // failed at one attempt until some later release happens to add a file
        // (#285). An install left without `term_schedule` that way is the one
        // in #275.
        //
        // The gate's own intent survives below: a migration often fails on a
        // prerequisite that a later migration repairs, so an upgrade that
        // brings new files restores the attempt budget of everything that
        // failed, and the retry starts over rather than staying exhausted.
        if (count($newMigrations) > 0) {
            self::restoreRetryBudget();
        }

        $retryMigrations = array_intersect(
            self::getRetryableMigrations(),
            $allMigrations
        );

        $pendingMigrations = array_merge($newMigrations, $retryMigrations);
        sort($pendingMigrations);

        // Do DB Updates if tables seem to be old versions
        $needsVersionUpdate = $dbversion < $currversion;

        if ($needsVersionUpdate) {
            if (
                'utf8utf8_general_ci' != Connection::preparedFetchValue(
                    'SELECT concat(default_character_set_name, default_collation_name) AS collation
                FROM information_schema.SCHEMATA
                WHERE schema_name = ?',
                    [$dbname],
                    'collation'
                )
            ) {
                Connection::query("SET collation_connection = 'utf8_general_ci'");
                // Note: ALTER DATABASE doesn't support prepared statements
                // Database name comes from trusted config, using backtick escaping
                $escapedDbName = '`' . str_replace('`', '``', $dbname) . '`';
                Connection::execute(
                    'ALTER DATABASE ' . $escapedDbName .
                    ' CHARACTER SET utf8 COLLATE utf8_general_ci'
                );
            }
        }

        if (count($pendingMigrations) > 0) {
            // Validate integrity of already-applied migrations
            $integrityCheck = self::validateMigrationIntegrity();
            if (!$integrityCheck['valid']) {
                // Log errors but don't block - allow admin to investigate
                foreach ($integrityCheck['errors'] as $error) {
                    error_log('Migration integrity warning: ' . $error);
                }
            }

            $migrationsDir = __DIR__ . '/../../../../db/migrations/';

            // Drop all FK constraints before running migrations.
            // SET FOREIGN_KEY_CHECKS = 0 only affects INSERT/UPDATE/DELETE and DROP TABLE,
            // not ALTER TABLE MODIFY on columns referenced by FKs.
            // The migrations recreate the ones they own; everything else is put
            // back from this snapshot once the run is over.
            $foreignKeys = self::captureForeignKeys();
            self::dropAllForeignKeys();

            // With the FKs out of the way, repair reference columns that drifted
            // from the key they point at. A migration creating a table with an
            // FK on a mismatched column fails outright, so this has to happen
            // before they run.
            self::alignReferenceColumnTypes();

            // Disable FK checks during migrations to handle legacy data
            // that may not satisfy new FK constraints until fully migrated
            Connection::execute("SET FOREIGN_KEY_CHECKS = 0");
            try {
                $errors = [];
                foreach ($pendingMigrations as $filename) {
                    $error = self::runMigrationFile($migrationsDir . $filename, $filename);
                    if ($error !== null) {
                        $errors[$filename] = $error;
                    }
                }

                // Migrations widen keys as they go, so a column can end up out
                // of step with the key it references only *after* the run — that
                // is what leaves fresh installs without some of their foreign
                // keys. Realign and give the failures one more go before writing
                // them down as failed.
                //
                // The foreign keys created by the run above are deliberately
                // left in place: a column that already carries one is by
                // definition type-compatible with its key and needs no
                // realignment, and dropping them here would throw away the work
                // the successful migrations just did.
                if ($errors !== []) {
                    self::alignReferenceColumnTypes();
                    foreach (array_keys($errors) as $filename) {
                        $error = self::runMigrationFile($migrationsDir . $filename, $filename);
                        if ($error === null) {
                            unset($errors[$filename]);
                        } else {
                            $errors[$filename] = $error;
                        }
                    }
                }

                foreach ($pendingMigrations as $filename) {
                    // Record the outcome. A migration with a failing statement is
                    // recorded as failed rather than applied: marking it applied
                    // would freeze a half-created schema in place forever, with
                    // the missing tables only surfacing as runtime errors much
                    // later (see issue #247).
                    self::recordMigration(
                        $filename,
                        self::calculateChecksum($migrationsDir . $filename),
                        isset($errors[$filename]) ? self::STATUS_FAILED : self::STATUS_APPLIED,
                        $errors[$filename] ?? null
                    );
                }
            } finally {
                // Put back every constraint the run did not recreate itself,
                // then add any the install lost to an earlier upgrade. Still
                // under FOREIGN_KEY_CHECKS = 0, the same conditions the
                // migrations create their keys under, so legacy rows that
                // violate a constraint do not cost the database its integrity
                // rules on every later write.
                self::restoreForeignKeys($foreignKeys);
                self::reconcileForeignKeys();
                Connection::execute("SET FOREIGN_KEY_CHECKS = 1");

                // The run may have created a table that code later in this same
                // request probes for before naming it in SQL.
                Connection::forgetTableCache();
            }
        }

        if ($needsVersionUpdate) {
            Connection::execute(
                "CREATE TABLE IF NOT EXISTS tts (
                    TtsID mediumint(8) unsigned NOT NULL AUTO_INCREMENT,
                    TtsTxt varchar(100) CHARACTER SET utf8 COLLATE utf8_bin NOT NULL,
                    TtsLc varchar(8) CHARACTER SET utf8 COLLATE utf8_bin NOT NULL,
                    PRIMARY KEY (TtsID),
                    UNIQUE KEY TtsTxtLC (TtsTxt,TtsLc)
                ) ENGINE=MyISAM DEFAULT CHARSET=utf8 PACK_KEYS=1"
            );

            // Set database to current version
            Settings::save('dbversion', $currversion);
            Settings::save('lastscorecalc', '0');  // Reset to trigger recalculation
        }
    }

    /**
     * Check and/or update the database.
     *
     * @return void
     */
    public static function checkAndUpdate(): void
    {
        $tables = array();

        // Get database name for INFORMATION_SCHEMA query
        $dbname = Globals::getDatabaseName();

        // Get all core LWT tables (no prefix in multi-user system)
        $res = Connection::preparedFetchAll(
            "SELECT TABLE_NAME
             FROM INFORMATION_SCHEMA.TABLES
             WHERE TABLE_SCHEMA = ?
             AND TABLE_NAME IN (
                'languages', 'texts', 'words', 'sentences',
                'word_occurrences', 'tags', 'text_tags', 'word_tag_map',
                'text_tag_map', 'news_feeds', 'feed_links',
                'settings', '_migrations'
             )",
            [$dbname]
        );
        foreach ($res as $row) {
            $tables[] = (string) $row['TABLE_NAME'];
        }

        /// counter for cache rebuild
        $count = 0;

        // Rebuild in missing table
        $queries = SqlFileParser::parseFile(__DIR__ . "/../../../../db/schema/baseline.sql");
        foreach ($queries as $query) {
            // Execute schema queries directly - no prefix in multi-user system
            $count += (int) Connection::execute($query);
        }

        // Ensure _migrations table has the new schema with applied_at column
        self::upgradeMigrationsTable();

        // Update the database (if necessary)
        self::update();

        if (!in_array("word_occurrences", $tables) && !in_array("word_occurrences", $tables)) {
            // Add data from the old database system
            if (in_array("textitems", $tables)) {
                // Complex migration query - use raw SQL
                Connection::execute(
                    "INSERT INTO word_occurrences (
                        Ti2WoID, Ti2LgID, Ti2TxID, Ti2SeID, Ti2Order, Ti2WordCount,
                        Ti2Text
                    )
                    SELECT IFNULL(WoID,0), TiLgID, TiTxID, TiSeID, TiOrder,
                    CASE WHEN TiIsNotWord = 1 THEN 0 ELSE TiWordCount END as WordCount,
                    CASE
                        WHEN STRCMP(TiText COLLATE utf8_bin,TiTextLC)!=0 OR TiWordCount=1
                        THEN TiText
                        ELSE ''
                    END AS Text
                    FROM textitems
                    LEFT JOIN words ON TiTextLC=WoTextLC AND TiLgID=WoLgID
                    WHERE TiWordCount<2 OR WoID IS NOT NULL"
                );
                QueryBuilder::table('textitems')->truncate();
            }
            $count++;
        }

        if ($count > 0) {
            // Rebuild Text Cache if cache tables new
            self::reparseAllTexts();
        }


        // Clean Word/Texttags and optimize db, once per day.
        //
        // This used to recompute every term's review score here as well, since
        // the legacy score was a stored number that went stale the moment the
        // day rolled over. A term's due date does not go stale, so the whole
        // sweep -- an UPDATE across the entire words table, on the first
        // request after midnight -- is simply gone.
        $lastscorecalc = Settings::get('lastscorecalc');
        $today = date('Y-m-d');
        if ($lastscorecalc != $today) {
            // Clean up orphaned word_tag_map (tags deleted)
            Connection::execute(
                "DELETE word_tag_map
                FROM (word_tag_map LEFT JOIN tags on WtTgID = TgID)
                WHERE TgID IS NULL"
            );
            // Clean up orphaned word_tag_map (words deleted)
            Connection::execute(
                "DELETE word_tag_map
                FROM (word_tag_map LEFT JOIN words ON WtWoID = WoID)
                WHERE WoID IS NULL"
            );
            // Clean up orphaned text_tag_map (text_tags deleted)
            Connection::execute(
                "DELETE text_tag_map
                FROM (text_tag_map LEFT JOIN text_tags ON TtT2ID = T2ID)
                WHERE T2ID IS NULL"
            );
            // Clean up orphaned text_tag_map (texts deleted)
            Connection::execute(
                "DELETE text_tag_map
                FROM (text_tag_map LEFT JOIN texts ON TtTxID = TxID)
                WHERE TxID IS NULL"
            );
            Maintenance::optimizeDatabase();
            Settings::save('lastscorecalc', $today);
        }
    }
}
