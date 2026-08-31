<?php

/**
 * Set up test database for integration tests.
 *
 * This script creates a test database, applies the baseline schema,
 * and runs all migrations. Run this before running integration tests.
 *
 * Usage:
 *   php tests/setup_test_db.php           # Setup test database
 *   php tests/setup_test_db.php --drop    # Drop and recreate test database
 *   php tests/setup_test_db.php --status  # Show test database status
 *
 * PHP version 8.2
 *
 * @category Testing
 * @package  Lwt\Tests
 * @author   LWT Project <lwt-project@hotmail.com>
 * @license  Unlicense <http://unlicense.org/>
 * @since    3.0.0
 */

declare(strict_types=1);

namespace Lwt\Tests;

use Lwt\Shared\Infrastructure\Bootstrap\EnvLoader;
use Lwt\Shared\Infrastructure\Database\SqlFileParser;

// Load environment configuration
require_once __DIR__ . '/../src/Shared/Infrastructure/Bootstrap/EnvLoader.php';
// Autoloader for SqlFileParser, so migrations are split into statements exactly
// the way Migrations::update() splits them in production.
require_once __DIR__ . '/../vendor/autoload.php';

// Parse command line arguments
$drop = in_array('--drop', $argv ?? []);
$statusOnly = in_array('--status', $argv ?? []);
$quiet = in_array('--quiet', $argv ?? []) || in_array('-q', $argv ?? []);

// Load .env configuration
$envFile = __DIR__ . '/../.env';
if (!file_exists($envFile)) {
    fwrite(STDERR, "Error: .env file not found at $envFile\n");
    fwrite(STDERR, "Copy .env.example to .env and configure your database credentials.\n");
    exit(1);
}

EnvLoader::load($envFile);
$config = EnvLoader::getDatabaseConfig();

$testDbName = 'test_' . $config['dbname'];

// Connect without database to create it
$conn = @mysqli_connect(
    $config['server'],
    $config['userid'],
    $config['passwd'],
    '',
    socket: $config['socket'] ?? ''
);

if (!$conn) {
    fwrite(STDERR, "Error: Could not connect to MySQL server.\n");
    fwrite(STDERR, "Check your .env database credentials.\n");
    fwrite(STDERR, "MySQL error: " . mysqli_connect_error() . "\n");
    exit(1);
}

// Disable mysqli exception mode to allow graceful error handling
mysqli_report(MYSQLI_REPORT_OFF);

/**
 * Output a message unless quiet mode is enabled.
 */
function output(string $message, bool $quiet): void
{
    if (!$quiet) {
        echo $message;
    }
}

/**
 * Get the count of tables in the test database.
 */
function getTableCount(\mysqli $conn, string $dbName): int
{
    $result = mysqli_query(
        $conn,
        "SELECT COUNT(*) as cnt FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = '$dbName'"
    );
    if ($result) {
        $row = mysqli_fetch_assoc($result);
        mysqli_free_result($result);
        return (int) ($row['cnt'] ?? 0);
    }
    return 0;
}

/**
 * Get the count of applied migrations.
 */
function getMigrationCount(\mysqli $conn, string $dbName): int
{
    $result = mysqli_query($conn, "SELECT COUNT(*) as cnt FROM `$dbName`._migrations");
    if ($result) {
        $row = mysqli_fetch_assoc($result);
        mysqli_free_result($result);
        return (int) ($row['cnt'] ?? 0);
    }
    return 0;
}

/**
 * Check if database exists.
 */
function databaseExists(\mysqli $conn, string $dbName): bool
{
    $result = mysqli_query(
        $conn,
        "SELECT SCHEMA_NAME FROM INFORMATION_SCHEMA.SCHEMATA WHERE SCHEMA_NAME = '$dbName'"
    );
    if ($result) {
        $exists = mysqli_num_rows($result) > 0;
        mysqli_free_result($result);
        return $exists;
    }
    return false;
}

/**
 * Check if foreign keys are present.
 */
