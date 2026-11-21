<?php

declare(strict_types=1);

namespace Habi\Controllers;

use Habi\Middleware\ErrorHandler;
use Habi\Services\AdminActionException;
use Habi\Services\CustomerService;
use Habi\Services\OrderService;
use Habi\Services\PaymentProofService;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\StreamFactoryInterface;

/**
 * Admin orders (R22, R44, N11): filtered list; detail with items grouped by
 * weaver, the event history and the proof of payment; and the merged
 * status/payment transitions (OrderService). Reject, Cancel and Refuse
 * payment open server-rendered dialogs (…/reject, …/cancel, …/refuse) with
 * a required reason. Every form sends the "status/payment" state it was
 * rendered for, so a stale view is refused with "review it again".
 */
final class AdminOrderController
{
    private const PROOF_TYPES = ['jpg' => 'image/jpeg', 'png' => 'image/png', 'webp' => 'image/webp'];

    public function __construct(
        private readonly AdminResponder $view,
        private readonly OrderService $orders,
        private readonly ?PaymentProofService $proofs = null,
        private readonly ?StreamFactoryInterface $streams = null,
    ) {
    }

    public function index(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        return $this->view->render($response, 'orders', array_merge(
            $this->orders->listForAdmin($request->getQueryParams()),
            [
                'active_nav' => 'orders',
                'statuses' => OrderService::ADMIN_STATUSES,
                'payment_statuses' => OrderService::PAYMENT_STATUSES,
                'methods' => array_keys(OrderService::PAYMENT_METHODS),
            ]
        ));
    }

    public function show(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        return $this->renderOrder($response, (string) $args['number'], null);
    }

    public function cancelForm(
        ServerRequestInterface $request,
        ResponseInterface $response,
        array $args,
    ): ResponseInterface {
        return $this->renderOrder($response, (string) $args['number'], 'cancel');
    }

    public function rejectForm(
        ServerRequestInterface $request,
        ResponseInterface $response,
        array $args,
    ): ResponseInterface {
        return $this->renderOrder($response, (string) $args['number'], 'reject');
    }

    public function refuseForm(
        ServerRequestInterface $request,
        ResponseInterface $response,
        array $args,
    ): ResponseInterface {
        return $this->renderOrder($response, (string) $args['number'], 'refuse');
    }

    public function updateStatus(
        ServerRequestInterface $request,
        ResponseInterface $response,
        array $args,
    ): ResponseInterface {
        $body = AdminResponder::body($request);
        $target = is_string($body['status'] ?? null) ? $body['status'] : '';

        $number = (string) $args['number'];

        return $this->act($request, $response, $number, null, function (
            string $number,
            ?string $expected
        ) use (
            $target
        ): string {
            $this->orders->advanceStatus($number, $target, $this->view->adminId(), $expected);

            return sprintf('Order %s is now %s.', $number, $target);
        });
    }

    public function reject(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $reason = self::reason($request);

        $number = (string) $args['number'];

        return $this->act($request, $response, $number, 'reject', function (
            string $number,
            ?string $expected
        ) use (
            $reason
        ): string {
            $this->orders->reject($number, $this->view->adminId(), $reason, $expected);

            return sprintf('Order %s was rejected and its stock restored. See the stock audit.', $number);
        });
    }

    public function cancel(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $reason = self::reason($request);

        $number = (string) $args['number'];

        return $this->act($request, $response, $number, 'cancel', function (
            string $number,
            ?string $expected
        ) use (
            $reason
        ): string {
            $this->orders->cancel($number, $this->view->adminId(), $reason, $expected);

            return sprintf('Order %s was cancelled and its stock restored. See the stock audit.', $number);
        });
    }

    /** Payment transitions: action accept | refuse (reason) | cod_paid. */
    public function payment(
        ServerRequestInterface $request,
        ResponseInterface $response,
        array $args,
    ): ResponseInterface {
        $action = AdminResponder::body($request)['action'] ?? '';
        $reason = self::reason($request);
        $admin = $this->view->adminId();

        return $this->act(
            $request,
            $response,
            (string) $args['number'],
            $action === 'refuse' ? 'refuse' : null,
            function (string $number, ?string $expected) use ($action, $reason, $admin): string {
                switch ($action) {
                    case 'accept':
                        $this->orders->acceptPayment($number, $admin, $expected);

                        return sprintf('Payment for order %s was accepted. It can now be confirmed.', $number);
                    case 'refuse':
                        $this->orders->refusePayment($number, $admin, $reason, $expected);

                        return sprintf(
                            'Payment for order %s was refused. Reject the order if it will not be paid.',
                            $number
                        );
                    case 'cod_paid':
                        $this->orders->markCodPaid($number, $admin, $expected);

                        return sprintf('Order %s is marked paid.', $number);
                    default:
                        throw new AdminActionException('Choose a payment action.');
                }
            }
        );
    }

