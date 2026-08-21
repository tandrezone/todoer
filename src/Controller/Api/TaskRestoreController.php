<?php

declare(strict_types=1);

namespace App\Controller\Api;

use App\Exception\ValidationException;
use App\Http\Input\InputBag;
use App\Http\RequestAttribute;
use App\Http\Responder;
use App\Service\TaskImportService;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\UploadedFileInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Restores tasks from a previously exported file.
 *
 * Accepts either the export file as an upload or the same JSON as a request body, because the Backup
 * page posts a file while a script or a test posts the document directly.
 */
final class TaskRestoreController implements RequestHandlerInterface
{
    public function __construct(
        private readonly TaskImportService $import,
        private readonly Responder $responder
    ) {
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $result = $this->import->restore(
            RequestAttribute::user($request),
            RequestAttribute::membership($request),
            $this->document($request)
        );

        return $this->responder->json(['ok' => true] + $result);
    }

    /** @return array<string, mixed> */
    private function document(ServerRequestInterface $request): array
    {
        $upload = $request->getUploadedFiles()['file'] ?? null;
        if ($upload instanceof UploadedFileInterface) {
            if ($upload->getError() !== UPLOAD_ERR_OK) {
                throw new ValidationException('No file uploaded, or the upload failed.');
            }
            $decoded = json_decode((string) $upload->getStream(), true);
            if (!is_array($decoded)) {
                throw new ValidationException('That file does not look like a Todoer tasks export.');
            }

            return $decoded;
        }

        return InputBag::fromBody($request)->all();
    }
}
