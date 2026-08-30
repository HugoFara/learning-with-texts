<?php

declare(strict_types=1);

namespace Lwt\Tests\Api\V1;

use Lwt\Api\V1\ApiV1;
use Lwt\Api\V1\Response;
use Lwt\Api\V1\Endpoints;
use Lwt\Shared\Infrastructure\Container\Container;
use Lwt\Shared\Infrastructure\Container\CoreServiceProvider;
use Lwt\Shared\Infrastructure\Container\ControllerServiceProvider;
use Lwt\Shared\Infrastructure\Container\RepositoryServiceProvider;
use Lwt\Modules\Text\TextServiceProvider;
use Lwt\Modules\Language\LanguageServiceProvider;
use Lwt\Modules\Feed\FeedServiceProvider;
use Lwt\Modules\Vocabulary\VocabularyServiceProvider;
use Lwt\Modules\Tags\TagsServiceProvider;
use Lwt\Modules\Review\ReviewServiceProvider;
use Lwt\Modules\Admin\AdminServiceProvider;
use Lwt\Modules\User\UserServiceProvider;
use Lwt\Modules\Dictionary\DictionaryServiceProvider;
use Lwt\Modules\Book\BookServiceProvider;
use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Tests for ApiV1 routing functionality.
 */
class ApiV1RoutingTest extends TestCase
{
    private ApiV1 $api;
    private static bool $providersRegistered = false;

    public static function setUpBeforeClass(): void
    {
        if (!defined('LWT_TEST_DB_AVAILABLE') || !LWT_TEST_DB_AVAILABLE) {
            return;
        }

        if (!self::$providersRegistered) {
            $container = Container::getInstance();

            $providers = [
                new CoreServiceProvider(),
                new ControllerServiceProvider(),
                new RepositoryServiceProvider(),
                new TextServiceProvider(),
                new LanguageServiceProvider(),
                new FeedServiceProvider(),
                new VocabularyServiceProvider(),
                new TagsServiceProvider(),
                new ReviewServiceProvider(),
                new AdminServiceProvider(),
                new UserServiceProvider(),
                new DictionaryServiceProvider(),
                new BookServiceProvider(),
            ];

            foreach ($providers as $provider) {
                $provider->register($container);
            }
            foreach ($providers as $provider) {
                $provider->boot($container);
            }

            self::$providersRegistered = true;
        }
    }

    protected function setUp(): void
    {
        parent::setUp();
        if (!defined('LWT_TEST_DB_AVAILABLE') || !LWT_TEST_DB_AVAILABLE) {
            $this->markTestSkipped('Database connection required');
        }
        $this->api = new ApiV1();
    }

    // ===== Endpoints class tests =====
    #[DataProvider('validEndpointsProvider')]
    public function testResolveValidEndpoints(string $method, string $uri, string $expectedEndpoint): void
    {
        $result = Endpoints::resolve($method, $uri);

        // Should return string (endpoint) not JsonResponse (error)
        $this->assertIsString($result);
        $this->assertEquals($expectedEndpoint, $result);
    }

    public static function validEndpointsProvider(): array
    {
        return [
            // GET endpoints
            ['GET', '/api/v1/version', 'version'],
            ['GET', '/api/v1/languages', 'languages'],
            ['GET', '/api/v1/languages/1', 'languages/1'],
            ['GET', '/api/v1/languages/definitions', 'languages/definitions'],
            ['GET', '/api/v1/settings/theme-path?path=test', 'settings/theme-path'],
            ['GET', '/api/v1/statuses', 'statuses'],
            ['GET', '/api/v1/tags', 'tags'],
            ['GET', '/api/v1/terms/list', 'terms/list'],
            ['GET', '/api/v1/review/next-word?test_type=1', 'review/next-word'],

            // POST endpoints
            ['POST', '/api/v1/auth/login', 'auth/login'],
            ['POST', '/api/v1/auth/register', 'auth/register'],
            ['POST', '/api/v1/settings', 'settings'],
            ['POST', '/api/v1/languages', 'languages'],

            // PUT endpoints
            ['PUT', '/api/v1/review/status', 'review/status'],
            ['PUT', '/api/v1/terms/1', 'terms/1'],

            // DELETE endpoints
            ['DELETE', '/api/v1/languages/1', 'languages/1'],
            ['DELETE', '/api/v1/terms/1', 'terms/1'],
        ];
    }

