<?php

/**
 * \file
 * \brief Database bootstrap class - establishes connection and initializes globals.
 *
 * PHP version 8.2
 *
 * @category Database
 * @package  Lwt\Shared\Infrastructure\Bootstrap
 * @author   HugoFara <git@hugofara.net>
 * @license  Unlicense <http://unlicense.org/>
 * @link     https://hugofara.github.io/lwt/developer/api
 * @since    3.0.0
 */

declare(strict_types=1);

namespace Lwt\Shared\Infrastructure\Bootstrap;

use Lwt\Shared\Infrastructure\Globals;
use Lwt\Shared\Infrastructure\Database\Configuration;

/**
 * Database bootstrap utility class.
 *
 * Provides static methods for establishing the database connection
 * and initializing the required global state.
 *
 * @since 3.0.0
 */
class DatabaseBootstrap
{
    /**
     * Load database configuration from .env file or environment variables.
     *
     * Configuration sources (in order of priority):
     * 1. .env file values (if file exists)
     * 2. Environment variables (for Docker deployments)
     * 3. Default values
     *
     * @return array{
     *     server: string,
     *     userid: string,
     *     passwd: string,
     *     dbname: string,
     *     socket: string
     * }
     */
    public static function loadConfiguration(): array
    {
        $envPath = __DIR__ . '/../../../../.env';

        // Try to load .env file (may not exist in Docker)
        EnvLoader::load($envPath);

        // getDatabaseConfig() will check:
        // 1. Values loaded from .env file
        // 2. $_ENV superglobal
        // 3. getenv() (environment variables from Docker)
        // 4. Default values
        return EnvLoader::getDatabaseConfig();
    }

    /**
     * Bootstrap the database connection.
     *
     * This method:
     * 1. Loads configuration from .env
     * 2. Establishes the database connection
     * 3. Registers connection with Globals
     * 4. Runs database migrations if needed
     *
     * @return void
     */
    public static function bootstrap(): void
    {
        // Skip if already initialized
        if (Globals::getDbConnection() !== null) {
            return;
        }

        // Load configuration
        $config = self::loadConfiguration();

        // Allow tests to override database name via Globals::setDatabaseName()
        $dbname = Globals::getDatabaseName() ?: $config['dbname'];

        // Connect to database
        $connection = Configuration::connect(
            $config['server'],
            $config['userid'],
            $config['passwd'],
            $dbname,
            $config['socket']
        );

        // Register connection with Globals
        Globals::setDbConnection($connection);
        Globals::setDatabaseName($dbname);

        // Run database migrations
        \Lwt\Shared\Infrastructure\Database\Migrations::checkAndUpdate();

        // Configure multi-user mode from environment
        $multiUserEnabled = EnvLoader::getBool('MULTI_USER_ENABLED', false);
        Globals::setMultiUserEnabled($multiUserEnabled);

        // Configure backup restore (only if explicitly set in env)
        if (EnvLoader::has('BACKUP_RESTORE_ENABLED')) {
            Globals::setBackupRestoreEnabled(EnvLoader::getBool('BACKUP_RESTORE_ENABLED', true));
        }
    }
}
