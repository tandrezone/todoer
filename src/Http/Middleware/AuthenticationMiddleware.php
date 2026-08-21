<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Http\RequestAttribute;
use App\Service\AuthService;
use App\Service\GroupService;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Resolves who is making this request, and which group they are in, once.
 *
 * Both land on the request as attributes, so a controller reads a typed User/Group instead of
 * re-querying the session. Crucially the group is resolved from the caller's *membership*, never
 * from anything the client sends -- that is the single fact the whole privacy model rests on, and
 * putting it here means no endpoint can forget to do it.
 *
 * A request with no session simply carries no user; whether that is allowed is the job of the
 * per-route Require*SessionMiddleware.
 */
final class AuthenticationMiddleware implements MiddlewareInterface
{
    public function __construct(
        private readonly AuthService $auth,
        private readonly GroupService $groups
    ) {
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $user = $this->auth->currentUser();
        if ($user === null) {
            return $handler->handle($request);
        }

        return $handler->handle(
            $request
                ->withAttribute(RequestAttribute::USER, $user)
                ->withAttribute(RequestAttribute::GROUP, $this->groups->requireGroupFor($user))
        );
    }
}
