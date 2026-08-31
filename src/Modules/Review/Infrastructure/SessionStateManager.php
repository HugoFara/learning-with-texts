<?php

/**
 * Session State Manager
 *
 * Infrastructure adapter for PHP session state management.
 *
 * PHP version 8.2
 *
 * @category Lwt
 * @package  Lwt\Modules\Review\Infrastructure
 * @author   HugoFara <git@hugofara.net>
 * @license  Unlicense <http://unlicense.org/>
 * @link     https://hugofara.github.io/lwt/developer/api
 * @since    3.0.0
 */

declare(strict_types=1);

namespace Lwt\Modules\Review\Infrastructure;

use Lwt\Modules\Review\Domain\ReviewSession;

/**
 * Adapter for PHP session state management.
 *
 * Abstracts $_SESSION access for the Review module,
 * enabling testability and future session backend changes.
 *
 * @since 3.0.0
 */
class SessionStateManager
{
    /**
     * Session keys used for review state.
     */
    private const KEY_START = 'reviewstart';
    private const KEY_TOTAL = 'reviewtotal';
    private const KEY_CORRECT = 'reviewcorrect';
    private const KEY_WRONG = 'reviewwrong';

    /**
     * Session keys used for review criteria.
     */
    private const KEY_REVIEW_KEY = 'review_key';
    private const KEY_SELECTION = 'review_selection';

    /**
     * Get the current review session from PHP session.
     *
     * @return ReviewSession|null Session or null if not initialized
     */
    public function getSession(): ?ReviewSession
    {
        if (!isset($_SESSION[self::KEY_TOTAL])) {
            return null;
        }

        return new ReviewSession(
            (int) ($_SESSION[self::KEY_START] ?? 0),
            (int) $_SESSION[self::KEY_TOTAL],
            (int) ($_SESSION[self::KEY_CORRECT] ?? 0),
            (int) ($_SESSION[self::KEY_WRONG] ?? 0)
        );
    }

    /**
     * Save the review session to PHP session.
     *
     * @param ReviewSession $session Session to save
     *
     * @return void
     */
    public function saveSession(ReviewSession $session): void
    {
        $_SESSION[self::KEY_START] = $session->getStartTime();
        $_SESSION[self::KEY_TOTAL] = $session->getTotal();
        $_SESSION[self::KEY_CORRECT] = $session->getCorrect();
        $_SESSION[self::KEY_WRONG] = $session->getWrong();
    }

    /**
     * Clear the review session from PHP session.
     *
     * @return void
     */
    public function clearSession(): void
    {
        unset(
            $_SESSION[self::KEY_START],
            $_SESSION[self::KEY_TOTAL],
            $_SESSION[self::KEY_CORRECT],
            $_SESSION[self::KEY_WRONG]
        );
    }

    /**
     * Check if a session exists.
     *
     * @return bool
     */
    public function hasSession(): bool
    {
        return isset($_SESSION[self::KEY_TOTAL]);
    }

    /**
     * Get raw session data (for backward compatibility).
     *
     * @return array{start: int, total: int, correct: int, wrong: int}
     */
    public function getRawSessionData(): array
    {
        return [
            'start' => (int) ($_SESSION[self::KEY_START] ?? 0),
            'total' => (int) ($_SESSION[self::KEY_TOTAL] ?? 0),
            'correct' => (int) ($_SESSION[self::KEY_CORRECT] ?? 0),
            'wrong' => (int) ($_SESSION[self::KEY_WRONG] ?? 0)
        ];
    }

    // =========================================================================
    // Review Criteria Management
    // =========================================================================

    /**
     * Save review criteria to session.
     *
     * Stores the review key type and selection values instead of raw SQL.
     * This is safer and allows proper validation of the criteria.
     *
     * @param string    $reviewKey  Review key type ('words', 'texts', 'lang', 'text')
     * @param int|int[] $selection  Selection value (ID or array of IDs)
     *
     * @return void
     */
    public function saveCriteria(string $reviewKey, int|array $selection): void
    {
        $_SESSION[self::KEY_REVIEW_KEY] = $reviewKey;
        $_SESSION[self::KEY_SELECTION] = $selection;
    }

    /**
     * Get review criteria from session.
     *
     * @return array{reviewKey: string, selection: int|int[]}|null Criteria or null if not set
     */
    public function getCriteria(): ?array
    {
        if (!isset($_SESSION[self::KEY_REVIEW_KEY]) || !isset($_SESSION[self::KEY_SELECTION])) {
            return null;
        }

        /** @var mixed $reviewKeyRaw */
        $reviewKeyRaw = $_SESSION[self::KEY_REVIEW_KEY];
        /** @var mixed $selectionRaw */
        $selectionRaw = $_SESSION[self::KEY_SELECTION];

        $reviewKey = is_string($reviewKeyRaw) ? $reviewKeyRaw : '';

        // Handle selection - can be int or array of ints
        if (is_array($selectionRaw)) {
            $selection = array_map('intval', $selectionRaw);
        } else {
            $selection = (int) $selectionRaw;
        }

        return [
            'reviewKey' => $reviewKey,
            'selection' => $selection
        ];
    }

    /**
     * Check if review criteria exists in session.
     *
     * @return bool True if criteria is set
     */
    public function hasCriteria(): bool
    {
        return isset($_SESSION[self::KEY_REVIEW_KEY]) && isset($_SESSION[self::KEY_SELECTION]);
    }

    /**
     * Clear review criteria from session.
     *
     * @return void
     */
    public function clearCriteria(): void
    {
        unset(
            $_SESSION[self::KEY_REVIEW_KEY],
            $_SESSION[self::KEY_SELECTION]
        );
    }

    /**
     * Clear all review data from session (criteria and progress).
     *
     * @return void
     */
    public function clearAll(): void
    {
        $this->clearSession();
        $this->clearCriteria();
    }

    /**
     * Get selection as string (for URL parameters).
     *
     * @return string Comma-separated IDs or empty string
     */
    public function getSelectionString(): string
    {
        $criteria = $this->getCriteria();
        if ($criteria === null) {
            return '';
        }

        $selection = $criteria['selection'];
        if (is_array($selection)) {
            return implode(',', $selection);
        }
        return (string) $selection;
    }

    /**
     * Record an answer in the session.
     *
     * @param bool $correct Whether the answer was correct
     *
     * @return void
     */
    public function recordAnswer(bool $correct): void
    {
        if ($correct) {
            $_SESSION[self::KEY_CORRECT] = ((int) ($_SESSION[self::KEY_CORRECT] ?? 0)) + 1;
        } else {
            $_SESSION[self::KEY_WRONG] = ((int) ($_SESSION[self::KEY_WRONG] ?? 0)) + 1;
        }
    }

    /**
     * Initialize a new review session with criteria and progress.
     *
     * @param string    $reviewKey  Review key type
     * @param int|int[] $selection  Selection value
     * @param int       $totalDue   Total words due for review
     *
     * @return void
     */
    public function initializeSession(string $reviewKey, int|array $selection, int $totalDue): void
    {
        $this->saveCriteria($reviewKey, $selection);
        $_SESSION[self::KEY_START] = time() + 2;
        $_SESSION[self::KEY_TOTAL] = $totalDue;
        $_SESSION[self::KEY_CORRECT] = 0;
        $_SESSION[self::KEY_WRONG] = 0;
    }
}
