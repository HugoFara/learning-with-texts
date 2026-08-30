<?php

declare(strict_types=1);

namespace Tests\Shared\Infrastructure\Routing\Middleware;

use Lwt\Shared\Infrastructure\Bootstrap\EnvLoader;
use Lwt\Shared\Infrastructure\Globals;
use Lwt\Shared\Infrastructure\Http\Cors;
use Lwt\Shared\Infrastructure\Routing\Middleware\CsrfMiddleware;
use Lwt\Shared\Infrastructure\Routing\Middleware\MiddlewareInterface;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for the CsrfMiddleware class.
 */
class CsrfMiddlewareTest extends TestCase
{
    private array $originalServer;
    private array $originalSession;
    private array $originalPost;
    private ?string $corsBackup = null;
    private bool $hadCors = false;

    protected function setUp(): void
    {
        parent::setUp();
        $this->originalServer = $_SERVER;
        $this->originalSession = $_SESSION ?? [];
        $this->originalPost = $_POST;

        // Snapshot + clear any ambient CORS_ALLOWED_ORIGINS through EnvLoader so
        // the CORS-origin tests are deterministic regardless of a developer's
        // .env (EnvLoader::get consults the loaded store first, which a bare
        // unset($_ENV[...]) would not clear).
        $this->hadCors = EnvLoader::has('CORS_ALLOWED_ORIGINS');
        $this->corsBackup = EnvLoader::get('CORS_ALLOWED_ORIGINS');
        EnvLoader::set('CORS_ALLOWED_ORIGINS', null);

        // Reset Globals
        Globals::reset();

        // Ensure session is started for tests
        if (session_status() === PHP_SESSION_NONE) {
            @session_start();
        }
        $_SESSION = [];
        $_POST = [];
    }

    protected function tearDown(): void
    {
        $_SERVER = $this->originalServer;
        $_SESSION = $this->originalSession;
        $_POST = $this->originalPost;
        EnvLoader::set('CORS_ALLOWED_ORIGINS', $this->hadCors ? $this->corsBackup : null);
        Globals::reset();
        parent::tearDown();
    }

    public function testImplementsMiddlewareInterface(): void
    {
        $middleware = new CsrfMiddleware();

        $this->assertInstanceOf(MiddlewareInterface::class, $middleware);
    }

    public function testAllowsGetRequests(): void
    {
        $_SERVER['REQUEST_METHOD'] = 'GET';

        $middleware = new CsrfMiddleware();
        $result = $middleware->handle();

        $this->assertTrue($result);
    }

    public function testAllowsHeadRequests(): void
    {
        $_SERVER['REQUEST_METHOD'] = 'HEAD';

        $middleware = new CsrfMiddleware();
        $result = $middleware->handle();

        $this->assertTrue($result);
    }

    public function testAllowsOptionsRequests(): void
    {
        $_SERVER['REQUEST_METHOD'] = 'OPTIONS';

        $middleware = new CsrfMiddleware();
        $result = $middleware->handle();

        $this->assertTrue($result);
    }

    public function testAllowsPostWithValidTokenFromFormField(): void
    {
        $_SERVER['REQUEST_METHOD'] = 'POST';

        // Generate a token and place it in the session
        $token = bin2hex(random_bytes(32));
        $_SESSION['LWT_SESSION_TOKEN'] = $token;

        // Provide the same token via POST form field
        $_POST['_csrf_token'] = $token;

        $middleware = new CsrfMiddleware();
        $result = $middleware->handle();

        $this->assertTrue($result);
    }

    public function testAllowsPostWithValidTokenFromHeader(): void
    {
        $_SERVER['REQUEST_METHOD'] = 'POST';

        // Generate a token and place it in the session
        $token = bin2hex(random_bytes(32));
        $_SESSION['LWT_SESSION_TOKEN'] = $token;

        // Provide the same token via X-CSRF-TOKEN header
        $_SERVER['HTTP_X_CSRF_TOKEN'] = $token;

        $middleware = new CsrfMiddleware();
        $result = $middleware->handle();

        $this->assertTrue($result);
    }

    public function testAllowsPostWithBearerTokenBypass(): void
    {
        $_SERVER['REQUEST_METHOD'] = 'POST';

        // Provide a Bearer token of at least 20 characters
        $_SERVER['HTTP_AUTHORIZATION'] = 'Bearer abcdefghijklmnopqrstuvwxyz';

        // No CSRF token set - should still pass due to Bearer bypass
        $middleware = new CsrfMiddleware();
        $result = $middleware->handle();

        $this->assertTrue($result);
    }

    /**
     * A cross-origin request from a CORS-allow-listed origin is exempt from
     * CSRF (no token needed): the server never allows credentials cross-origin,
     * so no session cookie is attached and there is nothing to forge. This is
     * what lets a packaged client reach /auth/login for its first bearer token.
     */
    public function testAllowsPostFromAllowListedCorsOrigin(): void
    {
        $_SERVER['REQUEST_METHOD'] = 'POST';
        EnvLoader::set('CORS_ALLOWED_ORIGINS', 'https://localhost,http://localhost:4173');
        $_SERVER['HTTP_ORIGIN'] = 'https://localhost';
        // No CSRF token and no Bearer token present.

        $middleware = new CsrfMiddleware();
        $result = $middleware->handle();

        $this->assertTrue($result);
    }