function hasForeignKeys(\mysqli $conn, string $dbName): bool
{
    $result = mysqli_query(
        $conn,
        "SELECT COUNT(*) as cnt FROM INFORMATION_SCHEMA.TABLE_CONSTRAINTS
         WHERE TABLE_SCHEMA = '$dbName'
         AND CONSTRAINT_TYPE = 'FOREIGN KEY'
         AND TABLE_NAME = 'word_occurrences'"
    );
    if ($result) {
        $row = mysqli_fetch_assoc($result);
        mysqli_free_result($result);
        return ((int) ($row['cnt'] ?? 0)) > 0;
    }
    return false;
}

// Status only mode
if ($statusOnly) {
    echo "Test Database Status\n";
    echo "====================\n";
    echo "Database name: $testDbName\n";

    if (!databaseExists($conn, $testDbName)) {
        echo "Status: NOT EXISTS\n";
        echo "\nRun 'composer test:setup-db' to create the test database.\n";
        mysqli_close($conn);
        exit(0);
    }

    mysqli_select_db($conn, $testDbName);
    $tableCount = getTableCount($conn, $testDbName);
    $migrationCount = getMigrationCount($conn, $testDbName);
    $hasFk = hasForeignKeys($conn, $testDbName);

    echo "Status: EXISTS\n";
    echo "Tables: $tableCount\n";
    echo "Applied migrations: $migrationCount\n";
    echo "Foreign keys: " . ($hasFk ? "YES" : "NO") . "\n";

    // Count available migrations
    $migrationsDir = __DIR__ . '/../db/migrations/';
    $migrationFiles = glob($migrationsDir . '*.sql');
    $totalMigrations = $migrationFiles ? count($migrationFiles) : 0;

    if ($migrationCount < $totalMigrations) {
        echo "\nWarning: $migrationCount of $totalMigrations migrations applied.\n";
        echo "Run 'composer test:setup-db' to apply pending migrations.\n";
    }

    mysqli_close($conn);
    exit(0);
}

// Drop database if requested
if ($drop) {
    output("Dropping test database '$testDbName'...\n", $quiet);
    if (!mysqli_query($conn, "DROP DATABASE IF EXISTS `$testDbName`")) {
        fwrite(STDERR, "Error dropping database: " . mysqli_error($conn) . "\n");
        mysqli_close($conn);
        exit(1);
    }
    output("Database dropped.\n", $quiet);
}

// Create database if it doesn't exist
output("Creating test database '$testDbName'...\n", $quiet);
if (!mysqli_query($conn, "CREATE DATABASE IF NOT EXISTS `$testDbName` CHARACTER SET utf8 COLLATE utf8_general_ci")) {
    fwrite(STDERR, "Error creating database: " . mysqli_error($conn) . "\n");
    mysqli_close($conn);
    exit(1);
}

// Select the test database
if (!mysqli_select_db($conn, $testDbName)) {
    fwrite(STDERR, "Error selecting database: " . mysqli_error($conn) . "\n");
    mysqli_close($conn);
    exit(1);
}

output("Database created.\n", $quiet);

// Apply baseline schema
output("Applying baseline schema...\n", $quiet);
$baselineFile = __DIR__ . '/../db/schema/baseline.sql';
if (!file_exists($baselineFile)) {
    fwrite(STDERR, "Error: Baseline schema file not found at $baselineFile\n");
    mysqli_close($conn);
    exit(1);
}

$baselineSql = file_get_contents($baselineFile);
if ($baselineSql === false) {
    fwrite(STDERR, "Error reading baseline schema file.\n");
    mysqli_close($conn);
    exit(1);
}

// Execute baseline schema (multi-statement)
mysqli_multi_query($conn, $baselineSql);
do {
    if ($result = mysqli_store_result($conn)) {
        mysqli_free_result($result);
    }
} while (mysqli_next_result($conn));

if (mysqli_errno($conn)) {
    fwrite(STDERR, "Error applying baseline schema: " . mysqli_error($conn) . "\n");
    mysqli_close($conn);
    exit(1);
}

output("Baseline schema applied.\n", $quiet);

