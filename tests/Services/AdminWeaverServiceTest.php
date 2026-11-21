<?php

declare(strict_types=1);

namespace Habi\Tests\Services;

use Habi\Bootstrap\HeritageUpgrade;
use Habi\Bootstrap\Initializer;
use Habi\Services\AdminActionException;
use Habi\Services\AdminWeaverService;
use Habi\Services\AuthService;
use Habi\Tests\TestSupport\IsolatedDatabaseTestCase;

/**
 * Admin-managed weaver accounts (PRD R40, R43; D9: no self-registration):
 * create with an entered or generated password (hashed, never stored in
 * plain text), edit the profile as plain text, deactivate (sign-in then
 * fails through AuthService) and reactivate, and reset passwords. The
 * placeholder weaver is never listed or editable.
 */
final class AdminWeaverServiceTest extends IsolatedDatabaseTestCase
{
    private AdminWeaverService $weavers;
    private AuthService $auth;
    private int $adminId;

    protected function setUp(): void
    {
        parent::setUp();
        Initializer::initialize($this->database(), $this->migrationsDir());
        $this->weavers = new AdminWeaverService($this->database());
        $this->auth = new AuthService($this->database());
        $this->adminId = (int) $this->database()->query('SELECT id FROM admins LIMIT 1')->fetchColumn();
    }

    public function testCreateWithAnEnteredPasswordLetsTheWeaverSignIn(): void
    {
        $result = $this->weavers->validate($this->input(['password' => 'loom-and-thread-1']), null);
        self::assertSame([], $result['errors']);

        $created = $this->weavers->create($result['values'], $this->adminId, null);

        self::assertNull($created['password'], 'An entered password is never echoed back.');
        $row = $this->row($created['id']);
        self::assertSame('ana@weavers.test', $row['email']);
        self::assertSame('ana-of-basey', $row['slug']);
        self::assertSame(1, (int) $row['is_active']);
        self::assertSame($this->adminId, (int) $row['created_by_admin_id']);
        self::assertNotSame('loom-and-thread-1', $row['password_hash']);
        self::assertTrue(password_verify('loom-and-thread-1', (string) $row['password_hash']));
        self::assertSame("First paragraph.\n\nSecond paragraph.", $row['story']);
        self::assertSame(
            AuthService::STATUS_OK,
            $this->auth->attemptWeaver('ANA@weavers.test', 'loom-and-thread-1', '127.0.0.1')['status']
        );
    }

    public function testCreateWithoutAPasswordGeneratesOneShownOnce(): void
    {
        $result = $this->weavers->validate($this->input(['password' => '']), null);
        $created = $this->weavers->create($result['values'], $this->adminId, null);

        self::assertIsString($created['password']);
        self::assertGreaterThanOrEqual(16, strlen($created['password']));
        self::assertTrue(password_verify($created['password'], (string) $this->row($created['id'])['password_hash']));
        self::assertSame(
            AuthService::STATUS_OK,
            $this->auth->attemptWeaver('ana@weavers.test', $created['password'], '127.0.0.1')['status']
        );
    }

    public function testValidationRefusesDuplicateEmailShortPasswordAndHtmlInTheStory(): void
    {
        $first = $this->weavers->validate($this->input(), null);
        $this->weavers->create($first['values'], $this->adminId, null);

        $result = $this->weavers->validate($this->input([
            'email' => 'Ana@Weavers.test',
            'password' => 'short',
            'story' => 'Hello <script>alert(1)</script>',
            'display_name' => '',
        ]), null);

        self::assertArrayHasKey('email', $result['errors']);
        self::assertArrayHasKey('password', $result['errors']);
        self::assertArrayHasKey('story', $result['errors']);
        self::assertArrayHasKey('display_name', $result['errors']);
        $invalid = $this->weavers->validate($this->input(['email' => 'not-an-email']), null);
        self::assertArrayHasKey('email', $invalid['errors']);
    }

    public function testDeactivatedWeaverCannotSignInUntilReactivated(): void
    {
        $created = $this->weavers->create(
            $this->weavers->validate($this->input(['password' => 'loom-and-thread-1']), null)['values'],
            $this->adminId,
            null
        );

        $this->weavers->setActive($created['id'], false);

        self::assertSame(0, (int) $this->row($created['id'])['is_active']);
        self::assertSame(
            AuthService::STATUS_INVALID,
            $this->auth->attemptWeaver('ana@weavers.test', 'loom-and-thread-1', '127.0.0.2')['status']
        );
        $this->weavers->setActive($created['id'], true);
        self::assertSame(
            AuthService::STATUS_OK,
            $this->auth->attemptWeaver('ana@weavers.test', 'loom-and-thread-1', '127.0.0.3')['status']
        );
    }

