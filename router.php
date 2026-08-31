<?php

/**
 * PHP Built-in Server Router
 *
 * This script enables clean URLs and legacy URL redirects when using
 * PHP's built-in web server (php -S localhost:8000 router.php)
 *
 * PHP version 8.2
 *
 * @category Server
 * @package  Lwt
 * @author   HugoFara <git@hugofara.net>
 * @license  Unlicense <http://unlicense.org/>
 * @link     https://hugofara.github.io/lwt/developer/api
 * @since    3.0.0
 *
 * Usage: php -S localhost:8000 router.php
 */

declare(strict_types=1);

$uri = $_SERVER['REQUEST_URI'] ?? '/';
$path = parse_url($uri, PHP_URL_PATH);
if ($path === false || $path === null) {
    $path = '/';
}

// Serve documentation files directly (VitePress output in /docs)
if (preg_match('#^/docs(/|$)#', $path)) {
    $docPath = __DIR__ . $path;

    // If path ends with / or has no extension, try index.html
    if (is_dir($docPath)) {
        $docPath = rtrim($docPath, '/') . '/index.html';
    } elseif (!pathinfo($path, PATHINFO_EXTENSION)) {
        $docPath .= '.html';
    }

    if (file_exists($docPath)) {
        // Set appropriate content type
        $ext = pathinfo($docPath, PATHINFO_EXTENSION);
        $mimeTypes = [
            'html' => 'text/html',
            'css' => 'text/css',
            'js' => 'application/javascript',
            'json' => 'application/json',
            'png' => 'image/png',
            'jpg' => 'image/jpeg',
            'jpeg' => 'image/jpeg',
            'gif' => 'image/gif',
            'svg' => 'image/svg+xml',
            'ico' => 'image/x-icon',
            'woff' => 'font/woff',
            'woff2' => 'font/woff2',
        ];
        $contentType = $mimeTypes[$ext] ?? 'application/octet-stream';
        header("Content-Type: $contentType");
        readfile($docPath);
        return true;
    }
}

// Serve static files directly
$staticExtensions = [
    'css', 'js', 'png', 'jpg', 'jpeg', 'gif',
    'ico', 'svg', 'woff', 'woff2', 'ttf', 'eot', 'map', 'html'
];
$ext = pathinfo($path, PATHINFO_EXTENSION);
if (in_array(strtolower($ext), $staticExtensions) && file_exists(__DIR__ . $path)) {
    return false; // Let PHP's built-in server handle static files
}

// Route everything through index.php
$_SERVER['SCRIPT_NAME'] = '/index.php';
require __DIR__ . '/index.php';
