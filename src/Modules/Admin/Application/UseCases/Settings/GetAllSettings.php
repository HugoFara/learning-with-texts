<?php

/**
 * Get All Settings Use Case
 *
 * PHP version 8.2
 *
 * @category Lwt
 * @package  Lwt\Modules\Admin\Application\UseCases\Settings
 * @author   HugoFara <git@hugofara.net>
 * @license  Unlicense <http://unlicense.org/>
 * @link     https://hugofara.github.io/lwt/developer/api
 * @since    3.0.0
 */

declare(strict_types=1);

namespace Lwt\Modules\Admin\Application\UseCases\Settings;

use Lwt\Shared\Infrastructure\Database\Settings;
use Lwt\Modules\Admin\Domain\SettingDefinitions;

/**
 * Use case for getting all admin-scoped settings.
 *
 * Only returns server-wide admin settings (theme, feed limits, registration).
 * User-scoped preferences are handled by GetUserPreferences in the User module.
 *
 * @since 3.0.0
 */
class GetAllSettings
{
    /**
     * Execute the use case.
     *
     * @return array<string, string> Admin settings with their values
     */
    public function execute(): array
    {
        $settings = [];
        foreach (SettingDefinitions::getAdminKeys() as $key) {
            $settings[$key] = Settings::getWithDefault($key);
        }
        return $settings;
    }

    /**
     * Get all admin setting keys.
     *
     * @return string[] Setting keys
     */
    public static function getSettingKeys(): array
    {
        return SettingDefinitions::getAdminKeys();
    }
}