// Get list of migration files
$migrationsDir = __DIR__ . '/../db/migrations/';
$migrationFiles = glob($migrationsDir . '*.sql');
if ($migrationFiles === false) {
    $migrationFiles = [];
}
sort($migrationFiles);

// Production (Migrations::checkAndUpdate) applies baseline.sql and then runs
// every pending migration in order, tolerating per-statement failures. This
// script has to do the same, or the test database drifts from what users
// actually have.
//
// It used to mark migrations as applied without running them, on the premise
// that "the baseline already includes all table structures". That premise is
// false for anything added after the baseline was last regenerated — `books`,
// `local_dictionaries` and `local_dictionary_entries` are all absent from it —
// so those tables never existed here while their migrations were recorded as
// applied. Every test touching them then skipped silently, locally and on CI.
//
// Skipping the FK migration also left languages.LgID as tinyint(3), because
// that migration is what widens it to int(11); the manual FK list below never
// carried the type changes. Any later migration with an FK to languages(LgID)
// then failed with errno 150 ("Foreign key constraint is incorrectly formed"),
// which is not suppressed by FOREIGN_KEY_CHECKS=0.
$columnDefaultsMigration = '20260107_120000_add_language_column_defaults.sql';
$fkMigration = '20251221_120000_add_inter_table_foreign_keys.sql';

// Only pending migrations are run, as in production: this script is also
// invoked non-destructively before each integration run, and re-executing
// every migration each time would be both slow and unsafe for any migration
// that moves data rather than just shaping schema.
$alreadyApplied = [];
$result = mysqli_query($conn, "SELECT filename FROM _migrations");
if ($result) {
    while ($row = mysqli_fetch_assoc($result)) {
        $alreadyApplied[] = $row['filename'];
    }
    mysqli_free_result($result);
}

output("Applying migrations...\n", $quiet);
mysqli_query($conn, "SET FOREIGN_KEY_CHECKS = 0");
$migrationsRun = 0;
$statementFailures = 0;
foreach ($migrationFiles as $migrationFile) {
    $filename = basename($migrationFile);

    // Applied explicitly further down: applying baseline.sql through
    // mysqli_multi_query drops the DEFAULT '' clauses this migration relies on.
    if ($filename === $columnDefaultsMigration) {
        continue;
    }

    if (in_array($filename, $alreadyApplied, true)) {
        continue;
    }

    foreach (SqlFileParser::parseFile($migrationFile) as $statement) {
        if (trim($statement) === '') {
            continue;
        }
        if (!@mysqli_query($conn, $statement)) {
            // Match production, which logs a failed statement and carries on:
            // legacy migrations reference tables the modern baseline no longer
            // has, and those failures are expected.
            $statementFailures++;
        }
    }

    $escapedFilename = mysqli_real_escape_string($conn, $filename);
    mysqli_query($conn, "INSERT IGNORE INTO _migrations (filename, applied_at) VALUES ('$escapedFilename', NOW())");
    $migrationsRun++;
}
mysqli_query($conn, "SET FOREIGN_KEY_CHECKS = 1");
output(
    "Ran $migrationsRun migration(s)"
    . ($statementFailures > 0 ? " ($statementFailures statement(s) skipped)" : '') . ".\n",
    $quiet
);

// Get applied migrations (to check if FK migration was already applied)
$appliedMigrations = [];
$result = mysqli_query($conn, "SELECT filename FROM _migrations");
if ($result) {
    while ($row = mysqli_fetch_assoc($result)) {
        $appliedMigrations[] = $row['filename'];
    }
    mysqli_free_result($result);
}

// Complete the inter-table foreign keys.
//
// The FK migration above only gets us part of the way: it was written against
// the legacy `textitems2` table, which the modern baseline creates as
// `word_occurrences`, so its statements for that table fail and the FKs the
// integration tests rely on never appear. The list below names the modern
// tables. It runs unconditionally because adding an existing constraint is
// reported as a duplicate and ignored, so it is safe to re-apply.
$appliedCount = 0;
output("Applying foreign key constraints...\n", $quiet);

