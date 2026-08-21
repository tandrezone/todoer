<?php

declare(strict_types=1);

namespace App\Http;

/**
 * One route: which methods and path reach which request handler, and what runs in front of it.
 *
 * `handler` and `middleware` are container ids (class names in practice), never instances, so
 * building the route table costs nothing and only the handler a request actually hits gets
 * constructed.
 */
final class Route
{
    /** @var list<string> */
    public readonly array $methods;

    private string $pattern;

    /** @param list<string> $middleware */
    public function __construct(
        public readonly string $name,
        array $methods,
        public readonly string $path,
        public readonly string $handler,
        public readonly array $middleware = []
    ) {
        $this->methods = array_values(array_unique(array_map('strtoupper', $methods)));
        $this->pattern = self::compile($path);
    }

    /** @param list<string> $middleware */
    public static function get(string $name, string $path, string $handler, array $middleware = []): self
    {
        return new self($name, ['GET', 'HEAD'], $path, $handler, $middleware);
    }

    /** @param list<string> $middleware */
    public static function post(string $name, string $path, string $handler, array $middleware = []): self
    {
        return new self($name, ['POST'], $path, $handler, $middleware);
    }

    /** @param list<string> $middleware */
    public static function any(string $name, array $methods, string $path, string $handler, array $middleware = []): self
    {
        return new self($name, $methods, $path, $handler, $middleware);
    }

    public function allowsMethod(string $method): bool
    {
        return in_array(strtoupper($method), $this->methods, true);
    }

    /**
     * @return array<string, string>|null Placeholder values, or null when the path does not match.
     */
    public function matchPath(string $path): ?array
    {
        if (preg_match($this->pattern, $path, $matches) !== 1) {
            return null;
        }

        return array_filter($matches, static fn(int|string $key): bool => is_string($key), ARRAY_FILTER_USE_KEY);
    }

    /** Turns "/api/tasks/{id}" into an anchored regex with a named capture per placeholder. */
    private static function compile(string $path): string
    {
        preg_match_all('/\{([a-zA-Z_][a-zA-Z0-9_]*)\}/', $path, $matches, PREG_OFFSET_CAPTURE);

        $pattern = '';
        $offset = 0;
        foreach ($matches[0] as $index => [$literal, $position]) {
            $pattern .= preg_quote(substr($path, $offset, $position - $offset), '#');
            $pattern .= '(?P<' . $matches[1][$index][0] . '>[^/]+)';
            $offset = $position + strlen((string) $literal);
        }
        $pattern .= preg_quote(substr($path, $offset), '#');

        return '#^' . $pattern . '$#';
    }
}
