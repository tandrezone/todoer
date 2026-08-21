<?php

declare(strict_types=1);

namespace App\Controller\Web;

use App\Http\RequestAttribute;
use App\Http\Responder;
use App\View\TemplateRenderer;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * The dashboard shell.
 *
 * There is deliberately no data-loading here: the page's contents come from /api/tasks and
 * /api/leaderboard, which the front-end also polls, so the markup does not have to be reproduced in
 * two places. The controller's whole job is "who is looking, and which group are they in".
 */
final class DashboardController implements RequestHandlerInterface
{
    public function __construct(
        private readonly TemplateRenderer $renderer,
        private readonly Responder $responder
    ) {
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $user = RequestAttribute::user($request);
        $group = RequestAttribute::group($request);

        return $this->responder->html($this->renderer->page(
            'dashboard',
            ['user' => $user, 'group' => $group],
            ['title' => 'Todoer', 'scripts' => ['assets/js/app.js']]
        ));
    }
}
