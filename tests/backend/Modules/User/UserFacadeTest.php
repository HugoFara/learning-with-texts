<?php

/**
 * Unit tests for the UserFacade class.
 *
 * PHP version 8.2
 *
 * @category Tests
 * @package  Lwt\Tests\Modules\User
 * @author   LWT Development Team
 * @license  Unlicense <http://unlicense.org/>
 * @since    3.0.0
 */

declare(strict_types=1);

namespace Lwt\Tests\Modules\User;

use Lwt\Shared\Infrastructure\Bootstrap\EnvLoader;
use Lwt\Modules\User\Domain\User;
use Lwt\Shared\Infrastructure\Exception\AuthException;
use Lwt\Shared\Infrastructure\Globals;
use Lwt\Shared\Infrastructure\Database\Configuration;
use Lwt\Shared\Infrastructure\Database\Connection;
use Lwt\Shared\Infrastructure\Database\QueryBuilder;
use Lwt\Modules\User\Application\UserFacade;
use Lwt\Modules\User\Application\Services\PasswordHasher;
use Lwt\Modules\User\Domain\UserRepositoryInterface;
use Lwt\Modules\User\Infrastructure\MySqlUserRepository;
use PHPUnit\Framework\TestCase;
use InvalidArgumentException;
use RuntimeException;

/**
 * Unit tests for the UserFacade class.
 *
 * Tests the business logic for user authentication and management.
 */
class UserFacadeTest extends TestCase
{
    private static bool $dbConnected = false;
    private static bool $usersTableExists = false;
    private UserFacade $facade;
    private UserRepositoryInterface $repository;
    private PasswordHasher $passwordHasher;

    /** @var int[] Test user IDs to clean up */
    private array $createdUserIds = [];

    public static function setUpBeforeClass(): void
    {
        $config = EnvLoader::getDatabaseConfig();
        $testDbname = "test_" . $config['dbname'];

        if (!Globals::getDbConnection()) {
            try {
                $connection = Configuration::connect(
                    $config['server'],
                    $config['userid'],
                    $config['passwd'],
                    $testDbname,
                    $config['socket'] ?? ''
                );
                Globals::setDbConnection($connection);
                self::$dbConnected = true;
            } catch (\Exception $e) {
                self::$dbConnected = false;
            }
        } else {
            self::$dbConnected = true;
        }

        // Check if users table exists
        if (self::$dbConnected) {
            $tables = Connection::preparedFetchValue(
                "SELECT COUNT(*) as value FROM information_schema.tables " .
                "WHERE table_schema = ? AND table_name = 'users'",
                [$testDbname]
            );
            self::$usersTableExists = ((int)($tables ?? 0)) > 0;
        }
    }

    protected function setUp(): void
    {
        $this->repository = new MySqlUserRepository();
        $this->passwordHasher = new PasswordHasher();
        $this->facade = new UserFacade($this->repository, $this->passwordHasher);
        $this->createdUserIds = [];
    }

    protected function tearDown(): void
    {
        // Clean up test users
        foreach ($this->createdUserIds as $userId) {
            try {
                $this->repository->delete($userId);
            } catch (\Exception $e) {
                // Ignore cleanup errors
            }
        }

        // Also clean up any users with test prefix
        if (self::$dbConnected && self::$usersTableExists) {
            QueryBuilder::table('users')
                ->where('UsUsername', 'LIKE', 'testuser_%')
                ->delete();
        }
    }

    private function skipIfNoDatabase(): void
    {
        if (!self::$dbConnected) {
            $this->markTestSkipped('Database connection required');
        }
    }

    private function skipIfNoUsersTable(): void
    {
        $this->skipIfNoDatabase();
        if (!self::$usersTableExists) {
            $this->markTestSkipped('users table required - run migrations');
        }
    }

