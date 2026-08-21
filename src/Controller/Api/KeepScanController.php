<?php

declare(strict_types=1);

namespace App\Controller\Api;

use App\Http\Input\InputBag;
use App\Http\Responder;
use App\Service\KeepImportService;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\UploadedFileInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Step one of the Keep import: parse the uploaded export into candidate rows to choose from.
 *
 * Nothing is written here -- scanning is a read of the upload, and the user still has to pick what
 * comes in and which list each item goes to.
 */
final class KeepScanController implements RequestHandlerInterface
{
    public function __construct(
        private readonly KeepImportService $keep,
        private readonly Responder $responder
    ) {
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $input = InputBag::fromBody($request);
        $options = KeepImportService::normaliseOptions(
            $input->bool('skip_archived'),
            $input->bool('skip_trashed'),
            $input->bool('include_checked'),
            $input->string('plain_note_mode', 'line')
        );

        $result = $this->keep->scan($this->uploadedFiles($request), $options);

        return $this->responder->json(['ok' => true] + $result);
    }

    /** @return list<UploadedFileInterface> */
    private function uploadedFiles(ServerRequestInterface $request): array
    {
        $files = $request->getUploadedFiles()['files'] ?? [];
        if ($files instanceof UploadedFileInterface) {
            return [$files];
        }

        return array_values(array_filter(
            is_array($files) ? $files : [],
            static fn(mixed $file): bool => $file instanceof UploadedFileInterface
        ));
    }
}
