<?php

declare(strict_types=1);

namespace Habi\Tests\Bootstrap;

use Habi\Bootstrap\Initializer;
use Habi\Tests\TestSupport\IsolatedDatabaseTestCase;
use PDO;

/**
 * A2 boundary: once the demo data exists, later boots must preserve every
 * local change — no catalog reinsert, no administrator overwrite.
 */
final class PreservationTest extends IsolatedDatabaseTestCase
{
    public function testLaterBootsPreserveChangedInventoryAndAdministrator(): void
    {
        $pdo = $this->database();
        Initializer::initialize($pdo, $this->migrationsDir());

        // Simulate a session of local edits: price change, stock change,
        // product removal, administrator rename.
        $pdo->exec('UPDATE products SET price_centavos = 999999 WHERE slug = \'sinag-tote\'');
        $pdo->exec('UPDATE products SET stock = 1 WHERE slug = \'sinag-tote\'');
        $pdo->exec('DELETE FROM products WHERE slug = \'dahon-sling\'');
        $pdo->exec('UPDATE admins SET name = \'Renamed administrator\'');

        Initializer::initialize($pdo, $this->migrationsDir());

        self::assertSame(
            999999,
            (int) $this->column($pdo, 'price_centavos', 'sinag-tote'),
            'Later boot must not overwrite a changed price.'
        );
        self::assertSame(
            1,
            (int) $this->column($pdo, 'stock', 'sinag-tote'),
            'Later boot must not overwrite changed stock.'
        );
        self::assertSame(
            9,
            (int) $pdo->query('SELECT COUNT(*) FROM products')->fetchColumn(),
            'Later boot must not reinsert removed products.'
        );
        self::assertSame(
            0,
            (int) $pdo->query(
                "SELECT COUNT(*) FROM products WHERE slug = 'dahon-sling'"
            )->fetchColumn()
        );
        self::assertSame(
            'Renamed administrator',
            (string) $pdo->query('SELECT name FROM admins')->fetchColumn(),
            'Later boot must not overwrite the administrator.'
        );
        self::assertSame(
            1,
            (int) $pdo->query('SELECT COUNT(*) FROM admins')->fetchColumn(),
            'Later boot must not insert a second administrator.'
        );
    }

    private function column(PDO $pdo, string $column, string $slug): bool|int|string|null
    {
        $statement = $pdo->prepare('SELECT ' . $column . ' FROM products WHERE slug = ?');
        $statement->execute([$slug]);

        return $statement->fetchColumn();
    }
}