// Widen the referencing columns first. The FK migration widens the
// referenced keys (languages.LgID, texts.TxID, words.WoID, sentences.SeID,
// tags.TgID) to int(11), but its statements for `textitems2` and
// `newsfeeds` no-op against a modern baseline that names those tables
// `word_occurrences` and `news_feeds`. Their columns are left at the
// baseline's narrower widths, and an FK between mismatched integer types
// fails with errno 150. These MODIFYs finish the job under the new names.
$columnWidening = [
    "ALTER TABLE news_feeds MODIFY COLUMN NfID int(11) unsigned NOT NULL AUTO_INCREMENT",
    "ALTER TABLE news_feeds MODIFY COLUMN NfLgID int(11) unsigned NOT NULL",
    "ALTER TABLE feed_links MODIFY COLUMN FlNfID int(11) unsigned NOT NULL",
    "ALTER TABLE word_occurrences MODIFY COLUMN Ti2TxID int(11) unsigned NOT NULL",
    "ALTER TABLE word_occurrences MODIFY COLUMN Ti2SeID int(11) unsigned NOT NULL",
    "ALTER TABLE word_occurrences MODIFY COLUMN Ti2WoID int(11) unsigned DEFAULT NULL",
    "ALTER TABLE word_tag_map MODIFY COLUMN WtWoID int(11) unsigned NOT NULL",
    "ALTER TABLE word_tag_map MODIFY COLUMN WtTgID int(11) unsigned NOT NULL",
    "ALTER TABLE text_tags MODIFY COLUMN T2ID int(11) unsigned NOT NULL AUTO_INCREMENT",
    "ALTER TABLE text_tag_map MODIFY COLUMN TtTxID int(11) unsigned NOT NULL",
    "ALTER TABLE text_tag_map MODIFY COLUMN TtT2ID int(11) unsigned NOT NULL",
];
foreach ($columnWidening as $sql) {
    @mysqli_query($conn, $sql);
}

// FK constraints to add (column types now match on both sides)
$fkConstraints = [
    // Language references
    "ALTER TABLE texts ADD CONSTRAINT fk_texts_language " .
        "FOREIGN KEY (TxLgID) REFERENCES languages(LgID) ON DELETE CASCADE",
    "ALTER TABLE words ADD CONSTRAINT fk_words_language " .
        "FOREIGN KEY (WoLgID) REFERENCES languages(LgID) ON DELETE CASCADE",
    "ALTER TABLE sentences ADD CONSTRAINT fk_sentences_language " .
        "FOREIGN KEY (SeLgID) REFERENCES languages(LgID) ON DELETE CASCADE",
    "ALTER TABLE news_feeds ADD CONSTRAINT fk_news_feeds_language " .
        "FOREIGN KEY (NfLgID) REFERENCES languages(LgID) ON DELETE CASCADE",
    // Text references
    "ALTER TABLE sentences ADD CONSTRAINT fk_sentences_text " .
        "FOREIGN KEY (SeTxID) REFERENCES texts(TxID) ON DELETE CASCADE",
    "ALTER TABLE word_occurrences ADD CONSTRAINT fk_word_occurrences_text " .
        "FOREIGN KEY (Ti2TxID) REFERENCES texts(TxID) ON DELETE CASCADE",
    "ALTER TABLE text_tag_map ADD CONSTRAINT fk_text_tag_map_text " .
        "FOREIGN KEY (TtTxID) REFERENCES texts(TxID) ON DELETE CASCADE",
    // Sentence reference
    "ALTER TABLE word_occurrences ADD CONSTRAINT fk_word_occurrences_sentence " .
        "FOREIGN KEY (Ti2SeID) REFERENCES sentences(SeID) ON DELETE CASCADE",
    // Word reference (SET NULL for unknown words)
    // (Ti2WoID is made nullable and widened to match words.WoID in the
    // column-widening step above.)
    "ALTER TABLE word_occurrences ADD CONSTRAINT fk_word_occurrences_word " .
        "FOREIGN KEY (Ti2WoID) REFERENCES words(WoID) ON DELETE SET NULL",
    // Word tags
    "ALTER TABLE word_tag_map ADD CONSTRAINT fk_word_tag_map_word " .
        "FOREIGN KEY (WtWoID) REFERENCES words(WoID) ON DELETE CASCADE",
    "ALTER TABLE word_tag_map ADD CONSTRAINT fk_word_tag_map_tag " .
        "FOREIGN KEY (WtTgID) REFERENCES tags(TgID) ON DELETE CASCADE",
    // Text tags
    "ALTER TABLE text_tag_map ADD CONSTRAINT fk_text_tag_map_text_tag " .
        "FOREIGN KEY (TtT2ID) REFERENCES text_tags(T2ID) ON DELETE CASCADE",
    // Feed links
    "ALTER TABLE feed_links ADD CONSTRAINT fk_feed_links_newsfeed " .
        "FOREIGN KEY (FlNfID) REFERENCES news_feeds(NfID) ON DELETE CASCADE",
];

