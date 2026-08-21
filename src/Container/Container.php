<?php

declare(strict_types=1);

namespace App\Container;

use Psr\Container\ContainerInterface;

/**
 * A small, explicit PSR-11 container.
 *
 * Every service is described by a factory closure in config/container.php, so the wiring of the
 * application is one readable file rather than magic spread across constructors. Services are
 * resolved lazily and memoised, which keeps a request that only needs the leaderboard from
 * opening the Keep importer, and makes swapping an implementation (a fake clock, an in-memory
 * session) a one-line change in a test.
 */
final class Container implements ContainerInterface
{
    /** @var array<string, callable(ContainerInterface): mixed> */
    private array $factories;

    /** @var array<string, mixed> */
    private array $resolved = [];

    /** @var array<string, true> Ids currently being resolved, for cycle detection. */
    private array $resolving = [];

    /**
     * @param array<string, callable(ContainerInterface): mixed> $factories
     * @param array<string, mixed>                               $values    Pre-built entries (settings, scalars).
     */
    public function __construct(array $factories = [], array $values = [])
    {
        $this->factories = $factories;
        $this->resolved = $values;
    }

    /** @param callable(ContainerInterface): mixed $factory */
    public function set(string $id, callable $factory): void
    {
        $this->factories[$id] = $factory;
        unset($this->resolved[$id]);
    }

    public function value(string $id, mixed $value): void
    {
        $this->resolved[$id] = $value;
    }

    public function get(string $id): mixed
    {
        if (array_key_exists($id, $this->resolved)) {
            return $this->resolved[$id];
        }

        if (!isset($this->factories[$id])) {
            throw new ServiceNotFoundException(sprintf('Service "%s" is not registered.', $id));
        }

        if (isset($this->resolving[$id])) {
            throw new ContainerException(sprintf(
                'Circular dependency detected while resolving "%s" (chain: %s).',
                $id,
                implode(' -> ', array_keys($this->resolving))
            ));
        }

        $this->resolving[$id] = true;
        try {
            $service = ($this->factories[$id])($this);
        } finally {
            unset($this->resolving[$id]);
        }

        return $this->resolved[$id] = $service;
    }

    public function has(string $id): bool
    {
        return array_key_exists($id, $this->resolved) || isset($this->factories[$id]);
    }

    /**
     * Typed convenience wrapper: resolves $id and guarantees it is an instance of $class.
     *
     * @template T of object
     * @param  class-string<T> $class
     * @return T
     */
    public function typed(string $class, ?string $id = null): object
    {
        $service = $this->get($id ?? $class);
        if (!$service instanceof $class) {
            throw new ContainerException(sprintf(
                'Service "%s" was expected to be an instance of %s, got %s.',
                $id ?? $class,
                $class,
                get_debug_type($service)
            ));
        }

        return $service;
    }
}
