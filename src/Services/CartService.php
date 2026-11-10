<?php

declare(strict_types=1);

namespace Habi\Services;

use Habi\Support\Csrf;
use Habi\Support\SessionStore;
use PDO;

/**
 * Session cart (M4, PRD R7/R8/R9/R17/R28). The cart lives in the PHP session
 * as a map of product_id => quantity; nothing is persisted to the database
 * and no stock is reserved or decremented.
 *
 * Every read and every mutation reconciles the whole cart against live
 * catalog state: unavailable (deleted/inactive) and sold-out products are
 * removed and quantities above live stock are clamped, each with a
 * persistent explanatory notice. Persistent notices stay in the session
 * until their condition resolves (stock replenished, quantity lowered,
 * product re-added) and are never consumed on render. Mutation feedback
 * ("Added ...") is a separate one-shot message consumed when rendered.
 *
 * Money is integer centavos throughout; prices and stock always come from
 * the database, never from the client.
 */
final class CartService
{
    // Upper bound for a single quantity; keeps existing+requested arithmetic
    // overflow-free (9 digits, well below PHP_INT_MAX).
    private const MAX_QUANTITY = 999999999;

    private const CART_KEY = 'cart';
    private const NOTICES_KEY = 'cart_notices';
    private const MESSAGE_KEY = 'cart_message';

    private const NOTICE_TYPES = ['clamped', 'removed_soldout', 'removed_unavailable'];

    public function __construct(
        private readonly PDO $pdo,
        private readonly SessionStore $session,
    ) {
    }

    /**
     * Adds a quantity of a product. Repeated adds accumulate and the sum is
     * capped at live stock.
     *
     * @return array{ok: bool, status: int, error: string|null, message: string|null}
     */
    public function add(int $productId, int $quantity): array
    {
        if ($quantity < 1) {
            return $this->failure(400, 'Enter a quantity of 1 or more.');
        }

        $this->reconcile();

        $product = $this->fetchProduct($productId);
        if ($product === null || (int) $product['is_active'] !== 1) {
            return $this->failure(404, 'This bag is no longer available.');
        }

        $name = (string) $product['name'];
        $stock = (int) $product['stock'];
        if ($stock <= 0) {
            return $this->failure(
                400,
                sprintf('%s is sold out and cannot be added to your bag.', $name)
            );
        }

        $quantities = $this->storedQuantities();
        $current = $quantities[$productId] ?? 0;
        $requested = $current + $quantity;
        $target = min($requested, $stock);
        $quantities[$productId] = $target;
        $this->writeQuantities($quantities);

        // The pre-clamped value equals stock, so reconcile() itself would
        // not flag it; record the correction explicitly so the explanation
        // persists with the cart.
        $extra = [];
        if ($target < $requested) {
            $extra[] = [
                'type' => 'clamped',
                'product_id' => $productId,
                'text' => sprintf(
                    'Only %d of %s in stock — the quantity in your bag was'
                    . ' adjusted to what is available.',
                    $stock,
                    $name
                ),
            ];
        }
        $this->reconcile($extra);

        if ($target > $current && $target === $requested) {
            $message = sprintf('Added %s to your bag.', $name);
        } elseif ($target > $current) {
            $message = sprintf(
                'Only %d of %s in stock — your bag now holds all of them.',
                $stock,
                $name
            );
        } else {
            $message = sprintf(
                'Only %d of %s in stock — your bag already has every piece.',
                $stock,
                $name
            );
        }

        return $this->success($message);
    }

    /**
     * Sets a line's quantity; 0 removes the line.
     *
     * @return array{ok: bool, status: int, error: string|null, message: string|null}
     */
    public function update(int $productId, int $quantity): array
    {
        if ($quantity < 0) {
            return $this->failure(400, 'Quantity cannot be negative.');
        }

        $this->reconcile();

        $quantities = $this->storedQuantities();
        if (!isset($quantities[$productId])) {
            return $this->failure(404, 'This bag is not in your cart.');
        }

        $name = $this->productName($productId) ?? 'This bag';

        if ($quantity === 0) {
            unset($quantities[$productId]);
            $this->writeQuantities($quantities);
            $this->reconcile();

            return $this->success(sprintf('Removed %s from your bag.', $name));
        }

        $quantities[$productId] = min($quantity, self::MAX_QUANTITY);
        $this->writeQuantities($quantities);
        $this->reconcile();

        return $this->success(sprintf('Updated the quantity of %s.', $name));
    }

