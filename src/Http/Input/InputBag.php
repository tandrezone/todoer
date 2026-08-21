<?php

declare(strict_types=1);

namespace App\Http\Input;

use App\Exception\ValidationException;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Read-only, typed access to request data.
 *
 * This replaces every `$_POST['x'] ?? ''` in the original code. Values arrive already parsed by
 * the HTTP layer (a JSON body or a form post -- callers cannot tell the difference), and each
 * getter states the type it wants, so a controller never has to remember that a checkbox posts
 * "on" or that a JSON number arrives as an int while a form field arrives as a string.
 */
final class InputBag
{
    /** @param array<string, mixed> $data */
    public function __construct(private readonly array $data = [])
    {
    }

    public static function fromBody(ServerRequestInterface $request): self
    {
        $body = $request->getParsedBody();

        return new self(is_array($body) ? $body : []);
    }

    public static function fromQuery(ServerRequestInterface $request): self
    {
        return new self($request->getQueryParams());
    }

    public function has(string $key): bool
    {
        return array_key_exists($key, $this->data);
    }

    /** Present *and* not an empty string -- "the client actually sent something for this". */
    public function filled(string $key): bool
    {
        return $this->has($key) && $this->data[$key] !== '' && $this->data[$key] !== null;
    }

    public function string(string $key, string $default = ''): string
    {
        $value = $this->data[$key] ?? null;
        if (is_string($value)) {
            return trim($value);
        }
        if (is_int($value) || is_float($value)) {
            return (string) $value;
        }

        return $default;
    }

    public function requiredString(string $key, string $message): string
    {
        $value = $this->string($key);
        if ($value === '') {
            throw new ValidationException($message);
        }

        return $value;
    }

    public function int(string $key, int $default = 0): int
    {
        $value = $this->data[$key] ?? null;
        if (is_int($value)) {
            return $value;
        }
        if (is_string($value) && is_numeric(trim($value))) {
            return (int) trim($value);
        }
        if (is_float($value)) {
            return (int) $value;
        }

        return $default;
    }

    public function nullableInt(string $key): ?int
    {
        return $this->filled($key) ? $this->int($key) : null;
    }

    public function bool(string $key, bool $default = false): bool
    {
        $value = $this->data[$key] ?? null;
        if (is_bool($value)) {
            return $value;
        }
        if (is_string($value)) {
            return in_array(strtolower(trim($value)), ['1', 'true', 'on', 'yes'], true);
        }
        if (is_int($value)) {
            return $value === 1;
        }

        return $default;
    }

    /** @return list<mixed> */
    public function list(string $key): array
    {
        $value = $this->data[$key] ?? null;

        return is_array($value) ? array_values($value) : [];
    }

    /** @return array<string, mixed> */
    public function all(): array
    {
        return $this->data;
    }
}
