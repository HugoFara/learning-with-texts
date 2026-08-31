<?php

/**
 * \file
 * \brief Helper for word status display and filtering.
 *
 * PHP version 8.2
 *
 * @category Application
 * @package  Lwt\Modules\Vocabulary\Application\Helpers
 * @author   HugoFara <git@hugofara.net>
 * @license  Unlicense <http://unlicense.org/>
 * @link     https://hugofara.github.io/lwt/developer/api
 * @since    3.0.0
 */

declare(strict_types=1);

namespace Lwt\Modules\Vocabulary\Application\Helpers;

use Lwt\Shared\UI\Helpers\IconHelper;
use Lwt\Modules\Vocabulary\Application\Services\TermStatusService;
use Lwt\Modules\Vocabulary\Domain\ValueObject\TermStatus;

/**
 * Helper class for word status-related display logic.
 *
 * Provides methods for building status-related SQL conditions,
 * CSS class filters, and HTML display elements.
 *
 * @since 3.0.0
 */
class StatusHelper
{
    /**
     * Build a SQL condition for filtering by status range.
     *
     * Status ranges:
     * - 1-5, 98, 99: Single status values
     * - 12-15: Status 1 to X (e.g., 14 = status 1-4)
     * - 23-25: Status 2 to X
     * - 34-35: Status 3 to X
     * - 45: Status 4-5
     * - 599: Status 5 or 99 (all known)
     *
     * @param string $fieldName   Database field name to filter on
     * @param int    $statusRange Status range code
     *
     * @return string SQL condition string
     */
    public static function makeCondition(string $fieldName, int $statusRange): string
    {
        if ($statusRange >= 12 && $statusRange <= 15) {
            return '(' . $fieldName . ' between 1 and ' . ($statusRange % 10) . ')';
        }
        if ($statusRange >= 23 && $statusRange <= 25) {
            return '(' . $fieldName . ' between 2 and ' . ($statusRange % 10) . ')';
        }
        if ($statusRange >= 34 && $statusRange <= 35) {
            return '(' . $fieldName . ' between 3 and ' . ($statusRange % 10) . ')';
        }
        if ($statusRange == 45) {
            return '(' . $fieldName . ' between 4 and 5)';
        }
        if ($statusRange == 599) {
            return $fieldName . ' in (5,99)';
        }
        return $fieldName . ' = ' . $statusRange;
    }

    /**
     * Check if a status value is within a status range.
     *
     * @param int $currentStatus Current status value
     * @param int $statusRange   Status range to check against
     *
     * @return bool True if status is within range
     */
    public static function checkRange(int $currentStatus, int $statusRange): bool
    {
        if ($statusRange >= 12 && $statusRange <= 15) {
            return ($currentStatus >= 1 && $currentStatus <= ($statusRange % 10));
        }
        if ($statusRange >= 23 && $statusRange <= 25) {
            return ($currentStatus >= 2 && $currentStatus <= ($statusRange % 10));
        }
        if ($statusRange >= 34 && $statusRange <= 35) {
            return ($currentStatus >= 3 && $currentStatus <= ($statusRange % 10));
        }
        if ($statusRange == 45) {
            return ($currentStatus == 4 || $currentStatus == 5);
        }
        if ($statusRange == 599) {
            return ($currentStatus == 5 || $currentStatus == 99);
        }
        return ($currentStatus == $statusRange);
    }

    /**
     * Build CSS class filter to exclude certain statuses.
     *
     * Returns a CSS selector string that excludes elements
     * NOT matching the given status filter.
     *
     * @param int|string $status Status filter value (0 returns empty string)
     *
     * @return string CSS :not() selector chain
     */
    public static function makeClassFilter(int|string $status): string
    {
        if ($status == 0) {
            return '';
        }

        $allStatuses = TermStatus::values();
        $includedStatuses = self::getIncludedStatuses($status);

        // Build :not() selectors for statuses NOT in the filter
        $result = '';
        foreach ($allStatuses as $s) {
            if (!in_array($s, $includedStatuses)) {
                $result .= ':not(.status' . $s . ')';
            }
        }

        return $result;
    }

    /**
     * Get the list of statuses included in a status range.
     *
     * @param int|string $statusRange Status range code
     *
     * @return int[] Array of included status values
     */
    public static function getIncludedStatuses(int|string $statusRange): array
    {
        $statusRange = (int)$statusRange;

        if ($statusRange == 599) {
            return [5, 99];
        }

        if ($statusRange < 6 || $statusRange > 97) {
            return [$statusRange];
        }

        // Range like 12, 23, 34, etc.
        $from = (int)($statusRange / 10);
        $to = $statusRange % 10;
        return range($from, $to);
    }

