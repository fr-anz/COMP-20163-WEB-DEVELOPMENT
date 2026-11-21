<?php

declare(strict_types=1);

namespace Habi\Controllers;

use Closure;
use Habi\Services\AdminActionException;
use Habi\Services\AuthService;
use Habi\Services\EarningsService;
use Habi\Services\ImageUploadService;
use Habi\Services\WeaverOrderService;
use Habi\Services\WeaverProductService;
use Habi\Services\WeaverService;
use Habi\Support\Csrf;
use Habi\Support\Session;
use Habi\Support\SessionStore;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\UploadedFileInterface;
use Throwable;

/**
 * Weaver sign-in/out (R34), dashboard, own profile (R35), own order items
 * (R38) and earnings (R39). Sign-in and sign-out regenerate the session ID
 * and rotate the CSRF token; a session holds one staff role, so signing in
 * as a weaver drops any administrator sign-in. The weaver id always comes
 * from WeaverAuthMiddleware, never from the request body or URL.
 */
final class WeaverController
{
    private const INVALID = 'Invalid email or password.';

    private readonly Closure $regenerate;

    /** @param (Closure(): void)|null $regenerate defaults to Session::regenerate */
    public function __construct(
        private readonly WeaverResponder $view,
        private readonly SessionStore $session,
        private readonly AuthService $auth,
        private readonly WeaverService $weavers,
        private readonly WeaverProductService $products,
        private readonly WeaverOrderService $orders,
        private readonly EarningsService $earnings,
        private readonly ImageUploadService $uploads,
        ?Closure $regenerate = null,
    ) {
        $this->regenerate = $regenerate ?? static fn () => Session::regenerate();
    }

    public function loginForm(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $id = $this->session->get('weaver_id');
        if (is_int($id) && $id > 0 && $this->weavers->findActive($id) !== null) {
            return $this->view->redirect($response, '/weaver');
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
        $body = self::body($request);
        $email = is_string($body['email'] ?? null) ? trim($body['email']) : '';
        $password = is_string($body['password'] ?? null) ? $body['password'] : '';
        $ip = (string) ($request->getServerParams()['REMOTE_ADDR'] ?? '');

        $result = $this->auth->attemptWeaver($email, $password, $ip);
        if ($result['status'] === AuthService::STATUS_OK) {
            ($this->regenerate)();
            $this->session->unset('admin_id');
            $this->session->unset('admin_email');
            $this->session->set('weaver_id', $result['weaver']['id']);
            $this->session->set('weaver_email', $result['weaver']['email']);
            Csrf::rotate($this->session);

            return $this->view->redirect($response, '/weaver');
        }

        $locked = $result['status'] === AuthService::STATUS_LOCKED;
        $this->view->feedback('login', ['email' => mb_substr($email, 0, 254), 'locked' => $locked], [], $locked
            ? sprintf(
                'Too many failed sign-in attempts. Wait %d seconds, then try again.',
                $result['retry_after'] ?? AuthService::LOCKOUT_SECONDS
            )
            : self::INVALID);

        return $this->view->redirect($response, '/weaver/login');
    }

    public function logout(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $this->session->unset('weaver_id');
        $this->session->unset('weaver_email');
        ($this->regenerate)();
        Csrf::rotate($this->session);
        $this->view->flash('success', 'You are signed out.');

        return $this->view->redirect($response, '/weaver/login');
    }

    public function dashboard(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $weaverId = WeaverResponder::weaverId($request);

        return $this->view->render($response, 'dashboard', [
            'active_nav' => 'dashboard',
            'weaver' => WeaverResponder::weaver($request),
            'earnings' => $this->earnings->summary($weaverId),
            'counts' => $this->products->statusCounts($weaverId),
            'low_stock' => $this->products->lowStock($weaverId),
            'recent' => $this->orders->recent($weaverId),
        ]);
    }

    public function profileForm(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $weaver = WeaverResponder::weaver($request);
        $feedback = $this->view->takeFeedback('profile');

        return $this->view->render($response->withStatus($feedback === null ? 200 : 422), 'profile', [
            'active_nav' => 'profile',
            'weaver' => $weaver,
            'values' => $feedback['values'] ?? array_intersect_key($weaver, WeaverService::PROFILE_LIMITS),
            'errors' => $feedback['errors'] ?? [],
            'limits' => WeaverService::PROFILE_LIMITS,
        ]);
    }

    public function saveProfile(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $weaverId = WeaverResponder::weaverId($request);
        $result = $this->weavers->validateProfile(self::body($request));
        $errors = $result['errors'];
        $photo = null;
        $file = $request->getUploadedFiles()['photo'] ?? null;
        if ($file instanceof UploadedFileInterface && ImageUploadService::provided($file)) {
            try {
                $photo = $this->uploads->validate($file);
            } catch (AdminActionException $invalid) {
                $errors['photo'] = $invalid->getMessage();
            }
        }
        if ($errors !== []) {
            $this->view->feedback('profile', $result['values'], $errors);

            return $this->view->redirect($response, '/weaver/profile');
        }

        $path = $photo === null ? null : $this->uploads->store($photo);
        try {
            $this->weavers->updateProfile($weaverId, $result['values'], $path);
        } catch (Throwable $failure) {
            if ($path !== null) {
                $this->uploads->discard($path);
            }
            throw $failure;
        }
        $this->view->flash('success', 'Your profile was saved.');

        return $this->view->redirect($response, '/weaver/profile');
    }

    public function orders(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $query = $request->getQueryParams();
        $page = is_string($query['page'] ?? null) && ctype_digit($query['page']) ? (int) $query['page'] : 1;

        return $this->view->render($response, 'orders', array_merge(
            $this->orders->list(WeaverResponder::weaverId($request), $page),
            ['active_nav' => 'orders']
        ));
    }

    public function order(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $order = $this->orders->find(WeaverResponder::weaverId($request), (string) $args['number']);
        if ($order === null) {
            return $this->view->notFound($response);
        }

        return $this->view->render($response, 'order', ['active_nav' => 'orders', 'order' => $order]);
    }

    public function earnings(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $query = $request->getQueryParams();

        return $this->view->render($response, 'earnings', [
            'active_nav' => 'earnings',
            'earnings' => $this->earnings->summary(
                WeaverResponder::weaverId($request),
                $query['from'] ?? null,
                $query['to'] ?? null
            ),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private static function body(ServerRequestInterface $request): array
    {
        $parsed = $request->getParsedBody();

        return is_array($parsed) ? $parsed : [];
    }
}
