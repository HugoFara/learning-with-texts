<?php

/**
 * Term Status Service
 *
 * PHP version 8.1
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

use Lwt\Modules\Vocabulary\Domain\ValueObject\TermStatus;

/**
 * Service class for term status definitions and scoring.
 *
 * Contains status definitions, SQL formulas for scoring,
 * and utility methods for status management.
 *
 * @since 3.0.0
 */
class TermStatusService
{
    /**
     * Decay rates per status level (score decrease per day).
     */
    private const DECAY_RATES = [
        1 => 7.0,
        2 => 3.5,
        3 => 2.3,
        4 => 1.75,
        5 => 1.4,
    ];

    /**
     * Base scores for each status level (day 0).
     */
    private const BASE_SCORES = [
        1 => 0.0,
        2 => 6.9,
        3 => 20.0,
        4 => 46.4,
        5 => 100.0,
    ];

    /**
     * Return an associative array of all possible statuses.
     *
     * Thin adapter over {@see TermStatus::definitions()} (the single source
     * of truth): names are localized via the i18n translator, abbreviations
     * are language-neutral ("1".."5" for learning levels, empty for 98/99 —
     * display code falls back to the full name when the abbreviation is empty).
     *
     * @return array<int, array{abbr: string, name: string}>
     *         Keys are 1, 2, 3, 4, 5, 98, 99.
     */
    public static function getStatuses(): array
    {
        $statuses = [];
        foreach (TermStatus::definitions() as $definition) {
            $statuses[$definition['value']] = [
                'abbr' => $definition['abbr'],
                'name' => $definition['name'],
            ];
        }
        return $statuses;
    }


    /**
     * Check if a status is valid.
     *
     * @param int $status Status to check
     *
     * @return bool True if valid status
     */
    public static function isValidStatus(int $status): bool
    {
        return TermStatus::isValid($status);
    }

    /**
     * Get status name.
     *
     * @param int $status Status value
     *
     * @return string Status name or empty if not found
     */
    public static function getStatusName(int $status): string
    {
        $statuses = self::getStatuses();
        return $statuses[$status]['name'] ?? '';
    }

    /**
     * Get status abbreviation.
     *
     * @param int $status Status value
     *
     * @return string Status abbreviation or empty if not found
     */
    public static function getStatusAbbr(int $status): string
    {
        $statuses = self::getStatuses();
        return $statuses[$status]['abbr'] ?? '';
    }

    /**
     * Calculate the current score for a term based on status and days since status change.
     *
     * @param int $status          Term status (1-5)
     * @param int $daysSinceChange Days since status was changed
     *
     * @return float Score value (-125 to 100)
     */
    public static function calculateScore(int $status, int $daysSinceChange): float
    {
        // Special statuses always return 100
        if ($status > 5) {
            return 100.0;
        }

        if (!isset(self::BASE_SCORES[$status]) || !isset(self::DECAY_RATES[$status])) {
            return 0.0;
        }

        $baseScore = self::BASE_SCORES[$status];
        $decayRate = self::DECAY_RATES[$status];

        $score = $baseScore - ($decayRate * $daysSinceChange);

        return max(-125.0, $score);
    }

    /**
     * Calculate tomorrow's score for a term.
     *
     * @param int $status          Term status (1-5)
     * @param int $daysSinceChange Days since status was changed
     *
     * @return float Tomorrow's score value (-125 to 100)
     */
    public static function calculateTomorrowScore(int $status, int $daysSinceChange): float
    {
        return self::calculateScore($status, $daysSinceChange + 1);
    }

    /**
     * Get the CSS color class for a status.
     *
     * @param int $status Status value
     *
     * @return string CSS color class name
     */
    public static function getStatusColor(int $status): string
    {
        return TermStatus::isValid($status)
            ? TermStatus::fromInt($status)->cssClass()
            : 'status0';
    }

    /**
     * Get all learning statuses (1-5).
     *
     * @return int[]
     */
    public static function getLearningStatuses(): array
    {
        return [
            TermStatus::NEW,
            TermStatus::LEARNING_2,
            TermStatus::LEARNING_3,
            TermStatus::LEARNING_4,
            TermStatus::LEARNED,
        ];
    }

    /**
     * Get active learning statuses (1-4, not yet learned).
     *
     * @return int[]
     */
    public static function getActiveLearningStatuses(): array
    {
        return [
            TermStatus::NEW,
            TermStatus::LEARNING_2,
            TermStatus::LEARNING_3,
            TermStatus::LEARNING_4,
        ];
    }

    /**
     * Get known statuses (5 and 99).
     *
     * @return int[]
     */
    public static function getKnownStatuses(): array
    {
        return [
            TermStatus::LEARNED,
            TermStatus::WELL_KNOWN,
        ];
    }

    /**
     * Check if a status represents a learning term (not ignored, not well-known).
     *
     * @param int $status Status value
     *
     * @return bool
     */
    public static function isLearningStatus(int $status): bool
    {
        return TermStatus::isLearningValue($status);
    }

    /**
     * Check if a status represents a known term.
     *
     * @param int $status Status value
     *
     * @return bool
     */
    public static function isKnownStatus(int $status): bool
    {
        return TermStatus::isKnownValue($status);
    }

    /**
     * Check if a status represents an ignored term.
     *
     * @param int $status Status value
     *
     * @return bool
     */
    public static function isIgnoredStatus(int $status): bool
    {
        return TermStatus::isIgnoredValue($status);
    }
}