    /**
     * Removes a line outright.
     *
     * @return array{ok: bool, status: int, error: string|null, message: string|null}
     */
    public function remove(int $productId): array
    {
        $this->reconcile();

        $quantities = $this->storedQuantities();
        if (!isset($quantities[$productId])) {
            return $this->failure(404, 'This bag is not in your cart.');
        }

        $name = $this->productName($productId) ?? 'This bag';
        unset($quantities[$productId]);
        $this->writeQuantities($quantities);
        $this->reconcile();

        return $this->success(sprintf('Removed %s from your bag.', $name));
    }

    /**
     * The reconciled cart: lines in add order, integer totals, and the
     * persistent stock-correction notices.
     *
     * @return array{lines: list<array<string, mixed>>, count: int, subtotal_centavos: int, notices: list<string>}
     */
    public function cart(): array
    {
        return $this->reconcile();
    }

    /**
     * Parses a submitted quantity. Accepts integers in [0, 999999999] and
     * their decimal-string form (native form posts, surrounding whitespace
     * tolerated); anything else — negative, fractional, overlong,
     * non-numeric, arrays — is null.
     */
    public static function parseQuantity(mixed $value): ?int
    {
        if (is_int($value)) {
            if ($value >= 0 && $value <= self::MAX_QUANTITY) {
                return $value;
            }

            return null;
        }

        if (!is_string($value)) {
            return null;
        }

        $trimmed = trim($value);
        if (preg_match('/^\d{1,9}$/', $trimmed) !== 1) {
            return null;
        }

        return (int) $trimmed;
    }

    /**
     * Parses a submitted product id: a positive integer (or its decimal
     * string); anything else is null.
     */
    public static function parseId(mixed $value): ?int
    {
        $parsed = self::parseQuantity($value);
        if ($parsed === null || $parsed < 1) {
            return null;
        }

        return $parsed;
    }

    public function csrfToken(): string
    {
        return Csrf::token($this->session);
    }

    public function validateCsrf(?string $submitted): bool
    {
        return Csrf::validate($this->session, $submitted);
    }

    /**
     * One-shot mutation feedback for native redirects; consumed on render.
     */
    public function setMessage(string $message): void
    {
        $this->session->set(self::MESSAGE_KEY, $message);
    }

    public function consumeMessage(): ?string
    {
        $message = $this->session->get(self::MESSAGE_KEY);
        $this->session->unset(self::MESSAGE_KEY);

        if (is_string($message) && $message !== '') {
            return $message;
        }

        return null;
    }

    /**
     * Re-reads every cart line from the catalog, drops unavailable,
     * inactive and sold-out products, clamps quantities to live stock and
     * merges the resulting corrections (plus any caller-supplied ones, e.g.
     * from an overstock add) into the persistent notice list.
     *
     * @return array{lines: list<array<string, mixed>>, count: int, subtotal_centavos: int, notices: list<string>}
     */
    private function reconcile(array $extraCorrections = []): array
    {
        $quantities = $this->storedQuantities();
        $products = $quantities === []
            ? []
            : $this->productsByIds(array_keys($quantities));

        $lines = [];
        $kept = [];
        $corrections = [];

        foreach ($quantities as $productId => $quantity) {
            $product = $products[$productId] ?? null;

            if ($product === null || (int) $product['is_active'] !== 1) {
                $corrections[] = [
                    'type' => 'removed_unavailable',
                    'product_id' => $productId,
                    'text' => 'A bag that is no longer available was removed'
                        . ' from your cart.',
                ];
                continue;
            }

            $name = (string) $product['name'];
            $stock = (int) $product['stock'];

            if ($stock <= 0) {
                $corrections[] = [
                    'type' => 'removed_soldout',
                    'product_id' => $productId,
                    'text' => sprintf(
                        '%s is sold out and was removed from your cart.',
                        $name
                    ),
                ];
                continue;
            }

            if ($quantity > $stock) {
                $quantity = $stock;
                $corrections[] = [
                    'type' => 'clamped',
                    'product_id' => $productId,
                    'text' => sprintf(
                        'Only %d of %s left in stock — your quantity was'
                        . ' adjusted.',
                        $stock,
                        $name
                    ),
                ];
            }

            $kept[$productId] = $quantity;

            $price = (int) $product['price_centavos'];
            $lines[] = [
                'product_id' => $productId,
                'slug' => (string) $product['slug'],
                'name' => $name,
                'image_path' => $product['image_path'] === null
                    ? null
                    : (string) $product['image_path'],
                'materials' => (string) $product['materials'],
                'price_centavos' => $price,
                'stock' => $stock,
                'quantity' => $quantity,
                'line_total_centavos' => $price * $quantity,
            ];
        }

        $notices = $this->mergeNotices(array_merge($corrections, $extraCorrections));
        $this->writeQuantities($kept);
        $this->session->set(self::NOTICES_KEY, $notices);

        return [
            'lines' => $lines,
            'count' => array_sum($kept),
            'subtotal_centavos' => array_sum(array_column($lines, 'line_total_centavos')),
            'notices' => array_column($notices, 'text'),
        ];
    }

