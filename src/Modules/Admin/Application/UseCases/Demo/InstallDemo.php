<?php

/**
 * Install Demo Use Case
 *
 * PHP version 8.2
 *
 * @category Lwt
 * @package  Lwt\Modules\Admin\Application\UseCases\Demo
 * @author   HugoFara <git@hugofara.net>
 * @license  Unlicense <http://unlicense.org/>
 * @link     https://hugofara.github.io/lwt/developer/api
 * @since    3.0.0
 */

declare(strict_types=1);

namespace Lwt\Modules\Admin\Application\UseCases\Demo;

use Lwt\Shared\Infrastructure\Database\Restore;

/**
 * Use case for installing demo database.
 *
 * @since 3.0.0
 */
class InstallDemo
{
    /**
     * Execute the use case.
     *
     * @return string Status message from restore process
     *
     * @throws \RuntimeException If working directory cannot be determined or file cannot be accessed
     */
    public function execute(): string
    {
        $cwd = getcwd();
        if ($cwd === false) {
            throw new \RuntimeException('Could not determine current working directory');
        }
        $file = $cwd . '/db/seeds/demo.sql';

        if (!file_exists($file)) {
            throw new \RuntimeException("File '{$file}' does not exist");
        }

        $handle = fopen($file, "r");
        if ($handle === false) {
            throw new \RuntimeException("File '{$file}' could not be opened");
        }

        return Restore::restoreFile($handle, "Demo Database");
    }
}
