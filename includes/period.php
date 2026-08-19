<?php
require_once __DIR__ . '/db.php';

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
 * Closes any elapsed (not current) period that hasn't been tallied yet:
 * sums points per user for that period, crowns the top scorer, and
 * awards them a random prize from the pool. Ties are broken randomly.
 * Safe to call on every page load.
 */
function todoer_close_elapsed_periods(PDO $pdo): void {
    foreach (TODOER_LIST_TYPES as $listType) {
        $currentKey = todoer_period_key($listType);

        $stmt = $pdo->prepare(
            'SELECT DISTINCT period_key FROM tasks
             WHERE list_type = ? AND period_key < ?
             AND period_key NOT IN (SELECT period_key FROM periods_closed WHERE list_type = ?)'
        );
        $stmt->execute([$listType, $currentKey, $listType]);
        $pendingKeys = array_column($stmt->fetchAll(), 'period_key');

        foreach ($pendingKeys as $periodKey) {
            todoer_close_one_period($pdo, $listType, $periodKey);
        }
    }
}

function todoer_close_one_period(PDO $pdo, string $listType, string $periodKey): void {
    $pdo->beginTransaction();
    try {
        $stmt = $pdo->prepare(
            "SELECT user_id, SUM(points) AS total
             FROM tasks
             WHERE list_type = ? AND period_key = ? AND status = 'done'
             GROUP BY user_id
             ORDER BY total DESC"
        );
        $stmt->execute([$listType, $periodKey]);
        $totals = $stmt->fetchAll();

        $topScore = $totals ? (int) $totals[0]['total'] : 0;

        if ($topScore > 0) {
            $winners = array_values(array_filter($totals, fn($r) => (int) $r['total'] === $topScore));
            $winner = $winners[array_rand($winners)];

            // Prefer a prize never awarded before; once the pool is exhausted, recycle randomly.
            $prizeStmt = $pdo->query(
                'SELECT id FROM prizes WHERE id NOT IN (SELECT prize_id FROM awards) ORDER BY RANDOM() LIMIT 1'
            );
            $prizeId = $prizeStmt->fetchColumn();
            if ($prizeId === false) {
                $prizeId = $pdo->query('SELECT id FROM prizes ORDER BY RANDOM() LIMIT 1')->fetchColumn();
            }

            $insert = $pdo->prepare(
                'INSERT INTO awards (user_id, list_type, period_key, points, prize_id)
                 VALUES (?, ?, ?, ?, ?)'
            );
            $insert->execute([$winner['user_id'], $listType, $periodKey, $topScore, $prizeId]);
        }

        $close = $pdo->prepare('INSERT OR IGNORE INTO periods_closed (list_type, period_key) VALUES (?, ?)');
        $close->execute([$listType, $periodKey]);

        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
}

/** Points totals per user for a given list_type + period_key (current period by default). */
function todoer_leaderboard(PDO $pdo, string $listType, ?string $periodKey = null): array {
    $periodKey = $periodKey ?? todoer_period_key($listType);
    $stmt = $pdo->prepare(
        "SELECT u.id AS user_id, u.username, u.color,
                COALESCE(SUM(CASE WHEN t.status = 'done' AND t.list_type = ? AND t.period_key = ? THEN t.points ELSE 0 END), 0) AS points
         FROM users u
         LEFT JOIN tasks t ON t.user_id = u.id
         GROUP BY u.id
         ORDER BY points DESC, u.username ASC"
    );
    $stmt->execute([$listType, $periodKey]);
    return $stmt->fetchAll();
}

/** All-time points totals per user, across every list type. */
function todoer_all_time_leaderboard(PDO $pdo): array {
    $stmt = $pdo->query(
        "SELECT u.id AS user_id, u.username, u.color,
                COALESCE(SUM(CASE WHEN t.status = 'done' THEN t.points ELSE 0 END), 0) AS points,
                (SELECT COUNT(*) FROM awards a WHERE a.user_id = u.id) AS prize_count
         FROM users u
         LEFT JOIN tasks t ON t.user_id = u.id
         GROUP BY u.id
         ORDER BY points DESC, u.username ASC"
    );
    return $stmt->fetchAll();
}
