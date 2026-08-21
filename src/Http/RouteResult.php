<?php

declare(strict_types=1);

namespace App\Http;

/** The outcome of matching a request against the route table. */
final class RouteResult
{
    /**
     * @param array<string, string> $params
     * @param list<string>          $allowedMethods
     */
    private function __construct(
        public readonly ?Route $route,
        public readonly array $params,
        public readonly array $allowedMethods,
        public readonly bool $methodMismatch
    ) {
    }

    /** @param array<string, string> $params */
    public static function found(Route $route, array $params): self
    {
        return new self($route, $params, [], false);
    }

    /** @param list<string> $allowedMethods */
    public static function methodNotAllowed(array $allowedMethods): self
    {
        return new self(null, [], $allowedMethods, true);
    }

    public static function notFound(): self
    {
        return new self(null, [], [], false);
    }

    public function isFound(): bool
    {
        return $this->route !== null;
    }
}
