<?php

declare(strict_types=1);

namespace Lwt\Tests\Core\Http;

use Lwt\Shared\Infrastructure\Http\UrlUtilities;
use PHPUnit\Framework\TestCase;

/**
 * Tests for UrlUtilities class
 */
final class UrlUtilitiesTest extends TestCase
{
    /**
     * Test if the language from dictionary feature is properly working.
     */
    public function testLangFromDict(): void
    {
        $urls = [
            'http://translate.google.com/lwt_term?ie=UTF-8&sl=ar&tl=en&text=&lwt_popup=true',
            'http://localhost/lwt/ggl.php/?sl=ar&tl=hr&text=',
            'http://localhost:5000/?lwt_translator=libretranslate&source=ar&target=en&q=lwt_term',
            'ggl.php?sl=ar&tl=en&text=###'
        ];
        foreach ($urls as $url) {
            $this->assertSame("ar", UrlUtilities::langFromDict($url));
        }
    }

    /**
     * Test langFromDict with edge cases
     */
    public function testLangFromDictEdgeCases(): void
    {
        // URL without language parameter
        $this->assertEquals('', UrlUtilities::langFromDict('http://example.com/page.php'));

        // Malformed URL
        $this->assertEquals('', UrlUtilities::langFromDict('not-a-url'));

        // Multiple sl parameters (parse_str uses the last one)
        $url = 'http://example.com/?sl=en&sl=fr';
        $result = UrlUtilities::langFromDict($url);
        // parse_str uses the last value when there are duplicates
        $this->assertEquals('fr', $result);

        // URL with fragment
        $this->assertEquals('de', UrlUtilities::langFromDict('http://example.com/?sl=de#fragment'));

        // Case sensitivity - query parameters are case-sensitive
        // 'SL' is different from 'sl', so this should return empty
        $this->assertEquals('', UrlUtilities::langFromDict('http://example.com/?SL=en'));
    }

    /**
     * Test URL base extraction
     */
    public function testUrlBase(): void
    {
        // Mock server variables for testing
        $_SERVER['HTTP_HOST'] = 'localhost';
        $_SERVER['REQUEST_URI'] = '/lwt/index.php';

        $base = UrlUtilities::urlBase();
        $this->assertStringStartsWith('http://', $base);
        $this->assertStringEndsWith('/', $base);
        $this->assertStringContainsString('localhost', $base);
    }

    /**
     * Test urlBase with different server configurations
     */
    public function testUrlBaseVariousConfigurations(): void
    {
        // Save original values
        $origHost = $_SERVER['HTTP_HOST'] ?? null;
        $origUri = $_SERVER['REQUEST_URI'] ?? null;
        $origHttps = $_SERVER['HTTPS'] ?? null;

        // Test with HTTPS
        $_SERVER['HTTPS'] = 'on';
        $_SERVER['HTTP_HOST'] = 'secure.example.com';
        $_SERVER['REQUEST_URI'] = '/lwt/page.php';

        $base = UrlUtilities::urlBase();
        $this->assertStringStartsWith('https://', $base);
        $this->assertStringContainsString('secure.example.com', $base);

        // Test without HTTPS
        $_SERVER['HTTPS'] = 'off';
        $_SERVER['HTTP_HOST'] = 'example.com';
        $_SERVER['REQUEST_URI'] = '/test/index.php';

        $base = UrlUtilities::urlBase();
        $this->assertStringStartsWith('http://', $base);
        $this->assertStringContainsString('example.com', $base);

        // Test with port number
        $_SERVER['HTTP_HOST'] = 'localhost:8080';
        $_SERVER['REQUEST_URI'] = '/lwt/index.php';

        $base = UrlUtilities::urlBase();
        $this->assertStringContainsString('localhost:8080', $base);

        // Restore original values
        if ($origHost !== null) {
            $_SERVER['HTTP_HOST'] = $origHost;
        }
        if ($origUri !== null) {
            $_SERVER['REQUEST_URI'] = $origUri;
        }
        if ($origHttps !== null) {
            $_SERVER['HTTPS'] = $origHttps;
        }
    }

