<?php

/**
 * Registration Form View
 *
 * Variables expected:
 * - $error: string|null Error message to display
 * - $username: string Pre-filled username
 * - $email: string Pre-filled email
 *
 * PHP version 8.2
 *
 * @category Lwt
 * @package  Lwt\Modules\User\Views
 * @author   HugoFara <git@hugofara.net>
 * @license  Unlicense <http://unlicense.org/>
 * @link     https://hugofara.github.io/lwt/developer/api
 * @since    3.0.0
 */

declare(strict_types=1);

namespace Lwt\Modules\User\Views;

// Validate injected variables from controller
assert(isset($username) && is_string($username));
assert(isset($email) && is_string($email));
assert(isset($error) && (is_string($error) || $error === null));
/** @var string|null $error */

$t = static fn(string $key): string => htmlspecialchars(__('user.' . $key), ENT_QUOTES, 'UTF-8');
?>

<section class="section">
    <div class="container">
        <div class="columns is-centered">
            <div class="column is-5-tablet is-4-desktop">
                <div class="box">
                    <?php echo \Lwt\Shared\UI\Helpers\PageLayoutHelper::languageSwitcher(); ?>
                    <!-- Logo/Title -->
                    <div class="has-text-centered mb-5">
                        <h1 class="title is-3">
                            <span class="icon-text">
                                <span class="icon has-text-primary">
                                    <i data-lucide="book-open"></i>
                                </span>
                                <span>LWT</span>
                            </span>
                        </h1>
                        <p class="subtitle is-6 has-text-grey"><?php echo $t('register.subtitle'); ?></p>
                    </div>

                    <!-- Error message -->
                    <?php if ($error !== null) : ?>
                    <div class="notification is-danger is-light">
                        <button class="delete" @click="$el.parentElement.remove()"></button>
                        <?php echo htmlspecialchars($error, ENT_QUOTES, 'UTF-8'); ?>
                    </div>
                    <?php endif; ?>

                    <!-- Registration form -->
                    <form method="POST" action="/register" x-data="registerForm()" @submit="submitForm($event)">
                        <?php echo \Lwt\Shared\UI\Helpers\FormHelper::csrfField(); ?>
                        <!-- Honeypot: hidden from people (off-screen, not
                             tabbable, autocomplete off); bots that fill it are
                             silently rejected server-side. Not translated on
                             purpose — it must look like a real field to a bot. -->
                        <div class="lwt-hp" aria-hidden="true">
                            <label for="homepage">Leave this field empty</label>
                            <input type="text" id="homepage" name="homepage"
                                tabindex="-1" autocomplete="off" value="">
                        </div>
                        <!-- Proof-of-work captcha solution, filled in by the
                             register form component before the POST. -->
                        <input type="hidden" id="altcha-solution" name="altcha" value="">
                        <div class="field">
                            <label class="label" for="username"><?php echo $t('register.username_label'); ?></label>
                            <div class="control has-icons-left has-icons-right">
                                <input
                                    type="text"
                                    id="username"
                                    name="username"
                                    class="input"
                                    :class="{ 'is-danger': errors.username }"
                                    placeholder="<?php echo $t('register.username_placeholder'); ?>"
                                    value="<?php echo htmlspecialchars($username, ENT_QUOTES, 'UTF-8'); ?>"
                                    required
                                    minlength="3"
                                    maxlength="100"
                                    pattern="[a-zA-Z0-9_-]+"
                                    @blur="validateUsername($event.target.value)"
                                    autofocus
                                >
                                <span class="icon is-small is-left">
                                    <i data-lucide="user"></i>
                                </span>
                                <span class="icon is-small is-right" x-show="errors.username">
                                    <i data-lucide="alert-circle" class="has-text-danger"></i>
                                </span>
                            </div>
                            <p class="help is-danger" x-show="errors.username" x-text="errors.username"></p>
                            <p class="help" x-show="!errors.username">
                                <?php echo $t('register.username_help'); ?>
                            </p>
                        </div>

                        <div class="field">
                            <label class="label" for="email"><?php echo $t('register.email_label_optional'); ?></label>
                            <div class="control has-icons-left has-icons-right">
                                <input
                                    type="email"
                                    id="email"
                                    name="email"
                                    class="input"
                                    :class="{ 'is-danger': errors.email }"
                                    placeholder="<?php echo $t('register.email_placeholder'); ?>"
                                    value="<?php echo htmlspecialchars($email, ENT_QUOTES, 'UTF-8'); ?>"
                                    @blur="validateEmail($event.target.value)"
                                >
                                <span class="icon is-small is-left">
                                    <i data-lucide="mail"></i>
                                </span>
                                <span class="icon is-small is-right" x-show="errors.email">
                                    <i data-lucide="alert-circle" class="has-text-danger"></i>
                                </span>
                            </div>
                            <p class="help is-danger" x-show="errors.email" x-text="errors.email"></p>
                            <p class="help" x-show="!errors.email">
                                <?php echo $t('register.email_help_optional'); ?>
                            </p>
                        </div>

                        <div class="field">
                            <label class="label" for="password"><?php echo $t('register.password_label'); ?></label>
                            <div class="control has-icons-left has-icons-right">
                                <input
                                    type="password"
                                    id="password"
                                    name="password"
                                    class="input"
                                    :class="{ 'is-danger': errors.password }"
                                    placeholder="<?php echo $t('register.password_placeholder'); ?>"
                                    required
                                    minlength="8"
                                    maxlength="128"
                                    x-model="password"
                                    @input="validatePassword()"
                                >
                                <span class="icon is-small is-left">
                                    <i data-lucide="lock"></i>
                                </span>
                                <span class="icon is-small is-right" x-show="errors.password">
                                    <i data-lucide="alert-circle" class="has-text-danger"></i>
                                </span>
                            </div>
                            <p class="help is-danger" x-show="errors.password" x-text="errors.password"></p>
                            <p class="help" x-show="!errors.password">
                                <?php echo $t('register.password_help'); ?>
                            </p>
                        </div>

                        <div class="field">
                            <label class="label" for="password_confirm">
                                <?php echo $t('register.password_confirm_label'); ?>
                            </label>
                            <div class="control has-icons-left has-icons-right">
                                <input
                                    type="password"
                                    id="password_confirm"
                                    name="password_confirm"
                                    class="input"
                                    :class="{ 'is-danger': errors.passwordConfirm }"
                                    placeholder="<?php echo $t('register.password_confirm_placeholder'); ?>"
                                    required
                                    x-model="passwordConfirm"
                                    @input="validatePasswordConfirm()"
                                >
                                <span class="icon is-small is-left">
                                    <i data-lucide="lock"></i>
                                </span>
                                <span class="icon is-small is-right"
                                    x-show="passwordConfirm && !errors.passwordConfirm">
                                    <i data-lucide="check" class="has-text-success"></i>
                                </span>
                                <span class="icon is-small is-right" x-show="errors.passwordConfirm">
                                    <i data-lucide="alert-circle" class="has-text-danger"></i>
                                </span>
                            </div>
                            <p class="help is-danger"
                                x-show="errors.passwordConfirm"
                                x-text="errors.passwordConfirm"></p>
                        </div>

                        <div class="field">
                            <div class="control">
                                <button
                                    type="submit"
                                    class="button is-primary is-fullwidth"
                                    :class="{ 'is-loading': loading }"
                                    :disabled="loading || hasErrors"
                                >
                                    <span class="icon">
                                        <i data-lucide="user-plus"></i>
                                    </span>
                                    <span><?php echo $t('register.submit'); ?></span>
                                </button>
                            </div>
                        </div>
                    </form>

                    <!-- Login link -->
                    <hr>
                    <p class="has-text-centered">
                        <?php echo $t('register.have_account'); ?>
                        <a href="/login"><?php echo $t('register.login_link'); ?></a>
                    </p>
                </div>
            </div>
        </div>
    </div>
</section>
