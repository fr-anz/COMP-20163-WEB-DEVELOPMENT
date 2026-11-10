<?php

declare(strict_types=1);

namespace Habi\Support;

/**
 * Per-session CSRF token (SDD §10): created lazily on first render, checked
 * with hash_equals on every mutation. The token lives only in the session,
 * so an expired session naturally invalidates old tokens.
 */
final class Csrf
{
    private const SESSION_KEY = 'csrf_token';

    /**
     * Returns the session's token, creating one on first use.
     */
    public static function token(SessionStore $session): string
    {
        $existing = $session->get(self::SESSION_KEY);
        if (is_string($existing) && $existing !== '') {
            return $existing;
        }

        $token = bin2hex(random_bytes(32));
        $session->set(self::SESSION_KEY, $token);

        return $token;
    }

    /**
     * Constant-time comparison of the submitted token against the session's.
     */
    public static function validate(SessionStore $session, ?string $submitted): bool
    {
        $token = $session->get(self::SESSION_KEY);
        if (!is_string($token) || $token === '' || !is_string($submitted) || $submitted === '') {
            return false;
        }

        return hash_equals($token, $submitted);
    }
}
