<?php

declare(strict_types=1);

namespace App\Repository;

use App\Domain\Period\Period;
use PDO;

/**
 * The standings queries.
 *
 * Both start from `group_members` and LEFT JOIN the tasks, so every member appears -- including
 * one who has scored nothing yet, which is exactly who needs to see the board. And because the
 * join is on the *group's* tasks, somebody outside the group has no place in these rows and this
 * group has no place in theirs.
 */
final class LeaderboardRepository
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    /** @return list<array{user_id: int, username: string, color: string, points: int}> */
    public function forPeriod(int $groupId, Period $period): array
    {
        $statement = $this->pdo->prepare(
            "SELECT u.id AS user_id, u.username, u.color,
                    COALESCE(SUM(CASE WHEN t.status = 'done' AND t.list_type = ? AND t.period_key = ? THEN t.points ELSE 0 END), 0) AS points
             FROM group_members gm
             JOIN users u ON u.id = gm.user_id
             LEFT JOIN tasks t ON t.user_id = u.id AND t.group_id = gm.group_id
             WHERE gm.group_id = ?
             GROUP BY u.id
             ORDER BY points DESC, u.username ASC"
        );
        $statement->execute([$period->listType->value, $period->key, $groupId]);

        return array_map(static fn(array $row): array => [
            'user_id' => (int) $row['user_id'],
            'username' => (string) $row['username'],
            'color' => (string) $row['color'],
            'points' => (int) $row['points'],
        ], $statement->fetchAll());
    }

    /** @return list<array{user_id: int, username: string, color: string, points: int, prize_count: int}> */
    public function allTime(int $groupId): array
    {
        $statement = $this->pdo->prepare(
            "SELECT u.id AS user_id, u.username, u.color,
                    COALESCE(SUM(CASE WHEN t.status = 'done' THEN t.points ELSE 0 END), 0) AS points,
                    (SELECT COUNT(*) FROM awards a WHERE a.user_id = u.id AND a.group_id = gm.group_id) AS prize_count
             FROM group_members gm
             JOIN users u ON u.id = gm.user_id
             LEFT JOIN tasks t ON t.user_id = u.id AND t.group_id = gm.group_id
             WHERE gm.group_id = ?
             GROUP BY u.id
             ORDER BY points DESC, u.username ASC"
        );
        $statement->execute([$groupId]);

        return array_map(static fn(array $row): array => [
            'user_id' => (int) $row['user_id'],
            'username' => (string) $row['username'],
            'color' => (string) $row['color'],
            'points' => (int) $row['points'],
            'prize_count' => (int) $row['prize_count'],
        ], $statement->fetchAll());
    }
}
