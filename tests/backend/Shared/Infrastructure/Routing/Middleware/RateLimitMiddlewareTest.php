<?php

declare(strict_types=1);

namespace Tests\Shared\Infrastructure\Routing\Middleware;

use PHPUnit\Framework\TestCase;
use Lwt\Shared\Infrastructure\Routing\Middleware\RateLimitMiddleware;
use Lwt\Shared\Infrastructure\Routing\Middleware\RateLimitStorage;

/**
 * Test cases for RateLimitMiddleware.
 */
class RateLimitMiddlewareTest extends TestCase
{
    private RateLimitStorage $storage;
    private string $storageDir;

    protected function setUp(): void
    {
        parent::setUp();

        // Use temp directory for testing
        $this->storageDir = sys_get_temp_dir() . '/lwt_rate_limit_test_' . getmypid();
        @mkdir($this->storageDir, 0700, true);

        $this->storage = new RateLimitStorage($this->storageDir);
        $this->storage->clear();

        // Set up minimal server vars
        $_SERVER['REMOTE_ADDR'] = '192.168.1.100';
        $_SERVER['REQUEST_URI'] = '/api/v1/terms';
        $_SERVER['REQUEST_METHOD'] = 'GET';
    }

    protected function tearDown(): void
    {
        parent::tearDown();

        // Clean up storage
        $this->storage->clear();

        // Remove test directory
        if (is_dir($this->storageDir)) {
            @rmdir($this->storageDir);
        }
    }

    public function testFirstRequestIsAllowed(): void
    {
        $middleware = new RateLimitMiddleware($this->storage, 10, 60);

        // First request should be allowed
        $this->assertTrue($middleware->handle());
    }

    public function testMultipleRequestsWithinLimitAllowed(): void
    {
        $middleware = new RateLimitMiddleware($this->storage, 5, 60);

        // All 5 requests within limit should be allowed
        for ($i = 0; $i < 5; $i++) {
            $this->assertTrue($middleware->handle());
        }
    }

    public function testStorageTracksRequestCount(): void
    {
        $middleware = new RateLimitMiddleware($this->storage, 10, 60);

        // Make 3 requests
        $middleware->handle();
        $middleware->handle();
        $middleware->handle();

        // Check storage has the count
        $key = "rate_limit:api:192.168.1.100";
        $data = $this->storage->get($key);

        $this->assertNotNull($data);
        $this->assertEquals(3, $data['count']);
    }

    public function testStorageSetsAndGetsData(): void
    {
        $testData = [
            'count' => 5,
            'window_start' => time(),
        ];

        $this->storage->set('test_key', $testData, 60);
        $retrieved = $this->storage->get('test_key');

        $this->assertNotNull($retrieved);
        $this->assertEquals($testData['count'], $retrieved['count']);
        $this->assertEquals($testData['window_start'], $retrieved['window_start']);
    }

    public function testStorageReturnsNullForMissingKey(): void
    {
        $result = $this->storage->get('nonexistent_key');
        $this->assertNull($result);
    }

    public function testStorageClearRemovesAllData(): void
    {
        $this->storage->set('key1', ['count' => 1, 'window_start' => time()], 60);
        $this->storage->set('key2', ['count' => 2, 'window_start' => time()], 60);

        $this->storage->clear();

        $this->assertNull($this->storage->get('key1'));
        $this->assertNull($this->storage->get('key2'));
    }

    public function testDifferentIpsTrackedSeparately(): void
    {
        $middleware = new RateLimitMiddleware($this->storage, 3, 60);

        // Make 2 requests from first IP
        $_SERVER['REMOTE_ADDR'] = '192.168.1.1';
        $middleware->handle();
        $middleware->handle();

        // Make 2 requests from second IP
        $_SERVER['REMOTE_ADDR'] = '192.168.1.2';
        $middleware->handle();
        $middleware->handle();

        // Check each IP has its own count
        $data1 = $this->storage->get('rate_limit:api:192.168.1.1');
        $data2 = $this->storage->get('rate_limit:api:192.168.1.2');

        $this->assertEquals(2, $data1['count']);
        $this->assertEquals(2, $data2['count']);
    }

