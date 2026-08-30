<?php

/**
 * Save Setting Use Case
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
use Lwt\Modules\Admin\Application\Services\SessionCleaner;

/**
 * Use case for saving a single setting with optional session clearing.
 *
 * Used by the save_setting_redirect endpoint.
 *
 * @since 3.0.0
 */
class SaveSetting
{
    private SessionCleaner $sessionCleaner;

    /**
     * Constructor.
     *
     * @param SessionCleaner|null $sessionCleaner Session cleaner service
     */
    public function __construct(?SessionCleaner $sessionCleaner = null)
    {
        $this->sessionCleaner = $sessionCleaner ?? new SessionCleaner();
    }

    /**
     * Execute the use case.
     *
     * @param string $key   Setting key
     * @param string $value Setting value
     *
     * @return void
     */
    public function execute(string $key, string $value): void
    {
        // Clear session when changing language
        if ($key === 'currentlanguage') {
            $this->sessionCleaner->clearAllFilters();
        }

        Settings::save($key, $value);
    }
}
