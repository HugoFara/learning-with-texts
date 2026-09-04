<?php

/**
 * The banner shown to a signed-in user whose email is not yet verified.
 *
 * It used to be echoed from `PageLayoutHelper`, spelling its three strings in
 * English, so every locale read "Email not verified." (#266). It carries
 * markup -- an emphasised opening and a resend form -- which is exactly the
 * case `NotificationHelper` sends to a view.
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

namespace Lwt\Views\User;

use Lwt\Shared\UI\Helpers\FormHelper;

?>
<div class="notification is-warning" role="alert">
    <strong><?php echo __e('user.verify_banner.title'); ?></strong>
    <?php echo __e('user.verify_banner.body'); ?>
    <form method="post" action="/email/resend-verification" style="display:inline">
        <?php echo FormHelper::csrfField(); ?>
        <button type="submit" class="button is-small is-warning is-outlined ml-2">
            <?php echo __e('user.verify_banner.resend'); ?>
        </button>
    </form>
</div>
