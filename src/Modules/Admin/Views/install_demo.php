<?php

/**
 * Install Demo View
 *
 * Modern Bulma + Alpine.js version of the demo installation page.
 *
 * Variables expected:
 * - $langcnt: int Count of existing languages
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

$langcnt = (int)($langcnt ?? 0);
$base = UrlUtilities::getBasePath();

?>
<div class="container" x-data="{ confirmed: false, installing: false }">
    <div class="box">
        <h2 class="title is-4">
            <span class="icon-text">
                <span class="icon has-text-info">
                    <?php echo IconHelper::render('database', ['class' => 'icon']); ?>
                </span>
                <span><?= __('admin.install_demo_title') ?></span>
            </span>
        </h2>

        <div class="content">
            <p><?= __('admin.install_demo_intro') ?></p>
        </div>

        <!-- Warning notification -->
        <div class="notification is-warning is-light">
            <div class="columns is-vcentered">
                <div class="column is-narrow">
                    <span class="icon is-large has-text-warning">
                        <?php echo IconHelper::render('triangle-alert', ['width' => 32, 'height' => 32]); ?>
                    </span>
                </div>
                <div class="column">
                    <p class="has-text-weight-semibold"><?= __('admin.install_demo_warning_heading') ?></p>
                    <p class="is-size-7">
                        <?= __('admin.install_demo_warning_db', [
                            'db' => htmlspecialchars(Globals::getDatabaseName(), ENT_QUOTES, 'UTF-8'),
                        ]) ?>
                        <?php if ($langcnt > 0) : ?>
                        <br><?= __('admin.install_demo_warning_existing', ['count' => $langcnt]) ?>
                        <?php endif; ?>
                    </p>
                </div>
            </div>
        </div>

        <!-- Confirmation checkbox -->
        <div class="field" x-show="!installing">
            <label class="checkbox">
                <input type="checkbox" x-model="confirmed">
                <span class="has-text-weight-medium">
                    <?= __('admin.install_demo_understand') ?>
                </span>
            </label>
        </div>

        <!-- Install form -->
        <form action="<?php echo $base; ?>/admin/install-demo" method="post"
              @submit="installing = true"
              x-show="!installing">
            <?php echo \Lwt\Shared\UI\Helpers\FormHelper::csrfField(); ?>
            <div class="field is-grouped mt-5">
                <div class="control">
                    <button type="submit"
                            name="install"
                            class="button is-danger"
                            :disabled="!confirmed"
                            :class="{ 'is-loading': installing }">
                        <?php echo IconHelper::render('download'); ?>
                        <span><?= __('admin.install_demo_button') ?></span>
                    </button>
                </div>
                <div class="control">
                    <a href="<?php echo $base; ?>/" class="button is-light">
                        <?php echo IconHelper::render('arrow-left'); ?>
                        <span><?= __('admin.install_demo_back') ?></span>
                    </a>
                </div>
            </div>
        </form>

        <!-- Installing state -->
        <div x-show="installing" x-cloak class="has-text-centered py-5">
            <p class="is-size-5 mb-4">
                <span class="icon is-medium has-text-info">
                    <span class="loader"></span>
                </span>
                <span class="ml-2"><?= __('admin.install_demo_installing') ?></span>
            </p>
            <p class="has-text-grey is-size-7"><?= __('admin.install_demo_installing_help') ?></p>
        </div>
    </div>

    <!-- What's included info -->
    <div class="box" x-show="!installing">
        <h3 class="title is-5">
            <span class="icon-text">
                <span class="icon has-text-success">
                    <?php echo IconHelper::render('package', ['class' => 'icon']); ?>
                </span>
                <span><?= __('admin.install_demo_included_title') ?></span>
            </span>
        </h3>
        <div class="content">
            <div class="columns">
                <div class="column">
                    <ul>
                        <li><?= __('admin.install_demo_included_texts') ?></li>
                        <li><?= __('admin.install_demo_included_vocab') ?></li>
                        <li><?= __('admin.install_demo_included_languages') ?></li>
                    </ul>
                </div>
                <div class="column">
                    <ul>
                        <li><?= __('admin.install_demo_included_tags') ?></li>
                        <li><?= __('admin.install_demo_included_settings') ?></li>
                        <li><?= __('admin.install_demo_included_audio') ?></li>
                    </ul>
                </div>
            </div>
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
