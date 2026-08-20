<?php
require_once __DIR__ . '/db.php';

if (session_status() === PHP_SESSION_NONE) {
    // Harden the session cookie: HttpOnly so it can't be read/exfiltrated via JavaScript (belt
    // and suspenders even though the app is careful to escape output), SameSite=Lax so it isn't
    // sent on cross-site requests (defense in depth alongside the CSRF token -- see
    // todoer_csrf_token()/todoer_require_csrf()), and Secure only when actually served over
    // HTTPS (this app is commonly run plain-HTTP on a LAN via `php -S`, and a Secure cookie
    // would simply never be sent there).
    $isHttps = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || ($_SERVER['SERVER_PORT'] ?? null) === '443'
        || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
    session_set_cookie_params([
        'lifetime' => 0,
        'path' => '/',
        'httponly' => true,
        'samesite' => 'Lax',
        'secure' => $isHttps,
    ]);
    session_start();
}

const TODOER_COLORS = ['#5b8def', '#e0665a', '#3fb682', '#e0a63f', '#9b6de0', '#3fb6c9'];

/** Random, session-scoped token used to protect state-changing requests against CSRF. */
function todoer_csrf_token(): string {
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

function todoer_register(string $username, string $password): array {
    $username = trim($username);
    if ($username === '' || strlen($password) < 8) {
        return [false, 'Pick a username and a password of at least 8 characters.'];
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
    // Regenerate the session id on every privilege change (anonymous -> authenticated) so a
    // session id an attacker may have handed the victim before login can't be reused afterward
    // (session fixation).
    session_regenerate_id(true);
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
    session_regenerate_id(true);
    $_SESSION['user_id'] = (int) $user['id'];
    return [true, ''];
}

function todoer_logout(): void {
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $params = session_get_cookie_params();
        setcookie(session_name(), '', [
            'expires' => time() - 42000,
            'path' => $params['path'],
            'domain' => $params['domain'],
            'secure' => $params['secure'],
            'httponly' => $params['httponly'],
            'samesite' => $params['samesite'] ?? 'Lax',
        ]);
    }
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
