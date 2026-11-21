<?php

declare(strict_types=1);

namespace Habi\Services;

use PDO;

/**
 * Customer records (PRD R42). There are no customer accounts: orders are
 * grouped by checkout email, compared case-insensitively. Contact details
 * are the latest order's; "spent" sums totals of orders that were not
 * rejected or cancelled. Detail pages use an opaque key (SHA-256 of the
 * lowercased email) so email addresses never appear in URLs or logs.
 */
final class CustomerService
{
    public const PER_PAGE = 25;

    public function __construct(private readonly PDO $pdo)
    {
    }

    public static function key(string $email): string
    {
        return hash('sha256', mb_strtolower(trim($email)));
    }

    /**
     * @param array<string, mixed> $filters q (email or name text), page
     *
     * @return array{customers: list<array{key: string, email: string, name: string, phone: string, orders: int,
     *     spent: int, last_order_at: string}>, q: string, page: int, pages: int, total: int}
     */
    public function list(array $filters): array
    {
        $q = is_string($filters['q'] ?? null) ? trim($filters['q']) : '';
        $having = '';
        $parameters = [];
        if ($q !== '') {
            $like = '%' . str_replace(['\\', '%', '_'], ['\\\\', '\%', '\_'], mb_strtolower($q)) . '%';
            $having = " WHERE g.email LIKE :q_email ESCAPE '\\' OR lower(g.name) LIKE :q_name ESCAPE '\\'";
            $parameters = ['q_email' => $like, 'q_name' => $like];
        }
        $grouped = "SELECT lower(o.customer_email) AS email, COUNT(*) AS orders,
                        COALESCE(SUM(CASE WHEN o.status NOT IN ('rejected', 'cancelled')
                            THEN o.total_centavos END), 0) AS spent,
                        MAX(o.created_at) AS last_order_at,
                        (SELECT l.customer_name FROM orders l WHERE lower(l.customer_email) = lower(o.customer_email)
                         ORDER BY l.created_at DESC, l.id DESC LIMIT 1) AS name,
                        (SELECT l.customer_phone FROM orders l WHERE lower(l.customer_email) = lower(o.customer_email)
                         ORDER BY l.created_at DESC, l.id DESC LIMIT 1) AS phone
                    FROM orders o GROUP BY lower(o.customer_email)";

        $count = $this->pdo->prepare('SELECT COUNT(*) FROM (' . $grouped . ') g' . $having);
        $count->execute($parameters);
        $total = (int) $count->fetchColumn();
        $pages = max(1, (int) ceil($total / self::PER_PAGE));
        $page = is_string($filters['page'] ?? null) && ctype_digit($filters['page']) ? (int) $filters['page'] : 1;
        $page = min(max(1, $page), $pages);

        $statement = $this->pdo->prepare(
            'SELECT * FROM (' . $grouped . ') g' . $having . ' ORDER BY g.email LIMIT :limit OFFSET :offset'
        );
        foreach ($parameters as $name => $value) {
            $statement->bindValue(':' . $name, $value);
        }
        $statement->bindValue(':limit', self::PER_PAGE, PDO::PARAM_INT);
        $statement->bindValue(':offset', ($page - 1) * self::PER_PAGE, PDO::PARAM_INT);
        $statement->execute();

        return [
            'customers' => array_map(static fn (array $row): array => [
                'key' => self::key((string) $row['email']),
                'email' => (string) $row['email'],
                'name' => (string) $row['name'],
                'phone' => (string) $row['phone'],
                'orders' => (int) $row['orders'],
                'spent' => (int) $row['spent'],
                'last_order_at' => (string) $row['last_order_at'],
            ], $statement->fetchAll()),
            'q' => $q,
            'page' => $page,
            'pages' => $pages,
            'total' => $total,
        ];
    }

    /**
     * One customer's contact details and every order, newest first, or null.
     *
     * @return array{key: string, email: string, name: string, phone: string, spent: int,
     *     orders: list<array<string, mixed>>}|null
     */
    public function find(string $key): ?array
    {
        if (preg_match('/^[a-f0-9]{64}$/', $key) !== 1) {
            return null;
        }
        $email = null;
        $emails = $this->pdo->query('SELECT DISTINCT lower(customer_email) FROM orders')->fetchAll(PDO::FETCH_COLUMN);
        foreach ($emails as $candidate) {
            if (hash_equals(self::key((string) $candidate), $key)) {
                $email = (string) $candidate;
                break;
            }
        }
        if ($email === null) {
            return null;
        }
        $statement = $this->pdo->prepare(
            'SELECT o.order_number, o.status, o.payment_method, o.payment_status, o.customer_name, o.customer_phone,
                    o.shipping_address1, o.shipping_address2, o.shipping_city, o.shipping_postal_code,
                    o.shipping_region, o.total_centavos, o.created_at,
                    (SELECT COALESCE(SUM(i.qty), 0) FROM order_items i WHERE i.order_id = o.id) AS items
             FROM orders o WHERE lower(o.customer_email) = ? ORDER BY o.created_at DESC, o.id DESC'
        );
        $statement->execute([$email]);
        $orders = $statement->fetchAll();
        $spent = 0;
        foreach ($orders as $order) {
            if (!in_array($order['status'], ['rejected', 'cancelled'], true)) {
                $spent += (int) $order['total_centavos'];
            }
        }

        return [
            'key' => $key,
            'email' => $email,
            'name' => (string) $orders[0]['customer_name'],
            'phone' => (string) $orders[0]['customer_phone'],
            'spent' => $spent,
            'orders' => $orders,
        ];
    }
}
