<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Http\RequestAttribute;
use App\Http\Responder;
use App\Http\UrlGenerator;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/** Guards a page: an anonymous visitor is sent to the sign-in screen. */
final class RequireWebSessionMiddleware implements MiddlewareInterface
{
    public function __construct(
        private readonly Responder $responder,
        private readonly UrlGenerator $urls
    ) {
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        if (RequestAttribute::optionalUser($request) === null) {
            return $this->responder->redirect($this->urls->path('/login'));
        }

        return $handler->handle($request);
    }
}
