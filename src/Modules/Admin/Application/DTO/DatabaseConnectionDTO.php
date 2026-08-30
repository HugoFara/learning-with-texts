<?php

/**
 * Database Connection DTO
 *
 * Data transfer object for database connection configuration.
 *
 * PHP version 8.2
 *
 * @category Lwt
 * @package  Lwt\Modules\Admin\Application\DTO
 * @author   HugoFara <git@hugofara.net>
 * @license  Unlicense <http://unlicense.org/>
 * @link     https://hugofara.github.io/lwt/developer/api
 * @since    3.0.0
 */

declare(strict_types=1);

namespace Lwt\Modules\Admin\Application\DTO;

/**
 * DTO for database connection configuration.
 *
 * Replaces the old DatabaseConnection class from DatabaseWizardService.
 *
 * @since 3.0.0
 */
class DatabaseConnectionDTO
{
    /**
     * Server name/host.
     */
    public string $server;

    /**
     * Database user ID.
     */
    public string $userid;

    /**
     * User password.
     */
    public string $passwd;

    /**
     * Database name.
     */
    public string $dbname;

    /**
     * Socket path (optional).
     */
    public string $socket;

    /**
     * Create a new database connection DTO.
     *
     * @param string $server Database server host
     * @param string $userid Database user ID
     * @param string $passwd Database password
     * @param string $dbname Database name
     * @param string $socket Socket path (optional)
     */
    public function __construct(
        string $server = '',
        string $userid = '',
        string $passwd = '',
        string $dbname = '',
        string $socket = ''
    ) {
        $this->server = $server;
        $this->userid = $userid;
        $this->passwd = $passwd;
        $this->dbname = $dbname;
        $this->socket = $socket;
    }

    /**
     * Create DTO from form data array.
     *
     * @param array<string, mixed> $formData Form input data
     *
     * @return self New DTO instance
     */
    public static function fromFormData(array $formData): self
    {
        return new self(
            (string) ($formData['server'] ?? ''),
            (string) ($formData['userid'] ?? ''),
            (string) ($formData['passwd'] ?? ''),
            (string) ($formData['dbname'] ?? ''),
            (string) ($formData['socket'] ?? '')
        );
    }

    /**
     * Convert to array.
     *
     * @return array<string, string> Connection data as array
     */
    public function toArray(): array
    {
        return [
            'server' => $this->server,
            'userid' => $this->userid,
            'passwd' => $this->passwd,
            'dbname' => $this->dbname,
            'socket' => $this->socket,
        ];
    }

    /**
     * Check if connection data is empty.
     *
     * @return bool True if no connection details are set
     */
    public function isEmpty(): bool
    {
        return empty($this->server) && empty($this->userid) &&
               empty($this->passwd) && empty($this->dbname);
    }
}
