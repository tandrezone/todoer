<?php

declare(strict_types=1);

namespace App\Session;

/**
 * Session-bound CSRF tokens.
 *
 * The token is minted once per session and echoed into every page that can issue a
 * state-changing request (a <meta> tag the JS reads, a hidden field in the sign-in form).
 * Comparison is timing-safe. A request from another origin cannot read either place, so it
 * cannot produce the token -- which is the property SameSite=Lax on the session cookie backs up
 * rather than replaces.
 */
final class CsrfTokenManager
{
    public const SESSION_KEY = 'csrf_token';
    public const HEADER = 'X-CSRF-Token';
    public const FIELD = 'csrf_token';

    public function __construct(private readonly SessionInterface $session)
    {
    }

    public function token(): string
    {
        $token = $this->session->get(self::SESSION_KEY);
        if (!is_string($token) || $token === '') {
            $token = bin2hex(random_bytes(32));
            $this->session->set(self::SESSION_KEY, $token);
        }

        return $token;
    }

    public function isValid(string $provided): bool
    {
        $expected = $this->session->get(self::SESSION_KEY);

        return is_string($expected) && $expected !== '' && $provided !== '' && hash_equals($expected, $provided);
    }
}
