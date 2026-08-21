<?php

declare(strict_types=1);

namespace App\Exception;

use RuntimeException;

final class AuthorizationException extends RuntimeException implements HttpAwareException
{
    public function statusCode(): int
    {
        return 403;
    }
}
