#!/usr/bin/env php
<?php

/**
 * Locale Completion Checker
 *
 * Compares each locale under locale/<lang>/ against the English reference
 * (locale/en/) and reports how many translation keys are present.
 *
 * Usage:
 *   php bin/check-locales.php                    # Human-readable table
 *   php bin/check-locales.php --json             # Machine-readable JSON
 *   php bin/check-locales.php --badges=DIR       # Write shields.io endpoint
 *                                                # JSON files to DIR
 *   php bin/check-locales.php --fail-under=90    # Exit 1 if any non-en
 *                                                # locale is below 90%
 *
 * Exit codes:
 *   0 = success (all locales meet --fail-under threshold, or none given)
 *   1 = one or more locales below threshold
 *   2 = locale/en/ missing or unreadable
 *
 * PHP version 8.2
 */

declare(strict_types=1);

$localeRoot = dirname(__DIR__) . '/locale';
$referenceLocale = 'en';

$asJson = false;
$badgesDir = null;
$failUnder = null;
foreach (array_slice($argv, 1) as $arg) {
    if ($arg === '--json') {
        $asJson = true;
    } elseif (str_starts_with($arg, '--badges=')) {
        $badgesDir = substr($arg, strlen('--badges='));
    } elseif (str_starts_with($arg, '--fail-under=')) {
        $failUnder = (float)substr($arg, strlen('--fail-under='));
    } else {
        fwrite(STDERR, "Unknown argument: $arg\n");
        exit(2);
    }
}

$referencePath = $localeRoot . '/' . $referenceLocale;
if (!is_dir($referencePath)) {
    fwrite(STDERR, "Reference locale directory not found: $referencePath\n");
    exit(2);
}

// Load reference key set per namespace.
$reference = [];
$referenceTotal = 0;
foreach (glob($referencePath . '/*.json') ?: [] as $file) {
    $namespace = basename($file, '.json');
    $decoded = json_decode((string)file_get_contents($file), true);
    if (!is_array($decoded)) {
        fwrite(STDERR, "Invalid JSON in reference file: $file\n");
        exit(2);
    }
    $reference[$namespace] = array_keys($decoded);
    $referenceTotal += count($decoded);
}

// Discover all locale directories.
$locales = [];
foreach (glob($localeRoot . '/*', GLOB_ONLYDIR) ?: [] as $dir) {
    $locales[] = basename($dir);
}
sort($locales);

$results = [];
foreach ($locales as $locale) {
    $localePath = $localeRoot . '/' . $locale;
    $translated = 0;
    $namespaces = [];
    foreach ($reference as $namespace => $refKeys) {
        $nsFile = $localePath . '/' . $namespace . '.json';
        $present = 0;
        $missing = [];
        if (is_file($nsFile)) {
            $decoded = json_decode((string)file_get_contents($nsFile), true);
            if (is_array($decoded)) {
                foreach ($refKeys as $key) {
                    if (array_key_exists($key, $decoded) && $decoded[$key] !== '') {
                        $present++;
                    } else {
                        $missing[] = $key;
                    }
                }
            } else {
                $missing = $refKeys;
            }
        } else {
            $missing = $refKeys;
        }
        $translated += $present;
        $namespaces[$namespace] = [
            'total' => count($refKeys),
            'translated' => $present,
            'missing' => $missing,
        ];
    }
    $percent = $referenceTotal > 0
        ? round($translated * 100 / $referenceTotal, 1)
        : 100.0;
    $results[$locale] = [
        'locale' => $locale,
        'total' => $referenceTotal,
        'translated' => $translated,
        'percent' => $percent,
        'namespaces' => $namespaces,
    ];
}

// Optional badges output (shields.io endpoint format).
if ($badgesDir !== null) {
    if (!is_dir($badgesDir) && !mkdir($badgesDir, 0755, true) && !is_dir($badgesDir)) {
        fwrite(STDERR, "Could not create badges directory: $badgesDir\n");
        exit(2);
    }
    foreach ($results as $locale => $data) {
        $percent = $data['percent'];
        $color = match (true) {
            $percent >= 100 => 'brightgreen',
            $percent >= 90  => 'green',
            $percent >= 75  => 'yellowgreen',
            $percent >= 50  => 'yellow',
            $percent >= 25  => 'orange',
            default         => 'red',
        };
        $badge = [
            'schemaVersion' => 1,
            'label' => 'locale ' . $locale,
            // number_format() guarantees the decimal point, so trimming
            // trailing zeros cannot eat a significant one (100.0 -> "1").
            'message' => rtrim(rtrim(number_format($percent, 1, '.', ''), '0'), '.') . '%',
            'color' => $color,
        ];
        file_put_contents(
            $badgesDir . '/locale-' . $locale . '.json',
            json_encode($badge, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n"
        );
    }
}

// Output.
if ($asJson) {
    echo json_encode($results, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n";
} else {
    printf("Locale completion (reference: %s, %d keys)\n\n", $referenceLocale, $referenceTotal);
    printf("  %-8s %8s %8s   %s\n", 'Locale', 'Keys', 'Percent', 'Bar');
    printf("  %s\n", str_repeat('-', 50));
    foreach ($results as $data) {
        $bar = str_repeat('#', (int)round($data['percent'] / 5))
            . str_repeat('.', 20 - (int)round($data['percent'] / 5));
        printf(
            "  %-8s %8d %7s%%   %s\n",
            $data['locale'],
            $data['translated'],
            number_format($data['percent'], 1),
            $bar
        );
    }
    echo "\n";
}

// Threshold check.
if ($failUnder !== null) {
    $failed = [];
    foreach ($results as $locale => $data) {
        if ($locale === $referenceLocale) {
            continue;
        }
        if ($data['percent'] < $failUnder) {
            $failed[] = sprintf('%s (%.1f%%)', $locale, $data['percent']);
        }
    }
    if ($failed !== []) {
        fwrite(STDERR, "Locales below {$failUnder}%: " . implode(', ', $failed) . "\n");
        exit(1);
    }
}

exit(0);
