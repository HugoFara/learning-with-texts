<?php

/**
 * Local Dictionary Service - Business logic for local dictionary management
 *
 * PHP version 8.1
 *
 * @category Lwt
 * @package  Lwt\Modules\Dictionary\Application\Services
 * @author   HugoFara <git@hugofara.net>
 * @license  Unlicense <http://unlicense.org/>
 * @link     https://hugofara.github.io/lwt/developer/api
 * @since    3.0.0
 */

declare(strict_types=1);

namespace Lwt\Modules\Dictionary\Application\Services;

use DateTimeImmutable;
use Lwt\Modules\Dictionary\Domain\LocalDictionary;
use Lwt\Shared\Infrastructure\Globals;
use Lwt\Shared\Infrastructure\Database\Connection;
use Lwt\Shared\Infrastructure\Database\Maintenance;
use Lwt\Shared\Infrastructure\Database\QueryBuilder;
use Lwt\Shared\Infrastructure\Database\UserScopedQuery;

/**
 * Service class for managing local dictionaries.
 *
 * Handles CRUD operations for local dictionaries and entries,
 * as well as term lookups.
 *
 * @category Lwt
 * @package  Lwt\Modules\Dictionary\Application\Services
 * @author   HugoFara <git@hugofara.net>
 * @license  Unlicense <http://unlicense.org/>
 * @link     https://hugofara.github.io/lwt/developer/api
 * @since    3.0.0
 */
class LocalDictionaryService
{
    /**
     * Batch size for bulk inserts.
     */
    private const BATCH_SIZE = 1000;

    /**
     * Character capacity of `LeTerm` / `LeTermLc` (both VARCHAR(250)).
     *
     * Entries with a longer headword are skipped rather than allowed to abort
     * the import: under STRICT_ALL_TABLES an over-long value fails the whole
     * multi-row INSERT, so a single bad entry used to lose every other entry in
     * its batch and end the import (issue #250 — one 293-character headword in
     * FreeDict German-English killed all 517,534 entries). 250 is also LWT's
     * own term length (`words.WoText`), so a longer headword could not become a
     * usable term anyway.
     */
    private const MAX_TERM_LENGTH = 250;

    /**
     * Character capacity of `LeReading` (VARCHAR(250)).
     */
    private const MAX_READING_LENGTH = 250;

    /**
     * Character capacity of `LePartOfSpeech` (VARCHAR(50)).
     */
    private const MAX_POS_LENGTH = 50;

    /**
     * Create a new local dictionary.
     *
     * @param int         $languageId   Language ID
     * @param string      $name         Dictionary name
     * @param string      $sourceFormat Source format (csv, json, stardict)
     * @param string|null $description  Optional description
     *
     * @return int The new dictionary ID
     */
    public function create(
        int $languageId,
        string $name,
        string $sourceFormat = 'csv',
        ?string $description = null
    ): int {
        $userId = Globals::getCurrentUserId();

        $dictionary = LocalDictionary::create($languageId, $name, $sourceFormat, $userId);
        if ($description !== null) {
            $dictionary->setDescription($description);
        }

        $data = [
            'LdLgID' => $dictionary->languageId(),
            'LdName' => $dictionary->name(),
            'LdDescription' => $dictionary->description(),
            'LdSourceFormat' => $dictionary->sourceFormat(),
            'LdEntryCount' => 0,
            'LdPriority' => $dictionary->priority(),
            'LdEnabled' => $dictionary->isEnabled() ? 1 : 0,
            'LdUsID' => $dictionary->userId(),
        ];

        QueryBuilder::table('local_dictionaries')->insertPrepared($data);
        return (int) Connection::lastInsertId();
    }

    /**
     * Get a dictionary by ID.
     *
     * @param int $dictId Dictionary ID
     *
     * @return LocalDictionary|null
     */
    public function getById(int $dictId): ?LocalDictionary
    {
        $record = QueryBuilder::table('local_dictionaries')
            ->where('LdID', '=', $dictId)
            ->firstPrepared();

        if ($record === null) {
            return null;
        }

        return $this->hydrateFromRecord($record);
    }

