<?php

declare(strict_types=1);

namespace Lwt\Modules\Feed\Http;

use Lwt\Shared\Infrastructure\Http\FlashMessageService;
use Lwt\Shared\UI\Helpers\NotificationHelper;

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
     * escaping by hand and none of them translated. The markup itself now
     * lives in NotificationHelper, since the term-import controller had the
     * same ten copies of it for the same reason.
     *
     * @param string $message Already-translated message text
     * @param bool   $isError Whether this reports a failure
     *
     * @return void
     */
    protected function renderNotification(string $message, bool $isError = true): void
    {
        NotificationHelper::render($message, $isError);
    }
}