    /**
     * Test that invalid methods return error JsonResponse.
     */
    public function testResolveInvalidMethod(): void
    {
        $result = Endpoints::resolve('PATCH', '/api/v1/version');

        $this->assertInstanceOf(\Lwt\Shared\Infrastructure\Http\JsonResponse::class, $result);
    }

    /**
     * Test parseFragments splits endpoints correctly.
     */
    public function testParseFragments(): void
    {
        $fragments = Endpoints::parseFragments('languages/1/stats');
        $this->assertEquals(['languages', '1', 'stats'], $fragments);

        $fragments = Endpoints::parseFragments('version');
        $this->assertEquals(['version'], $fragments);

        $fragments = Endpoints::parseFragments('terms/list');
        $this->assertEquals(['terms', 'list'], $fragments);
    }

    // ===== Public endpoint tests =====

    /**
     * Test isPublicEndpoint logic via reflection.
     */
    public function testPublicEndpoints(): void
    {
        $reflection = new \ReflectionClass(ApiV1::class);
        $method = $reflection->getMethod('isPublicEndpoint');

        // Auth login/register should be public
        $this->assertTrue($method->invoke($this->api, 'auth/login'));
        $this->assertTrue($method->invoke($this->api, 'auth/register'));
        $this->assertTrue($method->invoke($this->api, 'version'));
    }

    // ===== Query parameter parsing tests =====

    /**
     * Test parseQueryParams extracts query params correctly.
     */
    public function testParseQueryParams(): void
    {
        $reflection = new \ReflectionClass(ApiV1::class);
        $method = $reflection->getMethod('parseQueryParams');

        $params = $method->invoke($this->api, '/api/v1/terms?language_id=1&status=5');
        $this->assertEquals(['language_id' => '1', 'status' => '5'], $params);

        $params = $method->invoke($this->api, '/api/v1/version');
        $this->assertEquals([], $params);
    }

    // ===== Response class tests =====

    /**
     * Test Response::success returns correct format.
     */
    public function testResponseSuccess(): void
    {
        $response = Response::success(['test' => 'data']);

        $this->assertInstanceOf(\Lwt\Shared\Infrastructure\Http\JsonResponse::class, $response);
    }

    /**
     * Test Response::error returns correct format.
     */
    public function testResponseError(): void
    {
        $response = Response::error('Test error', 400);

        $this->assertInstanceOf(\Lwt\Shared\Infrastructure\Http\JsonResponse::class, $response);
    }

    // ===== Dispatch tests via reflection =====

    /**
     * Test dispatch returns 404 for unknown endpoint.
     */
    public function testDispatchReturns404ForUnknownEndpoint(): void
    {
        $reflection = new \ReflectionClass(ApiV1::class);
        $method = $reflection->getMethod('dispatch');

        $result = $method->invoke($this->api, 'GET', ['unknown-endpoint'], []);

        $this->assertInstanceOf(\Lwt\Shared\Infrastructure\Http\JsonResponse::class, $result);
    }

    /**
     * Test dispatch handles version endpoint inline.
     */
    public function testDispatchHandlesVersionEndpoint(): void
    {
        $reflection = new \ReflectionClass(ApiV1::class);
        $method = $reflection->getMethod('dispatch');

        $result = $method->invoke($this->api, 'GET', ['version'], []);

        $this->assertInstanceOf(\Lwt\Shared\Infrastructure\Http\JsonResponse::class, $result);
    }

    /**
     * Test dispatch handles statuses endpoint inline.
     */
    public function testDispatchHandlesStatusesEndpoint(): void
    {
        $reflection = new \ReflectionClass(ApiV1::class);
        $method = $reflection->getMethod('dispatch');

        $result = $method->invoke($this->api, 'GET', ['statuses'], []);

        $this->assertInstanceOf(\Lwt\Shared\Infrastructure\Http\JsonResponse::class, $result);
    }