    /**
     * Test target language extraction from dictionary URL
     */
    public function testTargetLangFromDict(): void
    {
        // Google Translate URLs
        $googleUrl = 'http://translate.google.com/?sl=ar&tl=en&text=test';
        $this->assertEquals('en', UrlUtilities::targetLangFromDict($googleUrl));

        $localGglUrl = 'http://localhost/ggl.php?sl=ar&tl=fr&text=';
        $this->assertEquals('fr', UrlUtilities::targetLangFromDict($localGglUrl));

        // LibreTranslate URLs
        $libreTranslateUrl = 'http://localhost:5000/?lwt_translator=libretranslate&source=ar&target=en&q=test';
        $this->assertEquals('en', UrlUtilities::targetLangFromDict($libreTranslateUrl));

        // Empty URL
        $this->assertEquals('', UrlUtilities::targetLangFromDict(''));
    }

    /**
     * Test targetLangFromDict with edge cases
     */
    public function testTargetLangFromDictEdgeCases(): void
    {
        // URL without target parameter
        $this->assertEquals('', UrlUtilities::targetLangFromDict('http://example.com/page.php'));

        // Malformed URL
        $this->assertEquals('', UrlUtilities::targetLangFromDict('not-a-url'));

        // URL with only source language
        $this->assertEquals('', UrlUtilities::targetLangFromDict('http://example.com/?sl=en'));

        // LibreTranslate without target
        $this->assertEquals('', UrlUtilities::targetLangFromDict('http://localhost:5000/?source=en'));

        // URL with fragment
        $this->assertEquals('es', UrlUtilities::targetLangFromDict('http://example.com/?tl=es#fragment'));
    }

    /**
     * Test SSRF protection blocks private IPv4 addresses
     */
    public function testValidateUrlBlocksPrivateIpv4(): void
    {
        // 10.0.0.0/8 range
        $result = UrlUtilities::validateUrlForFetch('http://10.0.0.1/secret');
        $this->assertFalse($result['valid']);
        $this->assertStringContainsString('private/reserved', $result['error']);

        // 172.16.0.0/12 range
        $result = UrlUtilities::validateUrlForFetch('http://172.16.0.1/admin');
        $this->assertFalse($result['valid']);

        $result = UrlUtilities::validateUrlForFetch('http://172.31.255.255/');
        $this->assertFalse($result['valid']);

        // 192.168.0.0/16 range
        $result = UrlUtilities::validateUrlForFetch('http://192.168.1.1/config');
        $this->assertFalse($result['valid']);
    }

    /**
     * Test SSRF protection blocks loopback addresses
     */
    public function testValidateUrlBlocksLoopback(): void
    {
        // 127.0.0.0/8 range
        $result = UrlUtilities::validateUrlForFetch('http://127.0.0.1/');
        $this->assertFalse($result['valid']);

        $result = UrlUtilities::validateUrlForFetch('http://127.1.2.3/');
        $this->assertFalse($result['valid']);

        // IPv6 loopback. Must be blocked by IP-literal detection, not by DNS
        // timeout: an earlier version stripped no brackets, fell through to
        // dns_get_record, and only "passed" when the lookup eventually failed.
        $result = UrlUtilities::validateUrlForFetch('http://[::1]/');
        $this->assertFalse($result['valid']);
        $this->assertSame('::1', $result['resolved_ip'] ?? null);
    }

    /**
     * Test SSRF protection blocks localhost and internal hostnames
     */
    public function testValidateUrlBlocksInternalHostnames(): void
    {
        $result = UrlUtilities::validateUrlForFetch('http://localhost/admin');
        $this->assertFalse($result['valid']);
        $this->assertStringContainsString('Internal hostname', $result['error']);

        $result = UrlUtilities::validateUrlForFetch('http://localhost.localdomain/');
        $this->assertFalse($result['valid']);

        // .local suffix
        $result = UrlUtilities::validateUrlForFetch('http://myserver.local/');
        $this->assertFalse($result['valid']);
        $this->assertStringContainsString('Internal domain', $result['error']);

        // .internal suffix
        $result = UrlUtilities::validateUrlForFetch('http://api.internal/');
        $this->assertFalse($result['valid']);

        // .localhost suffix
        $result = UrlUtilities::validateUrlForFetch('http://app.localhost/');
        $this->assertFalse($result['valid']);
    }

