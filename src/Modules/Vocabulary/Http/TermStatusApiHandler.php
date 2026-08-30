<?php

/**
 * Term Status API Handler
 *
 * Handles API operations for term status management.
 *
 * PHP version 8.2
 *
 * @category Lwt
 * @package  Lwt\Modules\Vocabulary\Http
 * @author   HugoFara <git@hugofara.net>
 * @license  Unlicense <http://unlicense.org/>
 * @link     https://hugofara.github.io/lwt/developer/api
 * @since    3.0.0
 */

declare(strict_types=1);

namespace Lwt\Modules\Vocabulary\Http;

use Lwt\Shared\Infrastructure\Database\Connection;
use Lwt\Shared\Infrastructure\Database\QueryBuilder;
use Lwt\Shared\Infrastructure\Database\UserScopedQuery;
use Lwt\Modules\Vocabulary\Application\VocabularyFacade;
use Lwt\Modules\Vocabulary\Application\Services\TermStatusService;
use Lwt\Modules\Vocabulary\Application\Helpers\StatusHelper;

/**
 * Handler for term status API operations.
 *
 * Provides endpoints for:
 * - Updating single term status
 * - Incrementing/decrementing status
 * - Bulk status updates
 * - Getting status list
 *
 * @since 3.0.0
 */
class TermStatusApiHandler
{
    private VocabularyFacade $facade;

    /**
     * Constructor.
     *
     * @param VocabularyFacade|null $facade Vocabulary facade
     */
    public function __construct(?VocabularyFacade $facade = null)
    {
        $this->facade = $facade ?? new VocabularyFacade();
    }

    // =========================================================================
    // Status Operations
    // =========================================================================

    /**
     * Update term status.
     *
     * @param int $termId Term ID
     * @param int $status New status
     *
     * @return array{success: bool, status?: int, error?: string}
     */
    public function updateStatus(int $termId, int $status): array
    {
        if (!TermStatusService::isValidStatus($status)) {
            return ['success' => false, 'error' => 'Invalid status'];
        }

        $result = $this->facade->updateStatus($termId, $status);

        if ($result) {
            return ['success' => true, 'status' => $status];
        }

        return ['success' => false, 'error' => 'Failed to update status'];
    }

    /**
     * Increment or decrement term status.
     *
     * @param int  $termId Term ID
     * @param bool $up     True to increment, false to decrement
     *
     * @return array{success: bool, status?: int, error?: string}
     */
    public function incrementStatus(int $termId, bool $up): array
    {
        $term = $this->facade->getTerm($termId);
        if ($term === null) {
            return ['success' => false, 'error' => 'Term not found'];
        }

        $result = $up
            ? $this->facade->advanceStatus($termId)
            : $this->facade->decreaseStatus($termId);

        if ($result) {
            // Fetch updated status
            $updatedTerm = $this->facade->getTerm($termId);
            $newStatus = $updatedTerm !== null ? $updatedTerm->status()->toInt() : 0;
            return ['success' => true, 'status' => $newStatus];
        }

        return ['success' => false, 'error' => 'Failed to update status'];
    }

    /**
     * Bulk update status for multiple terms.
     *
     * @param int[] $termIds Term IDs
     * @param int   $status  New status
     *
     * @return array{count: int, error?: string}
     */
    public function bulkUpdateStatus(array $termIds, int $status): array
    {
        if (!TermStatusService::isValidStatus($status)) {
            return ['count' => 0, 'error' => 'Invalid status'];
        }

        if (empty($termIds)) {
            return ['count' => 0, 'error' => 'No term IDs provided'];
        }

        $count = $this->facade->bulkUpdateStatus($termIds, $status);
        return ['count' => $count];
    }

    // =========================================================================
    // API Response Formatters
    // =========================================================================

    /**
     * Format response for updating status.
     *
     * @param int $termId Term ID
     * @param int $status New status
     *
     * @return array
     */
    public function formatUpdateStatus(int $termId, int $status): array
    {
        return $this->updateStatus($termId, $status);
    }

    /**
     * Format response for incrementing status.
     *
     * @param int  $termId Term ID
     * @param bool $up     True to increment, false to decrement
     *
     * @return array
     */
    public function formatIncrementStatus(int $termId, bool $up): array
    {
        return $this->incrementStatus($termId, $up);
    }

    /**
     * Format response for bulk status update.
     *
     * @param int[] $termIds Term IDs
     * @param int   $status  New status
     *
     * @return array
     */
    public function formatBulkUpdateStatus(array $termIds, int $status): array
    {
        return $this->bulkUpdateStatus($termIds, $status);
    }

