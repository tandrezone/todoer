<?php

declare(strict_types=1);

namespace App\Http;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use RuntimeException;

/**
 * The end of the pipeline, which should never be reached.
 *
 * PSR-15 requires a pipeline to bottom out in a request handler. In this application the last
 * middleware (DispatchMiddleware) always produces a response, and a request that matched no route
 * has already been turned into a 404 by RoutingMiddleware -- so getting here means the pipeline was
 * misconfigured, and saying so loudly beats returning a blank 200.
 */
final class TerminalHandler implements RequestHandlerInterface
{
    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        throw new RuntimeException(sprintf(
            'The middleware pipeline fell through without producing a response for %s %s.',
            $request->getMethod(),
            $request->getUri()->getPath()
        ));
    }
}