    /**
     * Create a test user via the repository.
     */
    private function createTestUser(
        string $suffix = '',
        string $password = 'TestPass123!'
    ): User {
        $uniqueId = uniqid();
        $username = 'testuser_' . $suffix . $uniqueId;
        $email = 'test_' . $suffix . $uniqueId . '@example.com';

        $user = User::create(
            $username,
            $email,
            $this->passwordHasher->hash($password)
        );

        $userId = $this->repository->save($user);
        $this->createdUserIds[] = $userId;

        return $this->repository->find($userId);
    }

    // ===== Constructor tests =====

    public function testCanBeInstantiated(): void
    {
        $this->assertInstanceOf(UserFacade::class, $this->facade);
    }

    public function testCanBeInstantiatedWithoutPasswordHasher(): void
    {
        $facade = new UserFacade($this->repository);
        $this->assertInstanceOf(UserFacade::class, $facade);
    }

    // ===== login() tests =====

    public function testLoginWithValidCredentials(): void
    {
        $this->skipIfNoUsersTable();

        $password = 'TestPass123!';
        $user = $this->createTestUser('login', $password);

        $result = $this->facade->login($user->username(), $password);

        $this->assertInstanceOf(User::class, $result);
        $this->assertSame($user->username(), $result->username());
    }

    public function testLoginWithEmail(): void
    {
        $this->skipIfNoUsersTable();

        $password = 'TestPass123!';
        $user = $this->createTestUser('email_login', $password);

        $result = $this->facade->login($user->email(), $password);

        $this->assertInstanceOf(User::class, $result);
        $this->assertSame($user->email(), $result->email());
    }

    public function testLoginThrowsForInvalidUsername(): void
    {
        $this->skipIfNoUsersTable();

        $this->expectException(AuthException::class);

        $this->facade->login('nonexistent_user', 'anypassword');
    }

    public function testLoginThrowsForInvalidPassword(): void
    {
        $this->skipIfNoUsersTable();

        $user = $this->createTestUser('wrongpass', 'CorrectPass123!');

        $this->expectException(AuthException::class);

        $this->facade->login($user->username(), 'WrongPassword!');
    }

    public function testLoginThrowsForInactiveUser(): void
    {
        $this->skipIfNoUsersTable();

        $password = 'TestPass123!';
        $user = $this->createTestUser('inactive', $password);

        // Deactivate the user
        $this->repository->deactivate($user->id()->toInt());

        $this->expectException(AuthException::class);

        $this->facade->login($user->username(), $password);
    }

    // ===== register() tests =====

    public function testRegisterCreatesUser(): void
    {
        $this->skipIfNoUsersTable();

        $uniqueId = uniqid();
        $username = 'testuser_reg_' . $uniqueId;
        $email = 'testreg_' . $uniqueId . '@example.com';
        $password = 'NewPass123!';

        $result = $this->facade->register($username, $email, $password);

        $this->assertInstanceOf(User::class, $result);
        $this->assertSame($username, $result->username());
        $this->assertSame($email, $result->email());

        $this->createdUserIds[] = $result->id()->toInt();
    }

    public function testRegisterThrowsForDuplicateUsername(): void
    {
        $this->skipIfNoUsersTable();

        $user = $this->createTestUser('dupuser');

        $this->expectException(InvalidArgumentException::class);

        $this->facade->register(
            $user->username(),
            'different_' . uniqid() . '@example.com',
            'Pass123!'
        );
    }

    public function testRegisterThrowsForDuplicateEmail(): void
    {
        $this->skipIfNoUsersTable();

        $user = $this->createTestUser('dupemail');

        $this->expectException(InvalidArgumentException::class);

        $this->facade->register(
            'different_user_' . uniqid(),
            $user->email(),
            'Pass123!'
        );
    }

    public function testRegisterThrowsForWeakPassword(): void
    {
        $this->skipIfNoUsersTable();

        $uniqueId = uniqid();

        $this->expectException(InvalidArgumentException::class);

        $this->facade->register(
            'testuser_weakpass_' . $uniqueId,
            'weakpass_' . $uniqueId . '@example.com',
            '123'  // Too weak
        );
    }

    public function testRegisterThrowsForEmptyUsername(): void
    {
        $this->skipIfNoUsersTable();

        $this->expectException(InvalidArgumentException::class);

        $this->facade->register('', 'test@example.com', 'Pass123!');
    }

