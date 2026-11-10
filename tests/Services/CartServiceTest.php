<?php

declare(strict_types=1);

namespace Habi\Tests\Services;

use Habi\Bootstrap\Initializer;
use Habi\Services\CartService;
use Habi\Support\SessionStore;
use Habi\Tests\TestSupport\IsolatedDatabaseTestCase;
use PDO;

/**
 * Boundary tests for the real service risks: live-stock clamping, sold-out
 * / inactive / deleted removal, the cumulative add cap, malformed input and
 * session persistence semantics (same array = same session, fresh array =
 * fresh session).
 */
final class CartServiceTest extends IsolatedDatabaseTestCase
{
    private CartService $cart;

    /** @var array<string, mixed> */
    private array $session;

    protected function setUp(): void
    {
        parent::setUp();
        Initializer::initialize($this->database(), $this->migrationsDir());

        $this->session = [];
        $this->cart = new CartService($this->database(), new SessionStore($this->session));
    }

    public function testAddClampsToLiveStockWithIntegerTotals(): void
    {
        $id = $this->productId('sinag-tote');
        $price = $this->priceOf($id);
        $this->setStock($id, 3);

        $result = $this->cart->add($id, 5);

        self::assertTrue($result['ok']);
        $cart = $this->cart->cart();
        self::assertSame(1, count($cart['lines']));
        self::assertSame(3, $cart['lines'][0]['quantity']);
        self::assertSame(3, $cart['count']);
        self::assertSame(3 * $price, $cart['subtotal_centavos']);
        self::assertSame(3 * $price, $cart['lines'][0]['line_total_centavos']);
        self::assertSame($price, $cart['lines'][0]['price_centavos']);
        self::assertNotSame('', $result['message']);
    }

    public function testRepeatedAddCapsExistingPlusRequestedAtStock(): void
    {
        $id = $this->productId('alon-tote');
        $this->setStock($id, 4);

        self::assertTrue($this->cart->add($id, 2)['ok']);
        $result = $this->cart->add($id, 5);

        self::assertTrue($result['ok']);
        $cart = $this->cart->cart();
        self::assertSame(4, $cart['lines'][0]['quantity']);
        self::assertSame(4, $cart['count']);
    }

    public function testZeroQuantityAddIsRejectedWithoutMutating(): void
    {
        $id = $this->productId('tala-sling');

        $result = $this->cart->add($id, 0);

        self::assertFalse($result['ok']);
        self::assertSame(400, $result['status']);
        self::assertSame([], $this->cart->cart()['lines']);
    }

    public function testNegativeQuantityAddIsRejected(): void
    {
        $id = $this->productId('tala-sling');

        $result = $this->cart->add($id, -2);

        self::assertFalse($result['ok']);
        self::assertSame(400, $result['status']);
        self::assertSame([], $this->cart->cart()['lines']);
    }

    public function testAddOfUnknownInactiveOrSoldOutProductFails(): void
    {
        $soldOut = $this->productId('gabi-clutch');
        $this->setStock($soldOut, 0);

        $soldOutResult = $this->cart->add($soldOut, 1);
        self::assertFalse($soldOutResult['ok']);
        self::assertSame(400, $soldOutResult['status']);

        $inactive = $this->productId('sinag-tote');
        $this->setInactive($inactive);
        $inactiveResult = $this->cart->add($inactive, 1);
        self::assertFalse($inactiveResult['ok']);
        self::assertSame(404, $inactiveResult['status']);

        $unknownResult = $this->cart->add(999999, 1);
        self::assertFalse($unknownResult['ok']);
        self::assertSame(404, $unknownResult['status']);

        self::assertSame([], $this->cart->cart()['lines']);
    }

    public function testHugeRequestedQuantityIsClampedWithoutOverflow(): void
    {
        $id = $this->productId('ulap-sling');
        $this->setStock($id, 2);

        self::assertTrue($this->cart->add($id, CartService::parseQuantity('999999999'))['ok']);

        self::assertSame(2, $this->cart->cart()['count']);
    }

    public function testUpdateToZeroRemovesTheLine(): void
    {
        $id = $this->productId('maya-clutch');
        $this->cart->add($id, 2);

        $result = $this->cart->update($id, 0);

        self::assertTrue($result['ok']);
        $cart = $this->cart->cart();
        self::assertSame([], $cart['lines']);
        self::assertSame(0, $cart['count']);
    }

    public function testUpdateOfMissingLineIsRejected(): void
    {
        $id = $this->productId('maya-clutch');

        $result = $this->cart->update($id, 1);

        self::assertFalse($result['ok']);
        self::assertSame(404, $result['status']);
    }

    public function testRemoveDropsTheLineAndUnknownRemovalIsRejected(): void
    {
        $id = $this->productId('lupa-satchel');
        $this->cart->add($id, 1);

        $missing = $this->cart->remove($this->productId('sierra-satchel'));
        self::assertFalse($missing['ok']);
        self::assertSame(404, $missing['status']);

        $result = $this->cart->remove($id);
        self::assertTrue($result['ok']);
        self::assertSame(0, $this->cart->cart()['count']);
    }

    public function testStockDropClampsQuantityWithPersistentNotice(): void
    {
        $id = $this->productId('dahon-sling');
        $this->setStock($id, 5);
        $this->cart->add($id, 4);

        $this->setStock($id, 1);

        $first = $this->cart->cart();
        self::assertSame(1, $first['lines'][0]['quantity']);
        self::assertCount(1, $first['notices']);
        self::assertStringContainsString('Only 1', $first['notices'][0]);

        // Regression: the explanation survives the next read unchanged and
        // is not duplicated.
        $second = $this->cart->cart();
        self::assertSame($first['notices'], $second['notices']);
    }

