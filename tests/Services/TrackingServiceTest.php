<?php

declare(strict_types=1);

namespace Habi\Tests\Services;

use Habi\Bootstrap\Initializer;
use Habi\Services\OrderService;
use Habi\Services\TrackingService;
use Habi\Tests\TestSupport\IsolatedDatabaseTestCase;

/**
 * Boundary tests for order tracking (PRD R32, N4; SDD §15.10): the
 * reference number and checkout email must both match, a wrong email is
 * indistinguishable from an unknown reference, results omit the street
 * address, phone and email, and failures are throttled per client under a
 * `track:`-scoped key without counting blank submissions.
 */
final class TrackingServiceTest extends IsolatedDatabaseTestCase
{
    private int $now = 1_800_000_000;
    private TrackingService $tracking;
    private string $number;

    protected function setUp(): void
    {
        parent::setUp();
        Initializer::initialize($this->database(), $this->migrationsDir());
        $this->tracking = new TrackingService($this->database(), fn (): int => $this->now);
        $product = $this->insertProduct('track-1', 'Track Tote', 120000, 3);
        $order = (new OrderService($this->database()))->placeOrder(
            [['product_id' => $product, 'quantity' => 2, 'price_centavos' => 120000]],
            [
                'name' => 'Maria Clara', 'email' => 'Maria@Example.com', 'phone' => '+63 917 123 4567',
                'address1' => '12 Katipunan Ave', 'address2' => 'Unit 3', 'city' => 'Quezon City',
                'postal_code' => '1108', 'region' => 'metro_manila',
            ],
            ['method' => 'cod'],
            'key-track'
        );
        $this->number = (string) $order['order_number'];
    }

    public function testMatchingReferenceAndEmailShowsTheOrderWithoutPrivateDetails(): void
    {
        $result = $this->tracking->lookup('  ' . strtolower($this->number) . ' ', ' maria@EXAMPLE.com ', '10.0.0.1');

        self::assertSame(TrackingService::STATUS_FOUND, $result['status']);
        $order = $result['order'];
        self::assertSame($this->number, $order['order_number']);
        self::assertSame(
            ['pending', 'cod', 'unpaid'],
            [$order['status'], $order['payment_method'], $order['payment_status']]
        );
        self::assertSame(['Quezon City', 'metro_manila'], [$order['shipping_city'], $order['shipping_region']]);
        self::assertSame(240000, (int) $order['subtotal_centavos']);
        self::assertSame([['name_snapshot' => 'Track Tote', 'qty' => 2, 'line_total_centavos' => 240000]], array_map(
            static fn (array $item): array => array_intersect_key(
                $item,
                array_flip(['name_snapshot', 'qty', 'line_total_centavos'])
            ),
            $order['items']
        ));
        self::assertSame(['status', 'payment_status'], array_column($order['events'], 'field'));
        $flat = json_encode($result, JSON_THROW_ON_ERROR);
        foreach (['Katipunan', 'Unit 3', '917', 'aria@', '1108', 'Maria Clara', 'key-track'] as $private) {
            self::assertStringNotContainsString($private, $flat);
        }
    }

    public function testWrongEmailLooksExactlyLikeAnUnknownReference(): void
    {
        $wrongEmail = $this->tracking->lookup($this->number, 'other@example.com', '10.0.0.2');
        $unknown = $this->tracking->lookup('H-20000101-0001', 'maria@example.com', '10.0.0.3');
        $malformed = $this->tracking->lookup('not-a-number', 'maria@example.com', '10.0.0.4');

        self::assertSame(['status' => TrackingService::STATUS_NOT_FOUND], $wrongEmail);
        self::assertSame($wrongEmail, $unknown);
        self::assertSame($wrongEmail, $malformed);
    }

    public function testBlankFieldsAreInlineErrorsAndAreNotCounted(): void
    {
        for ($i = 0; $i < TrackingService::MAX_FAILURES + 1; $i++) {
            $result = $this->tracking->lookup(' ', '', '10.0.0.5');
            self::assertSame(TrackingService::STATUS_INVALID, $result['status']);
            self::assertSame(['reference', 'email'], array_keys($result['errors']));
        }
        self::assertSame(
            TrackingService::STATUS_FOUND,
            $this->tracking->lookup($this->number, 'maria@example.com', '10.0.0.5')['status']
        );
    }

    public function testFifthFailureLocksTheClientEvenForACorrectLookupUntilTheLockExpires(): void
    {
        $ip = '10.0.0.6';
        for ($i = 1; $i < TrackingService::MAX_FAILURES; $i++) {
            self::assertSame(TrackingService::STATUS_NOT_FOUND, $this->statusFor('x@example.com', $ip));
        }
        $locked = $this->tracking->lookup('H-20000101-0002', 'x@example.com', $ip);
        self::assertSame(
            ['status' => TrackingService::STATUS_LOCKED, 'retry_after' => TrackingService::LOCKOUT_SECONDS],
            $locked
        );
        self::assertSame(TrackingService::STATUS_LOCKED, $this->statusFor('maria@example.com', $ip));

        // Another client is unaffected; the key is scoped and hashed.
        self::assertSame(TrackingService::STATUS_FOUND, $this->statusFor('maria@example.com', '10.0.0.7'));
        $keys = $this->database()->query('SELECT throttle_key FROM login_attempts')->fetchAll(\PDO::FETCH_COLUMN);
        self::assertSame([hash('sha256', 'track:' . $ip)], $keys);

        $this->now += TrackingService::LOCKOUT_SECONDS;
        self::assertSame(TrackingService::STATUS_FOUND, $this->statusFor('maria@example.com', $ip));
    }

    private function statusFor(string $email, string $ip): string
    {
        return $this->tracking->lookup($this->number, $email, $ip)['status'];
    }
}
