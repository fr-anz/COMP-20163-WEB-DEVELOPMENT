<?php

declare(strict_types=1);

namespace Habi\Tests\Services;

use Habi\Bootstrap\Initializer;
use Habi\Services\AdminActionException;
use Habi\Services\StockService;
use Habi\Tests\TestSupport\IsolatedDatabaseTestCase;
use Habi\Tests\TestSupport\WeaverFixtures;

/**
 * Weaver stock changes (R37, R18, N10): the same reviewed two-step change
 * as admins, recorded with the weaver as the only actor, and refused for
 * another weaver's product or a deactivated listing without writing.
 */
final class WeaverStockTest extends IsolatedDatabaseTestCase
{
    use WeaverFixtures;

    private StockService $stock;
    private int $lita;
    private int $rosa;

    protected function setUp(): void
    {
        parent::setUp();
        Initializer::initialize($this->database(), $this->migrationsDir());
        $this->stock = new StockService($this->database());
        $this->lita = $this->insertWeaver('lita');
        $this->rosa = $this->insertWeaver('rosa');
    }

    public function testWeaverChangeWritesOneAuditRowWithTheWeaverAsActor(): void
    {
        $id = $this->insertWeaverProduct($this->lita, 'LITA-1', 3);

        $preview = $this->stock->preview($id, '+2', 'Restock received', 'two more woven', $this->lita);
        self::assertSame(5, $preview['new_stock']);

        $result = $this->stock->adjustAsWeaver($id, $this->lita, 3, '+2', 'Restock received', 'two more woven');
        self::assertSame(['old_stock' => 3, 'new_stock' => 5], $result);

        $rows = $this->database()->query('SELECT * FROM stock_audit WHERE product_id = ' . $id)->fetchAll();
        self::assertCount(1, $rows);
        self::assertSame($this->lita, (int) $rows[0]['weaver_id']);
        self::assertNull($rows[0]['admin_id']);
        self::assertSame('Restock received — two more woven', $rows[0]['reason']);

        $entry = $this->stock->audit(1, $id)['entries'][0];
        self::assertSame('Lita Weaver', $entry['actor']);
    }

    public function testStaleExpectedStockIsRefusedWithTheCurrentValue(): void
    {
        $id = $this->insertWeaverProduct($this->lita, 'LITA-2', 3);
        try {
            $this->stock->adjustAsWeaver($id, $this->lita, 4, '-1', 'Count correction', '');
            self::fail('A stale review must be refused.');
        } catch (AdminActionException $stale) {
            self::assertSame(3, $stale->context['current_stock']);
        }
        self::assertSame(0, (int) $this->database()->query('SELECT COUNT(*) FROM stock_audit')->fetchColumn());
    }

    public function testAnotherWeaversProductOrADeactivatedListingIsRefusedWithoutWriting(): void
    {
        $rosaBag = $this->insertWeaverProduct($this->rosa, 'ROSA-1', 3);
        $deactivated = $this->insertWeaverProduct($this->lita, 'LITA-3', 3, 'deactivated');
        $before = (int) $this->database()->query('SELECT COUNT(*) FROM stock_audit')->fetchColumn();

        foreach ([$rosaBag, $deactivated] as $id) {
            try {
                $this->stock->preview($id, '1', 'Restock received', '', $this->lita);
                self::fail('Preview must be scoped to the weaver\'s editable listings.');
            } catch (AdminActionException) {
                // Expected.
            }
            try {
                $this->stock->adjustAsWeaver($id, $this->lita, 3, '1', 'Restock received', '');
                self::fail('Adjust must be scoped to the weaver\'s editable listings.');
            } catch (AdminActionException) {
                // Expected.
            }
        }
        self::assertSame(3, (int) $this->database()->query(
            'SELECT stock FROM products WHERE id = ' . $rosaBag
        )->fetchColumn());
        self::assertSame($before, (int) $this->database()->query('SELECT COUNT(*) FROM stock_audit')->fetchColumn());
    }
}
