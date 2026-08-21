<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Http\MiddlewarePipeline;
use App\Http\RequestAttribute;
use Psr\Container\ContainerInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use RuntimeException;

/**
 * The end of the application-wide pipeline: runs the matched route's own middleware (the
 * "must be logged in" guards) and then its handler.
 */
final class DispatchMiddleware implements MiddlewareInterface
{
    public function __construct(private readonly ContainerInterface $container)
    {
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $route = RequestAttribute::route($request);
        if ($route === null) {
            throw new RuntimeException('DispatchMiddleware ran without a matched route -- is RoutingMiddleware in front of it?');
        }

        return (new MiddlewarePipeline($this->container, $route->middleware, $route->handler))->handle($request);
    }
}
