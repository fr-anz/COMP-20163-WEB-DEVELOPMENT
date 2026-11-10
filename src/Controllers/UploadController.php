<?php

declare(strict_types=1);

namespace Habi\Controllers;

use Habi\Middleware\ErrorHandler;
use Habi\Services\ImageUploadService;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\StreamFactoryInterface;

/**
 * Serves uploaded product images (R23) from data/uploads with the MIME type
 * of their validated stored extension; any other name is a 404.
 */
final class UploadController
{
    public function __construct(
        private readonly ImageUploadService $uploads,
        private readonly StreamFactoryInterface $streams,
    ) {
    }

    public function show(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $file = $this->uploads->locate((string) $args['name']);
        if ($file === null) {
            $response->getBody()->write(ErrorHandler::page(404));

            return $response->withStatus(404)->withHeader('Content-Type', 'text/html; charset=UTF-8');
        }

        return $response
            ->withBody($this->streams->createStreamFromFile($file['path'], 'rb'))
            ->withHeader('Content-Type', $file['mime'])
            ->withHeader('Content-Length', (string) filesize($file['path']));
    }
}
