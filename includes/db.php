<?php
// Database bootstrap: opens (and initializes on first run) the SQLite file.

function todoer_db(): PDO {
    static $pdo = null;
    if ($pdo !== null) {
        return $pdo;
    }

    $dataDir = __DIR__ . '/../data';
    if (!is_dir($dataDir)) {
        mkdir($dataDir, 0777, true);
    }
    $dbFile = $dataDir . '/todoer.sqlite';
    $isNew = !file_exists($dbFile);

    $pdo = new PDO('sqlite:' . $dbFile);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
    $pdo->exec('PRAGMA foreign_keys = ON');

    $schema = file_get_contents(__DIR__ . '/schema.sql');
    $pdo->exec($schema);

    // Seed the prize pool once.
    $count = (int) $pdo->query('SELECT COUNT(*) FROM prizes')->fetchColumn();
    if ($count === 0) {
        $prizes = [
            '1 hour of uninterrupted rest / nap time, no interruptions allowed',
            'A 20-minute massage from the runner-up',
            'Pick the movie or show for the next two movie nights',
            'Skip one chore of your choice this week',
            'Breakfast in bed, served by the runner-up',
            'Choose the restaurant or takeout for the next dinner out',
            '30 minutes of extra guilt-free screen time',
            'Someone else does your laundry this week',
            'First shower / bathroom priority for a day',
            'Winner picks the weekend activity',
            'A handwritten "why I appreciate you" note from the others',
            'Free coffee or tea, made for you for 3 days straight',
            'Skip dish duty for a week',
            'Control the music playlist for a full day',
            'A surprise treat or dessert, bought by the runner-up',
            'One "get out of a task" free pass for next week',
            'A 15-minute foot rub from the runner-up',
            'Pick where to eat out next',
            'A lazy Sunday morning: no chores, no alarms',
            'The runner-up handles your least favorite chore next week',
        ];
        $stmt = $pdo->prepare('INSERT INTO prizes (description) VALUES (?)');
        foreach ($prizes as $p) {
            $stmt->execute([$p]);
        }
    }

    return $pdo;
}
