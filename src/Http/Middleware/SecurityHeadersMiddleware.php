<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Baseline response hardening, applied to every response including error pages.
 *
 * Deliberately conservative: no Content-Security-Policy is set here because the sign-in page and
 * the per-user colour swatches still use small inline script/style blocks, and a policy that
 * silently breaks them is worse than none. Removing those two inline blocks and turning CSP on
 * is a clean follow-up.
 */
final class SecurityHeadersMiddleware implements MiddlewareInterface
{
    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        return $handler->handle($request)
            ->withHeader('X-Content-Type-Options', 'nosniff')
            ->withHeader('X-Frame-Options', 'SAMEORIGIN')
            ->withHeader('Referrer-Policy', 'same-origin')
            ->withHeader('Cross-Origin-Opener-Policy', 'same-origin');
    }
}