    public function testPasswordResetReplacesTheOldPassword(): void
    {
        $created = $this->weavers->create(
            $this->weavers->validate($this->input(['password' => 'loom-and-thread-1']), null)['values'],
            $this->adminId,
            null
        );

        self::assertNull($this->weavers->setPassword($created['id'], 'new-loom-password'));
        self::assertSame(
            AuthService::STATUS_INVALID,
            $this->auth->attemptWeaver('ana@weavers.test', 'loom-and-thread-1', '127.0.0.4')['status']
        );
        self::assertSame(
            AuthService::STATUS_OK,
            $this->auth->attemptWeaver('ana@weavers.test', 'new-loom-password', '127.0.0.4')['status']
        );

        $generated = $this->weavers->setPassword($created['id'], '');
        self::assertIsString($generated);
        self::assertTrue(password_verify($generated, (string) $this->row($created['id'])['password_hash']));

        try {
            $this->weavers->setPassword($created['id'], 'short');
            self::fail('Expected a short-password refusal.');
        } catch (AdminActionException $refused) {
            self::assertSame('password', $refused->field);
        }
    }

    public function testSeededWeaverWithUnusableHashGetsAPasswordAndSignsIn(): void
    {
        $id = (int) $this->database()->query("SELECT id FROM weavers WHERE slug = 'rosa-basey'")->fetchColumn();
        self::assertSame(
            AuthService::STATUS_INVALID,
            $this->auth->attemptWeaver('rosa-basey@weavers.invalid', '!', '127.0.0.5')['status']
        );

        $this->weavers->setPassword($id, 'rosa-loom-password');

        self::assertSame(
            AuthService::STATUS_OK,
            $this->auth->attemptWeaver('rosa-basey@weavers.invalid', 'rosa-loom-password', '127.0.0.5')['status']
        );
    }

    public function testUpdateEditsTheProfileButNotThePassword(): void
    {
        $created = $this->weavers->create(
            $this->weavers->validate($this->input(['password' => 'loom-and-thread-1']), null)['values'],
            $this->adminId,
            null
        );
        $hash = $this->row($created['id'])['password_hash'];

        $edit = $this->weavers->validate($this->input([
            'display_name' => 'Ana Reyes',
            'email' => 'ana.reyes@weavers.test',
            'story' => "Line one\r\n\r\n\r\nLine two",
            'password' => 'ignored-on-edit',
        ]), $created['id']);
        self::assertSame([], $edit['errors']);
        $this->weavers->update($created['id'], $edit['values'], '/uploads/photo.png');

        $row = $this->row($created['id']);
        self::assertSame(['Ana Reyes', 'ana.reyes@weavers.test', "Line one\n\nLine two", '/uploads/photo.png'], [
            $row['display_name'], $row['email'], $row['story'], $row['photo_path'],
        ]);
        self::assertSame('ana-of-basey', $row['slug'], 'The public URL stays stable.');
        self::assertSame($hash, $row['password_hash']);
    }

    public function testThePlaceholderWeaverIsNeverListedOrChangeable(): void
    {
        $placeholder = HeritageUpgrade::placeholderWeaverId($this->database());

        self::assertNotContains($placeholder, array_column($this->weavers->list(), 'id'));
        self::assertNull($this->weavers->find($placeholder));
        foreach (
            [
                fn () => $this->weavers->setActive($placeholder, true),
                fn () => $this->weavers->setPassword($placeholder, 'some-long-password'),
            ] as $attempt
        ) {
            try {
                $attempt();
                self::fail('Expected the placeholder to be refused.');
            } catch (AdminActionException) {
                $this->addToAssertionCount(1);
            }
        }
        self::assertSame(0, (int) $this->row($placeholder)['is_active']);
        self::assertSame('!', $this->row($placeholder)['password_hash']);
    }

    public function testListCountsProductsAndPendingListings(): void
    {
        $id = (int) $this->database()->query("SELECT id FROM weavers WHERE slug = 'rosa-basey'")->fetchColumn();
        $this->database()->prepare(
            "INSERT INTO products (sku, slug, name, weaver_id, category_id, category, price_centavos, stock,
                 listing_status) VALUES ('W-PEND', 'w-pend', 'Pending bag', ?, 1, 'Tote', 100, 1, 'pending_review')"
        )->execute([$id]);

        $rosa = array_values(array_filter($this->weavers->list(), static fn (array $w): bool => $w['id'] === $id))[0];

        self::assertSame(1, $rosa['pending']);
        self::assertGreaterThanOrEqual(2, $rosa['products']);
    }

    /** @param array<string, string> $overrides @return array<string, string> */
    private function input(array $overrides = []): array
    {
        return array_merge([
            'email' => 'ana@weavers.test',
            'display_name' => 'Ana of Basey',
            'community' => 'Basey',
            'region' => 'Eastern Visayas (Region VIII)',
            'fibers' => 'Tikog',
            'techniques' => 'Banig weaving',
            'story' => "First paragraph.\r\n\r\nSecond paragraph.",
            'password' => 'loom-and-thread-1',
        ], $overrides);
    }

    /** @return array<string, mixed> */
    private function row(int $id): array
    {
        $statement = $this->database()->prepare('SELECT * FROM weavers WHERE id = ?');
        $statement->execute([$id]);

        return $statement->fetch();
    }
}
