<?php

/**
 * LWT Front Controller
 *
 * This file serves as the single entry point for all requests.
 * It bootstraps the application and delegates to the Application class.
 *
 * PHP version 8.2
 *
 * @category User_Interface
 * @package Lwt
 * @author  LWT Project <lwt-project@hotmail.com>
 * @license Unlicense <http://unlicense.org/>
 * @link    https://hugofara.github.io/lwt/developer/api
 * @since   3.0.0
 *
 * "Learning with Texts" (LWT) is free and unencumbered software
 * released into the PUBLIC DOMAIN.
 */

declare(strict_types=1);

// Load Composer autoloader for PSR-4 class autoloading
require_once __DIR__ . '/vendor/autoload.php';

// Create and run the application
$app = new \Lwt\Application(__DIR__);
$app->bootstrap();
$app->run();
