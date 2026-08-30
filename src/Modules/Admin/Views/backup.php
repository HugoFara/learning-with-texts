<?php

/**
 * Database Operations View
 *
 * Modern Bulma + Alpine.js version of the backup/restore page.
 *
 * Variables expected:
 * - $message: string Message to display (if any)
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

use Lwt\Shared\Infrastructure\Globals;
use Lwt\Shared\Infrastructure\Http\UrlUtilities;
use Lwt\Shared\UI\Helpers\IconHelper;

$base = UrlUtilities::getBasePath();

$escapedDbName = htmlspecialchars(Globals::getDatabaseName(), ENT_QUOTES, 'UTF-8');
$restoreEnabled = Globals::isBackupRestoreEnabled();
$iniFile = php_ini_loaded_file();
$escapedIniFile = htmlspecialchars($iniFile === false ? '' : $iniFile, ENT_QUOTES, 'UTF-8');
$postMaxSize = ini_get('post_max_size');
$uploadMaxFilesize = ini_get('upload_max_filesize');
?>
<div class="container" x-data="backupManager()">
    <!-- Backup Section -->
    <section class="box mb-4" x-data="{ open: false }">
        <header class="collapsible-header" @click="open = !open">
            <h2 class="title is-4 mb-0">
                <span class="icon-text">
                    <span class="icon has-text-info">
                        <?php echo IconHelper::render('download', ['class' => 'icon']); ?>
                    </span>
                    <span><?php echo __e('admin.backup_section_backup'); ?></span>
                </span>
            </h2>
            <span class="icon collapse-icon" :class="{ 'is-rotated': open }">
                <?php echo IconHelper::render('chevron-down'); ?>
            </span>
        </header>

        <div class="collapsible-content" x-show="open" x-collapse>
            <div class="content mt-4">
                <p>
                    <?php echo __('admin.backup_intro_db', ['db' => $escapedDbName]); ?>
                </p>
            </div>

            <div class="notification is-info is-light">
                <div class="columns is-vcentered">
                    <div class="column is-narrow">
                        <span class="icon is-medium has-text-info">
                            <?php echo IconHelper::render('circle-help', ['width' => 24, 'height' => 24]); ?>
                        </span>
                    </div>
                    <div class="column">
                        <p class="has-text-weight-semibold mb-1">
                            <?php echo __e('admin.backup_keep_safe_heading'); ?>
                        </p>
                        <ul class="is-size-7 mt-0">
                            <li><?php echo __e('admin.backup_keep_safe_li1'); ?></li>
                            <li><?php echo __('admin.backup_keep_safe_li2'); ?></li>
                            <li><?php echo __e('admin.backup_keep_safe_li3'); ?></li>
                        </ul>
                    </div>
                </div>
            </div>

            <form action="<?php echo $base; ?>/admin/backup" method="post" class="mt-4">
                <?php echo \Lwt\Shared\UI\Helpers\FormHelper::csrfField(); ?>
                <div class="field is-grouped is-grouped-right">
                    <div class="control">
                        <button type="submit" name="orig_backup" class="button is-info is-outlined">
                            <?php echo IconHelper::render('download'); ?>
                            <span><?php echo __e('admin.backup_download_official'); ?></span>
                        </button>
                    </div>
                    <div class="control">
                        <button type="submit" name="backup" class="button is-info">
                            <?php echo IconHelper::render('download'); ?>
                            <span><?php echo __e('admin.backup_download_full'); ?></span>
                        </button>
                    </div>
                </div>
            </form>
        </div>
    </section>

    <!-- Restore Section -->
    <section class="box mb-4" x-data="{ open: false }">
        <header class="collapsible-header" @click="open = !open">
            <h2 class="title is-4 mb-0">
                <span class="icon-text">
                    <span class="icon has-text-warning">
                        <?php echo IconHelper::render('upload', ['class' => 'icon']); ?>
                    </span>
                    <span><?php echo __e('admin.backup_section_restore'); ?></span>
                </span>
            </h2>
            <span class="icon collapse-icon" :class="{ 'is-rotated': open }">
                <?php echo IconHelper::render('chevron-down'); ?>
            </span>
        </header>

        <div class="collapsible-content" x-show="open" x-collapse>
            <?php if (!$restoreEnabled) : ?>
            <div class="notification is-danger is-light mt-4">
                <div class="columns is-vcentered">
                    <div class="column is-narrow">
                        <span class="icon is-medium has-text-danger">
                            <?php echo IconHelper::render('shield-x', ['width' => 24, 'height' => 24]); ?>
                        </span>
                    </div>
                    <div class="column">
                        <p class="has-text-weight-semibold mb-1">
                            <?php echo __e('admin.restore_disabled_heading'); ?>
                        </p>
                        <p class="is-size-7">
                            <?php echo __('admin.restore_disabled_body'); ?>
                        </p>
                    </div>
                </div>
            </div>
            <?php else : ?>
            <div class="content mt-4">
                <p>
                    <?php echo __('admin.restore_intro_db', ['db' => $escapedDbName]); ?>
                </p>
            </div>

            <div class="notification is-warning is-light">
                <div class="columns is-vcentered">
                    <div class="column is-narrow">
                        <span class="icon is-medium has-text-warning">
                            <?php echo IconHelper::render('triangle-alert', ['width' => 24, 'height' => 24]); ?>
                        </span>
                    </div>
                    <div class="column">
                        <p class="has-text-weight-semibold mb-1">
                            <?php echo __e('admin.restore_upload_limits_heading'); ?>
                        </p>
                        <p class="is-size-7">
                            Large backup files may fail to restore due to PHP limits:<br>
                            <code>post_max_size = <?php echo $postMaxSize; ?></code> /
                            <code>upload_max_filesize = <?php echo $uploadMaxFilesize; ?></code><br>
                            If needed, increase these values in
                            <code><?php echo $escapedIniFile; ?></code> and restart your server.
                        </p>
                    </div>
                </div>
            </div>

            <div class="notification is-info is-light">
                <div class="columns is-vcentered">
                    <div class="column is-narrow">
                        <span class="icon is-medium has-text-info">
                            <?php echo IconHelper::render('shield-check', ['width' => 24, 'height' => 24]); ?>
                        </span>
                    </div>
                    <div class="column">
                        <p class="has-text-weight-semibold mb-1">
                            <?php echo __e('admin.restore_security_heading'); ?>
                        </p>
                        <p class="is-size-7">
                            <?php echo __e('admin.restore_security_body'); ?>
                        </p>
                    </div>
                </div>
            </div>

            <form action="<?php echo $base; ?>/admin/backup" method="post" enctype="multipart/form-data"
                  @submit="restoring = true"
                  x-show="!restoring"
                  data-confirm-submit="<?php echo __e('admin.restore_confirm'); ?>">
                <?php echo \Lwt\Shared\UI\Helpers\FormHelper::csrfField(); ?>
                <input type="hidden" name="restore" value="1">
                <div class="field">
                    <label class="label"><?php echo __e('admin.restore_file_label'); ?></label>
                    <div class="file has-name is-fullwidth">
                        <label class="file-label">
                            <input class="file-input" type="file" name="thefile"
                                   @change="selectFile($event)"
                                   accept=".sql,.gz,.sql.gz">
                            <span class="file-cta">
                                <span class="file-icon">
                                    <?php echo IconHelper::render('upload'); ?>
                                </span>
                                <span class="file-label"><?php echo __e('admin.restore_choose_file'); ?></span>
                            </span>
                            <span class="file-name"
                                  x-text="fileName || '<?php echo __e('admin.restore_no_file'); ?>'"></span>
                        </label>
                    </div>
                </div>

                <div class="field is-grouped is-grouped-right mt-4">
                    <div class="control">
                        <button type="submit" class="button is-warning"
                                :disabled="!fileName">
                            <span class="icon">
                                <?php echo IconHelper::render('triangle-alert'); ?>
                            </span>
                            <span><?php echo __e('admin.restore_button'); ?></span>
                        </button>
                    </div>
                </div>
            </form>

            <!-- Restoring state -->
            <div x-show="restoring" x-cloak class="has-text-centered py-5">
                <p class="is-size-5 mb-4">
                    <span class="icon is-medium has-text-info">
                        <span class="loader"></span>
                    </span>
                    <span class="ml-2"><?php echo __e('admin.restore_loading'); ?></span>
                </p>
                <p class="has-text-grey is-size-7"><?php echo __e('admin.restore_loading_help'); ?></p>
            </div>
            <?php endif; ?>
        </div>
    </section>

    <!-- Demo Database Section -->
    <section class="box mb-4" x-data="{ open: false }">
        <header class="collapsible-header" @click="open = !open">
            <h2 class="title is-4 mb-0">
                <span class="icon-text">
                    <span class="icon has-text-success">
                        <?php echo IconHelper::render('package', ['class' => 'icon']); ?>
                    </span>
                    <span><?php echo __e('admin.backup_section_install_demo'); ?></span>
                </span>
            </h2>
            <span class="icon collapse-icon" :class="{ 'is-rotated': open }">
                <?php echo IconHelper::render('chevron-down'); ?>
            </span>
        </header>

        <div class="collapsible-content" x-show="open" x-collapse>
            <div class="content mt-4">
                <p>
                    <?php echo __('admin.install_demo_warning_db', ['db' => $escapedDbName]); ?>
                </p>
                <p class="is-size-7 has-text-grey">
                    <?php echo __e('admin.install_demo_intro'); ?>
                </p>
            </div>

            <div class="field is-grouped is-grouped-right">
                <div class="control">
                    <a href="<?php echo $base; ?>/admin/install-demo" class="button is-success is-outlined">
                        <?php echo IconHelper::render('download'); ?>
                        <span><?php echo __e('admin.install_demo_button'); ?></span>
                    </a>
                </div>
            </div>
        </div>
    </section>

    <!-- Empty Database Section -->
    <section class="box mb-4" x-data="{ open: false }">
        <header class="collapsible-header" @click="open = !open">
            <h2 class="title is-4 mb-0">
                <span class="icon-text">
                    <span class="icon has-text-danger">
                        <?php echo IconHelper::render('trash-2', ['class' => 'icon']); ?>
                    </span>
                    <span><?php echo __e('admin.backup_section_empty'); ?></span>
                </span>
            </h2>
            <span class="icon collapse-icon" :class="{ 'is-rotated': open }">
                <?php echo IconHelper::render('chevron-down'); ?>
            </span>
        </header>

        <div class="collapsible-content" x-show="open" x-collapse>
            <div class="content mt-4">
                <p>
                    <?php echo __('admin.empty_db_intro', ['db' => $escapedDbName]); ?>
                </p>
            </div>

            <div class="notification is-danger is-light">
                <div class="columns is-vcentered">
                    <div class="column is-narrow">
                        <span class="icon is-medium has-text-danger">
                            <?php echo IconHelper::render('triangle-alert', ['width' => 24, 'height' => 24]); ?>
                        </span>
                    </div>
                    <div class="column">
                        <p class="has-text-weight-semibold">
                            <?php echo __e('admin.empty_db_warning_heading'); ?>
                        </p>
                        <p class="is-size-7">
                            <?php echo __e('admin.empty_db_warning_body'); ?>
                        </p>
                    </div>
                </div>
            </div>

            <form action="<?php echo $base; ?>/admin/backup" method="post"
                  @submit="emptying = true"
                  x-show="!emptying"
                  data-confirm-submit="<?php echo __e('admin.empty_db_confirm'); ?>">
                <?php echo \Lwt\Shared\UI\Helpers\FormHelper::csrfField(); ?>
                <input type="hidden" name="empty" value="1">
                <div class="field" x-show="!emptying">
                    <label class="checkbox">
                        <input type="checkbox" x-model="confirmEmpty">
                        <span class="has-text-weight-medium">
                            <?php echo __e('admin.empty_db_understand'); ?>
                        </span>
                    </label>
                </div>

                <div class="field is-grouped is-grouped-right mt-4">
                    <div class="control">
                        <button type="submit" class="button is-danger"
                                :disabled="!confirmEmpty">
                            <span class="icon">
                                <?php echo IconHelper::render('trash-2'); ?>
                            </span>
                            <span><?php echo __e('admin.empty_db_button'); ?></span>
                        </button>
                    </div>
                </div>
            </form>

            <!-- Emptying state -->
            <div x-show="emptying" x-cloak class="has-text-centered py-5">
                <p class="is-size-5 mb-4">
                    <span class="icon is-medium has-text-danger">
                        <span class="loader"></span>
                    </span>
                    <span class="ml-2"><?php echo __e('admin.empty_db_loading'); ?></span>
                </p>
            </div>
        </div>
    </section>

    <!-- Back Button -->
    <div class="field">
        <div class="control">
            <a href="<?php echo $base; ?>/" class="button is-light">
                <?php echo IconHelper::render('arrow-left'); ?>
                <span><?php echo __e('admin.backup_back_to_main'); ?></span>
            </a>
        </div>
    </div>
</div>

<style>
/* Collapsible section styles */
.collapsible-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    cursor: pointer;
    user-select: none;
}

.collapsible-header:hover {
    opacity: 0.8;
}

.collapse-icon {
    transition: transform 0.2s ease;
}

.collapse-icon.is-rotated {
    transform: rotate(180deg);
}

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

/* Alpine.js cloak */
[x-cloak] {
    display: none !important;
}
</style>
<!-- backupManager() is now registered via src/frontend/js/admin/backup_manager.ts -->
