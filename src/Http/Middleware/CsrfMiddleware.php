<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Exception\AuthorizationException;
use App\Http\Input\InputBag;
use App\Session\CsrfTokenManager;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Rejects state-changing requests that do not carry the session's CSRF token.
 *
 * Applied to every unsafe method rather than opted into per endpoint -- the original code called
 * todoer_require_csrf() by hand in each API file, which works right up until a new endpoint
 * forgets to. JSON callers send the token as a header, HTML forms as a hidden field; both are
 * accepted so the sign-in POST and the API share one guard.
 */
final class CsrfMiddleware implements MiddlewareInterface
{
    private const SAFE_METHODS = ['GET', 'HEAD', 'OPTIONS', 'TRACE'];

    private const FAILURE_MESSAGE = 'Invalid or missing CSRF token. Please reload the page and try again.';

    public function __construct(private readonly CsrfTokenManager $csrf)
    {
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        if (in_array($request->getMethod(), self::SAFE_METHODS, true)) {
            return $handler->handle($request);
        }

        $provided = $request->getHeaderLine(CsrfTokenManager::HEADER);
        if ($provided === '') {
            $provided = InputBag::fromBody($request)->string(CsrfTokenManager::FIELD);
        }

        if (!$this->csrf->isValid($provided)) {
            // Thrown rather than rendered here, so the error handler can answer JSON to the API
            // and a readable page to a browser form post with one code path.
            throw new AuthorizationException(self::FAILURE_MESSAGE);
        }

        return $handler->handle($request);
    }
}
