#!/usr/bin/env php
<?php

/**
 * Route List Extractor
 *
 * Extracts all registered routes from routes.php and outputs them.
 * Useful for keeping smoke tests in sync with actual routes.
 *
 * Usage:
 *   php scripts/list-routes.php          # List all routes
 *   php scripts/list-routes.php --json   # Output as JSON
 *   php scripts/list-routes.php --cypress # Output as Cypress-compatible TypeScript
 *
 * PHP version 8.2
 */

// Parse the routes file directly using regex instead of executing it
// This avoids namespace/autoload issues

$routesFile = __DIR__ . '/../src/backend/Router/routes.php';

if (!file_exists($routesFile)) {
    fwrite(STDERR, "Error: routes.php not found at {$routesFile}\n");
    exit(1);
}

$content = file_get_contents($routesFile);
if ($content === false) {
    fwrite(STDERR, "Error: Could not read routes.php\n");
    exit(1);
}

// Extract routes using regex
$routes = [];

// Match $router->register('path', 'handler')
preg_match_all(
    "/\\\$router->register\s*\(\s*['\"]([^'\"]+)['\"]\s*,\s*['\"]([^'\"]+)['\"]/",
    $content,
    $matches,
    PREG_SET_ORDER
);

foreach ($matches as $match) {
    $routes[] = [
        'path' => $match[1],
        'handler' => $match[2],
        'type' => 'exact'
    ];
}

// Match $router->registerPrefix('prefix', 'handler')
preg_match_all(
    "/\\\$router->registerPrefix\s*\(\s*['\"]([^'\"]+)['\"]\s*,\s*['\"]([^'\"]+)['\"]/",
    $content,
    $matches,
    PREG_SET_ORDER
);

foreach ($matches as $match) {
    $routes[] = [
        'path' => $match[1],
        'handler' => $match[2],
        'type' => 'prefix'
    ];
}

// Parse command line arguments
$format = 'text';
if (in_array('--json', $argv)) {
    $format = 'json';
} elseif (in_array('--cypress', $argv)) {
    $format = 'cypress';
}

// Output based on format
switch ($format) {
    case 'json':
        $json = json_encode($routes, JSON_PRETTY_PRINT);
        echo ($json !== false ? $json : '[]') . "\n";
        break;

    case 'cypress':
        echo "// Auto-generated route list from routes.php\n";
        echo "// Generated: " . date('Y-m-d H:i:s') . "\n";
        echo "// Run: php scripts/list-routes.php --cypress\n\n";
        echo "export const allRoutes = [\n";
        foreach ($routes as $route) {
            $path = $route['path'];
            // Generate a readable name from the handler
            $handler = $route['handler'];
            if (str_contains($handler, '@')) {
                [$controller, $method] = explode('@', $handler);
                $controller = str_replace('Controller', '', $controller);
                $name = $controller . ' ' . ucfirst($method);
            } else {
                $name = basename($handler, '.php');
            }
            $name = addslashes($name);
            $path = addslashes($path);
            echo "  { path: '{$path}', name: '{$name}' },\n";
        }
        echo "] as const;\n";
        break;

    default:
        echo "LWT Routes (" . count($routes) . " total)\n";
        echo str_repeat('=', 60) . "\n\n";

        $currentSection = '';
        foreach ($routes as $route) {
            // Group by first path segment
            $parts = explode('/', trim($route['path'], '/'));
            $section = $parts[0] ?: 'root';

            if ($section !== $currentSection) {
                $currentSection = $section;
                echo "\n[" . strtoupper($section) . "]\n";
            }

            $typeIndicator = $route['type'] === 'prefix' ? ' (prefix)' : '';
            printf("  %-35s -> %s%s\n", $route['path'], $route['handler'], $typeIndicator);
        }
        break;
}
