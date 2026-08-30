<?php

/**
 * Get User Preferences Use Case
 *
 * PHP version 8.2
 *
 * @category Lwt
 * @package  Lwt\Modules\User\Application\UseCases
 * @author   HugoFara <git@hugofara.net>
 * @license  Unlicense <http://unlicense.org/>
 * @link     https://hugofara.github.io/lwt/developer/api
 * @since    3.0.0
 */

declare(strict_types=1);

namespace Lwt\Modules\User\Application\UseCases;

use Lwt\Shared\Infrastructure\Database\Settings;
use Lwt\Modules\Admin\Domain\SettingDefinitions;

/**
 * Use case for getting all user-scoped preferences.
 *
 * @since 3.0.0
 */
class GetUserPreferences
{
    /**
     * Execute the use case.
     *
     * @return array<string, string> User preferences with their current values
     */
    public function execute(): array
    {
        $settings = [];
        foreach (SettingDefinitions::getUserKeys() as $key) {
            $settings[$key] = Settings::getWithDefault($key);
        }
        return $settings;
    }
}
