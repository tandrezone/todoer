<?php

declare(strict_types=1);

namespace App\Session;

/** An in-memory session, for tests and CLI maintenance runs. */
final class ArraySession implements SessionInterface
{
    /** @param array<string, mixed> $data */
    public function __construct(private array $data = [], private bool $started = false)
    {
    }

    public function start(): void
    {
        $this->started = true;
    }

    public function isStarted(): bool
    {
        return $this->started;
    }

    public function get(string $key, mixed $default = null): mixed
    {
        return $this->data[$key] ?? $default;
    }

    public function set(string $key, mixed $value): void
    {
        $this->data[$key] = $value;
    }

    public function has(string $key): bool
    {
        return isset($this->data[$key]);
    }

    public function remove(string $key): void
    {
        unset($this->data[$key]);
    }

    public function regenerateId(): void
    {
        // No id to rotate in memory; the data survives, exactly as it does for a real session.
    }

    public function destroy(): void
    {
        $this->data = [];
        $this->started = false;
    }
}
