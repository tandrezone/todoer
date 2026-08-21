<?php

declare(strict_types=1);

namespace App\Http;

use App\Domain\Group\Group;
use App\Domain\Group\GroupMembership;
use App\Domain\User\User;
use App\Exception\AuthenticationException;
use Psr\Http\Message\ServerRequestInterface;

/**
 * The names of the request attributes the middleware stack puts on a request, plus typed
 * accessors for them.
 *
 * Controllers ask for `RequestAttribute::user($request)` rather than reaching into a session or
 * a global: by the time a handler runs, authentication middleware has either put a real User on
 * the request or the request never got here.
 */
final class RequestAttribute
{
    public const USER = 'todoer.user';
    public const GROUP = 'todoer.membership';
    public const ROUTE = 'todoer.route';
    public const ROUTE_PARAMS = 'todoer.route_params';

    private function __construct()
    {
    }

    public static function user(ServerRequestInterface $request): User
    {
        $user = $request->getAttribute(self::USER);
        if (!$user instanceof User) {
            throw new AuthenticationException('Not logged in.');
        }

        return $user;
    }

    public static function optionalUser(ServerRequestInterface $request): ?User
    {
        $user = $request->getAttribute(self::USER);

        return $user instanceof User ? $user : null;
    }

    /** The caller's group *and* their role in it -- what the API endpoints act on. */
    public static function membership(ServerRequestInterface $request): GroupMembership
    {
        $membership = $request->getAttribute(self::GROUP);
        if (!$membership instanceof GroupMembership) {
            throw new AuthenticationException('Not logged in.');
        }

        return $membership;
    }

    /** Just the group, for templates that only need its name. */
    public static function group(ServerRequestInterface $request): Group
    {
        return self::membership($request)->group;
    }

    public static function route(ServerRequestInterface $request): ?Route
    {
        $route = $request->getAttribute(self::ROUTE);

        return $route instanceof Route ? $route : null;
    }
}
