#!/usr/bin/env php
<?php

/**
 * Curated Feed Health Checker
 *
 * Validates that curated RSS/Atom feeds in data/curated_feeds.json are still
 * reachable, parseable, and returning fresh content. Designed to run in CI
 * or as a periodic cron job to catch link rot and format changes early.
 *
 * Usage:
 *   php bin/check-feeds.php                  # Check all feeds
 *   php bin/check-feeds.php --language=fr    # Check only French feeds
 *   php bin/check-feeds.php --json           # Machine-readable JSON output
 *   php bin/check-feeds.php --fix            # Update lastVerified on success
 *   php bin/check-feeds.php --sample-article # Also fetch one article per feed
 *   php bin/check-feeds.php --timeout=15     # HTTP timeout in seconds (default 10)
 *
 * Exit codes:
 *   0 = all feeds healthy
 *   1 = one or more feeds have issues
 *   2 = registry file missing or invalid
 *
 * PHP version 8.2
 */

declare(strict_types=1);

$registryPath = dirname(__DIR__) . '/data/curated_feeds.json';

// --- CLI argument parsing ---
$opts = getopt('', ['language:', 'json', 'fix', 'sample-article', 'timeout:', 'help']);

if (isset($opts['help'])) {
    echo <<<USAGE
    Usage: php bin/check-feeds.php [OPTIONS]

    Options:
      --language=CODE    Check only feeds for this language (e.g., fr, de, ja)
      --json             Output results as JSON
      --fix              Update _lastVerified date in registry on full success
      --sample-article   Fetch one article link per feed to test selector extraction
      --timeout=SECONDS  HTTP timeout per request (default: 10)
      --help             Show this help

    Exit codes:
      0  All feeds healthy
      1  One or more feeds have issues
      2  Registry file missing or invalid JSON

    USAGE;
    exit(0);
}

$filterLanguage = $opts['language'] ?? null;
$jsonOutput = isset($opts['json']);
$fixMode = isset($opts['fix']);
$sampleArticle = isset($opts['sample-article']);
$timeout = (int) ($opts['timeout'] ?? 10);

// --- Load registry ---
if (!file_exists($registryPath)) {
    fwrite(STDERR, "ERROR: Registry not found at $registryPath\n");
    exit(2);
}

$raw = file_get_contents($registryPath);
$registry = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);

if (!isset($registry['feeds']) || !is_array($registry['feeds'])) {
    fwrite(STDERR, "ERROR: Invalid registry format (missing 'feeds' array)\n");
    exit(2);
}

// --- Check feeds ---

/** @var array<int, array{language: string, name: string, url: string, status: string, details: string, items: ?int, latestDate: ?string}> */
$results = [];
$hasFailure = false;

