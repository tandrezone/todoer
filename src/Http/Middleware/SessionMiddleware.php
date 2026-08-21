<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Session\PhpSession;
use App\Session\SessionInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Starts the session for the request, deciding the Secure cookie flag from how the request
 * actually arrived (direct HTTPS, port 443, or a reverse proxy saying so).
 */
final class SessionMiddleware implements MiddlewareInterface
{
    public function __construct(private readonly SessionInterface $session)
    {
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        if ($this->session instanceof PhpSession) {
            $this->session->useSecureCookie($this->isHttps($request));
        }
        $this->session->start();

        return $handler->handle($request);
    }

    private function isHttps(ServerRequestInterface $request): bool
    {
        if ($request->getUri()->getScheme() === 'https') {
            return true;
        }
        if (strtolower($request->getHeaderLine('X-Forwarded-Proto')) === 'https') {
            return true;
        }
        $server = $request->getServerParams();
        $https = $server['HTTPS'] ?? '';

        return (is_string($https) && $https !== '' && strtolower($https) !== 'off')
            || ($server['SERVER_PORT'] ?? null) === '443';
    }
}
