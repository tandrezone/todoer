<?php

declare(strict_types=1);

namespace App\Controller\Web;

use App\Http\RequestAttribute;
use App\Http\Responder;
use App\View\TemplateRenderer;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

/** Renders the import page; its contents are loaded by assets/js/import.js from the API. */
final class ImportPageController implements RequestHandlerInterface
{
    public function __construct(
        private readonly TemplateRenderer $renderer,
        private readonly Responder $responder
    ) {
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        return $this->responder->html($this->renderer->page(
            'import',
            [
                'user' => RequestAttribute::user($request),
                'group' => RequestAttribute::group($request),
            ],
            ['title' => 'Todoer — Import from Google Keep', 'scripts' => ['assets/js/import.js']]
        ));
    }
}
