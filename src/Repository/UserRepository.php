<?php

declare(strict_types=1);

namespace App\Repository;

use App\Domain\User\User;
use PDO;

/**
 * Every SQL statement that touches `users`.
 *
 * All of them are prepared with bound parameters -- no string concatenation anywhere in the
 * repository layer -- which is what makes SQL injection a non-question rather than a review item.
 */
final class UserRepository
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    public function find(int $id): ?User
    {
        $statement = $this->pdo->prepare('SELECT id, username, color, active, created_at FROM users WHERE id = ?');
        $statement->execute([$id]);
        $row = $statement->fetch();

        return $row === false ? null : User::fromRow($row);
    }

    /** Includes the password hash: this is the only read that needs it. */
    public function findByUsernameWithCredentials(string $username): ?User
    {
        $statement = $this->pdo->prepare(
            'SELECT id, username, color, active, created_at, password_hash FROM users WHERE username = ?'
        );
        $statement->execute([trim($username)]);
        $row = $statement->fetch();

        return $row === false ? null : User::fromRow($row);
    }

    public function findByUsername(string $username): ?User
    {
        $statement = $this->pdo->prepare('SELECT id, username, color, active, created_at FROM users WHERE username = ?');
        $statement->execute([trim($username)]);
        $row = $statement->fetch();

        return $row === false ? null : User::fromRow($row);
    }

    public function usernameExists(string $username): bool
    {
        $statement = $this->pdo->prepare('SELECT 1 FROM users WHERE username = ?');
        $statement->execute([trim($username)]);

        return $statement->fetchColumn() !== false;
    }

    public function count(): int
    {
        return (int) $this->pdo->query('SELECT COUNT(*) FROM users')->fetchColumn();
    }

    /** @return array<string, int> username => id, for the import path's name resolution. */
    public function idsByUsername(int $groupId): array
    {
        $statement = $this->pdo->prepare(
            'SELECT u.username, u.id FROM group_members gm JOIN users u ON u.id = gm.user_id WHERE gm.group_id = ?'
        );
        $statement->execute([$groupId]);

        $map = [];
        foreach ($statement->fetchAll() as $row) {
            $map[(string) $row['username']] = (int) $row['id'];
        }

        return $map;
    }

    public function create(string $username, string $passwordHash, string $color): int
    {
        $statement = $this->pdo->prepare('INSERT INTO users (username, password_hash, color) VALUES (?, ?, ?)');
        $statement->execute([$username, $passwordHash, $color]);

        return (int) $this->pdo->lastInsertId();
    }
}
