<?php

declare(strict_types=1);

namespace Habi\Services;

use PDO;

/**
 * Orders containing a weaver's items (R38, N4, N10).
 *
 * Rows are selected from order_items by `order_items.weaver_id = :current`
 * (the snapshot copied at placement), joined to their order for the R38
 * columns only: reference, date, order and payment status and the delivery
 * city/region. Customer name, email, phone and address lines are never
 * selected, and other weavers' lines in the same order are never returned.
 * Weavers do not change order status.
 */
final class WeaverOrderService
{
    public const PER_PAGE = 20;

    public function __construct(private readonly PDO $pdo)
    {
    }

    /**
     * Newest-first orders with the weaver's own units and line total.
     *
     * @return array{orders: list<array<string, mixed>>, page: int, pages: int, total: int}
     */
    public function list(int $weaverId, int $page = 1): array
    {
        $count = $this->pdo->prepare('SELECT COUNT(DISTINCT order_id) FROM order_items WHERE weaver_id = :weaver');
        $count->execute(['weaver' => $weaverId]);
        $total = (int) $count->fetchColumn();
        $pages = max(1, (int) ceil($total / self::PER_PAGE));
        $page = min(max(1, $page), $pages);

        return [
            'orders' => $this->summaries($weaverId, self::PER_PAGE, ($page - 1) * self::PER_PAGE),
            'page' => $page,
            'pages' => $pages,
            'total' => $total,
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function recent(int $weaverId, int $limit = 5): array
    {
        return $this->summaries($weaverId, $limit, 0);
    }

    /**
     * One order by reference with only the weaver's lines, or null when it
     * does not exist or holds none of their items.
     *
     * @return array<string, mixed>|null
     */
    public function find(int $weaverId, string $orderNumber): ?array
    {
        $statement = $this->pdo->prepare(
            'SELECT o.order_number, o.created_at, o.status, o.payment_status, o.shipping_city, o.shipping_region,
                    oi.id AS item_id, oi.name_snapshot, oi.price_centavos, oi.qty, oi.line_total_centavos
             FROM order_items oi JOIN orders o ON o.id = oi.order_id
             WHERE o.order_number = :number AND oi.weaver_id = :weaver
             ORDER BY oi.id ASC'
        );
        $statement->execute(['number' => $orderNumber, 'weaver' => $weaverId]);
        $rows = $statement->fetchAll();
        if ($rows === []) {
            return null;
        }
        $items = array_map(static fn (array $row): array => [
            'name_snapshot' => (string) $row['name_snapshot'],
            'price_centavos' => (int) $row['price_centavos'],
            'qty' => (int) $row['qty'],
            'line_total_centavos' => (int) $row['line_total_centavos'],
        ], $rows);

        return self::header($rows[0]) + [
            'items' => $items,
            'units' => array_sum(array_column($items, 'qty')),
            'total_centavos' => array_sum(array_column($items, 'line_total_centavos')),
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function summaries(int $weaverId, int $limit, int $offset): array
    {
        $statement = $this->pdo->prepare(
            'SELECT o.order_number, o.created_at, o.status, o.payment_status, o.shipping_city, o.shipping_region,
                    SUM(oi.qty) AS units, SUM(oi.line_total_centavos) AS total_centavos
             FROM order_items oi JOIN orders o ON o.id = oi.order_id
             WHERE oi.weaver_id = :weaver
             GROUP BY o.id
             ORDER BY o.created_at DESC, o.id DESC
             LIMIT :limit OFFSET :offset'
        );
        $statement->bindValue(':weaver', $weaverId, PDO::PARAM_INT);
        $statement->bindValue(':limit', $limit, PDO::PARAM_INT);
        $statement->bindValue(':offset', $offset, PDO::PARAM_INT);
        $statement->execute();

        return array_map(static fn (array $row): array => self::header($row) + [
            'units' => (int) $row['units'],
            'total_centavos' => (int) $row['total_centavos'],
        ], $statement->fetchAll());
    }

    /**
     * @param array<string, mixed> $row
     *
     * @return array{order_number: string, created_at: string, status: string, payment_status: string,
     *     shipping_city: string, shipping_region: string}
     */
    private static function header(array $row): array
    {
        return [
            'order_number' => (string) $row['order_number'],
            'created_at' => (string) $row['created_at'],
            'status' => (string) $row['status'],
            'payment_status' => (string) $row['payment_status'],
            'shipping_city' => (string) $row['shipping_city'],
            'shipping_region' => (string) $row['shipping_region'],
        ];
    }
}
