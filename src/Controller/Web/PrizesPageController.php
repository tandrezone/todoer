<?php

declare(strict_types=1);

namespace App\Controller\Web;

use App\Http\RequestAttribute;
use App\Http\Responder;
use App\View\TemplateRenderer;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

/** Renders the prizes page; its contents are loaded by assets/js/prizes.js from the API. */
final class PrizesPageController implements RequestHandlerInterface
{
    public function __construct(
        private readonly TemplateRenderer $renderer,
        private readonly Responder $responder
    ) {
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        return $this->responder->html($this->renderer->page(
            'prizes',
            [
                'user' => RequestAttribute::user($request),
                'group' => RequestAttribute::group($request),
            ],
            ['title' => 'Todoer — Prizes', 'scripts' => ['assets/js/prizes.js']]
        ));
    }
}