// Constraints already on the database are left alone. This script also runs
// non-destructively before each integration run, when the database holds
// rows from a previous suite; re-adding an existing constraint would then
// fail against test data that predates it (an orphaned word_tag_map row is
// enough) and silently leave the constraint dropped.
$existingConstraints = [];
$constraintRows = mysqli_query(
    $conn,
    "SELECT CONSTRAINT_NAME FROM INFORMATION_SCHEMA.TABLE_CONSTRAINTS
     WHERE TABLE_SCHEMA = '$testDbName' AND CONSTRAINT_TYPE = 'FOREIGN KEY'"
);
if ($constraintRows) {
    while ($row = mysqli_fetch_assoc($constraintRows)) {
        $existingConstraints[] = (string) $row['CONSTRAINT_NAME'];
    }
    mysqli_free_result($constraintRows);
}

// Clear rows that would violate a constraint before adding it. The main
// suite drops every foreign key (Migrations::dropAllForeignKeys, reached
// through the restore and migration paths) and can leave children behind
// whose parent is gone. This script also runs non-destructively before an
// integration run, so without this the constraint cannot be re-added and
// the cascade tests that depend on it fail — or, as before, skip in
// silence.
$orphanCleanup = [
    "DELETE c FROM word_tag_map c LEFT JOIN words p ON c.WtWoID = p.WoID WHERE p.WoID IS NULL",
    "DELETE c FROM word_tag_map c LEFT JOIN tags p ON c.WtTgID = p.TgID WHERE p.TgID IS NULL",
    "DELETE c FROM text_tag_map c LEFT JOIN texts p ON c.TtTxID = p.TxID WHERE p.TxID IS NULL",
    "DELETE c FROM text_tag_map c LEFT JOIN text_tags p ON c.TtT2ID = p.T2ID WHERE p.T2ID IS NULL",
    "DELETE c FROM word_occurrences c LEFT JOIN texts p ON c.Ti2TxID = p.TxID WHERE p.TxID IS NULL",
    "DELETE c FROM word_occurrences c LEFT JOIN sentences p ON c.Ti2SeID = p.SeID WHERE p.SeID IS NULL",
    "UPDATE word_occurrences c LEFT JOIN words p ON c.Ti2WoID = p.WoID
        SET c.Ti2WoID = NULL WHERE c.Ti2WoID IS NOT NULL AND p.WoID IS NULL",
    "DELETE c FROM sentences c LEFT JOIN texts p ON c.SeTxID = p.TxID WHERE p.TxID IS NULL",
    "DELETE c FROM feed_links c LEFT JOIN news_feeds p ON c.FlNfID = p.NfID WHERE p.NfID IS NULL",
    "DELETE c FROM texts c LEFT JOIN languages p ON c.TxLgID = p.LgID WHERE p.LgID IS NULL",
    "DELETE c FROM words c LEFT JOIN languages p ON c.WoLgID = p.LgID WHERE p.LgID IS NULL",
    "DELETE c FROM sentences c LEFT JOIN languages p ON c.SeLgID = p.LgID WHERE p.LgID IS NULL",
    "DELETE c FROM news_feeds c LEFT JOIN languages p ON c.NfLgID = p.LgID WHERE p.LgID IS NULL",
];
foreach ($orphanCleanup as $sql) {
    @mysqli_query($conn, $sql);
}

