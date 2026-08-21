<?php

declare(strict_types=1);

namespace App\Http;

/**
 * Matches a method + path against the route table.
 *
 * The table is a plain list of Route objects built in config/routes.php -- no annotations, no
 * scanning, no cache to invalidate. Paths are normalised first (installation sub-directory
 * stripped, trailing slash removed) so the same route table works whether the app is served
 * from https://example.com/ or http://nas.local/todoer/.
 */
final class Router
{
    /** @var list<Route> */
    private array $routes;

    /** @param list<Route> $routes */
    public function __construct(array $routes, private readonly string $basePath = '')
    {
        $this->routes = $routes;
    }

    public function match(string $method, string $path): RouteResult
    {
        $path = $this->normalisePath($path);
        $allowed = [];

        foreach ($this->routes as $route) {
            $params = $route->matchPath($path);
            if ($params === null) {
                continue;
            }
            if ($route->allowsMethod($method)) {
                return RouteResult::found($route, $params);
            }
            $allowed = array_merge($allowed, $route->methods);
        }

        return $allowed === [] ? RouteResult::notFound() : RouteResult::methodNotAllowed(array_values(array_unique($allowed)));
    }

    /** Strips the installation sub-directory and any trailing slash, always leaving a leading one. */
    public function normalisePath(string $path): string
    {
        if ($this->basePath !== '' && str_starts_with($path, $this->basePath)) {
            $path = substr($path, strlen($this->basePath));
        }
        $path = '/' . ltrim($path, '/');
        if ($path !== '/' && str_ends_with($path, '/')) {
            $path = rtrim($path, '/');
        }

        return $path === '' ? '/' : $path;
    }
}
