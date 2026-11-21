<?php

declare(strict_types=1);

namespace Habi\Tests\TestSupport;

use PDO;

/**
 * Weaver, product and order rows for weaver-module tests (M12). Requires an
 * initialized database; used from IsolatedDatabaseTestCase subclasses.
 */
trait WeaverFixtures
{
    abstract protected function database(): PDO;

    protected function insertWeaver(string $slug, string $password = 'weaver-password', bool $active = true): int
    {
        $pdo = $this->database();
        $pdo->prepare(
            'INSERT INTO weavers (email, password_hash, slug, display_name, community, region, is_active)
             VALUES (?, ?, ?, ?, ?, ?, ?)'
        )->execute([
            $slug . '@weavers.test',
            password_hash($password, PASSWORD_DEFAULT),
            $slug,
            ucfirst($slug) . ' Weaver',
            ucfirst($slug) . ' community',
            'Eastern Visayas (Region VIII)',
            $active ? 1 : 0,
        ]);

        return (int) $pdo->lastInsertId();
    }

    protected function insertWeaverProduct(
        int $weaverId,
        string $sku,
        int $stock = 5,
        string $listingStatus = 'active',
        int $priceCentavos = 150000,
    ): int {
        $pdo = $this->database();
        $pdo->prepare(
            "INSERT INTO products (sku, slug, name, weaver_id, category_id, category, price_centavos, stock,
                 listing_status, dimensions, three_config)
             VALUES (?, ?, ?, ?, (SELECT id FROM categories WHERE name = 'Tote'), 'Tote', ?, ?, ?,
                 '{\"width\":30,\"height\":25,\"depth\":10}', '{\"shape\":\"tote\"}')"
        )->execute([$sku, strtolower($sku), 'Bag ' . $sku, $weaverId, $priceCentavos, $stock, $listingStatus]);

        return (int) $pdo->lastInsertId();
    }

    /**
     * An order with one line per [productId, weaverId, qty, unitCentavos] entry.
     *
     * @param list<array{0: int, 1: int, 2: int, 3: int}> $lines
     */
    protected function insertWeaverOrder(
        string $number,
        array $lines,
        string $status = 'pending',
        string $paymentStatus = 'unpaid',
        string $createdAt = '2026-11-20 10:00:00',
    ): int {
        $pdo = $this->database();
        $subtotal = array_sum(array_map(static fn (array $line): int => $line[2] * $line[3], $lines));
        $pdo->prepare(
            "INSERT INTO orders (order_number, status, payment_method, payment_status, customer_name, customer_email,
                 customer_phone, shipping_address1, shipping_city, shipping_postal_code, shipping_region,
                 shipping_fee_centavos, subtotal_centavos, total_centavos, created_at, updated_at)
             VALUES (?, ?, 'cod', ?, 'Maria Buyer', 'buyer@example.test', '09171234567', '12 Secret Street',
                 'Tacloban', '6500', 'visayas', 22000, ?, ?, ?, ?)"
        )->execute([$number, $status, $paymentStatus, $subtotal, $subtotal + 22000, $createdAt, $createdAt]);
        $orderId = (int) $pdo->lastInsertId();
        $item = $pdo->prepare(
            'INSERT INTO order_items (order_id, product_id, name_snapshot, price_centavos, qty, line_total_centavos,
                 weaver_id, weaver_name_snapshot)
             VALUES (?, ?, (SELECT name FROM products WHERE id = ?), ?, ?, ?, ?,
                 (SELECT display_name FROM weavers WHERE id = ?))'
        );
        foreach ($lines as [$productId, $weaverId, $qty, $unit]) {
            $item->execute([$orderId, $productId, $productId, $unit, $qty, $qty * $unit, $weaverId, $weaverId]);
        }

        return $orderId;
    }
}
