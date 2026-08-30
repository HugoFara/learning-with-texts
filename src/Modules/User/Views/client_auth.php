<?php

/**
 * Packaged-client auth view — "choose server + log in".
 *
 * Entry screen for a cross-origin/packaged client (the planned Capacitor /
 * F-Droid app). Unlike login.php (server-rendered cookie session), this is a
 * client-rendered, token-based flow driven by the `clientAuth` Alpine
 * component (src/frontend/js/modules/auth/pages/client_auth.ts):
 *   step 1 — pick a server (validated against /api/v1/version)
 *   step 2 — log in (POST /api/v1/auth/login -> bearer token)
 *
 * Strings are intentionally inline English for now; client-side i18n is a
 * separate roadmap item (Phase 1).
 *
 * PHP version 8.2
 *
 * @category Lwt
 * @package  Lwt\Modules\User\Views
 * @author   HugoFara <git@hugofara.net>
 * @license  Unlicense <http://unlicense.org/>
 * @since    3.1.1
 */

declare(strict_types=1);

namespace Lwt\Modules\User\Views;

use Lwt\Shared\UI\Helpers\ConfigIsland;

?>
<div class="container">
    <div class="columns is-centered">
        <div class="column is-6-tablet is-5-desktop">
            <div class="box" x-data="clientAuth">
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
                    <p class="subtitle is-6 has-text-grey">Connect to your server</p>
                </div>

                <!-- Error message -->
                <div class="notification is-danger is-light" x-show="error" x-cloak>
                    <span x-text="error"></span>
                </div>

                <!-- Step 1: choose server -->
                <form @submit.prevent="connect()" x-show="onServerStep" x-cloak>
                    <div class="field">
                        <label class="label" for="server-url">Server address</label>
                        <div class="control has-icons-left">
                            <input
                                type="text"
                                id="server-url"
                                class="input"
                                placeholder="https://my-lwt-server.org"
                                x-model="serverUrl"
                                inputmode="url"
                                autocomplete="url"
                                required
                            >
                            <span class="icon is-small is-left">
                                <i data-lucide="server"></i>
                            </span>
                        </div>
                        <p class="help">The address of the LWT server you want to read from.</p>
                    </div>

                    <div class="field">
                        <div class="control">
                            <button
                                type="submit"
                                class="button is-primary is-fullwidth"
                                :class="{ 'is-loading': loading }"
                                :disabled="loading"
                            >
                                <span class="icon"><i data-lucide="plug"></i></span>
                                <span>Connect</span>
                            </button>
                        </div>
                    </div>
                </form>

                <!-- Step 2: log in or create an account -->
                <div x-show="onLoginStep" x-cloak>
                    <!-- Log in -->
                    <form @submit="submitLogin($event)" x-show="onLoginMode">
                        <div class="field">
                            <label class="label" for="client-username">Username or email</label>
                            <div class="control has-icons-left">
                                <input
                                    type="text"
                                    id="client-username"
                                    class="input"
                                    x-model="username"
                                    autocomplete="username"
                                    required
                                >
                                <span class="icon is-small is-left">
                                    <i data-lucide="user"></i>
                                </span>
                            </div>
                        </div>

                        <div class="field">
                            <label class="label" for="client-password">Password</label>
                            <div class="control has-icons-left">
                                <input
                                    type="password"
                                    id="client-password"
                                    class="input"
                                    x-model="password"
                                    autocomplete="current-password"
                                    required
                                >
                                <span class="icon is-small is-left">
                                    <i data-lucide="lock"></i>
                                </span>
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
                                    <span class="icon"><i data-lucide="log-in"></i></span>
                                    <span>Log in</span>
                                </button>
                            </div>
                        </div>

                        <p class="has-text-centered">
                            <a class="is-size-7" @click="showRegister()">Create an account</a>
                        </p>
                    </form>

                    <!-- Create an account -->
                    <form @submit="submitRegister($event)" x-show="onRegisterMode">
                        <!-- Honeypot: hidden from people; bots that fill it are
                             rejected server-side. -->
                        <div class="lwt-hp" aria-hidden="true">
                            <label for="reg-homepage">Leave this field empty</label>
                            <input type="text" id="reg-homepage" x-model="homepage"
                                tabindex="-1" autocomplete="off">
                        </div>
                        <div class="field">
                            <label class="label" for="reg-username">Username</label>
                            <div class="control has-icons-left">
                                <input
                                    type="text"
                                    id="reg-username"
                                    class="input"
                                    x-model="username"
                                    autocomplete="username"
                                    required
                                >
                                <span class="icon is-small is-left">
                                    <i data-lucide="user"></i>
                                </span>
                            </div>
                        </div>

                        <div class="field">
                            <label class="label" for="reg-email">Email
                                <span class="has-text-grey is-size-7">(optional)</span></label>
                            <div class="control has-icons-left">
                                <input
                                    type="email"
                                    id="reg-email"
                                    class="input"
                                    x-model="email"
                                    autocomplete="email"
                                >
                                <span class="icon is-small is-left">
                                    <i data-lucide="mail"></i>
                                </span>
                            </div>
                            <p class="help">Only used to recover a forgotten password. Leave blank to skip.</p>
                        </div>

                        <div class="field">
                            <label class="label" for="reg-password">Password</label>
                            <div class="control has-icons-left">
                                <input
                                    type="password"
                                    id="reg-password"
                                    class="input"
                                    x-model="password"
                                    autocomplete="new-password"
                                    required
                                >
                                <span class="icon is-small is-left">
                                    <i data-lucide="lock"></i>
                                </span>
                            </div>
                        </div>

                        <div class="field">
                            <label class="label" for="reg-password-confirm">Confirm password</label>
                            <div class="control has-icons-left">
                                <input
                                    type="password"
                                    id="reg-password-confirm"
                                    class="input"
                                    x-model="passwordConfirm"
                                    autocomplete="new-password"
                                    required
                                >
                                <span class="icon is-small is-left">
                                    <i data-lucide="lock"></i>
                                </span>
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
                                    <span class="icon"><i data-lucide="user-plus"></i></span>
                                    <span>Create account</span>
                                </button>
                            </div>
                        </div>

                        <p class="has-text-centered">
                            <a class="is-size-7" @click="showLogin()">Already have an account? Log in</a>
                        </p>
                    </form>

                    <hr>
                    <p class="has-text-centered">
                        <a class="is-size-7" @click="back()">Use a different server</a>
                    </p>
                </div>

                <!-- Step 3: one-time recovery code (after an email-less sign-up) -->
                <div x-show="onRecoveryStep" x-cloak>
                    <div class="has-text-centered mb-4">
                        <span class="icon has-text-primary is-large"><i data-lucide="key"></i></span>
                        <h2 class="title is-5 mt-2">Your recovery code</h2>
                    </div>
                    <div class="notification is-warning is-light">
                        Save this code somewhere safe. It is the only way to recover your
                        account if you forget your password, and it will not be shown again.
                    </div>
                    <div class="field">
                        <div class="control">
                            <input
                                type="text"
                                class="input is-medium has-text-centered has-text-weight-semibold"
                                style="font-family: monospace; letter-spacing: 0.1em;"
                                x-model="recoveryCode"
                                readonly
                            >
                        </div>
                    </div>
                    <div class="field">
                        <div class="control">
                            <button type="button" class="button is-primary is-fullwidth"
                                @click="continueAfterRecovery()">
                                <span class="icon"><i data-lucide="check"></i></span>
                                <span>I've saved it — continue</span>
                            </button>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<?php ConfigIsland::render('client-auth-config', []); ?>
