<?php

declare(strict_types=1);

namespace App\Session;

/**
 * The native PHP session, with a hardened cookie.
 *
 * HttpOnly keeps the id away from JavaScript, SameSite=Lax means it is not sent on cross-site
 * requests (defence in depth next to the CSRF token), and Secure is decided per request rather
 * than hard-coded, because this app is routinely served over plain HTTP on a LAN where a Secure
 * cookie would simply never come back.
 */
final class PhpSession implements SessionInterface
{
    private bool $secure = false;

    /** @param array<string, mixed> $settings */
    public function __construct(private readonly array $settings = [])
    {
    }

    public function useSecureCookie(bool $secure): void
    {
        $this->secure = $secure;
    }

    public function start(): void
    {
        if ($this->isStarted()) {
            return;
        }

        if (PHP_SAPI !== 'cli') {
            $name = (string) ($this->settings['name'] ?? 'todoer_session');
            if ($name !== '') {
                session_name($name);
            }
            session_set_cookie_params([
                'lifetime' => 0,
                'path' => (string) ($this->settings['cookie_path'] ?? '/'),
                'httponly' => (bool) ($this->settings['cookie_httponly'] ?? true),
                'samesite' => (string) ($this->settings['cookie_samesite'] ?? 'Lax'),
                'secure' => $this->secure,
            ]);
        }

        session_start();
    }

    public function isStarted(): bool
    {
        return session_status() === PHP_SESSION_ACTIVE;
    }

    public function get(string $key, mixed $default = null): mixed
    {
        return $_SESSION[$key] ?? $default;
    }

    public function set(string $key, mixed $value): void
    {
        $_SESSION[$key] = $value;
    }

    public function has(string $key): bool
    {
        return isset($_SESSION[$key]);
    }

    public function remove(string $key): void
    {
        unset($_SESSION[$key]);
    }

    public function regenerateId(): void
    {
        if ($this->isStarted()) {
            session_regenerate_id(true);
        }
    }

    public function destroy(): void
    {
        $_SESSION = [];

        if ($this->isStarted() && PHP_SAPI !== 'cli' && ini_get('session.use_cookies')) {
            $params = session_get_cookie_params();
            setcookie(session_name(), '', [
                'expires' => time() - 42000,
                'path' => $params['path'],
                'domain' => $params['domain'],
                'secure' => $params['secure'],
                'httponly' => $params['httponly'],
                'samesite' => $params['samesite'] ?? 'Lax',
            ]);
        }

        if ($this->isStarted()) {
            session_destroy();
        }
    }
}
