<?php

/**
 * MySQL Settings Repository
 *
 * PHP version 8.2
 *
 * @category Lwt
 * @package  Lwt\Modules\Admin\Infrastructure
 * @author   HugoFara <git@hugofara.net>
 * @license  Unlicense <http://unlicense.org/>
 * @link     https://hugofara.github.io/lwt/developer/api
 * @since    3.0.0
 */

declare(strict_types=1);

namespace Lwt\Modules\Admin\Infrastructure;

use Lwt\Shared\Infrastructure\Database\QueryBuilder;
use Lwt\Shared\Infrastructure\Database\Settings;
use Lwt\Modules\Admin\Domain\SettingsRepositoryInterface;

/**
 * MySQL repository for settings operations.
 *
 * Provides database access for application settings.
 *
 * @since 3.0.0
 */
class MySqlSettingsRepository implements SettingsRepositoryInterface
{
    /**
     * {@inheritdoc}
     */
    public function get(string $key, string $default = ''): string
    {
        return Settings::getWithDefault($key);
    }

    /**
     * {@inheritdoc}
     */
    public function save(string $key, string $value): void
    {
        Settings::save($key, $value);
    }

    /**
     * {@inheritdoc}
     */
    public function deleteByPattern(string $pattern): int
    {
        return QueryBuilder::table('settings')
            ->where('StKey', 'LIKE', $pattern)
            ->delete();
    }

    /**
     * {@inheritdoc}
     */
    public function getAll(): array
    {
        $rows = QueryBuilder::table('settings')
            ->select(['StKey', 'StValue'])
            ->getPrepared();

        /** @var array<string, string> $settings */
        $settings = [];
        foreach ($rows as $row) {
            $key = (string) ($row['StKey'] ?? '');
            $settings[$key] = (string) ($row['StValue'] ?? '');
        }

        return $settings;
    }

    /**
     * {@inheritdoc}
     */
    public function exists(string $key): bool
    {
        return QueryBuilder::table('settings')
            ->where('StKey', '=', $key)
            ->existsPrepared();
    }
}
