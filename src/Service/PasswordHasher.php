<?php

declare(strict_types=1);

namespace App\Service;

/**
 * Password hashing, behind a seam.
 *
 * PASSWORD_DEFAULT follows PHP's recommended algorithm as it changes over releases, and
 * needsRehash() lets an old hash be upgraded on the next successful sign-in. Injecting this rather
 * than calling password_hash() inline also keeps the test suite fast: tests bind a cheap cost.
 */
final class PasswordHasher
{
    /** @param array<string, mixed> $options */
    public function __construct(
        private readonly string $algorithm = PASSWORD_DEFAULT,
        private readonly array $options = []
    ) {
    }

    public function hash(string $password): string
    {
        return password_hash($password, $this->algorithm, $this->options);
    }

    public function verify(string $password, string $hash): bool
    {
        return password_verify($password, $hash);
    }

    public function needsRehash(string $hash): bool
    {
        return password_needs_rehash($hash, $this->algorithm, $this->options);
    }
}
