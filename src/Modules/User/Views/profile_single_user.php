<?php

/**
 * Single-User Profile View
 *
 * Shown when multi-user mode is disabled. Provides navigation to
 * preferences and admin settings.
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

$t = static fn(string $key): string => htmlspecialchars(__('user.' . $key), ENT_QUOTES, 'UTF-8');
?>

<section class="section">
    <div class="container">
        <div class="columns is-centered">
            <div class="column is-10-tablet is-8-desktop">

                <!-- Single-user info -->
                <div class="box">
                    <h2 class="title is-4">
                        <span class="icon-text">
                            <span class="icon"><i data-lucide="user"></i></span>
                            <span><?php echo $t('profile.section_title'); ?></span>
                        </span>
                    </h2>
                    <div class="notification is-info is-light">
                        <?php echo __('user.profile_single.single_user_mode_html'); ?>
                    </div>
                </div>

                <!-- Preferences Link -->
                <div class="box">
                    <h2 class="title is-4">
                        <span class="icon-text">
                            <span class="icon"><i data-lucide="sliders-horizontal"></i></span>
                            <span><?php echo $t('profile.preferences_title'); ?></span>
                        </span>
                    </h2>
                    <p class="mb-3"><?php echo $t('profile_single.preferences_description'); ?></p>
                    <a href="/profile/preferences" class="button is-primary is-outlined">
                        <span class="icon"><i data-lucide="settings"></i></span>
                        <span><?php echo $t('profile.edit_preferences_button'); ?></span>
                    </a>
                </div>

                <!-- Admin Settings Link -->
                <div class="box">
                    <h2 class="title is-4">
                        <span class="icon-text">
                            <span class="icon"><i data-lucide="shield"></i></span>
                            <span><?php echo $t('profile_single.admin_settings_title'); ?></span>
                        </span>
                    </h2>
                    <p class="mb-3"><?php echo $t('profile_single.admin_settings_description'); ?></p>
                    <a href="/admin/settings" class="button is-primary is-outlined">
                        <span class="icon"><i data-lucide="settings"></i></span>
                        <span><?php echo $t('profile_single.admin_settings_button'); ?></span>
                    </a>
                </div>

            </div>
        </div>
    </div>
</section>