    /**
     * Alias for formatBulkUpdateStatus (for API compatibility).
     *
     * @param int[] $termIds Term IDs
     * @param int   $status  New status
     *
     * @return array
     */
    public function formatBulkStatus(array $termIds, int $status): array
    {
        return $this->formatBulkUpdateStatus($termIds, $status);
    }

    /**
     * Format response for getting all statuses.
     *
     * @return array{statuses: array<int, array{abbr: string, name: string}>}
     */
    public function formatGetStatuses(): array
    {
        return ['statuses' => TermStatusService::getStatuses()];
    }

    // =========================================================================
    // Status Logic (for HTML controls)
    // =========================================================================

    /**
     * Force a term to get a new status.
     *
     * @param int $wid    ID of the word to edit
     * @param int $status New status to set
     *
     * @return int Number of affected rows
     */
    public function setWordStatus(int $wid, int $status): int
    {

        // Use raw SQL for dynamic score update
        $bindings = [$status, $wid];
        return Connection::preparedExecute(
            "UPDATE words
            SET WoStatus = ?, WoStatusChanged = NOW()
            WHERE WoID = ?"
            . UserScopedQuery::forTablePrepared('words', $bindings),
            [$status, $wid]
        );
    }

    /**
     * Check the consistency of the new status.
     *
     * @param int  $oldstatus Old status
     * @param bool $up        True if status should incremented, false if decrementation needed
     *
     * @return int New status in the good number range (1-5, 98, or 99)
     */
    public function getNewStatus(int $oldstatus, bool $up): int
    {
        $currstatus = $oldstatus;
        if ($up) {
            $currstatus++;
            if ($currstatus == 99) {
                $currstatus = 1;
            } elseif ($currstatus == 6) {
                $currstatus = 99;
            }
        } else {
            $currstatus--;
            if ($currstatus == 98) {
                $currstatus = 5;
            } elseif ($currstatus == 0) {
                $currstatus = 98;
            }
        }
        return $currstatus;
    }

    /**
     * Save the new word status to the database, return the controls.
     *
     * @param int $wid        Word ID
     * @param int $currstatus Current status in the good value range.
     *
     * @return string|null HTML-formatted string with plus/minus controls if a success.
     */
    public function updateWordStatus(int $wid, int $currstatus): ?string
    {
        if (TermStatusService::isValidStatus($currstatus)) {
            $m1 = $this->setWordStatus($wid, $currstatus);
            if ($m1 == 1) {
                /** @var int|null $fetchedStatus */
                $fetchedStatus = QueryBuilder::table('words')
                    ->select(['WoStatus'])
                    ->where('WoID', '=', $wid)
                    ->valuePrepared('WoStatus');
                if (!isset($fetchedStatus)) {
                    return null;
                }
                $statusInt = $fetchedStatus;
                $statusAbbr = StatusHelper::getAbbr($statusInt);
                return StatusHelper::buildReviewTableControls(1, $statusInt, $wid, $statusAbbr);
            }
        }
        return null;
    }

    /**
     * Do a word status change.
     *
     * @param int  $wid Word ID
     * @param bool $up  Should the status be incremeted or decremented
     *
     * @return string HTML-formatted string for increments
     */
    public function incrementTermStatus(int $wid, bool $up): string
    {
        /** @var int|null $fetchedStatus */
        $fetchedStatus = QueryBuilder::table('words')
            ->select(['WoStatus'])
            ->where('WoID', '=', $wid)
            ->valuePrepared('WoStatus');

        if (!isset($fetchedStatus)) {
            return '';
        }

        $currstatus = $this->getNewStatus($fetchedStatus, $up);
        $formatted = $this->updateWordStatus($wid, $currstatus);

        if ($formatted === null) {
            return '';
        }
        return $formatted;
    }

    /**
     * Format response for incrementing term status (with HTML controls).
     *
     * @param int  $termId   Term ID
     * @param bool $statusUp Whether to increment (true) or decrement (false)
     *
     * @return array{increment?: string, error?: string}
     */
    public function formatIncrementStatusHtml(int $termId, bool $statusUp): array
    {
        $result = $this->incrementTermStatus($termId, $statusUp);
        if ($result == '') {
            return ["error" => ''];
        }
        return ["increment" => $result];
    }

    /**
     * Format response for setting term status.
     *
     * @param int $termId Term ID
     * @param int $status New status
     *
     * @return array{set: int}
     */
    public function formatSetStatus(int $termId, int $status): array
    {
        $result = $this->setWordStatus($termId, $status);
        return ["set" => $result];
    }
}
