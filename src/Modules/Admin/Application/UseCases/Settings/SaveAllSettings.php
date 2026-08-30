<?php

/**
 * Save All Settings Use Case
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

use Lwt\Shared\Infrastructure\Http\InputValidator;
use Lwt\Shared\Infrastructure\Database\Settings;
use Lwt\Modules\Admin\Domain\SettingDefinitions;

/**
 * Use case for saving admin-scoped settings from form data.
 *
 * Only saves server-wide admin settings (theme, feed limits, registration).
 * User-scoped preferences are handled by SaveUserPreferences in the User module.
 *
 * @since 3.0.0
 */
class SaveAllSettings
{
    /**
     * Execute the use case - save admin settings from request.
     *
     * @return array{success: bool}
     */
    public function execute(): array
    {
        foreach (SettingDefinitions::getAdminKeys() as $key) {
            if ($key === 'set-allow-registration') {
                // Handle checkbox - convert to 0/1
                $value = InputValidator::getBool($key, false) ? '1' : '0';
            } elseif (InputValidator::has($key)) {
                $value = InputValidator::getString($key);
            } else {
                continue;
            }
            Settings::save($key, $value);
        }

        return ['success' => true];
    }

    /**
     * Execute with explicit data array (for API/testing).
     *
     * @param array<string, string> $data Settings data
     *
     * @return array{success: bool}
     */
    public function executeWithData(array $data): array
    {
        foreach (SettingDefinitions::getAdminKeys() as $key) {
            if (isset($data[$key])) {
                $value = $data[$key];
                if ($key === 'set-allow-registration') {
                    $value = ($value === '1' || $value === 'true') ? '1' : '0';
                }
                Settings::save($key, $value);
            }
        }

        return ['success' => true];
    }
}