    /**
     * Persistent notices: historical correction notices are retained in the
     * session (including when the cart empties), new corrections are
     * appended without duplicates.
     *
     * @param list<array{type: string, product_id: int, text: string}> $corrections
     *
     * @return list<array{type: string, product_id: int, text: string}>
     */
    private function mergeNotices(array $corrections): array
    {
        $notices = $this->storedNotices();

        foreach ($corrections as $correction) {
            $duplicate = false;
            foreach ($notices as $existing) {
                $sameType = $existing['type'] === $correction['type'];
                $sameProduct = $existing['product_id'] === $correction['product_id'];
                if ($sameType && $sameProduct) {
                    $duplicate = true;
                    break;
                }
            }
            if (!$duplicate) {
                $notices[] = $correction;
            }
        }

        return $notices;
    }

    /**
     * The stored persistent notices, sanitized to the known shape.
     *
     * @return list<array{type: string, product_id: int, text: string}>
     */
    private function storedNotices(): array
    {
        $stored = $this->session->get(self::NOTICES_KEY);
        if (!is_array($stored)) {
            return [];
        }

        $notices = [];
        foreach ($stored as $notice) {
            $valid = is_array($notice)
                && isset($notice['type'], $notice['product_id'], $notice['text'])
                && in_array($notice['type'], self::NOTICE_TYPES, true)
                && is_int($notice['product_id'])
                && is_string($notice['text']);
            if (!$valid) {
                continue;
            }
            $notices[] = [
                'type' => $notice['type'],
                'product_id' => $notice['product_id'],
                'text' => $notice['text'],
            ];
        }

        return $notices;
    }

    /**
     * The stored cart map, sanitized: integer product ids of at least 1
     * mapped to quantities in [1, MAX_QUANTITY]. Anything else is dropped.
     *
     * @return array<int, int>
     */
    private function storedQuantities(): array
    {
        $stored = $this->session->get(self::CART_KEY);
        if (!is_array($stored)) {
            return [];
        }

        $quantities = [];
        foreach ($stored as $productId => $quantity) {
            $id = filter_var($productId, FILTER_VALIDATE_INT);
            $qty = filter_var($quantity, FILTER_VALIDATE_INT);
            if (!is_int($id) || $id < 1 || !is_int($qty) || $qty < 1) {
                continue;
            }
            if ($qty > self::MAX_QUANTITY) {
                continue;
            }
            $quantities[$id] = $qty;
        }

        return $quantities;
    }

    /**
     * @param array<int, int> $quantities
     */
    private function writeQuantities(array $quantities): void
    {
        $this->session->set(self::CART_KEY, $quantities);
    }

    /**
     * Catalog rows for the given ids (including inactive ones, so the
     * reconciliation can tell "inactive" from "deleted"), each with its
     * primary image path.
     *
     * @param list<int> $productIds
     *
     * @return array<int, array<string, mixed>>
     */
    private function productsByIds(array $productIds): array
    {
        if ($productIds === []) {
            return [];
        }

        $placeholders = implode(', ', array_fill(0, count($productIds), '?'));
        $statement = $this->pdo->prepare(
            'SELECT p.id, p.slug, p.name, p.materials, p.price_centavos,'
            . ' p.stock, p.is_active,'
            . ' (SELECT pi.path FROM product_images pi'
            . '  WHERE pi.product_id = p.id'
            . '  ORDER BY pi.is_primary DESC, pi.sort_order ASC, pi.id ASC'
            . '  LIMIT 1) AS image_path'
            . ' FROM products p'
            . ' WHERE p.id IN (' . $placeholders . ')'
        );
        $statement->execute($productIds);

        $products = [];
        foreach ($statement->fetchAll() as $row) {
            $products[(int) $row['id']] = $row;
        }

        return $products;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function fetchProduct(int $productId): ?array
    {
        return $this->productsByIds([$productId])[$productId] ?? null;
    }

    private function productName(int $productId): ?string
    {
        $product = $this->fetchProduct($productId);
        if ($product === null) {
            return null;
        }

        return (string) $product['name'];
    }

    /**
     * @return array{ok: bool, status: int, error: string|null, message: string|null}
     */
    private function success(string $message): array
    {
        return ['ok' => true, 'status' => 200, 'error' => null, 'message' => $message];
    }

    /**
     * @return array{ok: bool, status: int, error: string|null, message: string|null}
     */
    private function failure(int $status, string $error): array
    {
        return ['ok' => false, 'status' => $status, 'error' => $error, 'message' => null];
    }
}