foreach ($registry['feeds'] as $langGroup) {
    $langCode = $langGroup['language'] ?? '??';
    $langName = $langGroup['languageName'] ?? $langCode;

    if ($filterLanguage !== null && $langCode !== $filterLanguage) {
        continue;
    }

    foreach ($langGroup['sources'] as $source) {
        $name = $source['name'];
        $url = $source['url'];
        $note = $source['_note'] ?? null;

        $result = [
            'language' => $langCode,
            'languageName' => $langName,
            'name' => $name,
            'url' => $url,
            'status' => 'unknown',
            'details' => '',
            'httpCode' => null,
            'items' => null,
            'latestDate' => null,
        ];

        // Skip feeds with known non-RSS format
        if ($note !== null && str_contains($note, 'not RSS')) {
            $result['status'] = 'skipped';
            $result['details'] = $note;
            $results[] = $result;
            continue;
        }

        // 1. HTTP fetch
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS => 5,
            CURLOPT_TIMEOUT => $timeout,
            CURLOPT_CONNECTTIMEOUT => $timeout,
            CURLOPT_USERAGENT => 'LWT-FeedChecker/1.0 (+https://github.com/HugoFara/lwt)',
            CURLOPT_ENCODING => '',  // accept compressed
            CURLOPT_SSL_VERIFYPEER => true,
        ]);

        $body = curl_exec($ch);
        $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        curl_close($ch);

        $result['httpCode'] = $httpCode;

        if ($body === false || $curlError !== '') {
            $result['status'] = 'error';
            $result['details'] = "Connection failed: $curlError";
            $hasFailure = true;
            $results[] = $result;
            continue;
        }

        if ($httpCode < 200 || $httpCode >= 400) {
            $result['status'] = 'error';
            $result['details'] = "HTTP $httpCode";
            $hasFailure = true;
            $results[] = $result;
            continue;
        }

        if (empty(trim($body))) {
            $result['status'] = 'error';
            $result['details'] = "HTTP $httpCode but empty body";
            $hasFailure = true;
            $results[] = $result;
            continue;
        }

        // 2. XML parse
        $dom = new DOMDocument('1.0', 'utf-8');
        $prevErrors = libxml_use_internal_errors(true);
        $parsed = @$dom->loadXML($body, LIBXML_NOCDATA | LIBXML_NONET);
        $xmlErrors = libxml_get_errors();
        libxml_clear_errors();
        libxml_use_internal_errors($prevErrors);

        if (!$parsed) {
            $result['status'] = 'error';
            $errMsg = !empty($xmlErrors) ? $xmlErrors[0]->message : 'Unknown XML error';
            $result['details'] = 'XML parse failed: ' . trim($errMsg);
            $hasFailure = true;
            $results[] = $result;
            continue;
        }

        // 3. Detect feed format
        $root = $dom->documentElement;
        if ($root === null) {
            $result['status'] = 'error';
            $result['details'] = 'Empty XML document';
            $hasFailure = true;
            $results[] = $result;
            continue;
        }

        $rootTag = strtolower($root->localName);
        $isRss = ($rootTag === 'rss' || $rootTag === 'rdf');
        $isAtom = ($rootTag === 'feed');

        if (!$isRss && !$isAtom) {
            $result['status'] = 'error';
            $result['details'] = "Unknown feed format: <$rootTag>";
            $hasFailure = true;
            $results[] = $result;
            continue;
        }

        // 4. Count items and find latest date
        $itemTag = $isAtom ? 'entry' : 'item';
        $dateTag = $isAtom ? 'published' : 'pubDate';
        $altDateTag = $isAtom ? 'updated' : null;

        $items = $dom->getElementsByTagName($itemTag);
        $itemCount = $items->length;
        $result['items'] = $itemCount;

        if ($itemCount === 0) {
            $result['status'] = 'warning';
            $result['details'] = 'Feed parsed but contains 0 items';
            $hasFailure = true;
            $results[] = $result;
            continue;
        }

        // Find latest date from first few items
        $latestTimestamp = 0;
        $checkCount = min($itemCount, 5);
        for ($i = 0; $i < $checkCount; $i++) {
            $item = $items->item($i);
            $dateNodes = $item->getElementsByTagName($dateTag);
            if ($dateNodes->length === 0 && $altDateTag !== null) {
                $dateNodes = $item->getElementsByTagName($altDateTag);
            }
            if ($dateNodes->length > 0) {
                $ts = strtotime($dateNodes->item(0)->textContent);
                if ($ts !== false && $ts > $latestTimestamp) {
                    $latestTimestamp = $ts;
                }
            }
        }

        if ($latestTimestamp > 0) {
            $result['latestDate'] = date('Y-m-d', $latestTimestamp);
            $daysSinceUpdate = (int) ((time() - $latestTimestamp) / 86400);

            if ($daysSinceUpdate > 90) {
                $result['status'] = 'warning';
                $result['details'] = "Stale: last item is {$daysSinceUpdate} days old";
                $hasFailure = true;
                $results[] = $result;
                continue;
            }
        }

        // 5. Optional: test article selector on first article link
        if ($sampleArticle && !empty($source['articleSectionTags'])) {
            $linkTag = $isAtom ? 'link' : 'link';
            $firstItem = $items->item(0);
            $linkNodes = $firstItem->getElementsByTagName('link');
            $articleUrl = null;

            if ($linkNodes->length > 0) {
                $linkNode = $linkNodes->item(0);
                // Atom uses href attribute, RSS uses text content
                $articleUrl = $isAtom
                    ? $linkNode->getAttribute('href')
                    : trim($linkNode->textContent);
            }

            if ($articleUrl !== null && $articleUrl !== '') {
                $selectorResult = testArticleSelector(
                    $articleUrl,
                    $source['articleSectionTags'],
                    $timeout
                );
                if ($selectorResult !== null) {
                    $result['selectorTest'] = $selectorResult;
                    if ($selectorResult['status'] === 'error') {
                        $result['status'] = 'warning';
                        $result['details'] = 'Selector issue: ' . $selectorResult['details'];
                    }
                }
            }
        }

        // If we got here with no issues, it's healthy
        if ($result['status'] === 'unknown') {
            $result['status'] = 'ok';
            $result['details'] = "$itemCount items" .
                ($result['latestDate'] ? ", latest: {$result['latestDate']}" : '');
        }

        $results[] = $result;
    }
}

