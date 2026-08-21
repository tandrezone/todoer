<?php

declare(strict_types=1);

namespace App\Http;

use JsonException;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\StreamFactoryInterface;

/**
 * Turns application results into PSR-7 responses.
 *
 * Controllers never touch header(), http_response_code() or echo -- they return a response
 * object, which is what makes them callable from a test without output buffering, and what keeps
 * "did we already send headers?" from being a question anyone has to ask.
 */
final class Responder
{
    public function __construct(
        private readonly ResponseFactoryInterface $responseFactory,
        private readonly StreamFactoryInterface $streamFactory
    ) {
    }

    /** @param array<string, mixed>|list<mixed> $data */
    public function json(array $data, int $status = 200, int $flags = 0): ResponseInterface
    {
        try {
            $body = json_encode($data, JSON_THROW_ON_ERROR | $flags);
        } catch (JsonException $e) {
            $body = json_encode(['ok' => false, 'error' => 'Could not encode the response.']);
        }

        return $this->responseFactory->createResponse($status)
            ->withHeader('Content-Type', 'application/json; charset=utf-8')
            ->withBody($this->streamFactory->createStream((string) $body));
    }

    public function jsonError(string $message, int $status = 400): ResponseInterface
    {
        return $this->json(['ok' => false, 'error' => $message], $status);
    }

    public function html(string $html, int $status = 200): ResponseInterface
    {
        return $this->responseFactory->createResponse($status)
            ->withHeader('Content-Type', 'text/html; charset=utf-8')
            ->withBody($this->streamFactory->createStream($html));
    }

    public function text(string $text, int $status = 200): ResponseInterface
    {
        return $this->responseFactory->createResponse($status)
            ->withHeader('Content-Type', 'text/plain; charset=utf-8')
            ->withBody($this->streamFactory->createStream($text));
    }

    public function redirect(string $location, int $status = 302): ResponseInterface
    {
        return $this->responseFactory->createResponse($status)->withHeader('Location', $location);
    }

    public function download(string $body, string $filename, string $contentType = 'application/json'): ResponseInterface
    {
        // The filename is quoted and stripped of anything that could break out of the header.
        $safeName = preg_replace('/[^A-Za-z0-9._-]+/', '-', $filename) ?? 'download';

        return $this->responseFactory->createResponse(200)
            ->withHeader('Content-Type', $contentType)
            ->withHeader('Content-Disposition', 'attachment; filename="' . $safeName . '"')
            ->withBody($this->streamFactory->createStream($body));
    }
}