    /**
     * Test dispatch routes to handler for known resource.
     */
    public function testDispatchRoutesToLanguageHandler(): void
    {
        $reflection = new \ReflectionClass(ApiV1::class);
        $method = $reflection->getMethod('dispatch');

        $result = $method->invoke($this->api, 'GET', ['languages'], []);

        $this->assertInstanceOf(\Lwt\Shared\Infrastructure\Http\JsonResponse::class, $result);
    }

    /**
     * Test dispatch routes to handler for language definitions.
     */
    public function testDispatchRoutesToLanguageDefinitions(): void
    {
        $reflection = new \ReflectionClass(ApiV1::class);
        $method = $reflection->getMethod('dispatch');

        $result = $method->invoke($this->api, 'GET', ['languages', 'definitions'], []);

        $this->assertInstanceOf(\Lwt\Shared\Infrastructure\Http\JsonResponse::class, $result);
    }

    /**
     * Test dispatch routes to handler for languages with-texts.
     */
    public function testDispatchRoutesToLanguagesWithTexts(): void
    {
        $reflection = new \ReflectionClass(ApiV1::class);
        $method = $reflection->getMethod('dispatch');

        $result = $method->invoke($this->api, 'GET', ['languages', 'with-texts'], []);

        $this->assertInstanceOf(\Lwt\Shared\Infrastructure\Http\JsonResponse::class, $result);
    }

    /**
     * Test dispatch routes to handler for languages with-archived-texts.
     */
    public function testDispatchRoutesToLanguagesWithArchivedTexts(): void
    {
        $reflection = new \ReflectionClass(ApiV1::class);
        $method = $reflection->getMethod('dispatch');

        $result = $method->invoke($this->api, 'GET', ['languages', 'with-archived-texts'], []);

        $this->assertInstanceOf(\Lwt\Shared\Infrastructure\Http\JsonResponse::class, $result);
    }

    /**
     * Test dispatch routes to handler for single language.
     */
    public function testDispatchRoutesToSingleLanguage(): void
    {
        $reflection = new \ReflectionClass(ApiV1::class);
        $method = $reflection->getMethod('dispatch');

        $result = $method->invoke($this->api, 'GET', ['languages', '1'], []);

        $this->assertInstanceOf(\Lwt\Shared\Infrastructure\Http\JsonResponse::class, $result);
    }

    /**
     * Test dispatch routes to handler for language stats.
     */
    public function testDispatchRoutesToLanguageStats(): void
    {
        $reflection = new \ReflectionClass(ApiV1::class);
        $method = $reflection->getMethod('dispatch');

        $result = $method->invoke($this->api, 'GET', ['languages', '1', 'stats'], []);

        $this->assertInstanceOf(\Lwt\Shared\Infrastructure\Http\JsonResponse::class, $result);
    }

    /**
     * Test dispatch routes to handler for review endpoints.
     */
    public function testDispatchRoutesToReviewNextWord(): void
    {
        $reflection = new \ReflectionClass(ApiV1::class);
        $method = $reflection->getMethod('dispatch');

        $result = $method->invoke($this->api, 'GET', ['review', 'next-word'], ['test_type' => 1]);

        $this->assertInstanceOf(\Lwt\Shared\Infrastructure\Http\JsonResponse::class, $result);
    }

    /**
     * Test dispatch routes to handler for review tomorrow-count.
     */
    public function testDispatchRoutesToReviewTomorrowCount(): void
    {
        $reflection = new \ReflectionClass(ApiV1::class);
        $method = $reflection->getMethod('dispatch');

        $result = $method->invoke($this->api, 'GET', ['review', 'tomorrow-count'], []);

        $this->assertInstanceOf(\Lwt\Shared\Infrastructure\Http\JsonResponse::class, $result);
    }

    /**
     * Test dispatch routes to handler for review config.
     */
    public function testDispatchRoutesToReviewConfig(): void
    {
        $reflection = new \ReflectionClass(ApiV1::class);
        $method = $reflection->getMethod('dispatch');

        $result = $method->invoke($this->api, 'GET', ['review', 'config'], []);

        $this->assertInstanceOf(\Lwt\Shared\Infrastructure\Http\JsonResponse::class, $result);
    }

