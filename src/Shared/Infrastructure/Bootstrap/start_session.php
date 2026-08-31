<?php

/**
 * \file
 * \brief Start a PHP session.
 *
 * PHP version 8.2
 *
 * @category Lwt
 * @package  Lwt\Shared\Infrastructure\Bootstrap
 * @author   HugoFara <git@hugofara.net>
 * @license Unlicense <http://unlicense.org/>
 * @link    https://hugofara.github.io/lwt/developer/api
 * @since   2.0.3-fork
 */

declare(strict_types=1);

namespace Lwt\Shared\Infrastructure\Bootstrap;

// Core utilities (replaces kernel_utility.php)
require_once __DIR__ . '/../Globals.php';
require_once __DIR__ . '/../Utilities/ErrorHandler.php';
require_once __DIR__ . '/SessionBootstrap.php';

use Lwt\Shared\Infrastructure\Globals;


SessionBootstrap::bootstrap();