    /**
     * Test SSRF protection blocks non-HTTP schemes
     */
    public function testValidateUrlBlocksNonHttpSchemes(): void
    {
        $result = UrlUtilities::validateUrlForFetch('file:///etc/passwd');
        $this->assertFalse($result['valid']);
        $this->assertStringContainsString('HTTP and HTTPS', $result['error']);

        $result = UrlUtilities::validateUrlForFetch('ftp://ftp.example.com/file.txt');
        $this->assertFalse($result['valid']);

        $result = UrlUtilities::validateUrlForFetch('gopher://gopher.example.com/');
        $this->assertFalse($result['valid']);

        $result = UrlUtilities::validateUrlForFetch('dict://dict.example.com/');
        $this->assertFalse($result['valid']);
    }

    /**
     * Test SSRF protection rejects invalid URLs
     */
    public function testValidateUrlRejectsInvalidUrls(): void
    {
        // Empty URL
        $result = UrlUtilities::validateUrlForFetch('');
        $this->assertFalse($result['valid']);
        $this->assertStringContainsString('Empty', $result['error']);

        // Whitespace only
        $result = UrlUtilities::validateUrlForFetch('   ');
        $this->assertFalse($result['valid']);

        // No host
        $result = UrlUtilities::validateUrlForFetch('http://');
        $this->assertFalse($result['valid']);

        // Malformed URL
        $result = UrlUtilities::validateUrlForFetch('not-a-url');
        $this->assertFalse($result['valid']);
    }

    /**
     * Test SSRF protection blocks link-local addresses
     */
    public function testValidateUrlBlocksLinkLocal(): void
    {
        // 169.254.0.0/16 range (link-local)
        $result = UrlUtilities::validateUrlForFetch('http://169.254.169.254/');
        $this->assertFalse($result['valid']);

        // AWS metadata endpoint
        $result = UrlUtilities::validateUrlForFetch('http://169.254.169.254/latest/meta-data/');
        $this->assertFalse($result['valid']);
    }

    /**
     * Test SSRF protection blocks reserved addresses
     */
    public function testValidateUrlBlocksReservedAddresses(): void
    {
        // 0.0.0.0/8 range
        $result = UrlUtilities::validateUrlForFetch('http://0.0.0.0/');
        $this->assertFalse($result['valid']);

        // Multicast 224.0.0.0/4
        $result = UrlUtilities::validateUrlForFetch('http://224.0.0.1/');
        $this->assertFalse($result['valid']);

        // Reserved 240.0.0.0/4
        $result = UrlUtilities::validateUrlForFetch('http://240.0.0.1/');
        $this->assertFalse($result['valid']);
    }

    /**
     * Snapshot and restore the bits of $_SERVER and $_ENV that the
     * proxy-scoped tests below mutate. Each test calls this in its
     * setup arrow and reverses it before returning so the static caches
     * inside `UrlUtilities` (basePath / appUrl / trustProxy) don't leak
     * into unrelated tests.
     *
     * @return array{server: array<string, mixed>, env: array<string, mixed>}
     */
    private function snapshotEnv(): array
    {
        $keys = [
            'HTTPS', 'HTTP_X_FORWARDED_PROTO', 'HTTP_X_FORWARDED_HOST',
            'HTTP_X_FORWARDED_SSL', 'SERVER_PORT', 'HTTP_HOST',
        ];
        $server = [];
        foreach ($keys as $k) {
            $server[$k] = $_SERVER[$k] ?? null;
        }
        $env = [
            'TRUST_PROXY' => $_ENV['TRUST_PROXY'] ?? null,
            'APP_URL' => $_ENV['APP_URL'] ?? null,
        ];
        return ['server' => $server, 'env' => $env];
    }

    /**
     * @param array{server: array<string, mixed>, env: array<string, mixed>} $snap
     */
    private function restoreEnv(array $snap): void
    {
        foreach ($snap['server'] as $k => $v) {
            if ($v === null) {
                unset($_SERVER[$k]);
            } else {
                $_SERVER[$k] = $v;
            }
        }
        foreach ($snap['env'] as $k => $v) {
            if ($v === null) {
                unset($_ENV[$k]);
                putenv($k);
            } else {
                $_ENV[$k] = $v;
                putenv($k . '=' . $v);
            }
        }
        UrlUtilities::resetBasePath();
    }

