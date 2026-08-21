<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Service\MaintenanceService;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Runs the game's background bookkeeping before the request is handled: expire or reassign
 * timed-out tasks, warn holders whose window is nearly up, close finished periods and award
 * their prizes.
 *
 * This is the "no cron job needed" design of the original app, kept deliberately: the sweep is
 * cheap, it has to keep ticking for a group even while nobody in it has the app open, and any
 * request from any member is a fine moment to do it. It is wrapped in a try/catch because
 * bookkeeping failing is not a reason for a page to fail -- the error is logged and the request
 * carries on.
 */
final class MaintenanceMiddleware implements MiddlewareInterface
{
    public function __construct(
        private readonly MaintenanceService $maintenance,
        private readonly LoggerInterface $logger
    ) {
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        try {
            $this->maintenance->sweep();
        } catch (Throwable $e) {
            $this->logger->error('Maintenance sweep failed', ['exception' => $e]);
        }

        return $handler->handle($request);
    }
}
