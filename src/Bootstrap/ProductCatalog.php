<?php

declare(strict_types=1);

namespace Habi\Bootstrap;

/**
 * The fictional Habi demo catalog: 10 bags across 4 categories (SDD §5, N7).
 * Every product is a fictional demonstration, with illustrative materials
 * and unverified origin labels. Prices are integer centavos (₱1 = 100).
 *
 * Image paths are public asset URLs served from public/assets/img/products/,
 * each an original deterministic SVG illustration (4:5 quiet framing).
 */
final class ProductCatalog
{
    /**
     * @return list<array{
     *     sku: string, slug: string, name: string, category: string,
     *     description: string, materials: string, place_of_make: string,
     *     price_centavos: int, stock: int, low_stock_threshold: int,
     *     dimensions: array{width: int, height: int, depth: int},
     *     three_config: array<string, mixed>
     * }>
     */
    public static function products(): array
    {
        return [
            [
                'sku' => 'HBI-TOT-001',
                'slug' => 'sinag-tote',
                'name' => 'Sinag Tote',
                'category' => 'Tote',
                'description' => 'Fictional demo: a roomy morning-market tote with a woven-style panel '
                    . 'and rust trim. Its geometric sun-ray motif is an illustrative design.',
                'materials' => 'Illustrative materials: buri-style straw, leather-style trim, cotton lining',
                'place_of_make' => 'Illustrative sample — origin unverified.',
                'price_centavos' => 165000,
                'stock' => 12,
                'low_stock_threshold' => 3,
                'dimensions' => ['width' => 38, 'height' => 34, 'depth' => 14],
                'three_config' => [
                    'shape' => 'tote',
                    'dimensions' => ['width' => 38, 'height' => 34, 'depth' => 14],
                    'colors' => ['body' => '#D9A05B', 'trim' => '#8A4B2F', 'lining' => '#F3E7D3'],
                    'handle_style' => 'double_handles',
                    'material' => 'rattan',
                    'lining' => 'cotton',
                    'pockets' => ['interior_zip', 'slip_pocket'],
                ],
            ],
            [
                'sku' => 'HBI-TOT-002',
                'slug' => 'alon-tote',
                'name' => 'Alon Tote',
                'description' => 'Fictional demo: a soft-sided canvas-style tote in layered shore blues, '
                    . 'with wave bands and rope-style handles.',
                'materials' => 'Illustrative materials: cotton canvas, cotton rope handles, cotton lining',
                'place_of_make' => 'Illustrative sample — origin unverified.',
                'category' => 'Tote',
                'price_centavos' => 145000,
                'stock' => 15,
                'low_stock_threshold' => 3,
                'dimensions' => ['width' => 36, 'height' => 32, 'depth' => 12],
                'three_config' => [
                    'shape' => 'tote',
                    'dimensions' => ['width' => 36, 'height' => 32, 'depth' => 12],
                    'colors' => ['body' => '#6E93A8', 'trim' => '#2F4A5A', 'lining' => '#EAF1EF'],
                    'handle_style' => 'double_handles',
                    'material' => 'canvas',
                    'lining' => 'cotton',
                    'pockets' => ['interior_zip', 'bottle_slip'],
                ],
            ],
            [
                'sku' => 'HBI-SAT-001',
                'slug' => 'lupa-satchel',
                'name' => 'Lupa Satchel',
                'category' => 'Satchel',
                'description' => 'Fictional demo: a structured satchel in deep earth brown with a single '
                    . 'top handle and a stitched diamond motif on the flap.',
                'materials' => 'Illustrative materials: leather, brass-style hardware, cotton twill lining',
                'place_of_make' => 'Illustrative sample — origin unverified.',
                'price_centavos' => 285000,
                'stock' => 6,
                'low_stock_threshold' => 3,
                'dimensions' => ['width' => 28, 'height' => 22, 'depth' => 11],
                'three_config' => [
                    'shape' => 'satchel',
                    'dimensions' => ['width' => 28, 'height' => 22, 'depth' => 11],
                    'colors' => ['body' => '#8C6A4A', 'trim' => '#4A3320', 'lining' => '#E9DCC8'],
                    'handle_style' => 'top_handle',
                    'material' => 'leather',
                    'lining' => 'cotton',
                    'pockets' => ['interior_zip', 'pen_slip'],
                ],
            ],
            [
                'sku' => 'HBI-SAT-002',
                'name' => 'Sierra Satchel',
                'slug' => 'sierra-satchel',
                'category' => 'Satchel',
                'description' => 'Fictional demo: a moss-green satchel with triangular panel stitching '
                    . 'that steps down like a mountain slope.',
                'materials' => 'Illustrative materials: leather, brass-style buckles, canvas lining',
                'place_of_make' => 'Illustrative sample — origin unverified.',
                'price_centavos' => 315000,
                'stock' => 4,
                'low_stock_threshold' => 3,
                'dimensions' => ['width' => 30, 'height' => 24, 'depth' => 12],
                'three_config' => [
                    'shape' => 'satchel',
                    'dimensions' => ['width' => 30, 'height' => 24, 'depth' => 12],
                    'colors' => ['body' => '#7D8F6A', 'trim' => '#3E4E33', 'lining' => '#EDF0E4'],
                    'handle_style' => 'top_handle',
                    'material' => 'leather',
                    'lining' => 'canvas',
                    'pockets' => ['interior_zip', 'divider_pocket'],
                ],
            ],
            [
                'sku' => 'HBI-SLI-001',
                'name' => 'Tala Sling',
                'slug' => 'tala-sling',
                'category' => 'Sling',
                'description' => 'Fictional demo: a compact crossbody sling in warm gold with a star motif '
                    . 'on the flap and a contrasting strap.',
                'materials' => 'Illustrative materials: cotton canvas, cotton webbing strap, cotton lining',
                'place_of_make' => 'Illustrative sample — origin unverified.',
                'price_centavos' => 125000,
                'stock' => 18,
                'low_stock_threshold' => 4,
                'dimensions' => ['width' => 18, 'height' => 22, 'depth' => 7],
                'three_config' => [
                    'shape' => 'sling',
                    'dimensions' => ['width' => 18, 'height' => 22, 'depth' => 7],
                    'colors' => ['body' => '#E0B457', 'trim' => '#8A6420', 'lining' => '#F7EFD8'],
                    'handle_style' => 'crossbody_strap',
                    'material' => 'canvas',
                    'lining' => 'cotton',
                    'pockets' => ['exterior_zip', 'interior_slip'],
                ],
            ],
            [
                'sku' => 'HBI-SLI-002',
                'name' => 'Ulap Sling',
                'slug' => 'ulap-sling',
                'category' => 'Sling',
                'description' => 'Fictional demo: a cloud-grey sling shaped like a rounded pebble, '
                    . 'with a wide strap and quiet contrasting trim.',
                'materials' => 'Illustrative materials: cotton-blend canvas, nylon strap, cotton lining',
                'place_of_make' => 'Illustrative sample — origin unverified.',
                'price_centavos' => 135000,
                'stock' => 9,
                'low_stock_threshold' => 3,
                'dimensions' => ['width' => 20, 'height' => 18, 'depth' => 8],
                'three_config' => [
                    'shape' => 'sling',
                    'dimensions' => ['width' => 20, 'height' => 18, 'depth' => 8],
                    'colors' => ['body' => '#B9B4A6', 'trim' => '#6E6A5C', 'lining' => '#F2F1EC'],
                    'handle_style' => 'crossbody_strap',
                    'material' => 'canvas',
                    'lining' => 'cotton',
                    'pockets' => ['interior_slip', 'key_leash'],
                ],
            ],
            [
                'sku' => 'HBI-CLU-001',
                'name' => 'Maya Clutch',
                'slug' => 'maya-clutch',
                'category' => 'Clutch',
                'description' => 'Fictional demo: a slim woven-style clutch with a rust zigzag band '
                    . 'and a quiet rectangular silhouette.',
                'materials' => 'Illustrative materials: raffia-style weave, cotton backing, magnetic closure',
                'place_of_make' => 'Illustrative sample — origin unverified.',
                'price_centavos' => 98000,
                'stock' => 22,
                'low_stock_threshold' => 5,
                'dimensions' => ['width' => 26, 'height' => 14, 'depth' => 5],
                'three_config' => [
                    'shape' => 'clutch',
                    'dimensions' => ['width' => 26, 'height' => 14, 'depth' => 5],
                    'colors' => ['body' => '#C4754C', 'trim' => '#6E3A1F', 'lining' => '#F0E2D4'],
                    'handle_style' => 'none',
                    'material' => 'rattan',
                    'lining' => 'cotton',
                    'pockets' => ['back_slip'],
                ],
            ],
            [
                'sku' => 'HBI-CLU-002',
                'name' => 'Gabi Clutch',
                'slug' => 'gabi-clutch',
                'category' => 'Clutch',
                'description' => 'Fictional demo: an evening clutch in twilight indigo-brown, '
                    . 'folded like an envelope with a contrasting closure.',
                'materials' => 'Illustrative materials: leather, brass-style hardware, cotton lining',
                'place_of_make' => 'Illustrative sample — origin unverified.',
                'price_centavos' => 105000,
                'stock' => 3,
                'low_stock_threshold' => 3,
                'dimensions' => ['width' => 24, 'height' => 13, 'depth' => 4],
                'three_config' => [
                    'shape' => 'clutch',
                    'dimensions' => ['width' => 24, 'height' => 13, 'depth' => 4],
                    'colors' => ['body' => '#4E4A5E', 'trim' => '#2A2836', 'lining' => '#C9C4D6'],
                    'handle_style' => 'none',
                    'material' => 'leather',
                    'lining' => 'cotton',
                    'pockets' => [],
                ],
            ],
            [
                'sku' => 'HBI-TOT-003',
                'name' => 'Araw Tote',
                'slug' => 'araw-tote',
                'category' => 'Tote',
                'description' => 'Fictional demo: a straw-style tote with a wide flat base, '
                    . 'rolled handles and a simple geometric panel.',
                'materials' => 'Illustrative materials: abaca-style straw, leather handles, brass-style grommets',
                'place_of_make' => 'Illustrative sample — origin unverified.',
                'price_centavos' => 175000,
                'stock' => 11,
                'low_stock_threshold' => 3,
                'dimensions' => ['width' => 40, 'height' => 30, 'depth' => 15],
                'three_config' => [
                    'shape' => 'tote',
                    'dimensions' => ['width' => 40, 'height' => 30, 'depth' => 15],
                    'colors' => ['body' => '#E3C078', 'trim' => '#96702E', 'lining' => '#F6ECD6'],
                    'handle_style' => 'double_handles',
                    'material' => 'rattan',
                    'lining' => 'cotton',
                    'pockets' => ['interior_zip'],
                ],
            ],
            [
                'sku' => 'HBI-SLI-003',
                'name' => 'Dahon Sling',
                'slug' => 'dahon-sling',
                'category' => 'Sling',
                'description' => 'Fictional demo: a slender crossbody with woven-style panels over '
                    . 'a leaf-green lining and an adjustable strap.',
                'materials' => 'Illustrative materials: rattan-style panels, cotton backing, cotton strap',
                'place_of_make' => 'Illustrative sample — origin unverified.',
                'price_centavos' => 155000,
                'stock' => 7,
                'low_stock_threshold' => 3,
                'dimensions' => ['width' => 17, 'height' => 24, 'depth' => 6],
                'three_config' => [
                    'shape' => 'sling',
                    'dimensions' => ['width' => 17, 'height' => 24, 'depth' => 6],
                    'colors' => ['body' => '#86A06C', 'trim' => '#465C33', 'lining' => '#EAF0DF'],
                    'handle_style' => 'crossbody_strap',
                    'material' => 'rattan',
                    'lining' => 'cotton',
                    'pockets' => ['interior_zip', 'slip_pocket'],
                ],
            ],
        ];
    }
}
