<?php

/**
 * Admin User List - scaffold for the client-rendered user table.
 *
 * Rows, statistics and paging come from `GET /api/v1/admin/users`; the row
 * actions use the matching PUT/DELETE routes. See user_management.ts.
 *
 * Variables expected:
 * - $data: array carrying the initial search/sort/direction
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

/** @var array<string, mixed> $data */
$data = is_array($data ?? null) ? $data : [];
$search = isset($data['search']) && is_string($data['search']) ? $data['search'] : '';
$sort = isset($data['sort']) && is_string($data['sort']) ? $data['sort'] : 'username';
$dir = isset($data['dir']) && is_string($data['dir']) ? $data['dir'] : 'ASC';

$searchPlaceholder = htmlspecialchars(__('admin.users_search_placeholder'), ENT_QUOTES, 'UTF-8');
$titleEdit = htmlspecialchars(__('admin.users_action_edit'), ENT_QUOTES, 'UTF-8');
$titleDelete = htmlspecialchars(__('admin.users_action_delete'), ENT_QUOTES, 'UTF-8');
?>
<?php ConfigIsland::render('user-list-config', [
    'search' => $search,
    'sort' => $sort,
    'dir' => $dir,
]); ?>

<div class="container" x-data="userManagement">

    <div x-show="error" x-cloak class="notification is-danger">
        <span x-text="error"></span>
    </div>

    <!-- Stats Summary -->
    <div class="columns is-multiline mb-4" x-show="!isLoading" x-cloak>
        <div class="column is-3">
            <div class="box has-text-centered">
                <p class="heading"><?php echo __e('admin.users_total'); ?></p>
                <p class="title is-4" x-text="statOr('total')"></p>
            </div>
        </div>
        <div class="column is-3">
            <div class="box has-text-centered">
                <p class="heading"><?php echo __e('admin.users_active'); ?></p>
                <p class="title is-4 has-text-success" x-text="statOr('active')"></p>
            </div>
        </div>
        <div class="column is-3">
            <div class="box has-text-centered">
                <p class="heading"><?php echo __e('admin.users_inactive'); ?></p>
                <p class="title is-4 has-text-warning" x-text="statOr('inactive')"></p>
            </div>
        </div>
        <div class="column is-3">
            <div class="box has-text-centered">
                <p class="heading"><?php echo __e('admin.users_admins'); ?></p>
                <p class="title is-4 has-text-link" x-text="statOr('admins')"></p>
            </div>
        </div>
    </div>

    <!-- Toolbar -->
    <div class="level mb-4">
        <div class="level-left">
            <div class="level-item">
                <div class="field has-addons">
                    <div class="control has-icons-left">
                        <input class="input" type="text" x-model="searchInput"
                               @keyup.enter="doSearch()"
                               placeholder="<?php echo $searchPlaceholder; ?>">
                        <span class="icon is-small is-left">
                            <?php echo IconHelper::render('search', ['class' => 'icon']); ?>
                        </span>
                    </div>
                    <div class="control">
                        <button class="button is-info" type="button" @click="doSearch()">
                            <?php echo __e('admin.users_search_button'); ?>
                        </button>
                    </div>
                    <div class="control" x-show="searchInput" x-cloak>
                        <button class="button" type="button" @click="clearSearch()">
                            <?php echo IconHelper::render('x', ['class' => 'icon']); ?>
                        </button>
                    </div>
                </div>
            </div>
        </div>
        <div class="level-right">
            <div class="level-item">
                <a class="button is-primary" href="<?php echo url('/admin/users/new'); ?>">
                    <?php echo IconHelper::render('circle-plus', ['class' => 'icon']); ?>
                    <span class="ml-1"><?php echo __e('admin.users_add_new'); ?></span>
                </a>
            </div>
        </div>
    </div>

    <div x-show="isLoading" x-cloak class="has-text-centered py-4">
        <?php echo __e('admin.users_loading'); ?>
    </div>

    <p x-show="isEmpty()" x-cloak class="has-text-grey">
        <?php echo __e('admin.users_none'); ?>
    </p>

    <div class="table-container" x-show="users.length > 0" x-cloak>
        <table class="table is-fullwidth is-hoverable is-striped">
            <thead>
                <tr>
                    <th>
                        <a href="#" @click.prevent="sortBy('username')">
                            <?php echo __e('admin.users_col_username'); ?>
                        </a>
                    </th>
                    <th>
                        <a href="#" @click.prevent="sortBy('email')">
                            <?php echo __e('admin.users_col_email'); ?>
                        </a>
                    </th>
                    <th class="has-text-centered"><?php echo __e('admin.users_col_role'); ?></th>
                    <th class="has-text-centered"><?php echo __e('admin.users_col_active'); ?></th>
                    <th>
                        <a href="#" @click.prevent="sortBy('last_login')">
                            <?php echo __e('admin.users_col_last_login'); ?>
                        </a>
                    </th>
                    <th class="has-text-centered"><?php echo __e('admin.users_col_actions'); ?></th>
                </tr>
            </thead>
            <tbody>
                <template x-for="user in users" :key="user.id">
                    <tr>
                        <td>
                            <strong x-text="user.username"></strong>
                            <span class="tag is-light is-small ml-1" x-show="isSelf(user)">
                                <?php echo __e('admin.users_self_tag'); ?>
                            </span>
                        </td>
                        <td x-text="user.email"></td>
                        <td class="has-text-centered">
                            <span :class="roleClass(user)" x-text="roleLabel(user)"></span>
                        </td>
                        <td class="has-text-centered">
                            <span :class="statusClass(user)" x-text="statusLabel(user)"></span>
                        </td>
                        <td x-text="lastLoginLabel(user)"></td>
                        <td class="has-text-centered">
                            <div class="buttons are-small is-centered">
                                <a :href="editUrl(user)" class="button is-small is-ghost"
                                   title="<?php echo $titleEdit; ?>">
                                    <?php echo IconHelper::render('file-pen', ['class' => 'icon']); ?>
                                </a>
                                <!-- Self-protection is enforced server-side; these
                                     disabled states only spare a pointless call. -->
                                <button type="button" class="button is-small is-ghost"
                                        :disabled="isSelf(user) || isBusy(user)"
                                        @click="toggleActive(user)"
                                        :title="statusLabel(user)">
                                    <?php echo IconHelper::render('power', ['class' => 'icon']); ?>
                                </button>
                                <button type="button" class="button is-small is-ghost"
                                        :disabled="isSelf(user) || isBusy(user)"
                                        @click="toggleRole(user)"
                                        :title="roleLabel(user)">
                                    <?php echo IconHelper::render('shield', ['class' => 'icon']); ?>
                                </button>
                                <button type="button" class="button is-small is-ghost has-text-danger"
                                        :disabled="isSelf(user) || isBusy(user)"
                                        @click="confirmDelete(user)"
                                        title="<?php echo $titleDelete; ?>">
                                    <?php echo IconHelper::render('trash-2', ['class' => 'icon']); ?>
                                </button>
                            </div>
                        </td>
                    </tr>
                </template>
            </tbody>
        </table>
    </div>

    <!-- Pagination -->
    <nav class="pagination is-centered mt-4" role="navigation" x-show="hasPages()" x-cloak>
        <button class="pagination-previous" :disabled="pagination.page <= 1"
                @click="goToPage(pagination.page - 1)">
            <?php echo __e('admin.users_previous'); ?>
        </button>
        <button class="pagination-next" :disabled="pagination.page >= pagination.total_pages"
                @click="goToPage(pagination.page + 1)">
            <?php echo __e('admin.users_next'); ?>
        </button>
        <ul class="pagination-list">
            <template x-for="p in pagination.total_pages" :key="p">
                <li>
                    <button class="pagination-link" :class="{ 'is-current': p === pagination.page }"
                            @click="goToPage(p)" x-text="p"></button>
                </li>
            </template>
        </ul>
    </nav>

</div>