    public function testOverstockAddLeavesAPersistentClampNotice(): void
    {
        $id = $this->productId('sierra-satchel');
        $this->setStock($id, 3);

        self::assertTrue($this->cart->add($id, 5)['ok']);

        $first = $this->cart->cart();
        self::assertSame(3, $first['lines'][0]['quantity']);
        self::assertCount(1, $first['notices']);
        self::assertStringContainsString('Only 3', $first['notices'][0]);

        $second = $this->cart->cart();
        self::assertSame($first['notices'], $second['notices']);
    }

    public function testSoldOutInactiveAndDeletedProductsAreRemovedWithNotices(): void
    {
        $soldOut = $this->productId('sinag-tote');
        $inactive = $this->productId('alon-tote');
        $deleted = $this->productId('lupa-satchel');
        $healthy = $this->productId('maya-clutch');
        $this->cart->add($soldOut, 1);
        $this->cart->add($inactive, 1);
        $this->cart->add($deleted, 1);
        $this->cart->add($healthy, 1);

        $this->setStock($soldOut, 0);
        $this->setInactive($inactive);
        $this->database()->prepare('DELETE FROM products WHERE id = ?')->execute([$deleted]);

        $first = $this->cart->cart();

        self::assertSame([$healthy], array_column($first['lines'], 'product_id'));
        self::assertSame(1, $first['count']);
        self::assertCount(3, $first['notices']);
        self::assertStringContainsString('sold out', $first['notices'][0]);
        self::assertStringContainsString('no longer available', $first['notices'][1]);
        self::assertStringContainsString('no longer available', $first['notices'][2]);

        // Regression: removal explanations persist on later reads, including
        // once the cart itself is emptied.
        $this->cart->remove($healthy);
        $empty = $this->cart->cart();
        self::assertSame([], $empty['lines']);
        self::assertCount(3, $empty['notices']);
        $again = $this->cart->cart();
        self::assertSame($empty['notices'], $again['notices']);
    }

    public function testCartStatePersistsAcrossServiceInstancesOnTheSameSession(): void
    {
        $id = $this->productId('araw-tote');
        $this->cart->add($id, 2);

        $sameSession = new CartService($this->database(), new SessionStore($this->session));
        $cart = $sameSession->cart();

        self::assertSame(2, $cart['count']);
        self::assertSame([$id], array_column($cart['lines'], 'product_id'));
    }

    public function testCartStateDoesNotLeakIntoAFreshSession(): void
    {
        $id = $this->productId('araw-tote');
        $this->cart->add($id, 2);

        $freshSession = [];
        $fresh = new CartService($this->database(), new SessionStore($freshSession));

        self::assertSame(0, $fresh->cart()['count']);
        self::assertSame(2, $this->cart->cart()['count']);
    }

    public function testMalformedQuantitiesAndIdsAreRejectedByTheParsers(): void
    {
        foreach (['abc', '-1', '1.5', '1e3', '+1', '99999999999999999999', ''] as $bad) {
            self::assertNull(CartService::parseQuantity($bad), (string) $bad);
        }
        self::assertNull(CartService::parseQuantity(['1']));
        self::assertNull(CartService::parseQuantity(null));
        self::assertNull(CartService::parseQuantity(-1));
        self::assertNull(CartService::parseQuantity(1000000000));

        self::assertSame(0, CartService::parseQuantity('0'));
        self::assertSame(3, CartService::parseQuantity('3'));
        self::assertSame(3, CartService::parseQuantity(3));

        self::assertNull(CartService::parseId('0'));
        self::assertNull(CartService::parseId('abc'));
        self::assertSame(7, CartService::parseId('7'));
    }

    public function testCsrfTokenIsStablePerSessionAndRejectsOthers(): void
    {
        $token = $this->cart->csrfToken();

        self::assertSame($token, $this->cart->csrfToken());
        self::assertTrue($this->cart->validateCsrf($token));
        self::assertFalse($this->cart->validateCsrf('nope'));
        self::assertFalse($this->cart->validateCsrf(null));
        self::assertFalse($this->cart->validateCsrf(''));

        $freshSession = [];
        $fresh = new CartService($this->database(), new SessionStore($freshSession));
        self::assertNotSame($token, $fresh->csrfToken());
    }

    public function testTransientMessageIsConsumedExactlyOnce(): void
    {
        $id = $this->productId('tala-sling');
        $result = $this->cart->add($id, 1);

        $this->cart->setMessage((string) $result['message']);

        $first = $this->cart->consumeMessage();
        self::assertNotNull($first);
        self::assertNull($this->cart->consumeMessage());
    }

    private function productId(string $slug): int
    {
        $statement = $this->database()->prepare('SELECT id FROM products WHERE slug = ?');
        $statement->execute([$slug]);
        $id = $statement->fetchColumn();
        self::assertNotFalse($id, "Unknown fixture slug: {$slug}");

        return (int) $id;
    }

    private function priceOf(int $productId): int
    {
        $statement = $this->database()->prepare('SELECT price_centavos FROM products WHERE id = ?');
        $statement->execute([$productId]);

        return (int) $statement->fetchColumn();
    }

    private function setStock(int $productId, int $stock): void
    {
        $this->database()
            ->prepare('UPDATE products SET stock = ? WHERE id = ?')
            ->execute([$stock, $productId]);
    }

    private function setInactive(int $productId): void
    {
        $this->database()
            ->prepare('UPDATE products SET is_active = 0 WHERE id = ?')
            ->execute([$productId]);
    }
}
