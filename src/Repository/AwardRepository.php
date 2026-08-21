<?php

declare(strict_types=1);

namespace App\Repository;

use App\Domain\Period\Period;
use App\Domain\Prize\Award;
use App\Support\Clock;
use PDO;

/** Prizes won (`awards`), and the pool they are drawn from (`prizes`). */
final class AwardRepository
{
    public function __construct(
        private readonly PDO $pdo,
        private readonly Clock $clock
    ) {
    }

    /**
     * One group's prize history, newest first.
     *
     * @return list<Award>
     */
    public function forGroup(int $groupId): array
    {
        $statement = $this->pdo->prepare(
            'SELECT a.id, a.list_type, a.period_key, a.points, a.claimed, a.awarded_at,
                    u.id AS user_id, u.username, u.color,
                    p.description AS prize
             FROM awards a
             JOIN users u ON u.id = a.user_id
             JOIN prizes p ON p.id = a.prize_id
             WHERE a.group_id = ?
             ORDER BY a.awarded_at DESC, a.id DESC'
        );
        $statement->execute([$groupId]);

        return array_map(static fn(array $row): Award => Award::fromRow($row), $statement->fetchAll());
    }

    public function create(int $groupId, int $userId, Period $period, int $points, int $prizeId): void
    {
        $statement = $this->pdo->prepare(
            'INSERT INTO awards (group_id, user_id, list_type, period_key, points, prize_id, awarded_at)
             VALUES (?, ?, ?, ?, ?, ?, ?)'
        );
        $statement->execute([
            $groupId,
            $userId,
            $period->listType->value,
            $period->key,
            $points,
            $prizeId,
            $this->clock->sqlNow(),
        ]);
    }

    /** Only the winner can mark their own prize claimed, and only inside their own group. */
    public function markClaimed(int $awardId, int $groupId, int $userId): bool
    {
        $statement = $this->pdo->prepare('UPDATE awards SET claimed = 1 WHERE id = ? AND group_id = ? AND user_id = ?');
        $statement->execute([$awardId, $groupId, $userId]);

        return $statement->rowCount() > 0;
    }

    /**
     * A prize this group has never been awarded before, or -- once it has worked through the pool
     * -- any prize at all.
     *
     * The pool of descriptions is shared installation-wide but "already won" is judged per group:
     * one group burning through the prizes should not leave another with nothing new to win.
     */
    public function drawPrizeId(int $groupId): ?int
    {
        $statement = $this->pdo->prepare(
            'SELECT id FROM prizes WHERE id NOT IN (SELECT prize_id FROM awards WHERE group_id = ?)
             ORDER BY RANDOM() LIMIT 1'
        );
        $statement->execute([$groupId]);
        $prizeId = $statement->fetchColumn();

        if ($prizeId === false) {
            $prizeId = $this->pdo->query('SELECT id FROM prizes ORDER BY RANDOM() LIMIT 1')->fetchColumn();
        }

        return $prizeId === false ? null : (int) $prizeId;
    }
}
