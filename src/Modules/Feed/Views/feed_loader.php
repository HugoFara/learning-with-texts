<?php

/**
 * Feed loader - progress while one or more feeds are fetched.
 *
 * The markup used to be echoed twice: once from `FeedFacade`, an
 * application-layer class, and once from `FeedLoadController`. Both copies
 * spelled their labels in English with no `__()` call, so all nine locales
 * read "UPDATING 0/3 FEEDS" (#266). One view now holds it, and the controller
 * is what renders it.
 *
 * Expects `$feedCount` (int) and to be included inside a page that has already
 * emitted the `feed-loader-config` island the `feedLoader` component reads.
 *
 * PHP version 8.2
 *
 * @category Lwt
 * @package  Lwt\Views
 * @author   HugoFara <git@hugofara.net>
 * @license  Unlicense <http://unlicense.org/>
 * @link     https://hugofara.github.io/lwt/developer/api
 * @since    3.7.1
 */

declare(strict_types=1);

namespace Lwt\Views\Feed;

/** @var int $feedCount */

$updatingLine = __(
    'feed.loader.updating',
    [
        'loaded' => '<span x-text="loadedCount">0</span>',
        'count' => (string)$feedCount,
    ]
);
?>
<div x-data="feedLoader()">
    <?php if ($feedCount !== 1) { ?>
        <div class="notification is-info">
            <p><?php echo $updatingLine; ?></p>
        </div>
    <?php } ?>
    <template x-for="feed in feeds" :key="feed.id">
        <div :class="getStatusClass(feed.id)"><p x-text="feedMessages[feed.id]"></p></div>
    </template>
    <div class="has-text-centered">
        <button @click="handleContinue()"><?php echo __e('common.continue'); ?></button>
    </div>
</div>