    /**
     * Get all dictionaries for a language.
     *
     * @param int $languageId Language ID
     *
     * @return LocalDictionary[]
     */
    public function getForLanguage(int $languageId): array
    {
        $records = QueryBuilder::table('local_dictionaries')
            ->where('LdLgID', '=', $languageId)
            ->where('LdEnabled', '=', 1)
            ->orderBy('LdPriority', 'ASC')
            ->getPrepared();

        return array_map([$this, 'hydrateFromRecord'], $records);
    }

    /**
     * Get all dictionaries for a language (including disabled).
     *
     * @param int $languageId Language ID
     *
     * @return LocalDictionary[]
     */
    public function getAllForLanguage(int $languageId): array
    {
        $records = QueryBuilder::table('local_dictionaries')
            ->where('LdLgID', '=', $languageId)
            ->orderBy('LdPriority', 'ASC')
            ->getPrepared();

        return array_map([$this, 'hydrateFromRecord'], $records);
    }

    /**
     * Update a dictionary.
     *
     * @param LocalDictionary $dictionary Dictionary entity
     *
     * @return bool Success
     */
    public function update(LocalDictionary $dictionary): bool
    {
        if ($dictionary->isNew()) {
            return false;
        }

        $data = [
            'LdName' => $dictionary->name(),
            'LdDescription' => $dictionary->description(),
            'LdPriority' => $dictionary->priority(),
            'LdEnabled' => $dictionary->isEnabled() ? 1 : 0,
            'LdEntryCount' => $dictionary->entryCount(),
        ];

        return QueryBuilder::table('local_dictionaries')
            ->where('LdID', '=', $dictionary->id())
            ->updatePrepared($data) > 0;
    }

    /**
     * Delete a dictionary and all its entries.
     *
     * @param int $dictId Dictionary ID
     *
     * @return bool Success
     */
    public function delete(int $dictId): bool
    {
        // Entries are deleted via CASCADE
        return QueryBuilder::table('local_dictionaries')
            ->where('LdID', '=', $dictId)
            ->deletePrepared() > 0;
    }

    /**
     * Look up a term in local dictionaries for a language.
     *
     * @param int    $languageId Language ID
     * @param string $term       Term to look up
     *
     * @return array<array{term: string, definition: string, reading: ?string, pos: ?string, dictionary: string}>
     */
    public function lookup(int $languageId, string $term): array
    {
        $termLc = mb_strtolower(trim($term), 'UTF-8');

        $bindings = [$languageId, $termLc];
        $sql = "SELECT le.LeTerm, le.LeDefinition, le.LeReading, le.LePartOfSpeech, ld.LdName
                FROM " . Globals::table('local_dictionary_entries') . " le
                INNER JOIN " . Globals::table('local_dictionaries') . " ld ON le.LeLdID = ld.LdID
                WHERE ld.LdLgID = ? AND ld.LdEnabled = 1 AND le.LeTermLc = ?"
                . UserScopedQuery::forTablePrepared('local_dictionaries', $bindings, 'ld')
                . " ORDER BY ld.LdPriority ASC";

        $records = Connection::preparedFetchAll($sql, $bindings);

        return array_map(function ($row): array {
            return [
                'term' => (string) ($row['LeTerm'] ?? ''),
                'definition' => (string) ($row['LeDefinition'] ?? ''),
                'reading' => isset($row['LeReading']) ? (string) $row['LeReading'] : null,
                'pos' => isset($row['LePartOfSpeech']) ? (string) $row['LePartOfSpeech'] : null,
                'dictionary' => (string) ($row['LdName'] ?? ''),
            ];
        }, $records);
    }

    /**
     * Look up a term with prefix matching (for autocomplete).
     *
     * @param int    $languageId Language ID
     * @param string $prefix     Term prefix
     * @param int    $limit      Maximum results
     *
     * @return array<array{term: string, definition: string}>
     */
    public function lookupPrefix(int $languageId, string $prefix, int $limit = 10): array
    {
        $prefixLc = mb_strtolower(trim($prefix), 'UTF-8');

        $bindings = [$languageId, $prefixLc . '%'];
        $userScope = UserScopedQuery::forTablePrepared('local_dictionaries', $bindings, 'ld');
        $bindings[] = $limit;

        $sql = "SELECT DISTINCT le.LeTerm, le.LeDefinition
                FROM " . Globals::table('local_dictionary_entries') . " le
                INNER JOIN " . Globals::table('local_dictionaries') . " ld ON le.LeLdID = ld.LdID
                WHERE ld.LdLgID = ? AND ld.LdEnabled = 1 AND le.LeTermLc LIKE ?"
                . $userScope
                . " ORDER BY le.LeTermLc ASC
                LIMIT ?";

        $records = Connection::preparedFetchAll($sql, $bindings);

        return array_map(function ($row): array {
            return [
                'term' => (string) ($row['LeTerm'] ?? ''),
                'definition' => (string) ($row['LeDefinition'] ?? ''),
            ];
        }, $records);
    }

