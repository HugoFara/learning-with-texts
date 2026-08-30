<?php

/**
 * Admin Dashboard View
 *
 * Landing page for /admin with links to all admin subpages.
 *
 * PHP version 8.2
 *
 * @category Lwt
 * @package  Lwt\Modules\Admin\Views
 * @author   HugoFara <git@hugofara.net>
 * @license  Unlicense <http://unlicense.org/>
 * @link     https://hugofara.github.io/lwt/developer/api
 * @since    3.0.0
 */

declare(strict_types=1);

namespace Lwt\Views\Admin;

?>

<section class="section">
    <div class="container">
        <div class="columns is-centered">
            <div class="column is-10-tablet is-8-desktop">

                <div class="columns is-multiline">

                    <!-- Settings -->
                    <div class="column is-half">
                        <div class="box">
                            <h2 class="title is-5">
                                <span class="icon-text">
                                    <span class="icon"><i data-lucide="settings"></i></span>
                                    <span><?= __('admin.dashboard_settings_title') ?></span>
                                </span>
                            </h2>
                            <p class="mb-3"><?= __('admin.dashboard_settings_description') ?></p>
                            <a href="/admin/settings" class="button is-primary is-outlined">
                                <span class="icon"><i data-lucide="arrow-right"></i></span>
                                <span><?= __('admin.dashboard_open_settings') ?></span>
                            </a>
                        </div>
                    </div>

                    <!-- Backup & Restore -->
                    <div class="column is-half">
                        <div class="box">
                            <h2 class="title is-5">
                                <span class="icon-text">
                                    <span class="icon"><i data-lucide="database"></i></span>
                                    <span><?= __('admin.dashboard_backup_title') ?></span>
                                </span>
                            </h2>
                            <p class="mb-3"><?= __('admin.dashboard_backup_description') ?></p>
                            <a href="/admin/backup" class="button is-primary is-outlined">
                                <span class="icon"><i data-lucide="arrow-right"></i></span>
                                <span><?= __('admin.dashboard_open_backup') ?></span>
                            </a>
                        </div>
                    </div>

                    <!-- Database Wizard -->
                    <div class="column is-half">
                        <div class="box">
                            <h2 class="title is-5">
                                <span class="icon-text">
                                    <span class="icon"><i data-lucide="wand-2"></i></span>
                                    <span><?= __('admin.dashboard_wizard_title') ?></span>
                                </span>
                            </h2>
                            <p class="mb-3"><?= __('admin.dashboard_wizard_description') ?></p>
                            <a href="/admin/wizard" class="button is-primary is-outlined">
                                <span class="icon"><i data-lucide="arrow-right"></i></span>
                                <span><?= __('admin.dashboard_open_wizard') ?></span>
                            </a>
                        </div>
                    </div>

                    <!-- Install Demo -->
                    <div class="column is-half">
                        <div class="box">
                            <h2 class="title is-5">
                                <span class="icon-text">
                                    <span class="icon"><i data-lucide="download"></i></span>
                                    <span><?= __('admin.dashboard_install_demo_title') ?></span>
                                </span>
                            </h2>
                            <p class="mb-3"><?= __('admin.dashboard_install_demo_description') ?></p>
                            <a href="/admin/install-demo" class="button is-primary is-outlined">
                                <span class="icon"><i data-lucide="arrow-right"></i></span>
                                <span><?= __('admin.dashboard_install_demo_button') ?></span>
                            </a>
                        </div>
                    </div>

                    <!-- Server Data -->
                    <div class="column is-half">
                        <div class="box">
                            <h2 class="title is-5">
                                <span class="icon-text">
                                    <span class="icon"><i data-lucide="server"></i></span>
                                    <span><?= __('admin.dashboard_server_data_title') ?></span>
                                </span>
                            </h2>
                            <p class="mb-3"><?= __('admin.dashboard_server_data_description') ?></p>
                            <a href="/admin/server-data" class="button is-primary is-outlined">
                                <span class="icon"><i data-lucide="arrow-right"></i></span>
                                <span><?= __('admin.dashboard_view_server_data') ?></span>
                            </a>
                        </div>
                    </div>

                </div>

            </div>
        </div>
    </div>
</section>