    /**
     * Test dispatch routes to handler for review table-words.
     */
    public function testDispatchRoutesToReviewTableWords(): void
    {
        $reflection = new \ReflectionClass(ApiV1::class);
        $method = $reflection->getMethod('dispatch');

        $result = $method->invoke($this->api, 'GET', ['review', 'table-words'], []);

        $this->assertInstanceOf(\Lwt\Shared\Infrastructure\Http\JsonResponse::class, $result);
    }

    /**
     * Test dispatch handles sentences-with-term inline.
     */
    public function testDispatchHandlesSentencesWithTermId(): void
    {
        $reflection = new \ReflectionClass(ApiV1::class);
        $method = $reflection->getMethod('dispatch');

        $result = $method->invoke(
            $this->api,
            'GET',
            ['sentences-with-term', '1'],
            ['language_id' => 1, 'term_lc' => 'test']
        );

        $this->assertInstanceOf(\Lwt\Shared\Infrastructure\Http\JsonResponse::class, $result);
    }

    /**
     * Test dispatch handles sentences-with-term without term ID.
     */
    public function testDispatchHandlesSentencesWithoutTermId(): void
    {
        $reflection = new \ReflectionClass(ApiV1::class);
        $method = $reflection->getMethod('dispatch');

        $result = $method->invoke(
            $this->api,
            'GET',
            ['sentences-with-term'],
            ['language_id' => 1, 'term_lc' => 'test']
        );

        $this->assertInstanceOf(\Lwt\Shared\Infrastructure\Http\JsonResponse::class, $result);
    }

    /**
     * Test dispatch handles sentences-with-term with advanced search.
     */
    public function testDispatchHandlesSentencesWithAdvancedSearch(): void
    {
        $reflection = new \ReflectionClass(ApiV1::class);
        $method = $reflection->getMethod('dispatch');

        $result = $method->invoke(
            $this->api,
            'GET',
            ['sentences-with-term'],
            ['language_id' => 1, 'term_lc' => 'test', 'advanced_search' => true]
        );

        $this->assertInstanceOf(\Lwt\Shared\Infrastructure\Http\JsonResponse::class, $result);
    }

    /**
     * Test dispatch routes to settings handler for theme-path.
     */
    public function testDispatchRoutesToSettingsThemePath(): void
    {
        $reflection = new \ReflectionClass(ApiV1::class);
        $method = $reflection->getMethod('dispatch');

        $result = $method->invoke($this->api, 'GET', ['settings', 'theme-path'], ['path' => 'test']);

        $this->assertInstanceOf(\Lwt\Shared\Infrastructure\Http\JsonResponse::class, $result);
    }

    /**
     * Test dispatch routes to settings handler for POST.
     */
    public function testDispatchRoutesToSettingsPost(): void
    {
        $reflection = new \ReflectionClass(ApiV1::class);
        $method = $reflection->getMethod('dispatch');

        $result = $method->invoke($this->api, 'POST', ['settings'], ['key' => 'test', 'value' => 'value']);

        $this->assertInstanceOf(\Lwt\Shared\Infrastructure\Http\JsonResponse::class, $result);
    }

    /**
     * Test dispatch routes to tags handler.
     */
    public function testDispatchRoutesToTagsHandler(): void
    {
        $reflection = new \ReflectionClass(ApiV1::class);
        $method = $reflection->getMethod('dispatch');

        $result = $method->invoke($this->api, 'GET', ['tags'], []);

        $this->assertInstanceOf(\Lwt\Shared\Infrastructure\Http\JsonResponse::class, $result);
    }

    /**
     * Test dispatch routes to terms handler (list).
     */
    public function testDispatchRoutesToTermsList(): void
    {
        $reflection = new \ReflectionClass(ApiV1::class);
        $method = $reflection->getMethod('dispatch');

        $result = $method->invoke($this->api, 'GET', ['terms', 'list'], []);

        $this->assertInstanceOf(\Lwt\Shared\Infrastructure\Http\JsonResponse::class, $result);
    }

    /**
     * Test dispatch routes to terms handler (filter-options).
     */
    public function testDispatchRoutesToTermsFilterOptions(): void
    {
        $reflection = new \ReflectionClass(ApiV1::class);
        $method = $reflection->getMethod('dispatch');

        $result = $method->invoke($this->api, 'GET', ['terms', 'filter-options'], []);

        $this->assertInstanceOf(\Lwt\Shared\Infrastructure\Http\JsonResponse::class, $result);
    }

