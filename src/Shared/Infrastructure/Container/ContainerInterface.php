<?php

/**
 * PSR-11 Container Interface
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
 * PSR-11 compatible container interface.
 *
 * This interface follows the PSR-11 ContainerInterface specification.
 *
 * @since 3.0.0
 */
interface ContainerInterface
{
    /**
     * Finds an entry of the container by its identifier and returns it.
     *
     * @param string $id Identifier of the entry to look for
     *
     * @return mixed Entry
     *
     * @throws NotFoundException  No entry was found for this identifier
     * @throws ContainerException Error while retrieving the entry
     */
    public function get(string $id): mixed;

    /**
     * Returns true if the container can return an entry for the given identifier.
     *
     * @param string $id Identifier of the entry to look for
     *
     * @return bool
     */
    public function has(string $id): bool;
}
