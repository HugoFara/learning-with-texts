<?php

/**
 * \file
 * \brief The one place that knows what a notification looks like.
 *
 * PHP version 8.2
 *
 * @category View
 * @package  Lwt
 * @author   HugoFara <git@hugofara.net>
 * @license  Unlicense <http://unlicense.org/>
 * @link     https://hugofara.github.io/lwt/developer/api
 * @since    3.7.0
 */

declare(strict_types=1);

namespace Lwt\Shared\UI\Helpers;

/**
 * Renders the Bulma notification a controller uses to report an outcome.
 *
 * This exists because the markup was being spelled out again at every site
 * that needed it -- once per feed controller, ten times in the term-import
 * controller -- and each hand-written copy escaped by hand and skipped the
 * translator. A helper taking an already-translated message makes the
 * translation step the visible one.
 *
 * The message is plain text: it is escaped here, so a caller cannot
 * accidentally pass markup through. A notification that genuinely needs
 * markup (an emphasised name, a link) belongs in a view.
 *
 * Most notifications report an outcome, so `render()` takes the boolean that
 * says which one and picks the level -- a success also auto-hides, a failure
 * stays put. The four Bulma levels are reachable through `renderAs()` for the
 * notifications that are neither, such as a warning about missing input.
 *
 * @since 3.7.0
 */
class NotificationHelper
{
    /** A failure the reader has to act on. */
    public const LEVEL_DANGER = 'is-danger';

    /** An outcome that went as asked. */
    public const LEVEL_SUCCESS = 'is-success';

    /** Something missing or ignored, but not a failure. */
    public const LEVEL_WARNING = 'is-warning';

    /** Progress or context, carrying no verdict. */
    public const LEVEL_INFO = 'is-info';

    /**
     * Echo one notification reporting an outcome.
     *
     * @param string $message Already-translated message text
     * @param bool   $isError Whether this reports a failure
     *
     * @return void
     */
    public static function render(string $message, bool $isError = true): void
    {
        echo self::markup($message, $isError);
    }

    /**
     * Build one notification reporting an outcome.
     *
     * @param string $message Already-translated message text
     * @param bool   $isError Whether this reports a failure
     *
     * @return string The notification markup
     */
    public static function markup(string $message, bool $isError = true): string
    {
        return $isError
            ? self::markupAs($message, self::LEVEL_DANGER)
            : self::markupAs($message, self::LEVEL_SUCCESS, true);
    }

    /**
     * Echo one notification at an explicit level.
     *
     * @param string $message  Already-translated message text
     * @param string $level    One of the LEVEL_* constants
     * @param bool   $autoHide Whether the notification dismisses itself
     *
     * @return void
     */
    public static function renderAs(string $message, string $level, bool $autoHide = false): void
    {
        echo self::markupAs($message, $level, $autoHide);
    }

    /**
     * Build one notification at an explicit level.
     *
     * @param string $message  Already-translated message text
     * @param string $level    One of the LEVEL_* constants
     * @param bool   $autoHide Whether the notification dismisses itself
     *
     * @return string The notification markup
     */
    public static function markupAs(
        string $message,
        string $level,
        bool $autoHide = false
    ): string {
        $autoHideAttr = $autoHide ? ' data-auto-hide="true"' : '';

        return '<div class="notification ' . $level . '"' . $autoHideAttr . '>'
            . '<button class="delete" aria-label="close"></button>'
            . htmlspecialchars($message, ENT_QUOTES, 'UTF-8')
            . '</div>';
    }
}