    public function testIsSecureRequestDirectHttps(): void
    {
        $snap = $this->snapshotEnv();
        try {
            unset($_SERVER['HTTP_X_FORWARDED_PROTO'], $_SERVER['SERVER_PORT']);
            $_SERVER['HTTPS'] = 'on';
            UrlUtilities::resetBasePath();
            $this->assertTrue(UrlUtilities::isSecureRequest());
        } finally {
            $this->restoreEnv($snap);
        }
    }

    public function testIsSecureRequestPort443(): void
    {
        $snap = $this->snapshotEnv();
        try {
            unset($_SERVER['HTTPS'], $_SERVER['HTTP_X_FORWARDED_PROTO']);
            $_SERVER['SERVER_PORT'] = '443';
            UrlUtilities::resetBasePath();
            $this->assertTrue(UrlUtilities::isSecureRequest());
        } finally {
            $this->restoreEnv($snap);
        }
    }

    public function testIsSecureRequestForwardedProtoTrustedByDefault(): void
    {
        $snap = $this->snapshotEnv();
        try {
            unset($_SERVER['HTTPS'], $_SERVER['SERVER_PORT'], $_ENV['TRUST_PROXY']);
            putenv('TRUST_PROXY');
            $_SERVER['HTTP_X_FORWARDED_PROTO'] = 'https';
            UrlUtilities::resetBasePath();
            $this->assertTrue(UrlUtilities::isSecureRequest());
        } finally {
            $this->restoreEnv($snap);
        }
    }

    public function testIsSecureRequestForwardedProtoIgnoredWhenOptedOut(): void
    {
        $snap = $this->snapshotEnv();
        try {
            unset($_SERVER['HTTPS'], $_SERVER['SERVER_PORT']);
            $_SERVER['HTTP_X_FORWARDED_PROTO'] = 'https';
            $_ENV['TRUST_PROXY'] = 'false';
            putenv('TRUST_PROXY=false');
            UrlUtilities::resetBasePath();
            $this->assertFalse(UrlUtilities::isSecureRequest());
        } finally {
            $this->restoreEnv($snap);
        }
    }

    public function testIsSecureRequestForwardedSslOnTrusted(): void
    {
        $snap = $this->snapshotEnv();
        try {
            unset($_SERVER['HTTPS'], $_SERVER['SERVER_PORT'], $_SERVER['HTTP_X_FORWARDED_PROTO']);
            $_SERVER['HTTP_X_FORWARDED_SSL'] = 'on';
            UrlUtilities::resetBasePath();
            $this->assertTrue(UrlUtilities::isSecureRequest());
        } finally {
            $this->restoreEnv($snap);
        }
    }

    public function testIsSecureRequestPicksFirstHopOfForwardedProto(): void
    {
        $snap = $this->snapshotEnv();
        try {
            unset($_SERVER['HTTPS'], $_SERVER['SERVER_PORT']);
            $_SERVER['HTTP_X_FORWARDED_PROTO'] = 'https, http';
            UrlUtilities::resetBasePath();
            $this->assertTrue(UrlUtilities::isSecureRequest());
        } finally {
            $this->restoreEnv($snap);
        }
    }

    public function testIsSecureRequestNoIndicatorsReturnsFalse(): void
    {
        $snap = $this->snapshotEnv();
        try {
            unset(
                $_SERVER['HTTPS'],
                $_SERVER['SERVER_PORT'],
                $_SERVER['HTTP_X_FORWARDED_PROTO'],
                $_SERVER['HTTP_X_FORWARDED_SSL']
            );
            UrlUtilities::resetBasePath();
            $this->assertFalse(UrlUtilities::isSecureRequest());
        } finally {
            $this->restoreEnv($snap);
        }
    }