    public function testRegisterAllowsEmptyEmail(): void
    {
        $this->skipIfNoUsersTable();

        // Email is optional: an empty email is normalised to NULL, so the
        // account is created with no email on file (the username is unique).
        $user = $this->facade->register('testuser_' . uniqid(), '', 'Pass123!');

        $this->assertNull($user->email());
    }

    // ===== logout() tests =====

    public function testLogoutDoesNotThrow(): void
    {
        $this->skipIfNoUsersTable();

        // logout() should not throw even when not logged in
        $this->facade->logout();

        $this->assertTrue(true); // If we get here, no exception was thrown
    }

    // ===== validateSession() tests =====

    public function testValidateSessionReturnsBool(): void
    {
        $this->skipIfNoUsersTable();

        $result = $this->facade->validateSession();

        $this->assertIsBool($result);
    }

    // ===== getCurrentUser() tests =====

    public function testGetCurrentUserReturnsNullWhenNotLoggedIn(): void
    {
        $this->skipIfNoUsersTable();

        // Clear any current user
        Globals::setCurrentUserId(null);

        $result = $this->facade->getCurrentUser();

        $this->assertNull($result);
    }

    public function testGetCurrentUserReturnsUserWhenSet(): void
    {
        $this->skipIfNoUsersTable();

        $user = $this->createTestUser('current');
        Globals::setCurrentUserId($user->id()->toInt());

        $result = $this->facade->getCurrentUser();

        $this->assertInstanceOf(User::class, $result);
        $this->assertSame($user->username(), $result->username());

        // Clean up
        Globals::setCurrentUserId(null);
    }

    // ===== setCurrentUser() tests =====

    public function testSetCurrentUserSetsUserId(): void
    {
        $this->skipIfNoUsersTable();

        $user = $this->createTestUser('setcurrent');

        $this->facade->setCurrentUser($user);

        $this->assertSame($user->id()->toInt(), Globals::getCurrentUserId());

        // Clean up
        Globals::setCurrentUserId(null);
    }

    // ===== generateApiToken() tests =====

    public function testGenerateApiTokenReturnsString(): void
    {
        $this->skipIfNoUsersTable();

        $user = $this->createTestUser('apitoken');

        $result = $this->facade->generateApiToken($user->id()->toInt());

        $this->assertIsString($result);
        $this->assertNotEmpty($result);
    }

    public function testGenerateApiTokenCreatesValidToken(): void
    {
        $this->skipIfNoUsersTable();

        $user = $this->createTestUser('apitoken2');

        $token = $this->facade->generateApiToken($user->id()->toInt());

        // Validate the token
        $validatedUser = $this->facade->validateApiToken($token);

        $this->assertNotNull($validatedUser);
        $this->assertSame($user->id()->toInt(), $validatedUser->id()->toInt());
    }

    public function testGenerateApiTokenThrowsForNonExistentUser(): void
    {
        $this->skipIfNoUsersTable();

        $this->expectException(InvalidArgumentException::class);

        $this->facade->generateApiToken(999999);
    }

    // ===== validateApiToken() tests =====

    public function testValidateApiTokenReturnsNullForInvalidToken(): void
    {
        $this->skipIfNoUsersTable();

        $result = $this->facade->validateApiToken('invalid_token_xyz');

        $this->assertNull($result);
    }

    public function testValidateApiTokenReturnsNullForEmptyToken(): void
    {
        $this->skipIfNoUsersTable();

        $result = $this->facade->validateApiToken('');

        $this->assertNull($result);
    }

    // ===== invalidateApiToken() tests =====

    public function testInvalidateApiTokenRemovesToken(): void
    {
        $this->skipIfNoUsersTable();

        $user = $this->createTestUser('invalidate');
        $token = $this->facade->generateApiToken($user->id()->toInt());

        // Verify token works
        $this->assertNotNull($this->facade->validateApiToken($token));

        // Invalidate
        $this->facade->invalidateApiToken($user->id()->toInt());

        // Token should no longer work
        $this->assertNull($this->facade->validateApiToken($token));
    }

