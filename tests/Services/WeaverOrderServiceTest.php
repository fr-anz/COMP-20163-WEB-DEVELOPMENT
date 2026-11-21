<?php

declare(strict_types=1);

namespace Habi\Tests\Services;

use Habi\Bootstrap\Initializer;
use Habi\Services\EarningsService;
use Habi\Services\WeaverOrderService;
use Habi\Tests\TestSupport\IsolatedDatabaseTestCase;
use Habi\Tests\TestSupport\WeaverFixtures;

/**
 * Weaver order view and earnings (R38, R39, N4, N10): only the weaver's own
 * lines, selected by order_items.weaver_id, never the customer's name,
 * email, phone or address; earnings are delivered-and-paid line totals,
 * pending covers every other live order, shipping is never included and
 * rejected/cancelled orders count nowhere.
 */
final class WeaverOrderServiceTest extends IsolatedDatabaseTestCase
{
    use WeaverFixtures;

    private int $lita;
    private int $rosa;
    private int $litaBag;
    private int $rosaBag;

    protected function setUp(): void
    {
        parent::setUp();
        Initializer::initialize($this->database(), $this->migrationsDir());
        $this->lita = $this->insertWeaver('lita');
        $this->rosa = $this->insertWeaver('rosa');
        $this->litaBag = $this->insertWeaverProduct($this->lita, 'LITA-1', 10, 'active', 100000);
        $this->rosaBag = $this->insertWeaverProduct($this->rosa, 'ROSA-1', 10, 'active', 250000);
    }

    public function testOrdersShowOnlyOwnLinesWithoutCustomerContactData(): void
    {
        $this->insertWeaverOrder('H-20261120-0001', [
            [$this->litaBag, $this->lita, 2, 100000],
            [$this->rosaBag, $this->rosa, 1, 250000],
        ]);
        $this->insertWeaverOrder('H-20261120-0002', [[$this->rosaBag, $this->rosa, 1, 250000]]);
        $orders = new WeaverOrderService($this->database());

        $list = $orders->list($this->lita);
        self::assertSame(1, $list['total']);
        self::assertSame('H-20261120-0001', $list['orders'][0]['order_number']);
        self::assertSame(2, $list['orders'][0]['units']);
        self::assertSame(200000, $list['orders'][0]['total_centavos']);

        $order = $orders->find($this->lita, 'H-20261120-0001');
        self::assertNotNull($order);
        self::assertCount(1, $order['items']);
        self::assertSame('Bag LITA-1', $order['items'][0]['name_snapshot']);
        self::assertSame('Tacloban', $order['shipping_city']);
        self::assertSame('visayas', $order['shipping_region']);
        self::assertSame(200000, $order['total_centavos']);

        $flat = json_encode([$list, $order, $orders->recent($this->lita)], JSON_THROW_ON_ERROR);
        $secrets = ['Maria Buyer', 'buyer@example.test', '09171234567', '12 Secret Street', '6500', 'Bag ROSA-1'];
        foreach ($secrets as $secret) {
            self::assertStringNotContainsString($secret, $flat);
        }

        self::assertNull($orders->find($this->lita, 'H-20261120-0002'));
        self::assertNull($orders->find($this->lita, 'H-29991231-9999'));
    }

    public function testEarningsSplitEarnedAndPendingAndExcludeShippingAndDeadOrders(): void
    {
        $line = fn (int $qty): array => [
            [$this->litaBag, $this->lita, $qty, 100000],
            [$this->rosaBag, $this->rosa, 1, 250000],
        ];
        $this->insertWeaverOrder('H-20261101-0001', $line(1), 'delivered', 'paid', '2026-11-01 08:00:00');
        $this->insertWeaverOrder('H-20261105-0001', $line(2), 'delivered', 'unpaid', '2026-11-05 08:00:00');
        $this->insertWeaverOrder('H-20261110-0001', $line(3), 'shipped', 'paid', '2026-11-10 08:00:00');
        $this->insertWeaverOrder('H-20261112-0001', $line(4), 'pending', 'for_verification', '2026-11-12 08:00:00');
        $this->insertWeaverOrder('H-20261113-0001', $line(5), 'rejected', 'rejected', '2026-11-13 08:00:00');
        $this->insertWeaverOrder('H-20261114-0001', $line(6), 'cancelled', 'paid', '2026-11-14 08:00:00');
        $earnings = new EarningsService($this->database());

        $all = $earnings->summary($this->lita);
        self::assertSame(100000, $all['earned_centavos']);
        self::assertSame((2 + 3 + 4) * 100000, $all['pending_centavos']);
        self::assertSame(1 + 2 + 3 + 4, $all['units']);
        self::assertSame(100, $all['share_percent']);
        self::assertCount(4, $all['items']);
        self::assertSame(['pending', 'pending', 'pending', 'earned'], array_column($all['items'], 'bucket'));

        $period = $earnings->summary($this->lita, '2026-11-05', '2026-11-10');
        self::assertSame(0, $period['earned_centavos']);
        self::assertSame(500000, $period['pending_centavos']);
        self::assertSame(5, $period['units']);
        self::assertSame('2026-11-05', $period['from']);

        $invalid = $earnings->summary($this->lita, '2026-02-30', 'soon');
        self::assertSame('', $invalid['from']);
        self::assertSame('', $invalid['to']);
        self::assertSame(100000, $invalid['earned_centavos']);

        self::assertSame(0, $earnings->summary($this->insertWeaver('nobody'))['units']);
    }
}
