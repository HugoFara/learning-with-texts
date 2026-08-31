<?php

declare(strict_types=1);

namespace Lwt\Modules\Feed\Http;

use Lwt\Shared\Infrastructure\Http\FlashMessageService;

/**
 * Shared flash message rendering for Feed controllers.
 *
 * @since 3.0.0
 */
trait FeedFlashTrait
{
    /**
     * Render flash messages from the flash message service.
     *
     * @param FlashMessageService $flashService Flash message service
     *
     * @return void
     */
    protected function renderFlashMessages(FlashMessageService $flashService): void
    {
        $flashMessages = $flashService->getAndClear();
        foreach ($flashMessages as $flashMsg) {
            $this->renderNotification(
                $flashMsg['message'],
                FlashMessageService::isError($flashMsg['type'])
            );
        }
    }

    /**
     * Render one notification.
     *
     * Split out of the loop above so a controller reporting a problem of its
     * own does not have to spell the markup out again -- which is how the feed
     * controllers ended up with several hand-written copies of it, each
     * escaping by hand and none of them translated.
     *
     * @param string $message Already-translated message text
     * @param bool   $isError Whether this reports a failure
     *
     * @return void
     */
    protected function renderNotification(string $message, bool $isError = true): void
    {
        $notifClass = $isError ? 'is-danger' : 'is-success';
        $autoHide = $isError ? '' : ' data-auto-hide="true"';

        echo '<div class="notification ' . $notifClass . '"' . $autoHide . '>'
            . '<button class="delete" aria-label="close"></button>'
            . htmlspecialchars($message, ENT_QUOTES, 'UTF-8')
            . '</div>';
    }
}
