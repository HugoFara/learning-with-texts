<?php

/**
 * SQL Validator for Backup Restore Security
 *
 * This file provides SQL validation for backup restore operations
 * to prevent SQL injection and malicious queries.
 *
 * PHP version 8.2
 *
 * @category Lwt
 * @package  Lwt\Shared\Infrastructure\Database
 * @author   HugoFara <git@hugofara.net>
 * @license  Unlicense <http://unlicense.org/>
 * @link     https://hugofara.github.io/lwt/developer/api
 * @since    3.0.0
 */

declare(strict_types=1);

namespace Lwt\Shared\Infrastructure\Database;

/**
 * Validates SQL statements for backup restore operations.
 *
 * Provides security hardening by:
 * - Whitelisting allowed SQL statement types
 * - Validating table names against known LWT tables
 * - Blocking dangerous SQL patterns
 *
 * @since 3.0.0
 */
class SqlValidator
{
    /**
     * Tables allowed in backup/restore operations.
     *
     * @var string[]
     */
    private const ALLOWED_TABLES = [
        // Current table names
        'feed_links',
        'languages',
        'local_dictionaries',
        'local_dictionary_entries',
        'news_feeds',
        'sentences',
        'settings',
        'tags',
        'temp_word_occurrences',
        'temp_words',
        'text_tags',
        'word_occurrences',
        'texts',
        'text_tag_map',
        'words',
        'word_tag_map',
        // Legacy table names (for demo database and old backups)
        'archivedtexts',
        'archtexttags',
        'books',
        'feedlinks',
        'newsfeeds',
        'tags2',
        'temptextitems',
        'tempwords',
        'textitems',
        'textitems2',
        'texttags',
        'wordtags',
    ];

    /**
     * Dangerous SQL patterns that should be blocked.
     *
     * @var string[]
     */
    private const DANGEROUS_PATTERNS = [
        // File operations
        '/\bLOAD_FILE\s*\(/i',
        '/\bINTO\s+(OUTFILE|DUMPFILE)\b/i',
        '/\bLOAD\s+DATA\b/i',
        // System functions
        '/\bSYSTEM\s*\(/i',
        '/\bEXEC\s*\(/i',
        '/\bSHELL\s*\(/i',
        // User/privilege manipulation
        '/\bCREATE\s+USER\b/i',
        '/\bDROP\s+USER\b/i',
        '/\bGRANT\b/i',
        '/\bREVOKE\b/i',
        '/\bALTER\s+USER\b/i',
        // Database manipulation
        '/\bCREATE\s+DATABASE\b/i',
        '/\bDROP\s+DATABASE\b/i',
        '/\bALTER\s+DATABASE\b/i',
        // Process control
        '/\bKILL\b/i',
        '/\bSHUTDOWN\b/i',
        // Stored procedures/functions
        '/\bCREATE\s+(PROCEDURE|FUNCTION|TRIGGER|EVENT)\b/i',
        '/\bDROP\s+(PROCEDURE|FUNCTION|TRIGGER|EVENT)\b/i',
        '/\bALTER\s+(PROCEDURE|FUNCTION|TRIGGER|EVENT)\b/i',
        '/\bCALL\s+/i',
        // Variables and configuration
        '/\bSET\s+(GLOBAL|SESSION|@@)/i',
        // Subqueries that could exfiltrate data
        '/\bSELECT\b.*\bFROM\b(?!.*\bINSERT\s+INTO\b)/is',
        // Comments that could hide malicious code
        '/\/\*[^*]*\*+([^\/*][^*]*\*+)*\//i',
        // Note: Multi-statement detection (semicolon check) removed because it causes
        // false positives on semicolons in string literals. Statement type validation
        // already prevents injection of dangerous statements.
        // Hex strings that could encode malicious queries
        '/0x[0-9a-fA-F]{20,}/i',
        // Sleep/benchmark (DoS attacks)
        '/\bSLEEP\s*\(/i',
        '/\bBENCHMARK\s*\(/i',
        // Information schema access
        '/\bINFORMATION_SCHEMA\b/i',
        '/\bMYSQL\./i',
        '/\bPERFORMANCE_SCHEMA\b/i',
    ];

    /**
     * Validation errors collected during validation.
     *
     * @var string[]
     */
    private array $errors = [];

