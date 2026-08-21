<?php

declare(strict_types=1);

namespace App\Controller\Api;

use App\Exception\ValidationException;
use App\Http\Input\InputBag;
use App\Http\RequestAttribute;
use App\Http\Responder;
use App\Service\PrizeService;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

/** The group's prize history, and a winner marking their own prize as claimed. */
final class PrizeApiController implements RequestHandlerInterface
{
    public function __construct(
        private readonly PrizeService $prizes,
        private readonly Responder $responder
    ) {
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $user = RequestAttribute::user($request);
        $membership = RequestAttribute::membership($request);

        if ($request->getMethod() !== 'POST') {
            return $this->responder->json($this->prizes->history($user, $membership));
        }

        $input = InputBag::fromBody($request);
        if ($input->string('action') !== 'claim') {
            throw new ValidationException('Unknown action.');
        }

        $this->prizes->claim($user, $membership, $input->int('award_id'));

        return $this->responder->json(['ok' => true]);
    }
}
