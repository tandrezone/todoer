<?php

declare(strict_types=1);

namespace App\Http;

use Psr\Container\ContainerInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use RuntimeException;

/**
 * A PSR-15 pipeline: a queue of middleware wrapped around one final request handler.
 *
 * Middleware and handler are given as container ids and resolved only when reached, so a route
 * that never gets hit never constructs anything. The pipeline itself is immutable and
 * re-entrant -- each handle() walks its own cursor -- which is what lets DispatchMiddleware
 * nest a second, route-specific pipeline inside the application-wide one.
 */
final class MiddlewarePipeline implements RequestHandlerInterface
{
    /** @param list<string> $middleware Container ids of MiddlewareInterface implementations. */
    public function __construct(
        private readonly ContainerInterface $container,
        private readonly array $middleware,
        private readonly string $handler
    ) {
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        return $this->runFrom($request, 0);
    }

    private function runFrom(ServerRequestInterface $request, int $index): ResponseInterface
    {
        if (!isset($this->middleware[$index])) {
            return $this->resolveHandler()->handle($request);
        }

        $middleware = $this->container->get($this->middleware[$index]);
        if (!$middleware instanceof MiddlewareInterface) {
            throw new RuntimeException(sprintf(
                'Middleware "%s" must implement %s, got %s.',
                $this->middleware[$index],
                MiddlewareInterface::class,
                get_debug_type($middleware)
            ));
        }

        return $middleware->process($request, $this->next($index + 1));
    }

    private function next(int $index): RequestHandlerInterface
    {
        return new class ($this, $index) implements RequestHandlerInterface {
            public function __construct(
                private readonly MiddlewarePipeline $pipeline,
                private readonly int $index
            ) {
            }

            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                return $this->pipeline->continueFrom($request, $this->index);
            }
        };
    }

    /** @internal Used by the anonymous handler returned from next(). */
    public function continueFrom(ServerRequestInterface $request, int $index): ResponseInterface
    {
        return $this->runFrom($request, $index);
    }

    private function resolveHandler(): RequestHandlerInterface
    {
        $handler = $this->container->get($this->handler);
        if (!$handler instanceof RequestHandlerInterface) {
            throw new RuntimeException(sprintf(
                'Handler "%s" must implement %s, got %s.',
                $this->handler,
                RequestHandlerInterface::class,
                get_debug_type($handler)
            ));
        }

        return $handler;
    }
}