    public function testInvalidateApiTokenDoesNotThrowForNonExistentUser(): void
    {
        $this->skipIfNoUsersTable();

        // Should not throw
        $this->facade->invalidateApiToken(999999);

        $this->assertTrue(true);
    }

    // ===== findById() tests =====

    public function testFindByIdReturnsUser(): void
    {
        $this->skipIfNoUsersTable();

        $user = $this->createTestUser('findbyid');

        $result = $this->facade->findById($user->id()->toInt());

        $this->assertInstanceOf(User::class, $result);
        $this->assertSame($user->username(), $result->username());
    }

    public function testFindByIdReturnsNullForNonExistent(): void
    {
        $this->skipIfNoUsersTable();

        $result = $this->facade->findById(999999);

        $this->assertNull($result);
    }

    // ===== findByUsername() tests =====

    public function testFindByUsernameReturnsUser(): void
    {
        $this->skipIfNoUsersTable();

        $user = $this->createTestUser('findbyusername');

        $result = $this->facade->findByUsername($user->username());

        $this->assertInstanceOf(User::class, $result);
        $this->assertSame($user->email(), $result->email());
    }

    public function testFindByUsernameReturnsNullForNonExistent(): void
    {
        $this->skipIfNoUsersTable();

        $result = $this->facade->findByUsername('nonexistent_user_xyz');

        $this->assertNull($result);
    }

    // ===== findByEmail() tests =====

    public function testFindByEmailReturnsUser(): void
    {
        $this->skipIfNoUsersTable();

        $user = $this->createTestUser('findbyemail');

        $result = $this->facade->findByEmail($user->email());

        $this->assertInstanceOf(User::class, $result);
        $this->assertSame($user->username(), $result->username());
    }

    public function testFindByEmailReturnsNullForNonExistent(): void
    {
        $this->skipIfNoUsersTable();

        $result = $this->facade->findByEmail('nonexistent@example.com');

        $this->assertNull($result);
    }

    // ===== findOrCreateWordPressUser() tests =====

    public function testFindOrCreateWordPressUserCreatesNewUser(): void
    {
        $this->skipIfNoUsersTable();

        $wpUserId = 99900 + random_int(1, 99);
        $username = 'wpuser_' . uniqid();
        $email = 'wpuser_' . uniqid() . '@example.com';

        $result = $this->facade->findOrCreateWordPressUser($wpUserId, $username, $email);

        $this->assertInstanceOf(User::class, $result);
        $this->assertSame($username, $result->username());
        $this->assertSame($email, $result->email());
        $this->assertTrue($result->isLinkedToWordPress());
        $this->assertSame($wpUserId, $result->wordPressId());

        $this->createdUserIds[] = $result->id()->toInt();
    }

    public function testFindOrCreateWordPressUserFindsExistingByWpId(): void
    {
        $this->skipIfNoUsersTable();

        // Create a user with WordPress ID
        $wpUserId = 99800 + random_int(1, 99);
        $username = 'wpuser_existing_' . uniqid();
        $email = 'wpexisting_' . uniqid() . '@example.com';

        $created = $this->facade->findOrCreateWordPressUser($wpUserId, $username, $email);
        $this->createdUserIds[] = $created->id()->toInt();

        // Find by same WordPress ID
        $result = $this->facade->findOrCreateWordPressUser(
            $wpUserId,
            'different_username',
            'different@example.com'
        );

        $this->assertSame($created->id()->toInt(), $result->id()->toInt());
        $this->assertSame($username, $result->username());
    }

    public function testFindOrCreateWordPressUserLinksExistingByEmail(): void
    {
        $this->skipIfNoUsersTable();

        // Create a user without WordPress link
        $user = $this->createTestUser('wplink');

        $wpUserId = 99700 + random_int(1, 99);

        // Find/create with same email - should link existing user
        $result = $this->facade->findOrCreateWordPressUser(
            $wpUserId,
            'any_username',
            $user->email()
        );

        $this->assertSame($user->id()->toInt(), $result->id()->toInt());
        $this->assertTrue($result->isLinkedToWordPress());
        $this->assertSame($wpUserId, $result->wordPressId());
    }