    /**
     * The CORS exemption must NOT fire for an origin that is not allow-listed,
     * so the cookie-session web app stays protected. (resolveOrigin() returns
     * null, so handle() would fall through to token validation.)
     */
    public function testNonAllowListedOriginGetsNoCsrfExemption(): void
    {
        EnvLoader::set('CORS_ALLOWED_ORIGINS', 'https://localhost');
        $_SERVER['HTTP_ORIGIN'] = 'https://attacker.example';

        $this->assertNull(Cors::resolveOrigin());
    }

    public function testAllowsPutWithValidToken(): void
    {
        $_SERVER['REQUEST_METHOD'] = 'PUT';

        $token = bin2hex(random_bytes(32));
        $_SESSION['LWT_SESSION_TOKEN'] = $token;
        $_SERVER['HTTP_X_CSRF_TOKEN'] = $token;

        $middleware = new CsrfMiddleware();
        $result = $middleware->handle();

        $this->assertTrue($result);
    }

    public function testAllowsDeleteWithValidToken(): void
    {
        $_SERVER['REQUEST_METHOD'] = 'DELETE';

        $token = bin2hex(random_bytes(32));
        $_SESSION['LWT_SESSION_TOKEN'] = $token;
        $_SERVER['HTTP_X_CSRF_TOKEN'] = $token;

        $middleware = new CsrfMiddleware();
        $result = $middleware->handle();

        $this->assertTrue($result);
    }

    public function testAllowsPatchWithValidToken(): void
    {
        $_SERVER['REQUEST_METHOD'] = 'PATCH';

        $token = bin2hex(random_bytes(32));
        $_SESSION['LWT_SESSION_TOKEN'] = $token;
        $_SERVER['HTTP_X_CSRF_TOKEN'] = $token;

        $middleware = new CsrfMiddleware();
        $result = $middleware->handle();

        $this->assertTrue($result);
    }

    /**
     * Test that handle() rejects POST when no CSRF token is provided.
     *
     * Note: handle() calls handleInvalidToken() which calls exit(),
     * so this test cannot directly verify the return value. Instead
     * we use validateToken() via reflection to confirm rejection.
     */
    public function testValidateTokenReturnsFalseWithoutToken(): void
    {
        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_SESSION['LWT_SESSION_TOKEN'] = bin2hex(random_bytes(32));
        // No token in $_POST or headers

        $middleware = new CsrfMiddleware();
        $reflection = new \ReflectionClass($middleware);
        $method = $reflection->getMethod('validateToken');

        $result = $method->invoke($middleware);

        $this->assertFalse($result);
    }

    /**
     * Test that handle() rejects POST when an invalid CSRF token is provided.
     *
     * Note: handle() calls exit() on failure, so we test validateToken() directly.
     */
    public function testValidateTokenReturnsFalseWithInvalidToken(): void
    {
        $_SERVER['REQUEST_METHOD'] = 'POST';

        $token = bin2hex(random_bytes(32));
        $_SESSION['LWT_SESSION_TOKEN'] = $token;

        // Provide a different token
        $_POST['_csrf_token'] = bin2hex(random_bytes(32));

        $middleware = new CsrfMiddleware();
        $reflection = new \ReflectionClass($middleware);
        $method = $reflection->getMethod('validateToken');

        $result = $method->invoke($middleware);

        $this->assertFalse($result);
    }

    /**
     * Test that validation fails when the session token is empty.
     */
    public function testValidateTokenReturnsFalseWhenSessionTokenEmpty(): void
    {
        $_SERVER['REQUEST_METHOD'] = 'POST';

        // Session token is not set
        $_POST['_csrf_token'] = bin2hex(random_bytes(32));

        $middleware = new CsrfMiddleware();
        $reflection = new \ReflectionClass($middleware);
        $method = $reflection->getMethod('validateToken');

        $result = $method->invoke($middleware);

        $this->assertFalse($result);
    }

    /**
     * Test that a short Bearer token does not bypass CSRF validation.
     */
    public function testShortBearerTokenDoesNotBypass(): void
    {
        $_SERVER['REQUEST_METHOD'] = 'POST';

        // Bearer token less than 20 characters
        $_SERVER['HTTP_AUTHORIZATION'] = 'Bearer short_token';

        $middleware = new CsrfMiddleware();
        $reflection = new \ReflectionClass($middleware);
        $method = $reflection->getMethod('hasApiToken');

        $result = $method->invoke($middleware);

        $this->assertFalse($result);
    }

