<?php

declare(strict_types=1);

use App\Controller\Api\GroupApiController;
use App\Controller\Api\KeepCommitController;
use App\Controller\Api\KeepScanController;
use App\Controller\Api\LeaderboardApiController;
use App\Controller\Api\NotificationApiController;
use App\Controller\Api\PrizeApiController;
use App\Controller\Api\TaskApiController;
use App\Controller\Api\TaskExportController;
use App\Controller\Api\TaskRestoreController;
use App\Controller\Web\BackupPageController;
use App\Controller\Web\DashboardController;
use App\Controller\Web\GroupPageController;
use App\Controller\Web\ImportPageController;
use App\Controller\Web\LoginController;
use App\Controller\Web\LogoutController;
use App\Controller\Web\PrizesPageController;
use App\Http\Middleware\RequireApiSessionMiddleware;
use App\Http\Middleware\RequireWebSessionMiddleware;
use App\Http\Route;

/**
 * The route table.
 *
 * Two guards, applied per route rather than inside handlers: pages redirect an anonymous visitor to
 * the sign-in screen, API endpoints answer 401. Adding an endpoint means adding a line here, and
 * forgetting the guard is visible in the diff -- which is the improvement over each endpoint file
 * opening with its own copy of the "are you logged in?" check.
 *
 * The .php-suffixed paths the old front-end used are gone; assets/js and the service worker build
 * these URLs from the <meta name="base-path"> value, so the app still works when it is served from a
 * sub-directory.
 */
$page = [RequireWebSessionMiddleware::class];
$api = [RequireApiSessionMiddleware::class];

return [
    // --- Pages ---
    Route::get('dashboard', '/', DashboardController::class, $page),
    Route::any('login', ['GET', 'HEAD', 'POST'], '/login', LoginController::class),
    Route::post('logout', '/logout', LogoutController::class, $page),
    Route::get('group', '/group', GroupPageController::class, $page),
    Route::get('prizes', '/prizes', PrizesPageController::class, $page),
    Route::get('import', '/import', ImportPageController::class, $page),
    Route::get('backup', '/backup', BackupPageController::class, $page),

    // --- API ---
    Route::any('api.tasks', ['GET', 'HEAD', 'POST'], '/api/tasks', TaskApiController::class, $api),
    Route::any('api.group', ['GET', 'HEAD', 'POST'], '/api/group', GroupApiController::class, $api),
    Route::get('api.leaderboard', '/api/leaderboard', LeaderboardApiController::class, $api),
    Route::any('api.prizes', ['GET', 'HEAD', 'POST'], '/api/prizes', PrizeApiController::class, $api),
    Route::any('api.notifications', ['GET', 'HEAD', 'POST'], '/api/notifications', NotificationApiController::class, $api),
    Route::post('api.import.keep.scan', '/api/import/keep/scan', KeepScanController::class, $api),
    Route::post('api.import.keep.commit', '/api/import/keep/commit', KeepCommitController::class, $api),
    Route::post('api.import.tasks', '/api/import/tasks', TaskRestoreController::class, $api),
    Route::get('api.export.tasks', '/api/export/tasks', TaskExportController::class, $api),
];