    public function testGetRequestHostPrefersForwardedHostWhenTrusted(): void
    {
        $snap = $this->snapshotEnv();
        try {
            $_SERVER['HTTP_HOST'] = 'internal.local';
            $_SERVER['HTTP_X_FORWARDED_HOST'] = 'public.example.com';
            UrlUtilities::resetBasePath();
            $this->assertSame('public.example.com', UrlUtilities::getRequestHost());
        } finally {
            $this->restoreEnv($snap);
        }
    }

    public function testGetRequestHostIgnoresForwardedHostWhenOptedOut(): void
    {
        $snap = $this->snapshotEnv();
        try {
            $_SERVER['HTTP_HOST'] = 'internal.local';
            $_SERVER['HTTP_X_FORWARDED_HOST'] = 'public.example.com';
            $_ENV['TRUST_PROXY'] = 'false';
            putenv('TRUST_PROXY=false');
            UrlUtilities::resetBasePath();
            $this->assertSame('internal.local', UrlUtilities::getRequestHost());
        } finally {
            $this->restoreEnv($snap);
        }
    }

    public function testGetRequestHostRejectsMalformedForwardedHost(): void
    {
        $snap = $this->snapshotEnv();
        try {
            $_SERVER['HTTP_HOST'] = 'fallback.example.com';
            // Newline + "Set-Cookie" injection attempt
            $_SERVER['HTTP_X_FORWARDED_HOST'] = "evil.example.com\r\nSet-Cookie: x=1";
            UrlUtilities::resetBasePath();
            $this->assertSame('fallback.example.com', UrlUtilities::getRequestHost());
        } finally {
            $this->restoreEnv($snap);
        }
    }

    public function testGetRequestHostFallsBackToLocalhostOnInvalidHttpHost(): void
    {
        $snap = $this->snapshotEnv();
        try {
            unset($_SERVER['HTTP_X_FORWARDED_HOST']);
            $_SERVER['HTTP_HOST'] = 'evil example.com'; // space is invalid
            UrlUtilities::resetBasePath();
            $this->assertSame('localhost', UrlUtilities::getRequestHost());
        } finally {
            $this->restoreEnv($snap);
        }
    }

    public function testGetAppOriginUsesForwardedHeadersBehindProxy(): void
    {
        $snap = $this->snapshotEnv();
        try {
            unset($_SERVER['HTTPS'], $_SERVER['SERVER_PORT'], $_ENV['APP_URL']);
            putenv('APP_URL');
            $_SERVER['HTTP_X_FORWARDED_PROTO'] = 'https';
            $_SERVER['HTTP_X_FORWARDED_HOST'] = 'lwt.example.com';
            $_SERVER['HTTP_HOST'] = 'lwt:80';
            UrlUtilities::resetBasePath();
            $this->assertSame('https://lwt.example.com', UrlUtilities::getAppOrigin());
        } finally {
            $this->restoreEnv($snap);
        }
    }

    public function testGetAppOriginPrefersExplicitAppUrl(): void
    {
        $snap = $this->snapshotEnv();
        try {
            $_ENV['APP_URL'] = 'https://configured.example.com';
            putenv('APP_URL=https://configured.example.com');
            $_SERVER['HTTP_X_FORWARDED_PROTO'] = 'http';
            $_SERVER['HTTP_HOST'] = 'somethingelse.example.com';
            UrlUtilities::resetBasePath();
            $this->assertSame(
                'https://configured.example.com',
                UrlUtilities::getAppOrigin()
            );
        } finally {
            $this->restoreEnv($snap);
        }
    }

    public function testUrlBaseUsesHttpsBehindProxyByDefault(): void
    {
        $snap = $this->snapshotEnv();
        try {
            unset($_SERVER['HTTPS'], $_SERVER['SERVER_PORT'], $_ENV['TRUST_PROXY']);
            putenv('TRUST_PROXY');
            $_SERVER['HTTP_X_FORWARDED_PROTO'] = 'https';
            $_SERVER['HTTP_X_FORWARDED_HOST'] = 'lwt.example.com';
            $_SERVER['HTTP_HOST'] = 'lwt:80';
            $_SERVER['REQUEST_URI'] = '/';
            UrlUtilities::resetBasePath();
            $base = UrlUtilities::urlBase();
            $this->assertStringStartsWith('https://lwt.example.com', $base);
        } finally {
            $this->restoreEnv($snap);
        }
    }
}
