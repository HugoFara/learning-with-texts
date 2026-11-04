<?php

declare(strict_types=1);

namespace Lwt\Tests\Core\Entity;

use DateTimeImmutable;
use InvalidArgumentException;
use LogicException;
use Lwt\Modules\User\Domain\User;
use Lwt\Shared\Domain\ValueObjects\UserId;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for the User entity and UserId value object.
 */
class UserTest extends TestCase
{
    // =========================================================================
    // UserId Value Object Tests
    // =========================================================================

    public function testUserIdFromInt(): void
    {
        $id = UserId::fromInt(42);

        $this->assertEquals(42, $id->toInt());
        $this->assertFalse($id->isNew());
    }

    public function testUserIdNew(): void
    {
        $id = UserId::new();

        $this->assertEquals(0, $id->toInt());
        $this->assertTrue($id->isNew());
    }

    public function testUserIdFromIntRejectsZero(): void
    {
        $this->expectException(InvalidArgumentException::class);

        UserId::fromInt(0);
    }

    public function testUserIdFromIntRejectsNegative(): void
    {
        $this->expectException(InvalidArgumentException::class);

        UserId::fromInt(-1);
    }

    public function testUserIdEquality(): void
    {
        $id1 = UserId::fromInt(42);
        $id2 = UserId::fromInt(42);
        $id3 = UserId::fromInt(43);

        $this->assertTrue($id1->equals($id2));
        $this->assertFalse($id1->equals($id3));
    }

    public function testUserIdToString(): void
    {
        $id = UserId::fromInt(42);

        $this->assertEquals('42', (string) $id);
    }

    // =========================================================================
    // User Entity - Creation Tests
    // =========================================================================

    public function testCreateUser(): void
    {
        $user = User::create('testuser', 'test@example.com', 'hashedpassword');

        $this->assertTrue($user->id()->isNew());
        $this->assertEquals('testuser', $user->username());
        $this->assertEquals('test@example.com', $user->email());
        $this->assertEquals('hashedpassword', $user->passwordHash());
        $this->assertTrue($user->isActive());
        $this->assertEquals(User::ROLE_USER, $user->role());
        $this->assertNull($user->wordPressId());
        $this->assertNull($user->apiToken());
        $this->assertNull($user->lastLogin());
        $this->assertInstanceOf(DateTimeImmutable::class, $user->created());
    }

    public function testAnAccountWithoutEmailHasNothingToVerify(): void
    {
        $user = User::create('testuser', null, 'hashedpassword');

        $this->assertFalse($user->needsEmailVerification());
    }

    public function testAnEmailNeedsVerifyingUntilItIsVerified(): void
    {
        $user = User::create('testuser', 'test@example.com', 'hashedpassword');
        $this->assertTrue($user->needsEmailVerification());

        $user->markEmailVerified();

        $this->assertFalse($user->needsEmailVerification());
    }

    public function testCreateUserTrimsInputs(): void
    {
        $user = User::create('  testuser  ', '  test@example.com  ', 'hashedpassword');

        $this->assertEquals('testuser', $user->username());
        $this->assertEquals('test@example.com', $user->email());
    }

    public function testCreateUserLowercasesEmail(): void
    {
        $user = User::create('testuser', 'Test@Example.COM', 'hashedpassword');

        $this->assertEquals('test@example.com', $user->email());
    }

    public function testCreateUserRejectsEmptyUsername(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Username cannot be empty');

        User::create('', 'test@example.com', 'hashedpassword');
    }

    public function testCreateUserRejectsShortUsername(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('at least 3 characters');

        User::create('ab', 'test@example.com', 'hashedpassword');
    }

    public function testCreateUserRejectsInvalidUsernameChars(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('only contain letters');

        User::create('test user', 'test@example.com', 'hashedpassword');
    }

    public function testCreateUserAllowsEmptyEmail(): void
    {
        // Email is optional (the username is the unique identity). A blank or
        // null email is stored as NULL rather than rejected.
        $fromBlank = User::create('testuser', '', 'hashedpassword');
        $this->assertNull($fromBlank->email());

        $fromNull = User::create('testuser2', null, 'hashedpassword');
        $this->assertNull($fromNull->email());
    }

    public function testCreateUserRejectsInvalidEmail(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid email');

        User::create('testuser', 'not-an-email', 'hashedpassword');
    }

