<?php

declare(strict_types=1);

namespace App\Controller\Api;

use App\Http\RequestAttribute;
use App\Http\Responder;
use App\Service\LeaderboardService;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

/** Today / this week / this month / all-time standings for the caller's group. */
final class LeaderboardApiController implements RequestHandlerInterface
{
    public function __construct(
        private readonly LeaderboardService $leaderboards,
        private readonly Responder $responder
    ) {
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        return $this->responder->json(
            $this->leaderboards->boards(RequestAttribute::membership($request))
        );
    }
}
