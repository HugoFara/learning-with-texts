<?php

/**
 * Not Found Exception
 *
 * PHP version 8.2
 *
 * @category Lwt
 * @package  Lwt\Shared\Infrastructure\Container
 * @author   HugoFara <git@hugofara.net>
 * @license  Unlicense <http://unlicense.org/>
 * @link     https://hugofara.github.io/lwt/developer/api
 * @since    3.0.0
 */

declare(strict_types=1);

namespace Lwt\Shared\Infrastructure\Container;

/**
 * Exception thrown when a requested service is not found in the container.
 *
 * @since 3.0.0
 */
class NotFoundException extends ContainerException
{
}
