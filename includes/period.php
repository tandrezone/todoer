<?php
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/groups.php';

const TODOER_POINTS = [
    'daily' => 1,
    'weekly' => 3,
    'monthly' => 5,
];

const TODOER_LIST_TYPES = ['daily', 'weekly', 'monthly'];

const TODOER_LABELS = [
    'daily' => 'Today',
    'weekly' => 'This week',
    'monthly' => 'This month',
];

function todoer_period_key(string $listType, ?int $ts = null): string {
    $ts = $ts ?? time();
    switch ($listType) {
        case 'daily':
            return date('Y-m-d', $ts);
        case 'weekly':
            // ISO year + zero-padded ISO week, sorts chronologically as a string.
            return date('o', $ts) . '-' . date('W', $ts);
        case 'monthly':
            return date('Y-m', $ts);
        default:
            throw new InvalidArgumentException('Unknown list type: ' . $listType);
    }
}

/**
 * Combines a natural-cadence window value with a task's period_key into a full
 * "YYYY-MM-DD HH:MM:SS" datetime, so window_start/window_end stay ordinary datetimes
 * everywhere else in the app (sorting, deadline math) no matter which shorthand the UI
 * captured -- a daily window repeats every day so only a time-of-day makes sense for it, a
 * weekly window only needs a day-of-week, and a monthly window only needs a day-of-month.
 *
 * $value:
 *   daily   -> "HH:MM" (from <input type=time>)
 *   weekly  -> "1".."7" (Mon=1 .. Sun=7)
 *   monthly -> "1".."31" (day of month; clamped to that month's actual last day)
 * $endOfDay: the weekly/monthly shorthands carry no time-of-day, so a window *start* defaults
 *            to 00:00 and a window *end* defaults to 23:59 on the resolved date.
 * Returns null for an empty/invalid value.
 */
function todoer_period_window_datetime(string $listType, string $periodKey, ?string $value, bool $endOfDay): ?string {
    $value = trim((string) $value);
    if ($value === '') {
        return null;
    }

    if ($listType === 'daily') {
        if (!preg_match('/^([01]?\d|2[0-3]):([0-5]\d)$/', $value, $m)) {
            return null;
        }
        return $periodKey . ' ' . sprintf('%02d:%02d', (int) $m[1], (int) $m[2]) . ':00';
    }

    if ($listType === 'weekly') {
        $day = (int) $value;
        if ($day < 1 || $day > 7) {
            return null;
        }
        [$year, $week] = explode('-', $periodKey);
        $dt = new DateTime();
        $dt->setISODate((int) $year, (int) $week, $day);
        $dt->setTime($endOfDay ? 23 : 0, $endOfDay ? 59 : 0, 0);
        return $dt->format('Y-m-d H:i:s');
    }

    // monthly
    $day = (int) $value;
    if ($day < 1 || $day > 31) {
        return null;
    }
    $lastDay = (int) date('t', strtotime($periodKey . '-01'));
    $day = min($day, $lastDay);
    $dt = new DateTime($periodKey . '-' . str_pad((string) $day, 2, '0', STR_PAD_LEFT));
    $dt->setTime($endOfDay ? 23 : 0, $endOfDay ? 59 : 0, 0);
    return $dt->format('Y-m-d H:i:s');
}

function todoer_period_label(string $listType, string $periodKey): string {
    switch ($listType) {
        case 'daily':
            return date('D, j M Y', strtotime($periodKey));
        case 'weekly':
            [$year, $week] = explode('-', $periodKey);
            $dt = new DateTime();
            $dt->setISODate((int) $year, (int) $week);
            $end = clone $dt;
            $end->modify('+6 days');
            return 'Week ' . (int) $week . ' (' . $dt->format('j M') . ' - ' . $end->format('j M Y') . ')';
        case 'monthly':
            return date('F Y', strtotime($periodKey . '-01'));
        default:
            return $periodKey;
    }
}

/**
 * Closes any elapsed (not current) period that hasn't been tallied yet, for one group: sums
 * points per user for that period, crowns the top scorer, and awards them a random prize from
 * the pool. Ties are broken randomly. Safe to call on every page load.
 *
 * Everything here is per group: each group closes its own copy of the same day/week/month and
 * crowns its own winner, so one group's scores never decide another group's prize.
 */
