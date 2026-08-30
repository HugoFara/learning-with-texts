<?php

/**
 * Whisper Job Repository
 *
 * PHP version 8.2
 *
 * @category Lwt
 * @package  Lwt\Modules\Text\Infrastructure
 * @author   HugoFara <git@hugofara.net>
 * @license  Unlicense <http://unlicense.org/>
 * @link     https://hugofara.github.io/lwt/developer/api
 * @since    3.0.0
 */

declare(strict_types=1);

namespace Lwt\Modules\Text\Infrastructure;

use Lwt\Shared\Infrastructure\Database\Connection;
use Lwt\Shared\Infrastructure\Globals;

/**
 * Binds NLP-issued Whisper job IDs to the LWT user that started them.
 *
 * Without this binding, /api/v1/whisper/status/{id}, /result/{id},
 * and DELETE /job/{id} would accept arbitrary job IDs, letting any
 * authenticated user read or cancel another user's transcription if
 * the UUID leaked or was guessed.
 *
 * @since 3.0.0
 */
class WhisperJobRepository
{
    /**
     * Record that the current user owns the given job ID.
     *
     * In single-user mode the WjUsID column is NULL — there is exactly
     * one user, so binding is meaningless, but we still write the row
     * so isOwnedByCurrentUser can short-circuit on existence.
     *
     * @param string $jobId Job ID returned by the NLP service
     */
    public function recordForCurrentUser(string $jobId): void
    {
        $userId = Globals::isMultiUserEnabled() ? Globals::getCurrentUserId() : null;
        Connection::preparedExecute(
            'INSERT IGNORE INTO whisper_jobs (WjJobID, WjUsID) VALUES (?, ?)',
            [$jobId, $userId]
        );
    }

    /**
     * Verify the current user owns the job. Returns false for unknown
     * job IDs too, so callers can render a generic 404 without leaking
     * existence to the attacker.
     */
    public function isOwnedByCurrentUser(string $jobId): bool
    {
        if (!Globals::isMultiUserEnabled()) {
            // Single-user mode: the row exists iff this install started
            // the job. No cross-user concern.
            return $this->jobExists($jobId);
        }
        $userId = Globals::getCurrentUserId();
        if ($userId === null) {
            return false;
        }
        /** @var int|string|null $count */
        $count = Connection::preparedFetchValue(
            'SELECT COUNT(*) AS cnt FROM whisper_jobs WHERE WjJobID = ? AND WjUsID = ?',
            [$jobId, $userId],
            'cnt'
        );
        return $count !== null && (int) $count > 0;
    }

    /**
     * Delete the binding (called after cancelJob succeeds so the row
     * does not pile up forever).
     */
    public function forget(string $jobId): void
    {
        Connection::preparedExecute(
            'DELETE FROM whisper_jobs WHERE WjJobID = ?',
            [$jobId]
        );
    }

    private function jobExists(string $jobId): bool
    {
        /** @var int|string|null $count */
        $count = Connection::preparedFetchValue(
            'SELECT COUNT(*) AS cnt FROM whisper_jobs WHERE WjJobID = ?',
            [$jobId],
            'cnt'
        );
        return $count !== null && (int) $count > 0;
    }
}
