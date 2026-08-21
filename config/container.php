<?php

declare(strict_types=1);

use App\Container\Container;
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
use App\Database\ConnectionFactory;
use App\Database\Migrator;
use App\Database\TransactionManager;
use App\Http\Middleware\AuthenticationMiddleware;
use App\Http\Middleware\BodyParsingMiddleware;
use App\Http\Middleware\CsrfMiddleware;
use App\Http\Middleware\DispatchMiddleware;
use App\Http\Middleware\ErrorHandlerMiddleware;
use App\Http\Middleware\MaintenanceMiddleware;
use App\Http\Middleware\RequireApiSessionMiddleware;
use App\Http\Middleware\RequireWebSessionMiddleware;
use App\Http\Middleware\RoutingMiddleware;
use App\Http\Middleware\SecurityHeadersMiddleware;
use App\Http\Middleware\SessionMiddleware;
use App\Http\MiddlewarePipeline;
use App\Http\Responder;
use App\Http\Router;
use App\Http\SapiEmitter;
use App\Http\TerminalHandler;
use App\Http\UrlGenerator;
use App\Repository\AwardRepository;
use App\Repository\ClosedPeriodRepository;
use App\Repository\GameStateRepository;
use App\Repository\GroupRepository;
use App\Repository\LeaderboardRepository;
use App\Repository\NotificationRepository;
use App\Repository\PushSubscriptionRepository;
use App\Repository\TaskHistoryRepository;
use App\Repository\TaskRepository;
use App\Repository\UserRepository;
use App\Service\AssignmentService;
use App\Service\AuthService;
use App\Service\GroupService;
use App\Service\KeepImportService;
use App\Service\LeaderboardService;
use App\Service\MaintenanceService;
use App\Service\NotificationService;
use App\Service\PasswordHasher;
use App\Service\PeriodService;
use App\Service\PrizeService;
use App\Service\PushService;
use App\Service\TaskBoardService;
use App\Service\TaskDraftFactory;
use App\Service\TaskExportService;
use App\Service\TaskImportService;
use App\Service\TaskService;
use App\Session\CsrfTokenManager;
use App\Session\PhpSession;
use App\Session\SessionInterface;
use App\Support\Clock;
use App\Support\ErrorLogLogger;
use App\View\TemplateRenderer;
use Nyholm\Psr7\Factory\Psr17Factory;
use Psr\Container\ContainerInterface;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Psr\Log\LoggerInterface;

/**
 * The wiring.
 *
 * One file that says what the application is made of. Every dependency is passed in explicitly --
 * no autowiring, no annotations, no service locator smuggled into a constructor -- so the cost of
 * a new dependency is visible here, and a test can replace any single line of it (the smoke tests
 * swap the session, the clock and the database path and leave everything else alone).
 *
 * @param array<string, mixed> $settings
 */
