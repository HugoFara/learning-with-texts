#!/usr/bin/env php
<?php

/**
 * Curated Dictionary Health Checker
 *
 * Validates that curated dictionary download links in
 * data/curated_dictionaries.json are still reachable. Uses HEAD requests
 * to avoid downloading large files; falls back to GET for servers that
 * reject HEAD.
 *
 * Usage:
 *   php bin/check-dictionaries.php                  # Check all dictionaries
 *   php bin/check-dictionaries.php --language=fr    # Check only French
 *   php bin/check-dictionaries.php --json           # Machine-readable JSON output
 *   php bin/check-dictionaries.php --fix            # Update _lastVerified on success
 *   php bin/check-dictionaries.php --timeout=15     # HTTP timeout in seconds (default 10)
 *
 * Exit codes:
 *   0 = all dictionaries reachable
 *   1 = one or more dictionaries have issues
 *   2 = registry file missing or invalid
 *
 * PHP version 8.2
 */

declare(strict_types=1);

$registryPath = dirname(__DIR__) . '/data/curated_dictionaries.json';

// --- CLI argument parsing ---
$opts = getopt('', ['language:', 'json', 'fix', 'timeout:', 'help']);

if (isset($opts['help'])) {
    echo <<<USAGE
    Usage: php bin/check-dictionaries.php [OPTIONS]

    Options:
      --language=CODE    Check only dictionaries for this language (e.g., fr, de, ja)
      --json             Output results as JSON
      --fix              Update _lastVerified date in registry on full success
      --timeout=SECONDS  HTTP timeout per request (default: 10)
      --help             Show this help

    Exit codes:
      0  All dictionaries reachable
      1  One or more dictionaries have issues
      2  Registry file missing or invalid JSON

    USAGE;
    exit(0);
}

$filterLanguage = $opts['language'] ?? null;
$jsonOutput = isset($opts['json']);
$fixMode = isset($opts['fix']);
$timeout = (int) ($opts['timeout'] ?? 10);

// --- Load registry ---
if (!file_exists($registryPath)) {
    fwrite(STDERR, "ERROR: Registry not found at $registryPath\n");
    exit(2);
}

$raw = file_get_contents($registryPath);
$registry = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);

if (!isset($registry['dictionaries']) || !is_array($registry['dictionaries'])) {
    fwrite(STDERR, "ERROR: Invalid registry format (missing 'dictionaries' array)\n");
    exit(2);
}

// --- Check dictionaries ---

/** @var array<int, array{language: string, languageName: string, name: string, url: string, status: string, details: string, httpCode: ?int}> */
$results = [];
$hasFailure = false;

foreach ($registry['dictionaries'] as $langGroup) {
    $langCode = $langGroup['language'] ?? '??';
    $langName = $langGroup['languageName'] ?? $langCode;

    if ($filterLanguage !== null && $langCode !== $filterLanguage) {
        continue;
    }

    foreach ($langGroup['sources'] as $source) {
        $name = $source['name'];
        $url = $source['url'];

        $result = [
            'language' => $langCode,
            'languageName' => $langName,
            'name' => $name,
            'url' => $url,
            'format' => $source['format'] ?? '',
            'status' => 'unknown',
            'details' => '',
            'httpCode' => null,
        ];

        // URLs pointing to project/release pages (not direct downloads)
        // are checked as normal web pages
        $isDirectDownload = (bool) preg_match('/\.(tar\.gz|zip|gz|bz2|ifo|dict|idx)$/i', $url);

        // Try HEAD first (avoids downloading large files)
        $httpCode = httpHead($url, $timeout);

        // Some servers reject HEAD (405, 403, 0); fall back to GET with range
        if ($httpCode === 0 || $httpCode === 405 || $httpCode === 403) {
            $httpCode = httpGetCheck($url, $timeout);
        }

        $result['httpCode'] = $httpCode;

        if ($httpCode === 0) {
            $result['status'] = 'error';
            $result['details'] = 'Connection failed';
            $hasFailure = true;
        } elseif ($httpCode >= 200 && $httpCode < 400) {
            $result['status'] = 'ok';
            $result['details'] = "HTTP $httpCode";
        } elseif ($httpCode === 404) {
            $result['status'] = 'error';
            $result['details'] = 'HTTP 404 Not Found';
            $hasFailure = true;
        } else {
            $result['status'] = 'error';
            $result['details'] = "HTTP $httpCode";
            $hasFailure = true;
        }

        $results[] = $result;
    }
}