    /**
     * Test dispatch routes to terms handler (imported).
     */
    public function testDispatchRoutesToTermsImported(): void
    {
        $reflection = new \ReflectionClass(ApiV1::class);
        $method = $reflection->getMethod('dispatch');

        $result = $method->invoke($this->api, 'GET', ['terms', 'imported'], []);

        $this->assertInstanceOf(\Lwt\Shared\Infrastructure\Http\JsonResponse::class, $result);
    }

    /**
     * Test dispatch routes to terms handler (single term).
     */
    public function testDispatchRoutesToSingleTerm(): void
    {
        $reflection = new \ReflectionClass(ApiV1::class);
        $method = $reflection->getMethod('dispatch');

        $result = $method->invoke($this->api, 'GET', ['terms', '1'], []);

        $this->assertInstanceOf(\Lwt\Shared\Infrastructure\Http\JsonResponse::class, $result);
    }

    /**
     * Test dispatch routes to word-families handler.
     */
    public function testDispatchRoutesToWordFamiliesStats(): void
    {
        $reflection = new \ReflectionClass(ApiV1::class);
        $method = $reflection->getMethod('dispatch');

        $result = $method->invoke($this->api, 'GET', ['word-families', 'stats'], ['language_id' => 1]);

        $this->assertInstanceOf(\Lwt\Shared\Infrastructure\Http\JsonResponse::class, $result);
    }

    /**
     * Test dispatch routes POST to unknown returns 404.
     */
    public function testDispatchPostReturns404ForUnknown(): void
    {
        $reflection = new \ReflectionClass(ApiV1::class);
        $method = $reflection->getMethod('dispatch');

        $result = $method->invoke($this->api, 'POST', ['unknown-endpoint'], []);

        $this->assertInstanceOf(\Lwt\Shared\Infrastructure\Http\JsonResponse::class, $result);
    }

    /**
     * Test dispatch routes PUT to unknown returns 404.
     */
    public function testDispatchPutReturns404ForUnknown(): void
    {
        $reflection = new \ReflectionClass(ApiV1::class);
        $method = $reflection->getMethod('dispatch');

        $result = $method->invoke($this->api, 'PUT', ['unknown-endpoint'], []);

        $this->assertInstanceOf(\Lwt\Shared\Infrastructure\Http\JsonResponse::class, $result);
    }

    /**
     * Test dispatch routes DELETE to unknown returns 404.
     */
    public function testDispatchDeleteReturns404ForUnknown(): void
    {
        $reflection = new \ReflectionClass(ApiV1::class);
        $method = $reflection->getMethod('dispatch');

        $result = $method->invoke($this->api, 'DELETE', ['unknown-endpoint'], []);

        $this->assertInstanceOf(\Lwt\Shared\Infrastructure\Http\JsonResponse::class, $result);
    }

    /**
     * Test dispatch returns 405 for unsupported method.
     */
    public function testDispatchReturns405ForUnsupportedMethod(): void
    {
        $reflection = new \ReflectionClass(ApiV1::class);
        $method = $reflection->getMethod('dispatch');

        $result = $method->invoke($this->api, 'PATCH', ['languages'], []);

        $this->assertInstanceOf(\Lwt\Shared\Infrastructure\Http\JsonResponse::class, $result);
    }

    // ===== Static method tests =====

    /**
     * Test getRequestBody method for different HTTP methods.
     */
    public function testGetRequestBodyMethod(): void
    {
        $reflection = new \ReflectionClass(ApiV1::class);
        $method = $reflection->getMethod('getRequestBody');

        // GET should return empty array
        $result = $method->invoke(null, 'GET');
        $this->assertEquals([], $result);
    }

    /**
     * Test parseJsonBody returns empty array for empty input.
     */
    public function testParseJsonBodyEmptyInput(): void
    {
        $reflection = new \ReflectionClass(ApiV1::class);
        $method = $reflection->getMethod('parseJsonBody');

        // When there's no input, should return empty array
        $result = $method->invoke(null);
        $this->assertIsArray($result);
    }
}
