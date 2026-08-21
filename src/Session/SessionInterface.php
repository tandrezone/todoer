<?php

declare(strict_types=1);

namespace App\Session;

/**
 * The session, behind an interface.
 *
 * Two reasons this is not just $_SESSION: services that depend on the session (authentication,
 * CSRF) become unit-testable with an in-memory implementation, and the hardening of the session
 * cookie happens in exactly one place instead of at the top of every entry point.
 */
interface SessionInterface
{
    public function start(): void;

    public function isStarted(): bool;

    public function get(string $key, mixed $default = null): mixed;

    public function set(string $key, mixed $value): void;

    public function has(string $key): bool;

    public function remove(string $key): void;

    /** Rotates the session id, keeping the data -- called on every privilege change. */
    public function regenerateId(): void;

    /** Empties and invalidates the session, including its cookie. */
    public function destroy(): void;
}