    public function testCreateFromWordPress(): void
    {
        $user = User::createFromWordPress(123, 'wpuser', 'wp@example.com');

        $this->assertTrue($user->id()->isNew());
        $this->assertEquals('wpuser', $user->username());
        $this->assertEquals('wp@example.com', $user->email());
        $this->assertNull($user->passwordHash());
        $this->assertEquals(123, $user->wordPressId());
        $this->assertTrue($user->isLinkedToWordPress());
    }

    public function testCreateFromWordPressRejectsInvalidId(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('WordPress user ID must be positive');

        User::createFromWordPress(0, 'wpuser', 'wp@example.com');
    }

    // =========================================================================
    // User Entity - Reconstitution Tests
    // =========================================================================

    public function testReconstitute(): void
    {
        $created = new DateTimeImmutable('2024-01-01');
        $lastLogin = new DateTimeImmutable('2024-01-15');
        $tokenExpires = new DateTimeImmutable('+1 hour');
        $rememberExpires = new DateTimeImmutable('+30 days');

        $user = User::reconstitute(
            42,
            'testuser',
            'test@example.com',
            'hashedpassword',
            'token123',
            $tokenExpires,
            'remember456',
            $rememberExpires,
            null, // passwordResetToken
            null, // passwordResetTokenExpires
            null, // emailVerifiedAt
            null, // emailVerificationToken
            null, // emailVerificationTokenExpires
            null, // wordPressId
            null, // googleId
            null, // microsoftId
            $created,
            $lastLogin,
            true,
            User::ROLE_ADMIN
        );

        $this->assertEquals(42, $user->id()->toInt());
        $this->assertEquals('testuser', $user->username());
        $this->assertEquals('test@example.com', $user->email());
        $this->assertEquals('hashedpassword', $user->passwordHash());
        $this->assertEquals('token123', $user->apiToken());
        $this->assertSame($tokenExpires, $user->apiTokenExpires());
        $this->assertEquals('remember456', $user->rememberToken());
        $this->assertSame($rememberExpires, $user->rememberTokenExpires());
        $this->assertNull($user->wordPressId());
        $this->assertNull($user->googleId());
        $this->assertNull($user->microsoftId());
        $this->assertSame($created, $user->created());
        $this->assertSame($lastLogin, $user->lastLogin());
        $this->assertTrue($user->isActive());
        $this->assertEquals(User::ROLE_ADMIN, $user->role());
    }

    // =========================================================================
    // User Entity - Domain Behavior Tests
    // =========================================================================

    public function testChangeUsername(): void
    {
        $user = User::create('oldname', 'test@example.com', 'hashedpassword');

        $user->changeUsername('newname');

        $this->assertEquals('newname', $user->username());
    }

    public function testChangeUsernameTrims(): void
    {
        $user = User::create('oldname', 'test@example.com', 'hashedpassword');

        $user->changeUsername('  newname  ');

        $this->assertEquals('newname', $user->username());
    }

    public function testChangeUsernameRejectsInvalid(): void
    {
        $user = User::create('testuser', 'test@example.com', 'hashedpassword');

        $this->expectException(InvalidArgumentException::class);

        $user->changeUsername('');
    }

    public function testChangeEmail(): void
    {
        $user = User::create('testuser', 'old@example.com', 'hashedpassword');

        $user->changeEmail('new@example.com');

        $this->assertEquals('new@example.com', $user->email());
    }

    public function testChangeEmailLowercases(): void
    {
        $user = User::create('testuser', 'old@example.com', 'hashedpassword');

        $user->changeEmail('NEW@EXAMPLE.COM');

        $this->assertEquals('new@example.com', $user->email());
    }

    public function testChangePassword(): void
    {
        $user = User::create('testuser', 'test@example.com', 'oldhash');

        $user->changePassword('newhash');

        $this->assertEquals('newhash', $user->passwordHash());
    }

    public function testSetApiToken(): void
    {
        $user = User::create('testuser', 'test@example.com', 'hashedpassword');
        $expires = new DateTimeImmutable('+1 hour');

        $user->setApiToken('newtoken', $expires);

        $this->assertEquals('newtoken', $user->apiToken());
        $this->assertSame($expires, $user->apiTokenExpires());
    }

