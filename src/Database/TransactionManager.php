<?php

declare(strict_types=1);

namespace App\Database;

use PDO;
use Throwable;

/**
 * Runs a unit of work in a transaction.
 *
 * Services ask for this rather than a PDO handle, so "commit on success, roll back on any throw"
 * is written once instead of in a dozen try/catch blocks. It also tolerates being nested: SQLite
 * has no nested transactions, and several operations here (creating a group, joining one, closing
 * a period) are used both standalone and as a step inside something bigger, so an inner call joins
 * the outer transaction instead of starting a second one.
 */
final class TransactionManager
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    /**
     * @template T
     * @param  callable(): T $work
     * @return T
     */
    public function transactional(callable $work): mixed
    {
        if ($this->pdo->inTransaction()) {
            return $work();
        }

        $this->pdo->beginTransaction();
        try {
            $result = $work();
            $this->pdo->commit();

            return $result;
        } catch (Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }
    }
}
