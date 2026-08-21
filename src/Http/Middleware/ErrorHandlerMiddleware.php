<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Exception\HttpAwareException;
use App\Exception\MethodNotAllowedException;
use App\Http\Responder;
use App\View\TemplateRenderer;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * The outermost middleware: nothing escapes past it.
 *
 * Two kinds of failure arrive here. Expected ones (validation, permission, not-found) implement
 * HttpAwareException, carry a message written for the person reading it, and become that status
 * code. Anything else is a bug: it is logged with file and line, and the client gets a generic
 * 500 -- no stack trace, no file path, no SQL fragment, whether the caller wanted HTML or JSON.
 *
 * The original code did this with set_exception_handler() plus an `echo json_encode(...)`, which
 * meant an HTML page that failed halfway rendered a JSON error into the middle of its own markup.
 */
final class ErrorHandlerMiddleware implements MiddlewareInterface
{
    private const GENERIC_MESSAGE = 'Something went wrong on our end. Please try again.';

    public function __construct(
        private readonly Responder $responder,
        private readonly TemplateRenderer $renderer,
        private readonly LoggerInterface $logger,
        private readonly bool $debug = false
    ) {
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        try {
            return $handler->handle($request);
        } catch (HttpAwareException $e) {
            $response = $this->render($request, $e->statusCode(), $e->getMessage());

            return $e instanceof MethodNotAllowedException && $e->allowedMethods() !== []
                ? $response->withHeader('Allow', implode(', ', $e->allowedMethods()))
                : $response;
        } catch (Throwable $e) {
            $this->logger->error('Unhandled exception', ['exception' => $e]);

            return $this->render(
                $request,
                500,
                $this->debug ? get_class($e) . ': ' . $e->getMessage() : self::GENERIC_MESSAGE
            );
        }
    }

    private function render(ServerRequestInterface $request, int $status, string $message): ResponseInterface
    {
        if ($this->wantsJson($request)) {
            return $this->responder->jsonError($message, $status);
        }

        return $this->responder->html(
            $this->renderer->page(
                'error',
                ['status' => $status, 'message' => $message],
                ['title' => 'Todoer', 'bodyClass' => 'auth-body']
            ),
            $status
        );
    }

    /** API routes and XHR callers get JSON; a browser asking for a page gets a page. */
    private function wantsJson(ServerRequestInterface $request): bool
    {
        if (str_contains($request->getUri()->getPath(), '/api/')) {
            return true;
        }
        $accept = strtolower($request->getHeaderLine('Accept'));
        if (str_contains($accept, 'application/json')) {
            return true;
        }

        return str_contains(strtolower($request->getHeaderLine('Content-Type')), 'json');
    }
}
