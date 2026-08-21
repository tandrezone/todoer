<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Decodes a JSON request body into the request's parsed body.
 *
 * PSR-7 populates getParsedBody() for form posts only, and the whole front-end talks JSON. Doing
 * it once here means no controller ever reads php://input, and an unparseable body degrades to
 * an empty array rather than a fatal -- validation then reports the missing fields normally.
 */
final class BodyParsingMiddleware implements MiddlewareInterface
{
    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        if (in_array($request->getMethod(), ['GET', 'HEAD', 'OPTIONS'], true)) {
            return $handler->handle($request);
        }

        $contentType = strtolower($request->getHeaderLine('Content-Type'));
        if (!str_contains($contentType, 'json')) {
            return $handler->handle($request);
        }

        $body = $request->getBody();
        if ($body->isSeekable()) {
            $body->rewind();
        }
        $raw = $body->getContents();
        if ($body->isSeekable()) {
            $body->rewind();
        }

        $decoded = $raw === '' ? [] : json_decode($raw, true);

        return $handler->handle($request->withParsedBody(is_array($decoded) ? $decoded : []));
    }
}
