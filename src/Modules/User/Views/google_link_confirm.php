<?php

declare(strict_types=1);

/**
 * Google Account Link Confirmation View
 *
 * Shown when a user tries to log in with Google but an account
 * with the same email already exists.
 *
 * PHP version 8.2
 *
 * @category Lwt
 * @package  Lwt\Modules\User\Views
 * @author   HugoFara <git@hugofara.net>
 * @license  Unlicense <http://unlicense.org/>
 * @link     https://hugofara.github.io/lwt/developer/api
 * @since    3.0.0
 *
 * @var string      $email Email address from Google
 * @var string|null $error Error message (if any)
 */

namespace Lwt\Modules\User\Views;

use Lwt\Shared\UI\Helpers\FormHelper;

// Validate injected variables
assert(isset($email) && is_string($email));
$error = isset($error) && is_string($error) ? $error : null;

$t = static fn(string $key): string => htmlspecialchars(__('user.' . $key), ENT_QUOTES, 'UTF-8');
?>
<section class="section">
    <div class="container">
        <div class="columns is-centered">
            <div class="column is-5-tablet is-4-desktop">
                <div class="box">
                    <div class="has-text-centered mb-5">
                        <h1 class="title is-4"><?php echo $t('google_link.title'); ?></h1>
                        <p class="subtitle is-6 has-text-grey"><?php echo $t('google_link.subtitle'); ?></p>
                    </div>

                    <?php if ($error !== null) : ?>
                    <div class="notification is-danger is-light">
                        <button class="delete" @click="$el.parentElement.remove()"></button>
                        <?php echo htmlspecialchars($error, ENT_QUOTES, 'UTF-8'); ?>
                    </div>
                    <?php endif; ?>

                    <div class="notification is-info is-light">
                        <p>
                            <?php
                            echo __('user.google_link.exists_html', [
                                'email' => htmlspecialchars($email, ENT_QUOTES, 'UTF-8'),
                            ]);
                            ?>
                        </p>
                        <p class="mt-2"><?php echo $t('google_link.enter_password'); ?></p>
                    </div>

                    <form method="POST" action="/google/link-confirm">
                        <?php echo FormHelper::csrfField(); ?>

                        <div class="field">
                            <label class="label" for="password">
                                <?php echo $t('google_link.password_label'); ?>
                            </label>
                            <div class="control has-icons-left">
                                <input
                                    type="password"
                                    id="password"
                                    name="password"
                                    class="input"
                                    placeholder="<?php echo $t('google_link.password_placeholder'); ?>"
                                    required
                                    autofocus
                                >
                                <span class="icon is-small is-left">
                                    <i data-lucide="lock"></i>
                                </span>
                            </div>
                        </div>

                        <div class="field is-grouped">
                            <div class="control is-expanded">
                                <button type="submit" name="action" value="link" class="button is-primary is-fullwidth">
                                    <?php echo $t('google_link.link_button'); ?>
                                </button>
                            </div>
                            <div class="control">
                                <button type="submit" name="action" value="cancel" class="button is-light">
                                    <?php echo $t('google_link.cancel_button'); ?>
                                </button>
                            </div>
                        </div>
                    </form>

                    <hr>
                    <p class="has-text-centered is-size-7 has-text-grey">
                        <?php echo $t('google_link.forgot_prompt'); ?>
                        <a href="/password/forgot"><?php echo $t('google_link.reset_link'); ?></a>
                    </p>
                </div>
            </div>
        </div>
    </div>
</section>
