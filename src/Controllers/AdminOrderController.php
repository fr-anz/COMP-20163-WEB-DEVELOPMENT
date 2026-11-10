<?php

declare(strict_types=1);

namespace Habi\Controllers;

use Habi\Middleware\ErrorHandler;
use Habi\Services\AdminActionException;
use Habi\Services\OrderService;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Admin orders (R22): filtered list, detail, one-step status updates and
 * cancellation that restores stock (OrderService::cancel, audited). The
 * cancel confirmation is a server-rendered dialog at …/cancel.
 */
final class AdminOrderController
{
    public function __construct(
        private readonly AdminResponder $view,
        private readonly OrderService $orders,
    ) {
    }

    public function index(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        return $this->view->render($response, 'orders', array_merge(
            $this->orders->listForAdmin($request->getQueryParams()),
            [
                'active_nav' => 'orders',
                'statuses' => OrderService::ADMIN_STATUSES,
            ]
        ));
    }

    public function show(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        return $this->renderOrder($response, (string) $args['number'], false);
    }

    public function cancelForm(
        ServerRequestInterface $request,
        ResponseInterface $response,
        array $args,
    ): ResponseInterface {
        return $this->renderOrder($response, (string) $args['number'], true);
    }

    public function updateStatus(
        ServerRequestInterface $request,
        ResponseInterface $response,
        array $args,
    ): ResponseInterface {
        $number = (string) $args['number'];
        $target = AdminResponder::body($request)['status'] ?? '';
        try {
            $this->orders->advanceStatus($number, is_string($target) ? $target : '');
            $this->view->flash('success', sprintf('Order %s is now %s.', $number, $target));
        } catch (AdminActionException $failure) {
            $this->view->flash('error', $failure->getMessage());
        }

        return $this->view->redirect($response, '/admin/orders/' . rawurlencode($number));
    }

    public function cancel(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $number = (string) $args['number'];
        try {
            $this->orders->cancel($number, $this->view->adminId());
            $this->view->flash(
                'success',
                sprintf('Order %s was cancelled and its stock restored. See the stock audit.', $number)
            );
        } catch (AdminActionException $failure) {
            $this->view->flash('error', $failure->getMessage());
        }

        return $this->view->redirect($response, '/admin/orders/' . rawurlencode($number));
    }

    private function renderOrder(ResponseInterface $response, string $number, bool $confirmCancel): ResponseInterface
    {
        $order = $this->orders->findByNumber($number);
        if ($order === null) {
            $response->getBody()->write(ErrorHandler::page(404));

            return $response->withStatus(404)->withHeader('Content-Type', 'text/html; charset=UTF-8');
        }
        $cancellable = in_array($order['status'], OrderService::CANCELLABLE, true);

        return $this->view->render($response, 'order', [
            'active_nav' => 'orders',
            'order' => $order,
            'next_status' => OrderService::NEXT_STATUS[$order['status']] ?? null,
            'cancellable' => $cancellable,
            'confirm_cancel' => $confirmCancel && $cancellable,
        ]);
    }
}