    /**
     * Add a single entry to a dictionary.
     *
     * @param int         $dictId     Dictionary ID
     * @param string      $term       Term/headword
     * @param string      $definition Definition
     * @param string|null $reading    Pronunciation/reading
     * @param string|null $pos        Part of speech
     *
     * @return int Entry ID
     */
    public function addEntry(
        int $dictId,
        string $term,
        string $definition,
        ?string $reading = null,
        ?string $pos = null
    ): int {
        $this->assertOwnsDictionary($dictId);

        $data = [
            'LeLdID' => $dictId,
            'LeTerm' => $term,
            'LeTermLc' => mb_strtolower($term, 'UTF-8'),
            'LeDefinition' => $definition,
            'LeReading' => $reading,
            'LePartOfSpeech' => $pos,
        ];

        QueryBuilder::table('local_dictionary_entries')->insertPrepared($data);
        return (int) Connection::lastInsertId();
    }

    /**
     * Add multiple entries to a dictionary in batches.
     *
     * Entries whose headword exceeds the column's capacity are skipped and
     * counted rather than inserted. Under STRICT_ALL_TABLES an over-long value
     * aborts the entire multi-row INSERT, so without this a single oversized
     * headword discarded its whole batch and ended the import (issue #250).
     * `reading` and `pos` are descriptive metadata, so those are truncated
     * instead — losing a part-of-speech label beats losing the entry.
     *
     * @param int $dictId Dictionary ID
     * @param iterable<array{term: string, definition: string, reading?: ?string, pos?: ?string}> $entries
     *        Entries to add
     *
     * @return array{added: int, skipped: int} Entries inserted, and entries
     *         skipped for an unstorable headword
     *
     * @since 3.2.2-fork Returns a count pair and skips unstorable headwords
     */
    public function addEntriesBatch(int $dictId, iterable $entries): array
    {
        $this->assertOwnsDictionary($dictId);

        $batch = [];
        $added = 0;
        $skipped = 0;

        foreach ($entries as $entry) {
            $row = self::buildRow($dictId, $entry);
            if ($row === null) {
                $skipped++;
                continue;
            }

            $batch[] = $row;

            if (count($batch) >= self::BATCH_SIZE) {
                $this->insertBatch($batch);
                $added += count($batch);
                $batch = [];
            }
        }

        if (!empty($batch)) {
            $this->insertBatch($batch);
            $added += count($batch);
        }

        // Update entry count
        $this->updateEntryCount($dictId);

        return ['added' => $added, 'skipped' => $skipped];
    }

    /**
     * Delete all entries from a dictionary.
     *
     * @param int $dictId Dictionary ID
     *
     * @return int Number of entries deleted
     */
    public function clearEntries(int $dictId): int
    {
        $this->assertOwnsDictionary($dictId);

        $deleted = QueryBuilder::table('local_dictionary_entries')
            ->where('LeLdID', '=', $dictId)
            ->deletePrepared();

        $this->updateEntryCount($dictId);

        return $deleted;
    }

    /**
     * Get entry count for a dictionary.
     *
     * @param int $dictId Dictionary ID
     *
     * @return int
     */
    public function getEntryCount(int $dictId): int
    {
        $this->assertOwnsDictionary($dictId);

        $result = QueryBuilder::table('local_dictionary_entries')
            ->select(['COUNT(*) as cnt'])
            ->where('LeLdID', '=', $dictId)
            ->firstPrepared();

        return (int) ($result['cnt'] ?? 0);
    }

