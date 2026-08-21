<?php

declare(strict_types=1);

/**
 * Application settings.
 *
 * Everything the app needs to know about *this* installation lives here: filesystem paths,
 * where the database is, how the session cookie behaves, and where the Web Push keys come
 * from. Nothing in src/ reads $_ENV, getenv() or a superglobal directly -- values arrive as
 * constructor arguments (see config/container.php), which is what makes the services testable.
 *
 * @param string $rootDir Absolute path to the project root (the directory holding composer.json).
 */
return static function (string $rootDir): array {
    // Writable state lives outside the web root. TODOER_DATA_DIR lets a deployment (or a test run)
    // put it somewhere else entirely without touching code.
    $dataDir = (string) (getenv('TODOER_DATA_DIR') ?: $rootDir . '/data');

    return [
        'app' => [
            'name' => 'Todoer',
            'root_dir' => $rootDir,
            // Errors are logged, never rendered: a stack trace in the browser leaks file paths
            // and query fragments. Set TODOER_DEBUG=1 on a development box to see them instead.
            'debug' => in_array(getenv('TODOER_DEBUG'), ['1', 'true', 'yes'], true),
        ],
        'paths' => [
            'data' => $dataDir,
            'templates' => $rootDir . '/templates',
            'schema' => $rootDir . '/database/schema.sql',
            'public' => $rootDir . '/public',
        ],
        'database' => [
            'dsn' => 'sqlite:' . $dataDir . '/todoer.sqlite',
        ],
        'session' => [
            'name' => 'todoer_session',
            'cookie_path' => '/',
            // Secure is decided per request (see SessionMiddleware): this app is commonly run
            // over plain HTTP on a LAN via `php -S`, where a Secure cookie would never be sent.
            'cookie_samesite' => 'Lax',
            'cookie_httponly' => true,
        ],
        'push' => [
            // Both must be set to take precedence; otherwise a keypair is generated once and
            // cached in data/vapid.json (0600), outside the web root.
            'public_key' => (string) (getenv('TODOER_VAPID_PUBLIC_KEY') ?: ''),
            'private_key' => (string) (getenv('TODOER_VAPID_PRIVATE_KEY') ?: ''),
            'subject' => (string) (getenv('TODOER_VAPID_SUBJECT') ?: 'mailto:admin@example.com'),
            'key_file' => $dataDir . '/vapid.json',
        ],
    ];
};
