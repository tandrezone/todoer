<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Exception\MethodNotAllowedException;
use App\Exception\NotFoundException;
use App\Http\RequestAttribute;
use App\Http\Router;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/** Resolves the request to a route and puts it (plus its path parameters) on the request. */
final class RoutingMiddleware implements MiddlewareInterface
{
    public function __construct(private readonly Router $router)
    {
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $result = $this->router->match($request->getMethod(), $request->getUri()->getPath());

        if (!$result->isFound()) {
            if ($result->methodMismatch) {
                throw new MethodNotAllowedException($result->allowedMethods, 'Method not allowed.');
            }

            throw new NotFoundException('That page does not exist.');
        }

        $request = $request
            ->withAttribute(RequestAttribute::ROUTE, $result->route)
            ->withAttribute(RequestAttribute::ROUTE_PARAMS, $result->params);

        return $handler->handle($request);
    }
}
