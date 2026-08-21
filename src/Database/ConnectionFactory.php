<?php

declare(strict_types=1);

namespace App\Database;

use PDO;
use RuntimeException;

/**
 * Opens the one PDO connection the application uses.
 *
 * Exceptions on error, associative fetches and foreign keys ON are set here rather than hoped
 * for: SQLite ignores REFERENCES clauses unless foreign_keys is enabled per connection, and the
 * schema leans on ON DELETE CASCADE to keep a removed group from leaving orphaned tasks behind.
 */
final class ConnectionFactory
{
    public function __construct(
        private readonly string $dsn,
        private readonly ?string $username = null,
        private readonly ?string $password = null,
        private readonly ?string $dataDir = null
    ) {
    }

    public function create(): PDO
    {
        if ($this->dataDir !== null && !is_dir($this->dataDir)) {
            // 0770, not 0777: this directory holds the database and the Web Push private key.
            if (!mkdir($this->dataDir, 0770, true) && !is_dir($this->dataDir)) {
                throw new RuntimeException('Could not create the data directory: ' . $this->dataDir);
            }
        }

        $pdo = new PDO($this->dsn, $this->username, $this->password, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]);

        if (str_starts_with($this->dsn, 'sqlite:')) {
            $pdo->exec('PRAGMA foreign_keys = ON');
            // WAL keeps a reader (someone loading the dashboard) from blocking the maintenance
            // sweep's writes, which matters when several housemates refresh at once.
            $pdo->exec('PRAGMA journal_mode = WAL');
            $pdo->exec('PRAGMA busy_timeout = 5000');
        }

        return $pdo;
    }
}