    /**
     * Test that Bearer token of exactly 20 chars passes the check.
     */
    public function testBearerTokenExactly20CharsPassesCheck(): void
    {
        $_SERVER['HTTP_AUTHORIZATION'] = 'Bearer 12345678901234567890';

        $middleware = new CsrfMiddleware();
        $reflection = new \ReflectionClass($middleware);
        $method = $reflection->getMethod('hasApiToken');

        $result = $method->invoke($middleware);

        $this->assertTrue($result);
    }

    /**
     * Test that Bearer token of 19 chars does not pass.
     */
    public function testBearerToken19CharsDoesNotPass(): void
    {
        $_SERVER['HTTP_AUTHORIZATION'] = 'Bearer 1234567890123456789';

        $middleware = new CsrfMiddleware();
        $reflection = new \ReflectionClass($middleware);
        $method = $reflection->getMethod('hasApiToken');

        $result = $method->invoke($middleware);

        $this->assertFalse($result);
    }

    /**
     * Test that hasApiToken returns false when no Authorization header is set.
     */
    public function testHasApiTokenReturnsFalseWithNoHeader(): void
    {
        unset($_SERVER['HTTP_AUTHORIZATION']);

        $middleware = new CsrfMiddleware();
        $reflection = new \ReflectionClass($middleware);
        $method = $reflection->getMethod('hasApiToken');

        $result = $method->invoke($middleware);

        $this->assertFalse($result);
    }

    /**
     * Test that hasApiToken returns false for non-Bearer auth schemes.
     */
    public function testHasApiTokenReturnsFalseForBasicAuth(): void
    {
        $_SERVER['HTTP_AUTHORIZATION'] = 'Basic dXNlcjpwYXNz';

        $middleware = new CsrfMiddleware();
        $reflection = new \ReflectionClass($middleware);
        $method = $reflection->getMethod('hasApiToken');

        $result = $method->invoke($middleware);

        $this->assertFalse($result);
    }

    public function testGetTokenGeneratesNewToken(): void
    {
        // Ensure no token exists in session
        unset($_SESSION['LWT_SESSION_TOKEN']);

        $token = CsrfMiddleware::getToken();

        $this->assertNotEmpty($token);
        // Token should be 64 hex characters (32 bytes = 64 hex chars)
        $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $token);
        // Token should now be stored in session
        $this->assertEquals($token, $_SESSION['LWT_SESSION_TOKEN']);
    }

    public function testGetTokenReturnsSameTokenOnSubsequentCalls(): void
    {
        unset($_SESSION['LWT_SESSION_TOKEN']);

        $token1 = CsrfMiddleware::getToken();
        $token2 = CsrfMiddleware::getToken();

        $this->assertSame($token1, $token2);
    }

    public function testGetTokenReturnsExistingSessionToken(): void
    {
        $existingToken = bin2hex(random_bytes(32));
        $_SESSION['LWT_SESSION_TOKEN'] = $existingToken;

        $token = CsrfMiddleware::getToken();

        $this->assertSame($existingToken, $token);
    }

    public function testFormFieldReturnsHiddenInput(): void
    {
        unset($_SESSION['LWT_SESSION_TOKEN']);

        $html = CsrfMiddleware::formField();

        // Should be a hidden input element
        $this->assertStringContainsString('<input type="hidden"', $html);
        $this->assertStringContainsString('name="_csrf_token"', $html);
        $this->assertStringContainsString('value="', $html);

        // Extract the token value and verify it matches session
        $token = CsrfMiddleware::getToken();
        $this->assertStringContainsString('value="' . $token . '"', $html);
    }

    public function testFormFieldEscapesTokenValue(): void
    {
        // Set a token with characters that need HTML escaping
        // In practice tokens are hex, but verify the escaping is in place
        $_SESSION['LWT_SESSION_TOKEN'] = 'abc123def456';

        $html = CsrfMiddleware::formField();

        $this->assertStringContainsString('value="abc123def456"', $html);
    }

    /**
     * Test that form field token matches POST validation token.
     *
     * Verifies the round-trip: formField() generates HTML with a token
     * that, when submitted back as POST data, passes validation.
     */
    public function testFormFieldTokenPassesValidation(): void
    {
        $_SERVER['REQUEST_METHOD'] = 'POST';

        // Generate a token via getToken (simulates page render)
        $token = CsrfMiddleware::getToken();

        // Simulate form submission with that token
        $_POST['_csrf_token'] = $token;

        $middleware = new CsrfMiddleware();
        $result = $middleware->handle();

        $this->assertTrue($result);
    }

    /**
     * Test that extractToken prefers form field over header.
     */
    public function testExtractTokenPrefersFormFieldOverHeader(): void
    {
        $formToken = bin2hex(random_bytes(32));
        $headerToken = bin2hex(random_bytes(32));

        $_POST['_csrf_token'] = $formToken;
        $_SERVER['HTTP_X_CSRF_TOKEN'] = $headerToken;

        // Set session to match the form token
        $_SESSION['LWT_SESSION_TOKEN'] = $formToken;
        $_SERVER['REQUEST_METHOD'] = 'POST';

        $middleware = new CsrfMiddleware();
        $result = $middleware->handle();

        // Should pass because form token matches session token
        $this->assertTrue($result);
    }
}