    public function testInvalidateApiToken(): void
    {
        $user = User::create('testuser', 'test@example.com', 'hashedpassword');
        $user->setApiToken('token', new DateTimeImmutable('+1 hour'));

        $user->invalidateApiToken();

        $this->assertNull($user->apiToken());
        $this->assertNull($user->apiTokenExpires());
    }

    public function testRecordLogin(): void
    {
        $user = User::create('testuser', 'test@example.com', 'hashedpassword');
        $this->assertNull($user->lastLogin());

        $user->recordLogin();

        $this->assertInstanceOf(DateTimeImmutable::class, $user->lastLogin());
    }

    public function testActivateDeactivate(): void
    {
        $user = User::create('testuser', 'test@example.com', 'hashedpassword');
        $this->assertTrue($user->isActive());

        $user->deactivate();
        $this->assertFalse($user->isActive());
        $this->assertFalse($user->canLogin());

        $user->activate();
        $this->assertTrue($user->isActive());
        $this->assertTrue($user->canLogin());
    }

    public function testPromoteDemoteAdmin(): void
    {
        $user = User::create('testuser', 'test@example.com', 'hashedpassword');
        $this->assertFalse($user->isAdmin());

        $user->promoteToAdmin();
        $this->assertTrue($user->isAdmin());
        $this->assertEquals(User::ROLE_ADMIN, $user->role());

        $user->demoteFromAdmin();
        $this->assertFalse($user->isAdmin());
        $this->assertEquals(User::ROLE_USER, $user->role());
    }

    public function testLinkUnlinkWordPress(): void
    {
        $user = User::create('testuser', 'test@example.com', 'hashedpassword');
        $this->assertFalse($user->isLinkedToWordPress());

        $user->linkWordPress(123);
        $this->assertTrue($user->isLinkedToWordPress());
        $this->assertEquals(123, $user->wordPressId());

        $user->unlinkWordPress();
        $this->assertFalse($user->isLinkedToWordPress());
        $this->assertNull($user->wordPressId());
    }

    public function testLinkWordPressRejectsInvalidId(): void
    {
        $user = User::create('testuser', 'test@example.com', 'hashedpassword');

        $this->expectException(InvalidArgumentException::class);

        $user->linkWordPress(0);
    }

    // =========================================================================
    // User Entity - Query Method Tests
    // =========================================================================

    public function testHasPassword(): void
    {
        $user = User::create('testuser', 'test@example.com', 'hashedpassword');
        $this->assertTrue($user->hasPassword());

        $wpUser = User::createFromWordPress(123, 'wpuser', 'wp@example.com');
        $this->assertFalse($wpUser->hasPassword());
    }

    public function testHasValidApiToken(): void
    {
        $user = User::create('testuser', 'test@example.com', 'hashedpassword');
        $this->assertFalse($user->hasValidApiToken());

        // Set a future expiration
        $user->setApiToken('token', new DateTimeImmutable('+1 hour'));
        $this->assertTrue($user->hasValidApiToken());

        // Set an expired token
        $user->setApiToken('token', new DateTimeImmutable('-1 hour'));
        $this->assertFalse($user->hasValidApiToken());
    }

    // =========================================================================
    // User Entity - ID Management Tests
    // =========================================================================

    public function testSetId(): void
    {
        $user = User::create('testuser', 'test@example.com', 'hashedpassword');
        $this->assertTrue($user->id()->isNew());

        $user->setId(UserId::fromInt(42));

        $this->assertEquals(42, $user->id()->toInt());
        $this->assertFalse($user->id()->isNew());
    }

    public function testSetIdThrowsIfAlreadyPersisted(): void
    {
        $user = User::reconstitute(
            1,
            'testuser',
            'test@example.com',
            'hashedpassword',
            null, // apiToken
            null, // apiTokenExpires
            null, // rememberToken
            null, // rememberTokenExpires
            null, // passwordResetToken
            null, // passwordResetTokenExpires
            null, // emailVerifiedAt
            null, // emailVerificationToken
            null, // emailVerificationTokenExpires
            null, // wordPressId
            null, // googleId
            null, // microsoftId
            new DateTimeImmutable(),
            null,
            true,
            User::ROLE_USER
        );

        $this->expectException(LogicException::class);

        $user->setId(UserId::fromInt(42));
    }
}
