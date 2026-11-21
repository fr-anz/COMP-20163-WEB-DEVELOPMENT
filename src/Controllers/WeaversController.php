<?php

declare(strict_types=1);

namespace Habi\Controllers;

use Habi\Services\ProductService;
use Habi\Services\WeaverDirectoryService;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Meet the Weavers (R30): the public list of active weavers and each
 * profile with the weaver's active products. Deactivated weavers and the
 * placeholder weaver answer 404. Pages are indexable (R26). A weaver
 * without a photo shows the woven swatch of their first fiber.
 */
final class WeaversController
{
    public function __construct(
        private readonly StorefrontResponder $view,
        private readonly WeaverDirectoryService $weavers,
        private readonly ProductService $products,
    ) {
    }

    public function index(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        return $this->view->render($response, 'storefront/weavers.twig', [
            'weavers' => $this->weavers->listActive(),
            'fiber_art' => array_flip(StorefrontController::MATERIAL_FIBERS),
        ]);
    }

    /**
     * @param array<string, string> $args
     */
    public function show(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $weaver = $this->weavers->findActiveBySlug((string) ($args['slug'] ?? ''));
        if ($weaver === null) {
            return $this->view->notFound($response);
        }

        return $this->view->render($response, 'storefront/weaver.twig', [
            'weaver' => $weaver,
            'products' => $this->products->byWeaver($weaver['id']),
            'fiber_art' => array_flip(StorefrontController::MATERIAL_FIBERS),
        ]);
    }
}
