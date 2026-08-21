<?php

declare(strict_types=1);

namespace App\Http;

use Psr\Http\Message\ResponseInterface;

/**
 * Writes a PSR-7 response to the PHP SAPI.
 *
 * The only place in the application allowed to call header()/echo. Everything upstream of here
 * deals in response objects.
 */
final class SapiEmitter
{
    public function __construct(private readonly int $chunkSize = 8192)
    {
    }

    public function emit(ResponseInterface $response): void
    {
        if (headers_sent($file, $line)) {
            error_log(sprintf('Todoer: output already started at %s:%d, cannot emit response cleanly.', (string) $file, (int) $line));
        } else {
            foreach ($response->getHeaders() as $name => $values) {
                $first = strtolower($name) !== 'set-cookie';
                foreach ($values as $value) {
                    header($name . ': ' . $value, $first);
                    $first = false;
                }
            }

            header(sprintf(
                'HTTP/%s %d %s',
                $response->getProtocolVersion(),
                $response->getStatusCode(),
                $response->getReasonPhrase()
            ), true, $response->getStatusCode());
        }

        $body = $response->getBody();
        if ($body->isSeekable()) {
            $body->rewind();
        }

        while (!$body->eof()) {
            echo $body->read($this->chunkSize);
        }
    }
}
