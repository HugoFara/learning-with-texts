<?php

/**
 * Microsoft Account Link Confirmation View
 *
 * Shown when a user signs in with Microsoft but their email already
 * exists in the database with a different account.
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
 * @var string $email The email address from Microsoft
 * @var string|null $error Error message if any
 */

use Lwt\Shared\UI\Helpers\FormHelper;

// Validate injected variables
assert(isset($email) && is_string($email));
$error = isset($error) && is_string($error) ? $error : null;

$t = static fn(string $key): string => htmlspecialchars(__('user.' . $key), ENT_QUOTES, 'UTF-8');
?>
<div class="container">
    <div class="columns is-centered">
        <div class="column is-half">
            <div class="box mt-6">
                <h1 class="title is-4 has-text-centered"><?php echo $t('microsoft_link.title'); ?></h1>

                <div class="notification is-info is-light">
                    <p>
                        <?php
                        echo __('user.microsoft_link.exists_html', [
                            'email' => htmlspecialchars($email, ENT_QUOTES, 'UTF-8'),
                        ]);
                        ?>
                    </p>
                    <p class="mt-2"><?php echo $t('microsoft_link.enter_password'); ?></p>
                </div>

                <?php if ($error !== null) : ?>
                <div class="notification is-danger is-light">
                    <?= htmlspecialchars($error) ?>
                </div>
                <?php endif; ?>

                <form method="post" action="/microsoft/link-confirm">
                    <?= FormHelper::csrfField() ?>

                    <div class="field">
                        <label class="label" for="password">
                            <?php echo $t('microsoft_link.password_label'); ?>
                        </label>
                        <div class="control">
                            <input
                                class="input"
                                type="password"
                                id="password"
                                name="password"
                                required
                                autofocus
                                placeholder="<?php echo $t('microsoft_link.password_placeholder'); ?>"
                            >
                        </div>
                    </div>

                    <div class="field is-grouped">
                        <div class="control">
                            <button
                                type="submit"
                                name="action"
                                value="link"
                                class="button is-primary"
                            >
                                <?php echo $t('microsoft_link.link_button'); ?>
                            </button>
                        </div>
                        <div class="control">
                            <button
                                type="submit"
                                name="action"
                                value="cancel"
                                class="button is-light"
                            >
                                <?php echo $t('microsoft_link.cancel_button'); ?>
                            </button>
                        </div>
                    </div>
                </form>

                <hr>

                <p class="has-text-centered has-text-grey">
                    <a href="/login"><?php echo $t('microsoft_link.back_to_login'); ?></a>
                </p>
            </div>
        </div>
    </div>
</div>
