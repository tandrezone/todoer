<?php

declare(strict_types=1);

namespace App\Exception;

use RuntimeException;
use Throwable;

final class MethodNotAllowedException extends RuntimeException implements HttpAwareException
{
    /** @param list<string> $allowedMethods */
    public function __construct(
        private readonly array $allowedMethods,
        string $message = 'Method not allowed.',
        ?Throwable $previous = null
    ) {
        parent::__construct($message, 0, $previous);
    }

    public function statusCode(): int
    {
        return 405;
    }

    /** @return list<string> */
    public function allowedMethods(): array
    {
        return $this->allowedMethods;
    }
}
