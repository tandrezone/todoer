<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Exception\AuthenticationException;
use App\Http\RequestAttribute;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/** Guards an API endpoint: an anonymous caller gets a 401 rather than a redirect to HTML. */
final class RequireApiSessionMiddleware implements MiddlewareInterface
{
    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        if (RequestAttribute::optionalUser($request) === null) {
            throw new AuthenticationException('Not logged in.');
        }

        return $handler->handle($request);
    }
}
