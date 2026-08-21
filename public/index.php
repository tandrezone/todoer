<?php

declare(strict_types=1);

use App\Application;

// PHP's built-in server (`php -S 0.0.0.0:8080 -t public public/index.php`) uses this file as a
// router script. Returning false hands existing files (CSS, JS, icons) back to the server so
// the front controller only sees application routes.
if (PHP_SAPI === 'cli-server') {
    // PHP has already resolved the request against the document root: when it points at a real
    // file (a stylesheet, a script, an icon) returning false hands it back to the server, so the
    // front controller only ever sees application routes. Works the same whether the app is served
    // from the document root or a sub-directory.
    $resolved = realpath((string) ($_SERVER['SCRIPT_FILENAME'] ?? ''));
    if ($resolved !== false && $resolved !== __FILE__ && is_file($resolved) && !str_ends_with($resolved, '.php')) {
        return false;
    }
}

$rootDir = dirname(__DIR__);
$autoload = $rootDir . '/vendor/autoload.php';

if (!is_file($autoload)) {
    http_response_code(500);
    header('Content-Type: text/plain; charset=utf-8');
    echo "Todoer's dependencies are not installed yet. Run `composer install` in " . $rootDir . ".\n";
    return;
}

require $autoload;

Application::boot($rootDir)->run();