    /**
     * Get entries for a dictionary (paginated).
     *
     * @param int $dictId  Dictionary ID
     * @param int $page    Page number (1-based)
     * @param int $perPage Entries per page
     *
     * @return array{entries: array, total: int, page: int, perPage: int}
     */
    public function getEntries(int $dictId, int $page = 1, int $perPage = 50): array
    {
        $this->assertOwnsDictionary($dictId);

        $offset = max(0, ($page - 1) * $perPage);

        $total = $this->getEntryCount($dictId);

        $records = QueryBuilder::table('local_dictionary_entries')
            ->where('LeLdID', '=', $dictId)
            ->orderBy('LeTermLc', 'ASC')
            ->limit($perPage)
            ->offset($offset)
            ->getPrepared();

        $entries = array_map(function ($row) {
            return [
                'id' => (int) $row['LeID'],
                'term' => $row['LeTerm'],
                'definition' => $row['LeDefinition'],
                'reading' => $row['LeReading'],
                'pos' => $row['LePartOfSpeech'],
            ];
        }, $records);

        return [
            'entries' => $entries,
            'total' => $total,
            'page' => $page,
            'perPage' => $perPage,
        ];
    }

    /**
     * Update a single entry.
     *
     * @param int         $entryId    Entry ID
     * @param string      $term       Term
     * @param string      $definition Definition
     * @param string|null $reading    Reading
     * @param string|null $pos        Part of speech
     *
     * @return bool Success
     */
    public function updateEntry(
        int $entryId,
        string $term,
        string $definition,
        ?string $reading = null,
        ?string $pos = null
    ): bool {
        $entry = QueryBuilder::table('local_dictionary_entries')
            ->select(['LeLdID'])
            ->where('LeID', '=', $entryId)
            ->firstPrepared();
        if ($entry === null) {
            return false;
        }
        $this->assertOwnsDictionary((int) $entry['LeLdID']);

        $data = [
            'LeTerm' => $term,
            'LeTermLc' => mb_strtolower($term, 'UTF-8'),
            'LeDefinition' => $definition,
            'LeReading' => $reading,
            'LePartOfSpeech' => $pos,
        ];

        return QueryBuilder::table('local_dictionary_entries')
            ->where('LeID', '=', $entryId)
            ->updatePrepared($data) > 0;
    }

    /**
     * Delete a single entry.
     *
     * @param int $entryId Entry ID
     *
     * @return bool Success
     */
    public function deleteEntry(int $entryId): bool
    {
        // Get dictionary ID first to update count
        $entry = QueryBuilder::table('local_dictionary_entries')
            ->select(['LeLdID'])
            ->where('LeID', '=', $entryId)
            ->firstPrepared();

        if ($entry === null) {
            return false;
        }
        $this->assertOwnsDictionary((int) $entry['LeLdID']);

        $deleted = QueryBuilder::table('local_dictionary_entries')
            ->where('LeID', '=', $entryId)
            ->deletePrepared() > 0;

        if ($deleted) {
            $this->updateEntryCount((int) $entry['LeLdID']);
        }

        return $deleted;
    }

    /**
     * Check if a language has any local dictionaries.
     *
     * @param int $languageId Language ID
     *
     * @return bool
     */
    public function hasLocalDictionaries(int $languageId): bool
    {
        $result = QueryBuilder::table('local_dictionaries')
            ->select(['COUNT(*) as cnt'])
            ->where('LdLgID', '=', $languageId)
            ->where('LdEnabled', '=', 1)
            ->firstPrepared();

        return ((int) ($result['cnt'] ?? 0)) > 0;
    }

    /**
     * Create vocabulary terms (status 1) from dictionary entries.
     *
     * Uses INSERT IGNORE to skip terms that already exist in the
     * vocabulary for this language. Sets WoStatus = 1 (new/unknown)
     * and WoTranslation from the dictionary definition.
     *
     * The new rows are then linked to the occurrences they match. Creating
     * them alone is not enough: the reader decides a word is unknown from
     * `word_occurrences.Ti2WoID`, not from whether the term exists, so terms
     * created here stayed invisible to it and every attempt to add one from
     * the reading view collided with the unique index on (WoTextLC, WoLgID).
     * Because an import covers essentially the whole language, that affected
     * more or less every word in a text rather than isolated cases — see
     * issue #283.
     *
     * @param int $dictId     Dictionary ID
     * @param int $languageId Language ID
     *
     * @return int Number of vocabulary terms created
     */
    public function createVocabularyFromEntries(int $dictId, int $languageId): int
    {
        $this->assertOwnsDictionary($dictId);

        $entriesTable = Globals::table('local_dictionary_entries');
        $wordsTable = Globals::table('words');

        $bindings = [$languageId, $dictId];
        $userColumn = UserScopedQuery::insertColumn('words');
        $userValue = UserScopedQuery::insertValuePrepared('words', $bindings);

        $sql = "INSERT IGNORE INTO {$wordsTable} (
                    WoLgID, WoTextLC, WoText, WoStatus, WoTranslation,
                    WoSentence, WoRomanization, WoStatusChanged{$userColumn}
                )
                SELECT ?, le.LeTermLc, le.LeTerm, 1, le.LeDefinition,
                       '', '', NOW(){$userValue}
                FROM {$entriesTable} le
                WHERE le.LeLdID = ?";

