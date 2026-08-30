<?php

/**
 * Login Form View
 *
 * Variables expected:
 * - $error: string|null Error message to display
 * - $username: string Pre-filled username
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
assert(isset($error) && (is_string($error) || $error === null));
/** @var string|null $error */
$success = null;

$t = static fn(string $key): string => htmlspecialchars(__('user.' . $key), ENT_QUOTES, 'UTF-8');

// Check for success message (e.g., after password reset)
if (isset($_SESSION['auth_success'])) {
    /** @var mixed $authSuccess */
    $authSuccess = $_SESSION['auth_success'];
    $success = is_string($authSuccess) ? $authSuccess : null;
    unset($_SESSION['auth_success']);
}
?>

<section class="section">
    <div class="container">
        <div class="columns is-centered">
            <div class="column is-6-tablet is-5-desktop">
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
                        <p class="subtitle is-6 has-text-grey"><?php echo $t('app_subtitle'); ?></p>
                    </div>

                    <!-- Success message -->
                    <?php if ($success !== null) : ?>
                    <div class="notification is-success is-light">
                        <button class="delete" @click="$el.parentElement.remove()"></button>
                        <?php echo htmlspecialchars($success, ENT_QUOTES, 'UTF-8'); ?>
                    </div>
                    <?php endif; ?>

                    <!-- Error message -->
                    <?php if ($error !== null) : ?>
                    <div class="notification is-danger is-light">
                        <button class="delete" @click="$el.parentElement.remove()"></button>
                        <?php echo htmlspecialchars($error, ENT_QUOTES, 'UTF-8'); ?>
                    </div>
                    <?php endif; ?>

                    <!-- Login form -->
                    <form method="POST" action="/login" x-data="{ loading: false }" @submit="loading = true">
                        <?php echo \Lwt\Shared\UI\Helpers\FormHelper::csrfField(); ?>
                        <div class="field">
                            <label class="label" for="username"><?php echo $t('login.username_label'); ?></label>
                            <div class="control has-icons-left">
                                <input
                                    type="text"
                                    id="username"
                                    name="username"
                                    class="input"
                                    placeholder="<?php echo $t('login.username_placeholder'); ?>"
                                    value="<?php echo htmlspecialchars($username, ENT_QUOTES, 'UTF-8'); ?>"
                                    required
                                    autofocus
                                >
                                <span class="icon is-small is-left">
                                    <i data-lucide="user"></i>
                                </span>
                            </div>
                        </div>

                        <div class="field">
                            <label class="label" for="password"><?php echo $t('login.password_label'); ?></label>
                            <div class="control has-icons-left">
                                <input
                                    type="password"
                                    id="password"
                                    name="password"
                                    class="input"
                                    placeholder="<?php echo $t('login.password_placeholder'); ?>"
                                    required
                                >
                                <span class="icon is-small is-left">
                                    <i data-lucide="lock"></i>
                                </span>
                            </div>
                        </div>

                        <div class="field">
                            <div class="level is-mobile">
                                <div class="level-left">
                                    <label class="checkbox">
                                        <input type="checkbox" name="remember" value="1">
                                        <?php echo $t('login.remember_me'); ?>
                                    </label>
                                </div>
                                <div class="level-right">
                                    <a href="/password/forgot" class="is-size-7"><?php
                                        echo $t('login.forgot_password');
                                    ?></a>
                                </div>
                            </div>
                        </div>

                        <div class="field">
                            <div class="control">
                                <button
                                    type="submit"
                                    class="button is-primary is-fullwidth"
                                    :class="{ 'is-loading': loading }"
                                    :disabled="loading"
                                >
                                    <span class="icon">
                                        <i data-lucide="log-in"></i>
                                    </span>
                                    <span><?php echo $t('login.submit'); ?></span>
                                </button>
                            </div>
                        </div>
                    </form>

                    <!-- Registration link -->
                    <hr>
                    <p class="has-text-centered">
                        <?php echo $t('login.no_account'); ?>
                        <a href="/register"><?php echo $t('login.create_one'); ?></a>
                    </p>

                    <!-- WordPress login link (if available) -->
                    <?php if (($_ENV['WORDPRESS_ENABLED'] ?? '') !== '') : ?>
                    <p class="has-text-centered mt-3">
                        <a href="/wordpress/start" class="has-text-grey">
                            <span class="icon-text">
                                <span class="icon is-small">
                                    <i data-lucide="external-link"></i>
                                </span>
                                <span><?php echo $t('login.with_wordpress'); ?></span>
                            </span>
                        </a>
                    </p>
                    <?php endif; ?>

                    <!-- Google login link (if configured) -->
                    <?php if (($_ENV['GOOGLE_CLIENT_ID'] ?? '') !== '') : ?>
                    <p class="has-text-centered mt-3">
                        <a href="/google/start" class="button is-light is-fullwidth">
                            <span class="icon-text">
                                <span class="icon is-small">
                                    <!-- Google logo SVG -->
                                    <svg viewBox="0 0 24 24" width="16" height="16">
                                        <path
                                            fill="#4285F4"
                                            d="M22.56 12.25c0-.78-.07-1.53-.2-2.25H12v4.26h5.92
                                               c-.26 1.37-1.04 2.53-2.21 3.31v2.77h3.57
                                               c2.08-1.92 3.28-4.74 3.28-8.09z"
                                        />
                                        <path
                                            fill="#34A853"
                                            d="M12 23c2.97 0 5.46-.98 7.28-2.66l-3.57-2.77
                                               c-.98.66-2.23 1.06-3.71 1.06-2.86 0-5.29-1.93-6.16-4.53
                                               H2.18v2.84C3.99 20.53 7.7 23 12 23z"
                                        />
                                        <path
                                            fill="#FBBC05"
                                            d="M5.84 14.09c-.22-.66-.35-1.36-.35-2.09s.13-1.43.35-2.09
                                               V7.07H2.18C1.43 8.55 1 10.22 1 12s.43 3.45 1.18 4.93
                                               l2.85-2.22.81-.62z"
                                        />
                                        <path
                                            fill="#EA4335"
                                            d="M12 5.38c1.62 0 3.06.56 4.21 1.64l3.15-3.15
                                               C17.45 2.09 14.97 1 12 1 7.7 1 3.99 3.47 2.18 7.07
                                               l3.66 2.84c.87-2.6 3.3-4.53 6.16-4.53z"
                                        />
                                    </svg>
                                </span>
                                <span><?php echo $t('login.with_google'); ?></span>
                            </span>
                        </a>
                    </p>
                    <?php endif; ?>

                    <!-- Microsoft login link (if configured) -->
                    <?php if (($_ENV['MICROSOFT_CLIENT_ID'] ?? '') !== '') : ?>
                    <p class="has-text-centered mt-3">
                        <a href="/microsoft/start" class="button is-light is-fullwidth">
                            <span class="icon-text">
                                <span class="icon is-small">
                                    <svg viewBox="0 0 24 24" width="16" height="16">
                                        <rect x="1" y="1" width="10" height="10" fill="#F25022"/>
                                        <rect x="13" y="1" width="10" height="10" fill="#7FBA00"/>
                                        <rect x="1" y="13" width="10" height="10" fill="#00A4EF"/>
                                        <rect x="13" y="13" width="10" height="10" fill="#FFB900"/>
                                    </svg>
                                </span>
                                <span><?php echo $t('login.with_microsoft'); ?></span>
                            </span>
                        </a>
                    </p>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
</section>