    // ===== validatePasswordStrength() tests =====

    public function testValidatePasswordStrengthReturnsArray(): void
    {
        $result = $this->facade->validatePasswordStrength('TestPass123!');

        $this->assertIsArray($result);
        $this->assertArrayHasKey('valid', $result);
        $this->assertArrayHasKey('errors', $result);
    }

    public function testValidatePasswordStrengthValidForStrongPassword(): void
    {
        $result = $this->facade->validatePasswordStrength('StrongP@ss123');

        $this->assertTrue($result['valid']);
        $this->assertEmpty($result['errors']);
    }

    public function testValidatePasswordStrengthInvalidForWeakPassword(): void
    {
        $result = $this->facade->validatePasswordStrength('123');

        $this->assertFalse($result['valid']);
        $this->assertNotEmpty($result['errors']);
    }

    public function testValidatePasswordStrengthInvalidForEmptyPassword(): void
    {
        $result = $this->facade->validatePasswordStrength('');

        $this->assertFalse($result['valid']);
        $this->assertNotEmpty($result['errors']);
    }

    // ===== generateToken() tests =====

    public function testGenerateTokenReturnsString(): void
    {
        $result = $this->facade->generateToken();

        $this->assertIsString($result);
        $this->assertNotEmpty($result);
    }

    public function testGenerateTokenRespectsLength(): void
    {
        $result = $this->facade->generateToken(16);

        // Token is hex-encoded, so 16 bytes = 32 characters
        $this->assertSame(32, strlen($result));
    }

    public function testGenerateTokenProducesUniqueValues(): void
    {
        $token1 = $this->facade->generateToken();
        $token2 = $this->facade->generateToken();
        $token3 = $this->facade->generateToken();

        $this->assertNotSame($token1, $token2);
        $this->assertNotSame($token2, $token3);
        $this->assertNotSame($token1, $token3);
    }

    // ===== Integration tests =====

    public function testRegistrationAndLoginWorkflow(): void
    {
        $this->skipIfNoUsersTable();

        $uniqueId = uniqid();
        $username = 'testuser_workflow_' . $uniqueId;
        $email = 'workflow_' . $uniqueId . '@example.com';
        $password = 'WorkflowPass123!';

        // Register
        $registered = $this->facade->register($username, $email, $password);
        $this->createdUserIds[] = $registered->id()->toInt();

        // Login
        $loggedIn = $this->facade->login($username, $password);

        $this->assertSame($registered->id()->toInt(), $loggedIn->id()->toInt());
    }

    public function testApiTokenWorkflow(): void
    {
        $this->skipIfNoUsersTable();

        $user = $this->createTestUser('tokenworkflow');

        // Generate token
        $token = $this->facade->generateApiToken($user->id()->toInt());

        // Validate token
        $validated = $this->facade->validateApiToken($token);
        $this->assertNotNull($validated);
        $this->assertSame($user->id()->toInt(), $validated->id()->toInt());

        // Invalidate token
        $this->facade->invalidateApiToken($user->id()->toInt());

        // Token should no longer work
        $invalidated = $this->facade->validateApiToken($token);
        $this->assertNull($invalidated);
    }

    public function testFindMethodsReturnConsistentData(): void
    {
        $this->skipIfNoUsersTable();

        $user = $this->createTestUser('consistent');

        $byId = $this->facade->findById($user->id()->toInt());
        $byUsername = $this->facade->findByUsername($user->username());
        $byEmail = $this->facade->findByEmail($user->email());

        $this->assertNotNull($byId);
        $this->assertNotNull($byUsername);
        $this->assertNotNull($byEmail);

        $this->assertSame($byId->id()->toInt(), $byUsername->id()->toInt());
        $this->assertSame($byId->id()->toInt(), $byEmail->id()->toInt());
    }
}
