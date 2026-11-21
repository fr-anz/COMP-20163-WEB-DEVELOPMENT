<?php

declare(strict_types=1);

namespace Habi\Tests\Services;

use Habi\Bootstrap\HeritageUpgrade;
use Habi\Bootstrap\Initializer;
use Habi\Services\CartService;
use Habi\Services\CheckoutException;
use Habi\Services\OrderService;
use Habi\Services\ProductService;
use Habi\Support\SessionStore;
use Habi\Tests\TestSupport\IsolatedDatabaseTestCase;

/**
 * Bags of a deactivated weaver leave the storefront, cart and checkout; bags
 * owned by the inactive placeholder weaver (upgraded v1 products) stay
 * buyable until an administrator reassigns them.
 */
final class DeactivatedWeaverVisibilityTest extends IsolatedDatabaseTestCase
{
    private ProductService $products;

    protected function setUp(): void
    {
        parent::setUp();
        Initializer::initialize($this->database(), $this->migrationsDir());
        $this->products = new ProductService($this->database());
    }

    public function testDeactivatedWeaversBagsAreHiddenEverywhereCustomersSeeThem(): void
    {
        $pdo = $this->database();
        $lupa = $this->productId('lupa-satchel');
        $rosa = (int) $pdo->query("SELECT weaver_id FROM products WHERE slug = 'lupa-satchel'")->fetchColumn();
        $price = (int) $pdo->query("SELECT price_centavos FROM products WHERE slug = 'lupa-satchel'")->fetchColumn();

        $session = [];
        $cart = new CartService($pdo, new SessionStore($session));
        self::assertTrue($cart->add($lupa, 1)['ok'], 'Visible before deactivation.');

        $pdo->prepare('UPDATE weavers SET is_active = 0 WHERE id = ?')->execute([$rosa]);

        self::assertNotContains('lupa-satchel', array_column($this->products->list()['products'], 'slug'));
        self::assertNull($this->products->getBySlug('lupa-satchel'));
        self::assertNull($this->products->get3dConfig('lupa-satchel'));
        self::assertSame([], $this->products->byWeaver($rosa));
        self::assertSame([], $this->products->byFiber('tikog'));
        self::assertArrayNotHasKey('tikog', $this->products->fibers());
        self::assertNotContains('Satchel', $this->products->categories());

        self::assertFalse($cart->add($lupa, 1)['ok'], 'A hidden bag cannot be added.');

        $orders = new OrderService($pdo);
        try {
            $orders->placeOrder(
                [['product_id' => $lupa, 'quantity' => 1, 'price_centavos' => $price]],
                [
                    'name' => 'Ana', 'email' => 'ana@example.com', 'phone' => '09171234567',
                    'address1' => '1 St', 'address2' => '', 'city' => 'Quezon City',
                    'postal_code' => '1108', 'region' => 'metro_manila',
                ],
                ['method' => 'cod'],
                'key-hidden-weaver'
            );
            self::fail('A hidden bag already in a cart must not be orderable.');
        } catch (CheckoutException $exception) {
            self::assertSame(CheckoutException::REASON_INVALID, $exception->reason);
        }

        // Reactivating the weaver restores the bag.
        $pdo->prepare('UPDATE weavers SET is_active = 1 WHERE id = ?')->execute([$rosa]);
        self::assertNotNull($this->products->getBySlug('lupa-satchel'));
    }

    public function testPlaceholderOwnedBagsStayBuyable(): void
    {
        $pdo = $this->database();
        $placeholder = HeritageUpgrade::placeholderWeaverId($pdo);
        $pdo->prepare("UPDATE products SET weaver_id = ? WHERE slug = 'maya-clutch'")->execute([$placeholder]);

        self::assertNotNull($this->products->getBySlug('maya-clutch'));
        self::assertContains('maya-clutch', array_column($this->products->list()['products'], 'slug'));

        $session = [];
        $cart = new CartService($pdo, new SessionStore($session));
        self::assertTrue($cart->add($this->productId('maya-clutch'), 1)['ok']);
    }

    private function productId(string $slug): int
    {
        $statement = $this->database()->prepare('SELECT id FROM products WHERE slug = ?');
        $statement->execute([$slug]);

        return (int) $statement->fetchColumn();
    }
}
