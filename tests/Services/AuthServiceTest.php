<?php

declare(strict_types=1);

namespace Habi\Tests\Services;

use Habi\Bootstrap\Initializer;
use Habi\Services\AuthService;
use Habi\Tests\TestSupport\IsolatedDatabaseTestCase;
use Habi\Tests\TestSupport\WeaverFixtures;

/**
 * Boundary tests for the administrator sign-in throttle (PRD R19, SDD §10):
 * four consecutive failures stay a generic invalid, the fifth locks the
 * email+IP key for 60 seconds during which even the correct password is not
 * checked, the lock expires with the clock, a success clears the counter,
 * and the lock is scoped per email+IP key. Unknown emails and wrong
 * passwords are indistinguishable. Weaver sign-in (R34, SDD §15.1) shares
 * the rules with role-scoped throttle keys, and deactivated weavers fail
 * generically.
 */
final class AuthServiceTest extends IsolatedDatabaseTestCase
{
    use WeaverFixtures;

    private const EMAIL = 'admin@example.test';
    private const PASSWORD = 'habi-demo-password';

    private int $now = 1_700_000_000;
    private AuthService $auth;

    protected function setUp(): void
    {
        parent::setUp();
        Initializer::initialize($this->database(), $this->migrationsDir());
        $this->now = 1_700_000_000;
        $this->auth = new AuthService($this->database(), function (): int {
            return $this->now;
        });
    }

    public function testFourFailuresStayInvalidAndTheFifthLocksForSixtySeconds(): void
    {
        for ($i = 1; $i <= 4; $i++) {
            $result = $this->auth->attempt(self::EMAIL, 'wrong-password', '10.0.0.1');
            self::assertSame(AuthService::STATUS_INVALID, $result['status']);
            self::assertArrayNotHasKey('retry_after', $result);
        }

        $result = $this->auth->attempt(self::EMAIL, 'wrong-password', '10.0.0.1');
        self::assertSame(AuthService::STATUS_LOCKED, $result['status']);
        self::assertSame(60, $result['retry_after']);
    }

    public function testCorrectPasswordDuringLockIsNotCheckedAndStillLocked(): void
    {
        for ($i = 0; $i < 5; $i++) {
            $this->auth->attempt(self::EMAIL, 'wrong-password', '10.0.0.1');
        }

        $result = $this->auth->attempt(self::EMAIL, self::PASSWORD, '10.0.0.1');
        self::assertSame(AuthService::STATUS_LOCKED, $result['status']);
        self::assertGreaterThan(0, $result['retry_after']);
        self::assertArrayNotHasKey('admin', $result);
    }

    public function testAfterSixtySecondsTheCorrectPasswordSucceedsAndClearsTheCounter(): void
    {
        for ($i = 0; $i < 5; $i++) {
            $this->auth->attempt(self::EMAIL, 'wrong-password', '10.0.0.1');
        }

        // Still locked one second before the lock ends.
        $this->now += 59;
        $result = $this->auth->attempt(self::EMAIL, self::PASSWORD, '10.0.0.1');
        self::assertSame(AuthService::STATUS_LOCKED, $result['status']);

        // At exactly 60 seconds the lock is over and the password is checked.
        $this->now += 1;
        $result = $this->auth->attempt(self::EMAIL, self::PASSWORD, '10.0.0.1');
        self::assertSame(AuthService::STATUS_OK, $result['status']);
        self::assertSame(self::EMAIL, $result['admin']['email']);

        // The success cleared the key: four fresh failures must not lock.
        for ($i = 0; $i < 4; $i++) {
            $result = $this->auth->attempt(self::EMAIL, 'wrong-password', '10.0.0.1');
            self::assertSame(AuthService::STATUS_INVALID, $result['status']);
        }
    }

    public function testLockIsScopedPerEmailAndClientIp(): void
    {
        for ($i = 0; $i < 5; $i++) {
            $this->auth->attempt(self::EMAIL, 'wrong-password', '10.0.0.1');
        }
        self::assertSame(
            AuthService::STATUS_LOCKED,
            $this->auth->attempt(self::EMAIL, 'wrong-password', '10.0.0.1')['status']
        );

        // Same email from another IP is unaffected and logs in fine.
        $result = $this->auth->attempt(self::EMAIL, self::PASSWORD, '10.0.0.2');
        self::assertSame(AuthService::STATUS_OK, $result['status']);

        // Another email from the locked IP is unaffected too.
        $result = $this->auth->attempt('other@example.test', 'whatever', '10.0.0.1');
        self::assertSame(AuthService::STATUS_INVALID, $result['status']);
    }

