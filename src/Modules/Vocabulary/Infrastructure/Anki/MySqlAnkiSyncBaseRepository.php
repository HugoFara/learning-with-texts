<?php

/**
 * Storage for the note sync's base rows.
 *
 * PHP version 8.2
 *
 * @category Lwt
 * @package  Lwt\Modules\Vocabulary\Infrastructure\Anki
 * @license  Unlicense <http://unlicense.org/>
 * @link     https://github.com/HugoFara/lwt
 */

declare(strict_types=1);

namespace Lwt\Modules\Vocabulary\Infrastructure\Anki;

use DateTimeImmutable;
use Lwt\Modules\Vocabulary\Application\Services\Anki\Sync\SyncBase;
use Lwt\Shared\Infrastructure\Database\Connection;
use Lwt\Shared\Infrastructure\Globals;

/**
 * anki_sync_base, one row per synced term, scoped to the current user.
 *
 * Rows deliberately outlive their term (no foreign key): a row whose term is
 * gone is how a deletion in LWT reaches Anki.
 */
class MySqlAnkiSyncBaseRepository
{
    private const DATETIME_FORMAT = 'Y-m-d H:i:s';

    /**
     * Every base row of the current user.
     *
     * @return list<SyncBase>
     */
    public function all(): array
    {
        if (!Connection::tableExists('anki_sync_base')) {
            return [];
        }

        $params = [];
        $rows = Connection::preparedFetchAll(
            'SELECT * FROM anki_sync_base WHERE 1 = 1' . $this->scope($params),
            $params
        );

        $out = [];
        foreach ($rows as $row) {
            /** @var string|null $tags */
            $tags = $row['AsTags'];
            $out[] = new SyncBase(
                termId: (int) $row['AsWoID'],
                noteId: (int) $row['AsNoteID'],
                cardId: (int) $row['AsCardID'],
                translation: self::nullableString($row['AsTranslation']),
                romanization: self::nullableString($row['AsRomanization']),
                notes: self::nullableString($row['AsNotes']),
                tags: $tags === null ? null : self::splitTags($tags),
                suspended: $row['AsSuspended'] === null ? null : (bool) $row['AsSuspended'],
                restoreStatus: $row['AsRestoreStatus'] === null ? null : (int) $row['AsRestoreStatus'],
                syncedAt: new DateTimeImmutable((string) $row['AsSyncedAt']),
            );
        }

        return $out;
    }

    /**
     * Insert or replace rows.
     *
     * @param list<SyncBase> $bases
     */
    public function save(array $bases): void
    {
        $userId = Globals::isMultiUserEnabled() ? Globals::getCurrentUserId() : null;
        foreach ($bases as $base) {
            // A note id moves to its new term when a deleted term is re-created.
            $params = [$base->noteId, $base->termId];
            Connection::preparedExecute(
                'DELETE FROM anki_sync_base WHERE AsNoteID = ? AND AsWoID <> ?' . $this->scope($params),
                $params
            );
            Connection::preparedExecute(
                'REPLACE INTO anki_sync_base
                    (AsWoID, AsUsID, AsNoteID, AsCardID, AsTranslation, AsRomanization, AsNotes,
                     AsTags, AsSuspended, AsRestoreStatus, AsSyncedAt)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
                [
                    $base->termId,
                    $userId,
                    $base->noteId,
                    $base->cardId,
                    $base->translation,
                    $base->romanization,
                    $base->notes,
                    $base->tags === null ? null : implode(' ', $base->tags),
                    $base->suspended === null ? null : (int) $base->suspended,
                    $base->restoreStatus,
                    $base->syncedAt->format(self::DATETIME_FORMAT),
                ]
            );
        }
    }

    /**
     * Drop the rows of these terms.
     *
     * @param list<int> $termIds
     */
    public function delete(array $termIds): void
    {
        if ($termIds === []) {
            return;
        }
        $params = [];
        $in = Connection::buildPreparedInClause($termIds, $params);
        Connection::preparedExecute(
            'DELETE FROM anki_sync_base WHERE AsWoID IN ' . $in . $this->scope($params),
            $params
        );
    }

    /**
     * Drop every row of the current user, e.g. after a restore replaced the
     * vocabulary the rows describe.
     */
    public function clear(): void
    {
        if (!Connection::tableExists('anki_sync_base')) {
            return;
        }
        $params = [];
        Connection::preparedExecute('DELETE FROM anki_sync_base WHERE 1 = 1' . $this->scope($params), $params);
    }

    /**
     * @param array<int, mixed> $params
     */
    private function scope(array &$params): string
    {
        if (!Globals::isMultiUserEnabled()) {
            return '';
        }
        $userId = Globals::getCurrentUserId();
        if ($userId === null) {
            return '';
        }
        $params[] = $userId;

        return ' AND AsUsID = ?';
    }

    private static function nullableString(mixed $value): ?string
    {
        return $value === null ? null : (string) $value;
    }

    /**
     * @return list<string>
     */
    private static function splitTags(string $tags): array
    {
        return array_values(array_filter(explode(' ', $tags), static fn(string $t): bool => $t !== ''));
    }
}
