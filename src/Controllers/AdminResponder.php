<?php

declare(strict_types=1);

namespace Habi\Controllers;

use Habi\Services\ShippingService;
use Habi\Support\Csrf;
use Habi\Support\SessionStore;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Twig\Environment;

/**
 * Shared rendering for the admin panel: the signed-in administrator,
 * CSRF token and one-shot flash notices for every views/admin template,
 * plus POST/Redirect/GET helpers. Form feedback (values and field errors,
 * never passwords) is flashed in the session and shown once with 422.
 */
final class AdminResponder
{
    private const FLASH = 'admin_flash';
    private const FEEDBACK = 'admin_feedback';

    public function __construct(
        private readonly Environment $twig,
        private readonly SessionStore $session,
    ) {
    }

    /**
     * @param array<string, mixed> $data
     */
    public function render(ResponseInterface $response, string $template, array $data = []): ResponseInterface
    {
        $flash = $this->session->get(self::FLASH);
        $this->session->unset(self::FLASH);
        $response->getBody()->write($this->twig->render('admin/' . $template . '.twig', array_merge([
            'app_name' => 'Habi',
            'admin_email' => (string) $this->session->get('admin_email', ''),
            'csrf_token' => Csrf::token($this->session),
            'flash' => is_array($flash) ? $flash : null,
            'errors' => [],
            'regions' => ShippingService::REGIONS,
        ], $data)));

        return $response->withHeader('Content-Type', 'text/html; charset=UTF-8');
    }

    public function redirect(ResponseInterface $response, string $path): ResponseInterface
    {
        return $response->withStatus(303)->withHeader('Location', $path);
    }

    /** One-shot notice for the next rendered admin page. */
    public function flash(string $type, string $message): void
    {
        $this->session->set(self::FLASH, ['type' => $type, 'message' => $message]);
    }

    /**
     * Stores form feedback for the redirected GET of $form.
     *
     * @param array<string, mixed> $values
     * @param array<string, string> $errors
     */
    public function feedback(string $form, array $values, array $errors, ?string $message = null): void
    {
        $this->session->set(self::FEEDBACK, [
            'form' => $form,
            'values' => $values,
            'errors' => $errors,
            'message' => $message,
        ]);
    }

    /**
     * Takes the stored feedback for $form (or null), clearing it either way.
     *
     * @return array{values: array<string, mixed>, errors: array<string, string>, message: ?string}|null
     */
    public function takeFeedback(string $form): ?array
    {
        $feedback = $this->session->get(self::FEEDBACK);
        $this->session->unset(self::FEEDBACK);
        if (!is_array($feedback) || ($feedback['form'] ?? null) !== $form) {
            return null;
        }

        return [
            'values' => is_array($feedback['values'] ?? null) ? $feedback['values'] : [],
            'errors' => is_array($feedback['errors'] ?? null) ? $feedback['errors'] : [],
            'message' => is_string($feedback['message'] ?? null) ? $feedback['message'] : null,
        ];
    }

    public function adminId(): int
    {
        $id = $this->session->get('admin_id');

        return is_int($id) ? $id : 0;
    }

    /**
     * @return array<string, mixed>
     */
    public static function body(ServerRequestInterface $request): array
    {
        $parsed = $request->getParsedBody();

        return is_array($parsed) ? $parsed : [];
    }
}