    /**
     * Build a colored status message HTML.
     *
     * @param int      $status   Status value
     * @param string   $name     Status display name
     * @param string   $abbr     Status abbreviation
     *
     * @return string HTML span with status styling
     */
    public static function buildColoredMessage(int $status, string $name, string $abbr): string
    {
        $escapedName = htmlspecialchars($name, ENT_QUOTES, 'UTF-8');
        $bracket = '';
        if ($abbr !== '' && $abbr !== $name) {
            $bracket = '&nbsp;[' . htmlspecialchars($abbr, ENT_QUOTES, 'UTF-8') . ']';
        }
        return '<span class="status' . $status . '">&nbsp;' . $escapedName
            . $bracket . '&nbsp;</span>';
    }

    /**
     * Build a "set status to" option for multiple word actions.
     *
     * @param int    $status Status value
     * @param string $name   Status display name
     * @param string $abbr   Status abbreviation
     * @param string $suffix Optional suffix for the option value
     *
     * @return string HTML option element
     */
    public static function buildSetStatusOption(
        int $status,
        string $name,
        string $abbr,
        string $suffix = ''
    ): string {
        $escapedName = htmlspecialchars($name, ENT_QUOTES, 'UTF-8');
        $bracket = '';
        if ($abbr !== '' && $abbr !== $name) {
            $bracket = ' [' . htmlspecialchars($abbr, ENT_QUOTES, 'UTF-8') . ']';
        }
        return '<option value="s' . $status . $suffix . '">'
            . htmlspecialchars(__('vocabulary.bulk.set_status_to_prefix'), ENT_QUOTES, 'UTF-8')
            . ' ' . $escapedName . $bracket . '</option>';
    }

    /**
     * Build status controls (plus/minus buttons) for test table.
     *
     * @param int    $score      Score associated with the word
     * @param int    $status     Current status value
     * @param int    $wordId     Word ID for JavaScript callback
     * @param string $statusAbbr Status abbreviation text
     *
     * @return string HTML controls string
     */
    public static function buildReviewTableControls(
        int $score,
        int $status,
        int $wordId,
        string $statusAbbr
    ): string {
        // Format the score text
        if ($score < 0) {
            $escaped = htmlspecialchars($statusAbbr, ENT_QUOTES, 'UTF-8');
            $scoreText = '<span class="has-text-danger has-text-weight-bold">' . $escaped . '</span>';
        } else {
            $scoreText = htmlspecialchars($statusAbbr, ENT_QUOTES, 'UTF-8');
        }

        // Build plus button
        if ($status <= 5 || $status == 98) {
            $plus = IconHelper::render('plus', [
                'class' => 'click',
                'title' => '+',
                'alt' => '+',
                'data-action' => 'change-test-status',
                'data-word-id' => (string)$wordId,
                'data-direction' => 'up'
            ]);
        } else {
            $plus = IconHelper::render('circle', ['class' => 'icon-muted', 'title' => '', 'alt' => '']);
        }

        // Build minus button
        if ($status >= 1) {
            $minus = IconHelper::render('minus', [
                'class' => 'click',
                'title' => '-',
                'alt' => '-',
                'data-action' => 'change-test-status',
                'data-word-id' => (string)$wordId,
                'data-direction' => 'down'
            ]);
        } else {
            $minus = IconHelper::render('circle', ['class' => 'icon-muted', 'title' => '', 'alt' => '']);
        }

        return ($status == 98 ? '' : $minus . ' ') . $scoreText . ($status == 99 ? '' : ' ' . $plus);
    }

    // =========================================================================
    // Methods migrated from Core/UI/ui_helpers.php
    // =========================================================================

    /**
     * Get status name by status code.
     *
     * @param int $status Status value (1-5, 98, or 99)
     *
     * @return string Status display name
     */
    public static function getName(int $status): string
    {
        $statuses = TermStatusService::getStatuses();
        return $statuses[$status]['name'] ?? '';
    }

    /**
     * Get status abbreviation by status code.
     *
     * @param int $status Status value (1-5, 98, or 99)
     *
     * @return string Status abbreviation
     */
    public static function getAbbr(int $status): string
    {
        $statuses = TermStatusService::getStatuses();
        return $statuses[$status]['abbr'] ?? '';
    }

    /**
     * Get a colored status message HTML for a given status code.
     *
     * This is a convenience method that looks up name and abbreviation automatically.
     *
     * @param int $status Status value (1-5, 98, or 99)
     *
     * @return string HTML span with status styling
     */
    public static function getColoredMessage(int $status): string
    {
        $name = self::getName($status);
        $abbr = self::getAbbr($status);
        return self::buildColoredMessage($status, $name, $abbr);
    }
}
