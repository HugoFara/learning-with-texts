<?php

/**
 * \file
 * \brief Database configuration and connection setup.
 *
 * PHP version 8.2
 *
 * @category Database
 * @package  Lwt
 * @author   HugoFara <git@hugofara.net>
 * @license  Unlicense <http://unlicense.org/>
 * @link     https://hugofara.github.io/lwt/developer/api
 * @since    3.0.0
 */

declare(strict_types=1);

namespace Lwt\Shared\Infrastructure\Database;

use Lwt\Shared\Infrastructure\Bootstrap\EnvLoader;
use Lwt\Shared\Infrastructure\Utilities\ErrorHandler;

/**
 * Database configuration and connection utilities.
 *
 * Handles loading database configuration from .env files
 * and establishing connections.
 *
 * @since 3.0.0
 */
class Configuration
{
    /**
     * Build a helpful error message based on the mysqli connect error code.
     *
     * @param string $server The server that was being connected to
     * @param string $dbname The database name (if relevant)
     *
     * @return string Human-readable error message with troubleshooting hints
     */
    private static function buildConnectErrorMessage(
        string $server,
        string $dbname = ''
    ): string {
        $errno = mysqli_connect_errno();
        $error = mysqli_connect_error() ?? 'Unknown error';

        $hint = match ($errno) {
            2002 => "Cannot reach the database server at \"$server\".\n" .
                    "  - Is MySQL/MariaDB running? Try: sudo systemctl status mysql\n" .
                    "  - If using Docker, is the DB container up? Try: docker compose ps\n" .
                    "  - Check DB_HOST in your .env (use \"db\" for Docker, \"localhost\" or \"127.0.0.1\" for local).",
            2003 => "Connection refused by the database server at \"$server\".\n" .
                    "  - Is MySQL/MariaDB running and accepting connections?\n" .
                    "  - Check that DB_HOST and port are correct in your .env file.",
            2005 => "Unknown database host \"$server\".\n" .
                    "  - Check DB_HOST in your .env file — is the hostname spelled correctly?\n" .
                    "  - If using Docker, the host should typically be \"db\" (the service name).",
            1045 => "Access denied — wrong username or password.\n" .
                    "  - Check DB_USER and DB_PASSWORD in your .env file.\n" .
                    "  - Verify the credentials work: mysql -u <DB_USER> -p -h $server",
            1049 => "Database \"$dbname\" does not exist and could not be created.\n" .
                    "  - Check DB_NAME in your .env file.\n" .
                    "  - Does the DB_USER have CREATE DATABASE privileges?",
            1044 => "Access denied to database \"$dbname\".\n" .
                    "  - The user can connect but lacks permission for this database.\n" .
                    "  - Grant access: GRANT ALL ON `$dbname`.* TO '<user>'@'%';",
            2006 => "MySQL server has gone away.\n" .
                    "  - The server closed the connection. Is it overloaded or restarting?",
            default => "Connection failed (error $errno: $error).\n" .
                       "  - Check DB_HOST, DB_USER, DB_PASSWORD, and DB_NAME in your .env file.",
        };

        return "Database connection error:\n$hint\n\n" .
               "Raw error: [$errno] $error\n" .
               "Documentation: https://hugofara.github.io/lwt/guide/installation";
    }

    /**
     * Load database configuration from .env file.
     *
     * @param string $envPath Path to the .env file
     *
     * @return array{server: string, userid: string, passwd: string, dbname: string, socket: string}
     */
    public static function loadFromEnv(string $envPath): array
    {
        $defaults = [
            'server' => 'localhost',
            'userid' => 'root',
            'passwd' => '',
            'dbname' => 'learning-with-texts',
            'socket' => ''
        ];

        if (EnvLoader::load($envPath)) {
            return EnvLoader::getDatabaseConfig();
        }

        return $defaults;
    }

    /**
     * Make the connection to the database.
     *
     * @param string $server Server name
     * @param string $userid Database user ID
     * @param string $passwd User password
     * @param string $dbname Database name
     * @param string $socket Database socket
     *
     * @return \mysqli Connection to the database
     */
    public static function connect(
        string $server,
        string $userid,
        string $passwd,
        string $dbname,
        string $socket = ""
    ): \mysqli {
        // @ suppresses error messages

        // Necessary since mysqli_report default setting in PHP 8.1+ has changed
        @mysqli_report(MYSQLI_REPORT_OFF);

        $dbconnection = mysqli_init();

        if ($dbconnection === false) {
            ErrorHandler::die(
                "Database connection error: mysqli_init() failed.\n" .
                "  - Is the mysqli PHP extension installed? Try: php -m | grep mysqli\n" .
                "  - Documentation: https://hugofara.github.io/lwt/guide/installation"
            );
        }

        @mysqli_options($dbconnection, MYSQLI_OPT_LOCAL_INFILE, 1);

        if ($socket != "") {
            $success = @mysqli_real_connect(
                $dbconnection,
                $server,
                $userid,
                $passwd,
                $dbname,
                socket: $socket
            );
        } else {
            $success = @mysqli_real_connect(
                $dbconnection,
                $server,
                $userid,
                $passwd,
                $dbname
            );
        }

        if (!$success && mysqli_connect_errno() == 1049) {
            // Database unknown, try with generic database
            $success = @mysqli_real_connect(
                $dbconnection,
                $server,
                $userid,
                $passwd
            );

            if (!$success) {
                ErrorHandler::die(
                    self::buildConnectErrorMessage($server, $dbname)
                );
            }
            $result = mysqli_query(
                $dbconnection,
                "CREATE DATABASE `$dbname`
                DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci"
            );
            if (!$result) {
                ErrorHandler::die(
                    "Database \"$dbname\" does not exist and could not be created.\n" .
                    "  - Does the DB_USER have CREATE DATABASE privileges?\n" .
                    "  - You can create it manually: CREATE DATABASE `$dbname` " .
                    "DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"
                );
            }
            mysqli_close($dbconnection);
            // Create a new mysqli object — PHP 8.1+ does not allow
            // mysqli_real_connect() on a closed mysqli instance.
            $dbconnection = mysqli_init();
            if ($dbconnection === false) {
                ErrorHandler::die(
                    "Database connection error: mysqli_init() failed after creating database."
                );
            }
            $success = @mysqli_real_connect(
                $dbconnection,
                $server,
                $userid,
                $passwd,
                $dbname
            );
        }

        if (!$success) {
            ErrorHandler::die(
                self::buildConnectErrorMessage($server, $dbname)
            );
        }

        @mysqli_query($dbconnection, "SET NAMES 'utf8mb4'");

        @mysqli_query($dbconnection, "SET SESSION sql_mode = 'STRICT_ALL_TABLES'");

        // Set shorter timeouts for test database connections to prevent zombie locks
        // Use 300s (5 min) to allow for slow external HTTP requests in translation tests
        if (str_starts_with($dbname, 'test_')) {
            @mysqli_query($dbconnection, "SET SESSION wait_timeout = 300");
            @mysqli_query($dbconnection, "SET SESSION interactive_timeout = 300");
            @mysqli_query($dbconnection, "SET SESSION lock_wait_timeout = 60");
        }

        return $dbconnection;
    }
}
