<?php

declare(strict_types=1);

namespace Habi\Services;

use PDO;

/**
 * Weaver earnings summary (R39, SDD §15.7).
 *
 * Sums the weaver's own order_items.line_total_centavos (selected by
 * order_items.weaver_id): earned = delivered orders with payment paid;
 * pending = every other order that is not rejected or cancelled (pending,
 * confirmed, packed, shipped, and delivered but not yet paid). Units sold
 * count both. Shipping fees live on the order and are never included.
 * Habi takes no commission, so the weaver's share is 100%. The optional
 * period filters by order placement date (inclusive UTC dates as stored).
 */
final class EarningsService
{
    public const SHARE_PERCENT = 100;

    public function __construct(private readonly PDO $pdo)
    {
    }

    /**
     * @return array{earned_centavos: int, pending_centavos: int, units: int, share_percent: int,
     *     items: list<array<string, mixed>>, from: string, to: string}
     */
    public function summary(int $weaverId, mixed $from = null, mixed $to = null): array
    {
        $from = self::date($from);
        $to = self::date($to);
        $conditions = ['oi.weaver_id = :weaver', "o.status NOT IN ('rejected', 'cancelled')"];
        $parameters = ['weaver' => $weaverId];
        if ($from !== '') {
            $conditions[] = 'o.created_at >= :from';
            $parameters['from'] = $from;
        }
        if ($to !== '') {
            $conditions[] = "o.created_at < date(:to, '+1 day')";
            $parameters['to'] = $to;
        }
        $statement = $this->pdo->prepare(
            "SELECT o.order_number, o.created_at, o.status, o.payment_status, oi.name_snapshot, oi.qty,
                    oi.line_total_centavos,
                    CASE WHEN o.status = 'delivered' AND o.payment_status = 'paid'
                         THEN 'earned' ELSE 'pending' END AS bucket
             FROM order_items oi JOIN orders o ON o.id = oi.order_id
             WHERE " . implode(' AND ', $conditions) . '
             ORDER BY o.created_at DESC, o.id DESC, oi.id ASC'
        );
        $statement->execute($parameters);

        $summary = ['earned_centavos' => 0, 'pending_centavos' => 0, 'units' => 0];
        $items = [];
        foreach ($statement->fetchAll() as $row) {
            $item = [
                'order_number' => (string) $row['order_number'],
                'created_at' => (string) $row['created_at'],
                'status' => (string) $row['status'],
                'payment_status' => (string) $row['payment_status'],
                'name_snapshot' => (string) $row['name_snapshot'],
                'qty' => (int) $row['qty'],
                'line_total_centavos' => (int) $row['line_total_centavos'],
                'bucket' => (string) $row['bucket'],
            ];
            $summary[$item['bucket'] . '_centavos'] += $item['line_total_centavos'];
            $summary['units'] += $item['qty'];
            $items[] = $item;
        }

        return $summary + [
            'share_percent' => self::SHARE_PERCENT,
            'items' => $items,
            'from' => $from,
            'to' => $to,
        ];
    }

    /** A valid YYYY-MM-DD date, or '' for anything else. */
    private static function date(mixed $value): string
    {
        if (!is_string($value) || preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) !== 1) {
            return '';
        }
        [$year, $month, $day] = array_map('intval', explode('-', $value));

        return checkdate($month, $day, $year) ? $value : '';
    }
}
