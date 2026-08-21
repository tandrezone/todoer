<?php

declare(strict_types=1);

namespace App\Controller\Web;

use App\Http\Responder;
use App\Http\UrlGenerator;
use App\Service\AuthService;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Signing out.
 *
 * POST-only, and behind the CSRF guard like every other state change: a GET link meant any page
 * could sign a visitor out with an <img> tag. The nav posts a small form instead.
 */
final class LogoutController implements RequestHandlerInterface
{
    public function __construct(
        private readonly AuthService $auth,
        private readonly Responder $responder,
        private readonly UrlGenerator $urls
    ) {
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $this->auth->logout();

        return $this->responder->redirect($this->urls->path('/login'));
    }
}