return static function (array $settings, string $basePath = ''): Container {
    $container = new Container();

    $container->value('settings', $settings);
    $container->value('app.base_path', $basePath);

    // --- Infrastructure -----------------------------------------------------------------------
    $container->set(LoggerInterface::class, static fn(): LoggerInterface => new ErrorLogLogger('todoer'));
    $container->set(Clock::class, static fn(): Clock => new Clock());
    $container->set(Psr17Factory::class, static fn(): Psr17Factory => new Psr17Factory());
    $container->set(ResponseFactoryInterface::class, static fn(ContainerInterface $c) => $c->get(Psr17Factory::class));
    $container->set(StreamFactoryInterface::class, static fn(ContainerInterface $c) => $c->get(Psr17Factory::class));

    // The database connection is created on first use, and creating it is also what applies the
    // schema and any pending migrations -- so a fresh checkout becomes a working installation on the
    // first request, with no setup step to forget.
    $container->set(PDO::class, static function (ContainerInterface $c): PDO {
        $settings = $c->get('settings');
        $pdo = (new ConnectionFactory(
            (string) $settings['database']['dsn'],
            null,
            null,
            (string) $settings['paths']['data']
        ))->create();

        (new Migrator($pdo, (string) $settings['paths']['schema']))->migrate();

        return $pdo;
    });
    $container->set(TransactionManager::class, static fn(ContainerInterface $c) => new TransactionManager($c->get(PDO::class)));

    $container->set(SessionInterface::class, static fn(ContainerInterface $c) => new PhpSession($c->get('settings')['session']));
    $container->set(CsrfTokenManager::class, static fn(ContainerInterface $c) => new CsrfTokenManager($c->get(SessionInterface::class)));

    $container->set(UrlGenerator::class, static fn(ContainerInterface $c) => new UrlGenerator(
        (string) $c->get('app.base_path'),
        (string) $c->get('settings')['paths']['public']
    ));
    $container->set(Responder::class, static fn(ContainerInterface $c) => new Responder(
        $c->get(ResponseFactoryInterface::class),
        $c->get(StreamFactoryInterface::class)
    ));
    $container->set(SapiEmitter::class, static fn(): SapiEmitter => new SapiEmitter());
    $container->set(TemplateRenderer::class, static fn(ContainerInterface $c) => new TemplateRenderer(
        (string) $c->get('settings')['paths']['templates'],
        $c->get(UrlGenerator::class),
        $c->get(CsrfTokenManager::class)
    ));

    // --- Repositories -------------------------------------------------------------------------
    $container->set(UserRepository::class, static fn(ContainerInterface $c) => new UserRepository($c->get(PDO::class)));
    $container->set(GroupRepository::class, static fn(ContainerInterface $c) => new GroupRepository($c->get(PDO::class)));
    $container->set(TaskRepository::class, static fn(ContainerInterface $c) => new TaskRepository($c->get(PDO::class), $c->get(Clock::class)));
    $container->set(TaskHistoryRepository::class, static fn(ContainerInterface $c) => new TaskHistoryRepository($c->get(PDO::class), $c->get(Clock::class)));
    $container->set(GameStateRepository::class, static fn(ContainerInterface $c) => new GameStateRepository($c->get(PDO::class), $c->get(Clock::class)));
    $container->set(ClosedPeriodRepository::class, static fn(ContainerInterface $c) => new ClosedPeriodRepository($c->get(PDO::class), $c->get(Clock::class)));
    $container->set(AwardRepository::class, static fn(ContainerInterface $c) => new AwardRepository($c->get(PDO::class), $c->get(Clock::class)));
    $container->set(LeaderboardRepository::class, static fn(ContainerInterface $c) => new LeaderboardRepository($c->get(PDO::class)));
    $container->set(NotificationRepository::class, static fn(ContainerInterface $c) => new NotificationRepository($c->get(PDO::class), $c->get(Clock::class)));
    $container->set(PushSubscriptionRepository::class, static fn(ContainerInterface $c) => new PushSubscriptionRepository($c->get(PDO::class), $c->get(Clock::class)));

    // --- Services -----------------------------------------------------------------------------
    $container->set(PasswordHasher::class, static fn(): PasswordHasher => new PasswordHasher());
    $container->set(GroupService::class, static fn(ContainerInterface $c) => new GroupService(
        $c->get(PDO::class),
        $c->get(GroupRepository::class),
        $c->get(UserRepository::class),
        $c->get(PasswordHasher::class),
        $c->get(TransactionManager::class)
    ));
    $container->set(AuthService::class, static fn(ContainerInterface $c) => new AuthService(
        $c->get(UserRepository::class),
        $c->get(GroupService::class),
        $c->get(SessionInterface::class),
        $c->get(PasswordHasher::class)
    ));
    $container->set(PushService::class, static fn(ContainerInterface $c) => new PushService(
        $c->get(PushSubscriptionRepository::class),
        $c->get(LoggerInterface::class),
        $c->get('settings')['push']
    ));
    $container->set(NotificationService::class, static fn(ContainerInterface $c) => new NotificationService(
        $c->get(NotificationRepository::class),
        $c->get(GroupRepository::class),
        $c->get(PushService::class),
        $c->get(Clock::class)
    ));
    $container->set(PeriodService::class, static fn(ContainerInterface $c) => new PeriodService(
        $c->get(TaskRepository::class),
        $c->get(ClosedPeriodRepository::class),
        $c->get(AwardRepository::class),
        $c->get(GameStateRepository::class),
        $c->get(Clock::class),
        $c->get(TransactionManager::class),
        $c->get(LoggerInterface::class)
    ));
    $container->set(AssignmentService::class, static fn(ContainerInterface $c) => new AssignmentService(
        $c->get(TaskRepository::class),
        $c->get(TaskHistoryRepository::class),
        $c->get(GameStateRepository::class),
        $c->get(GroupRepository::class),
        $c->get(NotificationService::class),
        $c->get(PeriodService::class),
        $c->get(TransactionManager::class),
        $c->get(Clock::class)
    ));
    $container->set(TaskDraftFactory::class, static fn(): TaskDraftFactory => new TaskDraftFactory());
    $container->set(TaskService::class, static fn(ContainerInterface $c) => new TaskService(
        $c->get(TaskRepository::class),
        $c->get(TaskHistoryRepository::class),
        $c->get(AssignmentService::class),
        $c->get(PeriodService::class),
        $c->get(GroupService::class),
        $c->get(TaskDraftFactory::class),
        $c->get(NotificationService::class),
        $c->get(Clock::class)
    ));
    $container->set(TaskBoardService::class, static fn(ContainerInterface $c) => new TaskBoardService(
        $c->get(TaskRepository::class),
        $c->get(AssignmentService::class),
        $c->get(PeriodService::class),
        $c->get(GroupService::class),
        $c->get(NotificationService::class),
        $c->get(Clock::class)
    ));
    $container->set(LeaderboardService::class, static fn(ContainerInterface $c) => new LeaderboardService(
        $c->get(LeaderboardRepository::class),
        $c->get(PeriodService::class)
    ));
    $container->set(PrizeService::class, static fn(ContainerInterface $c) => new PrizeService($c->get(AwardRepository::class)));
    $container->set(MaintenanceService::class, static fn(ContainerInterface $c) => new MaintenanceService(
        $c->get(AssignmentService::class),
        $c->get(PeriodService::class),
        $c->get(GroupRepository::class)
    ));
    $container->set(KeepImportService::class, static fn(): KeepImportService => new KeepImportService());
    $container->set(TaskImportService::class, static fn(ContainerInterface $c) => new TaskImportService(
        $c->get(TaskRepository::class),
        $c->get(UserRepository::class),
        $c->get(AssignmentService::class),
        $c->get(PeriodService::class),
        $c->get(TransactionManager::class),
        $c->get(Clock::class)
    ));
    $container->set(TaskExportService::class, static fn(ContainerInterface $c) => new TaskExportService(
        $c->get(TaskRepository::class),
        $c->get(Clock::class)
    ));

    // --- Middleware ---------------------------------------------------------------------------
    $container->set(ErrorHandlerMiddleware::class, static fn(ContainerInterface $c) => new ErrorHandlerMiddleware(
        $c->get(Responder::class),
        $c->get(TemplateRenderer::class),
        $c->get(LoggerInterface::class),
        (bool) $c->get('settings')['app']['debug']
    ));
    $container->set(SecurityHeadersMiddleware::class, static fn(): SecurityHeadersMiddleware => new SecurityHeadersMiddleware());
    $container->set(SessionMiddleware::class, static fn(ContainerInterface $c) => new SessionMiddleware($c->get(SessionInterface::class)));
    $container->set(BodyParsingMiddleware::class, static fn(): BodyParsingMiddleware => new BodyParsingMiddleware());
    $container->set(CsrfMiddleware::class, static fn(ContainerInterface $c) => new CsrfMiddleware($c->get(CsrfTokenManager::class)));
    $container->set(AuthenticationMiddleware::class, static fn(ContainerInterface $c) => new AuthenticationMiddleware(
        $c->get(AuthService::class),
        $c->get(GroupService::class)
    ));
    $container->set(MaintenanceMiddleware::class, static fn(ContainerInterface $c) => new MaintenanceMiddleware(
        $c->get(MaintenanceService::class),
        $c->get(LoggerInterface::class)
    ));
    $container->set(RoutingMiddleware::class, static fn(ContainerInterface $c) => new RoutingMiddleware($c->get(Router::class)));
    $container->set(DispatchMiddleware::class, static fn(ContainerInterface $c) => new DispatchMiddleware($c));
    $container->set(RequireWebSessionMiddleware::class, static fn(ContainerInterface $c) => new RequireWebSessionMiddleware(
        $c->get(Responder::class),
        $c->get(UrlGenerator::class)
    ));
    $container->set(RequireApiSessionMiddleware::class, static fn(): RequireApiSessionMiddleware => new RequireApiSessionMiddleware());

    $container->set(Router::class, static fn(ContainerInterface $c) => new Router(
        require dirname(__DIR__) . '/config/routes.php',
        (string) $c->get('app.base_path')
    ));

    /**
     * The application pipeline, outermost first.
     *
     * Errors are caught outside everything, so even a failure in session handling produces a proper
     * response. Security headers wrap the whole thing so they are on error responses too. Routing
     * comes late on purpose: the session, the CSRF guard and the current user are established before
     * any route-specific code runs, and dispatch is what nests the route's own guards.
     */
    $container->set(MiddlewarePipeline::class, static fn(ContainerInterface $c) => new MiddlewarePipeline(
        $c,
        [
            ErrorHandlerMiddleware::class,
            SecurityHeadersMiddleware::class,
            SessionMiddleware::class,
            BodyParsingMiddleware::class,
            CsrfMiddleware::class,
            AuthenticationMiddleware::class,
            MaintenanceMiddleware::class,
            RoutingMiddleware::class,
            DispatchMiddleware::class,
        ],
        TerminalHandler::class
    ));
    $container->set(TerminalHandler::class, static fn(): TerminalHandler => new TerminalHandler());
    $container->set(RequestHandlerInterface::class, static fn(ContainerInterface $c) => $c->get(MiddlewarePipeline::class));

    // --- Controllers --------------------------------------------------------------------------
    $container->set(DashboardController::class, static fn(ContainerInterface $c) => new DashboardController(
        $c->get(TemplateRenderer::class),
        $c->get(Responder::class)
    ));
    $container->set(LoginController::class, static fn(ContainerInterface $c) => new LoginController(
        $c->get(AuthService::class),
        $c->get(TemplateRenderer::class),
        $c->get(Responder::class),
        $c->get(UrlGenerator::class)
    ));
    $container->set(LogoutController::class, static fn(ContainerInterface $c) => new LogoutController(
        $c->get(AuthService::class),
        $c->get(Responder::class),
        $c->get(UrlGenerator::class)
    ));
    foreach ([GroupPageController::class, PrizesPageController::class, ImportPageController::class, BackupPageController::class] as $pageController) {
        $container->set($pageController, static fn(ContainerInterface $c) => new $pageController(
            $c->get(TemplateRenderer::class),
            $c->get(Responder::class)
        ));
    }

    $container->set(TaskApiController::class, static fn(ContainerInterface $c) => new TaskApiController(
        $c->get(TaskBoardService::class),
        $c->get(TaskService::class),
        $c->get(AssignmentService::class),
        $c->get(NotificationService::class),
        $c->get(Responder::class)
    ));
    $container->set(GroupApiController::class, static fn(ContainerInterface $c) => new GroupApiController(
        $c->get(GroupService::class),
        $c->get(Responder::class)
    ));
    $container->set(LeaderboardApiController::class, static fn(ContainerInterface $c) => new LeaderboardApiController(
        $c->get(LeaderboardService::class),
        $c->get(Responder::class)
    ));
    $container->set(PrizeApiController::class, static fn(ContainerInterface $c) => new PrizeApiController(
        $c->get(PrizeService::class),
        $c->get(Responder::class)
    ));
    $container->set(NotificationApiController::class, static fn(ContainerInterface $c) => new NotificationApiController(
        $c->get(PushService::class),
        $c->get(CsrfTokenManager::class),
        $c->get(Responder::class)
    ));
    $container->set(KeepScanController::class, static fn(ContainerInterface $c) => new KeepScanController(
        $c->get(KeepImportService::class),
        $c->get(Responder::class)
    ));
    $container->set(KeepCommitController::class, static fn(ContainerInterface $c) => new KeepCommitController(
        $c->get(TaskImportService::class),
        $c->get(Responder::class)
    ));
    $container->set(TaskRestoreController::class, static fn(ContainerInterface $c) => new TaskRestoreController(
        $c->get(TaskImportService::class),
        $c->get(Responder::class)
    ));
    $container->set(TaskExportController::class, static fn(ContainerInterface $c) => new TaskExportController(
        $c->get(TaskExportService::class),
        $c->get(Responder::class)
    ));

    return $container;
};