    /**
     * Validate a single SQL statement.
     *
     * @param string $sql The SQL statement to validate
     *
     * @return bool True if valid, false otherwise
     */
    public function validate(string $sql): bool
    {
        $this->errors = [];
        $trimmedSql = trim($sql);

        if ($trimmedSql === '') {
            return true;
        }

        // Skip pure comments (starting with "-- " or just "--" possibly followed by newline)
        if (str_starts_with($trimmedSql, '-- ') || $trimmedSql === '--') {
            return true;
        }

        // Handle comment lines concatenated with statements (e.g., "--\nSET ...")
        // Strip leading comment lines from the statement
        while (preg_match('/^--[^\r\n]*\r?\n/', $trimmedSql)) {
            $result = preg_replace('/^--[^\r\n]*\r?\n/', '', $trimmedSql);
            $trimmedSql = trim($result ?? '');
            if ($trimmedSql === '') {
                return true;
            }
        }

        // Normalize whitespace for statement type detection (handles multi-line SQL)
        $normalizedSql = preg_replace('/\s+/', ' ', $trimmedSql) ?? $trimmedSql;

        // Check for dangerous patterns first
        foreach (self::DANGEROUS_PATTERNS as $pattern) {
            if (preg_match($pattern, $normalizedSql)) {
                $this->errors[] = "Dangerous SQL pattern detected: " . substr($trimmedSql, 0, 100);
                return false;
            }
        }

        // Validate statement type and table name
        if (str_starts_with(strtoupper($normalizedSql), 'DROP TABLE')) {
            return $this->validateDropTable($normalizedSql);
        }

        if (str_starts_with(strtoupper($normalizedSql), 'CREATE TABLE')) {
            return $this->validateCreateTable($normalizedSql);
        }

        if (str_starts_with(strtoupper($normalizedSql), 'INSERT INTO')) {
            return $this->validateInsert($normalizedSql);
        }

        // Allow SET FOREIGN_KEY_CHECKS for backup/restore operations
        if (preg_match('/^SET\s+FOREIGN_KEY_CHECKS\s*=\s*[01]\s*$/i', $normalizedSql)) {
            return true;
        }

        // Block all other statement types
        $this->errors[] = "Statement type not allowed: " . substr($trimmedSql, 0, 50);
        return false;
    }

    /**
     * Validate multiple SQL statements.
     *
     * @param string[] $statements Array of SQL statements
     *
     * @return bool True if all valid, false otherwise
     */
    public function validateAll(array $statements): bool
    {
        $allValid = true;

        foreach ($statements as $sql) {
            if (!$this->validate($sql)) {
                $allValid = false;
            }
        }

        return $allValid;
    }

    /**
     * Get validation errors.
     *
     * @return string[] Array of error messages
     */
    public function getErrors(): array
    {
        return $this->errors;
    }

    /**
     * Get the first error message.
     *
     * @return string|null First error or null if no errors
     */
    public function getFirstError(): ?string
    {
        return $this->errors[0] ?? null;
    }

    /**
     * Validate DROP TABLE statement.
     *
     * @param string $sql The SQL statement
     *
     * @return bool True if valid
     */
    private function validateDropTable(string $sql): bool
    {
        // Pattern: DROP TABLE [IF EXISTS] `tablename`
        $pattern = '/^DROP\s+TABLE\s+(?:IF\s+EXISTS\s+)?[`"]?(\w+)[`"]?\s*$/i';

        if (!preg_match($pattern, trim($sql), $matches)) {
            $this->errors[] = "Invalid DROP TABLE syntax: " . substr($sql, 0, 100);
            return false;
        }

        $tableName = strtolower($matches[1]);
        if (!$this->isAllowedTable($tableName)) {
            $this->errors[] = "Table not allowed: $tableName";
            return false;
        }

        return true;
    }

    /**
     * Validate CREATE TABLE statement.
     *
     * @param string $sql The SQL statement
     *
     * @return bool True if valid
     */
    private function validateCreateTable(string $sql): bool
    {
        // Extract table name from CREATE TABLE
        $pattern = '/^CREATE\s+TABLE\s+(?:IF\s+NOT\s+EXISTS\s+)?[`"]?(\w+)[`"]?\s*\(/i';

        if (!preg_match($pattern, $sql, $matches)) {
            $this->errors[] = "Invalid CREATE TABLE syntax: " . substr($sql, 0, 100);
            return false;
        }

        $tableName = strtolower($matches[1]);
        if (!$this->isAllowedTable($tableName)) {
            $this->errors[] = "Table not allowed: $tableName";
            return false;
        }

        // Validate no dangerous elements in CREATE TABLE
        // Block stored procedures, triggers, or functions in table definition
        if (
            preg_match('/\bTRIGGER\b/i', $sql) ||
            preg_match('/\bPROCEDURE\b/i', $sql) ||
            preg_match('/\bFUNCTION\b/i', $sql)
        ) {
            $this->errors[] = "Dangerous elements in CREATE TABLE: " . substr($sql, 0, 100);
            return false;
        }

        return true;
    }

    /**
     * Validate INSERT statement.
     *
     * @param string $sql The SQL statement
     *
     * @return bool True if valid
     */
    private function validateInsert(string $sql): bool
    {
        // Pattern: INSERT INTO `tablename` VALUES(...) or INSERT INTO tablename VALUES(...)
        $pattern = '/^INSERT\s+INTO\s+[`"]?(\w+)[`"]?\s+(?:VALUES|\()/i';

        if (!preg_match($pattern, $sql, $matches)) {
            $this->errors[] = "Invalid INSERT syntax: " . substr($sql, 0, 100);
            return false;
        }

        $tableName = strtolower($matches[1]);
        if (!$this->isAllowedTable($tableName)) {
            $this->errors[] = "Table not allowed for INSERT: $tableName";
            return false;
        }

        return true;
    }

    /**
     * Check if a table name is in the allowed list.
     *
     * @param string $tableName The table name to check
     *
     * @return bool True if allowed
     */
    private function isAllowedTable(string $tableName): bool
    {
        return in_array(strtolower($tableName), self::ALLOWED_TABLES, true);
    }

    /**
     * Get the list of allowed tables.
     *
     * @return string[] Array of allowed table names
     */
    public static function getAllowedTables(): array
    {
        return self::ALLOWED_TABLES;
    }
}
