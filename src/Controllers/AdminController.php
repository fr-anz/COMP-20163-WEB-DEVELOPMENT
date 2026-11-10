<?php

declare(strict_types=1);

namespace Habi\Controllers;

use Habi\Services\AdminProductService;
use Habi\Services\AuthService;
use Habi\Services\OrderService;
use Habi\Services\StockService;
use Habi\Support\Csrf;
use Habi\Support\Session;
use Habi\Support\SessionStore;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Admin sign-in/out (R19), dashboard (R18) and stock audit log (R18, R21).
 * Sign-in regenerates the session ID and rotates the CSRF token; failures
 * always show the same generic message and the AuthService throttle.
 */
final class AdminController
{
    private const INVALID = 'Invalid email or password.';

    public function __construct(
        private readonly AdminResponder $view,
        private readonly SessionStore $session,
        private readonly AuthService $auth,
        private readonly AdminProductService $products,
        private readonly OrderService $orders,
        private readonly StockService $stock,
    ) {
    }

    public function loginForm(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        if ($this->view->adminId() > 0) {
            return $this->view->redirect($response, '/admin');
        }
        $feedback = $this->view->takeFeedback('login');
        $status = $feedback === null ? 200 : (($feedback['values']['locked'] ?? false) ? 429 : 422);

        return $this->view->render($response->withStatus($status), 'login', [
            'email' => (string) ($feedback['values']['email'] ?? ''),
            'message' => $feedback['message'] ?? null,
        ]);
    }

    public function login(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $body = AdminResponder::body($request);
        $email = is_string($body['email'] ?? null) ? trim($body['email']) : '';
        $password = is_string($body['password'] ?? null) ? $body['password'] : '';
        $ip = (string) ($request->getServerParams()['REMOTE_ADDR'] ?? '');

        $result = $this->auth->attempt($email, $password, $ip);
        if ($result['status'] === AuthService::STATUS_OK) {
            Session::regenerate();
            $this->session->set('admin_id', $result['admin']['id']);
            $this->session->set('admin_email', $result['admin']['email']);
            Csrf::rotate($this->session);

            return $this->view->redirect($response, '/admin');
        }

        $locked = $result['status'] === AuthService::STATUS_LOCKED;
        $this->view->feedback('login', ['email' => mb_substr($email, 0, 254), 'locked' => $locked], [], $locked
            ? sprintf(
                'Too many failed sign-in attempts. Wait %d seconds, then try again.',
                $result['retry_after'] ?? AuthService::LOCKOUT_SECONDS
            )
            : self::INVALID);

        return $this->view->redirect($response, '/admin/login');
    }

    public function logout(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $this->session->unset('admin_id');
        $this->session->unset('admin_email');
        Session::regenerate();
        Csrf::rotate($this->session);
        $this->view->flash('success', 'You are signed out.');

        return $this->view->redirect($response, '/admin/login');
    }

    public function dashboard(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        return $this->view->render($response, 'dashboard', [
            'active_nav' => 'dashboard',
            'metrics' => $this->products->metrics(),
            'summary' => $this->orders->adminSummary(),
        ]);
    }

    public function audit(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $query = $request->getQueryParams();
        $productId = is_string($query['product'] ?? null) && ctype_digit($query['product'])
            ? (int) $query['product'] : null;
        $page = is_string($query['page'] ?? null) && ctype_digit($query['page']) ? (int) $query['page'] : 1;

        return $this->view->render($response, 'audit', [
            'active_nav' => 'audit',
            'audit' => $this->stock->audit($page, $productId),
            'product_id' => $productId,
            'products' => $this->products->list()['products'],
        ]);
    }
}
