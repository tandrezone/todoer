<?php
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/groups.php';

const TODOER_POINTS = [
    'daily' => 1,
    'weekly' => 3,
    'monthly' => 5,
];

const TODOER_LIST_TYPES = ['daily', 'weekly', 'monthly'];

// The fixed play window every list runs on. A period opens at 06:30 on its first day and closes
// at 23:59 on its last one:
//   daily   -> 06:30 that day        .. 23:59 that day
//   weekly  -> 06:30 Monday          .. 23:59 Sunday
//   monthly -> 06:30 on the 1st      .. 23:59 on the last day of the month
// These bounds are the outer limit of everything else: a task with no window of its own inherits
// them, and a task with one is clamped inside them (see todoer_period_window_datetime()). Nothing
// can be ticked off before its period opens, and once the close time passes, unfinished tasks are
// missed rather than handed around (see todoer_process_expirations()).
const TODOER_PERIOD_OPEN_HOUR = 6;
const TODOER_PERIOD_OPEN_MINUTE = 30;
const TODOER_PERIOD_CLOSE_HOUR = 23;
const TODOER_PERIOD_CLOSE_MINUTE = 59;

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
 * The first and last calendar dates covered by a period, as 'Y-m-d'. Weekly periods run
 * Monday..Sunday (ISO), monthly periods the 1st..the month's real last day.
 */
function todoer_period_date_range(string $listType, string $periodKey): array {
    switch ($listType) {
        case 'daily':
            return [$periodKey, $periodKey];
        case 'weekly':
            [$year, $week] = explode('-', $periodKey);
            $start = new DateTime();
            $start->setISODate((int) $year, (int) $week, 1);   // Monday
            $end = new DateTime();
            $end->setISODate((int) $year, (int) $week, 7);     // Sunday
            return [$start->format('Y-m-d'), $end->format('Y-m-d')];
        case 'monthly':
            $first = $periodKey . '-01';
            return [$first, date('Y-m-t', strtotime($first))];
        default:
            throw new InvalidArgumentException('Unknown list type: ' . $listType);
    }
}

/**
 * The period's play window as ['start' => datetime, 'end' => datetime]: opens 06:30 on the first
 * day, closes 23:59 on the last. Every task in the period lives inside this.
 */
function todoer_period_bounds(string $listType, string $periodKey): array {
    [$firstDay, $lastDay] = todoer_period_date_range($listType, $periodKey);
    return [
        'start' => $firstDay . ' ' . sprintf('%02d:%02d:00', TODOER_PERIOD_OPEN_HOUR, TODOER_PERIOD_OPEN_MINUTE),
        'end' => $lastDay . ' ' . sprintf('%02d:%02d:00', TODOER_PERIOD_CLOSE_HOUR, TODOER_PERIOD_CLOSE_MINUTE),
    ];
}

/** Whether a period's play window is open right now (between 06:30 and 23:59 of its own dates). */
function todoer_period_window_is_open(string $listType, string $periodKey, ?int $now = null): bool {
    $now = $now ?? time();
    $bounds = todoer_period_bounds($listType, $periodKey);
    return $now >= strtotime($bounds['start']) && $now <= strtotime($bounds['end']);
}

/** "06:30 → 23:59" / "Mon 06:30 → Sun 23:59" -- the window, phrased for the list's cadence. */
function todoer_period_window_label(string $listType, string $periodKey): string {
    $bounds = todoer_period_bounds($listType, $periodKey);
    $open = date('H:i', strtotime($bounds['start']));
    $close = date('H:i', strtotime($bounds['end']));
    switch ($listType) {
        case 'daily':
            return $open . ' → ' . $close;
        case 'weekly':
            return 'Mon ' . $open . ' → Sun ' . $close;
        default:
            return date('j M', strtotime($bounds['start'])) . ' ' . $open
                . ' → ' . date('j M', strtotime($bounds['end'])) . ' ' . $close;
    }
}

/**
 * Holds a datetime inside the period's window. A task can be tighter than its period (do the
 * dishes between 18:00 and 20:00) but never wider: a window that starts before the period opens
 * or ends after it closes is pulled back to the period's own bound, so the 06:30/23:59 rule is
 * the one thing every task obeys.
 */
function todoer_clamp_to_period(string $listType, string $periodKey, ?string $datetime): ?string {
    if ($datetime === null) {
        return null;
    }
    $bounds = todoer_period_bounds($listType, $periodKey);
    if ($datetime < $bounds['start']) {
        return $bounds['start'];
    }
    if ($datetime > $bounds['end']) {
        return $bounds['end'];
    }
    return $datetime;
}

/**
 * A task's window, filling in the period's own bounds wherever the task doesn't specify one.
 * Returns ['start' => datetime, 'end' => datetime] -- both always set, because every task is
 * bounded by its period even when nobody typed a time.
 */
function todoer_task_window(string $listType, string $periodKey, ?string $start, ?string $end): array {
    $bounds = todoer_period_bounds($listType, $periodKey);
    return [
        'start' => todoer_clamp_to_period($listType, $periodKey, $start) ?? $bounds['start'],
        'end' => todoer_clamp_to_period($listType, $periodKey, $end) ?? $bounds['end'],
    ];
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
 * $endOfDay: the weekly/monthly shorthands carry no time-of-day, so a window *start* defaults to
 *            the period's opening time (06:30) and a window *end* to its closing time (23:59) on
 *            the resolved date. The result is clamped into the period's own bounds either way.
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
        return todoer_clamp_to_period(
            'daily',
            $periodKey,
            $periodKey . ' ' . sprintf('%02d:%02d', (int) $m[1], (int) $m[2]) . ':00'
        );
    }

    if ($listType === 'weekly') {
        $day = (int) $value;
        if ($day < 1 || $day > 7) {
            return null;
        }
        [$year, $week] = explode('-', $periodKey);
        $dt = new DateTime();
        $dt->setISODate((int) $year, (int) $week, $day);
        $dt->setTime(
            $endOfDay ? TODOER_PERIOD_CLOSE_HOUR : TODOER_PERIOD_OPEN_HOUR,
            $endOfDay ? TODOER_PERIOD_CLOSE_MINUTE : TODOER_PERIOD_OPEN_MINUTE,
            0
        );
        return todoer_clamp_to_period('weekly', $periodKey, $dt->format('Y-m-d H:i:s'));
    }

    // monthly
    $day = (int) $value;
    if ($day < 1 || $day > 31) {
        return null;
    }
    $lastDay = (int) date('t', strtotime($periodKey . '-01'));
    $day = min($day, $lastDay);
    $dt = new DateTime($periodKey . '-' . str_pad((string) $day, 2, '0', STR_PAD_LEFT));
    $dt->setTime(
        $endOfDay ? TODOER_PERIOD_CLOSE_HOUR : TODOER_PERIOD_OPEN_HOUR,
        $endOfDay ? TODOER_PERIOD_CLOSE_MINUTE : TODOER_PERIOD_OPEN_MINUTE,
        0
    );
    return todoer_clamp_to_period('monthly', $periodKey, $dt->format('Y-m-d H:i:s'));
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
