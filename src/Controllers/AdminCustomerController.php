<?php

declare(strict_types=1);

namespace Habi\Controllers;

use Habi\Middleware\ErrorHandler;
use Habi\Services\CustomerService;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Admin customer records (R42): customers grouped by checkout email with
 * search, and one customer's contact details and orders. The detail URL
 * carries an opaque key, never the email address.
 */
final class AdminCustomerController
{
    public function __construct(
        private readonly AdminResponder $view,
        private readonly CustomerService $customers,
    ) {
    }

    public function index(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        return $this->view->render($response, 'customers', array_merge(
            $this->customers->list($request->getQueryParams()),
            ['active_nav' => 'customers']
        ));
    }

    public function show(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $customer = $this->customers->find((string) $args['key']);
        if ($customer === null) {
            $response->getBody()->write(ErrorHandler::page(404));

            return $response->withStatus(404)->withHeader('Content-Type', 'text/html; charset=UTF-8');
        }

        return $this->view->render($response, 'customer', ['active_nav' => 'customers', 'customer' => $customer]);
    }
}