    public function testUnknownEmailAndWrongPasswordBothReturnTheSameGenericInvalid(): void
    {
        $unknown = $this->auth->attempt('nobody@example.test', 'whatever', '10.0.0.9');
        $wrong = $this->auth->attempt(self::EMAIL, 'not-the-password', '10.0.0.9');

        self::assertSame(['status' => AuthService::STATUS_INVALID], $unknown);
        self::assertSame(['status' => AuthService::STATUS_INVALID], $wrong);
    }

    public function testSuccessReturnsTheAdminAndUnknownEmailsCanLockToo(): void
    {
        $result = $this->auth->attempt(self::EMAIL, self::PASSWORD, '10.0.0.3');
        self::assertSame(AuthService::STATUS_OK, $result['status']);
        self::assertSame('Demo administrator', $result['admin']['name']);
        self::assertSame(1, $result['admin']['id']);

        // Unknown emails fail generically but are throttled like known ones.
        for ($i = 1; $i <= 4; $i++) {
            self::assertSame(
                AuthService::STATUS_INVALID,
                $this->auth->attempt('ghost@example.test', 'x', '10.0.0.4')['status']
            );
        }
        self::assertSame(
            AuthService::STATUS_LOCKED,
            $this->auth->attempt('ghost@example.test', 'x', '10.0.0.4')['status']
        );
    }

    public function testWeaverSignInReturnsTheActiveWeaverOnly(): void
    {
        $weaverId = $this->insertWeaver('lita', 'tikog-secret');
        $this->insertWeaver('former', 'tikog-secret', false);

        $result = $this->auth->attemptWeaver('LITA@weavers.test', 'tikog-secret', '10.0.0.5');
        self::assertSame(AuthService::STATUS_OK, $result['status']);
        self::assertSame($weaverId, $result['weaver']['id']);
        self::assertSame('Lita Weaver', $result['weaver']['name']);
        self::assertArrayNotHasKey('admin', $result);

        // Deactivated weavers, wrong passwords, admins and unusable seed hashes fail the same way.
        foreach (
            [
                ['former@weavers.test', 'tikog-secret'],
                ['lita@weavers.test', 'wrong'],
                [self::EMAIL, self::PASSWORD],
                ['nena-quezon@weavers.invalid', '!'],
            ] as [$email, $password]
        ) {
            self::assertSame(
                ['status' => AuthService::STATUS_INVALID],
                $this->auth->attemptWeaver($email, $password, '10.0.0.6')
            );
        }
        // A weaver's credentials never open the admin path either.
        self::assertSame(
            AuthService::STATUS_INVALID,
            $this->auth->attempt('lita@weavers.test', 'tikog-secret', '10.0.0.7')['status']
        );
    }

    public function testThrottleKeysAreScopedPerRole(): void
    {
        $this->insertWeaver('shared', 'weaver-password');
        for ($i = 0; $i < 5; $i++) {
            $this->auth->attempt('shared@weavers.test', 'wrong-password', '10.0.0.8');
        }
        self::assertSame(
            AuthService::STATUS_LOCKED,
            $this->auth->attempt('shared@weavers.test', 'wrong-password', '10.0.0.8')['status']
        );

        // The admin lock on the same email + IP does not touch the weaver counter.
        $result = $this->auth->attemptWeaver('shared@weavers.test', 'weaver-password', '10.0.0.8');
        self::assertSame(AuthService::STATUS_OK, $result['status']);

        for ($i = 0; $i < 4; $i++) {
            $this->auth->attemptWeaver('shared@weavers.test', 'wrong-password', '10.0.0.8');
        }
        self::assertSame(
            AuthService::STATUS_LOCKED,
            $this->auth->attemptWeaver('shared@weavers.test', 'wrong-password', '10.0.0.8')['status']
        );
        self::assertSame(
            AuthService::STATUS_LOCKED,
            $this->auth->attemptWeaver('shared@weavers.test', 'weaver-password', '10.0.0.8')['status']
        );

        // Keys are role-prefixed before hashing, never the raw email.
        $keys = $this->database()->query('SELECT throttle_key FROM login_attempts')->fetchAll(\PDO::FETCH_COLUMN);
        self::assertContains(hash('sha256', 'admin:shared@weavers.test|10.0.0.8'), $keys);
        self::assertContains(hash('sha256', 'weaver:shared@weavers.test|10.0.0.8'), $keys);
    }
}