    /**
     * Streams an order's proof of payment to a signed-in administrator only
     * (N11; AdminAuthMiddleware guards the path). Unknown orders, orders
     * without a proof and missing files are 404.
     */
    public function proof(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $order = $this->orders->findByNumber((string) $args['number']);
        $proof = $order === null ? null : $this->orders->proofFor((int) $order['id']);
        $path = $proof === null || $this->proofs === null ? null : $this->proofs->locate($proof['path']);
        $type = $path === null ? null : (self::PROOF_TYPES[pathinfo($path, PATHINFO_EXTENSION)] ?? null);
        if ($path === null || $type === null || $this->streams === null) {
            return $this->notFound($response);
        }

        return $response
            ->withBody($this->streams->createStreamFromFile($path, 'rb'))
            ->withHeader('Content-Type', $type)
            ->withHeader('Content-Length', (string) filesize($path))
            ->withHeader('Content-Disposition', 'inline')
            ->withHeader('Cache-Control', 'no-store, private')
            ->withHeader('X-Content-Type-Options', 'nosniff')
            ->withHeader('Content-Security-Policy', "default-src 'none'; sandbox")
            ->withHeader('X-Robots-Tag', 'noindex, nofollow');
    }

    /**
     * Runs one transition; a reason error reopens its dialog with the
     * message, any other refusal is flashed on the detail page.
     *
     * @param callable(string, ?string): string $work returns the success message
     */
    private function act(
        ServerRequestInterface $request,
        ResponseInterface $response,
        string $number,
        ?string $dialog,
        callable $work,
    ): ResponseInterface {
        $expected = AdminResponder::body($request)['expected'] ?? null;
        $detail = '/admin/orders/' . rawurlencode($number);
        try {
            $this->view->flash('success', $work($number, is_string($expected) ? $expected : null));
        } catch (AdminActionException $failure) {
            if ($failure->field === 'reason' && $dialog !== null) {
                $this->view->feedback('order-' . $dialog, ['reason' => self::reason($request)], [
                    'reason' => $failure->getMessage(),
                ]);

                return $this->view->redirect($response, $detail . '/' . $dialog);
            }
            $this->view->flash('error', $failure->getMessage());
        }

        return $this->view->redirect($response, $detail);
    }

    private function renderOrder(ResponseInterface $response, string $number, ?string $dialog): ResponseInterface
    {
        $order = $this->orders->findByNumber($number);
        if ($order === null) {
            return $this->notFound($response);
        }
        $status = (string) $order['status'];
        $payment = (string) $order['payment_status'];
        $method = (string) $order['payment_method'];
        $open = !in_array($status, ['rejected', 'cancelled'], true);
        $actions = [
            'confirm' => $status === 'pending' && ($method === 'cod' || $payment === 'paid'),
            'reject' => $status === 'pending',
            'next' => $status === 'pending' ? null : (OrderService::NEXT_STATUS[$status] ?? null),
            'cancel' => in_array($status, OrderService::CANCELLABLE, true),
            'review_payment' => $open && $method === 'transfer' && $payment === 'for_verification',
            'cod_paid' => $method === 'cod' && $payment === 'unpaid'
                && in_array($status, OrderService::COD_PAYABLE, true),
        ];
        $allowed = [
            'cancel' => $actions['cancel'], 'reject' => $actions['reject'], 'refuse' => $actions['review_payment'],
        ];
        $dialog = $dialog !== null && ($allowed[$dialog] ?? false) ? $dialog : null;
        $feedback = $dialog === null ? null : $this->view->takeFeedback('order-' . $dialog);

        $groups = [];
        foreach ($order['items'] as $item) {
            $key = (string) ($item['weaver_id'] ?? 'none');
            $groups[$key] ??= [
                'weaver' => (string) ($item['weaver_name_snapshot'] ?? ''), 'items' => [], 'subtotal' => 0,
            ];
            $groups[$key]['items'][] = $item;
            $groups[$key]['subtotal'] += (int) $item['line_total_centavos'];
        }

        return $this->view->render($response->withStatus($feedback === null ? 200 : 422), 'order', [
            'active_nav' => 'orders',
            'order' => $order,
            'groups' => array_values($groups),
            'events' => $this->orders->events((int) $order['id']),
            'proof' => $this->orders->proofFor((int) $order['id']),
            'actions' => $actions,
            'expected' => OrderService::stateToken($order),
            'customer_key' => CustomerService::key((string) $order['customer_email']),
            'dialog' => $dialog,
            'reason' => (string) ($feedback['values']['reason'] ?? ''),
            'errors' => $feedback['errors'] ?? [],
            'max_reason' => OrderService::MAX_REASON_LENGTH,
        ]);
    }

    private static function reason(ServerRequestInterface $request): string
    {
        $reason = AdminResponder::body($request)['reason'] ?? '';

        return is_string($reason) ? mb_substr($reason, 0, OrderService::MAX_REASON_LENGTH * 2) : '';
    }

    private function notFound(ResponseInterface $response): ResponseInterface
    {
        $response->getBody()->write(ErrorHandler::page(404));

        return $response->withStatus(404)->withHeader('Content-Type', 'text/html; charset=UTF-8');
    }
}