// --- Output ---
if ($jsonOutput) {
    echo json_encode([
        'checkedAt' => date('c'),
        'totalDictionaries' => count($results),
        'healthy' => count(array_filter($results, fn($r) => $r['status'] === 'ok')),
        'errors' => count(array_filter($results, fn($r) => $r['status'] === 'error')),
        'results' => $results,
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n";
} else {
    $statusIcons = [
        'ok' => "\033[32m✓\033[0m",
        'error' => "\033[31m✗\033[0m",
        'unknown' => '?',
    ];

    $maxNameLen = 0;
    foreach ($results as $r) {
        $maxNameLen = max($maxNameLen, mb_strlen($r['name']));
    }
    $maxNameLen = min($maxNameLen, 45);

    echo "\nCurated Dictionary Health Check\n";
    echo str_repeat('=', 70) . "\n\n";

    $currentLang = '';
    foreach ($results as $r) {
        if ($r['languageName'] !== $currentLang) {
            $currentLang = $r['languageName'];
            echo "\033[1m{$currentLang} ({$r['language']})\033[0m\n";
        }

        $icon = $statusIcons[$r['status']];
        $name = mb_str_pad($r['name'], $maxNameLen);
        echo "  $icon $name  {$r['details']}\n";
    }

    // Summary
    $total = count($results);
    $ok = count(array_filter($results, fn($r) => $r['status'] === 'ok'));
    $err = count(array_filter($results, fn($r) => $r['status'] === 'error'));

    echo "\n" . str_repeat('-', 70) . "\n";
    echo "Total: $total  ";
    echo "\033[32mHealthy: $ok\033[0m  ";
    if ($err > 0) {
        echo "\033[31mErrors: $err\033[0m";
    }
    echo "\n\n";
}

// --- Fix mode: update lastVerified ---
if ($fixMode && !$hasFailure) {
    $registry['_lastVerified'] = date('Y-m-d');
    $json = json_encode($registry, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    file_put_contents($registryPath, $json . "\n");
    if (!$jsonOutput) {
        echo "Updated _lastVerified to " . date('Y-m-d') . "\n";
    }
}

exit($hasFailure ? 1 : 0);

// --- Helper functions ---

/**
 * HTTP HEAD request, returns status code (0 on failure).
 */
function httpHead(string $url, int $timeout): int
{
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_NOBODY => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_MAXREDIRS => 5,
        CURLOPT_TIMEOUT => $timeout,
        CURLOPT_CONNECTTIMEOUT => $timeout,
        CURLOPT_USERAGENT => 'LWT-DictChecker/1.0 (+https://github.com/HugoFara/lwt)',
        CURLOPT_SSL_VERIFYPEER => true,
    ]);

    curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return $code;
}

/**
 * HTTP GET request with early abort (downloads at most 1KB), returns status code.
 */
function httpGetCheck(string $url, int $timeout): int
{
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_MAXREDIRS => 5,
        CURLOPT_TIMEOUT => $timeout,
        CURLOPT_CONNECTTIMEOUT => $timeout,
        CURLOPT_USERAGENT => 'LWT-DictChecker/1.0 (+https://github.com/HugoFara/lwt)',
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_RANGE => '0-1023',      // Request only first 1KB
        CURLOPT_ENCODING => '',
    ]);

    curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    // 206 = partial content (range honored), treat as success
    if ($code === 206) {
        return 200;
    }
    return $code;
}

/**
 * Multi-byte str_pad (mb_str_pad is PHP 8.3+, so polyfill for 8.1 compat).
 */
if (!function_exists('mb_str_pad')) {
    function mb_str_pad(
        string $input,
        int $length,
        string $pad = ' ',
        int $type = STR_PAD_RIGHT
    ): string {
        $diff = $length - mb_strlen($input);
        if ($diff <= 0) {
            return $input;
        }
        $padding = str_repeat($pad, $diff);
        return match ($type) {
            STR_PAD_LEFT => $padding . $input,
            STR_PAD_BOTH => str_repeat($pad, (int) ($diff / 2)) . $input .
                str_repeat($pad, (int) ceil($diff / 2)),
            default => $input . $padding,
        };
    }
}
