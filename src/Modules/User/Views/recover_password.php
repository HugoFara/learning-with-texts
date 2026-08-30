<?php

/**
 * Recover-with-code Form View.
 *
 * Reset a password using a username + one-time recovery code (for accounts
 * created without an email). Reuses the resetPasswordForm Alpine component for
 * the new-password fields; username and recovery code are plain inputs.
 *
 * Variables expected:
 * - $username: string     Pre-filled username.
 * - $error: string|null   Error message to display.
 *
 * PHP version 8.2
 *
 * @category Lwt
 * @package  Lwt\Modules\User\Views
 * @author   HugoFara <git@hugofara.net>
 * @license  Unlicense <http://unlicense.org/>
 * @since    3.1.2
 */

declare(strict_types=1);

namespace Lwt\Modules\User\Views;

// Variables are extracted from the controller and may not be set here.
/** @psalm-suppress TypeDoesNotContainNull, RedundantCondition */
$username = isset($username) && is_string($username) ? $username : '';
/** @psalm-suppress TypeDoesNotContainNull, RedundantCondition */
$error = isset($error) && is_string($error) ? $error : null;

$t = static fn(string $key): string => htmlspecialchars(__('user.' . $key), ENT_QUOTES, 'UTF-8');
?>

<section class="section">
    <div class="container">
        <div class="columns is-centered">
            <div class="column is-5-tablet is-4-desktop">
                <div class="box">
                    <?php echo \Lwt\Shared\UI\Helpers\PageLayoutHelper::languageSwitcher(); ?>
                    <div class="has-text-centered mb-5">
                        <h1 class="title is-3">
                            <span class="icon-text">
                                <span class="icon has-text-primary">
                                    <i data-lucide="key"></i>
                                </span>
                                <span><?php echo $t('recovery.reset_page_title'); ?></span>
                            </span>
                        </h1>
                        <p class="subtitle is-6 has-text-grey"><?php echo $t('recovery.reset_subtitle'); ?></p>
                    </div>

                    <?php if ($error !== null) : ?>
                    <div class="notification is-danger is-light">
                        <button class="delete" @click="$el.parentElement.remove()"></button>
                        <?php echo htmlspecialchars($error, ENT_QUOTES, 'UTF-8'); ?>
                    </div>
                    <?php endif; ?>

                    <form
                        method="POST"
                        action="/password/recover"
                        x-data="resetPasswordForm()"
                        @submit="submitForm($event)"
                    >
                        <?php echo \Lwt\Shared\UI\Helpers\FormHelper::csrfField(); ?>

                        <div class="field">
                            <label class="label" for="username"><?php echo $t('recovery.username_label'); ?></label>
                            <div class="control has-icons-left">
                                <input
                                    type="text"
                                    id="username"
                                    name="username"
                                    class="input"
                                    value="<?php echo htmlspecialchars($username, ENT_QUOTES, 'UTF-8'); ?>"
                                    required
                                    autocapitalize="off"
                                    autocomplete="username"
                                    autofocus
                                >
                                <span class="icon is-small is-left"><i data-lucide="user"></i></span>
                            </div>
                        </div>

                        <div class="field">
                            <label class="label" for="recovery_code">
                                <?php echo $t('recovery.code_input_label'); ?></label>
                            <div class="control has-icons-left">
                                <input
                                    type="text"
                                    id="recovery_code"
                                    name="recovery_code"
                                    class="input"
                                    style="font-family: monospace; letter-spacing: 0.05em;"
                                    placeholder="XXXXX-XXXXX-XXXXX-XXXXX"
                                    required
                                    autocapitalize="characters"
                                    autocomplete="off"
                                    spellcheck="false"
                                >
                                <span class="icon is-small is-left"><i data-lucide="key"></i></span>
                            </div>
                        </div>

                        <div class="field">
                            <label class="label" for="password"><?php echo $t('reset.password_label'); ?></label>
                            <div class="control has-icons-left has-icons-right">
                                <input
                                    type="password"
                                    id="password"
                                    name="password"
                                    class="input"
                                    :class="{ 'is-danger': errors.password }"
                                    placeholder="<?php echo $t('reset.password_placeholder'); ?>"
                                    required
                                    minlength="8"
                                    maxlength="128"
                                    x-model="password"
                                    @input="validatePassword()"
                                >
                                <span class="icon is-small is-left"><i data-lucide="lock"></i></span>
                                <span class="icon is-small is-right" x-show="errors.password">
                                    <i data-lucide="alert-circle" class="has-text-danger"></i>
                                </span>
                            </div>
                            <p class="help is-danger" x-show="errors.password" x-text="errors.password"></p>
                            <p class="help" x-show="!errors.password">
                                <?php echo $t('reset.password_help'); ?>
                            </p>
                        </div>

                        <div class="field">
                            <label class="label" for="password_confirm">
                                <?php echo $t('reset.password_confirm_label'); ?>
                            </label>
                            <div class="control has-icons-left has-icons-right">
                                <input
                                    type="password"
                                    id="password_confirm"
                                    name="password_confirm"
                                    class="input"
                                    :class="{ 'is-danger': errors.passwordConfirm }"
                                    placeholder="<?php echo $t('reset.password_confirm_placeholder'); ?>"
                                    required
                                    x-model="passwordConfirm"
                                    @input="validatePasswordConfirm()"
                                >
                                <span class="icon is-small is-left"><i data-lucide="lock"></i></span>
                                <span
                                    class="icon is-small is-right"
                                    x-show="passwordConfirm && !errors.passwordConfirm"
                                >
                                    <i data-lucide="check" class="has-text-success"></i>
                                </span>
                                <span class="icon is-small is-right" x-show="errors.passwordConfirm">
                                    <i data-lucide="alert-circle" class="has-text-danger"></i>
                                </span>
                            </div>
                            <p
                                class="help is-danger"
                                x-show="errors.passwordConfirm"
                                x-text="errors.passwordConfirm"
                            ></p>
                        </div>

                        <div class="field">
                            <div class="control">
                                <button
                                    type="submit"
                                    class="button is-primary is-fullwidth"
                                    :class="{ 'is-loading': loading }"
                                    :disabled="loading || hasErrors"
                                >
                                    <span class="icon"><i data-lucide="check"></i></span>
                                    <span><?php echo $t('recovery.reset_submit'); ?></span>
                                </button>
                            </div>
                        </div>
                    </form>

                    <hr>

                    <p class="has-text-centered">
                        <a href="/login">
                            <span class="icon-text">
                                <span class="icon"><i data-lucide="arrow-left"></i></span>
                                <span><?php echo $t('reset.back_to_login'); ?></span>
                            </span>
                        </a>
                    </p>
                </div>
            </div>
        </div>
    </div>
</section>