        $created = Connection::preparedExecute($sql, $bindings);

        // WoWordCount defaults to 0, which is this schema's "not counted yet"
        // rather than a count. Nothing matches a term at 0: the reader's
        // parse-time linking asks for WoWordCount = 1 and expression matching
        // asks for > 1, so a term left at 0 is invisible to both and every
        // text parsed after the import comes back unlinked -- issue #283
        // repeating itself for each new text. The count cannot be worked out
        // here because it depends on the language's parsing rules, so hand it
        // to the routine that owns them; it only visits rows still at 0.
        Maintenance::initWordCount();

        $this->linkOccurrencesForLanguage($languageId);

        return $created;
    }

    /**
     * Point this language's unlinked occurrences at the terms matching them.
     *
     * Single-word occurrences only: a multi-word occurrence is an expression,
     * matched by its own mechanism rather than through Ti2WoID.
     *
     * Nothing here filters by user, and it does not need to — the join pins
     * occurrence and term to the same LgID, and a `languages` row has exactly
     * one owner, so the statement cannot reach across users. This is the same
     * confinement {@see \Lwt\Modules\Vocabulary\Application\Services\WordLinkingService}
     * relies on, and the caller has already passed assertOwnsDictionary().
     *
     * @param int $languageId Language ID
     *
     * @return void
     */
    private function linkOccurrencesForLanguage(int $languageId): void
    {
        $occurrencesTable = Globals::table('word_occurrences');
        $wordsTable = Globals::table('words');

        Connection::preparedExecute(
            "UPDATE {$occurrencesTable} o
             JOIN {$wordsTable} w
               ON LOWER(o.Ti2Text) = w.WoTextLC AND o.Ti2LgID = w.WoLgID
             SET o.Ti2WoID = w.WoID
             WHERE o.Ti2WoID IS NULL AND o.Ti2WordCount = 1 AND o.Ti2LgID = ?",
            [$languageId]
        );
    }

    /**
     * Auto-enable local dictionary mode if currently set to online-only.
     *
     * When a dictionary is imported, if the language's local dict mode
     * is 0 (online only), upgrade it to 1 (local first, online fallback).
     *
     * @param int $languageId Language ID
     *
     * @return void
     */
    public function autoEnableLocalDictMode(int $languageId): void
    {
        $currentMode = $this->getLocalDictMode($languageId);
        if ($currentMode === 0) {
            QueryBuilder::table('languages')
                ->where('LgID', '=', $languageId)
                ->updatePrepared(['LgLocalDictMode' => 1]);
        }
    }

    /**
     * Get the local dictionary mode for a language.
     *
     * @param int $languageId Language ID
     *
     * @return int Mode (0=online only, 1=local first, 2=local only, 3=combined)
     */
    public function getLocalDictMode(int $languageId): int
    {
        $result = QueryBuilder::table('languages')
            ->select(['LgLocalDictMode'])
            ->where('LgID', '=', $languageId)
            ->firstPrepared();

        return (int) ($result['LgLocalDictMode'] ?? 0);
    }

    /**
     * Reject operations that target a dictionary the current user does not own.
     *
     * `local_dictionary_entries` is not auto-scoped (it has no LdUsID column),
     * so every entry-level call has to bounce through `getById` — which IS
     * user-scoped via QueryBuilder — to confirm ownership before touching the
     * row. Single-user mode is a no-op.
     *
     * @throws \RuntimeException When the dictionary is missing or belongs to another user.
     */
    private function assertOwnsDictionary(int $dictId): void
    {
        if (!Globals::isMultiUserEnabled()) {
            return;
        }
        if ($this->getById($dictId) === null) {
            throw new \RuntimeException("Dictionary $dictId not found or access denied");
        }
    }

    /**
     * Hydrate a LocalDictionary entity from a database record.
     *
     * @param array<string, mixed> $record Database record
     *
     * @return LocalDictionary
     */
    private function hydrateFromRecord(array $record): LocalDictionary
    {
        return LocalDictionary::reconstitute(
            (int) $record['LdID'],
            (int) $record['LdLgID'],
            (string) $record['LdName'],
            $record['LdDescription'] !== null ? (string) $record['LdDescription'] : null,
            (string) $record['LdSourceFormat'],
            (int) $record['LdEntryCount'],
            (int) $record['LdPriority'],
            (bool) $record['LdEnabled'],
            new DateTimeImmutable((string) $record['LdCreated']),
            $record['LdUsID'] !== null ? (int) $record['LdUsID'] : null
        );
    }

    /**
     * Build an insertable row from a parsed dictionary entry.
     *
     * @param int                                                                       $dictId Dictionary ID
     * @param array{term: string, definition: string, reading?: ?string, pos?: ?string} $entry  Parsed entry
     *
     * @return array<string, string|int|null>|null The row, or null when the
     *         headword cannot be stored and the entry has to be skipped
     */
    private static function buildRow(int $dictId, array $entry): ?array
    {
        $term = $entry['term'];
        $termLc = mb_strtolower($term, 'UTF-8');

        // Lowercasing can lengthen a string (e.g. 'İ' becomes two code points),
        // so both columns have to be checked, not just the incoming term.
        if (
            mb_strlen($term, 'UTF-8') > self::MAX_TERM_LENGTH
            || mb_strlen($termLc, 'UTF-8') > self::MAX_TERM_LENGTH
        ) {
            return null;
        }

        return [
            'LeLdID' => $dictId,
            'LeTerm' => $term,
            'LeTermLc' => $termLc,
            'LeDefinition' => $entry['definition'],
            'LeReading' => self::clip($entry['reading'] ?? null, self::MAX_READING_LENGTH),
            'LePartOfSpeech' => self::clip($entry['pos'] ?? null, self::MAX_POS_LENGTH),
        ];
    }

    /**
     * Truncate an optional metadata value to a column's character capacity.
     *
     * @param string|null $value     Value to clip, or null
     * @param int         $maxLength Maximum characters the column accepts
     *
     * @return string|null The value, shortened if it was over-long
     */
    private static function clip(?string $value, int $maxLength): ?string
    {
        if ($value === null || mb_strlen($value, 'UTF-8') <= $maxLength) {
            return $value;
        }

        return mb_substr($value, 0, $maxLength, 'UTF-8');
    }

    /**
     * Insert a batch of entries.
     *
     * @param array<array<string, mixed>> $batch Batch of entry data
     *
     * @return void
     */
    private function insertBatch(array $batch): void
    {
        if (empty($batch)) {
            return;
        }

        $columns = ['LeLdID', 'LeTerm', 'LeTermLc', 'LeDefinition', 'LeReading', 'LePartOfSpeech'];
        $placeholders = [];
        $values = [];

        foreach ($batch as $row) {
            $placeholders[] = '(?, ?, ?, ?, ?, ?)';
            /** @var int $dictId */
            $dictId = $row['LeLdID'];
            $values[] = $dictId;
            $values[] = (string) ($row['LeTerm'] ?? '');
            $values[] = (string) ($row['LeTermLc'] ?? '');
            $values[] = (string) ($row['LeDefinition'] ?? '');
            /** @var string|null $reading */
            $reading = $row['LeReading'] ?? null;
            $values[] = $reading;
            /** @var string|null $pos */
            $pos = $row['LePartOfSpeech'] ?? null;
            $values[] = $pos;
        }

        $sql = "INSERT INTO " . Globals::table('local_dictionary_entries') .
               " (" . implode(', ', $columns) . ") VALUES " .
               implode(', ', $placeholders);

        Connection::preparedExecute($sql, $values);
    }

    /**
     * Update the entry count for a dictionary.
     *
     * @param int $dictId Dictionary ID
     *
     * @return void
     */
    private function updateEntryCount(int $dictId): void
    {
        $count = $this->getEntryCount($dictId);

        QueryBuilder::table('local_dictionaries')
            ->where('LdID', '=', $dictId)
            ->updatePrepared(['LdEntryCount' => $count]);
    }
}
