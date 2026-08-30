<?php

/**
 * Save User Preferences Use Case
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
use Lwt\Shared\Infrastructure\Globals;
use Lwt\Shared\Infrastructure\Http\InputValidator;
use Lwt\Modules\Admin\Domain\SettingDefinitions;

/**
 * Use case for saving user-scoped preferences from form data.
 *
 * In multi-user mode, saves with the current user's ID.
 * In single-user mode, saves to the global row (StUsID=0).
 *
 * @since 3.0.0
 */
class SaveUserPreferences
{
    /**
     * Execute the use case - save user preferences from request.
     *
     * @return array{success: bool}
     */
    public function execute(): array
    {
        $userId = Globals::getCurrentUserId();
        $userKeys = SettingDefinitions::getUserKeys();

        foreach ($userKeys as $key) {
            if ($key === 'set-tts') {
                $value = InputValidator::getBool($key, false) ? '1' : '0';
            } elseif (InputValidator::has($key)) {
                $value = InputValidator::getString($key);
            } else {
                continue;
            }

            if ($userId !== null) {
                Settings::saveForUser($key, $value, $userId);
            } else {
                Settings::save($key, $value);
            }
        }

        return ['success' => true];
    }

    /**
     * Save preferences from an array rather than the request.
     *
     * The API-friendly twin of {@see execute()}. Only keys the definitions
     * declare as user-scoped are written, so an unexpected key in the payload
     * is ignored rather than becoming a setting.
     *
     * @param array<string, mixed> $data Settings keyed by name
     *
     * @return array{success: bool}
     */
    public function executeFromData(array $data): array
    {
        $userId = Globals::getCurrentUserId();
        $userKeys = SettingDefinitions::getUserKeys();

        foreach ($userKeys as $key) {
            if (!array_key_exists($key, $data)) {
                continue;
            }

            if ($key === 'set-tts') {
                $value = !empty($data[$key]) && $data[$key] !== '0' ? '1' : '0';
            } else {
                $value = (string) $data[$key];
            }

            if ($userId !== null) {
                Settings::saveForUser($key, $value, $userId);
            } else {
                Settings::save($key, $value);
            }
        }

        return ['success' => true];
    }
}
