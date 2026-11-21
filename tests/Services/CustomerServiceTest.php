<?php

declare(strict_types=1);

namespace Habi\Tests\Services;

use Habi\Bootstrap\Initializer;
use Habi\Services\CustomerService;
use Habi\Tests\TestSupport\IsolatedDatabaseTestCase;

/**
 * Customer records grouped by checkout email (PRD R42): case-insensitive
 * grouping, latest contact details, order counts and sales excluding
 * rejected/cancelled orders, search, and detail by an opaque key.
 */
final class CustomerServiceTest extends IsolatedDatabaseTestCase
{
    private CustomerService $customers;

    protected function setUp(): void
    {
        parent::setUp();
        Initializer::initialize($this->database(), $this->migrationsDir());
        $this->customers = new CustomerService($this->database());
        $this->order('H-20261101-0001', 'Maria@Example.com', 'Maria C.', 'confirmed', 100000, '2026-11-01 10:00:00');
        $this->order('H-20261102-0001', 'maria@example.com', 'Maria Clara', 'delivered', 50000, '2026-11-02 10:00:00');
        $this->order('H-20261103-0001', 'maria@example.com', 'Maria Clara', 'rejected', 70000, '2026-11-03 10:00:00');
        $this->order('H-20261104-0001', 'juan@example.com', 'Juan Cruz', 'pending', 30000, '2026-11-04 10:00:00');
    }

    public function testOrdersAreGroupedByEmailCaseInsensitively(): void
    {
        $list = $this->customers->list([]);

        self::assertSame(2, $list['total']);
        self::assertSame(['juan@example.com', 'maria@example.com'], array_column($list['customers'], 'email'));
        $maria = $list['customers'][1];
        self::assertSame('Maria Clara', $maria['name'], 'The latest name wins.');
        self::assertSame(3, $maria['orders']);
        self::assertSame(150000, $maria['spent'], 'Rejected orders are not sales.');
        self::assertSame('2026-11-03 10:00:00', $maria['last_order_at']);
        self::assertSame(64, strlen($maria['key']));
    }

    public function testSearchMatchesEmailOrName(): void
    {
        $emails = fn (string $q): array => array_column($this->customers->list(['q' => $q])['customers'], 'email');
        self::assertSame(['juan@example.com'], $emails('JUAN'));
        self::assertSame(['maria@example.com'], $emails('clara'));
        self::assertSame([], $this->customers->list(['q' => 'nobody'])['customers']);
        self::assertSame(0, $this->customers->list(['q' => '%'])['total'], 'A literal % matches nothing.');
    }

    public function testDetailListsEveryOrderNewestFirst(): void
    {
        $key = $this->customers->list(['q' => 'maria'])['customers'][0]['key'];

        $customer = $this->customers->find($key);

        self::assertNotNull($customer);
        self::assertSame('maria@example.com', $customer['email']);
        self::assertSame('0917 000 0000', $customer['phone']);
        self::assertSame(
            ['H-20261103-0001', 'H-20261102-0001', 'H-20261101-0001'],
            array_column($customer['orders'], 'order_number')
        );
        self::assertNull($this->customers->find(str_repeat('0', 64)));
        self::assertNull($this->customers->find('not-a-key'));
    }

    private function order(string $number, string $email, string $name, string $status, int $total, string $at): void
    {
        $this->database()->prepare(
            "INSERT INTO orders (order_number, status, payment_method, payment_status, customer_name, customer_email,
                 customer_phone, shipping_region, subtotal_centavos, total_centavos, created_at, updated_at)
             VALUES (?, ?, 'cod', 'unpaid', ?, ?, '0917 000 0000', 'luzon', ?, ?, ?, ?)"
        )->execute([$number, $status, $name, $email, $total, $total, $at, $at]);
    }
}
