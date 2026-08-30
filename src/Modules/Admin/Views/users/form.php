<?php

/**
 * Admin User Form - scaffold for the client-rendered create/edit form.
 *
 * Values come from `GET /api/v1/admin/users/{id}` when editing; saving goes
 * through `POST /api/v1/admin/users` or `PUT /api/v1/admin/users/{id}`.
 * See user_management.ts.
 *
 * Variables expected:
 * - $isEdit: bool
 * - $user: User|null (only its ID is read; the values come from the API)
 *
 * PHP version 8.2
 *
 * @category Lwt
 * @package  Lwt\Views\Admin
 * @author   HugoFara <git@hugofara.net>
 * @license  Unlicense <http://unlicense.org/>
 * @link     https://hugofara.github.io/lwt/developer/api
 * @since    3.0.0
 */

declare(strict_types=1);

namespace Lwt\Views\Admin;

use Lwt\Shared\UI\Helpers\IconHelper;
use Lwt\Shared\UI\Helpers\ConfigIsland;

/** @var bool $isEdit */
$isEdit = $isEdit ?? false;
/** @var \Lwt\Modules\User\Domain\User|null $user */
$user = $user ?? null;

$userId = $isEdit && $user !== null ? $user->id()->toInt() : null;
$usernamePlaceholder = htmlspecialchars(
    __('admin.user_form_username_placeholder'),
    ENT_QUOTES,
    'UTF-8'
);
$pwPlaceholder = htmlspecialchars(
    $isEdit
        ? __('admin.user_form_password_placeholder_edit')
        : __('admin.user_form_password_placeholder_new'),
    ENT_QUOTES,
    'UTF-8'
);
?>
<?php ConfigIsland::render('user-form-config', [
    'isEdit' => $isEdit,
    'userId' => $userId,
]); ?>

<div class="container" x-data="userForm">
    <div class="box">
        <h2 class="title is-4">
            <?php echo $isEdit
                ? __e('admin.user_form_edit_title')
                : __e('admin.user_form_create_title'); ?>
        </h2>

        <div x-show="errors.length > 0" x-cloak class="notification is-danger">
            <template x-for="message in errors" :key="message">
                <p x-text="message"></p>
            </template>
        </div>

        <div x-show="isLoading" x-cloak class="has-text-centered py-4">
            <?php echo __e('admin.users_loading'); ?>
        </div>

        <form @submit.prevent="save()">
            <!-- Username -->
            <div class="field">
                <label class="label" for="username"><?php echo __e('admin.user_form_username'); ?></label>
                <div class="control has-icons-left">
                    <input class="input" type="text" id="username" x-model="form.username"
                           required minlength="3" maxlength="100"
                           pattern="[a-zA-Z0-9_-]+"
                           placeholder="<?php echo $usernamePlaceholder; ?>">
                    <span class="icon is-small is-left">
                        <?php echo IconHelper::render('user', ['class' => 'icon']); ?>
                    </span>
                </div>
            </div>

            <!-- Email -->
            <div class="field">
                <label class="label" for="email"><?php echo __e('admin.user_form_email'); ?></label>
                <div class="control has-icons-left">
                    <input class="input" type="email" id="email" x-model="form.email"
                           required maxlength="255" placeholder="user@example.com">
                    <span class="icon is-small is-left">
                        <?php echo IconHelper::render('mail', ['class' => 'icon']); ?>
                    </span>
                </div>
            </div>

            <!-- Password. Never prefilled; empty on edit means "leave it alone". -->
            <div class="field">
                <label class="label" for="password">
                    <?php echo __e('admin.user_form_password'); ?>
                    <?php if ($isEdit) : ?>
                        <span class="has-text-grey has-text-weight-normal">
                            <?php echo __e('admin.user_form_password_keep'); ?>
                        </span>
                    <?php endif; ?>
                </label>
                <div class="control has-icons-left">
                    <input class="input" type="password" id="password" x-model="form.password"
                           <?php echo $isEdit ? '' : 'required'; ?>
                           minlength="8"
                           placeholder="<?php echo $pwPlaceholder; ?>">
                    <span class="icon is-small is-left">
                        <?php echo IconHelper::render('lock', ['class' => 'icon']); ?>
                    </span>
                </div>
            </div>

            <!-- Role -->
            <div class="field">
                <label class="label" for="role"><?php echo __e('admin.user_form_role'); ?></label>
                <div class="control">
                    <div class="select">
                        <select id="role" x-model="form.role" :disabled="isSelf()">
                            <option value="user"><?php echo __e('admin.user_form_role_user'); ?></option>
                            <option value="admin"><?php echo __e('admin.user_form_role_admin'); ?></option>
                        </select>
                    </div>
                    <p class="help" x-show="isSelf()" x-cloak>
                        <?php echo __e('admin.user_form_role_self_help'); ?>
                    </p>
                </div>
            </div>

            <!-- Active -->
            <div class="field">
                <div class="control">
                    <label class="checkbox">
                        <input type="checkbox" x-model="form.is_active" :disabled="isSelf()">
                        <?php echo __e('admin.user_form_active'); ?>
                    </label>
                    <p class="help" x-show="isSelf()" x-cloak>
                        <?php echo __e('admin.user_form_active_self_help'); ?>
                    </p>
                </div>
            </div>

            <?php if ($isEdit) : ?>
            <!-- OAuth providers (read-only) -->
            <div class="field" x-show="hasLinkedProviders()" x-cloak>
                <label class="label"><?php echo __e('admin.user_form_oauth_label'); ?></label>
                <div class="control">
                    <div class="tags">
                        <template x-for="provider in user.linkedProviders" :key="provider">
                            <span class="tag is-info is-light" x-text="provider"></span>
                        </template>
                    </div>
                </div>
            </div>

            <!-- Metadata (read-only) -->
            <div class="field" x-show="user" x-cloak>
                <label class="label"><?php echo __e('admin.user_form_account_info'); ?></label>
                <div class="content is-small">
                    <p>
                        <strong><?php echo __e('admin.user_form_created'); ?></strong>
                        <span x-text="user.created"></span>
                        <br>
                        <strong><?php echo __e('admin.user_form_last_login'); ?></strong>
                        <span x-text="lastLoginLabel()"></span>
                        <br>
                        <strong><?php echo __e('admin.user_form_has_password'); ?></strong>
                        <span x-text="passwordLabel()"></span>
                    </p>
                </div>
            </div>
            <?php endif; ?>

            <!-- Buttons -->
            <div class="field is-grouped">
                <div class="control">
                    <button class="button is-primary" type="submit" :disabled="isSaving">
                        <?php echo $isEdit
                            ? __e('admin.user_form_save_changes')
                            : __e('admin.user_form_create'); ?>
                    </button>
                </div>
                <div class="control">
                    <a class="button is-light" href="<?php echo url('/admin/users'); ?>">
                        <?php echo __e('admin.user_form_cancel'); ?>
                    </a>
                </div>
            </div>
        </form>
    </div>
</div>
