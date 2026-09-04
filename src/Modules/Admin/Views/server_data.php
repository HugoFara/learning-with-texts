<?php

/**
 * Server Data View
 *
 * Modern Bulma + Alpine.js version of the server data page.
 *
 * Variables expected:
 * - $data: array Server data from ServerDataService::getServerData()
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

use Lwt\Shared\UI\Helpers\IconHelper;

/**
 * @var array{
 *     lwt_version?: string,
 *     server_soft?: string,
 *     apache?: string,
 *     server_location?: string,
 *     php?: string,
 *     db_name?: string,
 *     db_size?: float|int|string,
 *     mysql?: string,
 *     failed_migrations?: array<array{filename: string, attempts: int, error: string}>,
 *     missing_foreign_keys?: array<array{name: string, table: string}>,
 *     deprecated_mecab_languages?: array<array{id: int, name: string}>
 * } $data Server data from ServerDataService::getServerData()
 */
$data = is_array($data ?? null) ? $data : [];
$failedMigrations = $data['failed_migrations'] ?? [];
$missingForeignKeys = $data['missing_foreign_keys'] ?? [];
$deprecatedMecab = $data['deprecated_mecab_languages'] ?? [];

?>
<div class="container" x-data="serverDataApp()">
    <p class="mb-4"><?= __('admin.server_data_intro') ?></p>

    <!-- Server Section -->
    <div class="box mb-4">
        <h2 class="title is-4">
            <span class="icon-text">
                <span class="icon has-text-info">
                    <?php echo IconHelper::render('server', ['class' => 'icon']); ?>
                </span>
                <span><?= __('admin.server_data_section_server') ?></span>
            </span>
        </h2>
        <table class="table is-striped is-fullwidth">
            <tbody>
                <tr>
                    <th style="width: 200px;"><?= __('admin.server_data_lwt_version') ?></th>
                    <td><?php echo htmlspecialchars($data["lwt_version"] ?? '', ENT_QUOTES, 'UTF-8'); ?></td>
                </tr>
                <tr>
                    <th>
                        <a href="https://en.wikipedia.org/wiki/Web_server" target="_blank" rel="noopener">
                            <?= __('admin.server_data_web_server') ?>
                        </a>
                    </th>
                    <td><?php echo htmlspecialchars($data["server_soft"] ?? '', ENT_QUOTES, 'UTF-8'); ?></td>
                </tr>
                <tr>
                    <th><?= __('admin.server_data_server_software') ?></th>
                    <td>
                        <a href="https://en.wikipedia.org/wiki/Apache_HTTP_Server" target="_blank" rel="noopener">
                            <?php echo htmlspecialchars($data["apache"] ?? '', ENT_QUOTES, 'UTF-8'); ?>
                        </a>
                    </td>
                </tr>
                <tr>
                    <th><?= __('admin.server_data_server_location') ?></th>
                    <td><code><?php
                        echo htmlspecialchars($data["server_location"] ?? '', ENT_QUOTES, 'UTF-8');
                    ?></code></td>
                </tr>
                <tr>
                    <th>
                        <a href="https://en.wikipedia.org/wiki/PHP" target="_blank" rel="noopener">
                            <?= __('admin.server_data_php_version') ?>
                        </a>
                    </th>
                    <td><?php echo htmlspecialchars($data["php"] ?? '', ENT_QUOTES, 'UTF-8'); ?></td>
                </tr>
            </tbody>
        </table>
    </div>

    <!-- Database Section -->
    <div class="box mb-4">
        <h2 class="title is-4">
            <span class="icon-text">
                <span class="icon has-text-success">
                    <?php echo IconHelper::render('database', ['class' => 'icon']); ?>
                </span>
                <span><?= __('admin.server_data_section_database') ?></span>
            </span>
        </h2>
        <table class="table is-striped is-fullwidth">
            <tbody>
                <tr>
                    <th style="width: 200px;">
                        <a href="https://en.wikipedia.org/wiki/Database" target="_blank" rel="noopener">
                            <?= __('admin.server_data_db_name') ?>
                        </a>
                    </th>
                    <td><code><?php echo htmlspecialchars($data["db_name"] ?? '', ENT_QUOTES, 'UTF-8'); ?></code></td>
                </tr>
                <tr>
                    <th><?= __('admin.server_data_db_size') ?></th>
                    <td><?php echo htmlspecialchars((string)($data["db_size"] ?? ''), ENT_QUOTES, 'UTF-8'); ?> MB</td>
                </tr>
                <tr>
                    <th>
                        <a href="https://en.wikipedia.org/wiki/MySQL" target="_blank" rel="noopener">
                            <?= __('admin.server_data_mysql_version') ?>
                        </a>
                    </th>
                    <td><?php echo htmlspecialchars($data["mysql"] ?? '', ENT_QUOTES, 'UTF-8'); ?></td>
                </tr>
            </tbody>
        </table>
        <?php if ($failedMigrations !== []) { ?>
            <article class="message is-danger">
                <div class="message-header">
                    <p><?= __('admin.server_data_failed_migrations') ?></p>
                </div>
                <div class="message-body">
                    <p class="mb-3"><?= __('admin.server_data_failed_migrations_warning') ?></p>
                    <ul>
                        <?php foreach ($failedMigrations as $migration) {
                            $name = htmlspecialchars($migration['filename'], ENT_QUOTES, 'UTF-8');
                            $error = htmlspecialchars($migration['error'], ENT_QUOTES, 'UTF-8');
                            $attempts = $migration['attempts'];
                            ?>
                            <li>
                                <code><?php echo $name; ?></code>
                                (<?php echo $attempts; ?>
                                <?= __('admin.server_data_failed_migrations_attempts') ?>)
                                <br>
                                <small><?php echo $error; ?></small>
                            </li>
                        <?php } ?>
                    </ul>
                </div>
            </article>
        <?php } ?>
        <?php if ($deprecatedMecab !== []) { ?>
            <article class="message is-warning">
                <div class="message-header">
                    <p><?= __('admin.server_data_deprecated_mecab') ?></p>
                </div>
                <div class="message-body">
                    <p class="mb-3"><?= __('admin.server_data_deprecated_mecab_warning') ?></p>
                    <ul>
                        <?php foreach ($deprecatedMecab as $language) {
                            $name = htmlspecialchars($language['name'], ENT_QUOTES, 'UTF-8');
                            ?>
                            <li><?php echo $name; ?> (<code>LgID <?php echo $language['id']; ?></code>)</li>
                        <?php } ?>
                    </ul>
                </div>
            </article>
        <?php } ?>
        <?php if ($missingForeignKeys !== []) { ?>
            <article class="message is-warning">
                <div class="message-header">
                    <p><?= __('admin.server_data_missing_foreign_keys') ?></p>
                </div>
                <div class="message-body">
                    <p class="mb-3"><?= __('admin.server_data_missing_foreign_keys_warning') ?></p>
                    <ul>
                        <?php foreach ($missingForeignKeys as $key) {
                            $constraint = htmlspecialchars($key['name'], ENT_QUOTES, 'UTF-8');
                            $table = htmlspecialchars($key['table'], ENT_QUOTES, 'UTF-8');
                            ?>
                            <li><code><?php echo $constraint; ?></code> (<?php echo $table; ?>)</li>
                        <?php } ?>
                    </ul>
                </div>
            </article>
        <?php } ?>
    </div>

    <!-- Client API Section -->
    <div class="box mb-4">
        <h2 class="title is-4">
            <span class="icon-text">
                <span class="icon has-text-warning">
                    <?php echo IconHelper::render('cloud', ['class' => 'icon']); ?>
                </span>
                <span><?= __('admin.server_data_section_client_api') ?></span>
            </span>
        </h2>

        <!-- Loading State -->
        <div x-show="isLoading" class="has-text-centered py-4">
            <span class="icon is-medium">
                <span class="loader"></span>
            </span>
            <span class="ml-2"><?= __('admin.server_data_loading_api') ?></span>
        </div>

        <!-- Error State -->
        <div x-show="error && !isLoading" x-cloak class="notification is-danger is-light">
            <p><strong><?= __('admin.server_data_error_loading') ?></strong></p>
            <p x-text="error"></p>
        </div>

        <!-- Success State -->
        <div x-show="!isLoading && !error" x-cloak>
            <table class="table is-striped is-fullwidth">
                <tbody>
                    <tr>
                        <th style="width: 200px;">
                            <a href="https://en.wikipedia.org/wiki/REST" target="_blank" rel="noopener">
                                <?= __('admin.server_data_rest_version') ?>
                            </a>
                        </th>
                        <td x-text="apiVersion"></td>
                    </tr>
                    <tr>
                        <th>
                            <a href="https://en.wikipedia.org/wiki/REST" target="_blank" rel="noopener">
                                <?= __('admin.server_data_rest_release_date') ?>
                            </a>
                        </th>
                        <td x-text="apiReleaseDate"></td>
                    </tr>
                </tbody>
            </table>
        </div>
    </div>

    <!-- Back Button -->
    <div class="field">
        <div class="control">
            <a href="/" class="button is-light">
                <?php echo IconHelper::render('arrow-left'); ?>
                <span><?= __('admin.server_data_back_to_main') ?></span>
            </a>
        </div>
    </div>
</div>

<style>
/* Simple CSS loader animation */
.loader {
    display: inline-block;
    width: 1.5em;
    height: 1.5em;
    border: 3px solid #dbdbdb;
    border-radius: 50%;
    border-top-color: #3273dc;
    animation: spin 1s linear infinite;
}

@keyframes spin {
    to { transform: rotate(360deg); }
}
</style>
