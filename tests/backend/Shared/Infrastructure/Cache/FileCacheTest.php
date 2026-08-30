<?php

declare(strict_types=1);

namespace Lwt\Tests\Shared\Infrastructure\Cache;

use Lwt\Shared\Infrastructure\Cache\FileCache;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for FileCache.
 *
 * The cache sits in front of multi-second remote fetches, so the behaviour
 * that matters is that it never becomes a failure mode of its own: every
 * problem has to degrade to a miss, which costs a refetch, rather than an
 * exception on a page load.
 */
class FileCacheTest extends TestCase
{
    private string $namespace;

    protected function setUp(): void
    {
        parent::setUp();
        $this->namespace = 'lwt_filecache_test_' . getmypid() . '_' . random_int(1000, 9999);
    }

    protected function tearDown(): void
    {
        $dir = sys_get_temp_dir() . '/' . $this->namespace;
        if (is_dir($dir)) {
            foreach (glob($dir . '/*') ?: [] as $file) {
                @unlink($file);
            }
            @rmdir($dir);
        }
        parent::tearDown();
    }

    public function testGetReturnsNullOnMiss(): void
    {
        $cache = new FileCache($this->namespace, 60);

        $this->assertNull($cache->get('never-written'));
    }

    public function testRoundTripsAValue(): void
    {
        $cache = new FileCache($this->namespace, 60);
        $cache->set('https://example.org/book.txt', 'the book text');

        $this->assertSame('the book text', $cache->get('https://example.org/book.txt'));
    }

    public function testDistinguishesKeys(): void
    {
        $cache = new FileCache($this->namespace, 60);
        $cache->set('a', 'first');
        $cache->set('b', 'second');

        $this->assertSame('first', $cache->get('a'));
        $this->assertSame('second', $cache->get('b'));
    }

    public function testOverwritesAnExistingEntry(): void
    {
        $cache = new FileCache($this->namespace, 60);
        $cache->set('k', 'old');
        $cache->set('k', 'new');

        $this->assertSame('new', $cache->get('k'));
    }

    public function testKeysWithPathCharactersStayInsideTheNamespace(): void
    {
        $cache = new FileCache($this->namespace, 60);
        // Keys are URLs, so slashes and dots are the norm; a key must never be
        // able to steer the write out of the cache directory.
        $cache->set('../../escape', 'value');

        $this->assertSame('value', $cache->get('../../escape'));
        $this->assertFileDoesNotExist(sys_get_temp_dir() . '/escape');
    }

    public function testExpiredEntryReadsAsAMiss(): void
    {
        $cache = new FileCache($this->namespace, 1);
        $cache->set('k', 'value');

        // Backdate the entry rather than sleeping through the TTL.
        $path = sys_get_temp_dir() . '/' . $this->namespace . '/' . hash('sha256', 'k');
        $this->assertFileExists($path);
        touch($path, time() - 120);
        clearstatcache(true, $path);

        $this->assertNull($cache->get('k'));
        $this->assertFileDoesNotExist($path, 'Expired entry should be dropped');
    }

    public function testZeroTtlExpiresImmediatelyRatherThanCachingForever(): void
    {
        $cache = new FileCache($this->namespace, 0);
        $cache->set('k', 'value');
        $path = sys_get_temp_dir() . '/' . $this->namespace . '/' . hash('sha256', 'k');
        touch($path, time() - 1);
        clearstatcache(true, $path);

        $this->assertNull($cache->get('k'));
    }

    public function testEmptyValueIsNotStored(): void
    {
        $cache = new FileCache($this->namespace, 60);
        $cache->set('k', '');

        // A failed fetch returns an empty string; caching that would pin the
        // failure for the whole TTL.
        $this->assertNull($cache->get('k'));
    }

    public function testLeavesNoTemporaryFilesBehind(): void
    {
        $cache = new FileCache($this->namespace, 60);
        $cache->set('k', 'value');

        $leftovers = glob(sys_get_temp_dir() . '/' . $this->namespace . '/*.tmp') ?: [];
        $this->assertSame([], $leftovers);
    }

    public function testSeparateNamespacesDoNotShareEntries(): void
    {
        $other = $this->namespace . '_other';
        (new FileCache($this->namespace, 60))->set('k', 'mine');
        $otherCache = new FileCache($other, 60);

        try {
            $this->assertNull($otherCache->get('k'));
        } finally {
            $dir = sys_get_temp_dir() . '/' . $other;
            if (is_dir($dir)) {
                foreach (glob($dir . '/*') ?: [] as $file) {
                    @unlink($file);
                }
                @rmdir($dir);
            }
        }
    }

    public function testStoresBinarySafeContent(): void
    {
        $cache = new FileCache($this->namespace, 60);
        $value = "line\n\ttab \x00 null — ünïcode";
        $cache->set('k', $value);

        $this->assertSame($value, $cache->get('k'));
    }
}
