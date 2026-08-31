<?php

/**
 * Database Wizard View (Standalone)
 *
 * This view is standalone and can run without database connection.
 * It includes its own HTML structure (not using standard LWT layout).
 *
 * Variables expected:
 * - $conn: DatabaseConnection object with current values
 * - $errorMessage: string|null Error message to display
 *
 * PHP version 8.2
 *
 * @category Lwt
 * @package  Lwt\Views
 * @author   HugoFara <git@hugofara.net>
 * @license  Unlicense <http://unlicense.org/>
 * @link     https://hugofara.github.io/lwt/developer/api
 * @since    3.0.0
 */

declare(strict_types=1);

namespace Lwt\Views\Admin;

/**
 * @psalm-suppress MixedAssignment
 * @psalm-suppress MixedPropertyFetch
 */
$errorMessageRaw = $errorMessage ?? null;
$errorMessage = is_string($errorMessageRaw) ? $errorMessageRaw : null;

/**
 * Database connection configuration object
 * @psalm-suppress MixedAssignment
 * @psalm-suppress MixedPropertyFetch
 */
$connRaw = $conn ?? null;
$connObj = is_object($connRaw)
    ? $connRaw
    : (object)['server' => '', 'userid' => '', 'passwd' => '', 'dbname' => '', 'socket' => ''];
$connServer = $connObj->server ?? '';
$connUserid = $connObj->userid ?? '';
$connPasswd = $connObj->passwd ?? '';
$connDbname = $connObj->dbname ?? '';
$connSocket = $connObj->socket ?? '';

?><!DOCTYPE html>
<html lang="en">
<head>
    <meta http-equiv="content-type" content="text/html; charset=utf-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1" />
    <title>LWT - Database Connection Wizard</title>
    <link rel="shortcut icon" href="/favicon.ico" type="image/x-icon"/>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bulma@0.9.4/css/bulma.min.css" />
    <script defer src="https://cdn.jsdelivr.net/npm/alpinejs@3.x.x/dist/cdn.min.js"></script>
    <style>
        .wizard-container {
            max-width: 600px;
            margin: 2rem auto;
            padding: 1rem;
        }
    </style>
