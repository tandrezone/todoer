<?php

declare(strict_types=1);

namespace App\Controller\Api;

use App\Http\RequestAttribute;
use App\Http\Responder;
use App\Service\TaskExportService;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

/** Downloads the caller's group's tasks as a JSON file. */
final class TaskExportController implements RequestHandlerInterface
{
    public function __construct(
        private readonly TaskExportService $export,
        private readonly Responder $responder
    ) {
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $export = $this->export->export(RequestAttribute::membership($request));

        return $this->responder->download(
            (string) json_encode($export['payload'], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES),
            $export['filename']
        );
    }
}
