<?php

declare(strict_types=1);

namespace Habi\Controllers;

use Habi\Middleware\ErrorHandler;
use Habi\Services\ShippingService;
use Habi\Support\Csrf;
use Habi\Support\SessionStore;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Twig\Environment;

/**
 * Shared rendering for the weaver module (R34–R39): views/weaver templates
 * in the admin shell, CSRF token, one-shot flash and form feedback (never
 * passwords) as in AdminResponder, and the 404 used for every missing or
 * foreign resource (N10). Every weaver response is noindex (R26).
 */
final class WeaverResponder
{
    private const FLASH = 'weaver_flash';
    private const FEEDBACK = 'weaver_feedback';
    private const ROBOTS = 'noindex, nofollow';

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
        $response->getBody()->write($this->twig->render('weaver/' . $template . '.twig', array_merge([
            'app_name' => 'Habi',
            'staff_email' => (string) $this->session->get('weaver_email', ''),
            'csrf_token' => Csrf::token($this->session),
            'flash' => is_array($flash) ? $flash : null,
            'errors' => [],
            'regions' => ShippingService::REGIONS,
        ], $data)));

        return $response->withHeader('Content-Type', 'text/html; charset=UTF-8')
            ->withHeader('X-Robots-Tag', self::ROBOTS);
    }

    public function redirect(ResponseInterface $response, string $path): ResponseInterface
    {
        return $response->withStatus(303)->withHeader('Location', $path)->withHeader('X-Robots-Tag', self::ROBOTS);
    }

    /** Missing and other weavers' resources look the same (N10). */
    public function notFound(ResponseInterface $response): ResponseInterface
    {
        $response->getBody()->write(ErrorHandler::page(404));

        return $response->withStatus(404)->withHeader('Content-Type', 'text/html; charset=UTF-8')
            ->withHeader('X-Robots-Tag', self::ROBOTS);
    }

    /** One-shot notice for the next rendered weaver page. */
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

    /**
     * The active weaver set by WeaverAuthMiddleware.
     *
     * @return array<string, mixed>
     */
    public static function weaver(ServerRequestInterface $request): array
    {
        $weaver = $request->getAttribute('weaver');

        return is_array($weaver) ? $weaver : ['id' => 0];
    }

    public static function weaverId(ServerRequestInterface $request): int
    {
        return (int) (self::weaver($request)['id'] ?? 0);
    }
}