function todoer_close_elapsed_periods(PDO $pdo, int $groupId): void {
    foreach (TODOER_LIST_TYPES as $listType) {
        $currentKey = todoer_period_key($listType);

        $stmt = $pdo->prepare(
            'SELECT DISTINCT period_key FROM tasks
             WHERE group_id = ? AND list_type = ? AND period_key < ?
             AND period_key NOT IN (SELECT period_key FROM periods_closed WHERE group_id = ? AND list_type = ?)'
        );
        $stmt->execute([$groupId, $listType, $currentKey, $groupId, $listType]);
        $pendingKeys = array_column($stmt->fetchAll(), 'period_key');

        foreach ($pendingKeys as $periodKey) {
            todoer_close_one_period($pdo, $groupId, $listType, $periodKey);
        }
    }
}

function todoer_close_one_period(PDO $pdo, int $groupId, string $listType, string $periodKey): void {
    $pdo->beginTransaction();
    try {
        $stmt = $pdo->prepare(
            "SELECT user_id, SUM(points) AS total
             FROM tasks
             WHERE group_id = ? AND list_type = ? AND period_key = ? AND status = 'done'
             GROUP BY user_id
             ORDER BY total DESC"
        );
        $stmt->execute([$groupId, $listType, $periodKey]);
        $totals = $stmt->fetchAll();

        $topScore = $totals ? (int) $totals[0]['total'] : 0;

        if ($topScore > 0) {
            $winners = array_values(array_filter($totals, fn($r) => (int) $r['total'] === $topScore));
            $winner = $winners[array_rand($winners)];

            // Prefer a prize this *group* has never been awarded before; once the group has
            // worked through the pool, recycle randomly. The pool of prize descriptions is
            // shared install-wide, but "already won" is judged per group -- one group burning
            // through prizes shouldn't leave another with nothing new to win.
            $prizeStmt = $pdo->prepare(
                'SELECT id FROM prizes WHERE id NOT IN (SELECT prize_id FROM awards WHERE group_id = ?)
                 ORDER BY RANDOM() LIMIT 1'
            );
            $prizeStmt->execute([$groupId]);
            $prizeId = $prizeStmt->fetchColumn();
            if ($prizeId === false) {
                $prizeId = $pdo->query('SELECT id FROM prizes ORDER BY RANDOM() LIMIT 1')->fetchColumn();
            }

            $insert = $pdo->prepare(
                'INSERT INTO awards (group_id, user_id, list_type, period_key, points, prize_id)
                 VALUES (?, ?, ?, ?, ?, ?)'
            );
            $insert->execute([$groupId, $winner['user_id'], $listType, $periodKey, $topScore, $prizeId]);
        }

        $close = $pdo->prepare('INSERT OR IGNORE INTO periods_closed (group_id, list_type, period_key) VALUES (?, ?, ?)');
        $close->execute([$groupId, $listType, $periodKey]);

        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
}

/**
 * Points totals per user for a given list_type + period_key (current period by default), scoped
 * to one group: only that group's members are rows, and only points earned on that group's tasks
 * count. This is "no place in the prizelist outside your group" in query form.
 */
function todoer_leaderboard(PDO $pdo, int $groupId, string $listType, ?string $periodKey = null): array {
    $periodKey = $periodKey ?? todoer_period_key($listType);
    $stmt = $pdo->prepare(
        "SELECT u.id AS user_id, u.username, u.color,
                COALESCE(SUM(CASE WHEN t.status = 'done' AND t.list_type = ? AND t.period_key = ? THEN t.points ELSE 0 END), 0) AS points
         FROM group_members gm
         JOIN users u ON u.id = gm.user_id
         LEFT JOIN tasks t ON t.user_id = u.id AND t.group_id = gm.group_id
         WHERE gm.group_id = ?
         GROUP BY u.id
         ORDER BY points DESC, u.username ASC"
    );
    $stmt->execute([$listType, $periodKey, $groupId]);
    return $stmt->fetchAll();
}

/** All-time points totals per user within one group, across every list type. */
function todoer_all_time_leaderboard(PDO $pdo, int $groupId): array {
    $stmt = $pdo->prepare(
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
    $stmt->execute([$groupId]);
    return $stmt->fetchAll();
}
