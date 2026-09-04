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
 * @since 3.7.0
 */
class NotificationHelper
{
    /**
     * Echo one notification.
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
     * Build one notification.
     *
     * @param string $message Already-translated message text
     * @param bool   $isError Whether this reports a failure
     *
     * @return string The notification markup
     */
    public static function markup(string $message, bool $isError = true): string
    {
        $notifClass = $isError ? 'is-danger' : 'is-success';
        $autoHide = $isError ? '' : ' data-auto-hide="true"';

        return '<div class="notification ' . $notifClass . '"' . $autoHide . '>'
            . '<button class="delete" aria-label="close"></button>'
            . htmlspecialchars($message, ENT_QUOTES, 'UTF-8')
            . '</div>';
    }
}
