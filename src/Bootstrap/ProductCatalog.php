<?php

declare(strict_types=1);

namespace Habi\Bootstrap;

/**
 * The Habi catalog: one bag per category, 4 categories (SDD §5, N7).
 * Prices are integer centavos (₱1 = 100).
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
                'description' => 'A roomy morning-market tote with a woven-style panel '
                    . 'and rust trim, finished with a geometric sun-ray motif.',
                'materials' => 'Buri-style straw, leather-style trim, cotton lining',
                'place_of_make' => 'Handwoven in the Philippines',
                'price_centavos' => 165000,
                'stock' => 12,
                'low_stock_threshold' => 3,
                'dimensions' => ['width' => 38, 'height' => 34, 'depth' => 14],
                'three_config' => [
                    'shape' => 'tote',
                    'dimensions' => ['width' => 38, 'height' => 34, 'depth' => 14],
                    'colors' => [
                        'body' => '#C9A877', 'trim' => '#B08F62', 'lining' => '#F3E7D3',
                        'handle' => '#B08F62', 'handle_secondary' => '#C9A877',
                    ],
                    'handle_style' => 'double_handles',
                    'material' => 'rattan',
                    'lining' => 'cotton',
                    'pockets' => ['interior_zip', 'slip_pocket'],
                    'pattern' => [
                        'kind' => 'checker',
                        'cell' => 1.4,
                        'colors' => ['#C9A877', '#B08F62'],
                        'bands' => [
                            ['at' => 0.53, 'height' => 0.15, 'colors' => ['#A8462C', '#E8B94D', '#436D51', '#426A94']],
                            ['at' => 0.055, 'height' => 0.15, 'colors' => ['#A8462C', '#E8B94D', '#436D51', '#426A94']],
                        ],
                    ],
                ],
            ],
            [
                'sku' => 'HBI-SAT-001',
                'slug' => 'lupa-satchel',
                'name' => 'Lupa Satchel',
                'category' => 'Satchel',
                'description' => 'A structured satchel in deep earth brown with a single '
                    . 'top handle and a stitched diamond motif on the flap.',
                'materials' => 'Leather, brass-style hardware, cotton twill lining',
                'place_of_make' => 'Handwoven in the Philippines',
                'price_centavos' => 285000,
                'stock' => 6,
                'low_stock_threshold' => 3,
                'dimensions' => ['width' => 28, 'height' => 22, 'depth' => 11],
                'three_config' => [
                    'shape' => 'satchel',
                    'dimensions' => ['width' => 28, 'height' => 22, 'depth' => 11],
                    'colors' => [
                        'body' => '#C9A877', 'trim' => '#B08F62', 'lining' => '#F3E7D3',
                        'handle' => '#B08F62',
                    ],
                    'handle_style' => 'top_handle',
                    'material' => 'leather',
                    'lining' => 'cotton',
                    'pockets' => ['interior_zip', 'pen_slip'],
                    'pattern' => [
                        'kind' => 'checker',
                        'cell' => 1.0,
                        'colors' => ['#C9A877', '#B08F62'],
                        'bands' => [
                            ['at' => 0.40, 'height' => 0.17, 'colors' => ['#436D51', '#E8B94D', '#FFFCF6']],
                            ['at' => 0.0, 'height' => 0.10, 'colors' => ['#436D51', '#E8B94D', '#FFFCF6']],
                        ],
                    ],
                ],
            ],
            [
                'sku' => 'HBI-SLI-001',
                'name' => 'Tala Sling',
                'slug' => 'tala-sling',
                'category' => 'Sling',
                'description' => 'A compact crossbody sling in warm gold with a star motif '
                    . 'on the flap and a contrasting strap.',
                'materials' => 'Cotton canvas, cotton webbing strap, cotton lining',
                'place_of_make' => 'Handwoven in the Philippines',
                'price_centavos' => 125000,
                'stock' => 18,
                'low_stock_threshold' => 4,
                'dimensions' => ['width' => 30, 'height' => 22, 'depth' => 8],
                'three_config' => [
                    'shape' => 'sling',
                    'dimensions' => ['width' => 30, 'height' => 22, 'depth' => 8],
                    'colors' => [
                        'body' => '#A8462C', 'trim' => '#B08F62', 'lining' => '#F7EFD8',
                        'handle' => '#A8462C',
                    ],
                    'handle_style' => 'top_handle',
                    'material' => 'canvas',
                    'lining' => 'cotton',
                    'pockets' => ['interior_slip'],
                    'pattern' => [
                        'kind' => 'stripes',
                        'period' => 5.3,
                        'widths' => [0.368, 0.105, 0.079, 0.263, 0.079, 0.105],
                        'colors' => ['#A8462C', '#352820', '#FFFCF6', '#426A94', '#FFFCF6', '#352820'],
                        'lines' => 0.55,
                    ],
                ],
            ],
            [
                'sku' => 'HBI-CLU-001',
                'name' => 'Maya Clutch',
                'slug' => 'maya-clutch',
                'category' => 'Clutch',
                'description' => 'A slim woven-style clutch with a rust zigzag band '
                    . 'and a quiet rectangular silhouette.',
                'materials' => 'Raffia-style weave, cotton backing, magnetic closure',
                'place_of_make' => 'Handwoven in the Philippines',
                'price_centavos' => 98000,
                'stock' => 22,
                'low_stock_threshold' => 5,
                'dimensions' => ['width' => 26, 'height' => 16, 'depth' => 3],
                'three_config' => [
                    'shape' => 'clutch',
                    'dimensions' => ['width' => 26, 'height' => 16, 'depth' => 3],
                    'colors' => [
                        'body' => '#E8B94D', 'trim' => '#426A94', 'lining' => '#F7EFD8', 'binding' => '#352820',
                    ],
                    'handle_style' => 'none',
                    'material' => 'rattan',
                    'lining' => 'cotton',
                    'pockets' => ['back_slip'],
                    'pattern' => [
                        'kind' => 'pixel',
                        'tile' => 8.0,
                        'grid' => 11,
                        'base' => '#E8B94D',
                        'motif' => '#426A94',
                        'accent' => '#FFFCF6',
                    ],
                ],
            ],
        ];
    }
}