</head>
<body>
<section class="section">
    <div class="wizard-container">
        <div class="box">
            <h1 class="title is-3 has-text-centered">Database Connection Wizard</h1>

            <?php if ($errorMessage !== null) : ?>
            <div class="notification <?php
                echo str_contains($errorMessage, 'Success') ? 'is-success' : 'is-danger';
            ?> is-light"
                 x-data="{ show: true }"
                 x-show="show"
                 x-transition>
                <button class="delete" @click="show = false"></button>
                <?php echo htmlspecialchars($errorMessage); ?>
            </div>
            <?php endif; ?>

            <form name="database_connect" action="" method="post" x-data="{ showPassword: false }">
                <?php echo \Lwt\Shared\UI\Helpers\FormHelper::csrfField(); ?>
                <!-- Server Address -->
                <div class="field">
                    <label class="label" for="server">Server Address</label>
                    <div class="control has-icons-left">
                        <input type="text"
                               class="input"
                               name="server"
                               id="server"
                               value="<?php echo htmlspecialchars($connServer); ?>"
                               placeholder="localhost" />
                        <span class="icon is-left">
                            <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16"
                                 viewBox="0 0 24 24" fill="none" stroke="currentColor"
                                 stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                <rect width="20" height="8" x="2" y="2" rx="2" ry="2"/>
                                <rect width="20" height="8" x="2" y="14" rx="2" ry="2"/>
                                <line x1="6" x2="6.01" y1="6" y2="6"/>
                                <line x1="6" x2="6.01" y1="18" y2="18"/>
                            </svg>
                        </span>
                    </div>
                    <p class="help">Usually "localhost" for local installations</p>
                </div>

                <!-- Database User Name -->
                <div class="field">
                    <label class="label" for="userid">Database User Name</label>
                    <div class="control has-icons-left">
                        <input type="text"
                               class="input"
                               name="userid"
                               id="userid"
                               value="<?php echo htmlspecialchars($connUserid); ?>"
                               placeholder="root" />
                        <span class="icon is-left">
                            <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16"
                                 viewBox="0 0 24 24" fill="none" stroke="currentColor"
                                 stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                <path d="M19 21v-2a4 4 0 0 0-4-4H9a4 4 0 0 0-4 4v2"/>
                                <circle cx="12" cy="7" r="4"/>
                            </svg>
                        </span>
                    </div>
                </div>

                <!-- Password -->
                <div class="field">
                    <label class="label" for="passwd">Password</label>
                    <div class="field has-addons">
                        <div class="control is-expanded has-icons-left">
                            <input :type="showPassword ? 'text' : 'password'"
                                   class="input"
                                   name="passwd"
                                   id="passwd"
                                   value="<?php echo htmlspecialchars($connPasswd); ?>"
                                   placeholder="Enter password" />
                            <span class="icon is-left">
                                <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16"
                                     viewBox="0 0 24 24" fill="none" stroke="currentColor"
                                     stroke-width="2" stroke-linecap="round"
                                     stroke-linejoin="round">
                                    <rect width="18" height="11" x="3" y="11" rx="2" ry="2"/>
                                    <path d="M7 11V7a5 5 0 0 1 10 0v4"/>
                                </svg>
                            </span>
                        </div>
                        <div class="control">
                            <button type="button"
                                    class="button"
                                    @click="showPassword = !showPassword"
                                    :title="showPassword ? 'Hide password' : 'Show password'">
                                <span class="icon">
                                    <template x-if="!showPassword">
                                        <svg xmlns="http://www.w3.org/2000/svg" width="16"
                                             height="16" viewBox="0 0 24 24" fill="none"
                                             stroke="currentColor" stroke-width="2"
                                             stroke-linecap="round" stroke-linejoin="round">
                                            <path d="M2 12s3-7 10-7 10 7 10 7-3 7-10 7-10-7-10-7Z"/>
                                            <circle cx="12" cy="12" r="3"/>
                                        </svg>
                                    </template>
                                    <template x-if="showPassword">
                                        <svg xmlns="http://www.w3.org/2000/svg" width="16"
                                             height="16" viewBox="0 0 24 24" fill="none"
                                             stroke="currentColor" stroke-width="2"
                                             stroke-linecap="round" stroke-linejoin="round">
                                            <path d="M9.88 9.88a3 3 0 1 0 4.24 4.24"/>
                                            <path d="M10.73 5.08A10.43 10.43 0 0 1 12 5c7 0
                                                10 7 10 7a13.16 13.16 0 0 1-1.67 2.68"/>
                                            <path d="M6.61 6.61A13.526 13.526 0 0 0 2 12s3 7
                                                10 7a9.74 9.74 0 0 0 5.39-1.61"/>
                                            <line x1="2" x2="22" y1="2" y2="22"/>
                                        </svg>
                                    </template>
                                </span>
                            </button>
                        </div>
                    </div>
                </div>

                <!-- Database Name -->
                <div class="field">
                    <label class="label" for="dbname">Database Name</label>
                    <div class="control has-icons-left">
                        <input type="text"
                               class="input"
                               name="dbname"
                               id="dbname"
                               value="<?php echo htmlspecialchars($connDbname); ?>"
                               placeholder="learning-with-texts" />
                        <span class="icon is-left">
                            <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16"
                                 viewBox="0 0 24 24" fill="none" stroke="currentColor"
                                 stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                <ellipse cx="12" cy="5" rx="9" ry="3"/>
                                <path d="M3 5V19A9 3 0 0 0 21 19V5"/>
                                <path d="M3 12A9 3 0 0 0 21 12"/>
                            </svg>
                        </span>
                    </div>
                    <p class="help">The database will be created if it doesn't exist</p>
                </div>

                <!-- Socket Name (Optional) -->
                <div class="field">
                    <label class="label" for="socket">
                        Socket Name
                        <span class="tag is-light is-small ml-2">Optional</span>
                    </label>
                    <div class="control has-icons-left">
                        <input type="text"
                               class="input"
                               name="socket"
                               id="socket"
                               value="<?php echo htmlspecialchars($connSocket); ?>"
                               placeholder="/var/run/mysql.sock" />
                        <span class="icon is-left">
                            <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16"
                                 viewBox="0 0 24 24" fill="none" stroke="currentColor"
                                 stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                <path d="M12 22c5.523 0 10-4.477 10-10S17.523 2 12 2
                                    2 6.477 2 12s4.477 10 10 10z"/>
                                <path d="m9 12 2 2 4-4"/>
                            </svg>
                        </span>
                    </div>
                    <p class="help">Only needed for non-standard MySQL socket connections</p>
                </div>

                <!-- Form Actions -->
                <div class="field is-grouped is-grouped-centered mt-5">
                    <div class="control">
                        <button type="submit" name="op" value="Autocomplete" class="button is-info is-outlined">
                            <span class="icon is-small">
                                <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16"
                                     viewBox="0 0 24 24" fill="none" stroke="currentColor"
                                     stroke-width="2" stroke-linecap="round"
                                     stroke-linejoin="round">
                                    <path d="m15 15-6 6"/>
                                    <path d="M21 11a8 8 0 0 0-14.5-4.6"/>
                                    <path d="M3 13a8 8 0 0 0 14.5 4.6"/>
                                </svg>
                            </span>
                            <span>Autocomplete</span>
                        </button>
                    </div>
                    <div class="control">
                        <button type="submit" name="op" value="Check" class="button is-warning">
                            <span class="icon is-small">
                                <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16"
                                     viewBox="0 0 24 24" fill="none" stroke="currentColor"
                                     stroke-width="2" stroke-linecap="round"
                                     stroke-linejoin="round">
                                    <path d="M12 22c5.523 0 10-4.477 10-10S17.523 2 12 2
                                        2 6.477 2 12s4.477 10 10 10z"/>
                                    <path d="M9.09 9a3 3 0 0 1 5.83 1c0 2-3 3-3 3"/>
                                    <path d="M12 17h.01"/>
                                </svg>
                            </span>
                            <span>Check</span>
                        </button>
                    </div>
                    <div class="control">
                        <button type="submit" name="op" value="Change" class="button is-primary">
                            <span class="icon is-small">
                                <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16"
                                     viewBox="0 0 24 24" fill="none" stroke="currentColor"
                                     stroke-width="2" stroke-linecap="round"
                                     stroke-linejoin="round">
                                    <path d="M19 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11l5 5v11a2
                                        2 0 0 1-2 2z"/>
                                    <polyline points="17 21 17 13 7 13 7 21"/>
                                    <polyline points="7 3 7 8 15 8"/>
                                </svg>
                            </span>
                            <span>Save Changes</span>
                        </button>
                    </div>
                </div>
            </form>
        </div>

        <div class="content has-text-centered mt-4">
            <p class="has-text-grey">
                <small>LWT - Learning With Texts</small>
            </p>
        </div>
    </div>
</section>
</body>
</html>
