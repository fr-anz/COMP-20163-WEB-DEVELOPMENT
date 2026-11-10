<?php

declare(strict_types=1);

namespace Habi\Tests\Services;

use Habi\Bootstrap\Initializer;
use Habi\Services\AuthService;
use Habi\Tests\TestSupport\IsolatedDatabaseTestCase;

/**
 * Boundary tests for the administrator sign-in throttle (PRD R19, SDD §10):
 * four consecutive failures stay a generic invalid, the fifth locks the
 * email+IP key for 60 seconds during which even the correct password is not
 * checked, the lock expires with the clock, a success clears the counter,
 * and the lock is scoped per email+IP key. Unknown emails and wrong
 * passwords are indistinguishable.
 */
final class AuthServiceTest extends IsolatedDatabaseTestCase
{
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
}
