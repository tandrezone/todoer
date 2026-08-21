<?php

declare(strict_types=1);

namespace App\Controller\Api;

use App\Http\Input\InputBag;
use App\Http\RequestAttribute;
use App\Http\Responder;
use App\Service\TaskImportService;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

/** Step two of the Keep import: turn the picked candidates into real tasks. */
final class KeepCommitController implements RequestHandlerInterface
{
    public function __construct(
        private readonly TaskImportService $import,
        private readonly Responder $responder
    ) {
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $created = $this->import->commitCandidates(
            RequestAttribute::user($request),
            RequestAttribute::membership($request),
            InputBag::fromBody($request)->list('items')
        );

        return $this->responder->json(['ok' => true, 'created' => $created]);
    }
}