    public function testAuthEndpointDetection(): void
    {
        $middleware = new RateLimitMiddleware($this->storage, 100, 60);

        // Test auth login endpoint detection
        $_SERVER['REQUEST_URI'] = '/api/v1/auth/login';
        $middleware->handle();

        $authKey = 'rate_limit:auth:192.168.1.100';
        $authData = $this->storage->get($authKey);
        $this->assertNotNull($authData);

        // Test regular API endpoint
        $_SERVER['REQUEST_URI'] = '/api/v1/terms';
        $middleware->handle();

        $apiKey = 'rate_limit:api:192.168.1.100';
        $apiData = $this->storage->get($apiKey);
        $this->assertNotNull($apiData);
    }

    public function testWhisperTranscribeGetsItsOwnBucket(): void
    {
        $this->storage->clear();
        $middleware = new RateLimitMiddleware($this->storage, 100, 60);

        // /whisper/transcribe is the NLP-heavy kickoff: gets a separate
        // 'whisper' bucket so the strict 5/15min cap can apply.
        $_SERVER['REQUEST_URI'] = '/api/v1/whisper/transcribe';
        $middleware->handle();

        $this->assertNotNull($this->storage->get('rate_limit:whisper:192.168.1.100'));

        // Polling endpoints (status/result) stay on the general bucket
        // so a long-running transcription can be polled freely.
        $_SERVER['REQUEST_URI'] = '/api/v1/whisper/status/abc';
        $middleware->handle();

        $this->assertNotNull($this->storage->get('rate_limit:api:192.168.1.100'));
    }

    public function testLibraryPreviewsCannotUseUpTheGeneralQuota(): void
    {
        $middleware = new RateLimitMiddleware($this->storage, 1, 60);

        $_SERVER['REQUEST_URI'] = '/api/v1/texts/library-preview?url=x';
        $this->assertTrue($middleware->handle());

        $this->assertNotNull($this->storage->get('rate_limit:preview:192.168.1.100'));
        $this->assertNull($this->storage->get('rate_limit:api:192.168.1.100'));

        $_SERVER['REQUEST_URI'] = '/api/v1/texts';
        $this->assertTrue($middleware->handle());
    }

    public function testForwardedIpHeader(): void
    {
        $middleware = new RateLimitMiddleware($this->storage, 10, 60);

        // Set forwarded IP header
        $_SERVER['HTTP_X_FORWARDED_FOR'] = '10.0.0.1, 192.168.1.1';
        $middleware->handle();

        // Should use the first IP from the chain
        $data = $this->storage->get('rate_limit:api:10.0.0.1');
        $this->assertNotNull($data);
    }

    public function testRealIpHeader(): void
    {
        $this->storage->clear();
        $middleware = new RateLimitMiddleware($this->storage, 10, 60);

        // Set real IP header (X-Forwarded-For takes precedence, so clear it)
        unset($_SERVER['HTTP_X_FORWARDED_FOR']);
        $_SERVER['HTTP_X_REAL_IP'] = '172.16.0.1';
        $middleware->handle();

        // Should use X-Real-IP
        $data = $this->storage->get('rate_limit:api:172.16.0.1');
        $this->assertNotNull($data);
    }

    public function testInvalidForwardedIpFallsBackToRemoteAddr(): void
    {
        $this->storage->clear();
        $middleware = new RateLimitMiddleware($this->storage, 10, 60);

        // Set invalid forwarded IP
        $_SERVER['HTTP_X_FORWARDED_FOR'] = 'not-a-valid-ip';
        unset($_SERVER['HTTP_X_REAL_IP']);
        // Use non-localhost IP since localhost skips rate limiting
        $_SERVER['REMOTE_ADDR'] = '192.168.1.50';
        $middleware->handle();

        // Should fall back to REMOTE_ADDR
        $data = $this->storage->get('rate_limit:api:192.168.1.50');
        $this->assertNotNull($data);
    }
}