// --- Output ---
if ($jsonOutput) {
    echo json_encode([
        'checkedAt' => date('c'),
        'totalFeeds' => count($results),
        'healthy' => count(array_filter($results, fn($r) => $r['status'] === 'ok')),
        'warnings' => count(array_filter($results, fn($r) => $r['status'] === 'warning')),
        'errors' => count(array_filter($results, fn($r) => $r['status'] === 'error')),
        'skipped' => count(array_filter($results, fn($r) => $r['status'] === 'skipped')),
        'results' => $results,
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n";
} else {
    $statusIcons = [
        'ok' => "\033[32m✓\033[0m",
        'warning' => "\033[33m⚠\033[0m",
        'error' => "\033[31m✗\033[0m",
        'skipped' => "\033[90m-\033[0m",
        'unknown' => '?',
    ];

    $maxNameLen = 0;
    foreach ($results as $r) {
        $maxNameLen = max($maxNameLen, mb_strlen($r['name']));
    }
    $maxNameLen = min($maxNameLen, 40);

    echo "\nCurated Feed Health Check\n";
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
    $warn = count(array_filter($results, fn($r) => $r['status'] === 'warning'));
    $err = count(array_filter($results, fn($r) => $r['status'] === 'error'));
    $skip = count(array_filter($results, fn($r) => $r['status'] === 'skipped'));

    echo "\n" . str_repeat('-', 70) . "\n";
    echo "Total: $total  ";
    echo "\033[32mHealthy: $ok\033[0m  ";
    if ($warn > 0) {
        echo "\033[33mWarnings: $warn\033[0m  ";
    }
    if ($err > 0) {
        echo "\033[31mErrors: $err\033[0m  ";
    }
    if ($skip > 0) {
        echo "\033[90mSkipped: $skip\033[0m";
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
 * Test whether an article's HTML matches the configured XPath selector.
 *
 * @param string $url            Article URL to fetch
 * @param string $selectorTag    Tag name to look for (e.g., "article")
 * @param int    $timeout        HTTP timeout in seconds
 *
 * @return array{status: string, details: string, contentLength: ?int}|null
 */
function testArticleSelector(
    string $url,
    string $selectorTag,
    int $timeout
): ?array {
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_MAXREDIRS => 5,
        CURLOPT_TIMEOUT => $timeout,
        CURLOPT_CONNECTTIMEOUT => $timeout,
        CURLOPT_USERAGENT => 'LWT-FeedChecker/1.0 (+https://github.com/HugoFara/lwt)',
        CURLOPT_ENCODING => '',
        CURLOPT_SSL_VERIFYPEER => true,
    ]);

    $html = curl_exec($ch);
    $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($html === false || $httpCode >= 400) {
        return [
            'status' => 'error',
            'details' => "Article fetch failed (HTTP $httpCode)",
            'contentLength' => null,
        ];
    }

    $dom = new DOMDocument();
    $prevErrors = libxml_use_internal_errors(true);
    @$dom->loadHTML($html, LIBXML_NOERROR | LIBXML_NONET);
    libxml_clear_errors();
    libxml_use_internal_errors($prevErrors);

    // Simple tag-based selector (matches the app's articleSectionTags field)
    $elements = $dom->getElementsByTagName($selectorTag);

    if ($elements->length === 0) {
        return [
            'status' => 'error',
            'details' => "Selector <$selectorTag> matched 0 elements",
            'contentLength' => null,
        ];
    }

    $content = '';
    for ($i = 0; $i < $elements->length; $i++) {
        $content .= $dom->saveHTML($elements->item($i));
    }

    $textLength = mb_strlen(strip_tags($content));

    if ($textLength < 50) {
        return [
            'status' => 'error',
            'details' => "Selector <$selectorTag> matched but text too short ({$textLength} chars)",
            'contentLength' => $textLength,
        ];
    }

    return [
        'status' => 'ok',
        'details' => "Selector OK ({$textLength} chars extracted)",
        'contentLength' => $textLength,
    ];
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
