<?php

declare(strict_types=1);

namespace App\Exception;

use Throwable;

/**
 * An exception that already knows which HTTP status it should turn into, and whose message is
 * safe to show a user.
 *
 * Services throw these instead of formatting responses themselves: the domain says "that invite
 * code does not match any group", and the HTTP layer (ErrorHandlerMiddleware) decides whether
 * that becomes a 403 JSON body or a rendered error page. Anything that is *not* one of these is
 * treated as a bug: it is logged in full and the client gets a generic 500.
 */
interface HttpAwareException extends Throwable
{
    public function statusCode(): int;
}
