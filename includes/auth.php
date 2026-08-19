<?php
require_once __DIR__ . '/db.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

const TODOER_COLORS = ['#5b8def', '#e0665a', '#3fb682', '#e0a63f', '#9b6de0', '#3fb6c9'];

function todoer_register(string $username, string $password): array {
    $username = trim($username);
    if ($username === '' || strlen($password) < 3) {
        return [false, 'Pick a username and a password of at least 3 characters.'];
    }
    $pdo = todoer_db();
    $exists = $pdo->prepare('SELECT id FROM users WHERE username = ?');
    $exists->execute([$username]);
    if ($exists->fetch()) {
        return [false, 'That username is already taken.'];
    }
    $count = (int) $pdo->query('SELECT COUNT(*) FROM users')->fetchColumn();
    $color = TODOER_COLORS[$count % count(TODOER_COLORS)];
    $stmt = $pdo->prepare('INSERT INTO users (username, password_hash, color) VALUES (?, ?, ?)');
    $stmt->execute([$username, password_hash($password, PASSWORD_DEFAULT), $color]);
    $_SESSION['user_id'] = (int) $pdo->lastInsertId();
    return [true, ''];
}

function todoer_login(string $username, string $password): array {
    $pdo = todoer_db();
    $stmt = $pdo->prepare('SELECT * FROM users WHERE username = ?');
    $stmt->execute([trim($username)]);
    $user = $stmt->fetch();
    if (!$user || !password_verify($password, $user['password_hash'])) {
        return [false, 'Wrong username or password.'];
    }
    $_SESSION['user_id'] = (int) $user['id'];
    return [true, ''];
}

function todoer_logout(): void {
    $_SESSION = [];
    session_destroy();
}

function todoer_current_user(): ?array {
    if (empty($_SESSION['user_id'])) {
        return null;
    }
    $pdo = todoer_db();
    $stmt = $pdo->prepare('SELECT id, username, color, created_at FROM users WHERE id = ?');
    $stmt->execute([$_SESSION['user_id']]);
    $user = $stmt->fetch();
    return $user ?: null;
}

function todoer_require_login(): array {
    $user = todoer_current_user();
    if (!$user) {
        header('Location: login.php');
        exit;
    }
    return $user;
}
