<?php

declare(strict_types=1);

namespace Habi\Support;

/**
 * Native PHP session bootstrap (R28, SDD §10): strict sessions with a custom
 * cookie name, lifetime 0 (browser session), HttpOnly, SameSite=Lax and the
 * Secure flag whenever the request arrived over HTTPS. Store() starts the
 * session lazily and hands back a live wrapper around $_SESSION.
 */
final class Session
{
    private const COOKIE_NAME = 'habi_session';

    /**
     * Starts the session when needed and returns a store bound to $_SESSION.
     */
    public static function store(): SessionStore
    {
        self::start();

        return new SessionStore($_SESSION);
    }

    public static function start(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            return;
        }

        session_name(self::COOKIE_NAME);
        ini_set('session.use_strict_mode', '1');
        ini_set('session.use_only_cookies', '1');
        ini_set('session.use_trans_sid', '0');
        session_set_cookie_params([
            'lifetime' => 0,
            'path' => '/',
            'domain' => '',
            'secure' => self::isHttps(),
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
        session_start();
    }

    /**
     * New session ID for privilege changes (admin sign-in/out, SDD §10);
     * the old ID is destroyed so a fixated or leaked one stops working.
     */
    public static function regenerate(): void
    {
        self::start();
        session_regenerate_id(true);
    }

    private static function isHttps(): bool
    {
        $https = $_SERVER['HTTPS'] ?? '';

        return Env::get('APP_ENV', 'local') === 'production'
            || ($https !== '' && $https !== 'off');
    }
}