$fkCount = 0;
$fkErrors = 0;
foreach ($fkConstraints as $sql) {
    if (
        preg_match('/ADD CONSTRAINT (\w+)/', $sql, $match) === 1
        && in_array($match[1], $existingConstraints, true)
    ) {
        continue;
    }

    if (@mysqli_query($conn, $sql)) {
        $fkCount++;
    } else {
        $error = mysqli_error($conn);
        // Ignore "duplicate key" errors (constraint already exists)
        if (strpos($error, 'Duplicate') === false && strpos($error, 'already exists') === false) {
            $fkErrors++;
            if (!$quiet) {
                fwrite(STDERR, "  Warning: " . $error . "\n");
            }
        }
    }
}

// Record migration as applied
$escapedFilename = mysqli_real_escape_string($conn, $fkMigration);
mysqli_query($conn, "INSERT IGNORE INTO _migrations (filename, applied_at) VALUES ('$escapedFilename', NOW())");

output("Applied $fkCount FK constraint(s)" . ($fkErrors > 0 ? " ($fkErrors warnings)" : "") . ".\n", $quiet);
$appliedCount = 1;

// Apply column defaults migration (mysqli_multi_query doesn't handle DEFAULT '' correctly in baseline.sql)
if (!in_array($columnDefaultsMigration, $appliedMigrations)) {
    output("Applying column defaults for strict SQL mode...\n", $quiet);

    // These columns need explicit defaults for STRICT_ALL_TABLES mode
    $columnDefaults = [
        "ALTER TABLE languages MODIFY COLUMN LgCharacterSubstitutions varchar(500) NOT NULL DEFAULT ''",
        "ALTER TABLE languages MODIFY COLUMN LgRegexpSplitSentences varchar(500) NOT NULL DEFAULT '.!?'",
        "ALTER TABLE languages MODIFY COLUMN LgExceptionsSplitSentences varchar(500) NOT NULL DEFAULT ''",
        "ALTER TABLE languages MODIFY COLUMN LgRegexpWordCharacters varchar(500) NOT NULL DEFAULT 'a-zA-ZÀ-ÖØ-öø-ȳ'",
        "ALTER TABLE texts MODIFY COLUMN TxAnnotatedText longtext NOT NULL DEFAULT ''",
        "ALTER TABLE feed_links MODIFY COLUMN FlAudio varchar(200) NOT NULL DEFAULT ''",
        "ALTER TABLE feed_links MODIFY COLUMN FlText longtext NOT NULL DEFAULT ''",
    ];

    foreach ($columnDefaults as $sql) {
        @mysqli_query($conn, $sql);
    }

    // Record migration as applied
    $escapedFilename = mysqli_real_escape_string($conn, $columnDefaultsMigration);
    mysqli_query($conn, "INSERT IGNORE INTO _migrations (filename, applied_at) VALUES ('$escapedFilename', NOW())");

    output("Column defaults applied.\n", $quiet);
} else {
    output("Column defaults already applied.\n", $quiet);
}

// Verify setup
$tableCount = getTableCount($conn, $testDbName);
$migrationCount = getMigrationCount($conn, $testDbName);
$hasFk = hasForeignKeys($conn, $testDbName);

output("\n", $quiet);
output("Test database setup complete!\n", $quiet);
output("  Tables: $tableCount\n", $quiet);
output("  Migrations: $migrationCount\n", $quiet);
output("  Foreign keys: " . ($hasFk ? "Yes" : "No") . "\n", $quiet);

if (!$hasFk) {
    output("\nNote: Foreign key constraints not detected.\n", $quiet);
    output("Some integration tests may be skipped.\n", $quiet);
}

mysqli_close($conn);
exit(0);
