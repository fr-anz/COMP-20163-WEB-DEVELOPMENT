<?php

declare(strict_types=1);

namespace Habi\Bootstrap;

use PDO;

/**
 * Inserts the sample weavers, journal articles, native-fiber catalog and the
 * first-boot administrator inside the caller's transaction (categories come
 * from HeritageUpgrade). Credentials come exclusively from the environment;
 * they are never defaulted, logged, or echoed.
 */
final class Seeder
{
    /**
     * @param PDO $pdo connection with foreign_keys enabled
     */
    public static function seed(PDO $pdo): void
    {
        self::insertAdmin($pdo, self::adminFromEnv());
        self::insertCatalog($pdo, self::insertWeavers($pdo));
        self::insertArticles($pdo);
    }

    /**
     * Reads and validates the first-boot administrator settings from the
     * environment. Throws before any data is written, so callers can check
     * credentials before performing destructive work.
     *
     * @return array{email: string, password_hash: string, name: string}
     */
    public static function adminFromEnv(): array
    {
        $email = trim((string) env('ADMIN_SEED_EMAIL', ''));
        $hash = trim((string) env('ADMIN_SEED_PASSWORD_HASH', ''));
        $name = trim((string) env('ADMIN_SEED_NAME', ''));

        if ($email === '' || $hash === '') {
            throw new MissingAdminCredentialsException(
                'ADMIN_SEED_EMAIL and ADMIN_SEED_PASSWORD_HASH must both be set to initialize an empty database.'
            );
        }

        if (password_get_info($hash)['algoName'] === 'unknown') {
            throw new MissingAdminCredentialsException(
                'ADMIN_SEED_PASSWORD_HASH must be a PHP password_hash() value, not a plaintext password.'
            );
        }

        return [
            'email' => $email,
            'password_hash' => $hash,
            'name' => $name !== '' ? $name : 'Demo administrator',
        ];
    }

    /**
     * @param array{email: string, password_hash: string, name: string} $admin
     */
    private static function insertAdmin(PDO $pdo, array $admin): void
    {
        $statement = $pdo->prepare(
            'INSERT INTO admins (email, password_hash, name) VALUES (:email, :password_hash, :name)'
        );
        $statement->execute([
            'email' => $admin['email'],
            'password_hash' => $admin['password_hash'],
            'name' => $admin['name'],
        ]);
    }

    /**
     * @return array<string, array{id: int, community: string, region: string}> keyed by slug
     */
    private static function insertWeavers(PDO $pdo): array
    {
        $statement = $pdo->prepare(
            "INSERT INTO weavers (email, password_hash, slug, display_name, community, region, fibers,
                 techniques, story, is_active)
             VALUES (:email, '!', :slug, :display_name, :community, :region, :fibers, :techniques, :story, 1)"
        );
        $weavers = [];
        foreach (SampleContent::weavers() as $weaver) {
            $statement->execute($weaver);
            $weavers[$weaver['slug']] = [
                'id' => (int) $pdo->lastInsertId(),
                'community' => $weaver['community'],
                'region' => $weaver['region'],
            ];
        }

        return $weavers;
    }

    private static function insertArticles(PDO $pdo): void
    {
        $statement = $pdo->prepare(
            "INSERT INTO journal_articles (slug, title, excerpt, body, sources, status, published_at)
             VALUES (:slug, :title, :excerpt, :body, :sources, 'published', datetime('now'))"
        );
        foreach (SampleContent::articles() as $article) {
            $statement->execute($article);
        }
    }

    /**
     * @param array<string, array{id: int, community: string, region: string}> $weavers
     */
    private static function insertCatalog(PDO $pdo, array $weavers): void
    {
        $imageStatement = $pdo->prepare(
            'INSERT INTO product_images (product_id, path, sort_order, is_primary)
             VALUES (:product_id, :path, 0, 1)'
        );

        foreach (ProductCatalog::products() as $product) {
            $productId = self::insertProduct($pdo, $product, $weavers[$product['weaver']]);
            $imageStatement->execute([
                'product_id' => $productId,
                'path' => '/assets/img/products/' . $product['slug'] . '.svg',
            ]);
        }
    }

    /**
     * @param array<string, mixed> $product
     * @param array{id: int, community: string, region: string} $weaver
     */
    private static function insertProduct(PDO $pdo, array $product, array $weaver): int
    {
        $statement = $pdo->prepare(
            "INSERT INTO products
                (sku, slug, name, weaver_id, category_id, category, description, materials, place_of_make,
                 fiber, community, region, technique, story,
                 price_centavos, stock, low_stock_threshold, dimensions, three_config, listing_status)
             VALUES
                (:sku, :slug, :name, :weaver_id, (SELECT id FROM categories WHERE name = :category_name),
                 :category, :description, :materials, :place_of_make,
                 :fiber, :community, :region, :technique, :story,
                 :price_centavos, :stock, :low_stock_threshold, :dimensions, :three_config, 'active')"
        );

        $statement->execute([
            'weaver_id' => $weaver['id'],
            'category_name' => $product['category'],
            'fiber' => $product['fiber'],
            'community' => $weaver['community'],
            'region' => $weaver['region'],
            'technique' => $product['technique'],
            'story' => $product['story'],
            'sku' => $product['sku'],
            'slug' => $product['slug'],
            'name' => $product['name'],
            'category' => $product['category'],
            'description' => $product['description'],
            'materials' => $product['materials'],
            'place_of_make' => $product['place_of_make'],
            'price_centavos' => $product['price_centavos'],
            'stock' => $product['stock'],
            'low_stock_threshold' => $product['low_stock_threshold'],
            'dimensions' => json_encode($product['dimensions'], JSON_UNESCAPED_SLASHES),
            'three_config' => json_encode($product['three_config'], JSON_UNESCAPED_SLASHES),
        ]);

        return (int) $pdo->lastInsertId();
    }
}
